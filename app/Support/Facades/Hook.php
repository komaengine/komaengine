<?php

namespace App\Support\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void addAction(string $hook, callable $callback, int $priority = 10)
 * @method static void addFilter(string $hook, callable $callback, int $priority = 10)
 * @method static bool hasAction(string $hook)
 * @method static bool hasFilter(string $hook)
 * @method static void doAction(string $hook, mixed ...$args)
 * @method static mixed applyFilters(string $hook, mixed $value, mixed ...$args)
 *
 * @see \App\Engine\Hooks\HookManager
 */
class Hook extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \App\Engine\Hooks\HookManager::class;
    }
}
