<?php

use App\Engine\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->doctorStorageRoot = storage_path('framework/testing/koma-doctor-'.str()->uuid());
    $target = $this->doctorStorageRoot.'/target';
    $link = $this->doctorStorageRoot.'/public-storage';

    File::ensureDirectoryExists($target);
    symlink($target, $link);

    config([
        'installer.public_storage_link' => $link,
        'installer.public_storage_target' => $target,
        'installer.legacy_public_media_path' => $this->doctorStorageRoot.'/legacy-media',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->doctorStorageRoot);
});

test('bootstrap doctor passes before installer completion', function () {
    $status = Artisan::call('koma:doctor', ['--mode' => 'bootstrap', '--json' => true]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($status)->toBe(0)
        ->and($payload['mode'])->toBe('bootstrap')
        ->and($payload['ready'])->toBeTrue()
        ->and($payload['checks'])->not->toContain(false);
});

test('operational doctor requires the terminal install marker', function () {
    $beforeStatus = Artisan::call('koma:doctor', ['--mode' => 'operational', '--json' => true]);
    $before = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($beforeStatus)->toBe(1)
        ->and($before['checks']['installed'])->toBeFalse();

    app(Settings::class)->set('installed_at', now()->toIso8601String());

    $afterStatus = Artisan::call('koma:doctor', ['--mode' => 'operational', '--json' => true]);
    $after = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($afterStatus)->toBe(0)
        ->and($after['checks']['installed'])->toBeTrue()
        ->and($after['ready'])->toBeTrue();
});

test('doctor rejects unknown readiness modes', function () {
    $this->artisan('koma:doctor', ['--mode' => 'unknown'])
        ->expectsOutputToContain('Mode must be bootstrap or operational.')
        ->assertExitCode(2);
});

test('doctor reports a storage link whose target is missing as broken', function () {
    File::deleteDirectory($this->doctorStorageRoot.'/target');

    $status = Artisan::call('koma:doctor', ['--mode' => 'bootstrap', '--json' => true]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($status)->toBe(1)
        ->and($payload['ready'])->toBeFalse()
        ->and($payload['checks']['storage_link'])->toBeFalse()
        ->and(is_link($this->doctorStorageRoot.'/public-storage'))->toBeTrue();
});
