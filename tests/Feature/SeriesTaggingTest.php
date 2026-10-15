<?php

use App\Livewire\Admin\Series\Edit as SeriesEdit;
use App\Models\Chapter;
use App\Models\Genre;
use App\Models\Series;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->editor = User::factory()->create();
    $this->editor->assignRole('Editor');
});

test('create new shows tags separately from genres', function () {
    Genre::create(['name' => 'Drama']);
    Tag::create(['name' => 'Found family']);

    $this->actingAs($this->editor)
        ->get(route('admin.series.create'))
        ->assertOk()
        ->assertSeeInOrder(['Genres', 'Manage genres', 'Drama', 'Tags', 'Manage tags', 'Found family'])
        ->assertSeeInOrder(['Cover art', 'Create series', 'Visibility'])
        ->assertSee('aria-pressed="false"', false);
});

test('creating a series persists independently selected genres and tags', function () {
    $genre = Genre::create(['name' => 'Drama']);
    $selectedTag = Tag::create(['name' => 'Found family']);
    $unselectedTag = Tag::create(['name' => 'Slow burn']);

    Livewire::actingAs($this->editor)
        ->test(SeriesEdit::class)
        ->call('toggleGenre', $genre->id)
        ->call('toggleTag', $selectedTag->id)
        ->set('title', 'Tagged Test Series')
        ->call('save')
        ->assertHasNoErrors();

    $series = Series::query()->where('title', 'Tagged Test Series')->sole();

    expect($series->genres()->pluck('genres.id')->all())->toBe([$genre->id])
        ->and($series->tags()->pluck('tags.id')->all())->toBe([$selectedTag->id])
        ->and($series->tags()->whereKey($unselectedTag->id)->exists())->toBeFalse();

    Livewire::actingAs($this->editor)
        ->test(SeriesEdit::class, ['series' => $series])
        ->assertSet('selectedGenres', [$genre->id])
        ->assertSet('selectedTags', [$selectedTag->id]);
});

test('editing a series replaces its selected tags', function () {
    $oldTag = Tag::create(['name' => 'Old tag']);
    $newTag = Tag::create(['name' => 'New tag']);
    $series = Series::create([
        'title' => 'Existing Tagged Series',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
    ]);
    $series->tags()->attach($oldTag);

    Livewire::actingAs($this->editor)
        ->test(SeriesEdit::class, ['series' => $series])
        ->call('toggleTag', $oldTag->id)
        ->call('toggleTag', $newTag->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($series->fresh()->tags()->pluck('tags.id')->all())->toBe([$newTag->id]);
});

test('forged tag ids are rejected without creating a series', function () {
    Livewire::actingAs($this->editor)
        ->test(SeriesEdit::class)
        ->set('title', 'Forged Tag Series')
        ->set('selectedTags', [999999])
        ->call('save')
        ->assertHasErrors(['selectedTags.0' => 'exists']);

    expect(Series::query()->where('title', 'Forged Tag Series')->exists())->toBeFalse();
});

test('edit workspace persists archive v2 catalog metadata and chapter language', function () {
    $series = Series::create([
        'title' => 'Metadata Series',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
    ]);
    $chapter = Chapter::create([
        'series_id' => $series->id,
        'number' => 1,
        'status' => 'draft',
    ]);

    Livewire::actingAs($this->editor)
        ->test(SeriesEdit::class, ['series' => $series])
        ->set('publication_date', '2025-04-04')
        ->set('work_format', 'doujinshi')
        ->set('alternateTitles', [
            ['language' => 'ja', 'title' => 'メタデータ', 'type' => 'original'],
        ])
        ->set('credits', [
            ['name' => 'Example Artist', 'role' => 'artist', 'type' => 'person'],
        ])
        ->set('franchisesText', "Example Franchise\nExample Franchise")
        ->set('charactersText', "Character One\nCharacter Two")
        ->set('sourceReferences', [[
            'provider' => 'example-scanner',
            'external_id' => '3314583',
            'url' => 'https://gallery.example/example.html',
        ]])
        ->set("chapterLanguages.{$chapter->id}", 'en')
        ->call('save')
        ->assertHasNoErrors();

    $series->refresh();
    $chapter->refresh();

    expect($series->publication_date->format('Y-m-d'))->toBe('2025-04-04')
        ->and($series->work_format)->toBe('doujinshi')
        ->and($series->alternate_titles)->toHaveCount(1)
        ->and($series->credits[0]['name'])->toBe('Example Artist')
        ->and($series->franchises)->toBe(['Example Franchise'])
        ->and($series->characters)->toBe(['Character One', 'Character Two'])
        ->and($series->source_references[0]['external_id'])->toBe('3314583')
        ->and($chapter->language)->toBe('en');

    $this->actingAs($this->editor)
        ->get(route('admin.series.edit', $series))
        ->assertOk()
        ->assertSee('Catalog metadata')
        ->assertSee('Download v2 archive')
        ->assertSee('Download v1 compatibility archive');
});
