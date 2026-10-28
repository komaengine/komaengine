<?php

namespace App\Engine\Updates;

use App\Engine\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The optional update notice. Off until an administrator turns it on. When on,
 * it fetches one public file at most once a day, sends nothing about the site
 * (no query string, cookies, or identifying headers), and compares versions
 * here. A failed fetch is silent; the Updates page says when one last worked.
 */
final class UpdateCheck
{
    public const SETTING = 'update_checks';

    public const CACHE_KEY = 'koma:update-check';

    public const INTERVAL_HOURS = 24;

    /** A newer feed would still be a few hundred bytes; anything far larger is refused. */
    private const MAX_FEED_BYTES = 16384;

    private const RELEASE_URL_PREFIXES = [
        'https://github.com/komaengine/komaengine/releases/',
        'https://komaengine.com/',
    ];

    public function __construct(
        private readonly Settings $settings,
        private readonly Repository $cache,
    ) {}

    /** KOMA_UPDATE_CHECK=false turns checks off whatever an administrator chose. */
    public function allowedByServer(): bool
    {
        return (bool) config('koma.updates.allowed', true);
    }

    public function enabled(): bool
    {
        return $this->allowedByServer() && (bool) $this->settings->get(self::SETTING, false);
    }

    public function enable(): void
    {
        $this->settings->set(self::SETTING, true);
        $this->runIfDue();
    }

    public function disable(): void
    {
        $this->settings->set(self::SETTING, false);
        $this->cache->forget(self::CACHE_KEY);
    }

    /** Called every hour by the scheduler; fetches only once a day. */
    public function runIfDue(): void
    {
        if (! $this->enabled()) {
            return;
        }

        $attempted = $this->state()['attempted_at'];

        if ($attempted !== null && $attempted->greaterThan(CarbonImmutable::now()->subHours(self::INTERVAL_HOURS))) {
            return;
        }

        $this->fetch();
    }

    /**
     * @return array{enabled: bool, allowed: bool, current: string, attempted_at: ?CarbonImmutable,
     *     checked_at: ?CarbonImmutable, failed: bool, latest: ?array{version: string, tag: string, released: string, release_url: string},
     *     update_available: bool}
     */
    public function status(): array
    {
        $state = $this->enabled() ? $this->state() : ['attempted_at' => null, 'checked_at' => null, 'failed' => false, 'latest' => null];
        $current = (string) config('koma.version');
        $latest = $state['latest'];

        return [
            'enabled' => $this->enabled(),
            'allowed' => $this->allowedByServer(),
            'current' => $current,
            ...$state,
            'update_available' => $latest !== null && version_compare($latest['version'], $current, '>'),
        ];
    }

    /** The update steps for how this site runs. */
    public function updateGuideUrl(): string
    {
        $docs = rtrim((string) config('koma.docs_url'), '/');

        return config('koma.runtime') === 'docker-compose'
            ? $docs.'/install/docker.md#safe-update-outline'
            : $docs.'/install/shared-hosting.md#update-and-rollback';
    }

    private function fetch(): void
    {
        $state = $this->state();
        $state['attempted_at'] = CarbonImmutable::now();

        try {
            // No query string, cookies, or version: the request says nothing
            // about the site that sends it.
            $response = Http::withUserAgent('KomaEngine')
                ->accept('application/json')
                ->connectTimeout(3)
                ->timeout(5)
                ->withoutRedirecting()
                ->get((string) config('koma.updates.feed_url'));

            $latest = $response->successful() ? self::parse($response->body()) : null;
        } catch (Throwable) {
            $latest = null;
        }

        if ($latest === null) {
            $state['failed'] = true;
        } else {
            $state = ['latest' => $latest, 'checked_at' => $state['attempted_at'], 'failed' => false] + $state;
        }

        $this->cache->forever(self::CACHE_KEY, [
            'attempted_at' => $state['attempted_at']->toIso8601String(),
            'checked_at' => $state['checked_at']?->toIso8601String(),
            'failed' => $state['failed'],
            'latest' => $state['latest'],
        ]);
    }

    /**
     * @return array{attempted_at: ?CarbonImmutable, checked_at: ?CarbonImmutable, failed: bool,
     *     latest: ?array{version: string, tag: string, released: string, release_url: string}}
     */
    private function state(): array
    {
        $stored = $this->cache->get(self::CACHE_KEY);
        $stored = is_array($stored) ? $stored : [];
        $time = fn (mixed $value): ?CarbonImmutable => is_string($value) ? rescue(fn () => CarbonImmutable::parse($value), null, false) : null;

        return [
            'attempted_at' => $time($stored['attempted_at'] ?? null),
            'checked_at' => $time($stored['checked_at'] ?? null),
            'failed' => (bool) ($stored['failed'] ?? false),
            'latest' => is_array($stored['latest'] ?? null) ? $stored['latest'] : null,
        ];
    }

    /**
     * The feed is komaengine.com/releases/latest.json, written by the website
     * build: {"version", "tag", "released", "release_url"}. Only a stable
     * version with a release link on GitHub or komaengine.com is accepted.
     *
     * @return array{version: string, tag: string, released: string, release_url: string}|null
     */
    public static function parse(string $body): ?array
    {
        if ($body === '' || strlen($body) > self::MAX_FEED_BYTES) {
            return null;
        }

        $data = json_decode($body, true);

        if (! is_array($data)) {
            return null;
        }

        $version = $data['version'] ?? null;
        $releaseUrl = $data['release_url'] ?? null;

        if (! is_string($version) || preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/', $version) !== 1
            || ($data['tag'] ?? null) !== 'v'.$version
            || ! is_string($data['released'] ?? null) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $data['released']) !== 1
            || ! is_string($releaseUrl)
            || ! collect(self::RELEASE_URL_PREFIXES)->contains(fn (string $prefix) => str_starts_with($releaseUrl, $prefix))) {
            return null;
        }

        return [
            'version' => $version,
            'tag' => $data['tag'],
            'released' => $data['released'],
            'release_url' => $releaseUrl,
        ];
    }
}
