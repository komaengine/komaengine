<?php

use App\Engine\Settings\Settings;
use App\Engine\Themes\ThemeManager;
use App\Livewire\Admin\Extensions;
use App\Models\Chapter;
use App\Models\Comment;
use App\Models\Genre;
use App\Models\Page;
use App\Models\Series;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    app(Settings::class)->setMany([
        'active_theme' => 'kasai',
        'installed_at' => now()->toIso8601String(),
    ]);
    app(ThemeManager::class)->registerViews();
});

function kasaiSeries(array $attributes = []): Series
{
    return Series::create(array_merge([
        'title' => 'Signal Fire Workshop',
        'slug' => 'signal-fire-workshop',
        'author' => 'Koma Test Author',
        'artist' => 'Koma Test Artist',
        'year' => 2026,
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
        'is_public' => true,
        'synopsis' => 'A production-safe test synopsis for the Kasai public theme.',
        'cover_hue' => 24,
        'views' => 120,
    ], $attributes));
}

function kasaiChapter(Series $series, array $attributes = []): Chapter
{
    return Chapter::create(array_merge([
        'series_id' => $series->id,
        'number' => 1,
        'title' => 'First ignition',
        'status' => Chapter::STATUS_PUBLISHED,
        'published_at' => now()->subHour(),
    ], $attributes));
}

