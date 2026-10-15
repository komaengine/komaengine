<?php

namespace App\Engine\Hooks;

/**
 * A WordPress-style action/filter system.
 *
 * Actions let plugins run side effects at defined points in the engine
 * (e.g. after a chapter is published). Filters let plugins transform a value
 * before the engine uses it (e.g. the list of admin menu items).
 *
 * Callbacks are grouped by priority (lower runs first) and, within the same
 * priority, in registration order — mirroring WordPress semantics that theme
 * and plugin authors already understand.
 */
class HookManager
{
    /** @var array<string, array<int, array<int, callable>>> */
    protected array $actions = [];

    /** @var array<string, array<int, array<int, callable>>> */
    protected array $filters = [];

    public function addAction(string $hook, callable $callback, int $priority = 10): void
    {
        $this->actions[$hook][$priority][] = $callback;
    }

    public function addFilter(string $hook, callable $callback, int $priority = 10): void
    {
        $this->filters[$hook][$priority][] = $callback;
    }

    public function hasAction(string $hook): bool
    {
        return ! empty($this->actions[$hook]);
    }

    public function hasFilter(string $hook): bool
    {
        return ! empty($this->filters[$hook]);
    }

    /**
     * Fire every callback registered for an action, in priority order.
     */
    public function doAction(string $hook, mixed ...$args): void
    {
        foreach ($this->sortedCallbacks($this->actions[$hook] ?? []) as $callback) {
            $callback(...$args);
        }
    }

    /**
     * Pass a value through every registered filter and return the result.
     *
     * The first argument is the value being filtered; any further arguments
     * are passed to each callback for context but are not themselves filtered.
     */
    public function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        foreach ($this->sortedCallbacks($this->filters[$hook] ?? []) as $callback) {
            $value = $callback($value, ...$args);
        }

        return $value;
    }

    /**
     * Flatten a priority-keyed callback bag into a single ordered list.
     *
     * @param  array<int, array<int, callable>>  $byPriority
     * @return array<int, callable>
     */
    protected function sortedCallbacks(array $byPriority): array
    {
        ksort($byPriority);

        return array_merge(...array_values($byPriority)) ?: [];
    }
}
