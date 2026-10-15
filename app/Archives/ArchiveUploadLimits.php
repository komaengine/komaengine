<?php

namespace App\Archives;

final class ArchiveUploadLimits
{
    /**
     * @return array{
     *     application_bytes:int,
     *     application_label:string,
     *     php_upload_bytes:?int,
     *     php_upload_label:string,
     *     php_post_bytes:?int,
     *     php_post_label:string,
     *     effective_bytes:int,
     *     effective_label:string,
     *     constrained_by_server:bool
     * }
     */
    public function details(): array
    {
        $applicationBytes = max(
            1,
            (int) config('koma.archives.imports.max_archive_kb', 512000) * 1024,
        );
        $phpUploadLabel = (string) (ini_get('upload_max_filesize') ?: 'unlimited');
        $phpPostLabel = (string) (ini_get('post_max_size') ?: 'unlimited');
        $phpUploadBytes = self::parseIniBytes($phpUploadLabel);
        $phpPostBytes = self::parseIniBytes($phpPostLabel);
        $limits = array_values(array_filter(
            [$applicationBytes, $phpUploadBytes, $phpPostBytes],
            fn (?int $bytes): bool => $bytes !== null && $bytes > 0,
        ));
        $effectiveBytes = min($limits);

        return [
            'application_bytes' => $applicationBytes,
            'application_label' => self::formatBytes($applicationBytes),
            'php_upload_bytes' => $phpUploadBytes,
            'php_upload_label' => $phpUploadLabel,
            'php_post_bytes' => $phpPostBytes,
            'php_post_label' => $phpPostLabel,
            'effective_bytes' => $effectiveBytes,
            'effective_label' => self::formatBytes($effectiveBytes),
            'constrained_by_server' => $effectiveBytes < $applicationBytes,
        ];
    }

    public static function parseIniBytes(string|false|null $value): ?int
    {
        if ($value === false || $value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || $value === '-1' || $value === '0') {
            return null;
        }

        if (! preg_match('/\A(\d+(?:\.\d+)?)\s*([kmgt]?)b?\z/i', $value, $matches)) {
            return null;
        }

        $multiplier = match (strtolower($matches[2])) {
            't' => 1024 ** 4,
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return (int) floor((float) $matches[1] * $multiplier);
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return number_format($bytes / (1024 ** 3), 1).' GiB';
        }

        if ($bytes >= 1024 ** 2) {
            $megabytes = $bytes / (1024 ** 2);
            $decimals = abs($megabytes - round($megabytes)) < 0.0001 ? 0 : 1;

            return number_format($megabytes, $decimals).' MiB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1).' KiB';
        }

        return number_format($bytes).' bytes';
    }
}
