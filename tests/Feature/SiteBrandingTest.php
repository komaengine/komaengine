<?php

use App\Branding\SiteBranding;
use App\Engine\Settings\Settings;
use App\Engine\Themes\ThemeManager;
use App\Livewire\Admin\Settings as SettingsComponent;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Cache::flush();
    Storage::fake('media');
    app(Settings::class)->setMany([
        'installed_at' => now()->toIso8601String(),
        'site_title' => 'Moonlit Panels',
    ]);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('Administrator');
});

function brandingSettings(User $user)
{
    return Livewire::actingAs($user)->test(SettingsComponent::class, ['tab' => 'branding']);
}

/** @return array<string, string> theme => markup of its own mark */
function themeMarks(): array
{
    return [
        'aozora' => '<span class="glyph">コ</span>',
        'kasai' => '<svg class="brand-flame"',
        'sumi' => '<span class="rail-mark">墨</span>',
        'tankobon' => '<span class="glyph" aria-hidden="true">巻</span>',
    ];
}

function renderThemeHome(string $theme): string
{
    app(Settings::class)->set('active_theme', $theme);
    app(ThemeManager::class)->registerViews();
    // The view finder caches resolved names, so drop it to pick up the new theme.
    app('view')->getFinder()->flush();

    return test()->get(route('home'))->assertOk()->getContent();
}

