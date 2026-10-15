<?php

use App\Engine\Settings\Settings;
use App\Http\Middleware\EnsureKomaInstalled;
use App\Install\EnvironmentWriter;
use App\Install\InstallationException;
use App\Install\InstalledState;
use App\Install\InstallerService;
use App\Install\InstallerSetupAuthority;
use App\Livewire\Install\Installer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('installer page renders before setup', function () {
    $this->get(route('install'))
        ->assertOk()
        ->assertSee('Koma Engine')
        ->assertSee('Authorize this installer')
        ->assertDontSee('Choose your language');
});

test('installer rejects a visitor without the server generated setup token', function () {
    Livewire::test(Installer::class)
        ->set('setupToken', str_repeat('0', 64))
        ->call('unlock')
        ->assertSee('setup token is invalid')
        ->call('testDatabase')
        ->assertSet('databaseOk', false);
});

test('installer setup grant replaces the session id without invalidating the current csrf token', function () {
    $request = Request::create('/livewire-release-hash/update', 'POST');
    $session = app('session')->driver();
    $session->start();
    $request->setLaravelSession($session);
    $originalSessionId = $session->getId();
    $originalCsrfToken = $session->token();
    $authority = app(InstallerSetupAuthority::class);

    expect($authority->grant($request, $authority->token()))->toBeTrue()
        ->and($session->getId())->not->toBe($originalSessionId)
        ->and($session->token())->toBe($originalCsrfToken)
        ->and($authority->authorized($request))->toBeTrue();
});

test('installed-state middleware permits the named Livewire update route before setup', function () {
    $middleware = new class extends EnsureKomaInstalled
    {
        public function skips(Request $request): bool
        {
            return $this->shouldSkip($request);
        }
    };

    $request = Request::create('/livewire-release-hash/update', 'POST');
    $route = new Route(['POST'], 'livewire-release-hash/update', fn () => null);
    $route->name('default-livewire.update');
    $request->setRouteResolver(fn () => $route);

    $lookalike = Request::create('/livewire-release-hash/other', 'POST');
    $lookalikeRoute = new Route(['POST'], 'livewire-release-hash/other', fn () => null);
    $lookalikeRoute->name('untrusted.livewire.other');
    $lookalike->setRouteResolver(fn () => $lookalikeRoute);

    expect($middleware->skips($request))->toBeTrue()
        ->and($middleware->skips($lookalike))->toBeFalse();
});

test('installer can test sqlite and create the first admin', function () {
    authorizeInstallerSession($this);

    Livewire::test(Installer::class)
        ->set('dbConnection', 'sqlite')
        ->set('dbDatabase', ':memory:')
        ->set('siteTitle', 'Koma Test Library')
        ->set('appUrl', 'http://koma.test')
        ->set('adminName', 'Site Owner')
        ->set('adminEmail', 'owner@koma.test')
        ->set('adminPassword', 'correct-horse-1')
        ->set('adminPasswordConfirmation', 'correct-horse-1')
        ->call('testDatabase')
        ->assertSet('databaseOk', true)
        ->call('install')
        ->assertSet('installed', true)
        ->assertSee('Koma Engine is installed');

    $admin = User::where('email', 'owner@koma.test')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->hasRole('Administrator'))->toBeTrue()
        ->and(app(Settings::class)->get('site_title'))->toBe('Koma Test Library')
        ->and(app(Settings::class)->get('installed_version'))->toBe(config('koma.version'))
        ->and(app(Settings::class)->has('installed_at'))->toBeTrue();

    $this->get(route('install'))->assertRedirect(route('admin.dashboard'));
});

test('installer completion marker closes stale setup sessions before a database probe', function () {
    $marker = storage_path('framework/testing/installed-marker-'.str()->uuid());
    config()->set([
        'installer.installed_marker_path' => $marker,
        'installer.write_installed_marker_in_tests' => true,
    ]);

    try {
        app(InstallerService::class)->install(installerPayload());

        expect(File::exists($marker))->toBeTrue()
            ->and(fileperms($marker) & 0777)->toBe(0600)
            ->and(app(InstalledState::class)->installed())->toBeTrue();

        DB::table('settings')->where('key', 'installed_at')->delete();
        User::query()->delete();

        expect(fn () => app(InstallerService::class)->testDatabase(installerPayload()))
            ->toThrow(InstallationException::class, 'already installed');
    } finally {
        File::delete($marker);
    }
});

