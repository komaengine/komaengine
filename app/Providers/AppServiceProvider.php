<?php

namespace App\Providers;

use App\Engine\Settings\SettingsConfigBridge;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->make(SettingsConfigBridge::class)->apply();

        if (config('livewire.temporary_file_upload.rules') === null) {
            config()->set('livewire.temporary_file_upload.rules', [
                'required',
                'file',
                'max:'.max(
                    (int) config('koma.uploads.page_max_kb', 20480),
                    (int) config('koma.uploads.page_temporary_max_kb', 25600),
                ),
            ]);
        }

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('comments', fn (Request $request) => Limit::perMinute(
            (int) config('koma.comments.rate_limit_per_minute', 5),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('comment-reports', fn (Request $request) => Limit::perMinute(
            (int) config('koma.comments.report_rate_limit_per_minute', 6),
        )->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Administrators implicitly pass every capability check.
        Gate::before(function (User $user, string $ability) {
            return $user->hasRole('Administrator') ? true : null;
        });

        // Can this user reach the admin dashboard at all?
        Gate::define('access-admin', fn (User $user) => $user->canAccessAdmin());
    }
}
