<?php

use App\Livewire\Admin\Uploader;
use App\Media\ImageProcessor;
use App\Models\Chapter;
use App\Models\Page;
use App\Models\Series;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('Administrator');
    $this->series = Series::create([
        'title' => 'Upload Test Series',
        'author' => 'Test Author',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
    ]);

    Storage::fake('media');
});

test('chapter numbers are unique within a series at the database level', function () {
    $this->series->chapters()->create(['number' => 3, 'status' => 'draft']);

    expect(fn () => $this->series->chapters()->create(['number' => 3, 'status' => 'draft']))
        ->toThrow(QueryException::class);

    $otherSeries = Series::create([
        'title' => 'Second Upload Series',
        'author' => 'Test Author',
        'status' => 'ongoing',
        'reading_direction' => 'ltr',
        'content_rating' => 'safe',
    ]);

    expect($otherSeries->chapters()->create(['number' => 3, 'status' => 'draft']))
        ->toBeInstanceOf(Chapter::class);
});

test('uploader saves ordered pages using detected safe extensions', function () {
    $component = Livewire::actingAs($this->admin)
        ->test(Uploader::class)
        ->set('seriesId', $this->series->id)
        ->set('number', '2.5')
        ->set('chapterTitle', 'A reliable upload')
        ->set('incoming', [
            UploadedFile::fake()->image('first.JPEG', 800, 1200)->size(400),
            UploadedFile::fake()->image('second.png', 800, 1200)->size(500),
        ])
        ->assertCount('pages', 2);
    $temporaryFiles = $component->get('pages');

    $component
        ->call('saveDraft')
        ->assertRedirect(route('admin.series.edit', $this->series));

    $chapter = Chapter::query()->where('series_id', $this->series->id)->sole();
    $webp = app(ImageProcessor::class)->webpAvailable();

    expect($chapter->number)->toBe('2.50')
        ->and($chapter->status)->toBe('draft')
        ->and($chapter->pages)->toHaveCount(2)
        ->and($chapter->pages->pluck('sort_order')->all())->toBe([1, 2])
        ->and($chapter->pages->pluck('image_path')->all())->toBe([
            "pages/{$this->series->id}/{$chapter->id}/001.".($webp ? 'webp' : 'jpg'),
            "pages/{$this->series->id}/{$chapter->id}/002.".($webp ? 'webp' : 'png'),
        ]);

    foreach ($chapter->pages as $page) {
        Storage::disk('media')->assertExists($page->image_path);
        $bytes = Storage::disk('media')->get($page->image_path);
        $decoded = getimagesizefromstring($bytes);

        expect($decoded)->toBeArray()
            ->and($page->width)->toBe($decoded[0])
            ->and($page->height)->toBe($decoded[1])
            ->and($page->file_size)->toBe(strlen($bytes));
    }

    foreach ($temporaryFiles as $temporaryFile) {
        expect($temporaryFile->exists())->toBeFalse();
    }
});

test('uploader rolls back database rows and files when a page insert fails', function () {
    $createdPages = 0;

    Page::creating(function () use (&$createdPages) {
        if (++$createdPages === 2) {
            throw new \RuntimeException('Intentional page insert failure.');
        }
    });

    try {
        Livewire::actingAs($this->admin)
            ->test(Uploader::class)
            ->set('seriesId', $this->series->id)
            ->set('number', '4')
            ->set('incoming', [
                UploadedFile::fake()->image('first.jpg', 800, 1200),
                UploadedFile::fake()->image('second.jpg', 800, 1200),
            ])
            ->call('publish')
            ->assertHasErrors('pages')
            ->assertSee('No chapter or page files were kept.')
            ->assertCount('pages', 2)
            ->assertSet('seriesId', $this->series->id)
            ->assertSet('number', '4')
            ->assertSet('finalizing', false);
    } finally {
        Page::flushEventListeners();
    }

    expect(Chapter::query()->count())->toBe(0)
        ->and(Page::query()->count())->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBe([]);
});

test('uploader rejects unsupported oversized and excessive-dimension pages', function () {
    Livewire::actingAs($this->admin)
        ->test(Uploader::class)
        ->set('incoming', [UploadedFile::fake()->create('page.svg', 10, 'image/svg+xml')])
        ->assertCount('pages', 0)
        ->assertCount('failedFiles', 1)
        ->assertSee('must be a valid JPEG, PNG, or WebP image');

    Livewire::actingAs($this->admin)
        ->test(Uploader::class)
        ->set('incoming', [UploadedFile::fake()->image('large.jpg', 800, 1200)->size(20481)])
        ->assertCount('pages', 0)
        ->assertCount('failedFiles', 1)
        ->assertSee('exceeds the 20 MB limit');

    Livewire::actingAs($this->admin)
        ->test(Uploader::class)
        ->set('incoming', [UploadedFile::fake()->image('wide.jpg', 20001, 1)])
        ->assertCount('pages', 0)
        ->assertCount('failedFiles', 1)
        ->assertSee('exceeds the 20000×20000 source-dimension limit');
});

