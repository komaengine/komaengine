<?php

use App\Livewire\Admin\Series\Index;
use App\Models\Chapter;
use App\Models\Comment;
use App\Models\CommentReport;
use App\Models\Genre;
use App\Models\Series;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->editor = User::factory()->create();
    $this->editor->assignRole('Editor');

    $this->drama = Genre::create(['name' => 'Drama']);
    $this->fantasy = Genre::create(['name' => 'Fantasy']);
    $this->family = Tag::create(['name' => 'Found family']);
    $this->slowBurn = Tag::create(['name' => 'Slow burn']);

    $this->alpha = Series::create([
        'title' => 'Alpha Signal',
        'author' => 'Aster Vale',
        'status' => 'ongoing',
        'is_public' => true,
        'views' => 1200,
    ]);
    $this->alpha->genres()->attach($this->drama);
    $this->alpha->tags()->attach($this->family);
    $this->alphaChapter = Chapter::create([
        'series_id' => $this->alpha->id,
        'number' => 1,
        'status' => 'published',
    ]);
    $this->alpha->comments()->create(['body' => 'Series comment', 'status' => Comment::STATUS_APPROVED]);
    $this->alphaChapter->comments()->create(['body' => 'Chapter comment', 'status' => Comment::STATUS_PENDING]);

    $this->beta = Series::create([
        'title' => 'Beta Chronicle',
        'artist' => 'Briar Ink',
        'status' => 'completed',
        'is_public' => false,
        'views' => 30,
    ]);
    $this->beta->genres()->attach($this->fantasy);
    $this->beta->tags()->attach($this->slowBurn);
});

test('series table shows taxonomy comments and existing management data', function () {
    Livewire::actingAs($this->editor)
        ->test(Index::class)
        ->assertSee('Search, filter, and manage your catalog.')
        ->assertSee($this->alpha->title)
        ->assertSee($this->drama->name)
        ->assertSee($this->family->name)
        ->assertSee('Comments')
        ->assertSee('Chapters')
        ->assertSee('Views')
        ->assertSee('data-testid="genre-chip-'.$this->drama->id.'"', false)
        ->assertSee('data-testid="tag-chip-'.$this->family->id.'"', false)
        ->assertSee('data-icon="views"', false)
        ->assertSee('data-icon="updated"', false)
        ->assertSee('data-comment-count="2"', false)
        ->assertSee('data-testid="series-actions-'.$this->alpha->id.'"', false)
        ->assertSee('aria-haspopup="menu"', false)
        ->assertSee('data-menu-placement="down"', false)
        ->assertSee('data-menu-placement="up"', false)
        ->assertSee('wire:click="deleteSeries('.$this->alpha->id.')"', false)
        ->assertSee('This cannot be undone.')
        ->assertSee('1K');
});

test('search and every series filter narrow the table', function () {
    $component = Livewire::actingAs($this->editor)->test(Index::class);

    $component
        ->set('search', 'Aster')
        ->call('applyFilters')
        ->assertSee($this->alpha->title)
        ->assertDontSee($this->beta->title)
        ->call('clearFilters')
        ->set('statusFilter', 'completed')
        ->call('applyFilters')
        ->assertDontSee($this->alpha->title)
        ->assertSee($this->beta->title)
        ->call('clearFilters')
        ->set('visibilityFilter', 'private')
        ->call('applyFilters')
        ->assertDontSee($this->alpha->title)
        ->assertSee($this->beta->title)
        ->call('clearFilters')
        ->set('genreFilter', (string) $this->drama->id)
        ->call('applyFilters')
        ->assertSee($this->alpha->title)
        ->assertDontSee($this->beta->title)
        ->call('clearFilters')
        ->set('tagFilter', (string) $this->slowBurn->id)
        ->call('applyFilters')
        ->assertDontSee($this->alpha->title)
        ->assertSee($this->beta->title);
});

