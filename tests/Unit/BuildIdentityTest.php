<?php

use App\Engine\Health\BuildIdentity;

const BUILD_IDENTITY_COMMIT = '0123456789abcdef0123456789abcdef01234567';

/** The lines scripts/build-flatfile.sh writes, with some replaced. */
function buildIdentityLines(array $overrides = []): array
{
    $lines = array_merge([
        'format' => 'koma-build-v1',
        'artifact' => 'flatfile',
        'release_version' => '1.1.1',
        'source_commit' => BUILD_IDENTITY_COMMIT,
        'source_dirty' => 'false',
        'source_policy' => 'exact-commit',
        'build_id' => '1.1.1+0123456789ab',
        'source_manifest_sha256' => str_repeat('a', 64),
        'composer_lock_sha256' => str_repeat('b', 64),
        'package_lock_sha256' => str_repeat('c', 64),
        'php' => '8.4.27',
        'composer' => 'Composer version 2.9.8 2026-05-13 12:00:00',
        'node' => 'v22.23.4',
    ], $overrides);

    return array_map(fn (string $key, string $value) => $key.'='.$value, array_keys($lines), $lines);
}

function readBuildIdentity(?string $contents, bool $trusted = false): array
{
    $path = sys_get_temp_dir().'/koma-build-identity-'.bin2hex(random_bytes(8)).'.txt';
    if ($contents !== null) {
        file_put_contents($path, $contents);
    }

    try {
        return BuildIdentity::read($path, '1.1.1', [
            'commit' => 'unavailable',
            'build_id' => 'development',
            'trusted' => $trusted,
        ]);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
}

test('a release ZIP file is an official build', function () {
    expect(readBuildIdentity(implode("\n", buildIdentityLines())."\n"))->toBe([
        'version' => '1.1.1',
        'commit' => BUILD_IDENTITY_COMMIT,
        'build_id' => '1.1.1+0123456789ab',
        'official' => true,
        'reason' => null,
        'source' => 'metadata',
    ]);
});

test('an image file from the release workflow is an official build', function () {
    $lines = array_values(array_filter(
        buildIdentityLines(['artifact' => 'docker']),
        fn (string $line) => ! preg_match('/\A(source_manifest_sha256|php|composer|node)=/', $line),
    ));

    expect(readBuildIdentity(implode("\n", $lines)."\n"))
        ->toMatchArray(['official' => true, 'reason' => null, 'source' => 'metadata']);
});

test('a well-formed file that is not an official exact-commit build says why', function (array $overrides, string $reason) {
    $identity = readBuildIdentity(implode("\n", buildIdentityLines($overrides))."\n", trusted: true);

    expect($identity['official'])->toBeFalse()
        ->and($identity['source'])->toBe('metadata')
        ->and($identity['reason'])->toBe($reason)
        ->and($identity['commit'])->toBe($overrides['source_commit'] ?? BUILD_IDENTITY_COMMIT)
        ->and($identity['build_id'])->toBe($overrides['build_id'] ?? '1.1.1+0123456789ab');
})->with([
    'working-tree image' => [
        ['artifact' => 'docker', 'source_policy' => 'working-tree', 'source_commit' => 'unavailable', 'source_dirty' => 'unknown', 'build_id' => 'development'],
        'This copy was built from a working tree, not from one exact commit.',
    ],
    'other version' => [['release_version' => '1.1.0', 'build_id' => '1.1.0+0123456789ab'], 'The build metadata is for version 1.1.0, but this site runs 1.1.1.'],
    'other format' => [['format' => 'koma-build-v2'], 'The build metadata file is not in the koma-build-v1 format.'],
    'dirty source' => [['source_dirty' => 'true'], 'The build does not record a clean source tree.'],
    'unknown source state' => [['source_dirty' => 'unknown'], 'The build does not record a clean source tree.'],
    'short commit' => [['source_commit' => '0123456789ab', 'build_id' => '1.1.1+0123456789ab'], 'The build metadata does not name a full source commit.'],
    'uppercase commit' => [['source_commit' => strtoupper(BUILD_IDENTITY_COMMIT)], 'The build metadata does not name a full source commit.'],
    'other build ID' => [['build_id' => '1.1.1+fedcba987654'], 'The build ID does not match the version and source commit.'],
]);

test('a malformed file says what is wrong and falls back to the environment', function (string $contents, string $problem) {
    expect(readBuildIdentity($contents, trusted: true))->toBe([
        'version' => '1.1.1',
        'commit' => 'unavailable',
        'build_id' => 'development',
        'official' => false,
        'reason' => 'The build metadata file is malformed: '.$problem.'.',
        'source' => 'environment',
    ]);
})->with([
    'empty' => ['', 'format is missing'],
    'blank line' => [implode("\n", array_merge(array_slice(buildIdentityLines(), 0, 2), [''], array_slice(buildIdentityLines(), 2))), 'line 3 is not a key=value pair'],
    'no equals sign' => [implode("\n", array_merge(buildIdentityLines(), ['official'])), 'line 14 is not a key=value pair'],
    'unknown key' => [implode("\n", array_merge(buildIdentityLines(), ['trusted=true'])), 'line 14 has an unknown key'],
    'duplicate key' => [implode("\n", array_merge(buildIdentityLines(), ['source_dirty=false'])), 'line 14 repeats source_dirty'],
    'missing key' => [implode("\n", array_slice(buildIdentityLines(), 1)), 'format is missing'],
    'empty value' => [implode("\n", buildIdentityLines(['build_id' => ''])), 'build_id is missing'],
    'bad lock hash' => [implode("\n", buildIdentityLines(['composer_lock_sha256' => 'abc'])), 'composer_lock_sha256 is not a SHA-256 hash'],
    'unknown artifact' => [implode("\n", buildIdentityLines(['artifact' => 'tarball'])), 'artifact is neither flatfile nor docker'],
    'unknown policy' => [implode("\n", buildIdentityLines(['source_policy' => 'trusted'])), 'source_policy is neither exact-commit nor working-tree'],
    'Windows line endings' => [implode("\r\n", buildIdentityLines())."\r\n", 'composer_lock_sha256 is not a SHA-256 hash'],
]);

test('a file that cannot be read or is far too large is not an official build', function () {
    $link = sys_get_temp_dir().'/koma-build-identity-'.bin2hex(random_bytes(8)).'.txt';
    symlink($link.'-missing', $link);

    try {
        expect(BuildIdentity::read($link, '1.1.1', ['commit' => 'unavailable', 'build_id' => 'development', 'trusted' => true]))
            ->toMatchArray(['official' => false, 'reason' => 'The build metadata file cannot be read.', 'source' => 'environment']);
    } finally {
        unlink($link);
    }

    expect(readBuildIdentity(implode("\n", buildIdentityLines())."\n".str_repeat('x', 70000), trusted: true))
        ->toMatchArray(['official' => false, 'reason' => 'The build metadata file is too large to be build metadata.']);
});

test('without a file the environment decides, as before', function (bool $trusted) {
    expect(readBuildIdentity(null, $trusted))->toBe([
        'version' => '1.1.1',
        'commit' => 'unavailable',
        'build_id' => 'development',
        'official' => $trusted,
        'reason' => null,
        'source' => 'environment',
    ]);

    expect(BuildIdentity::read('', '1.1.1', ['commit' => 'c', 'build_id' => 'b', 'trusted' => $trusted]))
        ->toMatchArray(['official' => $trusted, 'source' => 'environment']);
})->with(['trusted environment' => [true], 'development' => [false]]);
