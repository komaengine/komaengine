<?php

namespace App\Engine\Plugins\Contracts;

/**
 * The contract every Koma plugin entry class must implement.
 *
 * `register()` runs early and should only bind services or register hooks —
 * it must not assume other plugins are loaded yet. `boot()` runs once every
 * enabled plugin has been registered, so it is safe to interact with them.
 */
interface Plugin
{
    public function register(): void;

    public function boot(): void;
}
