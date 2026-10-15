<?php

namespace App\Support;

use App\Engine\Plugins\PluginManager;
use App\Engine\Settings\Settings;
use App\Engine\Themes\ThemeManager;
use Carbon\CarbonInterface;

/**
 * Convenience accessors for the engine's services, for use in views and code
 * where injecting the managers would be awkward.
 */
class Koma
{
    public static function settings(): Settings
    {
        return app(Settings::class);
    }

    public static function setting(string $key, mixed $default = null): mixed
    {
        return app(Settings::class)->get($key, $default);
    }

    public static function themes(): ThemeManager
    {
        return app(ThemeManager::class);
    }

    public static function themeAsset(string $file): string
    {
        return app(ThemeManager::class)->asset($file);
    }

    public static function plugins(): PluginManager
    {
        return app(PluginManager::class);
    }

    public static function siteTitle(): string
    {
        return (string) app(Settings::class)->get('site_title', config('app.name'));
    }

    /** @return array{label: string, url: string}|null */
    public static function supportLink(): ?array
    {
        $url = self::publicHttpUrl('support_url');

        if ($url === null) {
            return null;
        }

        $label = trim((string) self::setting('support_label', ''));

        return [
            'label' => $label !== '' ? $label : 'Support this site',
            'url' => $url,
        ];
    }

    /** @return array{name: string, url: string|null}|null */
    public static function publisherIdentity(): ?array
    {
        $name = trim((string) self::setting('publisher_name', ''));

        if ($name === '') {
            return null;
        }

        return [
            'name' => $name,
            'url' => self::publicHttpUrl('publisher_url'),
        ];
    }

    /** Format an absolute date using the configured site date format and timezone. */
    public static function formatDate(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '';
        }

        return $date->timezone(config('app.timezone'))
            ->translatedFormat((string) app(Settings::class)->get('date_format', 'M j, Y'));
    }

    /** Format an absolute time using the configured site time format and timezone. */
    public static function formatTime(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '';
        }

        return $date->timezone(config('app.timezone'))
            ->translatedFormat((string) app(Settings::class)->get('time_format', 'g:i a'));
    }

    /**
     * Format a date the way the operator chose under Settings → General.
     *
     * Relative wording ("2 days ago") reads well while a chapter is fresh and
     * badly once it is a year old, so the default mode switches to an absolute
     * date after a week. Absolute output honours the configured date format.
     */
    public static function timeAgo(?CarbonInterface $date, bool $short = false): string
    {
        if ($date === null) {
            return '';
        }

        $mode = (string) app(Settings::class)->get('date_display', 'recent_relative');

        return match ($mode) {
            'absolute' => self::formatDate($date),
            'relative' => $date->diffForHumans(short: $short),
            default => abs($date->diffInDays(now())) < 7
                ? $date->diffForHumans(short: $short)
                : self::formatDate($date),
        };
    }

    public static function formatDateTime(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '';
        }

        return trim(self::formatDate($date).' · '.self::formatTime($date), ' ·');
    }

    /**
     * Configured privacy/terms/DMCA/contact links for theme footers.
     *
     * @return list<array{label: string, url: string}>
     */
    public static function legalLinks(): array
    {
        $settings = app(Settings::class);
        $links = [];

        foreach (['privacy_policy_url' => 'Privacy Policy', 'terms_url' => 'Terms', 'dmca_url' => 'DMCA'] as $key => $label) {
            $url = trim((string) $settings->get($key, ''));
            if ($url !== '') {
                $links[] = ['label' => $label, 'url' => $url];
            }
        }

        $contact = trim((string) $settings->get('contact_email', ''));
        if ($contact !== '') {
            $links[] = ['label' => 'Contact', 'url' => 'mailto:'.$contact];
        }

        return $links;
    }

    /** Return a configured absolute HTTP(S) URL, or no public value. */
    private static function publicHttpUrl(string $key): ?string
    {
        $url = trim((string) self::setting($key, ''));

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? $parts['host'] ?? null : null;

        return in_array($scheme, ['http', 'https'], true) && is_string($host) && $host !== ''
            ? $url
            : null;
    }
}