test('Kasai is discoverable compatible activatable and has an authorized preview', function () {
    $themes = app(ThemeManager::class);
    $manifest = $themes->all()['kasai'];

    expect($manifest['name'])->toBe('Kasai')
        ->and($manifest['version'])->toBe('1.0.0')
        ->and($manifest['compatible'])->toBeTrue()
        ->and($manifest['preview_url'])->toBe(route('admin.themes.preview', ['theme' => 'kasai'], false))
        ->and($themes->previewPath('kasai'))->toBe(realpath(base_path('themes/kasai/preview.svg')));

    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    Livewire::actingAs($admin)
        ->test(Extensions::class)
        ->call('activateTheme', 'kasai');

    expect(app(Settings::class)->get('active_theme'))->toBe('kasai');

    $this->actingAs($admin)
        ->get(route('admin.themes'))
        ->assertOk()
        ->assertSee('Kasai theme preview')
        ->assertSee(route('admin.themes.preview', ['theme' => 'kasai'], false), false);

    $this->get(route('admin.themes.preview', ['theme' => 'kasai']))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('Kasai owns every public contract template and runtime asset', function () {
    $themeRoot = realpath(base_path('themes/kasai'));
    $views = [
        'layout' => 'views/layout.blade.php',
        'home' => 'views/home.blade.php',
        'browse' => 'views/browse.blade.php',
        'series' => 'views/series.blade.php',
        'reader' => 'views/reader.blade.php',
        'mature-gate' => 'views/mature-gate.blade.php',
        'pagination' => 'views/pagination.blade.php',
        'partials.series-card' => 'views/partials/series-card.blade.php',
        'partials.comments' => 'views/partials/comments.blade.php',
        'partials.comment-item' => 'views/partials/comment-item.blade.php',
    ];

    foreach ($views as $view => $relativePath) {
        $expected = realpath($themeRoot.'/'.$relativePath);
        expect($expected)->not->toBeFalse()
            ->and(View::getFinder()->find('theme::'.$view))->toBe($expected);
    }

    expect(file_get_contents($themeRoot.'/views/partials/comments.blade.php'))
        ->not->toContain("@include('theme.partials")
        ->and(file_get_contents($themeRoot.'/assets/theme.css'))->not->toContain('url(http')
        ->and(file_get_contents($themeRoot.'/preview.svg'))->not->toContain('<image');

    $css = $this->get('/theme-assets/theme.css')->assertOk();
    $javascript = $this->get('/theme-assets/reader.js')->assertOk();

    expect($css->headers->get('Content-Type'))->toStartWith('text/css')
        ->and($javascript->headers->get('Content-Type'))->toStartWith('text/javascript');

    $this->get('/theme-assets/missing.png')->assertNotFound();
});

test('Kasai renders home browse long titles series chapters and empty states', function () {
    $longTitle = 'A Very Long Signal From the Edge of the Catalog Where Every Word Must Wrap Without Breaking the Layout';
    $series = kasaiSeries(['title' => $longTitle, 'slug' => 'long-signal-fire']);
    $chapter = kasaiChapter($series);
    $genre = Genre::create(['name' => 'Adventure', 'slug' => 'adventure']);
    $series->genres()->attach($genre);

    $emptySeries = kasaiSeries([
        'title' => 'Quiet Archive',
        'slug' => 'quiet-archive',
        'synopsis' => null,
        'views' => 0,
    ]);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Seasonal spotlight')
        ->assertSee($longTitle)
        ->assertSee('Latest updates')
        ->assertSee('Popular now')
        ->assertSee('/theme-assets/theme.css', false);

    $this->get(route('browse', ['q' => 'Signal', 'genre' => 'adventure', 'status' => 'ongoing']))
        ->assertOk()
        ->assertSee('Results for “Signal”')
        ->assertSee($longTitle)
        ->assertSee('Clear all filters');

    $this->get(route('series.show', $series))
        ->assertOk()
        ->assertSee($longTitle)
        ->assertSee('Release archive')
        ->assertSee($chapter->title)
        ->assertSee('Series discussion')
        ->assertSee('No comments yet.');

    $this->get(route('series.show', $emptySeries))
        ->assertOk()
        ->assertSee('No chapters published yet.')
        ->assertSee('No synopsis has been added', false);

    $this->get(route('browse', ['q' => 'Definitely absent']))
        ->assertOk()
        ->assertSee('No matching series.')
        ->assertSee('Reset catalog');
});

test('Kasai preserves mature gating and staff-only private previews', function () {
    app(Settings::class)->set('mature_gate', true);
    $mature = kasaiSeries([
        'title' => 'Mature Signal',
        'slug' => 'mature-signal',
        'content_rating' => 'mature',
    ]);
    kasaiChapter($mature);

    $this->get(route('home'))->assertOk()->assertDontSee($mature->title);
    $this->get(route('series.show', $mature))->assertRedirect(route('mature.confirmation'));
    $this->get(route('mature.confirmation'))
        ->assertOk()
        ->assertSee('Age confirmation required')
        ->assertSee('mature-gate-card', false)
        ->assertDontSee($mature->title);

    $private = kasaiSeries([
        'title' => 'Internal Ember',
        'slug' => 'internal-ember',
        'is_public' => false,
    ]);
    $chapter = kasaiChapter($private);

    $this->get(route('series.show', $private))->assertNotFound();

    $editor = User::factory()->create();
    $editor->assignRole('Editor');

    $this->actingAs($editor)
        ->get(route('series.show', $private))
        ->assertOk()
        ->assertSee('Private preview')
        ->assertSee($private->title);

    $this->actingAs($editor)
        ->get(route('reader', [$private, $chapter->displayNumber()]))
        ->assertOk()
        ->assertSee('Private preview')
        ->assertSee($private->title);
});

test('Kasai reader owns every mode and chapter discussion state', function () {
    $series = kasaiSeries(['default_reader_mode' => 'vertical']);
    $chapter = kasaiChapter($series);
    Page::create([
        'chapter_id' => $chapter->id,
        'sort_order' => 0,
        'image_path' => 'kasai-tests/page-001.webp',
        'width' => 800,
        'height' => 1200,
        'file_size' => 1000,
    ]);

    $member = User::factory()->create(['name' => 'Kasai Member']);
    $member->assignRole('Member');
    $approved = $chapter->comments()->create([
        'user_id' => $member->id,
        'body' => 'Approved Kasai discussion.',
        'status' => Comment::STATUS_APPROVED,
    ]);
    $pending = $chapter->comments()->create([
        'user_id' => $member->id,
        'body' => 'Pending Kasai discussion.',
        'status' => Comment::STATUS_PENDING,
    ]);
    $deleted = $chapter->comments()->create([
        'user_id' => $member->id,
        'body' => 'Deleted Kasai discussion.',
        'status' => Comment::STATUS_APPROVED,
    ]);
    $deleted->delete();

    $this->get(route('reader', [$series, $chapter->displayNumber()]))
        ->assertOk()
        ->assertSee('"initialMode":"vertical"', false)
        ->assertSee('aria-pressed="true" class="on">Webtoon', false)
        ->assertSee('data-mode="rtl"', false)
        ->assertSee('data-mode="ltr"', false)
        ->assertSee('data-mode="vertical"', false)
        ->assertSee('readerProgressFill', false)
        ->assertSee('Approved Kasai discussion.')
        ->assertDontSee('Pending Kasai discussion.')
        ->assertSee('This comment was deleted.')
        ->assertSee('/theme-assets/reader.js', false);

    $moderator = User::factory()->create();
    $moderator->assignRole('Moderator');

    $this->actingAs($moderator)
        ->get(route('reader', [$series, $chapter->displayNumber()]))
        ->assertOk()
        ->assertSee($pending->body)
        ->assertSee('Pending review');

    expect($approved->fresh()->isApproved())->toBeTrue();
});
