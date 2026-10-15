<?php

use App\Engine\Settings\Settings;
use App\Engine\Themes\ThemeManager;
use App\Livewire\Admin\Series\Edit as SeriesEdit;
use App\Livewire\Admin\Settings as SettingsComponent;
use App\Livewire\Auth\Register as RegisterComponent;
use App\Models\Chapter;
use App\Models\Series;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AdminNotice;
use App\Support\Facades\Hook;
use App\Support\Koma;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Cache::flush();
    app(Settings::class)->set('installed_at', now()->toIso8601String());
});

test('settings strictly round trip json types including an explicit null', function () {
    $settings = app(Settings::class);

    $settings->setMany([
        'boolean' => true,
        'integer' => 42,
        'string' => 'Koma',
        'array' => ['one', 'two'],
        'null' => null,
    ]);

    expect($settings->get('boolean'))->toBeTrue()
        ->and($settings->get('integer'))->toBe(42)
        ->and($settings->get('string'))->toBe('Koma')
        ->and($settings->get('array'))->toBe(['one', 'two'])
        ->and($settings->has('null'))->toBeTrue()
        ->and($settings->get('null', 'fallback'))->toBeNull()
        ->and($settings->get('missing', 'fallback'))->toBe('fallback');
});

test('invalid json input and corrupt stored json fail visibly', function () {
    $settings = app(Settings::class);

    expect(fn () => $settings->set('invalid', "\xB1\x31"))
        ->toThrow(JsonException::class);

    Setting::query()->create(['key' => 'corrupt', 'value' => '{']);
    Cache::forget('koma.settings');

    expect(fn () => $settings->get('corrupt'))
        ->toThrow(JsonException::class);
});

test('writes fail when the settings table is unavailable', function () {
    Schema::drop('settings');

    expect(fn () => app(Settings::class)->set('site_title', 'Unavailable'))
        ->toThrow(RuntimeException::class, 'settings table is unavailable');
});

test('a failed batch leaves prior settings unchanged', function () {
    $settings = app(Settings::class);
    $settings->setMany(['first' => 'old', 'second' => 'old']);

    DB::statement(<<<'SQL'
        CREATE TRIGGER fail_settings_batch
        BEFORE INSERT ON settings
        WHEN NEW.key = 'explode'
        BEGIN
            SELECT RAISE(ABORT, 'forced settings failure');
        END
        SQL);

    expect(fn () => $settings->setMany([
        'first' => 'new',
        'explode' => 'nope',
        'second' => 'new',
    ]))->toThrow(QueryException::class);

    Cache::forget('koma.settings');

    expect($settings->get('first'))->toBe('old')
        ->and($settings->get('second'))->toBe('old')
        ->and(Setting::query()->where('key', 'explode')->exists())->toBeFalse();
});

test('separate settings instances observe committed cache invalidation', function () {
    $reader = new Settings;
    $writer = new Settings;

    $writer->set('site_title', 'Before');
    expect($reader->get('site_title'))->toBe('Before');

    $writer->set('site_title', 'After');

    expect($reader->get('site_title'))->toBe('After');
});

test('only administrators can invoke the settings mutation', function () {
    $member = User::factory()->create();
    $member->assignRole('Member');

    Livewire::actingAs($member)
        ->test(SettingsComponent::class)
        ->set('values.site_title', 'Forged title')
        ->call('save')
        ->assertForbidden();

    expect(Setting::query()->where('key', 'site_title')->exists())->toBeFalse();
});

test('administrator saves settings per tab', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    Livewire::actingAs($admin)
        ->test(SettingsComponent::class, ['tab' => 'general'])
        ->set('values.site_title', 'Koma Library')
        ->set('values.tagline', 'Independent comics')
        ->set('values.timezone', 'America/New_York')
        ->set('values.admin_email', 'ops@koma.test')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($admin)
        ->test(SettingsComponent::class, ['tab' => 'publishing'])
        ->set('values.default_reading_direction', 'ltr')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($admin)
        ->test(SettingsComponent::class, ['tab' => 'reading'])
        ->set('values.mature_gate', false)
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(Settings::class);

    expect($settings->get('site_title'))->toBe('Koma Library')
        ->and($settings->get('tagline'))->toBe('Independent comics')
        ->and($settings->get('timezone'))->toBe('America/New_York')
        ->and($settings->get('admin_email'))->toBe('ops@koma.test')
        ->and($settings->get('default_reading_direction'))->toBe('ltr')
        ->and($settings->get('mature_gate'))->toBeFalse();
});

