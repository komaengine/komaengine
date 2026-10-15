<?php

use App\Engine\Plugins\PluginManager;
use App\Engine\Themes\ThemeManager;
use App\Recovery\MySqlSiteBackupDumper;
use App\Recovery\MySqlSiteBackupRestorer;
use App\Recovery\SiteBackupCreator;
use App\Recovery\SiteBackupException;
use App\Recovery\SiteBackupRestorer;
use App\Recovery\SiteBackupVerifier;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class RecoveryTestMaintenanceMode implements MaintenanceMode
{
    private bool $active = false;

    /** @var array<string, mixed> */
    private array $payload = [];

    public function activate(array $payload): void
    {
        $this->active = true;
        $this->payload = $payload;
    }

    public function deactivate(): void
    {
        $this->active = false;
        $this->payload = [];
    }

    public function active(): bool
    {
        return $this->active;
    }

    public function data(): array
    {
        return $this->payload;
    }
}

beforeEach(function () {
    $this->recoveryRoot = sys_get_temp_dir().'/koma-recovery-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($this->recoveryRoot);

    $this->priorRecoveryConfig = [
        'database.default' => config('database.default'),
        'database.connections.sqlite.database' => config('database.connections.sqlite.database'),
        'database.connections.mariadb' => config('database.connections.mariadb'),
        'filesystems.disks.media.root' => config('filesystems.disks.media.root'),
        'filesystems.disks.local.root' => config('filesystems.disks.local.root'),
        'koma.paths.themes' => config('koma.paths.themes'),
        'koma.paths.plugins' => config('koma.paths.plugins'),
        'koma.release.commit' => config('koma.release.commit'),
        'koma.release.build_id' => config('koma.release.build_id'),
        'koma.release.source_dirty' => config('koma.release.source_dirty'),
        'koma.release.trusted' => config('koma.release.trusted'),
        'koma.release.metadata_path' => config('koma.release.metadata_path'),
    ];

    $this->sourceDatabase = $this->recoveryRoot.'/source.sqlite';
    touch($this->sourceDatabase);
    $this->publicMedia = $this->recoveryRoot.'/public-media';
    $this->privateInputs = $this->recoveryRoot.'/private-inputs';
    $this->themes = $this->recoveryRoot.'/themes';
    $this->plugins = $this->recoveryRoot.'/plugins';
    File::ensureDirectoryExists($this->publicMedia.'/series');
    File::ensureDirectoryExists($this->privateInputs.'/archive-imports/source');
    File::ensureDirectoryExists($this->themes.'/aozora');
    File::ensureDirectoryExists($this->plugins);
    File::put($this->publicMedia.'/series/cover.txt', 'public media fixture');
    File::put($this->privateInputs.'/archive-imports/source/retry.txt', 'private input fixture');
    File::put($this->themes.'/aozora/theme.json', json_encode([
        'slug' => 'aozora',
        'name' => 'Aozora',
        'version' => '1.0.0',
    ], JSON_THROW_ON_ERROR));
    File::put($this->themes.'/aozora/asset.txt', 'theme fixture');

    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => $this->sourceDatabase,
        'filesystems.disks.media.root' => $this->publicMedia,
        'filesystems.disks.local.root' => $this->privateInputs,
        'koma.paths.themes' => $this->themes,
        'koma.paths.plugins' => $this->plugins,
    ]);
    DB::purge('sqlite');
    DB::connection('sqlite')->unprepared('create table recovery_fixture (id integer primary key, name text not null)');
    DB::connection('sqlite')->insert('insert into recovery_fixture (name) values (?)', ['captured']);

    $this->secretBundle = $this->recoveryRoot.'/operator-secrets.age';
    $this->secretContents = 'age-encrypted-fixture-content-'.bin2hex(random_bytes(6));
    File::put($this->secretBundle, $this->secretContents);
    $this->maintenance = new RecoveryTestMaintenanceMode;
    app()->instance(MaintenanceMode::class, $this->maintenance);
});

afterEach(function () {
    DB::disconnect('sqlite');
    config($this->priorRecoveryConfig);
    DB::purge('sqlite');
    app()->forgetInstance(MaintenanceMode::class);
    File::deleteDirectory($this->recoveryRoot);
});

function recoveryCreator(RecoveryTestMaintenanceMode $maintenance): SiteBackupCreator
{
    return new SiteBackupCreator(
        $maintenance,
        app(ThemeManager::class),
        app(PluginManager::class),
        app(SiteBackupVerifier::class),
        app(MySqlSiteBackupDumper::class),
    );
}

function recoveryRestorer(?MySqlSiteBackupRestorer $mySqlRestorer = null): SiteBackupRestorer
{
    return new SiteBackupRestorer(app(SiteBackupVerifier::class), $mySqlRestorer);
}

test('creates a self-verifying SQLite backup with the required durable inventory', function () {
    $destination = $this->recoveryRoot.'/published-backup';

    $backup = recoveryCreator($this->maintenance)->create($destination, $this->secretBundle);

    expect($backup->path)->toBe($destination)
        ->and($this->maintenance->active())->toBeFalse()
        ->and($backup->manifest['database']['driver'])->toBe('sqlite')
        ->and($backup->manifest['database']['artifact']['path'])->toBe('database/database.sqlite')
        ->and($backup->manifest['secrets']['bundle']['path'])->toBe('secrets/operator-secrets.age')
        ->and($backup->manifest['secrets'])->not->toContain($this->secretContents)
        ->and($backup->manifest['backup_tool']['version'])->toBe(config('koma.version'))
        ->and($backup->manifest['release']['identity']['version'])->toBe(config('koma.version'))
        ->and($backup->manifest['release']['identity']['build_id'])->toBe('development')
        ->and($backup->manifest['release']['identity']['source_dirty'])->toBeNull()
        ->and($backup->manifest['release']['build_metadata'])->toBeNull()
        ->and(File::exists($destination.'/storage/public/media/series/cover.txt'))->toBeTrue()
        ->and(File::exists($destination.'/storage/private/archive-imports/source/retry.txt'))->toBeTrue()
        ->and(File::exists($destination.'/release/extensions/themes/aozora/theme.json'))->toBeTrue()
        ->and(File::exists($destination.'/database/database.sqlite'))->toBeTrue();

    $captured = new PDO('sqlite:'.$destination.'/database/database.sqlite');
    expect($captured->query('select name from recovery_fixture')->fetchColumn())->toBe('captured');
});

