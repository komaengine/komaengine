<?php

use App\Livewire\Admin\Chapters\Pages as ChapterPages;
use App\Media\ImageProcessor;
use App\Models\Chapter;
use App\Models\Page;
use App\Models\Series;
use App\Models\User;
use App\Publishing\ChapterPageService;
use App\Support\Facades\Hook;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->editor = User::factory()->create();
    $this->editor->assignRole('Editor');
    $this->uploader = User::factory()->create();
    $this->uploader->assignRole('Uploader');

    $this->series = Series::create([
        'title' => 'Page Manager Series',
        'slug' => 'page-manager-series',
        'author' => 'Test Author',
        'status' => 'ongoing',
        'reading_direction' => 'ltr',
        'content_rating' => 'safe',
        'is_public' => true,
    ]);

    $this->chapter = $this->series->chapters()->create([
        'number' => 1,
        'title' => 'Managed chapter',
        'status' => Chapter::STATUS_PUBLISHED,
        'published_at' => now()->subMinute(),
    ]);

    Storage::fake('media');
    $this->pages = collect();

    foreach (range(1, 3) as $sortOrder) {
        $upload = UploadedFile::fake()->image("page-{$sortOrder}.jpg", 600 + $sortOrder, 900 + $sortOrder);
        $path = $upload->storeAs(
            "pages/{$this->series->id}/{$this->chapter->id}",
            str_pad((string) $sortOrder, 3, '0', STR_PAD_LEFT).'.jpg',
            'media',
        );

        $this->pages->push(Page::create([
            'chapter_id' => $this->chapter->id,
            'sort_order' => $sortOrder,
            'image_path' => $path,
            'width' => 600 + $sortOrder,
            'height' => 900 + $sortOrder,
            'file_size' => $upload->getSize(),
        ]));
    }
});

test('page manager requires series editing permission and scoped chapter ownership', function () {
    $route = route('admin.series.chapters.pages', [$this->series, $this->chapter]);

    $this->get($route)->assertRedirect(route('login'));
    $this->actingAs($this->uploader)->get($route)->assertForbidden();
    $this->actingAs($this->editor)->get($route)
        ->assertOk()
        ->assertSee('Chapter 1 pages')
        ->assertSee('Move page 1 earlier');

    $otherSeries = Series::create([
        'title' => 'Other Series',
        'slug' => 'other-series',
        'author' => 'Test Author',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
    ]);

    $this->actingAs($this->editor)
        ->get(route('admin.series.chapters.pages', [$otherSeries, $this->chapter]))
        ->assertNotFound();
});

test('editors can reorder pages with normalized sort values and reader order updates', function () {
    $pageSavedHooks = 0;
    $chapterSavedHooks = 0;
    Hook::addAction('page.saved', function () use (&$pageSavedHooks) {
        $pageSavedHooks++;
    });
    Hook::addAction('chapter.saved', function () use (&$chapterSavedHooks) {
        $chapterSavedHooks++;
    });

    Livewire::actingAs($this->editor)
        ->test(ChapterPages::class, ['series' => $this->series, 'chapter' => $this->chapter])
        ->call('movePage', $this->pages[0]->id, 'later')
        ->assertHasNoErrors();

    $orderedPages = $this->chapter->pages()->get();

    expect($orderedPages->pluck('id')->all())->toBe([
        $this->pages[1]->id,
        $this->pages[0]->id,
        $this->pages[2]->id,
    ])->and($orderedPages->pluck('sort_order')->all())->toBe([1, 2, 3])
        ->and($orderedPages->pluck('image_path')->sort()->values()->all())
        ->toBe($this->pages->pluck('image_path')->sort()->values()->all())
        ->and($pageSavedHooks)->toBe(3)
        ->and($chapterSavedHooks)->toBe(1);

    $this->get(route('reader', [$this->series, $this->chapter->displayNumber()]))
        ->assertOk()
        ->assertSeeInOrder($orderedPages->map->url()->all(), false);
});

test('drag-style page drops persist the intended order', function () {
    Livewire::actingAs($this->editor)
        ->test(ChapterPages::class, ['series' => $this->series, 'chapter' => $this->chapter])
        ->call('dropPage', $this->pages[0]->id, $this->pages[2]->id)
        ->assertHasNoErrors();

    expect($this->chapter->pages()->pluck('id')->all())->toBe([
        $this->pages[1]->id,
        $this->pages[2]->id,
        $this->pages[0]->id,
    ]);
});

test('replacement stores the new page before swapping and removes the old file after success', function () {
    $oldPath = $this->pages[1]->image_path;
    $pageSavedHooks = 0;
    Hook::addAction('page.saved', function () use (&$pageSavedHooks) {
        $pageSavedHooks++;
    });

    Livewire::actingAs($this->editor)
        ->test(ChapterPages::class, ['series' => $this->series, 'chapter' => $this->chapter])
        ->set("replacementFiles.{$this->pages[1]->id}", UploadedFile::fake()->image('fixed.PNG', 640, 1000))
        ->call('replacePage', $this->pages[1]->id)
        ->assertHasNoErrors();

    $replacement = $this->pages[1]->fresh();
    $expectedExtension = app(ImageProcessor::class)->webpAvailable() ? 'webp' : 'png';
    $replacementBytes = Storage::disk('media')->get($replacement->image_path);

    expect($replacement->image_path)->not->toBe($oldPath)
        ->and($replacement->image_path)->toEndWith('.'.$expectedExtension)
        ->and($replacement->sort_order)->toBe(2)
        ->and($replacement->width)->toBe(640)
        ->and($replacement->height)->toBe(1000)
        ->and($replacement->file_size)->toBe(strlen($replacementBytes))
        ->and(getimagesizefromstring($replacementBytes))->toBeArray()
        ->and($pageSavedHooks)->toBe(1);

    Storage::disk('media')->assertExists($replacement->image_path);
    Storage::disk('media')->assertMissing($oldPath);
});

