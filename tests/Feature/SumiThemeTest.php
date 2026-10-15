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
    app(Settings::class)->setMany(['active_theme' => 'sumi', 'installed_at' => now()->toIso8601String()]);
    app(ThemeManager::class)->registerViews();
});

function sumiSeries(array $attributes = []): Series
{
    return Series::create(array_merge([
        'title' => 'Quiet Ink Archive', 'slug' => 'quiet-ink-archive', 'author' => 'Koma Test Author',
        'artist' => 'Koma Test Artist', 'year' => 2026, 'status' => 'ongoing', 'reading_direction' => 'rtl',
        'content_rating' => 'safe', 'is_public' => true,
        'synopsis' => 'A production-safe test synopsis for the Sumi public theme.', 'cover_hue' => 31, 'views' => 120,
    ], $attributes));
}

function sumiChapter(Series $series, array $attributes = []): Chapter
{
    return Chapter::create(array_merge([
        'series_id' => $series->id, 'number' => 1, 'title' => 'First impression',
        'status' => Chapter::STATUS_PUBLISHED, 'published_at' => now()->subHour(),
    ], $attributes));
}

test('Sumi is discoverable compatible activatable and has an authorized preview', function () {
    $themes = app(ThemeManager::class);
    $manifest = $themes->all()['sumi'];

    expect($manifest['name'])->toBe('Sumi')
        ->and($manifest['version'])->toBe('1.0.0')
        ->and($manifest['compatible'])->toBeTrue()
        ->and($manifest['preview_url'])->toBe(route('admin.themes.preview', ['theme' => 'sumi'], false))
        ->and($themes->previewPath('sumi'))->toBe(realpath(base_path('themes/sumi/preview.svg')));

    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    Livewire::actingAs($admin)->test(Extensions::class)->call('activateTheme', 'sumi');
    expect(app(Settings::class)->get('active_theme'))->toBe('sumi');

    $this->actingAs($admin)->get(route('admin.themes'))->assertOk()->assertSee('Sumi theme preview')->assertSee(route('admin.themes.preview', ['theme' => 'sumi'], false), false);
    $this->get(route('admin.themes.preview', ['theme' => 'sumi']))->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('Sumi owns every public contract template and runtime asset', function () {
    $themeRoot = realpath(base_path('themes/sumi'));
    $views = [
        'layout' => 'views/layout.blade.php', 'home' => 'views/home.blade.php', 'browse' => 'views/browse.blade.php',
        'series' => 'views/series.blade.php', 'reader' => 'views/reader.blade.php', 'mature-gate' => 'views/mature-gate.blade.php',
        'pagination' => 'views/pagination.blade.php', 'partials.series-card' => 'views/partials/series-card.blade.php',
        'partials.comments' => 'views/partials/comments.blade.php', 'partials.comment-item' => 'views/partials/comment-item.blade.php',
    ];

    foreach ($views as $view => $relativePath) {
        $expected = realpath($themeRoot.'/'.$relativePath);
        expect($expected)->not->toBeFalse()->and(View::getFinder()->find('theme::'.$view))->toBe($expected);
    }

    expect(file_get_contents($themeRoot.'/views/partials/comments.blade.php'))->not->toContain("@include('theme.partials")
        ->and(file_get_contents($themeRoot.'/assets/theme.css'))->not->toContain('url(http')
        ->and(file_get_contents($themeRoot.'/preview.svg'))->not->toContain('<image');

    $css = $this->get('/theme-assets/theme.css')->assertOk();
    $javascript = $this->get('/theme-assets/reader.js')->assertOk();
    expect($css->headers->get('Content-Type'))->toStartWith('text/css')->and($javascript->headers->get('Content-Type'))->toStartWith('text/javascript');
    $this->get('/theme-assets/missing.png')->assertNotFound();
});

test('Sumi renders home browse long titles series chapters and empty states', function () {
    $longTitle = 'A Very Long Quiet Signal From the Edge of the Catalog Where Every Word Must Wrap Without Breaking the Layout';
    $series = sumiSeries(['title' => $longTitle, 'slug' => 'long-quiet-signal']);
    $chapter = sumiChapter($series);
    $genre = Genre::create(['name' => 'Adventure', 'slug' => 'adventure']);
    $series->genres()->attach($genre);
    $emptySeries = sumiSeries(['title' => 'Unmarked Shelf', 'slug' => 'unmarked-shelf', 'synopsis' => null, 'views' => 0]);

    $this->get(route('home'))->assertOk()->assertSee('Editorial selection')->assertSee($longTitle)->assertSee('Latest updates')->assertSee('Popular in the archive')->assertSee('top-search-submit', false)->assertSee('/theme-assets/theme.css', false);
    $this->get(route('browse', ['q' => 'Signal', 'genre' => 'adventure', 'status' => 'ongoing']))->assertOk()->assertSee('Results for “Signal”')->assertSee($longTitle)->assertSee('Clear all filters');
    $this->get(route('series.show', $series))->assertOk()->assertSee($longTitle)->assertSee('Release archive')->assertSee($chapter->title)->assertSee('Series discussion')->assertSee('No comments yet.');
    $this->get(route('series.show', $emptySeries))->assertOk()->assertSee('No chapters published yet.')->assertSee('No synopsis has been added', false);
    $this->get(route('browse', ['q' => 'Definitely absent']))->assertOk()->assertSee('No matching series.')->assertSee('Reset catalog');
});

test('Sumi preserves mature gating and staff-only private previews', function () {
    app(Settings::class)->set('mature_gate', true);
    $mature = sumiSeries(['title' => 'Mature Ink', 'slug' => 'mature-ink', 'content_rating' => 'mature']);
    sumiChapter($mature);
    $this->get(route('home'))->assertOk()->assertDontSee($mature->title);
    $this->get(route('series.show', $mature))->assertRedirect(route('mature.confirmation'));
    $this->get(route('mature.confirmation'))->assertOk()->assertSee('Age confirmation required')->assertSee('mature-gate-card', false)->assertDontSee($mature->title);

    $private = sumiSeries(['title' => 'Internal Manuscript', 'slug' => 'internal-manuscript', 'is_public' => false]);
    $chapter = sumiChapter($private);
    $this->get(route('series.show', $private))->assertNotFound();
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $this->actingAs($editor)->get(route('series.show', $private))->assertOk()->assertSee('Private preview')->assertSee($private->title);
    $this->actingAs($editor)->get(route('reader', [$private, $chapter->displayNumber()]))->assertOk()->assertSee('Private preview')->assertSee($private->title);
});

test('Sumi reader owns every mode and chapter discussion state', function () {
    $series = sumiSeries(['default_reader_mode' => 'vertical']);
    $chapter = sumiChapter($series);
    Page::create(['chapter_id' => $chapter->id, 'sort_order' => 0, 'image_path' => 'sumi-tests/page-001.webp', 'width' => 800, 'height' => 1200, 'file_size' => 1000]);
    $member = User::factory()->create(['name' => 'Sumi Member']);
    $member->assignRole('Member');
    $approved = $chapter->comments()->create(['user_id' => $member->id, 'body' => 'Approved Sumi discussion.', 'status' => Comment::STATUS_APPROVED]);
    $pending = $chapter->comments()->create(['user_id' => $member->id, 'body' => 'Pending Sumi discussion.', 'status' => Comment::STATUS_PENDING]);
    $deleted = $chapter->comments()->create(['user_id' => $member->id, 'body' => 'Deleted Sumi discussion.', 'status' => Comment::STATUS_APPROVED]);
    $deleted->delete();

    $this->get(route('reader', [$series, $chapter->displayNumber()]))->assertOk()
        ->assertSee('"initialMode":"vertical"', false)->assertSee('aria-pressed="true" class="on">Webtoon', false)
        ->assertSee('data-mode="rtl"', false)->assertSee('data-mode="ltr"', false)->assertSee('data-mode="vertical"', false)
        ->assertSee('readerProgressFill', false)->assertSee('Approved Sumi discussion.')->assertDontSee('Pending Sumi discussion.')
        ->assertSee('This comment was deleted.')->assertSee('/theme-assets/reader.js', false);

    $moderator = User::factory()->create();
    $moderator->assignRole('Moderator');
    $this->actingAs($moderator)->get(route('reader', [$series, $chapter->displayNumber()]))->assertOk()->assertSee($pending->body)->assertSee('Pending review');
    expect($approved->fresh()->isApproved())->toBeTrue();
});
