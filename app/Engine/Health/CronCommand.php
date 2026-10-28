<?php

namespace App\Engine\Health;

use Closure;

/**
 * The one cron line a hosting panel needs for this site, built from its folder
 * and the PHP that is serving the request.
 */
final class CronCommand
{
    /** @return array{line: string, php_known: bool} */
    public static function forThisSite(): array
    {
        $php = self::cliBinary(PHP_BINARY);

        return [
            'line' => sprintf(
                'cd %s && %s artisan schedule:run >/dev/null 2>&1',
                self::shellWord(base_path()),
                $php === null ? 'php' : self::shellWord($php),
            ),
            'php_known' => $php !== null,
        ];
    }

    /**
     * Pages are served by PHP-FPM, CGI, or LiteSpeed, whose binary is not the
     * command-line PHP a cron job needs. Panels install that one beside it,
     * for example /opt/plesk/php/8.3/sbin/php-fpm and /opt/plesk/php/8.3/bin/php.
     *
     * @param  (Closure(string): bool)|null  $executable
     */
    public static function cliBinary(string $binary, ?Closure $executable = null): ?string
    {
        $executable ??= fn (string $path): bool => @is_file($path) && @is_executable($path);

        if ($binary === '') {
            return null;
        }

        if (preg_match('/\A(php-fpm|php-cgi|lsphp)(.*)\z/', basename($binary), $matches) !== 1) {
            return $binary;
        }

        $directory = dirname($binary);
        $candidates = $matches[1] === 'lsphp'
            ? [$directory.'/php']
            : [dirname($directory).'/bin/php'.$matches[2], dirname($directory).'/bin/php', $directory.'/php'.$matches[2]];

        foreach ($candidates as $candidate) {
            if ($executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function shellWord(string $value): string
    {
        return preg_match('#\A[A-Za-z0-9_./-]+\z#', $value) === 1 ? $value : escapeshellarg($value);
    }
}
