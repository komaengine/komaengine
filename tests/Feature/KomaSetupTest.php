<?php

use App\Engine\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setupRoot = storage_path('framework/testing/koma-setup-'.str()->uuid());
    File::ensureDirectoryExists($this->setupRoot.'/public');
    File::ensureDirectoryExists($this->setupRoot.'/storage/app/public');
    File::put($this->setupRoot.'/.env.example', "APP_NAME=KomaEngine\nAPP_KEY=\nAPP_URL=https://manga.example.com\nDB_CONNECTION=sqlite\n");

    config([
        'installer.environment_mode' => 'file',
        'installer.environment_path' => $this->setupRoot.'/.env',
        'installer.environment_example_path' => $this->setupRoot.'/.env.example',
        'installer.public_storage_link' => $this->setupRoot.'/public/storage',
        'installer.public_storage_target' => $this->setupRoot.'/storage/app/public',
        'installer.legacy_public_media_path' => storage_path('app/public/koma-setup-test-'.str()->uuid()),
        'installer.installed_marker_path' => $this->setupRoot.'/koma-installed',
        'filesystems.disks.media.root' => $this->setupRoot.'/media',
        'app.url' => 'https://manga.example.com',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->setupRoot);
});

function runSetup(): array
{
    $status = Artisan::call('koma:setup', ['--json' => true]);

    return [$status, json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)];
}

function setupStep(array $payload, string $step): array
{
    return collect($payload['steps'])->firstWhere('step', $step);
}

function envKey(string $path): string
{
    preg_match('/^APP_KEY=(.*)$/m', (string) file_get_contents($path), $match);

    return $match[1] ?? '';
}

test('a fresh tree gets a private .env, a key, a relative storage link, and protected media', function () {
    config(['app.key' => '']);

    [$status, $payload] = runSetup();
    $env = $this->setupRoot.'/.env';

    expect($status)->toBe(1)
        ->and(setupStep($payload, 'env')['result'])->toBe('created')
        ->and(fileperms($env) & 0777)->toBe(0600)
        ->and(setupStep($payload, 'app_key')['result'])->toBe('generated')
        ->and(envKey($env))->toStartWith('base64:')
        ->and(strlen(base64_decode(substr(envKey($env), 7), true)))->toBe(32)
        ->and(setupStep($payload, 'storage_link')['result'])->toBe('created')
        ->and(readlink($this->setupRoot.'/public/storage'))->toBe('../storage/app/public')
        ->and(realpath($this->setupRoot.'/public/storage'))->toBe(realpath($this->setupRoot.'/storage/app/public'))
        ->and(setupStep($payload, 'media')['ok'])->toBeTrue()
        ->and(is_dir($this->setupRoot.'/media'))->toBeTrue()
        ->and($payload['doctor'])->toBeNull()
        ->and($payload['next'][0])->toContain('Edit .env');
});

test('a second run changes nothing, keeps the key, and reports readiness', function () {
    config(['app.key' => '']);
    runSetup();
    $key = envKey($this->setupRoot.'/.env');
    config(['app.key' => $key]);

    [$status, $payload] = runSetup();

    expect($status)->toBe(0)
        ->and($payload['ready'])->toBeTrue()
        ->and(setupStep($payload, 'env')['result'])->toBe('ok')
        ->and(setupStep($payload, 'app_key')['result'])->toBe('ok')
        ->and(setupStep($payload, 'storage_link')['result'])->toBe('ok')
        ->and(envKey($this->setupRoot.'/.env'))->toBe($key)
        ->and($payload['doctor']['ready'])->toBeTrue()
        ->and($payload['next'][0])->toContain('koma:installer-token')
        ->and($payload['next'][1])->toContain('https://manga.example.com/install');
});

test('a readable .env with a loose mode is tightened to 0600', function () {
    $env = $this->setupRoot.'/.env';
    File::put($env, 'APP_KEY='.config('app.key')."\n");
    chmod($env, 0644);

    [, $payload] = runSetup();

    clearstatcache(true, $env);
    expect(setupStep($payload, 'env')['result'])->toBe('fixed')
        ->and(fileperms($env) & 0777)->toBe(0600)
        ->and(setupStep($payload, 'app_key')['result'])->toBe('ok');
});

test('an invalid key is replaced and the rest of .env is kept', function () {
    $env = $this->setupRoot.'/.env';
    File::put($env, "APP_NAME=\"My Manga Library\"\nAPP_KEY=too-short\nDB_PASSWORD=\"secret value\"\n");
    chmod($env, 0600);
    config(['app.key' => 'too-short']);

    [, $payload] = runSetup();
    $contents = (string) file_get_contents($env);

    expect(setupStep($payload, 'app_key')['result'])->toBe('generated')
        ->and(envKey($env))->toStartWith('base64:')
        ->and(config('app.key'))->toBe(envKey($env))
        ->and($contents)->toContain('APP_NAME="My Manga Library"')
        ->and($contents)->toContain('DB_PASSWORD="secret value"');
});

test('an occupied public/storage stops setup and is left alone', function () {
    File::ensureDirectoryExists($this->setupRoot.'/public/storage');
    File::put($this->setupRoot.'/public/storage/keep.txt', 'keep');

    [$status, $payload] = runSetup();

    expect($status)->toBe(1)
        ->and(setupStep($payload, 'storage_link')['ok'])->toBeFalse()
        ->and(setupStep($payload, 'storage_link')['detail'])->toContain('occupied')
        ->and(File::get($this->setupRoot.'/public/storage/keep.txt'))->toBe('keep')
        ->and($payload['doctor'])->toBeNull();
});

test('a link to the wrong place is replaced', function () {
    File::ensureDirectoryExists($this->setupRoot.'/elsewhere');
    symlink($this->setupRoot.'/elsewhere', $this->setupRoot.'/public/storage');

    [, $payload] = runSetup();

    expect(setupStep($payload, 'storage_link')['result'])->toBe('fixed')
        ->and(realpath($this->setupRoot.'/public/storage'))->toBe(realpath($this->setupRoot.'/storage/app/public'));
});

test('a process-managed environment leaves .env and the key alone', function () {
    config(['installer.environment_mode' => 'process']);

    [, $payload] = runSetup();

    expect(setupStep($payload, 'env')['result'])->toBe('skipped')
        ->and(setupStep($payload, 'app_key')['result'])->toBe('skipped')
        ->and(file_exists($this->setupRoot.'/.env'))->toBeFalse()
        ->and(setupStep($payload, 'storage_link')['result'])->toBe('created');
});

test('an installed site is told to use the operational check', function () {
    File::put($this->setupRoot.'/.env', 'APP_KEY='.config('app.key')."\n");
    chmod($this->setupRoot.'/.env', 0600);
    app(Settings::class)->set('installed_at', now()->toIso8601String());

    [, $payload] = runSetup();

    expect($payload['installed'])->toBeTrue()
        ->and($payload['next'][0])->toContain('already installed');
});

test('the text output shows each step, the readiness table, and the next command', function () {
    File::put($this->setupRoot.'/.env', 'APP_KEY='.config('app.key')."\n");
    chmod($this->setupRoot.'/.env', 0600);

    $this->artisan('koma:setup')
        ->expectsOutputToContain('public/storage')
        ->expectsOutputToContain('Readiness (koma:doctor --mode=bootstrap):')
        ->expectsOutputToContain('php artisan koma:installer-token')
        ->assertExitCode(0);
});
