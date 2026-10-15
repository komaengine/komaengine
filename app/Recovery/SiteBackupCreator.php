<?php

namespace App\Recovery;

use App\Engine\Plugins\PluginManager;
use App\Engine\Themes\ThemeManager;
use FilesystemIterator;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

class SiteBackupCreator
{
    private const FORMAT = 'koma-site-backup-v1';

    public function __construct(
        private readonly MaintenanceMode $maintenanceMode,
        private readonly ThemeManager $themes,
        private readonly PluginManager $plugins,
        private readonly SiteBackupVerifier $verifier,
        private readonly MySqlSiteBackupDumper $mySqlDumper,
    ) {}

    public function create(string $destination, ?string $secretBundle): SiteBackup
    {
        $target = $this->validateDestination($destination);
        $secretBundle = $this->validateSecretBundle($secretBundle);
        $connection = $this->databaseConnection();
        $stage = $this->stagePath($target);
        $wasAlreadyDown = $this->maintenanceMode->active();

        try {
            if (! mkdir($stage, 0700)) {
                throw new SiteBackupException('Backup staging directory could not be created.');
            }

            if (! $wasAlreadyDown) {
                $this->maintenanceMode->activate([
                    'except' => [],
                    'redirect' => null,
                    'retry' => null,
                    'refresh' => null,
                    'secret' => null,
                    'status' => 503,
                    'template' => null,
                ]);
            }

            $manifest = $this->capture($stage, $connection, $secretBundle);
            $manifestPath = $stage.DIRECTORY_SEPARATOR.'manifest.json';
            file_put_contents(
                $manifestPath,
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n",
            );

            $this->verifier->verify($stage);
            $this->beforePublish($stage);

            if (! rename($stage, $target)) {
                throw new SiteBackupException('Backup staging directory could not be published atomically.');
            }

            return $this->verifier->verify($target);
        } catch (SiteBackupException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new SiteBackupException('Backup creation failed before publication.');
        } finally {
            if (is_dir($stage) || is_link($stage)) {
                $this->deleteDirectory($stage);
            }
            if (! $wasAlreadyDown && $this->maintenanceMode->active()) {
                $this->maintenanceMode->deactivate();
            }
        }
    }

    /** Hook retained solely to make interrupted staging cleanup testable. */
    protected function beforePublish(string $stage): void {}

    /**
     * @return array<string, mixed>
     */
    private function capture(string $stage, Connection $connection, string $secretBundle): array
    {
        $files = [];
        $driver = $connection->getDriverName();
        $databasePath = $stage.'/database/'.($driver === 'sqlite' ? 'database.sqlite' : 'database.sql');
        $this->ensureDirectory(dirname($databasePath));
        if ($driver === 'sqlite') {
            $this->captureSqlite($connection, $databasePath);
        } else {
            $this->captureMySql($connection, $driver, $databasePath, $stage);
        }
        $database = $this->describeFile($databasePath, 'database', $stage);
        $files[] = $database;

        $publicMedia = $this->throughStorageLink(
            (string) config('filesystems.disks.media.root', storage_path('app/media')),
        );
        $privateInputs = $this->throughStorageLink(
            (string) config('filesystems.disks.local.root', storage_path('app/private')),
        );
        $this->copyDirectory($publicMedia, $stage.'/storage/public/media', 'public_media', $stage, $files);
        $this->copyDirectory($privateInputs, $stage.'/storage/private', 'private_input', $stage, $files);

        $composerLockPath = base_path('composer.lock');
        $composerLock = $this->copyFile(
            $composerLockPath,
            $stage.'/release/composer.lock',
            'release',
            $stage,
        );
        $files[] = $composerLock;

        [$releaseIdentity, $buildMetadata] = $this->captureReleaseIdentity($stage, $composerLock, $files);

        $extensions = $this->copyEnabledExtensions($stage, $files);
        $secretName = basename($secretBundle);
        $secret = $this->copyFile(
            $secretBundle,
            $stage.'/secrets/'.$secretName,
            'secret_bundle',
            $stage,
        );
        $files[] = $secret;

        return [
            'format' => self::FORMAT,
            'schema_version' => 1,
            'created_at' => now()->utc()->toIso8601String(),
            'backup_tool' => [
                'name' => 'KomaEngine',
                'version' => (string) config('koma.version'),
            ],
            'database' => [
                'driver' => $driver,
                'server_version' => $this->databaseVersion($connection, $driver),
                'artifact' => $this->withoutRole($database),
            ],
            'release' => [
                'identity' => $releaseIdentity,
                'composer_lock' => $this->withoutRole($composerLock),
                'build_metadata' => $buildMetadata,
                'extensions' => $extensions,
            ],
            'secrets' => [
                'bundle' => $this->withoutRole($secret),
                'format' => $this->secretFormat($secretName),
            ],
            'files' => $files,
        ];
    }

