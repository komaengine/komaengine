<?php

use App\Livewire\Admin\Series\Edit;
use App\Models\Chapter;
use App\Models\Comment;
use App\Models\Series;
use App\Models\User;
use App\Publishing\ChapterDeletionService;
use App\Support\Facades\Hook;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // A whole second, so dates compare equal after a database round trip.
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00 UTC'));
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('media');
    $this->editor = User::factory()->create();
    $this->editor->assignRole('Editor');

    $this->series = Series::create(['title' => 'Bulk Series', 'status' => 'ongoing', 'reading_direction' => 'rtl', 'content_rating' => 'safe', 'is_public' => true]);
    $this->draft = Chapter::create(['series_id' => $this->series->id, 'number' => 1, 'status' => Chapter::STATUS_DRAFT]);
    $this->scheduled = Chapter::create(['series_id' => $this->series->id, 'number' => 2]);
    $this->scheduled->scheduleFor(now()->addDay());
    $this->published = Chapter::create(['series_id' => $this->series->id, 'number' => 3]);
    $this->published->publish();

    foreach ([$this->draft, $this->scheduled, $this->published] as $chapter) {
        $path = "pages/bulk/{$chapter->id}.webp";
        $chapter->pages()->create(['sort_order' => 1, 'image_path' => $path]);
        Storage::disk('media')->put($path, 'fixture');
        $chapter->comments()->create(['body' => "Comment on {$chapter->id}", 'status' => Comment::STATUS_APPROVED]);
    }

    $this->other = Series::create(['title' => 'Other Series', 'status' => 'ongoing', 'is_public' => true]);
    $this->foreign = Chapter::create(['series_id' => $this->other->id, 'number' => 1, 'status' => Chapter::STATUS_DRAFT]);
});

function chapterCommentsExist(Chapter $chapter): bool
{
    return Comment::withTrashed()
        ->where('commentable_type', Chapter::class)
        ->where('commentable_id', $chapter->id)
        ->exists();
}

test('deleting one chapter removes its pages, comments, and exact media files', function () {
    Livewire::actingAs($this->editor)
        ->test(Edit::class, ['series' => $this->series])
        ->call('deleteChapter', $this->draft->id)
        ->assertHasNoErrors()
        ->assertSet('chapterNotice', 'Chapter deleted.')->assertSet('chapterNoticeIsError', false);

    expect(Chapter::query()->find($this->draft->id))->toBeNull()
        ->and($this->draft->pages()->count())->toBe(0)
        ->and(chapterCommentsExist($this->draft))->toBeFalse()
        ->and(chapterCommentsExist($this->published))->toBeTrue();
    Storage::disk('media')->assertMissing("pages/bulk/{$this->draft->id}.webp");
    Storage::disk('media')->assertExists("pages/bulk/{$this->published->id}.webp");
});

test('chapter actions require permission to edit series', function () {
    $uploader = User::factory()->create();
    $uploader->assignRole('Uploader');
    $editor = fn () => Livewire::actingAs($uploader)->test(Edit::class, ['series' => $this->series]);

    $editor()->call('deleteChapter', $this->draft->id)->assertForbidden();
    $editor()->call('togglePublish', $this->draft->id)->assertForbidden();
    $editor()->call('cancelSchedule', $this->scheduled->id)->assertForbidden();
    $editor()->set('selectedChapters', [$this->draft->id])->call('applyChapterBulk', 'delete')->assertForbidden();

    expect(Chapter::query()->find($this->draft->id))->not->toBeNull()
        ->and($this->draft->fresh()->status)->toBe(Chapter::STATUS_DRAFT);
});

test('bulk publish publishes drafts and scheduled chapters now and leaves published ones alone', function () {
    $publishedHooks = [];
    Hook::addAction('chapter.published', function (Chapter $chapter) use (&$publishedHooks) {
        $publishedHooks[] = $chapter->id;
    });
    $originalDate = $this->published->published_at;

    Livewire::actingAs($this->editor)
        ->test(Edit::class, ['series' => $this->series])
        ->set('selectedChapters', [$this->draft->id, $this->scheduled->id, $this->published->id])
        ->call('applyChapterBulk', 'publish')
        ->assertHasNoErrors()
        ->assertSet('selectedChapters', [])
        ->assertSet('chapterNotice', 'Published 2 chapters. 1 was already published.')->assertSet('chapterNoticeIsError', false);

    expect($this->draft->fresh()->status)->toBe(Chapter::STATUS_PUBLISHED)
        ->and($this->draft->fresh()->published_at->equalTo(now()))->toBeTrue()
        ->and($this->scheduled->fresh()->status)->toBe(Chapter::STATUS_PUBLISHED)
        ->and($this->scheduled->fresh()->published_at->equalTo(now()))->toBeTrue()
        ->and($this->published->fresh()->published_at->equalTo($originalDate))->toBeTrue()
        ->and($publishedHooks)->toEqualCanonicalizing([$this->draft->id, $this->scheduled->id]);

    Livewire::actingAs($this->editor)
        ->test(Edit::class, ['series' => $this->series])
        ->set('selectedChapters', [$this->published->id])
        ->call('applyChapterBulk', 'publish')
        ->assertSet('chapterNotice', 'The selected chapter was already published.');
});

