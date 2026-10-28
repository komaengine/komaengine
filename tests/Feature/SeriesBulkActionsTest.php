<?php

use App\Livewire\Admin\Series\Index;
use App\Models\Chapter;
use App\Models\Comment;
use App\Models\Genre;
use App\Models\Series;
use App\Models\User;
use App\Support\Facades\Hook;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('media');
    $this->editor = User::factory()->create();
    $this->editor->assignRole('Editor');

    $this->drama = Genre::create(['name' => 'Drama']);

    // Public with a published chapter.
    $this->alpha = Series::create(['title' => 'Alpha Signal', 'status' => 'ongoing', 'is_public' => true]);
    $this->alphaChapter = Chapter::create(['series_id' => $this->alpha->id, 'number' => 1]);
    $this->alphaChapter->publish();
    $this->alphaChapter->pages()->create(['sort_order' => 1, 'image_path' => 'pages/alpha/1.webp']);
    $this->alphaChapter->comments()->create(['body' => 'Chapter comment', 'status' => Comment::STATUS_APPROVED]);
    Storage::disk('media')->put('pages/alpha/1.webp', 'fixture');

    // Private with a published chapter.
    $this->beta = Series::create(['title' => 'Beta Chronicle', 'status' => 'ongoing', 'is_public' => false]);
    Chapter::create(['series_id' => $this->beta->id, 'number' => 1])->publish();
    $this->beta->genres()->attach($this->drama);

    // Private with only a draft chapter.
    $this->gamma = Series::create(['title' => 'Gamma Draft', 'status' => 'ongoing', 'is_public' => false]);
    Chapter::create(['series_id' => $this->gamma->id, 'number' => 1, 'status' => Chapter::STATUS_DRAFT]);
    $this->gamma->update(['cover_path' => 'covers/gamma.webp']);
    Storage::disk('media')->put('covers/gamma.webp', 'fixture');
});

test('make public changes only private series and says which stay hidden', function () {
    $savedHooks = [];
    Hook::addAction('series.saved', function (Series $series) use (&$savedHooks) {
        $savedHooks[] = $series->id;
    });

    Livewire::actingAs($this->editor)
        ->test(Index::class)
        ->set('selectedSeries', [$this->alpha->id, $this->beta->id, $this->gamma->id])
        ->call('applySeriesBulk', 'public')
        ->assertHasNoErrors()
        ->assertSet('selectedSeries', [])
        ->assertSet('seriesNotice', 'Made 2 series public. 1 was already public. 1 series stays hidden from readers until it has a published chapter.')
        ->assertSet('seriesNoticeIsError', false);

    expect($this->beta->fresh()->is_public)->toBeTrue()
        ->and($this->gamma->fresh()->is_public)->toBeTrue()
        ->and($this->gamma->chapters()->first()->status)->toBe(Chapter::STATUS_DRAFT)
        ->and($savedHooks)->toEqualCanonicalizing([$this->beta->id, $this->gamma->id])
        ->and(Series::query()->published()->pluck('id')->all())->toEqualCanonicalizing([$this->alpha->id, $this->beta->id]);
});

test('make private hides the selected series', function () {
    Livewire::actingAs($this->editor)
        ->test(Index::class)
        ->set('selectedSeries', [$this->alpha->id, $this->beta->id])
        ->call('applySeriesBulk', 'private')
        ->assertHasNoErrors()
        ->assertSet('seriesNotice', 'Made 1 series private. 1 was already private.');

    expect($this->alpha->fresh()->is_public)->toBeFalse()
        ->and($this->alphaChapter->fresh()->isPublished())->toBeTrue();

    Livewire::actingAs($this->editor)
        ->test(Index::class)
        ->set('selectedSeries', [$this->alpha->id, $this->beta->id])
        ->call('applySeriesBulk', 'private')
        ->assertSet('seriesNotice', 'The selected series were already private.');
});