test('settings validation rejects an invalid value on its tab', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    Livewire::actingAs($admin)
        ->test(SettingsComponent::class, ['tab' => 'general'])
        ->set('values.site_title', '')
        ->set('values.admin_email', 'not-an-email')
        ->call('save')
        ->assertHasErrors(['values.site_title', 'values.admin_email']);

    expect(app(Settings::class)->has('site_title'))->toBeFalse();
});

test('administrators save Community monetization settings', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    Livewire::actingAs($admin)
        ->test(SettingsComponent::class, ['tab' => 'monetization'])
        ->set('values.support_url', 'https://support.koma.test/creator')
        ->set('values.support_label', 'Back Koma')
        ->set('values.publisher_name', 'Koma Collective')
        ->set('values.publisher_url', 'https://publisher.koma.test')
        ->set('values.community_ads_enabled', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(app(Settings::class)->get('support_url'))->toBe('https://support.koma.test/creator')
        ->and(app(Settings::class)->get('support_label'))->toBe('Back Koma')
        ->and(app(Settings::class)->get('publisher_name'))->toBe('Koma Collective')
        ->and(app(Settings::class)->get('publisher_url'))->toBe('https://publisher.koma.test')
        ->and(app(Settings::class)->get('community_ads_enabled'))->toBeTrue();
});

test('Community monetization settings only accept HTTP(S) URLs', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    Livewire::actingAs($admin)
        ->test(SettingsComponent::class, ['tab' => 'monetization'])
        ->set('values.support_url', 'ftp://support.koma.test/creator')
        ->set('values.publisher_url', '/publisher')
        ->call('save')
        ->assertHasErrors(['values.support_url', 'values.publisher_url']);

    expect(app(Settings::class)->has('support_url'))->toBeFalse()
        ->and(app(Settings::class)->has('publisher_url'))->toBeFalse();
});

test('each settings tab renders its fields', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    $expected = [
        'general' => 'Administration email',
        'publishing' => 'Default status on upload',
        'reading' => 'Home page shows',
        'discussion' => 'Enable comments',
        'mail' => 'SMTP password',
        'monetization' => 'Support URL',
        'media' => 'WebP quality',
        'permalinks' => 'Series base',
        'privacy' => 'DMCA / takedown URL',
    ];

    foreach ($expected as $tab => $label) {
        $this->actingAs($admin)->get(route('admin.settings', ['tab' => $tab]))
            ->assertOk()
            ->assertSee($label);
    }

    // The media tab also renders the storage panel.
    $this->actingAs($admin)->get(route('admin.settings', ['tab' => 'media']))
        ->assertSee('Media stored')
        ->assertSee('Remote storage (S3 / R2 / CDN)');
});

test('mail settings encrypt credentials, preserve their mask, and bridge into runtime config', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    Livewire::actingAs($admin)
        ->test(SettingsComponent::class, ['tab' => 'mail'])
        ->set('values.mail_from_name', 'Koma Mailer')
        ->set('values.mail_from_address', 'mailer@koma.test')
        ->set('values.mail_mailer', 'smtp')
        ->set('values.mail_host', 'smtp.koma.test')
        ->set('values.mail_port', 465)
        ->set('values.mail_encryption', 'smtps')
        ->set('values.mail_username', 'mailer')
        ->set('values.mail_password', 'mail-secret')
        ->set('values.notify_new_user', true)
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(Settings::class);

    expect($settings->get('mail_password'))->not->toBe('mail-secret')
        ->and($settings->getSecret('mail_password'))->toBe('mail-secret')
        ->and(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.koma.test')
        ->and(config('mail.mailers.smtp.port'))->toBe(465)
        ->and(config('mail.mailers.smtp.scheme'))->toBe('smtps')
        ->and(config('mail.mailers.smtp.password'))->toBe('mail-secret');

    Livewire::actingAs($admin)
        ->test(SettingsComponent::class, ['tab' => 'mail'])
        ->assertSet('values.mail_password', SettingsComponent::SECRET_PLACEHOLDER)
        ->call('save')
        ->assertHasNoErrors();

    expect($settings->getSecret('mail_password'))->toBe('mail-secret');
});