    /**
     * @param  array{role: string, path: string, bytes: int, sha256: string}  $composerLock
     * @param  array<int, array{role: string, path: string, bytes: int, sha256: string}>  $files
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}
     */
    private function captureReleaseIdentity(string $stage, array $composerLock, array &$files): array
    {
        $version = (string) config('koma.version');
        if (preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?\z/D', $version) !== 1) {
            throw new SiteBackupException('KomaEngine release version is missing or invalid.');
        }

        $metadataPath = config('koma.release.metadata_path');
        if (! is_string($metadataPath) || $metadataPath === '') {
            throw new SiteBackupException('KomaEngine build metadata path is invalid.');
        }

        if (is_file($metadataPath) || is_link($metadataPath)) {
            $record = $this->copyFile(
                $metadataPath,
                $stage.'/release/KOMA-BUILD-METADATA.txt',
                'release',
                $stage,
            );
            $files[] = $record;
            $metadata = $this->parseBuildMetadata($stage.'/'.$record['path']);
            $packageLockPath = base_path('package-lock.json');
            $packageLockHash = is_file($packageLockPath) ? hash_file('sha256', $packageLockPath) : false;

            if ($metadata['format'] !== 'koma-build-v1'
                || $metadata['release_version'] !== $version
                || $metadata['composer_lock_sha256'] !== $composerLock['sha256']
                || ! is_string($packageLockHash)
                || ! hash_equals($metadata['package_lock_sha256'], $packageLockHash)) {
                throw new SiteBackupException('KomaEngine build metadata does not match the running release.');
            }

            $commit = $metadata['source_commit'];
            $dirty = $this->sourceDirty($metadata['source_dirty']);
            $buildId = $metadata['build_id'];
            if (($commit !== 'unavailable' && preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1)
                || $buildId === ''
                || ($metadata['source_policy'] === 'exact-commit'
                    && ($dirty !== false || $commit === 'unavailable' || $buildId !== $version.'+'.substr($commit, 0, 12)))) {
                throw new SiteBackupException('KomaEngine build metadata contains an inconsistent release identity.');
            }

            return [[
                'version' => $version,
                'git_commit' => $commit,
                'composer_lock_sha256' => $composerLock['sha256'],
                'build_metadata_sha256' => $record['sha256'],
                'build_id' => $buildId,
                'source_dirty' => $dirty,
            ], $this->withoutRole($record)];
        }

        if (config('koma.release.trusted') === true) {
            throw new SiteBackupException('Trusted KomaEngine builds require build metadata.');
        }

        $configuredCommit = config('koma.release.commit', 'unavailable');
        if (! is_string($configuredCommit)
            || ($configuredCommit !== 'unavailable' && preg_match('/\A[0-9a-f]{40}\z/D', $configuredCommit) !== 1)) {
            throw new SiteBackupException('KomaEngine release commit is invalid.');
        }
        $commit = $configuredCommit === 'unavailable' ? $this->gitCommit() : $configuredCommit;
        $buildId = config('koma.release.build_id', 'development');
        if (! is_string($buildId) || $buildId === '') {
            throw new SiteBackupException('KomaEngine release build identity is invalid.');
        }

        return [[
            'version' => $version,
            'git_commit' => $commit,
            'composer_lock_sha256' => $composerLock['sha256'],
            'build_metadata_sha256' => null,
            'build_id' => $buildId,
            'source_dirty' => $this->sourceDirty(config('koma.release.source_dirty', 'unknown')),
        ], null];
    }