test('bulk delete removes the selected series with their chapters, comments, and media', function () {
    Livewire::actingAs($this->editor)
        ->test(Index::class)
        ->set('selectedSeries', [$this->alpha->id, $this->gamma->id])
        ->call('applySeriesBulk', 'delete')
        ->assertHasNoErrors()
        ->assertSet('selectedSeries', [])
        ->assertSet('seriesNotice', 'Deleted 2 series.')
        ->assertSet('seriesNoticeIsError', false)
        ->assertDontSee('Alpha Signal')
        ->assertSee('Beta Chronicle');

    expect(Series::query()->whereKey([$this->alpha->id, $this->gamma->id])->exists())->toBeFalse()
        ->and(Chapter::query()->find($this->alphaChapter->id))->toBeNull()
        ->and(Comment::withTrashed()->where('commentable_type', Chapter::class)->where('commentable_id', $this->alphaChapter->id)->exists())->toBeFalse()
        ->and($this->beta->fresh())->not->toBeNull();
    Storage::disk('media')->assertMissing('pages/alpha/1.webp');
    Storage::disk('media')->assertMissing('covers/gamma.webp');
});

test('a failed media cleanup is reported and the rest of the selection is still deleted', function () {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('delete')->twice()->andReturn(false, true);
    Storage::shouldReceive('disk')->with('media')->andReturn($disk);

    Livewire::actingAs($this->editor)
        ->test(Index::class)
        ->set('selectedSeries', [$this->alpha->id, $this->gamma->id])
        ->call('applySeriesBulk', 'delete')
        ->assertSet('seriesNotice', 'Deleted 2 series, but some media files could not be removed. Check the application logs.')
        ->assertSet('seriesNoticeIsError', true);

    expect(Series::query()->whereKey([$this->alpha->id, $this->gamma->id])->exists())->toBeFalse();
});

test('a selection with a series that no longer exists changes nothing', function () {
    $missing = $this->gamma->id + 100;

    Livewire::actingAs($this->editor)
        ->test(Index::class)
        ->set('selectedSeries', [$this->beta->id, $missing])
        ->call('applySeriesBulk', 'delete')
        ->assertHasErrors('selectedSeries');

    Livewire::actingAs($this->editor)
        ->test(Index::class)
        ->set('selectedSeries', [$this->beta->id, $missing])
        ->call('applySeriesBulk', 'public')
        ->assertHasErrors('selectedSeries');

    expect($this->beta->fresh()->is_public)->toBeFalse();
});

test('a bulk action needs a selection and a known action', function () {
    Livewire::actingAs($this->editor)
        ->test(Index::class)
        ->call('applySeriesBulk', 'public')
        ->assertHasErrors(['selectedSeries' => 'required']);

    Livewire::actingAs($this->editor)
        ->test(Index::class)
        ->set('selectedSeries', [$this->beta->id])
        ->call('applySeriesBulk', 'archive')
        ->assertStatus(422);
});

test('an uploader cannot use the bulk actions', function () {
    $uploader = User::factory()->create();
    $uploader->assignRole('Uploader');
    $index = fn () => Livewire::actingAs($uploader)->test(Index::class)->set('selectedSeries', [$this->alpha->id]);

    $index()->call('applySeriesBulk', 'delete')->assertForbidden();
    $index()->call('applySeriesBulk', 'private')->assertForbidden();

    expect($this->alpha->fresh())->not->toBeNull()
        ->and($this->alpha->fresh()->is_public)->toBeTrue();
});

test('changing filters or the page clears the selection', function () {
    $index = Livewire::actingAs($this->editor)->test(Index::class);

    $index->set('selectedSeries', [$this->alpha->id])->call('applyFilters')->assertSet('selectedSeries', []);
    $index->set('selectedSeries', [$this->alpha->id])->call('filterByGenre', $this->drama->id)->assertSet('selectedSeries', []);
    $index->set('selectedSeries', [$this->beta->id])->call('clearFilters')->assertSet('selectedSeries', []);
    $index->set('selectedSeries', [$this->alpha->id])->call('gotoPage', 2)->assertSet('selectedSeries', []);
});

test('the list shows a checkbox per series and the bulk controls', function () {
    Livewire::actingAs($this->editor)
        ->test(Index::class)
        ->assertSee('Select all series on this page', false)
        ->assertSee('aria-label="Select Alpha Signal"', false)
        ->set('selectedSeries', [$this->alpha->id, $this->beta->id])
        ->assertSee('2 series selected')
        ->assertSee('Permanently delete 2 series, with all their chapters, pages, comments, and media? This cannot be undone.', false)
        ->call('applySeriesBulk', 'private')
        ->assertSee('data-testid="series-notice"', false)
        ->assertSee('Made 1 series private. 1 was already private.');
});