test('registration notifications are opt-in and route to the administration email', function () {
    Notification::fake();

    $user = User::factory()->create(['name' => 'New Reader']);

    app(Settings::class)->set('admin_email', 'ops@koma.test');
    Hook::doAction('user.registered', $user);
    Notification::assertNothingSent();

    app(Settings::class)->set('notify_new_user', true);
    Hook::doAction('user.registered', $user);

    Notification::assertSentOnDemand(AdminNotice::class, function (AdminNotice $notice, array $channels, object $notifiable): bool {
        return $notice->subject === 'New registration: New Reader'
            && $channels === ['mail']
            && $notifiable->routeNotificationFor('mail') === 'ops@koma.test';
    });
});

test('saved settings bridge onto runtime config, locale, and timezone', function () {
    app(Settings::class)->setMany([
        'comment_max_links' => 9,
        'comments_per_page' => 33,
        'series_per_page' => 12,
        'site_language' => 'ja',
        'timezone' => 'America/New_York',
    ]);

    app(App\Engine\Settings\SettingsConfigBridge::class)->apply();

    expect(config('koma.comments.max_links'))->toBe(9)
        ->and(config('koma.comments.per_page'))->toBe(33)
        ->and(config('koma.reading.series_per_page'))->toBe(12)
        ->and(app()->getLocale())->toBe('ja')
        ->and(config('app.timezone'))->toBe('America/New_York');
});

test('new series use the saved reading default', function (string $direction) {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    app(Settings::class)->set('default_reading_direction', $direction);

    Livewire::actingAs($editor)
        ->test(SeriesEdit::class)
        ->assertSet('reading_direction', $direction);
})->with(['rtl', 'ltr', 'vertical']);

test('existing series keep their own reading direction', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    app(Settings::class)->set('default_reading_direction', 'ltr');

    $series = Series::query()->create([
        'title' => 'Existing direction',
        'slug' => 'existing-direction',
        'status' => 'ongoing',
        'reading_direction' => 'vertical',
        'content_rating' => 'safe',
    ]);

    Livewire::actingAs($editor)
        ->test(SeriesEdit::class, ['series' => $series])
        ->assertSet('reading_direction', 'vertical');
});

test('an unsupported saved direction falls back to rtl for new series', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    app(Settings::class)->set('default_reading_direction', 'sideways');

    Livewire::actingAs($editor)
        ->test(SeriesEdit::class)
        ->assertSet('reading_direction', 'rtl');
});

function settingsPublicSeries(string $title, string $slug, int $views): Series
{
    $series = Series::create([
        'title' => $title, 'slug' => $slug, 'status' => 'ongoing',
        'reading_direction' => 'rtl', 'content_rating' => 'safe', 'is_public' => true, 'views' => $views,
    ]);
    Chapter::create([
        'series_id' => $series->id, 'number' => 1,
        'status' => Chapter::STATUS_PUBLISHED, 'published_at' => now()->subHour(),
    ]);

    return $series;
}

test('new series adopt the publishing defaults', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');

    app(Settings::class)->setMany([
        'default_series_visibility' => 'public',
        'default_content_rating' => 'mature',
        'default_reading_direction' => 'ltr',
    ]);

    Livewire::actingAs($editor)
        ->test(SeriesEdit::class)
        ->assertSet('is_public', true)
        ->assertSet('content_rating', 'mature')
        ->assertSet('reading_direction', 'ltr');
});

test('legal links and date formatting reflect settings', function () {
    app(Settings::class)->setMany([
        'privacy_policy_url' => 'https://example.test/privacy',
        'contact_email' => 'legal@koma.test',
        'date_format' => 'Y-m-d',
        'time_format' => 'H:i',
    ]);

    $links = App\Support\Koma::legalLinks();
    expect(collect($links)->pluck('label')->all())->toBe(['Privacy Policy', 'Contact'])
        ->and($links[0]['url'])->toBe('https://example.test/privacy')
        ->and($links[1]['url'])->toBe('mailto:legal@koma.test');

    $moment = Carbon\CarbonImmutable::create(2026, 3, 9, 14, 30, 0, 'UTC');
    expect(App\Support\Koma::formatDate($moment))->toBe('2026-03-09')
        ->and(App\Support\Koma::formatDateTime($moment))->toContain('2026-03-09')
        ->and(App\Support\Koma::formatDateTime($moment))->toContain('14:30');
});

