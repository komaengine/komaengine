<?php

namespace App\Recovery;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

class SiteBackupRestorer
{
    private readonly MySqlSiteBackupRestorer $mySqlRestorer;

    public function __construct(
        private readonly SiteBackupVerifier $verifier,
        ?MySqlSiteBackupRestorer $mySqlRestorer = null,
    ) {
        $this->mySqlRestorer = $mySqlRestorer ?? new MySqlSiteBackupRestorer;
    }

    /**
     * Verify a backup and describe the exact isolated target writes.
     * This method never creates or changes the target path.
     */
    public function inspect(
        string $backupPath,
        string $targetRoot,
        ?string $databaseCredentials = null,
        ?string $databaseTarget = null,
    ): SiteBackupRestorePlan {
        $backup = $this->verifier->verify($backupPath);
        $target = $this->validateTarget($targetRoot, $backup->path);
        $this->assertNoInterruptedStaging($target);
        $database = $this->databaseTarget($backup, $databaseCredentials, $databaseTarget);

        return new SiteBackupRestorePlan($backup, $target, $this->plannedActions($backup, $database), $database);
    }

    /**
     * Restore a previously inspected backup into a new isolated target.
     * The caller is responsible for the command-level explicit --force guard.
     */
    public function restore(
        string $backupPath,
        string $targetRoot,
        bool $force = false,
        ?string $databaseCredentials = null,
        ?string $databaseTarget = null,
    ): SiteBackupRestorePlan {
        if (! $force) {
            throw new SiteBackupException('Site restore requires explicit force confirmation.');
        }

        $plan = $this->inspect($backupPath, $targetRoot, $databaseCredentials, $databaseTarget);
        $stage = $this->stagePath($plan->targetRoot);
        // Null until the logical import starts writing to the target database.
        $databaseState = null;
        $database = null;

        try {
            // Re-verify immediately before the first write so an inspect result
            // cannot authorize a changed backup later in the same command.
            $backup = $this->verifier->verify($plan->backup->path);
            $database = $this->databaseTarget($backup, $databaseCredentials, $databaseTarget);

            if (! mkdir($stage, 0700)) {
                throw new SiteBackupException('Restore staging directory could not be created.');
            }

            foreach ($backup->manifest['files'] as $file) {
                if (! is_array($file)) {
                    throw new SiteBackupException('Backup file inventory is invalid for restore.');
                }
                if ($database !== null && ($file['role'] ?? null) === 'database') {
                    continue;
                }

                $source = $backup->path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, (string) ($file['path'] ?? ''));
                $destination = $stage.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $this->targetPathFor($file));
                $this->copyVerifiedFile($source, $destination, $file);
            }

            // Empty durable directories are meaningful even though they contain
            // no inventory records. Cache and view directories are deliberately
            // new disposable state, not captured application state.
            $this->ensureDirectory($stage.'/storage/app/media');
            $this->ensureDirectory($stage.'/storage/app/private');
            // Manga media no longer lives under the public disk, so a backup
            // may hold nothing for it. The public link must still resolve.
            $this->ensureDirectory($stage.'/storage/app/public');
            $this->ensureDirectory($stage.'/storage/framework/cache');
            $this->ensureDirectory($stage.'/storage/framework/views');
            $this->createStorageLink($stage);
            $this->beforePublish($stage);

            // Re-check the exact target immediately before the atomic publish.
            // A concurrent creator therefore cannot be overwritten.
            if ($this->validateTarget($plan->targetRoot, $backup->path) !== $plan->targetRoot) {
                throw new SiteBackupException('Restore target changed before publication.');
            }
            if ($database !== null) {
                $this->mySqlRestorer->preflight($database);
                $databaseState = 'loading';
                $this->mySqlRestorer->restore($database);
                $databaseState = 'loaded';
                $this->afterDatabaseLoad($stage);
            }
            if (! rename($stage, $plan->targetRoot)) {
                throw new SiteBackupException('Restore staging directory could not be published atomically.');
            }

