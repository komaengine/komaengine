<?php

namespace App\Recovery;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

class SiteBackupVerifier
{
    private const FORMAT = 'koma-site-backup-v1';

    /**
     * Validate a published backup without changing it.
     */
    public function verify(string $backupPath): SiteBackup
    {
        $root = $this->absoluteDirectory($backupPath, 'Backup directory');
        $manifestPath = $root.DIRECTORY_SEPARATOR.'manifest.json';

        if (! is_file($manifestPath) || is_link($manifestPath)) {
            throw new SiteBackupException('Backup manifest is missing or unsafe.');
        }

        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new SiteBackupException('Backup manifest is not valid JSON.');
        }

        if (! is_array($manifest)) {
            throw new SiteBackupException('Backup manifest must be an object.');
        }

        $this->validateManifest($manifest);
        $expected = $this->validateFileInventory($manifest, $root);
        $this->rejectUnexpectedFiles($root, $expected);

        return new SiteBackup($root, $manifest);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function validateManifest(array $manifest): void
    {
        $this->assertExactKeys($manifest, [
            'format', 'schema_version', 'created_at', 'backup_tool', 'database', 'release', 'secrets', 'files',
        ], 'manifest');

        if (($manifest['format'] ?? null) !== self::FORMAT || ($manifest['schema_version'] ?? null) !== 1) {
            throw new SiteBackupException('Backup manifest format is unsupported.');
        }

        if (! is_string($manifest['created_at']) || strtotime($manifest['created_at']) === false) {
            throw new SiteBackupException('Backup manifest capture time is invalid.');
        }

        $tool = $this->object($manifest['backup_tool'], 'backup_tool');
        $this->assertExactKeys($tool, ['name', 'version'], 'backup_tool');
        if ($tool['name'] !== 'KomaEngine' || ! is_string($tool['version']) || $tool['version'] === '') {
            throw new SiteBackupException('Backup tool metadata is invalid.');
        }

        $database = $this->object($manifest['database'], 'database');
        $this->assertExactKeys($database, ['driver', 'server_version', 'artifact'], 'database');
        if (! in_array($database['driver'], ['sqlite', 'mysql', 'mariadb'], true)
            || ! is_string($database['server_version']) || $database['server_version'] === '') {
            throw new SiteBackupException('Backup database metadata is invalid.');
        }
        $databaseArtifact = $this->fileRecord($database['artifact'], 'database artifact');
        $expectedDatabasePath = $database['driver'] === 'sqlite'
            ? 'database/database.sqlite'
            : 'database/database.sql';
        if ($databaseArtifact['path'] !== $expectedDatabasePath) {
            throw new SiteBackupException('Backup database artifact path is invalid.');
        }

        $release = $this->object($manifest['release'], 'release');
        $currentIdentity = array_key_exists('build_metadata', $release);
        $this->assertExactKeys(
            $release,
            $currentIdentity
                ? ['identity', 'composer_lock', 'build_metadata', 'extensions']
                : ['identity', 'composer_lock', 'extensions'],
            'release',
        );
        $identity = $this->object($release['identity'], 'release identity');
        $this->assertExactKeys(
            $identity,
            $currentIdentity
                ? ['version', 'git_commit', 'composer_lock_sha256', 'build_metadata_sha256', 'build_id', 'source_dirty']
                : ['git_commit', 'composer_lock_sha256', 'build_metadata_sha256'],
            'release identity',
        );
        if (! is_string($identity['git_commit']) || $identity['git_commit'] === ''
            || ! $this->isSha256($identity['composer_lock_sha256'])
            || ($identity['build_metadata_sha256'] !== null && ! $this->isSha256($identity['build_metadata_sha256']))) {
            throw new SiteBackupException('Backup release identity is invalid.');
        }
        if ($currentIdentity
            && (! is_string($identity['version'])
                || preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?\z/D', $identity['version']) !== 1
                || $identity['version'] !== $tool['version']
                || ($identity['git_commit'] !== 'unavailable'
                    && preg_match('/\A[0-9a-f]{40}\z/D', $identity['git_commit']) !== 1)
                || ! is_string($identity['build_id']) || $identity['build_id'] === ''
                || (! is_bool($identity['source_dirty']) && $identity['source_dirty'] !== null))) {
            throw new SiteBackupException('Backup release identity is inconsistent.');
        }
        $composerLock = $this->fileRecord($release['composer_lock'], 'composer lock');
        if ($composerLock['path'] !== 'release/composer.lock'
            || $composerLock['sha256'] !== $identity['composer_lock_sha256']) {
            throw new SiteBackupException('Backup Composer lock cross-link is invalid.');
        }
        if ($currentIdentity) {
            if ($release['build_metadata'] === null) {
                if ($identity['build_metadata_sha256'] !== null) {
                    throw new SiteBackupException('Backup build metadata cross-link is invalid.');
                }
            } else {
                $buildMetadata = $this->fileRecord($release['build_metadata'], 'build metadata');
                if ($buildMetadata['path'] !== 'release/KOMA-BUILD-METADATA.txt'
                    || $buildMetadata['sha256'] !== $identity['build_metadata_sha256']) {
                    throw new SiteBackupException('Backup build metadata cross-link is invalid.');
                }
            }
        }

        if (! is_array($release['extensions'])) {
            throw new SiteBackupException('Backup extension inventory is invalid.');
        }
        foreach ($release['extensions'] as $extension) {
            $this->validateExtension($extension);
        }

        $secrets = $this->object($manifest['secrets'], 'secrets');
        $this->assertExactKeys($secrets, ['bundle', 'format'], 'secrets');
        if (! is_string($secrets['format']) || $secrets['format'] === '') {
            throw new SiteBackupException('Backup secret-bundle format is invalid.');
        }
        $bundle = $this->fileRecord($secrets['bundle'], 'secret bundle');
        if (! str_starts_with($bundle['path'], 'secrets/')) {
            throw new SiteBackupException('Backup secret-bundle path is invalid.');
        }

        if (! is_array($manifest['files']) || count($manifest['files']) < 3) {
            throw new SiteBackupException('Backup file inventory is incomplete.');
        }
    }