test('Community monetization helpers normalize safe public values', function () {
    expect(Koma::supportLink())->toBeNull()
        ->and(Koma::publisherIdentity())->toBeNull();

    app(Settings::class)->setMany([
        'support_url' => '  https://support.koma.test/creator  ',
        'support_label' => '   ',
        'publisher_name' => '  Koma Collective  ',
        'publisher_url' => '  https://publisher.koma.test  ',
    ]);

    expect(Koma::supportLink())->toBe([
        'label' => 'Support this site',
        'url' => 'https://support.koma.test/creator',
    ])->and(Koma::publisherIdentity())->toBe([
        'name' => 'Koma Collective',
        'url' => 'https://publisher.koma.test',
    ]);

    app(Settings::class)->setMany([
        'support_url' => 'mailto:creator@koma.test',
        'publisher_url' => 'ftp://publisher.koma.test',
        'publisher_name' => 'Koma Collective',
    ]);

    expect(Koma::supportLink())->toBeNull()
        ->and(Koma::publisherIdentity())->toBe([
            'name' => 'Koma Collective',
            'url' => null,
        ]);
});

test('Community footers render configured support and publisher identity once', function () {
    $settings = app(Settings::class);
    $themes = app(ThemeManager::class);

    foreach (['aozora', 'kasai', 'sumi', 'tankobon'] as $theme) {
        $settings->setMany([
            'active_theme' => $theme,
            'support_url' => 'https://support.koma.test/creator',
            'support_label' => 'Support Koma QA',
            'publisher_name' => 'Koma Footer Publisher',
            'publisher_url' => 'https://publisher.koma.test',
        ]);
        $themes->registerViews();

        $content = $this->get(route('home'))->assertOk()->getContent();

        expect(substr_count($content, 'Support Koma QA'))->toBe(1)
            ->and(substr_count($content, 'Koma Footer Publisher'))->toBe(1)
            ->and(substr_count($content, 'https://support.koma.test/creator'))->toBe(1)
            ->and(substr_count($content, 'https://publisher.koma.test'))->toBe(1);

        $settings->setMany([
            'support_url' => '',
            'support_label' => 'Invisible support',
            'publisher_name' => 'Koma Publisher Without URL',
            'publisher_url' => '',
        ]);
        $themes->registerViews();

        $plainPublisher = $this->get(route('home'))->assertOk()->getContent();

        expect($plainPublisher)->not->toContain('Invisible support')
            ->and(substr_count($plainPublisher, 'Koma Publisher Without URL'))->toBe(1)
            ->and((bool) preg_match('/<a[^>]*>Koma Publisher Without URL<\\/a>/', $plainPublisher))->toBeFalse();

        $settings->setMany([
            'support_url' => 'ftp://support.koma.test/creator',
            'support_label' => 'Invalid support',
            'publisher_name' => '',
            'publisher_url' => 'ftp://publisher.koma.test',
        ]);
        $themes->registerViews();

        $invalid = $this->get(route('home'))->assertOk()->getContent();

        expect($invalid)->not->toContain('Invalid support')
            ->and($invalid)->not->toContain('ftp://publisher.koma.test');
    }
});

test('kasai and sumi own comment partials honor the comments-disabled setting', function () {
    foreach (['kasai', 'sumi'] as $theme) {
        app(Settings::class)->setMany(['active_theme' => $theme, 'comments_enabled' => false]);
        app(App\Engine\Themes\ThemeManager::class)->registerViews();
        app(App\Engine\Settings\SettingsConfigBridge::class)->apply();

        $series = settingsPublicSeries("Closed {$theme}", "closed-{$theme}", 1);

        $this->get(route('series.show', $series))
            ->assertOk()
            ->assertSee('Comments are closed')
            ->assertDontSee('class="comment-composer"', false);
    }
});

test('the login page hides the register link when registration is disabled', function () {
    User::factory()->create()->assignRole('Administrator');

    $this->get(route('login'))->assertOk()->assertSee(route('register'), false);

    app(Settings::class)->set('allow_registration', false);

    $this->get(route('login'))->assertOk()->assertDontSee(route('register'), false);
});