test('mixed selection preserves valid pages and records each invalid file', function () {
    Livewire::actingAs($this->admin)
        ->test(Uploader::class)
        ->set('incoming', [
            UploadedFile::fake()->image('first.jpg', 800, 1200),
            UploadedFile::fake()->create('broken.svg', 10, 'image/svg+xml'),
            UploadedFile::fake()->image('third.png', 800, 1200),
        ])
        ->assertCount('pages', 2)
        ->assertCount('pageSequences', 2)
        ->assertCount('failedFiles', 1)
        ->assertSee('first.jpg')
        ->assertSee('third.png')
        ->assertSee('broken.svg')
        ->assertSee('Retry selection');
});

test('retry replaces a failed selection in its original page-order slot', function () {
    $component = Livewire::actingAs($this->admin)
        ->test(Uploader::class)
        ->set('incoming', [
            UploadedFile::fake()->image('first.jpg', 800, 1200),
            UploadedFile::fake()->create('broken.svg', 10, 'image/svg+xml'),
            UploadedFile::fake()->image('third.png', 800, 1200),
        ]);

    $failureId = $component->get('failedFiles')[0]['id'];

    $component
        ->set("retryIncoming.{$failureId}", UploadedFile::fake()->image('second.webp', 800, 1200))
        ->assertCount('pages', 3)
        ->assertCount('failedFiles', 0);

    expect(collect($component->get('pages'))->map->getClientOriginalName()->all())
        ->toBe(['first.jpg', 'second.webp', 'third.png']);
});

test('failed selections block final saving until they are dismissed or retried', function () {
    $component = Livewire::actingAs($this->admin)
        ->test(Uploader::class)
        ->set('seriesId', $this->series->id)
        ->set('number', '8')
        ->set('incoming', [
            UploadedFile::fake()->image('ready.jpg', 800, 1200),
            UploadedFile::fake()->create('broken.svg', 10, 'image/svg+xml'),
        ])
        ->call('publish')
        ->assertHasErrors('failedFiles')
        ->assertCount('pages', 1)
        ->assertCount('failedFiles', 1)
        ->assertSet('number', '8')
        ->assertSet('finalizing', false);

    expect(Chapter::query()->count())->toBe(0);

    $failureId = $component->get('failedFiles')[0]['id'];

    $component
        ->call('dismissFailed', $failureId)
        ->call('publish')
        ->assertRedirect(route('admin.series.edit', $this->series));

    expect(Chapter::query()->count())->toBe(1);
});

test('clear session removes ready failed and retry state', function () {
    $component = Livewire::actingAs($this->admin)
        ->test(Uploader::class)
        ->set('incoming', [
            UploadedFile::fake()->image('ready.jpg', 800, 1200),
            UploadedFile::fake()->create('broken.svg', 10, 'image/svg+xml'),
        ]);
    $temporary = $component->get('pages')[0];

    expect($temporary->exists())->toBeTrue();

    $component
        ->call('clearPages')
        ->assertCount('pages', 0)
        ->assertCount('pageSequences', 0)
        ->assertCount('failedFiles', 0)
        ->assertCount('retryIncoming', 0)
        ->assertSet('nextPageSequence', 1)
        ->assertSet('nextFailureId', 1)
        ->assertSet('finalizing', false);

    expect($temporary->exists())->toBeFalse();
});

test('removing a ready page discards its temporary file and preserves remaining order', function () {
    $component = Livewire::actingAs($this->admin)
        ->test(Uploader::class)
        ->set('incoming', [
            UploadedFile::fake()->image('first.jpg', 800, 1200),
            UploadedFile::fake()->image('second.jpg', 800, 1200),
        ]);
    $removed = $component->get('pages')[0];

    $component
        ->call('removePage', 0)
        ->assertCount('pages', 1)
        ->assertCount('pageSequences', 1);

    expect($removed->exists())->toBeFalse()
        ->and($component->get('pages')[0]->getClientOriginalName())->toBe('second.jpg');
});

test('a duplicate finalization request is ignored before persistence', function () {
    Livewire::actingAs($this->admin)
        ->test(Uploader::class)
        ->set('seriesId', $this->series->id)
        ->set('number', '9')
        ->set('incoming', [UploadedFile::fake()->image('ready.jpg', 800, 1200)])
        ->set('finalizing', true)
        ->call('publish')
        ->assertNoRedirect();

    expect(Chapter::query()->count())->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBe([]);
});
