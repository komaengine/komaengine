<?php

namespace App\Engine\Health;

/**
 * The release identity the Health page shows. The release ZIP and the image
 * both ship KOMA-BUILD-METADATA.txt, so the file is read first and the
 * KOMA_RELEASE_* environment is used only when there is none. Never throws: a
 * file that cannot be read or does not hold an official exact-commit build
 * reports why instead.
 */
final class BuildIdentity
{
    /** The same keys SiteBackupCreator accepts in the file. */
    private const ALLOWED = [
        'format', 'artifact', 'release_version', 'source_commit', 'source_dirty',
        'source_policy', 'build_id', 'source_manifest_sha256',
        'composer_lock_sha256', 'package_lock_sha256', 'php', 'composer', 'node',
    ];

    private const REQUIRED = [
        'format', 'artifact', 'release_version', 'source_commit', 'source_dirty',
        'source_policy', 'build_id', 'composer_lock_sha256', 'package_lock_sha256',
    ];

    /** Far larger than any real file; a bigger one is not build metadata. */
    private const MAX_BYTES = 65536;

    /** @return array{version: string, commit: string, build_id: string, official: bool, reason: string|null, source: 'metadata'|'environment'} */
    public static function forThisSite(): array
    {
        $path = config('koma.release.metadata_path');

        return self::read(
            is_string($path) ? $path : '',
            (string) config('koma.version'),
            [
                'commit' => (string) config('koma.release.commit'),
                'build_id' => (string) config('koma.release.build_id'),
                'trusted' => filter_var(config('koma.release.trusted'), FILTER_VALIDATE_BOOL),
            ],
        );
    }

    /**
     * @param  array{commit: string, build_id: string, trusted: bool}  $environment
     * @return array{version: string, commit: string, build_id: string, official: bool, reason: string|null, source: 'metadata'|'environment'}
     */
    public static function read(string $path, string $version, array $environment): array
    {
        $fallback = [
            'version' => $version,
            'commit' => $environment['commit'],
            'build_id' => $environment['build_id'],
            'official' => $environment['trusted'],
            'reason' => null,
            'source' => 'environment',
        ];

        if ($path === '' || (! @is_file($path) && ! @is_link($path))) {
            return $fallback;
        }

        $contents = @is_readable($path) ? @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1) : false;
        if (! is_string($contents)) {
            return [...$fallback, 'official' => false, 'reason' => 'The build metadata file cannot be read.'];
        }
        if (strlen($contents) > self::MAX_BYTES) {
            return [...$fallback, 'official' => false, 'reason' => 'The build metadata file is too large to be build metadata.'];
        }

        $parsed = self::parse($contents);
        if (is_string($parsed)) {
            return [...$fallback, 'official' => false, 'reason' => 'The build metadata file is malformed: '.$parsed.'.'];
        }

        $reason = self::notOfficialBecause($parsed, $version);

        return [
            'version' => $version,
            'commit' => $parsed['source_commit'],
            'build_id' => $parsed['build_id'],
            'official' => $reason === null,
            'reason' => $reason,
            'source' => 'metadata',
        ];
    }

    /**
     * The key rules of SiteBackupCreator::parseBuildMetadata.
     *
     * @return array<string, string>|string the keys, or what is wrong with them
     */
    private static function parse(string $contents): array|string
    {
        $lines = explode("\n", $contents);
        if (end($lines) === '') {
            array_pop($lines);
        }

        $metadata = [];
        foreach ($lines as $index => $line) {
            $number = $index + 1;
            if ($line === '' || ! str_contains($line, '=')) {
                return "line {$number} is not a key=value pair";
            }
            [$key, $value] = explode('=', $line, 2);
            if (! in_array($key, self::ALLOWED, true)) {
                return "line {$number} has an unknown key";
            }
            if (array_key_exists($key, $metadata)) {
                return "line {$number} repeats {$key}";
            }
            $metadata[$key] = $value;
        }

        foreach (self::REQUIRED as $required) {
            if (! isset($metadata[$required]) || $metadata[$required] === '') {
                return "{$required} is missing";
            }
        }
        foreach (['composer_lock_sha256', 'package_lock_sha256'] as $hash) {
            if (preg_match('/\A[a-f0-9]{64}\z/D', $metadata[$hash]) !== 1) {
                return "{$hash} is not a SHA-256 hash";
            }
        }
        if (! in_array($metadata['artifact'], ['flatfile', 'docker'], true)) {
            return 'artifact is neither flatfile nor docker';
        }
        if (! in_array($metadata['source_policy'], ['exact-commit', 'working-tree'], true)) {
            return 'source_policy is neither exact-commit nor working-tree';
        }

        return $metadata;
    }

    /** @param  array<string, string>  $metadata */
    private static function notOfficialBecause(array $metadata, string $version): ?string
    {
        $commit = $metadata['source_commit'];

        return match (true) {
            $metadata['format'] !== 'koma-build-v1' => 'The build metadata file is not in the koma-build-v1 format.',
            $metadata['source_policy'] !== 'exact-commit' => 'This copy was built from a working tree, not from one exact commit.',
            $metadata['source_dirty'] !== 'false' => 'The build does not record a clean source tree.',
            $metadata['release_version'] !== $version => "The build metadata is for version {$metadata['release_version']}, but this site runs {$version}.",
            preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1 => 'The build metadata does not name a full source commit.',
            $metadata['build_id'] !== $version.'+'.substr($commit, 0, 12) => 'The build ID does not match the version and source commit.',
            default => null,
        };
    }
}
