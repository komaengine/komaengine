<?php

use App\Engine\Plugins\PluginManager;
use App\Engine\Settings\Settings;
use App\Engine\Themes\ThemeManager;
use App\Livewire\Admin\Extensions;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('Administrator');
});

test('administrator can open separate themes plugins and hooks screens', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.themes'))
        ->assertOk()
        ->assertSee('Aozora theme preview')
        ->assertDontSee('Hello Koma')
        ->assertDontSee('chapter.published');

    $this->actingAs($this->admin)
        ->get(route('admin.plugins.hooks'))
        ->assertOk()
        ->assertSee('Hooks')
        ->assertSee('chapter.published');

    $this->actingAs($this->admin)
        ->get(route('admin.plugins'))
        ->assertOk()
        ->assertSee('Hello Koma')
        ->assertDontSee('Aozora theme preview');
});

test('theme cards render real previews and authenticated preview delivery is authorized', function () {
    $previewUrl = route('admin.themes.preview', ['theme' => 'aozora'], false);

    $this->actingAs($this->admin)
        ->get(route('admin.themes'))
        ->assertOk()
        ->assertSee('Aozora theme preview')
        ->assertSee($previewUrl, false);

    $this->actingAs($this->admin)
        ->get($previewUrl)
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml')
        ->assertHeader('Cache-Control', 'max-age=3600, private')
        ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'; style-src 'unsafe-inline'")
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $editor = User::factory()->create();
    $editor->assignRole('Editor');

    $this->actingAs($editor)->get($previewUrl)->assertForbidden();
    auth()->logout();
    $this->get($previewUrl)->assertRedirect(route('login'));
});

test('staff without extension permissions cannot open theme or plugin management', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');

    $this->actingAs($editor)
        ->get(route('admin.themes'))
        ->assertForbidden();

    $this->actingAs($editor)
        ->get(route('admin.plugins'))
        ->assertForbidden();
});

test('theme and plugin routes enforce their independent capabilities', function () {
    $themeManager = User::factory()->create();
    $themeManager->assignRole('Editor');
    $themeManager->givePermissionTo('manage_themes');

    $this->actingAs($themeManager)
        ->get(route('admin.themes'))
        ->assertOk();
    $this->actingAs($themeManager)
        ->get(route('admin.plugins'))
        ->assertForbidden();

    $pluginManager = User::factory()->create();
    $pluginManager->assignRole('Editor');
    $pluginManager->givePermissionTo('manage_plugins');

    $this->actingAs($pluginManager)
        ->get(route('admin.plugins'))
        ->assertOk();
    $this->actingAs($pluginManager)
        ->get(route('admin.plugins.hooks'))
        ->assertOk();
    $this->actingAs($pluginManager)
        ->get(route('admin.themes'))
        ->assertForbidden();
});

test('legacy extension links redirect to the separated screens', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.extensions'))
        ->assertRedirect(route('admin.themes'));

    $this->actingAs($this->admin)
        ->get(route('admin.extensions.plugins'))
        ->assertRedirect(route('admin.plugins'));

    $this->actingAs($this->admin)
        ->get(route('admin.extensions.hooks'))
        ->assertRedirect(route('admin.plugins.hooks'));
});

test('administrator can activate a theme and toggle a plugin', function () {
    app(Settings::class)->setMany([
        'active_theme' => 'missing-theme',
        'enabled_plugins' => [],
    ]);

    Livewire::actingAs($this->admin)
        ->test(Extensions::class)
        ->call('activateTheme', 'aozora')
        ->call('togglePlugin', 'hello-koma');

    expect(app(Settings::class)->get('active_theme'))->toBe('aozora')
        ->and(app(Settings::class)->get('enabled_plugins'))->toContain('hello-koma');
});

test('incompatible extensions cannot be activated', function () {
    config()->set('koma.version', '0.0.1');

    $themes = new ThemeManager(app(Settings::class));
    $plugins = new PluginManager(app(Settings::class));

    expect($themes->all()['aozora']['compatible'])->toBeFalse()
        ->and($themes->activate('aozora'))->toBeFalse()
        ->and($plugins->all()['hello-koma']['compatible'])->toBeFalse()
        ->and($plugins->enable('hello-koma'))->toBeFalse();
});

test('theme view paths resolve active then Aozora then the application without duplicates', function () {
    config()->set('koma.paths.themes', base_path('tests/Fixtures/themes'));
    app(Settings::class)->set('active_theme', 'guarded');

    $themes = new ThemeManager(app(Settings::class));
    $themes->registerViews();

    expect($themes->viewPaths())->toBe([
        realpath(base_path('tests/Fixtures/themes/guarded/views')),
        realpath(base_path('tests/Fixtures/themes/aozora/views')),
        realpath(resource_path('views/theme')),
    ])->and(trim(View::make('theme::active-marker')->render()))->toBe('Guarded active marker')
        ->and(trim(View::make('theme::contract-marker')->render()))->toBe('Aozora fallback marker')
        ->and(View::getFinder()->find('theme::mature-gate'))->toBe(resource_path('views/theme/mature-gate.blade.php'));
});

