<?php

namespace Koma\Plugins\HelloKoma;

use App\Engine\Plugins\Contracts\Plugin;
use App\Support\Facades\Hook;

/**
 * Example plugin.
 *
 * It exists to prove the extension seam end-to-end: dropping this folder into
 * `plugins/` and enabling it changes the running app without editing core.
 * A real plugin (payments, watermarking, importers, …) registers the same way.
 */
class HelloKomaPlugin implements Plugin
{
    public function register(): void
    {
        Hook::addFilter('admin.footer_text', function (string $text): string {
            return $text.'  ·  extended by the Hello Koma plugin';
        });
    }

    public function boot(): void
    {
        // Nothing to do on boot for this example.
    }
}