test('an administrator uploads a PNG logo that is scaled, stored on the media disk, and served with long cache headers', function () {
    brandingSettings($this->admin)
        ->set('logoUpload', UploadedFile::fake()->image('logo.png', 1024, 256))
        ->assertHasNoErrors()
        ->assertSet('logoUpload', null)
        ->assertSet('brandingNotice', 'Logo saved.')
        ->assertSee('data-testid="logoUpload-preview"', false)
        ->assertSee('512 × 128 pixels');

    $logo = app(SiteBranding::class)->logo();

    expect($logo['path'])->toMatch('#\Abranding/logo-[a-f0-9]{32}\.(webp|png)\z#')
        ->and([$logo['width'], $logo['height']])->toBe([512, 128]);
    Storage::disk('media')->assertExists($logo['path']);

    $response = $this->get($logo['url'])->assertOk();

    expect($response->headers->get('Content-Type'))->toBe($logo['mime'])
        ->and($response->headers->get('Cache-Control'))->toContain('public')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=31536000')
        ->and($response->headers->get('Cache-Control'))->toContain('immutable')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

test('a WebP logo is accepted and served as an image', function () {
    brandingSettings($this->admin)
        ->set('logoUpload', UploadedFile::fake()->image('logo.webp', 300, 300))
        ->assertHasNoErrors();

    $logo = app(SiteBranding::class)->logo();

    expect([$logo['width'], $logo['height']])->toBe([256, 256]);
    expect($this->get($logo['url'])->assertOk()->headers->get('Content-Type'))->toStartWith('image/');
});

test('SVG, renamed, animated, and oversized files are rejected and nothing is stored', function (Closure $file, string $message) {
    brandingSettings($this->admin)
        ->set('logoUpload', $file())
        ->assertHasErrors('logoUpload')
        ->assertSee($message);

    expect(app(SiteBranding::class)->logo())->toBeNull()
        ->and(Storage::disk('media')->allFiles())->toBe([]);
})->with([
    'svg' => [fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'Upload a PNG, JPEG, or WebP image.'],
    'renamed' => [fn () => UploadedFile::fake()->createWithContent('logo.png', 'not an image at all'), 'Upload a PNG, JPEG, or WebP image between 16 and 8,000 pixels on each side.'],
    'animated' => [function () {
        $frame = UploadedFile::fake()->image('frame.png', 64, 64);

        return UploadedFile::fake()->createWithContent('logo.png', file_get_contents($frame->getRealPath()).'acTL');
    }, 'Animated images are not supported.'],
    'oversized' => [fn () => UploadedFile::fake()->image('logo.png', 64, 64)->size(5000), 'must not be greater than 4096 kilobytes'],
]);

test('replacing the logo removes the old file and removing it restores the theme mark', function () {
    $settings = brandingSettings($this->admin)
        ->set('logoUpload', UploadedFile::fake()->image('first.png', 400, 100));
    $first = app(SiteBranding::class)->logo();

    $settings->set('logoUpload', UploadedFile::fake()->image('second.png', 300, 150));
    $second = app(SiteBranding::class)->logo();

    expect($second['path'])->not->toBe($first['path']);
    Storage::disk('media')->assertMissing($first['path']);
    Storage::disk('media')->assertExists($second['path']);

    $settings->call('removeLogo')
        ->assertSet('brandingNotice', 'Logo removed. The theme shows its own mark again.');

    expect(app(SiteBranding::class)->logo())->toBeNull();
    Storage::disk('media')->assertMissing($second['path']);
    expect(renderThemeHome('aozora'))->toContain(themeMarks()['aozora'])->not->toContain('brand-logo');
});

test('every built-in theme shows its own mark without a logo and the logo once one is set', function () {
    foreach (themeMarks() as $theme => $mark) {
        expect(renderThemeHome($theme))->toContain($mark)
            ->not->toContain('brand-logo')
            ->not->toContain('rel="icon"');
    }

    app(SiteBranding::class)->storeLogo(UploadedFile::fake()->image('logo.png', 400, 100));
    $url = app(SiteBranding::class)->logo()['url'];

    foreach (themeMarks() as $theme => $mark) {
        expect(renderThemeHome($theme))->toContain('<img class="brand-logo" src="'.$url.'" alt="" width="400" height="100">')
            ->toContain('Moonlit Panels')
            ->not->toContain($mark);
    }

    app(Settings::class)->set('branding_logo_display', 'logo_only');

    foreach (array_keys(themeMarks()) as $theme) {
        expect(renderThemeHome($theme))->toContain('<img class="brand-logo" src="'.$url.'" alt="Moonlit Panels"');
    }
});

test('the logo and icon also appear in the admin bar and on the sign-in page', function () {
    app(SiteBranding::class)->storeLogo(UploadedFile::fake()->image('logo.png', 400, 100));
    app(SiteBranding::class)->storeIcon(UploadedFile::fake()->image('icon.png', 300, 200));
    $logo = app(SiteBranding::class)->logo();
    $icon = app(SiteBranding::class)->icon();

    expect([$icon['width'], $icon['height'], $icon['mime']])->toBe([200, 200, 'image/png']);

    $iconLink = '<link rel="icon" type="image/png" sizes="200x200" href="'.$icon['url'].'">';

    $this->get(route('login'))->assertOk()
        ->assertSee('<img src="'.$logo['url'].'" alt=""', false)
        ->assertSee($iconLink, false);

    $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()
        ->assertSee('data-testid="admin-site-logo"', false)
        ->assertSee($iconLink, false);

    expect(renderThemeHome('sumi'))->toContain($iconLink);
});

test('an editor cannot change branding', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    app(SiteBranding::class)->storeLogo(UploadedFile::fake()->image('logo.png', 400, 100));
    $logo = app(SiteBranding::class)->logo();

    brandingSettings($editor)->set('logoUpload', UploadedFile::fake()->image('other.png', 200, 100))->assertForbidden();
    brandingSettings($editor)->call('removeLogo')->assertForbidden();

    expect(app(SiteBranding::class)->logo()['path'])->toBe($logo['path']);
    $this->actingAs($editor)->get(route('admin.settings', ['tab' => 'branding']))->assertForbidden();
});

test('the branding route serves only branding file names that exist', function () {
    $this->get('/branding/logo-'.str_repeat('a', 32).'.png')->assertNotFound();
    $this->get('/branding/notes.txt')->assertNotFound();
});