test('registration can be disabled except for the first account', function () {
    app(Settings::class)->set('allow_registration', false);
    $this->get(route('register'))->assertOk();

    User::factory()->create()->assignRole('Administrator');
    $this->get(route('register'))->assertNotFound();

    app(Settings::class)->setMany(['allow_registration' => true, 'default_user_role' => 'Uploader']);
    $this->get(route('register'))->assertOk();

    Livewire::test(RegisterComponent::class)
        ->set('name', 'New Reader')
        ->set('email', 'reader@koma.test')
        ->set('password', 'password1234')
        ->set('password_confirmation', 'password1234')
        ->call('register');

    expect(User::where('email', 'reader@koma.test')->first()?->hasRole('Uploader'))->toBeTrue();
});

test('the reading front page can pin a specific featured series', function () {
    $pinned = settingsPublicSeries('Pinned Tale', 'pinned-tale', 5);
    $popular = settingsPublicSeries('Popular Tale', 'popular-tale', 500);

    $this->get(route('home'))->assertViewHas('featured', fn ($f) => $f?->id === $popular->id);

    app(Settings::class)->setMany(['front_page' => 'series', 'front_page_series' => $pinned->id]);
    $this->get(route('home'))->assertViewHas('featured', fn ($f) => $f?->id === $pinned->id);
});

test('the library respects the series-per-page setting', function () {
    settingsPublicSeries('Alpha', 'alpha', 1);
    settingsPublicSeries('Bravo', 'bravo', 2);

    app(Settings::class)->set('series_per_page', 1);
    app(App\Engine\Settings\SettingsConfigBridge::class)->apply();

    $this->get(route('browse'))->assertViewHas('series', function ($paginator) {
        return $paginator->perPage() === 1 && $paginator->total() === 2 && $paginator->count() === 1;
    });
});

test('permalink bases persist and reject reserved words', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    Livewire::actingAs($admin)
        ->test(SettingsComponent::class, ['tab' => 'permalinks'])
        ->set('values.permalink_series_base', 'admin')
        ->call('save')
        ->assertHasErrors('values.permalink_series_base');

    expect(app(Settings::class)->has('permalink_series_base'))->toBeFalse();

    Livewire::actingAs($admin)
        ->test(SettingsComponent::class, ['tab' => 'permalinks'])
        ->set('values.permalink_series_base', 'manga')
        ->set('values.permalink_chapter_base', 'ch')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(Settings::class)->get('permalink_series_base'))->toBe('manga')
        ->and(app(Settings::class)->get('permalink_chapter_base'))->toBe('ch');

    // The default structure resolves to the configured one via named routes.
    $series = settingsPublicSeries('Perma Tale', 'perma-tale', 1);
    expect(route('series.show', $series, false))->toBe('/series/perma-tale');
});

test('discourage search engines toggles robots.txt and the noindex header', function () {
    $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /admin');
    expect($this->get(route('home'))->headers->get('X-Robots-Tag'))->toBeNull();

    app(Settings::class)->set('discourage_search_engines', true);

    expect($this->get('/robots.txt')->getContent())->toContain("Disallow: /\n");
    $this->get(route('home'))->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

test('discussion settings hold new comments for moderation', function () {
    $series = settingsPublicSeries('Held Series', 'held-series', 1);
    $member = User::factory()->create();
    $member->assignRole('Member');

    app(Settings::class)->set('comments_hold_for_moderation', true);
    app(App\Engine\Settings\SettingsConfigBridge::class)->apply();

    $comment = app(App\Community\CommentService::class)->create($member, $series, 'A held comment.');

    expect($comment->status)->toBe(App\Models\Comment::STATUS_PENDING);
});

test('disabling comments blocks creation and hides the composer', function () {
    $series = settingsPublicSeries('Closed Series', 'closed-series', 1);
    $member = User::factory()->create();
    $member->assignRole('Member');

    app(Settings::class)->set('comments_enabled', false);
    app(App\Engine\Settings\SettingsConfigBridge::class)->apply();

    expect(fn () => app(App\Community\CommentService::class)->create($member, $series, 'Nope.'))
        ->toThrow(App\Community\CommentsDisabledException::class);

    $this->actingAs($member)->get(route('series.show', $series))
        ->assertOk()
        ->assertSee('Comments are closed');
});
