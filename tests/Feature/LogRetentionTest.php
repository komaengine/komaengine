<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** @return array<string, string> */
function flatFileEnvironmentTemplate(): array
{
    $values = [];

    foreach (File::lines(base_path('.env.flatfile.example')) as $line) {
        if (preg_match('/\A([A-Z][A-Z0-9_]*)=(.*)\z/', trim($line), $matches) === 1) {
            $values[$matches[1]] = $matches[2];
        }
    }

    return $values;
}

beforeEach(function () {
    $this->logDirectory = storage_path('framework/testing/log-retention-'.Str::uuid());
    File::ensureDirectoryExists($this->logDirectory);
});

afterEach(function () {
    Log::forgetChannel('daily');
    Log::forgetChannel('stack');
    File::deleteDirectory($this->logDirectory);
});

test('the flat-file production template selects age-bounded daily logs', function () {
    $template = flatFileEnvironmentTemplate();

    expect($template['APP_ENV'])->toBe('production')
        ->and($template['LOG_CHANNEL'])->toBe('stack')
        ->and($template['LOG_STACK'])->toBe('daily')
        ->and($template['LOG_DAILY_DAYS'])->toMatch('/\A[1-9][0-9]?\z/')
        ->and((int) $template['LOG_DAILY_DAYS'])->toBeLessThanOrEqual(30)
        ->and(config('logging.channels.daily.driver'))->toBe('daily');
});

test('the flat-file log policy keeps only its configured days of files', function () {
    $template = flatFileEnvironmentTemplate();
    $days = 3;

    foreach (range(1, 8) as $age) {
        File::put(
            $this->logDirectory.'/laravel-'.date('Y-m-d', strtotime("-{$age} days")).'.log',
            "stale entry\n",
        );
    }

    // Resolve the template the way config/logging.php resolves a real .env.
    config([
        'logging.default' => $template['LOG_CHANNEL'],
        'logging.channels.stack.channels' => explode(',', $template['LOG_STACK']),
        'logging.channels.daily.path' => $this->logDirectory.'/laravel.log',
        'logging.channels.daily.days' => $days,
        'logging.channels.daily.level' => $template['LOG_LEVEL'],
    ]);
    Log::forgetChannel('daily');
    Log::forgetChannel('stack');

    Log::error('retention probe');
    Log::info('below the production level');
    Log::channel('daily')->getLogger()->close();

    $files = collect(File::files($this->logDirectory))
        ->map(fn (SplFileInfo $file): string => $file->getFilename())
        ->sort()
        ->values()
        ->all();
    $today = 'laravel-'.date('Y-m-d').'.log';

    expect($files)->toBe([
        'laravel-'.date('Y-m-d', strtotime('-2 days')).'.log',
        'laravel-'.date('Y-m-d', strtotime('-1 day')).'.log',
        $today,
    ])->and(File::get($this->logDirectory.'/'.$today))
        ->toContain('retention probe')
        ->not->toContain('below the production level');
});
