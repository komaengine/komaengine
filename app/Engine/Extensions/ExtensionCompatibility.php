<?php

namespace App\Engine\Extensions;

final class ExtensionCompatibility
{
    /**
     * @param  array<string, mixed>  $manifest
     * @return array{compatible: bool, issues: array<int, string>}
     */
    public static function evaluate(array $manifest): array
    {
        $requirements = (array) ($manifest['requires'] ?? []);
        $issues = [];

        $requiredKoma = self::minimumVersion($requirements['koma'] ?? null);
        $requiredPhp = self::minimumVersion($requirements['php'] ?? null);
        $currentKoma = self::minimumVersion(config('koma.version', '0.0.0')) ?? '0.0.0';

        if ($requiredKoma !== null && version_compare($currentKoma, $requiredKoma, '<')) {
            $issues[] = "Requires Koma Engine {$requiredKoma} or newer.";
        }

        if ($requiredPhp !== null && version_compare(PHP_VERSION, $requiredPhp, '<')) {
            $issues[] = "Requires PHP {$requiredPhp} or newer.";
        }

        return [
            'compatible' => $issues === [],
            'issues' => $issues,
        ];
    }

    protected static function minimumVersion(mixed $version): ?string
    {
        if (! is_string($version) || trim($version) === '') {
            return null;
        }

        return ltrim(trim($version), "vV>= \t\n\r\0\x0B");
    }
}
