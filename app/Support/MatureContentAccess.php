<?php

namespace App\Support;

use App\Engine\Settings\Settings;
use App\Models\Series;
use Illuminate\Http\Request;

class MatureContentAccess
{
    public const CONFIRMED_SESSION_KEY = 'mature_content.confirmed';

    public const INTENDED_SESSION_KEY = 'mature_content.intended';

    public function __construct(protected Settings $settings) {}

    public function gateEnabled(): bool
    {
        return (bool) $this->settings->get('mature_gate', true);
    }

    public function allows(Request $request): bool
    {
        return ! $this->gateEnabled()
            || (bool) $request->session()->get(self::CONFIRMED_SESSION_KEY, false);
    }

    public function hidesMature(Request $request): bool
    {
        return ! $this->allows($request);
    }

    public function requiresConfirmation(Series $series, Request $request): bool
    {
        return $series->content_rating === 'mature' && $this->hidesMature($request);
    }

    public function confirm(Request $request): void
    {
        $request->session()->put(self::CONFIRMED_SESSION_KEY, true);
    }

    public function decline(Request $request): void
    {
        $request->session()->forget([
            self::CONFIRMED_SESSION_KEY,
            self::INTENDED_SESSION_KEY,
        ]);
    }

    public function rememberIntended(Request $request, ?string $path): void
    {
        if ($safePath = $this->safeLocalPath($path)) {
            $request->session()->put(self::INTENDED_SESSION_KEY, $safePath);
        }
    }

    public function pullIntended(Request $request, string $fallback): string
    {
        $path = $request->session()->pull(self::INTENDED_SESSION_KEY);

        return $this->safeLocalPath(is_string($path) ? $path : null) ?? $fallback;
    }

    protected function safeLocalPath(?string $path): ?string
    {
        if ($path === null
            || ! str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            return null;
        }

        return $path;
    }
}