test('active Aozora is registered once before the application fallback', function () {
    config()->set('koma.paths.themes', base_path('tests/Fixtures/themes'));
    app(Settings::class)->set('active_theme', 'aozora');

    $themes = new ThemeManager(app(Settings::class));

    expect($themes->viewPaths())->toBe([
        realpath(base_path('tests/Fixtures/themes/aozora/views')),
        realpath(resource_path('views/theme')),
    ])->and(array_unique($themes->viewPaths()))->toHaveCount(2);
});

test('an incompatible configured theme falls back to Aozora without changing the saved setting', function () {
    config()->set('koma.paths.themes', base_path('tests/Fixtures/themes'));
    app(Settings::class)->set('active_theme', 'incompatible');

    $themes = new ThemeManager(app(Settings::class));

    expect($themes->activeSlug())->toBe('aozora')
        ->and($themes->viewPaths())->toBe([
            realpath(base_path('tests/Fixtures/themes/aozora/views')),
            realpath(resource_path('views/theme')),
        ])
        ->and(app(Settings::class)->get('active_theme'))->toBe('incompatible');
});

test('missing and malicious previews degrade without exposing sibling files', function () {
    config()->set('koma.paths.themes', base_path('tests/Fixtures/themes'));
    app(Settings::class)->set('active_theme', 'guarded');

    $themes = new ThemeManager(app(Settings::class));

    expect($themes->previewPath('guarded'))->toBe(realpath(base_path('tests/Fixtures/themes/guarded/preview.svg')))
        ->and($themes->previewUrl('guarded'))->toBe('/admin/themes/guarded/preview')
        ->and($themes->previewPath('missing-preview'))->toBeNull()
        ->and($themes->previewUrl('missing-preview'))->toBeNull()
        ->and($themes->previewPath('malicious-preview'))->toBeNull()
        ->and($themes->previewPath('missing-theme'))->toBeNull();
});

test('extension cards show fallback artwork for a missing preview and keep incompatible themes blocked', function () {
    config()->set('koma.paths.themes', base_path('tests/Fixtures/themes'));
    app()->forgetInstance(ThemeManager::class);

    $this->actingAs($this->admin)
        ->get(route('admin.themes'))
        ->assertOk()
        ->assertSee('Preview unavailable for Missing Preview')
        ->assertSee('Incompatible Preview')
        ->assertSee('Incompatible');

    $themes = app(ThemeManager::class);

    expect($themes->activate('incompatible'))->toBeFalse();
});

test('plugin registration failures are recorded and automatically disabled', function () {
    config()->set('koma.paths.plugins', base_path('tests/Fixtures/plugins'));
    app(Settings::class)->set('enabled_plugins', ['broken']);

    $plugins = new PluginManager(app(Settings::class));
    $plugins->boot();

    expect($plugins->isEnabled('broken'))->toBeFalse()
        ->and($plugins->failures()['broken']['stage'])->toBe('register')
        ->and($plugins->failures()['broken']['message'])->toContain('Intentional registration failure');
});

test('theme assets cannot escape into a sibling directory with the same prefix', function () {
    config()->set('koma.paths.themes', base_path('tests/Fixtures/themes'));
    app(Settings::class)->set('active_theme', 'guarded');

    $themes = new ThemeManager(app(Settings::class));

    expect($themes->assetPath('theme.css'))->not->toBeNull()
        ->and($themes->assetPath('../assets-private/secret.txt'))->toBeNull();
});

test('theme asset URLs keep immutable responses isolated by theme', function () {
    $themes = new ThemeManager(app(Settings::class));

    expect($themes->activate('kasai'))->toBeTrue();
    $kasaiUrl = $themes->asset('theme.css');

    expect($themes->activate('sumi'))->toBeTrue();
    $sumiUrl = $themes->asset('theme.css');

    expect($kasaiUrl)->not->toBe($sumiUrl)
        ->and($kasaiUrl)->toContain('theme=kasai')
        ->and($sumiUrl)->toContain('theme=sumi');

    $kasaiResponse = $this->get($kasaiUrl)
        ->assertOk()
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');

    $sumiResponse = $this->get($sumiUrl)
        ->assertOk()
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');

    $legacyResponse = $this->get('/theme-assets/theme.css')
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, public');

    expect($kasaiResponse->baseResponse->getFile()->getRealPath())
        ->toBe(realpath(base_path('themes/kasai/assets/theme.css')))
        ->and($sumiResponse->baseResponse->getFile()->getRealPath())
        ->toBe(realpath(base_path('themes/sumi/assets/theme.css')))
        ->and($legacyResponse->baseResponse->getFile()->getRealPath())
        ->toBe(realpath(base_path('themes/sumi/assets/theme.css')));

    $this->get('/theme-assets/theme.css?theme=missing-theme')->assertNotFound();
    $this->get('/theme-assets/../assets-private/secret.txt?theme=sumi')->assertNotFound();
});
