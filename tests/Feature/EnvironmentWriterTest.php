<?php

use App\Install\EnvironmentWriter;
use App\Install\InstallerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

test('environment updates are atomic mode 0600 and rollback capable', function () {
    $root = storage_path('framework/testing/environment-'.str()->uuid());
    $environment = $root.'/.env';
    $example = $root.'/.env.example';
    $cachedConfig = $root.'/bootstrap/cache/config.php';
    File::ensureDirectoryExists(dirname($cachedConfig));
    File::put($environment, "APP_NAME=Old\nAPP_URL=http://old.test\n");
    File::put($example, "APP_NAME=Example\nAPP_KEY=\n");
    File::put($cachedConfig, '<?php return [];');
    chmod($environment, 0640);

    configureEnvironmentWriter($environment, $example, $cachedConfig);

    try {
        $backup = app(EnvironmentWriter::class)->write([
            'APP_NAME' => 'New Library',
            'APP_URL' => 'https://new.test',
        ]);

        expect($backup)->not->toBeNull()
            ->and(File::get($environment))->toContain('APP_NAME="New Library"')
            ->and(File::get($environment))->toContain('APP_URL=https://new.test')
            ->and(fileperms($environment) & 0777)->toBe(0600)
            ->and(File::exists($cachedConfig))->toBeFalse();

        $backup->rollback();

        expect(File::get($environment))->toBe("APP_NAME=Old\nAPP_URL=http://old.test\n")
            ->and(fileperms($environment) & 0777)->toBe(0600);

        $committed = app(EnvironmentWriter::class)->write(['APP_NAME' => 'Committed']);
        $committed?->commit();

        expect(File::get($environment))->toContain('APP_NAME=Committed')
            ->and(glob($root.'/.env.*.tmp'))->toBe([])
            ->and(glob($root.'/.env.*.bak'))->toBe([])
            ->and(File::exists($environment.'.lock'))->toBeFalse();
    } finally {
        File::deleteDirectory($root);
    }
});

test('process environment mode never reads or rewrites an environment file', function () {
    $root = storage_path('framework/testing/environment-process-'.str()->uuid());
    $environment = $root.'/.env';
    File::ensureDirectoryExists($root);
    File::put($environment, "APP_NAME=Container\n");

    configureEnvironmentWriter($environment, $root.'/.env.example', $root.'/config.php');
    config()->set('installer.environment_mode', 'process');

    try {
        $backup = app(EnvironmentWriter::class)->write(['APP_NAME' => 'Changed']);

        expect($backup)->toBeNull()
            ->and(File::get($environment))->toBe("APP_NAME=Container\n");
    } finally {
        File::deleteDirectory($root);
    }
});

test('database preflight proves create write read and drop capabilities', function () {
    $root = storage_path('framework/testing/database-probe-'.str()->uuid());
    $database = $root.'/installer.sqlite';
    File::ensureDirectoryExists($root);

    try {
        app(InstallerService::class)->testDatabase([
            'db_connection' => 'sqlite',
            'db_database' => $database,
            'db_prefix' => '',
        ]);

        $pdo = new PDO('sqlite:'.$database);
        $probes = $pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE 'koma_install_probe_%'",
        )->fetchColumn();

        expect((int) $probes)->toBe(0);
    } finally {
        File::deleteDirectory($root);
    }
});

function configureEnvironmentWriter(string $environment, string $example, string $cachedConfig): void
{
    config()->set([
        'installer.write_env' => true,
        'installer.write_env_in_tests' => true,
        'installer.environment_mode' => 'file',
        'installer.environment_path' => $environment,
        'installer.environment_example_path' => $example,
        'installer.cached_config_path' => $cachedConfig,
    ]);
}