test('captures consistent build metadata in the release inventory', function () {
    $version = (string) config('koma.version');
    $commit = str_repeat('a', 40);
    $buildId = $version.'+'.substr($commit, 0, 12);
    $metadataPath = $this->recoveryRoot.'/KOMA-BUILD-METADATA.txt';
    File::put($metadataPath, implode("\n", [
        'format=koma-build-v1',
        'artifact=flatfile',
        'release_version='.$version,
        'source_commit='.$commit,
        'source_dirty=false',
        'source_policy=exact-commit',
        'build_id='.$buildId,
        'composer_lock_sha256='.hash_file('sha256', base_path('composer.lock')),
        'package_lock_sha256='.hash_file('sha256', base_path('package-lock.json')),
    ])."\n");
    config(['koma.release.metadata_path' => $metadataPath]);

    $destination = $this->recoveryRoot.'/metadata-backup';
    $backup = recoveryCreator($this->maintenance)->create($destination, $this->secretBundle);

    expect($backup->manifest['release']['identity'])->toMatchArray([
        'version' => $version,
        'git_commit' => $commit,
        'build_id' => $buildId,
        'source_dirty' => false,
    ])->and($backup->manifest['release']['identity']['build_metadata_sha256'])
        ->toBe(hash_file('sha256', $metadataPath))
        ->and($backup->manifest['release']['build_metadata']['path'])
        ->toBe('release/KOMA-BUILD-METADATA.txt')
        ->and(File::get($destination.'/release/KOMA-BUILD-METADATA.txt'))
        ->toBe(File::get($metadataPath));
});

test('rejects inconsistent build identity but accepts a valid legacy v1 manifest', function () {
    $inconsistent = $this->recoveryRoot.'/inconsistent-metadata.txt';
    File::put($inconsistent, implode("\n", [
        'format=koma-build-v1',
        'artifact=docker',
        'release_version=9.9.9',
        'source_commit='.str_repeat('b', 40),
        'source_dirty=false',
        'source_policy=exact-commit',
        'build_id=9.9.9+'.str_repeat('b', 12),
        'composer_lock_sha256='.hash_file('sha256', base_path('composer.lock')),
        'package_lock_sha256='.hash_file('sha256', base_path('package-lock.json')),
    ])."\n");
    config(['koma.release.metadata_path' => $inconsistent]);

    $rejected = $this->recoveryRoot.'/rejected-identity';
    expect(fn () => recoveryCreator($this->maintenance)->create($rejected, $this->secretBundle))
        ->toThrow(SiteBackupException::class, 'does not match the running release')
        ->and(File::exists($rejected))->toBeFalse();

    config(['koma.release.metadata_path' => $this->recoveryRoot.'/missing-metadata.txt']);
    $legacy = $this->recoveryRoot.'/legacy-v1';
    $backup = recoveryCreator($this->maintenance)->create($legacy, $this->secretBundle);
    $manifest = $backup->manifest;
    unset(
        $manifest['release']['build_metadata'],
        $manifest['release']['identity']['version'],
        $manifest['release']['identity']['build_id'],
        $manifest['release']['identity']['source_dirty'],
    );
    File::put($legacy.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));

    expect(app(SiteBackupVerifier::class)->verify($legacy)->manifest)
        ->toBe($manifest);
});

test('rejects a current backup whose release version disagrees with its tool version', function () {
    config(['koma.release.metadata_path' => $this->recoveryRoot.'/missing-metadata.txt']);
    $destination = $this->recoveryRoot.'/version-mismatch';
    $backup = recoveryCreator($this->maintenance)->create($destination, $this->secretBundle);
    $manifest = $backup->manifest;
    $manifest['release']['identity']['version'] = '9.9.9';
    File::put($destination.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));

    expect(fn () => app(SiteBackupVerifier::class)->verify($destination))
        ->toThrow(SiteBackupException::class, 'identity is inconsistent');
});

test('rejects hash-valid build metadata that disagrees with the release identity', function () {
    $version = (string) config('koma.version');
    $commit = str_repeat('d', 40);
    $metadataPath = $this->recoveryRoot.'/source-build-metadata.txt';
    File::put($metadataPath, implode("\n", [
        'format=koma-build-v1',
        'artifact=docker',
        'release_version='.$version,
        'source_commit='.$commit,
        'source_dirty=false',
        'source_policy=exact-commit',
        'build_id='.$version.'+'.substr($commit, 0, 12),
        'composer_lock_sha256='.hash_file('sha256', base_path('composer.lock')),
        'package_lock_sha256='.hash_file('sha256', base_path('package-lock.json')),
    ])."\n");
    config(['koma.release.metadata_path' => $metadataPath]);

    $destination = $this->recoveryRoot.'/metadata-content-mismatch';
    $backup = recoveryCreator($this->maintenance)->create($destination, $this->secretBundle);
    $manifest = $backup->manifest;
    $capturedPath = $destination.'/release/KOMA-BUILD-METADATA.txt';
    File::put($capturedPath, str_replace('source_commit='.$commit, 'source_commit='.str_repeat('e', 40), File::get($capturedPath)));
    $newHash = hash_file('sha256', $capturedPath);
    $manifest['release']['identity']['build_metadata_sha256'] = $newHash;
    $manifest['release']['build_metadata']['sha256'] = $newHash;
    foreach ($manifest['files'] as &$file) {
        if ($file['path'] === 'release/KOMA-BUILD-METADATA.txt') {
            $file['sha256'] = $newHash;
        }
    }
    unset($file);
    File::put($destination.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));

    expect(fn () => app(SiteBackupVerifier::class)->verify($destination))
        ->toThrow(SiteBackupException::class, 'metadata content is inconsistent');
});

test('trusted builds refuse backup creation when build metadata is missing', function () {
    config([
        'koma.release.trusted' => true,
        'koma.release.metadata_path' => $this->recoveryRoot.'/missing-metadata.txt',
    ]);

    expect(fn () => recoveryCreator($this->maintenance)->create(
        $this->recoveryRoot.'/trusted-without-metadata',
        $this->secretBundle,
    ))->toThrow(SiteBackupException::class, 'require build metadata');
});

