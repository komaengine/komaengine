<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->vendor = storage_path('framework/testing/dist-vendor-'.str()->uuid());
    File::ensureDirectoryExists($this->vendor.'/composer');
    File::ensureDirectoryExists($this->vendor.'/acme/widget/src');
});

afterEach(function () {
    File::deleteDirectory($this->vendor);
});

/** @param  list<array<string, string>>  $packages */
function writeInstalledPackages(string $vendor, array $packages): void
{
    File::put($vendor.'/composer/installed.json', json_encode(['packages' => $packages, 'dev' => false]));
}

function checkVendor(string $vendor): Illuminate\Contracts\Process\ProcessResult
{
    return Process::run([PHP_BINARY, base_path('scripts/release/assert-dist-vendor.php'), $vendor]);
}

test('a vendor tree installed from dist archives passes', function () {
    writeInstalledPackages($this->vendor, [
        ['name' => 'acme/widget', 'installation-source' => 'dist'],
        ['name' => 'acme/gadget', 'installation-source' => 'dist'],
    ]);

    $result = checkVendor($this->vendor);

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toContain('All 2 Composer packages were installed from dist archives.');
});

test('a package installed from source fails the build', function () {
    writeInstalledPackages($this->vendor, [
        ['name' => 'acme/widget', 'installation-source' => 'source'],
    ]);

    $result = checkVendor($this->vendor);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('acme/widget was installed from source, not dist');
});

test('Git metadata anywhere in vendor fails the build', function () {
    writeInstalledPackages($this->vendor, [
        ['name' => 'acme/widget', 'installation-source' => 'dist'],
    ]);
    File::ensureDirectoryExists($this->vendor.'/acme/widget/.git');

    $result = checkVendor($this->vendor);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('Git metadata in acme/widget/.git');
});

test('a missing package list is a usage error', function () {
    expect(checkVendor($this->vendor)->exitCode())->toBe(2);
});

test('the real vendor tree was installed from dist archives', function () {
    expect(checkVendor(base_path('vendor'))->exitCode())->toBe(0);
});
