<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

// No RefreshDatabase: these tests need the empty database a flat-file site has
// before its web installer runs.

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
        'installer.installed_marker_path' => $this->doctorStorageRoot.'/koma-installed',
    ]);

    expect(Schema::hasTable('migrations'))->toBeFalse();
});

afterEach(function () {
    File::deleteDirectory($this->doctorStorageRoot);
});

test('bootstrap doctor reports migrations as pending on an empty database', function () {
    $status = Artisan::call('koma:doctor', ['--mode' => 'bootstrap']);
    $output = Artisan::output();

    expect($status)->toBe(0)
        ->and($output)->toMatch('/^migrations\s+PENDING$/m')
        ->and($output)->not->toContain('FAIL');

    $status = Artisan::call('koma:doctor', ['--mode' => 'bootstrap', '--json' => true]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($status)->toBe(0)
        ->and($payload['ready'])->toBeTrue()
        ->and($payload['checks']['migrations'])->toBeTrue()
        ->and($payload['pending'])->toBe(['migrations']);
});

test('operational doctor still fails before installation', function () {
    $status = Artisan::call('koma:doctor', ['--mode' => 'operational', '--json' => true]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($status)->toBe(1)
        ->and($payload['ready'])->toBeFalse()
        ->and($payload['checks']['migrations'])->toBeFalse()
        ->and($payload['checks']['installed'])->toBeFalse()
        ->and($payload)->not->toHaveKey('pending');
});

test('an installed site with an empty database fails bootstrap', function () {
    File::put($this->doctorStorageRoot.'/koma-installed', '{}');

    $status = Artisan::call('koma:doctor', ['--mode' => 'bootstrap', '--json' => true]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($status)->toBe(1)
        ->and($payload['checks']['migrations'])->toBeFalse()
        ->and($payload)->not->toHaveKey('pending');
});
