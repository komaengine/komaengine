<?php

namespace App\Install;

use App\Engine\Settings\Settings;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class InstallerService
{
    public function __construct(protected EnvironmentWriter $environment) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function testDatabase(array $data): void
    {
        $this->withInstallLock(function () use ($data): void {
            $this->ensureNotInstalled();
            $this->probeDatabase($data);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function probeDatabase(array $data): void
    {
        $connection = 'installer_test';
        Config::set("database.connections.{$connection}", $this->connectionConfig($data));

        DB::purge($connection);
        $database = DB::connection($connection);
        $table = 'koma_install_probe_'.str()->lower(str()->random(12));
        $connected = false;

        try {
            $database->getPdo();
            $connected = true;
            $this->ensureTargetNotInstalled($database);
            $database->getSchemaBuilder()->create($table, function ($blueprint): void {
                $blueprint->id();
                $blueprint->string('probe');
            });
            $database->table($table)->insert(['probe' => 'ready']);

            if ($database->table($table)->value('probe') !== 'ready') {
                throw new InstallationException(
                    'DATABASE_PROBE_FAILED',
                    'The database account could not read back its setup probe.',
                );
            }
        } finally {
            try {
                // Cleanup would reconnect and replace the original failure.
                if ($connected) {
                    $database->getSchemaBuilder()->dropIfExists($table);
                }
            } finally {
                DB::disconnect($connection);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function install(array $data): User
    {
        return $this->withInstallLock(function () use ($data): User {
            $environmentBackup = null;

            try {
                $this->ensureNotInstalled();
                $this->probeDatabase($data);

                if (! app()->environment('testing')) {
                    $this->applyRuntimeDatabase($data);
                    $environmentBackup = $this->writeEnvironment($data);
                }

                Config::set('app.name', $data['site_title']);
                Config::set('app.url', $data['app_url']);
                Config::set('app.locale', $data['language']);

                $settings = app(Settings::class);
                $settings->flush();
                $this->ensureNotInstalled();

                $this->runRequiredArtisan('migrate', ['--force' => true], 'MIGRATION_FAILED');

                // Migration can reveal an existing legacy site that did not
                // have the settings table before this attempt.
                $settings->flush();
                $this->ensureNotInstalled();
                $settings->set('installation_state', 'installing');

                $this->runRequiredArtisan('db:seed', [
                    '--class' => RolePermissionSeeder::class,
                    '--force' => true,
                ], 'SEED_FAILED');

                $this->ensureStorageLink();
                $this->writeRobots((bool) $data['discourage_indexing']);

                $user = DB::transaction(function () use ($data, $settings): User {
                    $user = User::query()->updateOrCreate(
                        ['email' => $data['admin_email']],
                        [
                            'name' => $data['admin_name'],
                            'password' => Hash::make($data['admin_password']),
                        ],
                    );
                    $user->syncRoles(['Administrator']);

                    $settings->setMany([
                        'installed_version' => (string) config('koma.version'),
                        'locale' => $data['language'],
                        'site_title' => $data['site_title'],
                        'tagline' => '',
                        'app_url' => $data['app_url'],
                        'active_theme' => 'aozora',
                        'default_reading_direction' => $data['default_direction'],
                        'mature_gate' => (bool) $data['mature_gate'],
                        'discourage_search_engines' => (bool) $data['discourage_indexing'],
                        'enabled_plugins' => [],
                        'media_disk' => 'local',
                    ]);

                    $settings->forget('installation_state');

                    // This is the final database write. The filesystem marker
                    // is written immediately after the transaction commits.
                    $settings->set('installed_at', now()->toIso8601String());

                    return $user;
                });

                $this->writeInstalledMarker();
                $environmentBackup?->commit();

                return $user;
            } catch (Throwable $exception) {
                try {
                    $environmentBackup?->rollback();
                } catch (Throwable $rollbackException) {
                    Log::critical('Installer environment rollback failed.', [
                        'exception' => $rollbackException,
                    ]);
                }

                throw $exception;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function connectionConfig(array $data): array
    {
        $driver = $data['db_connection'];
        $prefix = (string) ($data['db_prefix'] ?? '');

        if ($driver === 'sqlite') {
            $database = self::sqliteDatabasePath((string) $data['db_database']);

            if ($database !== ':memory:') {
                $dir = dirname($database);

                if (! is_dir($dir) || ! is_writable($dir)) {
                    throw new RuntimeException("SQLite directory is not writable: {$dir}");
                }

                if (! file_exists($database)) {
                    File::put($database, '');
                }
            }

            return [
                'driver' => 'sqlite',
                'database' => $database,
                'prefix' => $prefix,
                'foreign_key_constraints' => true,
            ];
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Only SQLite, MySQL, and MariaDB are supported by the installer today.');
        }

        if (! extension_loaded('pdo_mysql')) {
            throw new RuntimeException('The pdo_mysql PHP extension is required for MySQL or MariaDB.');
        }

        return [
            'driver' => $driver,
            'host' => $data['db_host'] ?: '127.0.0.1',
            'port' => $data['db_port'] ?: '3306',
            'database' => $data['db_database'],
            'username' => $data['db_username'],
            'password' => $data['db_password'],
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => $prefix,
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => [],
        ];
    }

    public static function sqliteDatabasePath(string $database): string
    {
        $database = $database ?: database_path('database.sqlite');

        if ($database !== ':memory:' && ! str_starts_with($database, DIRECTORY_SEPARATOR)) {
            $database = database_path($database);
        }

        return $database;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function applyRuntimeDatabase(array $data): void
    {
        $driver = $data['db_connection'];

        Config::set("database.connections.{$driver}", $this->connectionConfig($data));
        Config::set('database.default', $driver);

        DB::purge($driver);
        DB::reconnect($driver);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function writeEnvironment(array $data): ?EnvironmentBackup
    {
        return $this->environment->write([
            'APP_NAME' => $data['site_title'],
            'APP_URL' => $data['app_url'],
            'DB_CONNECTION' => $data['db_connection'],
            'DB_HOST' => $data['db_host'],
            'DB_PORT' => $data['db_port'],
            'DB_DATABASE' => $data['db_database'],
            'DB_USERNAME' => $data['db_username'],
            'DB_PASSWORD' => $data['db_password'],
            'DB_PREFIX' => $data['db_prefix'],
            'SESSION_DRIVER' => 'file',
            'CACHE_STORE' => 'file',
            'MEDIA_DISK_DRIVER' => 'local',
        ]);
    }

    protected function ensureStorageLink(): void
    {
        if (app()->environment('testing') && ! config('installer.verify_storage_link_in_tests')) {
            return;
        }

        $link = $this->publicStorageLink();
        $target = $this->publicStorageTarget();

        if (is_link($link) && realpath($link) === realpath($target)) {
            return;
        }

        if (file_exists($link) && ! is_link($link)) {
            throw new InstallationException(
                'STORAGE_LINK_OCCUPIED',
                'The public storage path is occupied by a file or directory. Move it and retry.',
            );
        }

        $this->runRequiredArtisan('storage:link', ['--force' => true], 'STORAGE_LINK_FAILED');

        if (! is_link($link) || realpath($link) !== realpath($target)) {
            throw new InstallationException(
                'STORAGE_LINK_INVALID',
                'The public storage link does not point to the media storage directory.',
            );
        }
    }

    protected function writeRobots(bool $discourage): void
    {
        if (app()->environment('testing') || ! config('installer.write_robots')) {
            return;
        }

        $path = public_path('robots.txt');

        if (file_exists($path) && ! is_writable($path)) {
            throw new InstallationException(
                'ROBOTS_NOT_WRITABLE',
                'robots.txt is not writable. Correct its permissions and retry.',
            );
        }

        File::put($path, $discourage
            ? "User-agent: *\nDisallow: /\n"
            : "User-agent: *\nDisallow:\n"
        );
    }

    protected function ensureNotInstalled(): void
    {
        if (app(InstalledState::class)->installed()) {
            throw new InstallationException(
                'ALREADY_INSTALLED',
                'Koma Engine is already installed. Sign in to the existing site.',
            );
        }
    }

    protected function ensureTargetNotInstalled(\Illuminate\Database\ConnectionInterface $database): void
    {
        $schema = $database->getSchemaBuilder();

        if ($schema->hasTable('settings')) {
            $installed = $database->table('settings')->where('key', 'installed_at')->exists();
            $installing = $database->table('settings')->where('key', 'installation_state')->exists();
            if ($installed || $installing) {
                throw new InstallationException(
                    'TARGET_ALREADY_INITIALIZED',
                    'The selected database already contains a Koma Engine installation or setup attempt.',
                );
            }
        }

        foreach (['users', 'series'] as $table) {
            if ($schema->hasTable($table) && $database->table($table)->exists()) {
                throw new InstallationException(
                    'TARGET_ALREADY_INITIALIZED',
                    'The selected database already contains Koma Engine data.',
                );
            }
        }
    }

    protected function writeInstalledMarker(): void
    {
        if (app()->environment('testing') && ! config('installer.write_installed_marker_in_tests')) {
            return;
        }

        $path = (string) config('installer.installed_marker_path', storage_path('framework/koma-installed'));
        File::ensureDirectoryExists(dirname($path));
        $temporary = $path.'.tmp-'.bin2hex(random_bytes(8));
        $contents = json_encode([
            'installed_at' => now()->toIso8601String(),
            'version' => (string) config('koma.version'),
        ], JSON_THROW_ON_ERROR)."\n";

        try {
            if (File::put($temporary, $contents, true) === false || ! @chmod($temporary, 0600) || ! @rename($temporary, $path)) {
                throw new InstallationException(
                    'INSTALL_MARKER_FAILED',
                    'The installer could not close the setup boundary. Correct storage permissions before retrying.',
                );
            }
        } finally {
            if (is_file($temporary)) {
                File::delete($temporary);
            }
        }
    }

    protected function publicStorageLink(): string
    {
        return (string) config('installer.public_storage_link', public_path('storage'));
    }

    protected function publicStorageTarget(): string
    {
        return (string) config('installer.public_storage_target', storage_path('app/public'));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function runRequiredArtisan(string $command, array $arguments, string $errorCode): void
    {
        $status = $this->runArtisan($command, $arguments);

        if ($status === 0) {
            return;
        }

        Log::error('Installer command failed.', [
            'command' => $command,
            'status' => $status,
            'output' => Artisan::output(),
        ]);

        throw new InstallationException(
            $errorCode,
            'A required setup command failed. Review the application log, correct the problem, and retry.',
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function runArtisan(string $command, array $arguments): int
    {
        return Artisan::call($command, $arguments);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function withInstallLock(callable $callback): mixed
    {
        $path = (string) config('installer.lock_path', storage_path('framework/koma-install.lock'));
        File::ensureDirectoryExists(dirname($path));
        $handle = @fopen($path, 'c+');

        if ($handle === false) {
            throw new InstallationException(
                'INSTALL_LOCK_UNAVAILABLE',
                'The installer cannot create its lock file. Correct storage permissions and retry.',
            );
        }

        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new InstallationException(
                    'INSTALL_BUSY',
                    'Another installation request is running. Wait a moment and retry.',
                );
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
