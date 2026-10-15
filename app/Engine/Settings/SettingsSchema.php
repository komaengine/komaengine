<?php

namespace App\Engine\Settings;

/**
 * Single source of truth for admin-editable settings.
 *
 * Each field declares its tab, input type, default, validation rules, and an
 * optional `config` key. Fields with a `config` key are applied onto the
 * runtime config by {@see SettingsConfigBridge} at boot, so the existing
 * `config('koma.*')` and `config('mail.*')` consumers pick up admin overrides
 * for free.
 *
 * `options` is either an associative value=>label array (static) or a string
 * token the Livewire component resolves at render time (dynamic lists such as
 * roles, series, time zones, and locales).
 *
 * `secret` marks a credential. Secrets are encrypted at rest, never sent to the
 * browser, and left untouched when the form posts back the masked placeholder.
 *
 * `depends` hides a field until another field holds one of the listed values.
 */
final class SettingsSchema
{
    /** @return array<string, string> tab key => label */
    public static function tabs(): array
    {
        return [
            'general' => 'General',
            'publishing' => 'Publishing',
            'reading' => 'Reading',
            'discussion' => 'Discussion',
            'mail' => 'Mail',
            'monetization' => 'Monetization',
            'media' => 'Media & Storage',
            'permalinks' => 'Permalinks',
            'privacy' => 'Privacy & Legal',
        ];
    }

