<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

// GitHub's "Source code" archives and Git checkouts have no vendor/. Both entry
// points must say what to download instead of failing with PHP's fatal error.

beforeEach(function () {
    $this->copy = storage_path('framework/testing/no-vendor-'.str()->uuid());
    File::ensureDirectoryExists($this->copy.'/public');
    File::copy(base_path('artisan'), $this->copy.'/artisan');
    File::copy(public_path('index.php'), $this->copy.'/public/index.php');
});

afterEach(function () {
    File::deleteDirectory($this->copy);
});

test('artisan without vendor/ prints what to download and exits 1', function () {
    $result = Process::path($this->copy)->run([PHP_BINARY, 'artisan', 'list']);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toBe('')
        ->and($result->errorOutput())->toBe(
            "KomaEngine cannot start: vendor/autoload.php is missing.\n"
            ."This looks like GitHub's \"Source code\" download or a Git checkout. Install the\n"
            ."release ZIP (komaengine-YYYYMMDD-HHMMSS.zip) from https://komaengine.com/download/.\n"
            ."Developers: run composer install.\n"
        )
        ->and($result->errorOutput())->not->toContain($this->copy);
});

test('public/index.php without vendor/ answers 503 with what to download', function () {
    // The CLI has no web server, so a wrapper reports the status index.php set
    // once it has finished. (The CLI keeps no headers to inspect.)
    File::put($this->copy.'/probe.php', <<<'PHP'
        <?php
        register_shutdown_function(function () {
            fwrite(STDERR, (string) http_response_code());
        });
        require __DIR__.'/public/index.php';
        PHP);

    $result = Process::path($this->copy)->run([PHP_BINARY, 'probe.php']);

    expect($result->exitCode())->toBe(0)
        ->and($result->errorOutput())->toBe('503')
        ->and($result->output())->toStartWith("KomaEngine cannot start: vendor/autoload.php is missing.\n")
        ->and($result->output())->toContain('release ZIP (komaengine-YYYYMMDD-HHMMSS.zip) from https://komaengine.com/download/.')
        ->and($result->output())->not->toContain($this->copy);
});

test('maintenance mode is still checked before the autoloader', function () {
    File::ensureDirectoryExists($this->copy.'/storage/framework');
    File::put($this->copy.'/storage/framework/maintenance.php', "<?php\necho \"Down for maintenance.\\n\";\nexit;\n");

    $result = Process::path($this->copy)->run([PHP_BINARY, 'public/index.php']);

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toBe("Down for maintenance.\n");
});
