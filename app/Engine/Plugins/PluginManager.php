<?php

namespace App\Engine\Plugins;

use App\Engine\Extensions\ExtensionCompatibility;
use App\Engine\Plugins\Contracts\Plugin;
use App\Engine\Settings\Settings;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Discovers, activates and boots plugins living in the top-level `plugins/`
 * directory — Koma's equivalent of `wp-content/plugins`.
 *
 * Each plugin is a folder containing a `plugin.json` manifest and a PHP entry
 * class implementing {@see Plugin}. Enabled/disabled state is persisted in
 * settings (key `enabled_plugins`) so it survives across requests and is
 * editable from the admin without touching the filesystem.
 */
class PluginManager
{
    /** @var array<string, array<string, mixed>>|null */
    protected ?array $discovered = null;

    /** @var array<string, Plugin> */
    protected array $instances = [];

    protected bool $booted = false;

    public function __construct(protected Settings $settings) {}

    public function path(?string $append = null): string
    {
        $base = rtrim((string) config('koma.paths.plugins', base_path('plugins')), '/\\');

        return $base.($append ? DIRECTORY_SEPARATOR.ltrim($append, '/\\') : '');
    }

    /**
     * Scan the plugins directory and parse each valid manifest.
     *
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
     * All discovered plugins, each flagged with its current enabled state.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $enabled = $this->enabledSlugs();
        $failures = $this->failures();

        return array_map(
            function (array $manifest) use ($enabled, $failures) {
                return array_merge(
                    $manifest,
                    ExtensionCompatibility::evaluate($manifest),
                    [
                        'enabled' => in_array($manifest['slug'], $enabled, true),
                        'failure' => $failures[$manifest['slug']] ?? null,
                    ],
                );
            },
            $this->discover(),
        );
    }

    /**
     * @return array<int, string>
     */
    public function enabledSlugs(): array
    {
        return (array) $this->settings->get('enabled_plugins', []);
    }

    public function isEnabled(string $slug): bool
    {
        return in_array($slug, $this->enabledSlugs(), true);
    }

    public function enable(string $slug): bool
    {
        $manifest = $this->discover()[$slug] ?? null;

        if ($manifest === null || ! ExtensionCompatibility::evaluate($manifest)['compatible']) {
            return false;
        }

        $this->clearFailure($slug);

        if ($this->isEnabled($slug)) {
            return true;
        }

        $this->settings->set('enabled_plugins', array_values(
            array_unique([...$this->enabledSlugs(), $slug]),
        ));

        return true;
    }

    public function disable(string $slug): void
    {
        $this->settings->set('enabled_plugins', array_values(
            array_diff($this->enabledSlugs(), [$slug]),
        ));
    }

    /**
     * @return array<string, array{stage: string, message: string, at: string}>
     */
    public function failures(): array
    {
        $failures = $this->settings->get('plugin_failures', []);

        return is_array($failures) ? $failures : [];
    }

    public function clearFailure(string $slug): void
    {
        $failures = $this->failures();

        if (! isset($failures[$slug])) {
            return;
        }

        unset($failures[$slug]);
        $this->settings->set('plugin_failures', $failures);
    }

    /**
     * Register and boot every enabled plugin. Safe to call once per request;
     * a plugin that throws is skipped so one bad plugin cannot take down the
     * whole site.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        $plugins = [];

        foreach ($this->enabledSlugs() as $slug) {
            $manifest = $this->discover()[$slug] ?? null;

            if ($manifest === null) {
                $this->recordFailure($slug, 'loading', 'The plugin manifest could not be found.');

                continue;
            }

            $compatibility = ExtensionCompatibility::evaluate($manifest);

            if (! $compatibility['compatible']) {
                $this->recordFailure($slug, 'compatibility', implode(' ', $compatibility['issues']));

                continue;
            }

            try {
                $instance = $this->instantiate($slug);
                $instance->register();
                $plugins[$slug] = $instance;
            } catch (Throwable $e) {
                report($e);
                $this->recordFailure($slug, 'register', $e->getMessage());
            }
        }

        foreach ($plugins as $slug => $instance) {
            try {
                $instance->boot();
            } catch (Throwable $e) {
                report($e);
                $this->recordFailure($slug, 'boot', $e->getMessage());
            }
        }
    }

    protected function instantiate(string $slug): Plugin
    {
        if (isset($this->instances[$slug])) {
            return $this->instances[$slug];
        }

        $manifest = $this->discover()[$slug] ?? null;

        if ($manifest === null) {
            throw new RuntimeException("Plugin [{$slug}] is not installed.");
        }

        $entry = $manifest['path'].DIRECTORY_SEPARATOR.$manifest['entry'];

        if (! class_exists($manifest['class']) && is_file($entry)) {
            require_once $entry;
        }

        if (! class_exists($manifest['class'])) {
            throw new RuntimeException("Plugin entry class [{$manifest['class']}] could not be loaded.");
        }

        $instance = app($manifest['class']);

        if (! $instance instanceof Plugin) {
            throw new RuntimeException("Plugin entry class [{$manifest['class']}] must implement the plugin contract.");
        }

        return $this->instances[$slug] = $instance;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function readManifest(string $dir): ?array
    {
        $file = $dir.DIRECTORY_SEPARATOR.'plugin.json';

        if (! is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (! is_array($data) || empty($data['class']) || empty($data['entry'])) {
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
            'class' => $data['class'],
            'entry' => $data['entry'],
            'path' => $dir,
            'requires' => [
                'koma' => $requirements['koma'] ?? $data['requires_koma'] ?? null,
                'php' => $requirements['php'] ?? $data['requires_php'] ?? null,
            ],
        ];
    }

    protected function recordFailure(string $slug, string $stage, string $message): void
    {
        $failures = $this->failures();
        $failures[$slug] = [
            'stage' => $stage,
            'message' => Str::limit($message !== '' ? $message : 'Unknown plugin error.', 240, ''),
            'at' => now()->toIso8601String(),
        ];

        $this->settings->set('plugin_failures', $failures);
        $this->disable($slug);
    }
}
