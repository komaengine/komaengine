<?php

namespace App\Providers;

use App\Engine\Hooks\HookManager;
use App\Engine\Plugins\PluginManager;
use App\Engine\Settings\Settings;
use App\Engine\Themes\ThemeManager;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

/**
 * Boots the Koma "engine": the hook system, settings store, and the theme and
 * plugin managers that make the CMS extensible in the spirit of WordPress.
 */
class EngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HookManager::class);
        $this->app->singleton(Settings::class);
        $this->app->singleton(PluginManager::class);
        $this->app->singleton(ThemeManager::class);

        AliasLoader::getInstance()->alias('Hook', \App\Support\Facades\Hook::class);
    }

    public function boot(): void
    {
        // Make the active theme's views resolvable under the `theme::` namespace.
        $this->app->make(ThemeManager::class)->registerViews();

        // Load enabled plugins so they can register their hooks before requests
        // are handled. Skipped in console during package discovery.
        $this->app->make(PluginManager::class)->boot();

        $this->registerBladeDirectives();

        $this->app->make(HookManager::class)->doAction('koma.booted');
    }

    protected function registerBladeDirectives(): void
    {
        // @themeAsset('theme.css') -> cache-busted URL to the active theme asset.
        Blade::directive('themeAsset', function (string $expression) {
            return "<?php echo e(app(\App\Engine\Themes\ThemeManager::class)->asset($expression)); ?>";
        });

        // @action('hook.name', ...$args) -> fire an action hook from a view.
        Blade::directive('action', function (string $expression) {
            return "<?php app(\App\Engine\Hooks\HookManager::class)->doAction($expression); ?>";
        });

        // @filter('hook.name', $value) -> echo a filtered value from a view.
        Blade::directive('filter', function (string $expression) {
            return "<?php echo e(app(\App\Engine\Hooks\HookManager::class)->applyFilters($expression)); ?>";
        });
    }
}