    private function validateExtension(mixed $extension): void
    {
        $extension = $this->object($extension, 'extension');
        $this->assertExactKeys($extension, ['kind', 'name', 'enabled', 'manifest', 'files'], 'extension');
        if (! in_array($extension['kind'], ['plugin', 'theme'], true)
            || ! is_string($extension['name'])
            || preg_match('/\A[a-z0-9][a-z0-9-]*\z/D', $extension['name']) !== 1
            || ! is_bool($extension['enabled'])
            || ! is_array($extension['files'])
            || $extension['files'] === []) {
            throw new SiteBackupException('Backup extension inventory is invalid.');
        }

        $manifest = $this->fileRecord($extension['manifest'], 'extension manifest');
        $base = 'release/extensions/'.($extension['kind'] === 'plugin' ? 'plugins' : 'themes').'/'.$extension['name'].'/';
        if (! str_starts_with($manifest['path'], $base)) {
            throw new SiteBackupException('Backup extension manifest path is invalid.');
        }

        $filePaths = [];
        foreach ($extension['files'] as $file) {
            $file = $this->fileRecord($file, 'extension file');
            if (! str_starts_with($file['path'], $base)) {
                throw new SiteBackupException('Backup extension file path is invalid.');
            }
            $filePaths[] = $file['path'];
        }

        if (! in_array($manifest['path'], $filePaths, true)) {
            throw new SiteBackupException('Backup extension manifest is missing from its file inventory.');
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, true>
     */
    private function validateFileInventory(array $manifest, string $root): array
    {
        $files = [];

        foreach ($manifest['files'] as $file) {
            $file = $this->fileRecord($file, 'file inventory entry', true);
            if (! in_array($file['role'], ['database', 'public_media', 'private_input', 'release', 'secret_bundle'], true)
                || isset($files[$file['path']])) {
                throw new SiteBackupException('Backup file inventory contains an invalid or duplicate entry.');
            }

            $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file['path']);
            if (! is_file($path) || is_link($path)) {
                throw new SiteBackupException('Backup inventory references a missing or unsafe file.');
            }
            $this->assertNoSymlinks($path);

            $actualBytes = filesize($path);
            $actualHash = $this->hashFile($path);
            if ($actualBytes !== $file['bytes'] || ! hash_equals($file['sha256'], $actualHash)) {
                throw new SiteBackupException('Backup file size or SHA-256 verification failed.');
            }
            if ($file['role'] === 'database' && in_array($manifest['database']['driver'], ['mysql', 'mariadb'], true)) {
                $this->validateLogicalDatabaseDump($path);
            }
            $files[$file['path']] = true;
        }

        $database = $this->fileRecord($manifest['database']['artifact'], 'database artifact');
        $composerLock = $this->fileRecord($manifest['release']['composer_lock'], 'composer lock');
        $secretBundle = $this->fileRecord($manifest['secrets']['bundle'], 'secret bundle');
        $requiredFiles = [$database, $composerLock, $secretBundle];
        if (($manifest['release']['build_metadata'] ?? null) !== null) {
            $requiredFiles[] = $this->fileRecord($manifest['release']['build_metadata'], 'build metadata');
        }
        foreach ($requiredFiles as $requiredFile) {
            if (! isset($files[$requiredFile['path']])) {
                throw new SiteBackupException('Backup manifest cross-link is absent from the file inventory.');
            }
        }
        if (($this->inventoryRole($manifest['files'], $database['path']) ?? null) !== 'database'
            || ($this->inventoryRole($manifest['files'], $composerLock['path']) ?? null) !== 'release'
            || ($this->inventoryRole($manifest['files'], $secretBundle['path']) ?? null) !== 'secret_bundle') {
            throw new SiteBackupException('Backup manifest file roles are invalid.');
        }
        if (($manifest['release']['build_metadata'] ?? null) !== null
            && ($this->inventoryRole($manifest['files'], 'release/KOMA-BUILD-METADATA.txt') ?? null) !== 'release') {
            throw new SiteBackupException('Backup build metadata file role is invalid.');
        }
        if (($manifest['release']['build_metadata'] ?? null) !== null) {
            $this->validateBuildMetadataIdentity(
                $root.DIRECTORY_SEPARATOR.'release'.DIRECTORY_SEPARATOR.'KOMA-BUILD-METADATA.txt',
                $manifest['release']['identity'],
            );
        }

        foreach ($manifest['release']['extensions'] as $extension) {
            foreach ($extension['files'] as $extensionFile) {
                $extensionFile = $this->fileRecord($extensionFile, 'extension file');
                if (! isset($files[$extensionFile['path']])
                    || ($this->inventoryRole($manifest['files'], $extensionFile['path']) ?? null) !== 'release') {
                    throw new SiteBackupException('Backup extension file is absent from the release inventory.');
                }
            }
        }

        return $files;
    }

    /** @param array<string, mixed> $identity */
    private function validateBuildMetadataIdentity(string $path, array $identity): void
    {
        $metadata = $this->parseBuildMetadata($path);
        $dirty = match ($metadata['source_dirty']) {
            'true' => true,
            'false' => false,
            'unknown' => null,
            default => throw new SiteBackupException('Backup build metadata is malformed.'),
        };

        if ($metadata['format'] !== 'koma-build-v1'
            || ! in_array($metadata['artifact'], ['flatfile', 'docker'], true)
            || $metadata['release_version'] !== $identity['version']
            || $metadata['source_commit'] !== $identity['git_commit']
            || $metadata['build_id'] !== $identity['build_id']
            || $dirty !== $identity['source_dirty']
            || $metadata['composer_lock_sha256'] !== $identity['composer_lock_sha256']
            || ($metadata['source_policy'] === 'exact-commit'
                && ($dirty !== false
                    || $metadata['source_commit'] === 'unavailable'
                    || $metadata['build_id'] !== $metadata['release_version'].'+'.substr($metadata['source_commit'], 0, 12)))) {
            throw new SiteBackupException('Backup build metadata content is inconsistent.');
        }
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
                throw new SiteBackupException('Backup build metadata is malformed.');
            }
            [$key, $value] = explode('=', $line, 2);
            if (! in_array($key, $allowed, true) || array_key_exists($key, $metadata)) {
                throw new SiteBackupException('Backup build metadata is malformed.');
            }
            $metadata[$key] = $value;
        }

