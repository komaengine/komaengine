<?php

namespace App\Http\Middleware;

use App\Install\InstalledState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureKomaInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldSkip($request) || app(InstalledState::class)->installed()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Koma Engine is not installed.'], 503);
        }

        return redirect()->route('install');
    }

    protected function shouldSkip(Request $request): bool
    {
        $route = $request->route()?->getName();

        if ($route && (str_starts_with($route, 'install') || str_ends_with($route, 'livewire.update'))) {
            return true;
        }

        $path = $request->path();

        return $path === 'up'
            || str_starts_with($path, 'build/')
            || str_starts_with($path, 'favicon');
    }
}