test('refuses unsupported database drivers before writing a backup', function () {
    config(['database.default' => 'pgsql']);
    DB::purge('pgsql');
    $destination = $this->recoveryRoot.'/wrong-driver';

    expect(fn () => recoveryCreator($this->maintenance)->create($destination, $this->secretBundle))
        ->toThrow(SiteBackupException::class, 'supports SQLite, MySQL, and MariaDB only')
        ->and(File::exists($destination))->toBeFalse();
});

test('refuses unsafe and non-empty destinations', function () {
    $existing = $this->recoveryRoot.'/existing';
    File::ensureDirectoryExists($existing);

    expect(fn () => recoveryCreator($this->maintenance)->create($existing, $this->secretBundle))
        ->toThrow(SiteBackupException::class, 'must not already exist');
    expect(fn () => recoveryCreator($this->maintenance)->create(base_path('forbidden-backup'), $this->secretBundle))
        ->toThrow(SiteBackupException::class, 'outside the application');
});

test('refuses symbolic links in the backup destination and source inventory', function () {
    $linkedParent = $this->recoveryRoot.'/linked-parent';
    symlink($this->recoveryRoot, $linkedParent);

    expect(fn () => recoveryCreator($this->maintenance)->create($linkedParent.'/backup', $this->secretBundle))
        ->toThrow(SiteBackupException::class, 'symbolic link');

    unlink($linkedParent);
    symlink($this->secretBundle, $this->publicMedia.'/series/unsafe-link');
    $destination = $this->recoveryRoot.'/unsafe-source';

    expect(fn () => recoveryCreator($this->maintenance)->create($destination, $this->secretBundle))
        ->toThrow(SiteBackupException::class, 'symbolic link')
        ->and(File::exists($destination))->toBeFalse()
        ->and(glob($this->recoveryRoot.'/.unsafe-source.staging-*'))->toBeEmpty();
});

test('backs up the documented release layout whose storage links to shared storage', function () {
    // docs/install/shared-hosting.md installs <release>/storage as a link to
    // <shared>/storage so releases can be switched side by side.
    $shared = $this->recoveryRoot.'/shared/storage';
    $release = $this->recoveryRoot.'/release';
    File::ensureDirectoryExists($shared.'/app/media/series');
    File::ensureDirectoryExists($shared.'/app/private/archive-imports/source');
    File::ensureDirectoryExists($release);
    File::put($shared.'/app/media/series/cover.txt', 'shared media fixture');
    File::put($shared.'/app/private/archive-imports/source/retry.txt', 'shared private fixture');
    symlink($shared, $release.'/storage');
    $originalStorage = storage_path();
    app()->useStoragePath($release.'/storage');
    config([
        'filesystems.disks.media.root' => storage_path('app/media'),
        'filesystems.disks.local.root' => storage_path('app/private'),
    ]);

    try {
        $destination = $this->recoveryRoot.'/linked-storage-backup';
        $backup = recoveryCreator($this->maintenance)->create($destination, $this->secretBundle);

        expect(File::get($destination.'/storage/public/media/series/cover.txt'))->toBe('shared media fixture')
            ->and(File::get($destination.'/storage/private/archive-imports/source/retry.txt'))->toBe('shared private fixture')
            ->and(app(SiteBackupVerifier::class)->verify($destination)->path)->toBe($backup->path);

        // Only the storage root itself may be a link. One beneath it is refused.
        File::deleteDirectory($shared.'/app/media');
        symlink($this->publicMedia, $shared.'/app/media');
        $unsafe = $this->recoveryRoot.'/linked-media-backup';

        expect(fn () => recoveryCreator($this->maintenance)->create($unsafe, $this->secretBundle))
            ->toThrow(SiteBackupException::class, 'symbolic link')
            ->and(File::exists($unsafe))->toBeFalse();
    } finally {
        app()->useStoragePath($originalStorage);
    }
});

test('requires an explicit encrypted secret bundle without exposing its content', function () {
    $destination = $this->recoveryRoot.'/missing-secret';

    expect(fn () => recoveryCreator($this->maintenance)->create($destination, null))
        ->toThrow(SiteBackupException::class, 'encrypted secret bundle')
        ->and(File::exists($destination))->toBeFalse();

    $status = Artisan::call('koma:site-backup:create', [
        'destination' => $this->recoveryRoot.'/command-backup',
        '--secrets-bundle' => $this->secretBundle,
    ]);
    expect($status)->toBe(0)
        ->and(Artisan::output())->not->toContain($this->secretContents)
        ->and((string) File::get($this->recoveryRoot.'/command-backup/manifest.json'))->not->toContain($this->secretContents);
});