    /**
     * Every editable field, keyed by setting key.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fields(): array
    {
        return [
            // ---- General ----
            'site_title' => [
                'tab' => 'general', 'section' => 'Site identity', 'type' => 'text',
                'label' => 'Site title', 'default' => null,
                'rules' => ['required', 'string', 'max:120'],
            ],
            'tagline' => [
                'tab' => 'general', 'section' => 'Site identity', 'type' => 'text',
                'label' => 'Tagline', 'help' => 'A short description shown in metadata and some themes.',
                'default' => '', 'rules' => ['nullable', 'string', 'max:180'],
            ],
            'admin_email' => [
                'tab' => 'general', 'section' => 'Site identity', 'type' => 'email',
                'label' => 'Administration email', 'help' => 'Used for system notices. Never shown publicly.',
                'default' => '', 'rules' => ['nullable', 'email', 'max:180'],
            ],
            'site_language' => [
                'tab' => 'general', 'section' => 'Localization', 'type' => 'select',
                'label' => 'Site language', 'default' => 'en', 'options' => 'locales',
                'rules' => ['required', 'string', 'max:12'],
            ],
            'timezone' => [
                'tab' => 'general', 'section' => 'Localization', 'type' => 'select',
                'label' => 'Timezone', 'help' => 'Chapter scheduling and displayed times use this zone. Stored as UTC.',
                'default' => 'UTC', 'options' => 'timezones', 'rules' => ['required', 'timezone'],
            ],
            'date_format' => [
                'tab' => 'general', 'section' => 'Localization', 'type' => 'text',
                'label' => 'Date format', 'help' => 'PHP date() format, e.g. M j, Y.',
                'default' => 'M j, Y', 'rules' => ['required', 'string', 'max:40'],
            ],
            'time_format' => [
                'tab' => 'general', 'section' => 'Localization', 'type' => 'text',
                'label' => 'Time format', 'help' => 'PHP date() format, e.g. g:i a.',
                'default' => 'g:i a', 'rules' => ['required', 'string', 'max:40'],
            ],
            'date_display' => [
                'tab' => 'general', 'section' => 'Localization', 'type' => 'select',
                'label' => 'Date display',
                'help' => 'How dates read in listings, chapter lists, and comments. Absolute dates use the format above.',
                'default' => 'recent_relative',
                'options' => [
                    'recent_relative' => 'Relative for the past week, then absolute',
                    'relative' => 'Always relative (2 days ago)',
                    'absolute' => 'Always absolute (Mar 4, 2026)',
                ],
                'rules' => ['required', 'in:recent_relative,relative,absolute'],
            ],
            'allow_registration' => [
                'tab' => 'general', 'section' => 'Membership', 'type' => 'toggle',
                'label' => 'Anyone can register',
                'help' => 'When off, the public registration page is disabled. Admins can still create users.',
                'default' => true, 'rules' => ['boolean'],
            ],
            'default_user_role' => [
                'tab' => 'general', 'section' => 'Membership', 'type' => 'select',
                'label' => 'New user default role', 'default' => 'Member', 'options' => 'roles',
                'rules' => ['required', 'string', 'max:60'],
            ],

            // ---- Publishing ----
            'default_series_visibility' => [
                'tab' => 'publishing', 'section' => 'New series defaults', 'type' => 'select',
                'label' => 'Default visibility', 'default' => 'private',
                'options' => ['private' => 'Private', 'public' => 'Public'],
                'rules' => ['required', 'in:private,public'],
            ],
            'default_content_rating' => [
                'tab' => 'publishing', 'section' => 'New series defaults', 'type' => 'select',
                'label' => 'Default content rating', 'default' => 'safe',
                'options' => ['safe' => 'Safe', 'mature' => 'Mature'],
                'rules' => ['required', 'in:safe,mature'],
            ],
            'default_reading_direction' => [
                'tab' => 'publishing', 'section' => 'New series defaults', 'type' => 'select',
                'label' => 'Default reading direction', 'default' => 'rtl',
                'options' => ['rtl' => 'Right → Left', 'ltr' => 'Left → Right', 'vertical' => 'Webtoon'],
                'rules' => ['required', 'in:rtl,ltr,vertical'],
            ],
            'default_chapter_status' => [
                'tab' => 'publishing', 'section' => 'New chapter defaults', 'type' => 'select',
                'label' => 'Default status on upload', 'default' => 'draft',
                'options' => ['draft' => 'Draft', 'published' => 'Published'],
                'rules' => ['required', 'in:draft,published'],
            ],

            // ---- Reading ----
            'front_page' => [
                'tab' => 'reading', 'section' => 'Home page', 'type' => 'select',
                'label' => 'Home page shows', 'default' => 'latest',
                'options' => ['latest' => 'Latest updates', 'featured' => 'Most-viewed series', 'series' => 'A specific series'],
                'rules' => ['required', 'in:latest,featured,series'],
            ],
            'front_page_series' => [
                'tab' => 'reading', 'section' => 'Home page', 'type' => 'select',
                'label' => 'Featured series', 'help' => 'Used when the home page shows a specific series (ideal for single-series themes like Tankōbon).',
                'default' => null, 'options' => 'series', 'rules' => ['nullable', 'integer'],
                'depends' => ['front_page' => 'series'],
            ],
            'series_per_page' => [
                'tab' => 'reading', 'section' => 'Pagination', 'type' => 'number',
                'label' => 'Series per page', 'suffix' => 'in the library', 'default' => 24,
                'rules' => ['required', 'integer', 'min:1', 'max:100'],
                'config' => 'koma.reading.series_per_page',
            ],
            'mature_gate' => [
                'tab' => 'reading', 'section' => 'Content', 'type' => 'toggle',
                'label' => 'Mature content gate',
                'help' => 'Ask readers to confirm their age before showing mature series.',
                'default' => true, 'rules' => ['boolean'],
            ],
            'discourage_search_engines' => [
                'tab' => 'reading', 'section' => 'Visibility', 'type' => 'toggle',
                'label' => 'Discourage search engines from indexing this site',
                'help' => 'Adds a noindex hint and a disallow-all robots.txt. Honored by well-behaved crawlers only.',
                'default' => false, 'rules' => ['boolean'],
            ],

            // ---- Discussion ----
            'comments_enabled' => [
                'tab' => 'discussion', 'section' => 'Comments', 'type' => 'toggle',
                'label' => 'Enable comments', 'default' => true, 'rules' => ['boolean'],
                'config' => 'koma.comments.enabled',
            ],
            'comments_hold_for_moderation' => [
                'tab' => 'discussion', 'section' => 'Moderation', 'type' => 'toggle',
                'label' => 'Hold new comments for review',
                'help' => 'New comments stay pending until a moderator approves them.',
                'default' => false, 'rules' => ['boolean'],
                'config' => 'koma.comments.hold_for_moderation',
            ],
            'comment_max_links' => [
                'tab' => 'discussion', 'section' => 'Moderation', 'type' => 'number',
                'label' => 'Hold if it contains more than', 'suffix' => 'links', 'default' => 5,
                'rules' => ['required', 'integer', 'min:0', 'max:50'],
                'config' => 'koma.comments.max_links',
            ],
            'comment_owner_edit_window' => [
                'tab' => 'discussion', 'section' => 'Author controls', 'type' => 'number',
                'label' => 'Author edit/delete window', 'suffix' => 'minutes', 'default' => 15,
                'rules' => ['required', 'integer', 'min:0', 'max:1440'],
                'config' => 'koma.comments.owner_edit_window_minutes',
            ],
            'comments_per_page' => [
                'tab' => 'discussion', 'section' => 'Display', 'type' => 'number',
                'label' => 'Comments per page', 'default' => 20,
                'rules' => ['required', 'integer', 'min:5', 'max:200'],
                'config' => 'koma.comments.per_page',
            ],
            'comment_rate_limit' => [
                'tab' => 'discussion', 'section' => 'Display', 'type' => 'number',
                'label' => 'Max comments per minute per user', 'default' => 5, 'advanced' => true,
                'rules' => ['required', 'integer', 'min:1', 'max:120'],
                'config' => 'koma.comments.rate_limit_per_minute',
            ],

            // ---- Mail ----
            'mail_from_name' => [
                'tab' => 'mail', 'section' => 'Sender', 'type' => 'text',
                'label' => 'From name', 'help' => 'The name readers see in their inbox.',
                'default' => '', 'rules' => ['nullable', 'string', 'max:120'],
                'config' => 'mail.from.name',
            ],
            'mail_from_address' => [
                'tab' => 'mail', 'section' => 'Sender', 'type' => 'email',
                'label' => 'From address',
                'help' => 'Must be an address your mail host is allowed to send as, or messages will be rejected.',
                'default' => '', 'rules' => ['nullable', 'email', 'max:180'],
                'config' => 'mail.from.address',
            ],
            'mail_mailer' => [
                'tab' => 'mail', 'section' => 'Delivery', 'type' => 'select',
                'label' => 'How mail is sent', 'default' => 'log',
                'options' => [
                    'log' => 'Write to the log (no mail leaves the server)',
                    'smtp' => 'SMTP server',
                    'sendmail' => 'Local sendmail binary',
                ],
                'rules' => ['required', 'in:log,smtp,sendmail'],
                'config' => 'mail.default',
            ],
            'mail_host' => [
                'tab' => 'mail', 'section' => 'Delivery', 'type' => 'text',
                'label' => 'SMTP host', 'default' => '', 'depends' => ['mail_mailer' => 'smtp'],
                'rules' => ['nullable', 'string', 'max:180'],
                'config' => 'mail.mailers.smtp.host',
            ],
            'mail_port' => [
                'tab' => 'mail', 'section' => 'Delivery', 'type' => 'number',
                'label' => 'SMTP port', 'default' => 587, 'depends' => ['mail_mailer' => 'smtp'],
                'rules' => ['required_if:values.mail_mailer,smtp', 'nullable', 'integer', 'min:1', 'max:65535'],
                'config' => 'mail.mailers.smtp.port',
            ],
            'mail_encryption' => [
                'tab' => 'mail', 'section' => 'Delivery', 'type' => 'select',
                'label' => 'Encryption', 'default' => 'smtp', 'depends' => ['mail_mailer' => 'smtp'],
                'help' => 'STARTTLS is the usual choice on port 587; implicit TLS on port 465.',
                'options' => ['smtp' => 'STARTTLS', 'smtps' => 'Implicit TLS (SMTPS)'],
                'rules' => ['required_if:values.mail_mailer,smtp', 'nullable', 'in:smtp,smtps'],
                'config' => 'mail.mailers.smtp.scheme',
            ],
            'mail_username' => [
                'tab' => 'mail', 'section' => 'Delivery', 'type' => 'text',
                'label' => 'SMTP username', 'default' => '', 'depends' => ['mail_mailer' => 'smtp'],
                'rules' => ['nullable', 'string', 'max:180'],
                'config' => 'mail.mailers.smtp.username',
            ],
            'mail_password' => [
                'tab' => 'mail', 'section' => 'Delivery', 'type' => 'password',
                'label' => 'SMTP password', 'default' => '', 'secret' => true,
                'depends' => ['mail_mailer' => 'smtp'],
                'help' => 'Encrypted with the application key before it is stored. Leave the mask untouched to keep the saved password.',
                'rules' => ['nullable', 'string', 'max:400'],
                'config' => 'mail.mailers.smtp.password',
            ],
            'notify_comment_pending' => [
                'tab' => 'mail', 'section' => 'Notify the administration email about', 'type' => 'toggle',
                'label' => 'Comments held for moderation',
                'help' => 'A held comment stays invisible until someone approves it, so this is the one worth watching.',
                'default' => false, 'rules' => ['boolean'],
            ],
            'notify_comment_reported' => [
                'tab' => 'mail', 'section' => 'Notify the administration email about', 'type' => 'toggle',
                'label' => 'Reported comments', 'default' => false, 'rules' => ['boolean'],
            ],
            'notify_new_user' => [
                'tab' => 'mail', 'section' => 'Notify the administration email about', 'type' => 'toggle',
                'label' => 'New registrations',
                'help' => 'Noisy on a site with open registration.',
                'default' => false, 'rules' => ['boolean'],
            ],
            'notify_archive_import' => [
                'tab' => 'mail', 'section' => 'Notify the administration email about', 'type' => 'toggle',
                'label' => 'Finished and failed archive imports',
                'help' => 'Imports run on the queue, so nothing on screen tells you how one ended.',
                'default' => false, 'rules' => ['boolean'],
            ],

            // ---- Monetization ----
            'support_url' => [
                'tab' => 'monetization', 'section' => 'Support', 'type' => 'url',
                'label' => 'Support URL',
                'help' => 'An optional external page where readers can support this site. Nothing is shown publicly until a theme uses it.',
                'default' => '', 'rules' => ['nullable', 'url:http,https', 'max:2048'],
            ],
            'support_label' => [
                'tab' => 'monetization', 'section' => 'Support', 'type' => 'text',
                'label' => 'Support link label',
                'help' => 'Shown as “Support this site” when left blank.',
                'default' => '', 'rules' => ['nullable', 'string', 'max:80'],
            ],
            'publisher_name' => [
                'tab' => 'monetization', 'section' => 'Publisher identity', 'type' => 'text',
                'label' => 'Publisher name',
                'help' => 'Optional attribution for a person, studio, or collective.',
                'default' => '', 'rules' => ['nullable', 'string', 'max:120'],
            ],
            'publisher_url' => [
                'tab' => 'monetization', 'section' => 'Publisher identity', 'type' => 'url',
                'label' => 'Publisher URL',
                'help' => 'Optional external profile or website for the named publisher.',
                'default' => '', 'rules' => ['nullable', 'url:http,https', 'max:2048'],
            ],
            'community_ads_enabled' => [
                'tab' => 'monetization', 'section' => 'Advertising', 'type' => 'toggle',
                'label' => 'Enable Community advertising',
                'help' => 'Allows enabled plugins to render the documented Community header and footer slots. Core does not load ads, tracking, or payment services.',
                'default' => false, 'rules' => ['boolean'],
            ],

            // ---- Media & Storage ----
            'upload_max_kb' => [
                'tab' => 'media', 'section' => 'Uploads', 'type' => 'number',
                'label' => 'Maximum page upload size', 'suffix' => 'KB', 'default' => 20480,
                'help' => 'The server’s PHP/proxy limits still apply — see the storage panel below.',
                'rules' => ['required', 'integer', 'min:256', 'max:512000'],
                'config' => 'koma.uploads.page_max_kb',
            ],
            'cover_width' => [
                'tab' => 'media', 'section' => 'Image sizes', 'type' => 'number',
                'label' => 'Cover width', 'suffix' => 'px', 'default' => 1000,
                'rules' => ['required', 'integer', 'min:200', 'max:4000'],
                'config' => 'koma.images.covers.width',
            ],
            'cover_height' => [
                'tab' => 'media', 'section' => 'Image sizes', 'type' => 'number',
                'label' => 'Cover height', 'suffix' => 'px', 'default' => 1400,
                'rules' => ['required', 'integer', 'min:200', 'max:6000'],
                'config' => 'koma.images.covers.height',
            ],
            'cover_thumbnail_width' => [
                'tab' => 'media', 'section' => 'Image sizes', 'type' => 'number',
                'label' => 'Cover thumbnail width', 'suffix' => 'px', 'default' => 300,
                'rules' => ['required', 'integer', 'min:80', 'max:1200'],
                'config' => 'koma.images.covers.thumbnail_width',
            ],
            'cover_thumbnail_height' => [
                'tab' => 'media', 'section' => 'Image sizes', 'type' => 'number',
                'label' => 'Cover thumbnail height', 'suffix' => 'px', 'default' => 420,
                'rules' => ['required', 'integer', 'min:80', 'max:1600'],
                'config' => 'koma.images.covers.thumbnail_height',
            ],
            'page_max_width' => [
                'tab' => 'media', 'section' => 'Image sizes', 'type' => 'number',
                'label' => 'Max page width', 'suffix' => 'px', 'default' => 2400,
                'rules' => ['required', 'integer', 'min:400', 'max:10000'],
                'config' => 'koma.images.pages.max_width',
            ],
            'page_max_height' => [
                'tab' => 'media', 'section' => 'Image sizes', 'type' => 'number',
                'label' => 'Max page height', 'suffix' => 'px', 'default' => 4000,
                'rules' => ['required', 'integer', 'min:400', 'max:20000'],
                'config' => 'koma.images.pages.max_height',
            ],
            'webp_quality' => [
                'tab' => 'media', 'section' => 'Image quality', 'type' => 'number',
                'label' => 'WebP quality', 'suffix' => '1–100', 'default' => 82,
                'rules' => ['required', 'integer', 'min:1', 'max:100'],
                'config' => 'koma.images.quality.webp',
            ],
            'jpeg_quality' => [
                'tab' => 'media', 'section' => 'Image quality', 'type' => 'number',
                'label' => 'JPEG quality', 'suffix' => '1–100', 'default' => 88,
                'rules' => ['required', 'integer', 'min:1', 'max:100'],
                'config' => 'koma.images.quality.jpeg',
            ],

            // ---- Permalinks ----
            'permalink_series_base' => [
                'tab' => 'permalinks', 'section' => 'URL structure', 'type' => 'slug',
                'label' => 'Series base', 'help' => 'The path prefix for series and reader URLs.',
                'default' => 'series', 'rules' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/'],
            ],
            'permalink_chapter_base' => [
                'tab' => 'permalinks', 'section' => 'URL structure', 'type' => 'slug',
                'label' => 'Chapter base', 'help' => 'The segment before a chapter number in reader URLs.',
                'default' => 'chapter', 'rules' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/'],
            ],

            // ---- Privacy & Legal ----
            'privacy_policy_url' => [
                'tab' => 'privacy', 'section' => 'Legal pages', 'type' => 'url',
                'label' => 'Privacy policy URL', 'default' => '', 'rules' => ['nullable', 'url', 'max:300'],
            ],
            'terms_url' => [
                'tab' => 'privacy', 'section' => 'Legal pages', 'type' => 'url',
                'label' => 'Terms of service URL', 'default' => '', 'rules' => ['nullable', 'url', 'max:300'],
            ],
            'dmca_url' => [
                'tab' => 'privacy', 'section' => 'Legal pages', 'type' => 'url',
                'label' => 'DMCA / takedown URL', 'default' => '', 'rules' => ['nullable', 'url', 'max:300'],
            ],
            'contact_email' => [
                'tab' => 'privacy', 'section' => 'Contact', 'type' => 'email',
                'label' => 'Public contact email', 'help' => 'Shown in the footer for rights and takedown requests.',
                'default' => '', 'rules' => ['nullable', 'email', 'max:180'],
            ],
        ];
    }

    /** @return array<string, mixed> setting key => default */
    public static function defaults(): array
    {
        $defaults = [];
        foreach (self::fields() as $key => $field) {
            $defaults[$key] = $field['default'] ?? null;
        }

        return $defaults;
    }

    /** @return array<string, string> setting key => config key (only mapped fields) */
    public static function configMap(): array
    {
        $map = [];
        foreach (self::fields() as $key => $field) {
            if (! empty($field['config'])) {
                $map[$key] = $field['config'];
            }
        }

        return $map;
    }

    /** @return array<int, string> keys whose stored value is encrypted */
    public static function secretKeys(): array
    {
        return array_keys(array_filter(self::fields(), fn ($f) => ! empty($f['secret'])));
    }

    /**
     * Keys that other fields watch through `depends`, for the form's Alpine state.
     *
     * @return array<int, string>
     */
    public static function dependencyKeys(): array
    {
        $keys = [];
        foreach (self::fields() as $field) {
            foreach (array_keys($field['depends'] ?? []) as $key) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    /** @return array<int, string> keys belonging to a tab */
    public static function keysForTab(string $tab): array
    {
        return array_keys(array_filter(self::fields(), fn ($f) => $f['tab'] === $tab));
    }
}