test('installer refuses an initialized target before creating its probe table', function () {
    $database = storage_path('framework/testing/initialized-target-'.str()->uuid().'.sqlite');
    touch($database);
    $connection = installerPayload();
    $connection['db_database'] = $database;

    try {
        $pdo = new PDO('sqlite:'.$database);
        $pdo->exec('create table users (id integer primary key, email text)');
        $pdo->exec("insert into users (email) values ('existing@koma.test')");

        expect(fn () => app(InstallerService::class)->testDatabase($connection))
            ->toThrow(InstallationException::class, 'already contains Koma Engine data');

        $tables = $pdo->query("select name from sqlite_master where type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        expect(collect($tables)->filter(fn (string $table) => str_starts_with($table, 'koma_install_probe_')))->toBeEmpty();
    } finally {
        File::delete($database);
    }
});

test('a completed install cannot be replayed with another administrator', function () {
    $service = app(InstallerService::class);
    $first = installerPayload();
    $second = [...$first, 'admin_email' => 'second-owner@koma.test'];

    $service->install($first);

    try {
        $service->install($second);
        $this->fail('A completed installer request was replayed.');
    } catch (InstallationException $exception) {
        expect($exception->errorCode)->toBe('ALREADY_INSTALLED');
    }

    expect(User::query()->count())->toBe(1)
        ->and(User::query()->where('email', 'second-owner@koma.test')->exists())->toBeFalse();
});

test('installer lock contention performs no database mutations and is retryable', function () {
    $lockPath = storage_path('framework/testing/installer-contention.lock');
    File::ensureDirectoryExists(dirname($lockPath));
    $handle = fopen($lockPath, 'c+');
    expect($handle)->not->toBeFalse();
    flock($handle, LOCK_EX | LOCK_NB);
    config()->set('installer.lock_path', $lockPath);

    try {
        app(InstallerService::class)->install(installerPayload());
        $this->fail('The installer acquired a lock already held by this process.');
    } catch (InstallationException $exception) {
        expect($exception->errorCode)->toBe('INSTALL_BUSY');
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
        File::delete($lockPath);
    }

    expect(User::query()->exists())->toBeFalse()
        ->and(app(Settings::class)->has('installed_at'))->toBeFalse();

    app(InstallerService::class)->install(installerPayload());

    expect(User::query()->count())->toBe(1)
        ->and(app(Settings::class)->has('installed_at'))->toBeTrue();
});

test('nonzero migration status aborts before an install marker or administrator', function () {
    $service = new class(app(EnvironmentWriter::class)) extends InstallerService
    {
        protected function runArtisan(string $command, array $arguments): int
        {
            return $command === 'migrate' ? 1 : 0;
        }
    };

    try {
        $service->install(installerPayload());
        $this->fail('A nonzero migration status was ignored.');
    } catch (InstallationException $exception) {
        expect($exception->errorCode)->toBe('MIGRATION_FAILED');
    }

    expect(User::query()->exists())->toBeFalse()
        ->and(app(Settings::class)->has('installed_at'))->toBeFalse()
        ->and(app(Settings::class)->has('installation_state'))->toBeFalse();
});

test('nonzero role seeding status leaves an explicit retry state', function () {
    $service = new class(app(EnvironmentWriter::class)) extends InstallerService
    {
        protected function runArtisan(string $command, array $arguments): int
        {
            return $command === 'db:seed' ? 1 : 0;
        }
    };

    try {
        $service->install(installerPayload());
        $this->fail('A nonzero seeder status was ignored.');
    } catch (InstallationException $exception) {
        expect($exception->errorCode)->toBe('SEED_FAILED');
    }

    expect(User::query()->exists())->toBeFalse()
        ->and(app(Settings::class)->has('installed_at'))->toBeFalse()
        ->and(app(Settings::class)->get('installation_state'))->toBe('installing')
        ->and(app(InstalledState::class)->installed())->toBeFalse();
});

test('a terminal marker failure rolls back the administrator and retries safely', function () {
    DB::statement(<<<'SQL'
        CREATE TRIGGER fail_installed_marker
        BEFORE INSERT ON settings
        WHEN NEW.key = 'installed_at'
        BEGIN
            SELECT RAISE(ABORT, 'forced terminal marker failure');
        END
        SQL);

    expect(fn () => app(InstallerService::class)->install(installerPayload()))
        ->toThrow(QueryException::class);

    expect(User::query()->exists())->toBeFalse()
        ->and(app(Settings::class)->has('installed_at'))->toBeFalse()
        ->and(app(Settings::class)->get('installation_state'))->toBe('installing')
        ->and(app(InstalledState::class)->installed())->toBeFalse();

    DB::statement('DROP TRIGGER fail_installed_marker');
    app(InstallerService::class)->install(installerPayload());

    expect(User::query()->count())->toBe(1)
        ->and(app(Settings::class)->has('installed_at'))->toBeTrue()
        ->and(app(Settings::class)->has('installation_state'))->toBeFalse();
});

test('storage readiness repairs a wrong link and rejects an occupied path', function () {
    $root = storage_path('framework/testing/storage-link-'.str()->uuid());
    $link = $root.'/public/storage';
    $target = $root.'/storage/app/public';
    $wrong = $root.'/wrong-target';
    File::ensureDirectoryExists(dirname($link));
    File::ensureDirectoryExists($target);
    File::ensureDirectoryExists($wrong);
    symlink($wrong, $link);

    config()->set('installer.verify_storage_link_in_tests', true);
    config()->set('installer.public_storage_link', $link);
    config()->set('installer.public_storage_target', $target);
    config()->set('filesystems.links', [$link => $target]);

    $service = new class(app(EnvironmentWriter::class)) extends InstallerService
    {
        public function verifyStorageLink(): void
        {
            $this->ensureStorageLink();
        }
    };

    try {
        $service->verifyStorageLink();
        expect(realpath($link))->toBe(realpath($target));

        unlink($link);
        File::put($link, 'occupied');

        try {
            $service->verifyStorageLink();
            $this->fail('An occupied storage link path was accepted.');
        } catch (InstallationException $exception) {
            expect($exception->errorCode)->toBe('STORAGE_LINK_OCCUPIED');
        }
    } finally {
        File::deleteDirectory($root);
    }
});

test('shared host preflight reports document root environment and runtime path fixes', function () {
    $root = storage_path('framework/testing/shared-host-preflight-'.str()->uuid());
    $environment = $root.'/.env';
    $blockedRuntimePath = $root.'/blocked-runtime-path';
    File::ensureDirectoryExists($root);
    File::put($environment, "APP_KEY=base64:test\n");
    File::put($blockedRuntimePath, 'occupied');
    chmod($environment, 0644);

    config()->set([
        'installer.environment_mode' => 'file',
        'installer.environment_path' => $environment,
        'installer.verify_environment_in_tests' => true,
        'installer.runtime_paths' => [
            'storage/framework/cache' => $blockedRuntimePath,
        ],
    ]);
    try {
        authorizeInstallerSession($this);
        $component = Livewire::test(Installer::class);
        request()->server->set('DOCUMENT_ROOT', base_path());
        $requirements = collect($component->instance()->requirements())
            ->keyBy('label');

        expect($requirements['Web document root']['ok'])->toBeFalse()
            ->and($requirements['Web document root']['value'])
            ->toBe('must point to the package public/ directory')
            ->and($requirements['Environment file']['ok'])->toBeFalse()
            ->and($requirements['Environment file']['value'])
            ->toBe('mode 0644 is unsafe; run chmod 600 .env')
            ->and($requirements['Runtime paths']['ok'])->toBeFalse()
            ->and($requirements['Runtime paths']['value'])
            ->toBe('storage/framework/cache is not create/read/delete ready');
    } finally {
        File::deleteDirectory($root);
    }
});

test('shared host preflight accepts public document root and secured environment', function () {
    $root = storage_path('framework/testing/shared-host-ready-'.str()->uuid());
    $environment = $root.'/.env';
    $runtimePath = $root.'/runtime';
    File::ensureDirectoryExists($runtimePath);
    File::put($environment, "APP_KEY=base64:test\n");
    chmod($environment, 0600);

    config()->set([
        'installer.environment_mode' => 'file',
        'installer.environment_path' => $environment,
        'installer.verify_environment_in_tests' => true,
        'installer.runtime_paths' => [
            'storage/framework/cache' => $runtimePath,
        ],
    ]);
    try {
        authorizeInstallerSession($this);
        $component = Livewire::test(Installer::class);
        request()->server->set('DOCUMENT_ROOT', public_path());
        $requirements = collect($component->instance()->requirements())
            ->keyBy('label');

        expect($requirements['Web document root']['ok'])->toBeTrue()
            ->and($requirements['Environment file']['ok'])->toBeTrue()
            ->and($requirements['Runtime paths']['ok'])->toBeTrue();
    } finally {
        File::deleteDirectory($root);
    }
});

test('docker compose install pre-fills the bundled mariadb service but never the password', function () {
    $default = config('database.default');
    $compose = [
        'driver' => 'mariadb',
        'host' => 'db',
        'port' => '3306',
        'database' => 'komaengine',
        'username' => 'koma',
        'password' => 'server-side-only-secret',
        'prefix' => '',
    ];
    config()->set([
        'koma.runtime' => 'docker-compose',
        'database.connections.mysql' => [...$compose, 'driver' => 'mysql'],
        'database.connections.mariadb' => $compose,
        'database.default' => 'mariadb',
    ]);

    try {
        authorizeInstallerSession($this);

        Livewire::test(Installer::class)
            ->assertSet('dbConnection', 'mariadb')
            ->assertSet('dbHost', 'db')
            ->assertSet('dbPort', '3306')
            ->assertSet('dbDatabase', 'komaengine')
            ->assertSet('dbUsername', 'koma')
            ->assertSet('dbPassword', '')
            ->set('step', 2)
            ->assertSee('KOMA_DB_PASSWORD')
            ->assertSee('SQLite (not used with Docker Compose)')
            ->assertDontSee('server-side-only-secret');
    } finally {
        config()->set('database.default', $default);
    }
});

test('docker compose install refuses sqlite before probing it', function () {
    config()->set('koma.runtime', 'docker-compose');
    authorizeInstallerSession($this);

    $component = Livewire::test(Installer::class)
        ->set('dbConnection', 'sqlite')
        ->set('dbDatabase', ':memory:')
        ->call('testDatabase')
        ->assertHasErrors(['dbConnection' => 'in'])
        ->assertSet('databaseOk', false)
        ->assertSet('databaseMessage', null);

    expect($component->errors()->first('dbConnection'))
        ->toBe('Docker Compose installs use the bundled MariaDB service. SQLite is not supported here.');
});

test('outside docker compose sqlite stays available without docker guidance', function () {
    authorizeInstallerSession($this);

    Livewire::test(Installer::class)
        ->set('step', 2)
        ->set('dbConnection', 'sqlite')
        ->assertDontSee('not used with Docker Compose')
        ->assertDontSee('KOMA_DB_PASSWORD')
        ->set('dbDatabase', ':memory:')
        ->call('testDatabase')
        ->assertHasNoErrors()
        ->assertSet('databaseOk', true);
});

test('an unusable sqlite location is explained before and after submission', function () {
    $directory = storage_path('framework/testing/missing-'.str()->uuid());
    $expected = "The web server cannot write to {$directory}. Choose a writable location or use MySQL or MariaDB.";
    authorizeInstallerSession($this);

    Livewire::test(Installer::class)
        ->set('step', 2)
        ->set('dbConnection', 'sqlite')
        ->set('dbDatabase', $directory.'/koma.sqlite')
        ->assertSee($expected)
        ->call('testDatabase')
        ->assertSet('databaseOk', false)
        ->assertSet('databaseMessage', $expected);

    expect(is_dir($directory))->toBeFalse();
});

test('database test failures name the actual problem', function (?string $runtime, Throwable $failure, string $expected) {
    if ($runtime !== null) {
        config()->set('koma.runtime', $runtime);
    }
    $this->mock(InstallerService::class, function ($mock) use ($failure): void {
        $mock->shouldReceive('testDatabase')->once()->andThrow($failure);
    });
    authorizeInstallerSession($this);

    Livewire::test(Installer::class)
        ->set('dbConnection', 'mariadb')
        ->set('dbHost', '127.0.0.1')
        ->set('dbPort', '3306')
        ->set('dbDatabase', 'komaengine')
        ->set('dbUsername', 'koma')
        ->set('dbPassword', 'typed-password')
        ->call('testDatabase')
        ->assertSet('databaseOk', false)
        ->assertSet('databaseMessage', $expected);
})->with([
    'rejected credentials under compose' => [
        'docker-compose',
        new PDOException('SQLSTATE[HY000] [1045] Access denied', 1045),
        'The database server rejected the username or password. With Docker Compose, the password is the KOMA_DB_PASSWORD value in the Docker env file you started Compose with.',
    ],
    'unreachable host under compose, wrapped by a query failure' => [
        'docker-compose',
        new QueryException('installer_test', 'select 1', [], new PDOException('SQLSTATE[HY000] [2002] Connection refused', 2002)),
        'Koma could not reach a database server at 127.0.0.1:3306. Inside Docker Compose the database host is db.',
    ],
    'unknown database' => [
        null,
        new PDOException("SQLSTATE[HY000] [1049] Unknown database 'komaengine'", 1049),
        'The database komaengine does not exist on that server.',
    ],
    'rejected credentials outside compose' => [
        null,
        new PDOException('SQLSTATE[HY000] [1045] Access denied', 1045),
        'The database server rejected the username or password.',
    ],
    'installer refusal' => [
        null,
        new InstallationException('DATABASE_ALREADY_INITIALIZED', 'The target database already contains KomaEngine tables.'),
        '[DATABASE_ALREADY_INITIALIZED] The target database already contains KomaEngine tables.',
    ],
    'anything else' => [
        null,
        new RuntimeException('socket exploded'),
        'Database connection failed. Verify the database details and server availability.',
    ],
]);

test('a refused database connection surfaces its own error rather than a cleanup failure', function () {
    $data = [
        ...installerPayload(),
        'db_connection' => 'mysql',
        'db_host' => '127.0.0.1',
        'db_port' => '1',
        'db_database' => 'komaengine',
        'db_username' => 'koma',
        'db_password' => 'typed-password',
    ];

    try {
        app(InstallerService::class)->testDatabase($data);
        $this->fail('The probe connected to a closed port.');
    } catch (PDOException $exception) {
        expect($exception)->not->toBeInstanceOf(QueryException::class)
            ->and($exception->getCode())->toBe(2002);
    }
});

/**
 * @return array<string, mixed>
 */
function installerPayload(): array
{
    return [
        'language' => 'en',
        'db_connection' => 'sqlite',
        'db_host' => '127.0.0.1',
        'db_port' => '3306',
        'db_database' => ':memory:',
        'db_username' => '',
        'db_password' => '',
        'db_prefix' => '',
        'storage_driver' => 'local',
        'site_title' => 'Installer Service Test',
        'app_url' => 'http://koma.test',
        'admin_name' => 'Site Owner',
        'admin_email' => 'owner@koma.test',
        'admin_password' => 'correct-horse-1',
        'default_direction' => 'rtl',
        'mature_gate' => true,
        'discourage_indexing' => false,
    ];
}

function authorizeInstallerSession($testCase): void
{
    $testCase->withSession([
        'koma.installer.setup_grant' => app(InstallerSetupAuthority::class)->sessionGrant(),
    ]);
}
