<?php

namespace App\Engine\Themes;

use App\Engine\Extensions\ExtensionCompatibility;
use App\Engine\Settings\Settings;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * Resolves the active front-end theme from the top-level `themes/` directory.
 *
 * A theme is a folder with a `theme.json` manifest, a `views/` directory of
 * Blade templates (home, browse, series, reader, …) and an `assets/` directory
 * of *precompiled* CSS/JS — so a theme can be dropped onto a server with no
 * build step, which is essential for flat-file/shared hosting.
 *
 * Views are exposed under the `theme::` namespace. The active theme is checked
 * first, then a built-in fallback, so a theme that omits a template still
 * renders instead of erroring.
 */
class ThemeManager
{
    protected const DEFAULT_THEME = 'aozora';

    protected const PREVIEW_EXTENSIONS = [
        'avif',
        'gif',
        'jpeg',
        'jpg',
        'png',
        'svg',
        'webp',
    ];

    /** @var array<string, array<string, mixed>>|null */
    protected ?array $discovered = null;

    public function __construct(protected Settings $settings) {}

    public function path(?string $append = null): string
    {
        $base = rtrim((string) config('koma.paths.themes', base_path('themes')), '/\\');

        return $base.($append ? DIRECTORY_SEPARATOR.ltrim($append, '/\\') : '');
    }

    /**
     * @return array<string, array<string, mixed>> keyed by slug
     */
    public function discover(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }

        $this->discovered = [];

        if (! is_dir($this->path())) {
            return $this->discovered;
        }

        foreach (glob($this->path('*'), GLOB_ONLYDIR) ?: [] as $dir) {
            $manifest = $this->readManifest($dir);

            if ($manifest !== null) {
                $this->discovered[$manifest['slug']] = $manifest;
            }
        }

        return $this->discovered;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $active = $this->activeSlug();

