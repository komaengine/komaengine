<?php

namespace App\Livewire\Admin;

use App\Engine\Settings\Settings as SettingsStore;
use App\Engine\Settings\SettingsConfigBridge;
use App\Engine\Settings\SettingsSchema;
use App\Models\Series;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Spatie\Permission\Models\Role;
use Throwable;

#[Layout('components.layouts.admin')]
class Settings extends Component
{
    /**
     * Stand-in for a stored credential.
     *
     * A saved secret is never sent to the browser. The form shows this instead,
     * and posting it back unchanged means "keep what is stored".
     */
    public const SECRET_PLACEHOLDER = '••••••••';

    public string $tab = 'general';

    /** @var array<string, mixed> */
    public array $values = [];

    public function mount(SettingsStore $settings, ?string $tab = null): void
    {
        $tabs = SettingsSchema::tabs();
        $this->tab = ($tab !== null && array_key_exists($tab, $tabs)) ? $tab : 'general';

        $defaults = SettingsSchema::defaults();
        $defaults['site_title'] = $defaults['site_title'] ?? config('app.name');

        $secrets = SettingsSchema::secretKeys();

        foreach (SettingsSchema::fields() as $key => $field) {
            if (in_array($key, $secrets, true)) {
                $this->values[$key] = $settings->hasSecret($key) ? self::SECRET_PLACEHOLDER : '';

                continue;
            }

            // A config-backed field that has never been saved shows whatever
            // the deployment's config/env already has, so opening the form and
            // saving it cannot silently overwrite a working .env value with a
            // schema default.
            $fallback = $defaults[$key] ?? null;

            if (isset($field['config']) && ! $settings->has($key)) {
                $fallback = config($field['config'], $fallback) ?? $fallback;
            }

            $this->values[$key] = $settings->get($key, $fallback);
        }
    }

    public function save(SettingsStore $settings): void
    {
        Gate::authorize('manage_settings');

        $this->validate($this->rules(), [], $this->validationAttributes());

        if ($this->tab === 'permalinks' && ! $this->validatePermalinkBases()) {
            return;
        }

        $store = [];
        $secrets = SettingsSchema::secretKeys();
        $secretChanges = [];

        foreach (SettingsSchema::keysForTab($this->tab) as $key) {
            $value = $this->values[$key] ?? null;

            if (in_array($key, $secrets, true)) {
                // An untouched mask means the operator did not change the
                // credential, so leave the encrypted row exactly as it is.
                if ($value !== self::SECRET_PLACEHOLDER) {
                    $secretChanges[$key] = is_string($value) ? trim($value) : null;
                    $this->values[$key] = filled($secretChanges[$key]) ? self::SECRET_PLACEHOLDER : '';
                }

                continue;
            }

            $store[$key] = $this->castValue($key, $value);
        }

        $settings->setManyWithSecrets($store, $secretChanges);

        // Config was bridged at boot, before this save. Re-apply so anything
        // done later in this request (the test email) uses the new values.
        app(SettingsConfigBridge::class)->apply();

        // Permalink bases are read at route registration. If the route table is
        // cached, drop the stale cache so the new bases take effect.
        if ($this->tab === 'permalinks' && app()->routesAreCached()) {
            Artisan::call('route:clear');
        }

        session()->flash('status', SettingsSchema::tabs()[$this->tab].' settings saved.');
    }