            return new SiteBackupRestorePlan($backup, $plan->targetRoot, $plan->actions, $database);
        } catch (Throwable $exception) {
            // The filesystem publishes atomically or not at all, but a logical
            // import cannot be undone from here. Say exactly what state the
            // target database is in instead of inviting a retry against it.
            if ($database !== null && $databaseState === 'loading') {
                throw new SiteBackupException(
                    "Logical database restore failed after it began writing to database [{$database->database}]. "
                    .'Nothing was published and the backup is unchanged. That database may be partly populated: '
                    .'do not reuse it. Provision a new empty database and run the restore again.',
                );
            }
            if ($database !== null && $databaseState === 'loaded') {
                throw new SiteBackupException(
                    "The logical database was restored into [{$database->database}], but the site files were not "
                    .'published, so no restored site exists. The backup is unchanged. That database has no matching '
                    .'files: do not reuse it. Provision a new empty database and run the restore again.',
                );
            }

            throw $exception instanceof SiteBackupException
                ? $exception
                : new SiteBackupException('Site restore failed before publication.');
        } finally {
            if (is_dir($stage) || is_link($stage)) {
                $this->deleteDirectory($stage);
            }
        }
    }

    /** Hook retained solely to make interrupted restore staging cleanup testable. */
    protected function beforePublish(string $stage): void {}

    /** Hook retained solely to make a failure between database load and publication testable. */
    protected function afterDatabaseLoad(string $stage): void {}

    /**
     * A restore that was killed outright cannot remove its own staging
     * directory. That directory is a private copy of backup content, and may
     * belong to a restore that is still running, so it is reported and never
     * removed or reused automatically.
     */
    private function assertNoInterruptedStaging(string $target): void
    {
        $prefix = '.'.basename($target).'.restore-staging-';

        foreach (scandir(dirname($target)) ?: [] as $name) {
            if (str_starts_with($name, $prefix)) {
                throw new SiteBackupException(
                    "A restore staging directory already exists beside this target: {$name}. "
                    .'Confirm no restore is running, then remove it. If that restore had started loading a '
                    .'database, provision a new empty database before running the restore again.',
                );
            }
        }
    }

    private function databaseTarget(
        SiteBackup $backup,
        ?string $databaseCredentials,
        ?string $databaseTarget,
    ): ?MySqlRestoreTarget {
        $driver = $backup->manifest['database']['driver'] ?? null;
        if ($driver === 'sqlite') {
            if ($databaseCredentials !== null || $databaseTarget !== null) {
                throw new SiteBackupException('SQLite restore does not accept logical database target options.');
            }

            return null;
        }
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new SiteBackupException('Site backup restore supports SQLite, MySQL, and MariaDB only.');
        }

        $artifact = $backup->manifest['database']['artifact']['path'] ?? null;
        if (! is_string($artifact)) {
            throw new SiteBackupException('Logical database artifact is invalid for restore.');
        }

        $target = $this->mySqlRestorer->target(
            $driver,
            $backup->path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $artifact),
            $databaseCredentials,
            $databaseTarget,
        );
        $this->assertDatabaseTargetIsDistinct($target);

        return $target;
    }

    private function assertDatabaseTargetIsDistinct(MySqlRestoreTarget $target): void
    {
        $connection = config('database.connections.'.config('database.default'));
        if (! is_array($connection) || ! in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            return;
        }

        $sourceDatabase = $connection['database'] ?? null;
        $sourceSocket = $connection['unix_socket'] ?? null;
        if (is_string($sourceSocket) && $sourceSocket !== '') {
            $sourceEndpoint = 'socket:'.$sourceSocket;
        } else {
            $sourceHost = $connection['host'] ?? null;
            $sourcePort = $connection['port'] ?? '3306';
            if (! is_string($sourceHost) || $sourceHost === '' || ! is_scalar($sourcePort)) {
                throw new SiteBackupException('Active database endpoint could not be compared with the restore target.');
            }
            $sourceEndpoint = 'tcp:'.strtolower($sourceHost).':'.(int) $sourcePort;
        }

        if (is_string($sourceDatabase) && $sourceDatabase !== ''
            && $sourceEndpoint === $target->endpoint && $sourceDatabase === $target->database) {
            throw new SiteBackupException('Logical database restore target must differ from the active source database.');
        }
    }

    private function validateTarget(string $targetRoot, string $backupRoot): string
    {
        if (! str_starts_with($targetRoot, DIRECTORY_SEPARATOR) || str_contains($targetRoot, "\0")) {
            throw new SiteBackupException('Restore target root must be an absolute path.');
        }
        if (file_exists($targetRoot) || is_link($targetRoot)) {
            throw new SiteBackupException('Restore target root must not already exist.');
        }

        $parent = dirname($targetRoot);
        $name = basename($targetRoot);
        if ($name === '' || $name === '.' || $name === DIRECTORY_SEPARATOR
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $name) !== 1
            || ! is_dir($parent) || ! is_writable($parent)) {
            throw new SiteBackupException('Restore target parent must be an existing writable directory and target name must be safe.');
        }
        $this->assertNoSymlinks($parent, 'Restore target path');
        $parent = realpath($parent);
        if ($parent === false) {
            throw new SiteBackupException('Restore target parent could not be resolved.');
        }
        $target = $parent.DIRECTORY_SEPARATOR.$name;

        $unsafeRoots = [
            $backupRoot,
            base_path(),
            storage_path(),
            database_path(),
            public_path(),
            (string) config('filesystems.disks.media.root', storage_path('app/media')),
            (string) config('filesystems.disks.local.root', storage_path('app/private')),
            (string) config('installer.public_storage_link', public_path('storage')),
            (string) config('installer.public_storage_target', storage_path('app/public')),
        ];
        $database = config('database.connections.'.config('database.default').'.database');
        if (is_string($database) && str_starts_with($database, DIRECTORY_SEPARATOR)) {
            $unsafeRoots[] = $database;
        }

        foreach ($unsafeRoots as $unsafeRoot) {
            $root = $this->canonicalPath($unsafeRoot);
            if ($root !== null && $this->overlaps($target, $root)) {
                throw new SiteBackupException('Restore target must be isolated from the backup, application, and active configured paths.');
            }
        }

        return $target;
    }

    /**
     * @return list<array{action: string, source: string|null, target: string}>
     */
    private function plannedActions(SiteBackup $backup, ?MySqlRestoreTarget $databaseTarget): array
    {
        $actions = [];
        foreach ($backup->manifest['files'] as $file) {
            if (! is_array($file)) {
                throw new SiteBackupException('Backup file inventory is invalid for restore.');
            }
            if ($databaseTarget !== null && ($file['role'] ?? null) === 'database') {
                $actions[] = [
                    'action' => 'load',
                    'source' => (string) ($file['path'] ?? ''),
                    'target' => 'database service: '.$databaseTarget->database,
                ];
            } else {
                $actions[] = [
                    'action' => 'copy',
                    'source' => (string) ($file['path'] ?? ''),
                    'target' => $this->targetPathFor($file),
                ];
            }
        }
        $actions[] = ['action' => 'rebuild', 'source' => null, 'target' => 'storage/framework/cache'];
        $actions[] = ['action' => 'rebuild', 'source' => null, 'target' => 'storage/framework/views'];
        $actions[] = ['action' => 'link', 'source' => null, 'target' => 'public/storage -> ../storage/app/public'];

        return $actions;
    }

    /**
     * @param  array<string, mixed>  $file
     */
    private function targetPathFor(array $file): string
    {
        $role = $file['role'] ?? null;
        $path = $file['path'] ?? null;
        if (! is_string($role) || ! is_string($path)) {
            throw new SiteBackupException('Backup file inventory is invalid for restore.');
        }

        return match ($role) {
            'database' => $path === 'database/database.sqlite'
                ? 'database/database.sqlite'
                : throw new SiteBackupException('SQLite backup database artifact is invalid for restore.'),
            // Keep the v1 archive path/role stable while restoring it into the
            // protected application-controlled media root.
            'public_media' => $this->mapInventoryPath($path, 'storage/public/media/', 'storage/app/media/'),
            'private_input' => $this->mapInventoryPath($path, 'storage/private/', 'storage/app/private/'),
            'release' => $this->mapInventoryPath($path, 'release/', 'release/'),
            'secret_bundle' => $this->mapInventoryPath($path, 'secrets/', 'secrets/'),
            default => throw new SiteBackupException('Backup file role is invalid for restore.'),
        };
    }

    private function mapInventoryPath(string $path, string $sourcePrefix, string $targetPrefix): string
    {
        if (! str_starts_with($path, $sourcePrefix) || strlen($path) === strlen($sourcePrefix)) {
            throw new SiteBackupException('Backup file path is invalid for restore.');
        }

        return $targetPrefix.substr($path, strlen($sourcePrefix));
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function copyVerifiedFile(string $source, string $destination, array $expected): void
    {
        $bytesExpected = $expected['bytes'] ?? null;
        $hashExpected = $expected['sha256'] ?? null;
        if (! is_int($bytesExpected) || ! is_string($hashExpected)
            || ! is_file($source) || is_link($source)) {
            throw new SiteBackupException('Verified backup artifact is missing or unsafe during restore.');
        }
        $this->assertNoSymlinks($source, 'Backup artifact path');
        $this->ensureDirectory(dirname($destination));

        $input = fopen($source, 'rb');
        $output = fopen($destination, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new SiteBackupException('Verified backup artifact could not be copied safely.');
        }

        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            while (! feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                if ($chunk === false) {
                    throw new SiteBackupException('Verified backup artifact could not be read during restore.');
                }
                if ($chunk === '') {
                    continue;
                }
                $this->writeAll($output, $chunk);
                hash_update($hash, $chunk);
                $bytes += strlen($chunk);
            }
        } finally {
            fclose($input);
            fclose($output);
        }

        if ($bytes !== $bytesExpected || ! hash_equals($hashExpected, hash_final($hash))) {
            throw new SiteBackupException('Backup artifact changed during restore staging.');
        }
    }

    private function createStorageLink(string $stage): void
    {
        $this->ensureDirectory($stage.'/public');
        $link = $stage.'/public/storage';
        if (! symlink('../storage/app/public', $link)
            || ! is_link($link)
            || readlink($link) !== '../storage/app/public'
            || realpath($link) !== realpath($stage.'/storage/app/public')) {
            throw new SiteBackupException('Restore staging storage link could not be created safely.');
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (is_link($path) || (file_exists($path) && ! is_dir($path))) {
            throw new SiteBackupException('Restore staging path is unsafe.');
        }
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new SiteBackupException('Restore staging directory could not be created.');
        }
    }

    private function stagePath(string $target): string
    {
        try {
            $suffix = bin2hex(random_bytes(10));
        } catch (Throwable) {
            throw new SiteBackupException('Secure restore staging name could not be generated.');
        }

        return dirname($target).DIRECTORY_SEPARATOR.'.'.basename($target).'.restore-staging-'.$suffix;
    }

    private function canonicalPath(string $path): ?string
    {
        if (! str_starts_with($path, DIRECTORY_SEPARATOR) || str_contains($path, "\0")) {
            return null;
        }
        if (file_exists($path) || is_link($path)) {
            $resolved = realpath($path);

            return $resolved === false ? null : $resolved;
        }

        return rtrim($path, DIRECTORY_SEPARATOR);
    }

    private function overlaps(string $left, string $right): bool
    {
        return $this->inside($left, $right) || $this->inside($right, $left);
    }

    private function inside(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    private function assertNoSymlinks(string $path, string $label): void
    {
        $segments = explode(DIRECTORY_SEPARATOR, trim($path, DIRECTORY_SEPARATOR));
        $cursor = DIRECTORY_SEPARATOR;
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }
            $cursor .= ($cursor === DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR).$segment;
            if (is_link($cursor)) {
                throw new SiteBackupException("{$label} contains a symbolic link.");
            }
        }
    }

    /**
     * @param  resource  $stream
     */
    private function writeAll($stream, string $chunk): void
    {
        $length = strlen($chunk);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($stream, substr($chunk, $offset));
            if ($written === false || $written === 0) {
                throw new SiteBackupException('Verified backup artifact could not be written completely.');
            }
            $offset += $written;
        }
    }

    private function deleteDirectory(string $directory): void
    {
        if (is_link($directory)) {
            unlink($directory);

            return;
        }
        if (! is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($entry->isLink() || $entry->isFile()) {
                unlink($path);
            } else {
                rmdir($path);
            }
        }
        rmdir($directory);
    }
}