test('taxonomy names in the table act as quick filters', function () {
    Livewire::actingAs($this->editor)
        ->test(Index::class)
        ->call('filterByGenre', $this->drama->id)
        ->assertSet('genreFilter', (string) $this->drama->id)
        ->assertSee($this->alpha->title)
        ->assertDontSee($this->beta->title)
        ->call('clearFilters')
        ->call('filterByTag', $this->slowBurn->id)
        ->assertSet('tagFilter', (string) $this->slowBurn->id)
        ->assertDontSee($this->alpha->title)
        ->assertSee($this->beta->title);
});

test('series rows show only three tags until the additional tags are disclosed', function () {
    $additionalTags = collect(['Adventure', 'Comedy', 'Mystery', 'Romance'])
        ->map(fn (string $name) => Tag::create(['name' => $name]));
    $this->alpha->tags()->attach($additionalTags->pluck('id'));

    $hiddenTags = $this->alpha->fresh('tags')->tags->slice(3);

    $component = Livewire::actingAs($this->editor)
        ->test(Index::class)
        ->assertSee('data-testid="tag-overflow-'.$this->alpha->id.'"', false)
        ->assertSee('+2 more')
        ->assertSee('title="'.$hiddenTags->pluck('name')->implode(', ').'"', false)
        ->assertSee('x-bind:aria-expanded="expanded"', false)
        ->assertSee('x-show="expanded"', false);

    foreach ($hiddenTags as $tag) {
        $component->assertSee('data-testid="additional-tag-chip-'.$tag->id.'"', false);
    }
});

test('the only series row opens its action menu upward inside the table', function () {
    $this->beta->delete();

    Livewire::actingAs($this->editor)
        ->test(Index::class)
        ->assertSee('data-testid="series-actions-'.$this->alpha->id.'"', false)
        ->assertSee('data-menu-placement="up"', false)
        ->assertSee('bottom-full -mb-0.5', false)
        ->assertDontSee('data-menu-placement="down"', false);
});

test('series action menu deletes relational data and exact media after confirmation', function () {
    Storage::fake('media');
    $this->alpha->forceFill([
        'cover_path' => 'covers/alpha/original.webp',
        'cover_thumbnail_path' => 'covers/alpha/thumb.webp',
    ])->save();
    $page = $this->alphaChapter->pages()->create([
        'sort_order' => 1,
        'image_path' => 'pages/alpha/001.webp',
    ]);
    $mediaPaths = [
        $this->alpha->cover_path,
        $this->alpha->cover_thumbnail_path,
        $page->image_path,
    ];

    foreach ($mediaPaths as $path) {
        Storage::disk('media')->put($path, 'fixture');
    }

    $seriesComment = $this->alpha->comments()->firstOrFail();
    $report = CommentReport::forceCreate([
        'comment_id' => $seriesComment->id,
        'reason' => 'other',
        'comment_author_name' => 'Deleted author',
        'commentable_type' => Series::class,
        'commentable_id' => $this->alpha->id,
        'comment_body' => $seriesComment->body,
    ]);

    Livewire::actingAs($this->editor)
        ->test(Index::class)
        ->call('deleteSeries', $this->alpha->id)
        ->assertDontSee($this->alpha->title);

    expect(Series::query()->find($this->alpha->id))->toBeNull()
        ->and(Chapter::query()->find($this->alphaChapter->id))->toBeNull()
        ->and(Comment::withTrashed()->where(function ($comments): void {
            $comments
                ->where(function ($seriesComments): void {
                    $seriesComments
                        ->where('commentable_type', Series::class)
                        ->where('commentable_id', $this->alpha->id);
                })
                ->orWhere(function ($chapterComments): void {
                    $chapterComments
                        ->where('commentable_type', Chapter::class)
                        ->where('commentable_id', $this->alphaChapter->id);
                });
        })->exists())->toBeFalse()
        ->and($report->fresh()->comment_id)->toBeNull();

    foreach ($mediaPaths as $path) {
        Storage::disk('media')->assertMissing($path);
    }
});

test('series deletion action rechecks authorization', function () {
    $member = User::factory()->create();
    $member->assignRole('Member');

    Livewire::actingAs($member)
        ->test(Index::class)
        ->call('deleteSeries', $this->alpha->id)
        ->assertForbidden();

    expect($this->alpha->fresh())->not->toBeNull();
});