test('invalid replacement keeps the old row and file readable', function () {
    $page = $this->pages[1];

    Livewire::actingAs($this->editor)
        ->test(ChapterPages::class, ['series' => $this->series, 'chapter' => $this->chapter])
        ->set("replacementFiles.{$page->id}", UploadedFile::fake()->create('bad.svg', 10, 'image/svg+xml'))
        ->call('replacePage', $page->id)
        ->assertHasErrors("replacementFiles.{$page->id}");

    expect($page->fresh()->image_path)->toBe($page->image_path);
    Storage::disk('media')->assertExists($page->image_path);
});

test('database replacement failure cleans the new file and leaves the old reader intact', function () {
    $page = $this->pages[1];
    $originalFiles = Storage::disk('media')->allFiles();

    Page::updating(function () {
        throw new RuntimeException('Intentional replacement database failure.');
    });

    try {
        Livewire::actingAs($this->editor)
            ->test(ChapterPages::class, ['series' => $this->series, 'chapter' => $this->chapter])
            ->set("replacementFiles.{$page->id}", UploadedFile::fake()->image('replacement.jpg', 700, 1100))
            ->call('replacePage', $page->id)
            ->assertHasErrors("replacementFiles.{$page->id}")
            ->assertSee('The existing page is unchanged.');
    } finally {
        Page::flushEventListeners();
    }

    expect($page->fresh()->image_path)->toBe($page->image_path)
        ->and(Storage::disk('media')->allFiles())->toBe($originalFiles);

    $this->get(route('reader', [$this->series, $this->chapter->displayNumber()]))
        ->assertOk()
        ->assertSee($page->url(), false);
});

test('storage replacement failure leaves the old page untouched', function () {
    Storage::fake('media');
    $oldPath = 'legacy/original.jpg';
    Storage::disk('media')->put($oldPath, 'still-readable');
    Storage::disk('media')->put('pages', 'blocks replacement directory creation');
    $page = $this->pages[0];
    $page->update(['image_path' => $oldPath]);

    Livewire::actingAs($this->editor)
        ->test(ChapterPages::class, ['series' => $this->series, 'chapter' => $this->chapter])
        ->set("replacementFiles.{$page->id}", UploadedFile::fake()->image('replacement.jpg', 700, 1100))
        ->call('replacePage', $page->id)
        ->assertHasErrors("replacementFiles.{$page->id}");

    expect($page->fresh()->image_path)->toBe($oldPath);
    Storage::disk('media')->assertExists($oldPath);
});

test('deleting a page removes its file and normalizes the remaining reader order', function () {
    $deletedPath = $this->pages[1]->image_path;
    $deletedHooks = 0;
    Hook::addAction('page.deleted', function () use (&$deletedHooks) {
        $deletedHooks++;
    });

    Livewire::actingAs($this->editor)
        ->test(ChapterPages::class, ['series' => $this->series, 'chapter' => $this->chapter])
        ->call('deletePage', $this->pages[1]->id)
        ->assertHasNoErrors();

    $remaining = $this->chapter->pages()->get();

    expect($remaining->pluck('id')->all())->toBe([$this->pages[0]->id, $this->pages[2]->id])
        ->and($remaining->pluck('sort_order')->all())->toBe([1, 2])
        ->and($deletedHooks)->toBe(1);
    Storage::disk('media')->assertMissing($deletedPath);

    $this->get(route('reader', [$this->series, $this->chapter->displayNumber()]))
        ->assertOk()
        ->assertSeeInOrder($remaining->map->url()->all(), false);
});

test('the final page cannot be deleted even through a direct action call', function () {
    foreach ($this->pages->slice(1) as $page) {
        Storage::disk('media')->delete($page->image_path);
        $page->delete();
    }

    $lastPage = $this->pages[0];

    Livewire::actingAs($this->editor)
        ->test(ChapterPages::class, ['series' => $this->series, 'chapter' => $this->chapter])
        ->call('deletePage', $lastPage->id)
        ->assertHasErrors('pageOrder')
        ->assertSee('A chapter must retain at least one page.');

    expect($lastPage->fresh())->not->toBeNull();
    Storage::disk('media')->assertExists($lastPage->image_path);
});

test('service rejects incomplete reorder payloads without changing any rows', function () {
    expect(fn () => app(ChapterPageService::class)->reorder($this->chapter, [
        $this->pages[0]->id,
        $this->pages[1]->id,
    ]))->toThrow(InvalidArgumentException::class);

    expect($this->chapter->pages()->pluck('id')->all())->toBe($this->pages->pluck('id')->all())
        ->and($this->chapter->pages()->pluck('sort_order')->all())->toBe([1, 2, 3]);
});

test('post-commit hook failures do not misreport a durable page mutation', function () {
    Hook::addAction('page.saved', function () {
        throw new RuntimeException('Intentional plugin hook failure.');
    });

    Livewire::actingAs($this->editor)
        ->test(ChapterPages::class, ['series' => $this->series, 'chapter' => $this->chapter])
        ->call('movePage', $this->pages[0]->id, 'later')
        ->assertHasNoErrors();

    expect($this->chapter->pages()->pluck('id')->all())->toBe([
        $this->pages[1]->id,
        $this->pages[0]->id,
        $this->pages[2]->id,
    ]);
});