test('verifier rejects changed payloads and changed manifests', function () {
    $destination = $this->recoveryRoot.'/tampered';
    recoveryCreator($this->maintenance)->create($destination, $this->secretBundle);

    File::append($destination.'/storage/public/media/series/cover.txt', 'tampered');
    expect(fn () => app(SiteBackupVerifier::class)->verify($destination))
        ->toThrow(SiteBackupException::class, 'SHA-256');

    recoveryCreator($this->maintenance)->create($this->recoveryRoot.'/tampered-manifest', $this->secretBundle);
    $manifestPath = $this->recoveryRoot.'/tampered-manifest/manifest.json';
    $manifest = json_decode((string) File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $manifest['format'] = 'tampered';
    File::put($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
    expect(fn () => app(SiteBackupVerifier::class)->verify($this->recoveryRoot.'/tampered-manifest'))
        ->toThrow(SiteBackupException::class, 'format is unsupported');
});

test('cleans interrupted staging before any final directory is published', function () {
    $creator = new class($this->maintenance, app(ThemeManager::class), app(PluginManager::class), app(SiteBackupVerifier::class), app(MySqlSiteBackupDumper::class)) extends SiteBackupCreator
    {
        protected function beforePublish(string $stage): void
        {
            throw new SiteBackupException('simulated interruption');
        }
    };
    $destination = $this->recoveryRoot.'/interrupted';

    expect(fn () => $creator->create($destination, $this->secretBundle))
        ->toThrow(SiteBackupException::class, 'simulated interruption')
        ->and(File::exists($destination))->toBeFalse()
        ->and(glob($this->recoveryRoot.'/.interrupted.staging-*'))->toBeEmpty()
        ->and($this->maintenance->active())->toBeFalse();
});

test('inspects a verified SQLite backup without creating the isolated target', function () {
    $destination = $this->recoveryRoot.'/inspectable';
    recoveryCreator($this->maintenance)->create($destination, $this->secretBundle);
    $target = $this->recoveryRoot.'/inspected-restore';

    $plan = recoveryRestorer()->inspect($destination, $target);

    expect($plan->targetRoot)->toBe($target)
        ->and($plan->backup->manifest['database']['driver'])->toBe('sqlite')
        ->and($plan->actions)->toContain([
            'action' => 'copy',
            'source' => 'database/database.sqlite',
            'target' => 'database/database.sqlite',
        ])
        ->and($plan->actions)->toContain([
            'action' => 'link',
            'source' => null,
            'target' => 'public/storage -> ../storage/app/public',
        ])
        ->and(File::exists($target))->toBeFalse()
        ->and(glob($this->recoveryRoot.'/.inspected-restore.restore-staging-*'))->toBeEmpty();
});

test('restore command stays in inspect mode until force is explicit', function () {
    $destination = $this->recoveryRoot.'/command-inspect';
    recoveryCreator($this->maintenance)->create($destination, $this->secretBundle);
    $target = $this->recoveryRoot.'/command-inspect-target';

    $status = Artisan::call('koma:site-backup:restore', [
        'backup' => $destination,
        '--target-root' => $target,
    ]);

    expect($status)->toBe(0)
        ->and(Artisan::output())->toContain('Inspection only. No target files were written.')
        ->and(File::exists($target))->toBeFalse();
});

test('restores a verified SQLite database and durable inventory into an isolated target', function () {
    $destination = $this->recoveryRoot.'/sqlite-restore';
    recoveryCreator($this->maintenance)->create($destination, $this->secretBundle);
    $target = $this->recoveryRoot.'/sqlite-restore-target';

    $plan = recoveryRestorer()->restore($destination, $target, true);

    $database = new PDO('sqlite:'.$target.'/database/database.sqlite');
    expect($plan->targetRoot)->toBe($target)
        ->and($database->query('select name from recovery_fixture')->fetchColumn())->toBe('captured')
        ->and(File::get($target.'/storage/app/media/series/cover.txt'))->toBe('public media fixture')
        ->and(File::get($target.'/storage/app/private/archive-imports/source/retry.txt'))->toBe('private input fixture')
        ->and(File::exists($target.'/release/composer.lock'))->toBeTrue()
        ->and(File::exists($target.'/release/extensions/themes/aozora/theme.json'))->toBeTrue()
        ->and(File::get($target.'/secrets/operator-secrets.age'))->toBe($this->secretContents)
        ->and(File::isDirectory($target.'/storage/framework/cache'))->toBeTrue()
        ->and(File::isDirectory($target.'/storage/framework/views'))->toBeTrue()
        ->and(is_link($target.'/public/storage'))->toBeTrue()
        ->and(readlink($target.'/public/storage'))->toBe('../storage/app/public')
        ->and(realpath($target.'/public/storage'))->toBe(realpath($target.'/storage/app/public'))
        ->and(File::exists($target.'/database/.database-client.cnf'))->toBeFalse()
        ->and(glob($this->recoveryRoot.'/.sqlite-restore-target.restore-staging-*'))->toBeEmpty();
});

test('refuses tampered hashes, manifests, paths, and missing secrets before restore writes', function () {
    $hashBackup = $this->recoveryRoot.'/restore-tampered-hash';
    recoveryCreator($this->maintenance)->create($hashBackup, $this->secretBundle);
    File::append($hashBackup.'/storage/public/media/series/cover.txt', 'tampered');
    $hashTarget = $this->recoveryRoot.'/restore-tampered-hash-target';

    expect(fn () => recoveryRestorer()->inspect($hashBackup, $hashTarget))
        ->toThrow(SiteBackupException::class, 'SHA-256')
        ->and(File::exists($hashTarget))->toBeFalse();

    $pathBackup = $this->recoveryRoot.'/restore-tampered-path';
    $pathBackupData = recoveryCreator($this->maintenance)->create($pathBackup, $this->secretBundle);
    $manifest = $pathBackupData->manifest;
    $manifest['files'][0]['path'] = '../database.sqlite';
    File::put($pathBackup.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    $pathTarget = $this->recoveryRoot.'/restore-tampered-path-target';

    expect(fn () => recoveryRestorer()->inspect($pathBackup, $pathTarget))
        ->toThrow(SiteBackupException::class, 'invalid')
        ->and(File::exists($pathTarget))->toBeFalse();

    $secretBackup = $this->recoveryRoot.'/restore-missing-secret';
    recoveryCreator($this->maintenance)->create($secretBackup, $this->secretBundle);
    File::delete($secretBackup.'/secrets/operator-secrets.age');
    $secretTarget = $this->recoveryRoot.'/restore-missing-secret-target';

    expect(fn () => recoveryRestorer()->inspect($secretBackup, $secretTarget))
        ->toThrow(SiteBackupException::class, 'missing or unsafe')
        ->and(File::exists($secretTarget))->toBeFalse();
});

test('refuses unsafe, active, symlinked, and backup-contained restore targets', function () {
    $destination = $this->recoveryRoot.'/target-guards';
    recoveryCreator($this->maintenance)->create($destination, $this->secretBundle);

    expect(fn () => recoveryRestorer()->inspect($destination, base_path('restore-target-guard')))
        ->toThrow(SiteBackupException::class, 'isolated');
    expect(fn () => recoveryRestorer()->inspect($destination, $this->publicMedia.'/restore-target-guard'))
        ->toThrow(SiteBackupException::class, 'isolated');
    expect(fn () => recoveryRestorer()->inspect($destination, $destination.'/restore-target-guard'))
        ->toThrow(SiteBackupException::class, 'isolated');

    $linkedParent = $this->recoveryRoot.'/linked-restore-parent';
    symlink($this->recoveryRoot, $linkedParent);
    expect(fn () => recoveryRestorer()->inspect($destination, $linkedParent.'/restore-target-guard'))
        ->toThrow(SiteBackupException::class, 'symbolic link')
        ->and(File::exists($linkedParent.'/restore-target-guard'))->toBeFalse();
});

test('cleans interrupted restore staging before an isolated target is published', function () {
    $destination = $this->recoveryRoot.'/restore-interrupted';
    recoveryCreator($this->maintenance)->create($destination, $this->secretBundle);
    $target = $this->recoveryRoot.'/restore-interrupted-target';
    $restorer = new class(app(SiteBackupVerifier::class)) extends SiteBackupRestorer
    {
        protected function beforePublish(string $stage): void
        {
            throw new SiteBackupException('simulated restore interruption');
        }
    };

    expect(fn () => $restorer->restore($destination, $target, true))
        ->toThrow(SiteBackupException::class, 'simulated restore interruption')
        ->and(File::exists($target))->toBeFalse()
        ->and(glob($this->recoveryRoot.'/.restore-interrupted-target.restore-staging-*'))->toBeEmpty();
});

function recoveryDumpClient(string $root, int $exitCode = 0): string
{
    $script = $root.'/fake-dump-client.sh';
    File::put($script, "#!/bin/sh\nfor argument in \"\$@\"; do\n  case \"\$argument\" in\n    --result-file=*) destination=\"\${argument#--result-file=}\" ;;\n  esac\ndone\n[ -n \"\${destination:-}\" ] || exit 2\nif [ \"$exitCode\" -eq 0 ]; then\n  printf '%s\\n' '-- MariaDB dump 10.19' 'CREATE TABLE recovery_fixture (id int);' '-- Dump completed on 2026-09-03 00:00:00' > \"\$destination\"\nfi\nexit $exitCode\n");
    chmod($script, 0700);

    return $script;
}

function recoveryRestoreCredentials(string $root, string $host = 'restore-db', string $port = '3306'): string
{
    $credentials = $root.'/restore-target.cnf';
    File::put($credentials, "[client]\nhost={$host}\nport={$port}\nuser=recovery\npassword=not-printed\n");
    chmod($credentials, 0600);

    return $credentials;
}

function recoveryRestoreClient(string $root, string $preflightTableCount = '0'): string
{
    $script = $root.'/fake-restore-client.sh';
    File::put($script, "#!/bin/sh\nif [ -n \"\${KOMA_RECOVERY_TEST_ARGS_PATH:-}\" ]; then\n  printf '%s\\n' \"\$@\" >> \"\$KOMA_RECOVERY_TEST_ARGS_PATH\"\nfi\nfor argument in \"\$@\"; do\n  case \"\$argument\" in\n    --execute=*) printf '%s\\n' '{$preflightTableCount}'; exit 0 ;;\n  esac\ndone\nif [ \"\${KOMA_RECOVERY_TEST_IMPORT_FAIL:-0}\" = '1' ]; then\n  exit 1\nfi\ncat > \"\${KOMA_RECOVERY_TEST_IMPORT_PATH:?}\"\n");
    chmod($script, 0700);

    return $script;
}

function recoveryMariaDbConnection(string $password): Connection
{
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('mariadb');
    $connection->shouldReceive('getDatabaseName')->andReturn('recovery_fixture');
    $connection->shouldReceive('getConfig')->andReturnUsing(function (string $key) use ($password): mixed {
        return [
            'username' => 'recovery',
            'password' => $password,
            'host' => '127.0.0.1',
            'port' => '3306',
            'unix_socket' => null,
        ][$key] ?? null;
    });
    $connection->shouldReceive('scalar')->with('select version()')->andReturn('11.8.5-MariaDB');

    return $connection;
}

function recoveryMySqlConnection(string $password): Connection
{
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('mysql');
    $connection->shouldReceive('getDatabaseName')->andReturn('recovery_fixture');
    $connection->shouldReceive('getConfig')->andReturnUsing(function (string $key) use ($password): mixed {
        return [
            'username' => 'recovery',
            'password' => $password,
            'host' => '127.0.0.1',
            'port' => '3306',
            'unix_socket' => null,
        ][$key] ?? null;
    });
    $connection->shouldReceive('scalar')->with('select version()')->andReturn('8.4.0');

    return $connection;
}

function recoveryMariaDbCreator(RecoveryTestMaintenanceMode $maintenance, Connection $connection, MySqlSiteBackupDumper $dumper): SiteBackupCreator
{
    return new class($maintenance, app(ThemeManager::class), app(PluginManager::class), app(SiteBackupVerifier::class), $dumper, $connection) extends SiteBackupCreator
    {
        public function __construct(
            MaintenanceMode $maintenance,
            ThemeManager $themes,
            PluginManager $plugins,
            SiteBackupVerifier $verifier,
            MySqlSiteBackupDumper $dumper,
            private readonly Connection $connection,
        ) {
            parent::__construct($maintenance, $themes, $plugins, $verifier, $dumper);
        }

        protected function databaseConnection(): Connection
        {
            return $this->connection;
        }
    };
}

test('creates and verifies a MariaDB logical backup without retaining database credentials', function () {
    $destination = $this->recoveryRoot.'/mariadb-backup';
    $databasePassword = 'db-password-'.bin2hex(random_bytes(6));
    $client = recoveryDumpClient($this->recoveryRoot);

    $backup = recoveryMariaDbCreator(
        $this->maintenance,
        recoveryMariaDbConnection($databasePassword),
        new MySqlSiteBackupDumper([$client]),
    )->create($destination, $this->secretBundle);

    expect($backup->manifest['database']['driver'])->toBe('mariadb')
        ->and($backup->manifest['database']['artifact']['path'])->toBe('database/database.sql')
        ->and((string) File::get($destination.'/manifest.json'))->not->toContain($databasePassword)
        ->and(File::exists($destination.'/.database-client.cnf'))->toBeFalse()
        ->and(glob($this->recoveryRoot.'/.mariadb-backup.staging-*'))->toBeEmpty();
});

test('refuses a missing dump client and hides client authentication failures', function () {
    $credentials = $this->recoveryRoot.'/client.cnf';
    File::put($credentials, "[client]\npassword=fixture\n");
    $password = 'db-password-'.bin2hex(random_bytes(6));

    expect(fn () => (new MySqlSiteBackupDumper([]))->dump('mariadb', ['database' => 'fixture'], $credentials, $this->recoveryRoot.'/missing.sql'))
        ->toThrow(SiteBackupException::class, 'dump client is unavailable');

    $failingClient = recoveryDumpClient($this->recoveryRoot, 1);
    $destination = $this->recoveryRoot.'/failed-mariadb';
    $failure = null;
    try {
        recoveryMariaDbCreator(
            $this->maintenance,
            recoveryMariaDbConnection($password),
            new MySqlSiteBackupDumper([$failingClient]),
        )->create($destination, $this->secretBundle);
    } catch (SiteBackupException $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeInstanceOf(SiteBackupException::class)
        ->and($failure->getMessage())->toContain('Logical database dump failed')
        ->and($failure->getMessage())->not->toContain($password)
        ->and(File::exists($destination))->toBeFalse()
        ->and(glob($this->recoveryRoot.'/.failed-mariadb.staging-*'))->toBeEmpty();
});

test('verifier rejects a logical database dump whose valid hash has malformed SQL structure', function () {
    $destination = $this->recoveryRoot.'/malformed-mariadb';
    $backup = recoveryMariaDbCreator(
        $this->maintenance,
        recoveryMariaDbConnection('db-password-'.bin2hex(random_bytes(6))),
        new MySqlSiteBackupDumper([recoveryDumpClient($this->recoveryRoot)]),
    )->create($destination, $this->secretBundle);

    $dumpPath = $destination.'/database/database.sql';
    File::put($dumpPath, 'not a database dump');
    $manifest = $backup->manifest;
    $bytes = filesize($dumpPath);
    $hash = hash_file('sha256', $dumpPath);
    $manifest['database']['artifact']['bytes'] = $bytes;
    $manifest['database']['artifact']['sha256'] = $hash;
    foreach ($manifest['files'] as &$file) {
        if ($file['path'] === 'database/database.sql') {
            $file['bytes'] = $bytes;
            $file['sha256'] = $hash;
        }
    }
    unset($file);
    File::put($destination.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));

    expect(fn () => app(SiteBackupVerifier::class)->verify($destination))
        ->toThrow(SiteBackupException::class, 'dump structure is invalid');
});

test('requires an isolated target credentials file before MariaDB restore inspection', function () {
    $destination = $this->recoveryRoot.'/mariadb-restore-refused';
    $backup = recoveryMariaDbCreator(
        $this->maintenance,
        recoveryMariaDbConnection('db-password-'.bin2hex(random_bytes(6))),
        new MySqlSiteBackupDumper([recoveryDumpClient($this->recoveryRoot)]),
    )->create($destination, $this->secretBundle);
    $target = $this->recoveryRoot.'/mariadb-restore-refused-target';

    expect(fn () => recoveryRestorer()->inspect($backup->path, $target, null, 'restored_koma'))
        ->toThrow(SiteBackupException::class, 'operator-provided isolated target credentials file')
        ->and(File::exists($target))->toBeFalse();
});

test('inspects and restores a verified MariaDB backup through an isolated target client', function () {
    $destination = $this->recoveryRoot.'/mariadb-restore';
    $backup = recoveryMariaDbCreator(
        $this->maintenance,
        recoveryMariaDbConnection('db-password-'.bin2hex(random_bytes(6))),
        new MySqlSiteBackupDumper([recoveryDumpClient($this->recoveryRoot)]),
    )->create($destination, $this->secretBundle);
    $credentials = recoveryRestoreCredentials($this->recoveryRoot);
    $target = $this->recoveryRoot.'/mariadb-restore-target';
    $importedDump = $this->recoveryRoot.'/imported-mariadb.sql';
    $clientArguments = $this->recoveryRoot.'/restore-client-arguments.txt';
    putenv('KOMA_RECOVERY_TEST_IMPORT_PATH='.$importedDump);
    putenv('KOMA_RECOVERY_TEST_ARGS_PATH='.$clientArguments);

    try {
        $restorer = recoveryRestorer(new MySqlSiteBackupRestorer([recoveryRestoreClient($this->recoveryRoot)]));
        $plan = $restorer->inspect($backup->path, $target, $credentials, 'restored_koma');
        $restorer->restore($backup->path, $target, true, $credentials, 'restored_koma');
    } finally {
        putenv('KOMA_RECOVERY_TEST_IMPORT_PATH');
        putenv('KOMA_RECOVERY_TEST_ARGS_PATH');
    }

    expect($plan->actions)->toContain([
        'action' => 'load',
        'source' => 'database/database.sql',
        'target' => 'database service: restored_koma',
    ])
        ->and(File::get($importedDump))->toBe(File::get($destination.'/database/database.sql'))
        ->and(File::get($target.'/storage/app/media/series/cover.txt'))->toBe('public media fixture')
        ->and(File::get($target.'/storage/app/private/archive-imports/source/retry.txt'))->toBe('private input fixture')
        ->and(File::exists($target.'/database/database.sql'))->toBeFalse()
        ->and(File::exists($target.'/restore-target.cnf'))->toBeFalse()
        ->and(is_link($target.'/public/storage'))->toBeTrue()
        ->and(File::get($clientArguments))->toContain("--binary-mode\n")
        ->and(File::get($clientArguments))->toContain("--local-infile=0\n");
});

test('rejects restore client option injection in the credentials file', function () {
    $credentials = recoveryRestoreCredentials($this->recoveryRoot);
    File::append($credentials, "local-infile=1\n");

    expect(fn () => (new MySqlSiteBackupRestorer([recoveryRestoreClient($this->recoveryRoot)]))->target(
        'mariadb',
        tap($this->recoveryRoot.'/database.sql', fn (string $path) => File::put($path, "-- MariaDB dump\n")),
        $credentials,
        'restored_koma',
    ))->toThrow(SiteBackupException::class, 'unsupported option [local-infile]');
});

test('refuses missing force, unavailable clients, nonempty targets, and failed imports before publication', function () {
    $destination = $this->recoveryRoot.'/mariadb-restore-refusals';
    $backup = recoveryMariaDbCreator(
        $this->maintenance,
        recoveryMariaDbConnection('db-password-'.bin2hex(random_bytes(6))),
        new MySqlSiteBackupDumper([recoveryDumpClient($this->recoveryRoot)]),
    )->create($destination, $this->secretBundle);
    $credentials = recoveryRestoreCredentials($this->recoveryRoot);
    $importedDump = $this->recoveryRoot.'/refusal-import.sql';
    putenv('KOMA_RECOVERY_TEST_IMPORT_PATH='.$importedDump);

    try {
        $target = $this->recoveryRoot.'/missing-force-target';
        expect(fn () => recoveryRestorer()->restore($backup->path, $target, false, $credentials, 'restored_koma'))
            ->toThrow(SiteBackupException::class, 'explicit force')
            ->and(File::exists($target))->toBeFalse();

        $target = $this->recoveryRoot.'/missing-client-target';
        expect(fn () => recoveryRestorer(new MySqlSiteBackupRestorer([]))->restore($backup->path, $target, true, $credentials, 'restored_koma'))
            ->toThrow(SiteBackupException::class, 'restore client is unavailable')
            ->and(File::exists($target))->toBeFalse();

        $target = $this->recoveryRoot.'/nonempty-database-target';
        expect(fn () => recoveryRestorer(new MySqlSiteBackupRestorer([recoveryRestoreClient($this->recoveryRoot, '1')]))->restore($backup->path, $target, true, $credentials, 'restored_koma'))
            ->toThrow(SiteBackupException::class, 'empty isolated target database')
            ->and(File::exists($target))->toBeFalse()
            ->and(File::exists($importedDump))->toBeFalse();

        putenv('KOMA_RECOVERY_TEST_IMPORT_FAIL=1');
        $target = $this->recoveryRoot.'/failed-import-target';
        expect(fn () => recoveryRestorer(new MySqlSiteBackupRestorer([recoveryRestoreClient($this->recoveryRoot)]))->restore($backup->path, $target, true, $credentials, 'restored_koma'))
            ->toThrow(SiteBackupException::class, 'Logical database restore failed')
            ->and(File::exists($target))->toBeFalse();
    } finally {
        putenv('KOMA_RECOVERY_TEST_IMPORT_FAIL');
        putenv('KOMA_RECOVERY_TEST_IMPORT_PATH');
    }
});

test('a failure after the database loads is reported exactly and recovers through a new empty database', function () {
    $destination = $this->recoveryRoot.'/mariadb-post-load';
    $backup = recoveryMariaDbCreator(
        $this->maintenance,
        recoveryMariaDbConnection('db-password-'.bin2hex(random_bytes(6))),
        new MySqlSiteBackupDumper([recoveryDumpClient($this->recoveryRoot)]),
    )->create($destination, $this->secretBundle);
    $credentials = recoveryRestoreCredentials($this->recoveryRoot);
    $target = $this->recoveryRoot.'/post-load-target';
    $firstImport = $this->recoveryRoot.'/post-load-first.sql';
    $secondImport = $this->recoveryRoot.'/post-load-second.sql';
    $verifier = app(SiteBackupVerifier::class);
    $interrupted = new class($verifier, new MySqlSiteBackupRestorer([recoveryRestoreClient($this->recoveryRoot)])) extends SiteBackupRestorer
    {
        protected function afterDatabaseLoad(string $stage): void
        {
            throw new RuntimeException('simulated publication failure');
        }
    };

    try {
        putenv('KOMA_RECOVERY_TEST_IMPORT_PATH='.$firstImport);
        expect(fn () => $interrupted->restore($backup->path, $target, true, $credentials, 'restored_koma'))->toThrow(
            SiteBackupException::class,
            'The logical database was restored into [restored_koma], but the site files were not published',
        );

        // The database really was loaded, nothing was published, no staging
        // copy lingers, and the backup still verifies.
        expect(File::get($firstImport))->toBe(File::get($destination.'/database/database.sql'))
            ->and(File::exists($target))->toBeFalse()
            ->and(glob($this->recoveryRoot.'/.post-load-target.restore-staging-*'))->toBeEmpty()
            ->and($verifier->verify($backup->path)->path)->toBe($backup->path);

        // Running again against the database that is now populated is refused
        // before anything is written.
        putenv('KOMA_RECOVERY_TEST_IMPORT_PATH='.$secondImport);
        expect(fn () => recoveryRestorer(new MySqlSiteBackupRestorer([recoveryRestoreClient($this->recoveryRoot, '6')]))
            ->restore($backup->path, $target, true, $credentials, 'restored_koma'))
            ->toThrow(SiteBackupException::class, 'empty isolated target database')
            ->and(File::exists($target))->toBeFalse()
            ->and(File::exists($secondImport))->toBeFalse()
            ->and(glob($this->recoveryRoot.'/.post-load-target.restore-staging-*'))->toBeEmpty();

        // A newly provisioned empty database completes the same restore.
        recoveryRestorer(new MySqlSiteBackupRestorer([recoveryRestoreClient($this->recoveryRoot)]))
            ->restore($backup->path, $target, true, $credentials, 'restored_koma_fresh');
    } finally {
        putenv('KOMA_RECOVERY_TEST_IMPORT_PATH');
    }

    expect(File::get($secondImport))->toBe(File::get($destination.'/database/database.sql'))
        ->and(File::get($target.'/storage/app/media/series/cover.txt'))->toBe('public media fixture')
        ->and(File::get($target.'/secrets/operator-secrets.age'))->toBe($this->secretContents)
        ->and(glob($this->recoveryRoot.'/.post-load-target.restore-staging-*'))->toBeEmpty();
});

test('a failed logical import says the target database may be partly populated', function () {
    $destination = $this->recoveryRoot.'/mariadb-partial-load';
    $backup = recoveryMariaDbCreator(
        $this->maintenance,
        recoveryMariaDbConnection('db-password-'.bin2hex(random_bytes(6))),
        new MySqlSiteBackupDumper([recoveryDumpClient($this->recoveryRoot)]),
    )->create($destination, $this->secretBundle);
    $credentials = recoveryRestoreCredentials($this->recoveryRoot);
    $target = $this->recoveryRoot.'/partial-load-target';
    putenv('KOMA_RECOVERY_TEST_IMPORT_PATH='.$this->recoveryRoot.'/partial-load.sql');
    putenv('KOMA_RECOVERY_TEST_IMPORT_FAIL=1');

    try {
        expect(fn () => recoveryRestorer(new MySqlSiteBackupRestorer([recoveryRestoreClient($this->recoveryRoot)]))
            ->restore($backup->path, $target, true, $credentials, 'restored_koma'))->toThrow(
                SiteBackupException::class,
                'after it began writing to database [restored_koma]. Nothing was published and the backup is unchanged. That database may be partly populated: do not reuse it.',
            );
    } finally {
        putenv('KOMA_RECOVERY_TEST_IMPORT_FAIL');
        putenv('KOMA_RECOVERY_TEST_IMPORT_PATH');
    }

    expect(File::exists($target))->toBeFalse()
        ->and(glob($this->recoveryRoot.'/.partial-load-target.restore-staging-*'))->toBeEmpty()
        ->and(app(SiteBackupVerifier::class)->verify($backup->path)->path)->toBe($backup->path);
});

test('staging left by a killed restore is reported and never removed or reused', function () {
    $destination = $this->recoveryRoot.'/orphan-backup';
    recoveryCreator($this->maintenance)->create($destination, $this->secretBundle);
    $target = $this->recoveryRoot.'/orphan-target';
    $orphan = $this->recoveryRoot.'/.orphan-target.restore-staging-0123456789abcdef0123';
    File::ensureDirectoryExists($orphan.'/storage');
    File::put($orphan.'/storage/partial.txt', 'left by a killed restore');
    // Staging that belongs to a different target is not this restore's concern.
    File::ensureDirectoryExists($this->recoveryRoot.'/.orphan-target-two.restore-staging-0123456789abcdef0123');

    expect(fn () => recoveryRestorer()->inspect($destination, $target))->toThrow(
        SiteBackupException::class,
        'A restore staging directory already exists beside this target: .orphan-target.restore-staging-0123456789abcdef0123.',
    );
    expect(fn () => recoveryRestorer()->restore($destination, $target, true))
        ->toThrow(SiteBackupException::class, 'Confirm no restore is running, then remove it.')
        ->and(File::exists($target))->toBeFalse()
        ->and(File::get($orphan.'/storage/partial.txt'))->toBe('left by a killed restore')
        ->and(glob($this->recoveryRoot.'/.orphan-target.restore-staging-*'))->toBe([$orphan]);

    // Once the operator has removed it, the same restore proceeds.
    File::deleteDirectory($orphan);
    recoveryRestorer()->restore($destination, $target, true);

    expect(File::get($target.'/storage/app/media/series/cover.txt'))->toBe('public media fixture')
        ->and(glob($this->recoveryRoot.'/.orphan-target.restore-staging-*'))->toBeEmpty();
});

test('a backup that changes between inspection and restore is refused as a restore error', function () {
    $destination = $this->recoveryRoot.'/changed-after-inspect';
    recoveryCreator($this->maintenance)->create($destination, $this->secretBundle);
    $target = $this->recoveryRoot.'/changed-after-inspect-target';
    // Valid when the restore inspects it, altered by the time it re-verifies.
    $verifier = new class extends SiteBackupVerifier
    {
        private int $calls = 0;

        public function verify(string $backupPath): App\Recovery\SiteBackup
        {
            if (++$this->calls > 1) {
                throw new SiteBackupException('Backup changed after it was inspected.');
            }

            return parent::verify($backupPath);
        }
    };

    expect(fn () => (new SiteBackupRestorer($verifier))->restore($destination, $target, true))
        ->toThrow(SiteBackupException::class, 'Backup changed after it was inspected.')
        ->and(File::exists($target))->toBeFalse()
        ->and(glob($this->recoveryRoot.'/.changed-after-inspect-target.restore-staging-*'))->toBeEmpty();
});

test('a restored site has a real public directory behind its storage link', function () {
    $destination = $this->recoveryRoot.'/public-directory';
    recoveryCreator($this->maintenance)->create($destination, $this->secretBundle);
    $target = $this->recoveryRoot.'/public-directory-target';

    recoveryRestorer()->restore($destination, $target, true);

    // Manga media is restored into protected storage, so nothing in the
    // backup creates this directory; the link must not be left dangling.
    expect(is_dir($target.'/storage/app/public'))->toBeTrue()
        ->and(realpath($target.'/public/storage'))->not->toBeFalse()
        ->and(realpath($target.'/public/storage'))->toBe(realpath($target.'/storage/app/public'));
});

test('refuses a logical restore target matching the active source database', function () {
    $destination = $this->recoveryRoot.'/mariadb-source-target';
    $backup = recoveryMariaDbCreator(
        $this->maintenance,
        recoveryMariaDbConnection('db-password-'.bin2hex(random_bytes(6))),
        new MySqlSiteBackupDumper([recoveryDumpClient($this->recoveryRoot)]),
    )->create($destination, $this->secretBundle);
    config([
        'database.default' => 'mariadb',
        'database.connections.mariadb.host' => 'restore-db',
        'database.connections.mariadb.port' => '3306',
        'database.connections.mariadb.database' => 'restored_koma',
        'database.connections.mariadb.unix_socket' => '',
    ]);
    $target = $this->recoveryRoot.'/mariadb-source-target-root';

    expect(fn () => recoveryRestorer(new MySqlSiteBackupRestorer([recoveryRestoreClient($this->recoveryRoot)]))->inspect(
        $backup->path,
        $target,
        recoveryRestoreCredentials($this->recoveryRoot),
        'restored_koma',
    ))->toThrow(SiteBackupException::class, 'must differ from the active source database')
        ->and(File::exists($target))->toBeFalse();
});

test('inspects a verified MySQL backup through the same guarded target boundary', function () {
    $destination = $this->recoveryRoot.'/mysql-restore';
    $backup = recoveryMariaDbCreator(
        $this->maintenance,
        recoveryMySqlConnection('db-password-'.bin2hex(random_bytes(6))),
        new MySqlSiteBackupDumper([recoveryDumpClient($this->recoveryRoot)]),
    )->create($destination, $this->secretBundle);
    $target = $this->recoveryRoot.'/mysql-restore-target';

    $plan = recoveryRestorer(new MySqlSiteBackupRestorer([recoveryRestoreClient($this->recoveryRoot)]))->inspect(
        $backup->path,
        $target,
        recoveryRestoreCredentials($this->recoveryRoot),
        'restored_mysql',
    );

    expect($plan->backup->manifest['database']['driver'])->toBe('mysql')
        ->and($plan->databaseTarget?->database)->toBe('restored_mysql')
        ->and(File::exists($target))->toBeFalse();
});
