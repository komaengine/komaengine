<?php

namespace App\Engine\Settings;

use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Bridges admin-saved settings onto runtime config, locale, and timezone so the
 * existing `config('koma.*')` and `config('mail.*')` consumers observe operator
 * overrides without every call site learning about the settings table.
 *
 * Runs from {@see \App\Providers\AppServiceProvider} at boot, and again right
 * after the settings screen saves, so an action taken in the same request (the
 * "send a test email" button) uses the values that were just written.
 *
 * Safe before migrations run: reads fall back to the config/file defaults.
 */
class SettingsConfigBridge
{
    public function __construct(
        protected Application $app,
        protected Settings $settings,
    ) {}

    public function apply(): void
    {
        try {
            $saved = $this->settings->all();
        } catch (Throwable) {
            return;
        }

        if ($saved === []) {
            return;
        }

        $secrets = SettingsSchema::secretKeys();

        foreach (SettingsSchema::configMap() as $key => $configKey) {
            if (! array_key_exists($key, $saved) || $saved[$key] === null) {
                continue;
            }

            config()->set($configKey, in_array($key, $secrets, true)
                ? $this->settings->getSecret($key)
                : $saved[$key]);
        }

        // An empty scheme means "no encryption"; Symfony's transport wants null
        // rather than an empty string for that.
        if (config('mail.mailers.smtp.scheme') === '') {
            config()->set('mail.mailers.smtp.scheme', null);
        }

        if (! empty($saved['site_language']) && is_string($saved['site_language'])) {
            $this->app->setLocale($saved['site_language']);
        }

        if (! empty($saved['timezone']) && is_string($saved['timezone']) && in_array($saved['timezone'], timezone_identifiers_list(), true)) {
            config()->set('app.timezone', $saved['timezone']);
            date_default_timezone_set($saved['timezone']);
        }

        // The mailer is resolved once per request and caches its transport, so a
        // fresh apply() after a save has to drop the stale instance.
        $this->app->forgetInstance('mail.manager');
        $this->app->forgetInstance('mailer');
    }
}
