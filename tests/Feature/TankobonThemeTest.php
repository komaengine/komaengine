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
    app(Settings::class)->setMany(['active_theme' => 'tankobon', 'installed_at' => now()->toIso8601String()]);
    app(ThemeManager::class)->registerViews();
});

function tankobonSeries(array $attributes = []): Series
{
    return Series::create(array_merge([
        'title' => 'The Bound Volume', 'slug' => 'the-bound-volume', 'author' => 'Koma Test Author',
        'artist' => 'Koma Test Artist', 'year' => 2026, 'status' => 'ongoing', 'reading_direction' => 'rtl',
        'content_rating' => 'safe', 'is_public' => true,
        'synopsis' => 'A production-safe test synopsis for the Tankobon single-series theme.', 'cover_hue' => 20, 'views' => 200,
    ], $attributes));
}

function tankobonChapter(Series $series, array $attributes = []): Chapter
{
    return Chapter::create(array_merge([
        'series_id' => $series->id, 'number' => 1, 'title' => 'The first page',
        'status' => Chapter::STATUS_PUBLISHED, 'published_at' => now()->subHour(),
    ], $attributes));
}

test('Tankobon is discoverable compatible activatable and has an authorized preview', function () {
    $themes = app(ThemeManager::class);
    $manifest = $themes->all()['tankobon'];

    expect($manifest['name'])->toBe('Tankōbon')
        ->and($manifest['version'])->toBe('1.0.0')
        ->and($manifest['compatible'])->toBeTrue()
        ->and($manifest['preview_url'])->toBe(route('admin.themes.preview', ['theme' => 'tankobon'], false))
        ->and($themes->previewPath('tankobon'))->toBe(realpath(base_path('themes/tankobon/preview.svg')));

    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    Livewire::actingAs($admin)->test(Extensions::class)->call('activateTheme', 'tankobon');
    expect(app(Settings::class)->get('active_theme'))->toBe('tankobon');

    $this->get(route('admin.themes.preview', ['theme' => 'tankobon']))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('Tankobon ships its templates and serves dependency-free assets', function () {
    $themeRoot = realpath(base_path('themes/tankobon'));
    $views = [
        'layout' => 'views/layout.blade.php',
        'home' => 'views/home.blade.php',
        'browse' => 'views/browse.blade.php',
        'series' => 'views/series.blade.php',
        'reader' => 'views/reader.blade.php',
        'pagination' => 'views/pagination.blade.php',
        'partials.landing' => 'views/partials/landing.blade.php',
        'partials.series-card' => 'views/partials/series-card.blade.php',
        'partials.comments' => 'views/partials/comments.blade.php',
    ];

    foreach ($views as $view => $relativePath) {
        $expected = realpath($themeRoot.'/'.$relativePath);
        expect($expected)->not->toBeFalse()
            ->and(View::getFinder()->find('theme::'.$view))->toBe($expected);
    }

    expect(file_get_contents($themeRoot.'/assets/theme.css'))->not->toContain('url(http')
        ->and(file_get_contents($themeRoot.'/preview.svg'))->not->toContain('<image');

    $css = $this->get('/theme-assets/theme.css')->assertOk();
    $javascript = $this->get('/theme-assets/reader.js')->assertOk();
    expect($css->headers->get('Content-Type'))->toStartWith('text/css')
        ->and($javascript->headers->get('Content-Type'))->toStartWith('text/javascript');
    $this->get('/theme-assets/missing.png')->assertNotFound();
});

test('Tankobon home features the single series as a bound-volume landing', function () {
    $series = tankobonSeries();
    $genre = Genre::create(['name' => 'Comedy', 'slug' => 'comedy']);
    $series->genres()->attach($genre);
    $first = tankobonChapter($series, ['number' => 1, 'title' => 'The first page']);
    $latest = tankobonChapter($series, ['number' => 2, 'title' => 'The second page', 'published_at' => now()->subMinutes(5)]);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Now reading')
        ->assertSee('The Bound Volume')
        ->assertSee('Start reading · Ch. 1', false)
        ->assertSee('Chapters')
        ->assertSee('The first page')
        ->assertSee('The second page')
        ->assertSee('Latest chapters')
        ->assertSee('Join the discussion', false)
        ->assertSee('/theme-assets/theme.css', false);
});

test('Tankobon home shows an empty state with no published series', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('No series has been published');
});

test('Tankobon series page renders the landing plus discussion, and library lists series', function () {
    $series = tankobonSeries();
    $chapter = tankobonChapter($series);

    $this->get(route('series.show', $series))
        ->assertOk()
        ->assertSee('The Bound Volume')
        ->assertSee('The first page')
        ->assertSee('Series discussion')
        ->assertSee('No comments yet.');

    $this->get(route('browse'))
        ->assertOk()
        ->assertSee('All series')
        ->assertSee('The Bound Volume');

    $this->get(route('browse', ['q' => 'Definitely absent series']))
        ->assertOk()
        ->assertSee('No matching series.');
});

test('Tankobon preserves mature gating and staff-only private previews', function () {
    app(Settings::class)->set('mature_gate', true);
    $mature = tankobonSeries(['title' => 'Mature Volume', 'slug' => 'mature-volume', 'content_rating' => 'mature']);
    tankobonChapter($mature);

    $this->get(route('series.show', $mature))->assertRedirect(route('mature.confirmation'));
    $this->get(route('mature.confirmation'))
        ->assertOk()
        ->assertSee('Age confirmation required')
        ->assertSee('mature-gate-card', false)
        ->assertDontSee($mature->title);

    $private = tankobonSeries(['title' => 'Internal Draft', 'slug' => 'internal-draft', 'is_public' => false]);
    $chapter = tankobonChapter($private);
    $this->get(route('series.show', $private))->assertNotFound();

    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $this->actingAs($editor)->get(route('series.show', $private))
        ->assertOk()
        ->assertSee('Private preview')
        ->assertSee($private->title);
    $this->actingAs($editor)->get(route('reader', [$private, $chapter->displayNumber()]))
        ->assertOk()
        ->assertSee('Private preview');
});

test('Tankobon reader owns every mode and chapter discussion state', function () {
    $series = tankobonSeries(['default_reader_mode' => 'vertical']);
    $chapter = tankobonChapter($series);
    Page::create(['chapter_id' => $chapter->id, 'sort_order' => 0, 'image_path' => 'tankobon-tests/page-001.webp', 'width' => 800, 'height' => 1200, 'file_size' => 1000]);

    $member = User::factory()->create(['name' => 'Tankobon Member']);
    $member->assignRole('Member');
    $approved = $chapter->comments()->create(['user_id' => $member->id, 'body' => 'Approved Tankobon discussion.', 'status' => Comment::STATUS_APPROVED]);
    $pending = $chapter->comments()->create(['user_id' => $member->id, 'body' => 'Pending Tankobon discussion.', 'status' => Comment::STATUS_PENDING]);
    $deleted = $chapter->comments()->create(['user_id' => $member->id, 'body' => 'Deleted Tankobon discussion.', 'status' => Comment::STATUS_APPROVED]);
    $deleted->delete();

    $this->get(route('reader', [$series, $chapter->displayNumber()]))
        ->assertOk()
        ->assertSee('"initialMode":"vertical"', false)
        ->assertSee('data-mode="rtl"', false)
        ->assertSee('data-mode="ltr"', false)
        ->assertSee('data-mode="vertical"', false)
        ->assertSee('Approved Tankobon discussion.')
        ->assertDontSee('Pending Tankobon discussion.')
        ->assertSee('This comment was deleted.')
        ->assertSee('/theme-assets/reader.js', false);

    $moderator = User::factory()->create();
    $moderator->assignRole('Moderator');
    $this->actingAs($moderator)->get(route('reader', [$series, $chapter->displayNumber()]))
        ->assertOk()
        ->assertSee($pending->body)
        ->assertSee('Pending review');
    expect($approved->fresh()->isApproved())->toBeTrue();
});