        foreach ([
            'format', 'artifact', 'release_version', 'source_commit', 'source_dirty',
            'source_policy', 'build_id', 'composer_lock_sha256', 'package_lock_sha256',
        ] as $required) {
            if (! isset($metadata[$required]) || $metadata[$required] === '') {
                throw new SiteBackupException('Backup build metadata is incomplete.');
            }
        }
        if (preg_match('/\A[a-f0-9]{64}\z/D', $metadata['composer_lock_sha256']) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $metadata['package_lock_sha256']) !== 1
            || ! in_array($metadata['source_policy'], ['exact-commit', 'working-tree'], true)) {
            throw new SiteBackupException('Backup build metadata is malformed.');
        }

        return $metadata;
    }

    /**
     * @param  array<int, mixed>  $files
     */
    private function inventoryRole(array $files, string $path): ?string
    {
        foreach ($files as $file) {
            if (is_array($file) && ($file['path'] ?? null) === $path) {
                return is_string($file['role'] ?? null) ? $file['role'] : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, true>  $expected
     */
    private function rejectUnexpectedFiles(string $root, array $expected): void
    {
        foreach ($this->filesIn($root) as $file) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($root) + 1));
            if ($relative !== 'manifest.json' && ! isset($expected[$relative])) {
                throw new SiteBackupException('Backup contains a file absent from its inventory.');
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function filesIn(string $root): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($entry->isLink()) {
                throw new SiteBackupException('Backup contains a symbolic link.');
            }
            if ($entry->isFile()) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * @return array{path: string, bytes: int, sha256: string, role?: string}
     */
    private function fileRecord(mixed $value, string $context, bool $withRole = false): array
    {
        $file = $this->object($value, $context);
        $expected = $withRole ? ['role', 'path', 'bytes', 'sha256'] : ['path', 'bytes', 'sha256'];
        $this->assertExactKeys($file, $expected, $context);

        if (! is_string($file['path']) || ! $this->safeRelativePath($file['path'])
            || ! is_int($file['bytes']) || $file['bytes'] < 0
            || ! $this->isSha256($file['sha256'])) {
            throw new SiteBackupException("Backup {$context} is invalid.");
        }

        return $file;
    }

    /**
     * @return array<string, mixed>
     */
    private function object(mixed $value, string $context): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new SiteBackupException("Backup {$context} must be an object.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<int, string>  $keys
     */
    private function assertExactKeys(array $value, array $keys, string $context): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new SiteBackupException("Backup {$context} has an unsupported shape.");
        }
    }

    private function safeRelativePath(string $path): bool
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._\/-]*\z/D', $path) !== 1 || str_starts_with($path, '/')) {
            return false;
        }

        return ! in_array('.', explode('/', $path), true) && ! in_array('..', explode('/', $path), true);
    }

    private function isSha256(mixed $hash): bool
    {
        return is_string($hash) && preg_match('/\A[a-f0-9]{64}\z/D', $hash) === 1;
    }

    private function hashFile(string $path): string
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new SiteBackupException('A backup file could not be read for verification.');
        }

        $context = hash_init('sha256');
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    throw new SiteBackupException('A backup file could not be read for verification.');
                }
                if ($chunk !== '') {
                    hash_update($context, $chunk);
                }
            }
        } finally {
            fclose($stream);
        }

        return hash_final($context);
    }

    private function validateLogicalDatabaseDump(string $path): void
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new SiteBackupException('Logical database dump could not be read for validation.');
        }
        try {
            $opening = fread($stream, 65536);
            fseek($stream, max(0, filesize($path) - 65536));
            $closing = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }

        if (! is_string($opening) || ! is_string($closing)
            || (! str_contains($opening, 'MySQL dump') && ! str_contains($opening, 'MariaDB dump'))
            || ! str_contains($closing, 'Dump completed on')) {
            throw new SiteBackupException('Logical database dump structure is invalid.');
        }
    }

    private function absoluteDirectory(string $path, string $label): string
    {
        if (! str_starts_with($path, DIRECTORY_SEPARATOR) || ! is_dir($path) || is_link($path)) {
            throw new SiteBackupException("{$label} must be an existing absolute non-symlink directory.");
        }
        $this->assertNoSymlinks($path);
        $resolved = realpath($path);

        if ($resolved === false) {
            throw new SiteBackupException("{$label} could not be resolved.");
        }

        return $resolved;
    }

    private function assertNoSymlinks(string $path): void
    {
        $segments = explode(DIRECTORY_SEPARATOR, trim($path, DIRECTORY_SEPARATOR));
        $cursor = DIRECTORY_SEPARATOR;
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }
            $cursor .= ($cursor === DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR).$segment;
            if (is_link($cursor)) {
                throw new SiteBackupException('Backup path contains a symbolic link.');
            }
        }
    }
}
