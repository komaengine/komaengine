<?php

use App\Archives\ArchiveUploadLimits;

test('php ini upload sizes are converted into bytes', function (string|false|null $value, ?int $expected) {
    expect(ArchiveUploadLimits::parseIniBytes($value))->toBe($expected);
})->with([
    'megabytes' => ['512M', 512 * 1024 * 1024],
    'gigabytes' => ['1.5G', (int) (1.5 * 1024 * 1024 * 1024)],
    'kilobytes' => ['2048K', 2048 * 1024],
    'raw bytes' => ['4096', 4096],
    'unlimited zero' => ['0', null],
    'unlimited negative' => ['-1', null],
    'unknown' => ['not-a-size', null],
    'missing' => [false, null],
]);

test('byte counts are formatted for archive upload diagnostics', function () {
    expect(ArchiveUploadLimits::formatBytes(500 * 1024 * 1024))->toBe('500 MiB')
        ->and(ArchiveUploadLimits::formatBytes((int) (1.5 * 1024 * 1024 * 1024)))->toBe('1.5 GiB');
});