    /**
     * Send a throwaway message to the administration email using the saved
     * delivery settings, so an operator can prove mail works before switching
     * any notice on.
     */
    public function sendTestEmail(SettingsStore $settings): void
    {
        Gate::authorize('manage_settings');

        $recipient = trim((string) $settings->get('admin_email', ''));

        if ($recipient === '') {
            $this->addError('values.mail_from_address', 'Set an administration email under General first — that is where test mail goes.');

            return;
        }

        app(SettingsConfigBridge::class)->apply();

        try {
            Mail::raw(
                "This is a test message from {$this->siteTitle()}.\n\n"
                .'If it reached you, the mail settings work and the notices you switch on will be delivered.',
                fn ($message) => $message->to($recipient)->subject('['.$this->siteTitle().'] Test message'),
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('values.mail_host', 'The test message could not be sent: '.$exception->getMessage());

            return;
        }

        $mailer = (string) config('mail.default');
        session()->flash('status', $mailer === 'log'
            ? 'Mail is set to write to the log, so the test message was written to storage/logs rather than sent to '.$recipient.'.'
            : 'A test message was sent to '.$recipient.'.');
    }

    protected function siteTitle(): string
    {
        return (string) ($this->values['site_title'] ?? config('app.name'));
    }

    /** Reject bases that would shadow existing top-level or nested routes. */
    protected function validatePermalinkBases(): bool
    {
        $reservedTop = ['admin', 'login', 'register', 'logout', 'install', 'browse',
            'mature-content', 'theme-assets', 'reader', 'robots.txt', 'up', 'storage', 'api'];

        $ok = true;
        if (in_array($this->values['permalink_series_base'] ?? '', $reservedTop, true)) {
            $this->addError('values.permalink_series_base', 'That base is reserved by another route. Choose a different word.');
            $ok = false;
        }
        if (in_array($this->values['permalink_chapter_base'] ?? '', ['comments'], true)) {
            $this->addError('values.permalink_chapter_base', 'That base is reserved. Choose a different word.');
            $ok = false;
        }

        return $ok;
    }

    /** @return array<string, array<int, mixed>> */
    protected function rules(): array
    {
        $rules = [];
        foreach (SettingsSchema::keysForTab($this->tab) as $key) {
            $rules['values.'.$key] = SettingsSchema::fields()[$key]['rules'] ?? ['nullable'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        $attributes = [];
        foreach (SettingsSchema::fields() as $key => $field) {
            $attributes['values.'.$key] = strtolower($field['label']);
        }

        return $attributes;
    }

    protected function castValue(string $key, mixed $value): mixed
    {
        $type = SettingsSchema::fields()[$key]['type'] ?? 'text';

        return match ($type) {
            'toggle' => (bool) $value,
            'number' => $value === '' || $value === null ? null : (int) $value,
            default => $key === 'front_page_series'
                ? ($value === '' || $value === null ? null : (int) $value)
                : (is_string($value) ? trim($value) : $value),
        };
    }

    /**
     * Resolve a field's dynamic option list.
     *
     * @return array<string|int, string>
     */
    public function optionsFor(array $field): array
    {
        $options = $field['options'] ?? [];

        if (is_array($options)) {
            return $options;
        }

        return match ($options) {
            'roles' => Role::query()->orderBy('name')->pluck('name', 'name')->all(),
            'series' => ['' => '— Select a series —'] + Series::query()->orderBy('title')->pluck('title', 'id')->all(),
            'locales' => [
                'en' => 'English', 'ja' => '日本語 (Japanese)', 'es' => 'Español', 'fr' => 'Français',
                'de' => 'Deutsch', 'pt-BR' => 'Português (Brasil)', 'it' => 'Italiano', 'ko' => '한국어',
                'zh-CN' => '简体中文', 'ru' => 'Русский', 'id' => 'Bahasa Indonesia',
            ],
            'timezones' => $this->groupedTimezones(),
            default => [],
        };
    }

    /**
     * Time zones grouped by region for <optgroup> rendering.
     *
     * @return array<string, array<string, string>>
     */
    protected function groupedTimezones(): array
    {
        $groups = [];

        foreach (timezone_identifiers_list() as $tz) {
            $region = str_contains($tz, '/') ? explode('/', $tz, 2)[0] : 'Other';
            $groups[$region][$tz] = $tz;
        }

        return $groups;
    }

    public function render()
    {
        $fields = array_filter(
            SettingsSchema::fields(),
            fn ($field) => $field['tab'] === $this->tab,
        );

        // Group by section, preserving declaration order.
        $sections = [];
        foreach ($fields as $key => $field) {
            $sections[$field['section'] ?? ''][$key] = $field;
        }

        return view('livewire.admin.settings', [
            'tabs' => SettingsSchema::tabs(),
            'sections' => $sections,
            'dependencyKeys' => SettingsSchema::dependencyKeys(),
            'storage' => $this->tab === 'media' ? $this->storageInfo() : null,
        ]);
    }

    /** @return array<string, mixed> */
    protected function storageInfo(): array
    {
        $used = (int) \App\Models\Page::query()->sum('file_size')
            + (int) Series::query()->sum('cover_file_size')
            + (int) Series::query()->sum('cover_thumbnail_file_size');

        return [
            'disk' => (string) config('filesystems.default', 'local'),
            'media_disk' => (string) config('koma.media_disk', config('filesystems.default', 'local')),
            'used_bytes' => $used,
            'php_upload' => ini_get('upload_max_filesize'),
            'php_post' => ini_get('post_max_size'),
            'php_memory' => ini_get('memory_limit'),
        ];
    }
}