test('bulk unpublish returns chapters to draft and cancels schedules', function () {
    Livewire::actingAs($this->editor)
        ->test(Edit::class, ['series' => $this->series])
        ->set('selectedChapters', [$this->scheduled->id, $this->published->id])
        ->call('applyChapterBulk', 'unpublish')
        ->assertHasNoErrors()
        ->assertSet('chapterNotice', 'Returned 2 chapters to draft.')->assertSet('chapterNoticeIsError', false);

    foreach ([$this->scheduled, $this->published] as $chapter) {
        expect($chapter->fresh()->status)->toBe(Chapter::STATUS_DRAFT)
            ->and($chapter->fresh()->published_at)->toBeNull();
    }
});

test('bulk delete removes the selected chapters only', function () {
    Livewire::actingAs($this->editor)
        ->test(Edit::class, ['series' => $this->series])
        ->set('selectedChapters', [$this->draft->id, $this->published->id])
        ->call('applyChapterBulk', 'delete')
        ->assertHasNoErrors()
        ->assertSet('chapterNotice', 'Deleted 2 chapters.')->assertSet('chapterNoticeIsError', false);

    expect(Chapter::query()->whereIn('id', [$this->draft->id, $this->published->id])->exists())->toBeFalse()
        ->and(Chapter::query()->find($this->scheduled->id))->not->toBeNull()
        ->and(chapterCommentsExist($this->draft))->toBeFalse()
        ->and(chapterCommentsExist($this->scheduled))->toBeTrue();
    Storage::disk('media')->assertMissing("pages/bulk/{$this->draft->id}.webp");
    Storage::disk('media')->assertMissing("pages/bulk/{$this->published->id}.webp");
    Storage::disk('media')->assertExists("pages/bulk/{$this->scheduled->id}.webp");
});

test('a selection with a chapter from another series changes nothing', function () {
    Livewire::actingAs($this->editor)
        ->test(Edit::class, ['series' => $this->series])
        ->set('selectedChapters', [$this->draft->id, $this->foreign->id])
        ->call('applyChapterBulk', 'delete')
        ->assertHasErrors('selectedChapters');

    expect(Chapter::query()->find($this->draft->id))->not->toBeNull()
        ->and(Chapter::query()->find($this->foreign->id))->not->toBeNull();
});

test('a bulk action needs a selection', function () {
    Livewire::actingAs($this->editor)
        ->test(Edit::class, ['series' => $this->series])
        ->call('applyChapterBulk', 'publish')
        ->assertHasErrors(['selectedChapters' => 'required']);
});

test('the editor shows a checkbox per chapter and the bulk controls', function () {
    Livewire::actingAs($this->editor)
        ->test(Edit::class, ['series' => $this->series])
        ->assertSee('Select all chapters', false)
        ->assertSee('aria-label="Select chapter 1"', false)
        ->set('selectedChapters', [$this->draft->id, $this->scheduled->id])
        ->assertSee('2 chapters selected')
        ->assertSee('Delete 2 chapters and all their pages? This cannot be undone.', false)
        ->call('applyChapterBulk', 'unpublish')
        ->assertSee('data-testid="chapter-notice"', false)
        ->assertSee('Returned 1 chapter to draft. 1 was already in draft.');
});

test('a media cleanup failure is reported after the chapter rows are deleted', function () {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('delete')->once()->andReturnFalse();
    Storage::shouldReceive('disk')->with('media')->andReturn($disk);

    $result = app(ChapterDeletionService::class)->deleteMany($this->series, [$this->draft->id]);

    expect($result)->toBeFalse()
        ->and(Chapter::query()->find($this->draft->id))->toBeNull();
});