    /** @return array<string, string> */
    private function parseBuildMetadata(string $path): array
    {
        $allowed = [
            'format', 'artifact', 'release_version', 'source_commit', 'source_dirty',
            'source_policy', 'build_id', 'source_manifest_sha256',
            'composer_lock_sha256', 'package_lock_sha256', 'php', 'composer', 'node',
        ];
        $metadata = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if ($line === '' || ! str_contains($line, '=')) {
                throw new SiteBackupException('KomaEngine build metadata is malformed.');
            }
            [$key, $value] = explode('=', $line, 2);
            if (! in_array($key, $allowed, true) || array_key_exists($key, $metadata)) {
                throw new SiteBackupException('KomaEngine build metadata is malformed.');
            }
            $metadata[$key] = $value;
        }

        foreach ([
            'format', 'artifact', 'release_version', 'source_commit', 'source_dirty',
            'source_policy', 'build_id', 'composer_lock_sha256', 'package_lock_sha256',
        ] as $required) {
            if (! isset($metadata[$required]) || $metadata[$required] === '') {
                throw new SiteBackupException('KomaEngine build metadata is incomplete.');
            }
        }
        if (preg_match('/\A[a-f0-9]{64}\z/D', $metadata['composer_lock_sha256']) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $metadata['package_lock_sha256']) !== 1
            || ! in_array($metadata['artifact'], ['flatfile', 'docker'], true)
            || ! in_array($metadata['source_policy'], ['exact-commit', 'working-tree'], true)) {
            throw new SiteBackupException('KomaEngine build metadata is malformed.');
        }

        return $metadata;
    }

    private function sourceDirty(mixed $value): ?bool
    {
        return match ($value) {
            true, 'true' => true,
            false, 'false' => false,
            null, 'unknown' => null,
            default => throw new SiteBackupException('KomaEngine source-dirty identity is invalid.'),
        };
    }

    protected function databaseConnection(): Connection
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->assertTransactionalTables($connection);

            return $connection;
        }
        if ($driver !== 'sqlite') {
            throw new SiteBackupException('Site backup supports SQLite, MySQL, and MariaDB only.');
        }

        $database = $connection->getDatabaseName();
        if (! is_string($database) || $database === ':memory:' || ! str_starts_with($database, DIRECTORY_SEPARATOR)
            || ! is_file($database) || is_link($database)) {
            throw new SiteBackupException('SQLite site backup requires a regular absolute SQLite database file; in-memory and unsafe paths are unsupported.');
        }
        $this->assertNoSymlinks($database, 'SQLite database path');

        $version = $this->databaseVersion($connection, $driver);
        if (version_compare($version, '3.27.0', '<')) {
            throw new SiteBackupException('SQLite '.$version.' is too old for the required online backup mechanism. SQLite 3.27.0 or newer is required.');
        }

        return $connection;
    }

    private function captureMySql(Connection $connection, string $driver, string $destination, string $stage): void
    {
        $credentials = $stage.'/.database-client.cnf';
        try {
            $this->writeDatabaseClientCredentials($connection, $credentials);
            $this->mySqlDumper->dump($driver, [
                'database' => (string) $connection->getDatabaseName(),
            ], $credentials, $destination);
        } finally {
            if (is_file($credentials) || is_link($credentials)) {
                unlink($credentials);
            }
        }

        if (! is_file($destination) || is_link($destination)) {
            throw new SiteBackupException('Logical database dump did not produce a safe artifact.');
        }
    }

    private function captureSqlite(Connection $connection, string $destination): void
    {
        try {
            $quotedDestination = $connection->getPdo()->quote($destination);
            if (! is_string($quotedDestination)) {
                throw new SiteBackupException('SQLite could not prepare the backup destination.');
            }
            $connection->unprepared('VACUUM INTO '.$quotedDestination);
        } catch (Throwable) {
            throw new SiteBackupException('SQLite online backup failed. The source database was not copied directly.');
        }

        if (! is_file($destination) || is_link($destination)) {
            throw new SiteBackupException('SQLite online backup did not produce a safe database artifact.');
        }
    }

    /**
     * @param  array<int, array{role: string, path: string, bytes: int, sha256: string}>  $files
     * @return array<int, array<string, mixed>>
     */
    private function copyEnabledExtensions(string $stage, array &$files): array
    {
        $extensions = [];
        $activeTheme = $this->themes->active();
        if ($activeTheme !== null) {
            $extensions[] = $this->copyExtension('theme', $activeTheme, $stage, $files);
        }

        foreach ($this->plugins->all() as $plugin) {
            if (($plugin['enabled'] ?? false) === true) {
                $extensions[] = $this->copyExtension('plugin', $plugin, $stage, $files);
            }
        }

        return $extensions;
    }

    /**
     * @param  array<string, mixed>  $extension
     * @param  array<int, array{role: string, path: string, bytes: int, sha256: string}>  $files
     * @return array<string, mixed>
     */
    private function copyExtension(string $kind, array $extension, string $stage, array &$files): array
    {
        $name = $extension['slug'] ?? null;
        $source = $extension['path'] ?? null;
        if (! is_string($name) || preg_match('/\A[a-z0-9][a-z0-9-]*\z/D', $name) !== 1
            || ! is_string($source) || ! is_dir($source)) {
            throw new SiteBackupException('Enabled extension inventory is invalid.');
        }

        $category = $kind === 'theme' ? 'themes' : 'plugins';
        $target = $stage.'/release/extensions/'.$category.'/'.$name;
        $extensionFiles = [];
        $this->copyDirectory($source, $target, 'release', $stage, $files, $extensionFiles);
        $manifestPath = $target.'/'.($kind === 'theme' ? 'theme.json' : 'plugin.json');
        $manifest = null;
        foreach ($extensionFiles as $file) {
            if ($file['path'] === str_replace(DIRECTORY_SEPARATOR, '/', substr($manifestPath, strlen($stage) + 1))) {
                $manifest = $this->withoutRole($file);
                break;
            }
        }
        if ($manifest === null) {
            throw new SiteBackupException('Enabled extension manifest is missing from the copied inventory.');
        }

        return [
            'kind' => $kind,
            'name' => $name,
            'enabled' => true,
            'manifest' => $manifest,
            'files' => array_map(fn (array $file) => $this->withoutRole($file), $extensionFiles),
        ];
    }

    /**
     * @param  array<int, array{role: string, path: string, bytes: int, sha256: string}>  $files
     * @param  array<int, array{role: string, path: string, bytes: int, sha256: string}>|null  $extensionFiles
     */
    private function copyDirectory(string $source, string $target, string $role, string $stage, array &$files, ?array &$extensionFiles = null): void
    {
        if (! is_dir($source)) {
            $this->ensureDirectory($target);

            return;
        }
        if (is_link($source)) {
            throw new SiteBackupException('Backup source contains a symbolic link.');
        }
        $this->assertNoSymlinks($source, 'Backup source path');
        $this->ensureDirectory($target);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );
        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $sourcePath = $entry->getPathname();
            if ($entry->isLink()) {
                throw new SiteBackupException('Backup source contains a symbolic link.');
            }
            if (! $entry->isFile()) {
                continue;
            }
            $relative = substr($sourcePath, strlen(rtrim($source, DIRECTORY_SEPARATOR)) + 1);
            $record = $this->copyFile($sourcePath, $target.DIRECTORY_SEPARATOR.$relative, $role, $stage);
            $files[] = $record;
            if ($extensionFiles !== null) {
                $extensionFiles[] = $record;
            }
        }
    }

    /**
     * @return array{role: string, path: string, bytes: int, sha256: string}
     */
    private function copyFile(string $source, string $destination, string $role, string $stage): array
    {
        if (! is_file($source) || is_link($source)) {
            throw new SiteBackupException('Backup source file is missing or unsafe.');
        }
        $this->assertNoSymlinks($source, 'Backup source path');
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
            throw new SiteBackupException('Backup artifact could not be copied safely.');
        }

        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            while (! feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                if ($chunk === false) {
                    throw new SiteBackupException('Backup artifact could not be read.');
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

        return [
            'role' => $role,
            'path' => str_replace(DIRECTORY_SEPARATOR, '/', substr($destination, strlen($stage) + 1)),
            'bytes' => $bytes,
            'sha256' => hash_final($hash),
        ];
    }

    /**
     * @return array{role: string, path: string, bytes: int, sha256: string}
     */
    private function describeFile(string $path, string $role, string $stage): array
    {
        if (! is_file($path) || is_link($path)) {
            throw new SiteBackupException('Backup artifact is missing or unsafe.');
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new SiteBackupException('Backup artifact could not be hashed.');
        }
        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    throw new SiteBackupException('Backup artifact could not be hashed.');
                }
                if ($chunk !== '') {
                    hash_update($hash, $chunk);
                    $bytes += strlen($chunk);
                }
            }
        } finally {
            fclose($stream);
        }

        return [
            'role' => $role,
            'path' => str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($stage) + 1)),
            'bytes' => $bytes,
            'sha256' => hash_final($hash),
        ];
    }

    /**
     * @param  array{role: string, path: string, bytes: int, sha256: string}  $file
     * @return array{path: string, bytes: int, sha256: string}
     */
    private function withoutRole(array $file): array
    {
        unset($file['role']);

        return $file;
    }

    private function validateDestination(string $destination): string
    {
        if (! str_starts_with($destination, DIRECTORY_SEPARATOR) || str_contains($destination, "\0")) {
            throw new SiteBackupException('Backup destination must be an absolute path.');
        }
        if (file_exists($destination) || is_link($destination)) {
            throw new SiteBackupException('Backup destination must not already exist.');
        }
        $parent = dirname($destination);
        $name = basename($destination);
        if ($name === '.' || $name === '' || $name === DIRECTORY_SEPARATOR || ! is_dir($parent) || ! is_writable($parent)) {
            throw new SiteBackupException('Backup destination parent must be an existing writable directory.');
        }
        $this->assertNoSymlinks($parent, 'Backup destination path');
        $parent = realpath($parent);
        if ($parent === false) {
            throw new SiteBackupException('Backup destination parent could not be resolved.');
        }
        $target = $parent.DIRECTORY_SEPARATOR.$name;

        foreach ([base_path(), storage_path()] as $unsafeRoot) {
            $root = realpath($unsafeRoot);
            if ($root !== false && $this->inside($target, $root)) {
                throw new SiteBackupException('Backup destination must be outside the application and storage directories.');
            }
        }

        return $target;
    }

    private function validateSecretBundle(?string $secretBundle): string
    {
        if (! is_string($secretBundle) || ! str_starts_with($secretBundle, DIRECTORY_SEPARATOR)
            || ! is_file($secretBundle) || is_link($secretBundle)) {
            throw new SiteBackupException('An operator-provided encrypted secret bundle is required.');
        }
        $this->assertNoSymlinks($secretBundle, 'Secret bundle path');
        $resolved = realpath($secretBundle);
        $name = basename($secretBundle);
        if ($resolved === false || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $name) !== 1) {
            throw new SiteBackupException('Secret bundle must have a safe filename.');
        }

        return $resolved;
    }

    private function stagePath(string $target): string
    {
        try {
            $suffix = bin2hex(random_bytes(10));
        } catch (Throwable) {
            throw new SiteBackupException('Secure backup staging name could not be generated.');
        }

        return dirname($target).DIRECTORY_SEPARATOR.'.'.basename($target).'.staging-'.$suffix;
    }

    private function databaseVersion(Connection $connection, string $driver): string
    {
        try {
            $version = $connection->scalar($driver === 'sqlite' ? 'select sqlite_version()' : 'select version()');
        } catch (Throwable) {
            throw new SiteBackupException('Database version could not be determined for safe backup.');
        }
        if (! is_string($version) || $version === '') {
            throw new SiteBackupException('Database version could not be determined for safe backup.');
        }

        return $version;
    }

    private function assertTransactionalTables(Connection $connection): void
    {
        try {
            $count = $connection->scalar(
                "select count(*) from information_schema.tables where table_schema = database() and table_type = 'BASE TABLE' and engine is not null and engine <> 'InnoDB'",
            );
        } catch (Throwable) {
            throw new SiteBackupException('Database transaction-capability preflight failed.');
        }
        if (! is_numeric($count)) {
            throw new SiteBackupException('Database transaction-capability preflight returned an invalid result.');
        }
        if ((int) $count !== 0) {
            throw new SiteBackupException('Database backup requires every base table to use InnoDB for a consistent logical snapshot.');
        }
    }

    private function writeDatabaseClientCredentials(Connection $connection, string $path): void
    {
        $settings = [
            'user' => $connection->getConfig('username'),
            'password' => $connection->getConfig('password'),
            'host' => $connection->getConfig('host'),
            'port' => $connection->getConfig('port'),
            'socket' => $connection->getConfig('unix_socket'),
        ];
        foreach ($settings as $value) {
            if ($value !== null && (! is_scalar($value) || str_contains((string) $value, "\0")
                || str_contains((string) $value, "\n") || str_contains((string) $value, "\r"))) {
                throw new SiteBackupException('Database client credentials contain an unsupported control character.');
            }
        }

        $lines = ['[client]'];
        foreach (['user', 'password', 'host', 'port', 'socket'] as $key) {
            $value = $settings[$key];
            if ($value === null || $value === '') {
                continue;
            }
            $lines[] = $key.'="'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value).'"';
        }
        $content = implode("\n", $lines)."\n";

        if (file_put_contents($path, $content, LOCK_EX) === false || ! chmod($path, 0600)) {
            throw new SiteBackupException('Temporary database client credentials could not be protected.');
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
                throw new SiteBackupException('Backup artifact could not be written completely.');
            }
            $offset += $written;
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new SiteBackupException('Backup directory could not be created.');
        }
    }

    /**
     * The documented release layout installs <release>/storage as a link to
     * shared storage so releases can be switched side by side. Follow that one
     * operator-owned link to the real directory. Every path beneath the
     * storage root is still checked, so a link planted inside it is refused.
     */
    private function throughStorageLink(string $path): string
    {
        $storage = rtrim(storage_path(), DIRECTORY_SEPARATOR);
        $real = is_link($storage) ? realpath($storage) : false;

        if ($real === false || ! $this->inside($path, $storage)) {
            return $path;
        }

        return $real.substr($path, strlen($storage));
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

    private function inside(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    private function secretFormat(string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        return $extension === '' ? 'opaque' : strtolower($extension);
    }

    private function gitCommit(): string
    {
        $git = base_path('.git');
        $head = $git.DIRECTORY_SEPARATOR.'HEAD';
        if (! is_dir($git) || ! is_file($head) || is_link($head)) {
            return 'unavailable';
        }
        $value = trim((string) file_get_contents($head));
        if (preg_match('/\A[0-9a-f]{40}\z/D', $value) === 1) {
            return $value;
        }
        if (preg_match('/\Aref: (refs\/[A-Za-z0-9._\/-]+)\z/D', $value, $matches) !== 1) {
            return 'unavailable';
        }
        $ref = $git.DIRECTORY_SEPARATOR.$matches[1];
        if (! is_file($ref) || is_link($ref)) {
            return 'unavailable';
        }
        $commit = trim((string) file_get_contents($ref));

        return preg_match('/\A[0-9a-f]{40}\z/D', $commit) === 1 ? $commit : 'unavailable';
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