        return array_map(
            fn (array $manifest) => array_merge(
                $manifest,
                ExtensionCompatibility::evaluate($manifest),
                [
                    'active' => $manifest['slug'] === $active,
                    'preview_url' => $this->previewUrl($manifest['slug']),
                ],
            ),
            $this->discover(),
        );
    }

    public function activeSlug(): string
    {
        $slug = (string) $this->settings->get('active_theme', self::DEFAULT_THEME);

        $themes = $this->discover();

        if (isset($themes[$slug]) && ExtensionCompatibility::evaluate($themes[$slug])['compatible']) {
            return $slug;
        }

        if (isset($themes[self::DEFAULT_THEME])
            && ExtensionCompatibility::evaluate($themes[self::DEFAULT_THEME])['compatible']) {
            return self::DEFAULT_THEME;
        }

        foreach ($themes as $candidate => $manifest) {
            if (ExtensionCompatibility::evaluate($manifest)['compatible']) {
                return $candidate;
            }
        }

        return self::DEFAULT_THEME;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function active(): ?array
    {
        return $this->discover()[$this->activeSlug()] ?? null;
    }

    public function activate(string $slug): bool
    {
        $manifest = $this->discover()[$slug] ?? null;

        if ($manifest === null || ! ExtensionCompatibility::evaluate($manifest)['compatible']) {
            return false;
        }

        $this->settings->set('active_theme', $slug);

        return true;
    }

    /**
     * Point the `theme::` namespace at active theme -> Aozora -> app fallback.
     */
    public function registerViews(): void
    {
        $paths = $this->viewPaths();

        if ($paths !== []) {
            View::replaceNamespace('theme', $paths);
        }
    }

    /**
     * Return the ordered, de-duplicated directories in the public view contract.
     *
     * @return array<int, string>
     */
    public function viewPaths(): array
    {
        $themes = $this->discover();
        $candidates = [];

        if ($active = $this->active()) {
            $candidates[] = $active['path'].DIRECTORY_SEPARATOR.'views';
        }

        if (isset($themes[self::DEFAULT_THEME])) {
            $candidates[] = $themes[self::DEFAULT_THEME]['path'].DIRECTORY_SEPARATOR.'views';
        }

        $candidates[] = resource_path('views/theme');
        $paths = [];

        foreach ($candidates as $candidate) {
            $resolved = realpath($candidate);

            if ($resolved !== false && is_dir($resolved) && ! in_array($resolved, $paths, true)) {
                $paths[] = $resolved;
            }
        }

        return $paths;
    }

    /**
     * Build a cache-busted URL for a file in the active theme's assets dir.
     */
    public function asset(string $file): string
    {
        $file = ltrim($file, '/');
        $slug = $this->activeSlug();
        $absolute = $this->assetPathForTheme($slug, $file);
        $version = $absolute === null ? null : filemtime($absolute);
        $query = ['theme' => $slug];

        if ($version !== null) {
            $query['v'] = $version;
        }

        // Relative URL so CSS/JS load on whatever host serves the app (MAMP, artisan
        // serve, Docker, flat-file) without depending on APP_URL being exact. The
        // theme slug is part of the cache key so switching between themes cannot
        // reuse another theme's immutable CSS/JS response when mtimes match.
        return '/theme-assets/'.$file.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Resolve an asset path against the active theme (used by the asset route).
     */
    public function assetPath(string $file): ?string
    {
        return $this->assetPathForTheme($this->activeSlug(), $file);
    }

    /**
     * Resolve an asset path against an explicit installed theme.
     */
    public function assetPathForTheme(string $slug, string $file): ?string
    {
        $active = $this->discover()[$slug] ?? null;

        if ($active === null) {
            return null;
        }

        return $this->containedFile(
            $active['path'].DIRECTORY_SEPARATOR.'assets',
            $file,
        );
    }

    /**
     * Resolve one theme's manifest-declared preview without consulting the
     * active theme. The resulting admin URL contains only the explicit slug.
     */
    public function previewPath(string $slug): ?string
    {
        $manifest = $this->discover()[$slug] ?? null;
        $preview = $manifest['preview'] ?? null;

        if ($manifest === null || ! is_string($preview) || trim($preview) === '') {
            return null;
        }

        $target = $this->containedFile($manifest['path'], $preview);

        if ($target === null
            || ! in_array(strtolower(pathinfo($target, PATHINFO_EXTENSION)), self::PREVIEW_EXTENSIONS, true)) {
            return null;
        }

        return $target;
    }

    public function previewUrl(string $slug): ?string
    {
        if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $slug) !== 1
            || $this->previewPath($slug) === null) {
            return null;
        }

        return route('admin.themes.preview', ['theme' => $slug], false);
    }

    protected function containedFile(string $directory, string $file): ?string
    {
        if (str_contains($file, "\0")) {
            return null;
        }

        $base = realpath($directory);
        $target = realpath($directory.DIRECTORY_SEPARATOR.ltrim($file, '/\\'));

        if ($base === false
            || $target === false
            || ($target !== $base && ! str_starts_with($target, $base.DIRECTORY_SEPARATOR))) {
            return null;
        }

        return is_file($target) ? $target : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function readManifest(string $dir): ?array
    {
        $file = $dir.DIRECTORY_SEPARATOR.'theme.json';

        if (! is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (! is_array($data)) {
            return null;
        }

        $slug = $data['slug'] ?? Str::slug(basename($dir));
        $requirements = is_array($data['requires'] ?? null) ? $data['requires'] : [];

        return [
            'slug' => $slug,
            'name' => $data['name'] ?? Str::headline($slug),
            'description' => $data['description'] ?? '',
            'version' => $data['version'] ?? '1.0.0',
            'author' => $data['author'] ?? '',
            'accent' => $data['accent'] ?? '#2f6df6',
            'preview' => is_string($data['preview'] ?? null) ? trim($data['preview']) : null,
            'path' => $dir,
            'requires' => [
                'koma' => $requirements['koma'] ?? $data['requires_koma'] ?? null,
                'php' => $requirements['php'] ?? $data['requires_php'] ?? null,
            ],
        ];
    }
}
