<?php

use App\Livewire\Admin\Uploader;
use App\Media\ImageProcessor;
use App\Models\Chapter;
use App\Models\Page;
use App\Models\Series;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File as TestFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('Administrator');
    $this->series = Series::create([
        'title' => 'Real Image Fixture Upload Smoke Test',
        'author' => 'Test fixture',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
    ]);

    Storage::fake('media');
});

test('real manga fixture uploads five complete chapters in page order', function () {
    $fixtureRoot = getenv('KOMA_REAL_IMAGE_FIXTURE') ?: base_path('test-data/real-images');
    $manifestPath = $fixtureRoot.'/manifest.json';

    $installedChapterDirectories = glob($fixtureRoot.'/chapter-*', GLOB_ONLYDIR) ?: [];

    if (! is_file($manifestPath) || $installedChapterDirectories === []) {
        $this->markTestSkipped('Local authorized real-image fixture is not installed.');
    }

    $manifest = json_decode(
        file_get_contents($manifestPath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($manifest['language'])->toBe('en')
        ->and($manifest['chapters'])->toHaveCount(5);

    $expectedPageTotal = 0;

    foreach ($manifest['chapters'] as $fixtureChapter) {
        $chapterDirectory = $fixtureRoot.'/'.$fixtureChapter['folder'];
        $sourcePages = glob($chapterDirectory.'/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [];
        sort($sourcePages, SORT_NATURAL);

        expect(is_dir($chapterDirectory))->toBeTrue()
            ->and($sourcePages)->toHaveCount($fixtureChapter['page_count']);

        $expectedPageTotal += count($sourcePages);
        $uploads = array_map(function (string $path): TestFile {
            $stream = fopen($path, 'rb');

            if ($stream === false) {
                throw new RuntimeException("Unable to open fixture page: {$path}");
            }

            return new TestFile(basename($path), $stream);
        }, $sourcePages);

        Livewire::actingAs($this->admin)
            ->test(Uploader::class)
            ->set('seriesId', $this->series->id)
            ->set('number', $fixtureChapter['number'])
            ->set('chapterTitle', $fixtureChapter['title'])
            ->set('scanlationGroup', $fixtureChapter['scanlation_group'])
            ->set('incoming', $uploads)
            ->assertCount('pages', $fixtureChapter['page_count'])
            ->call('publish')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.series.edit', $this->series));

        $chapter = Chapter::query()
            ->whereBelongsTo($this->series)
            ->where('number', $fixtureChapter['number'])
            ->with('pages')
            ->sole();

        expect($chapter->status)->toBe('published')
            ->and($chapter->title)->toBe($fixtureChapter['title'])
            ->and($chapter->scanlation_group)->toBe($fixtureChapter['scanlation_group'])
            ->and($chapter->pages)->toHaveCount($fixtureChapter['page_count'])
            ->and($chapter->pages->pluck('sort_order')->all())
            ->toBe(range(1, $fixtureChapter['page_count']));

        foreach ($chapter->pages as $index => $page) {
            $sourcePage = $sourcePages[$index];
            $storedBytes = Storage::disk('media')->get($page->image_path);
            $decoded = getimagesizefromstring($storedBytes);
            $sourceInfo = getimagesize($sourcePage);
            $expectedExtension = app(ImageProcessor::class)->webpAvailable()
                ? 'webp'
                : match ($sourceInfo['mime']) {
                    'image/png' => 'png',
                    default => 'jpg',
                };

            Storage::disk('media')->assertExists($page->image_path);

            expect($decoded)->toBeArray()
                ->and(pathinfo($page->image_path, PATHINFO_EXTENSION))->toBe($expectedExtension)
                ->and($page->width)->toBe($decoded[0])
                ->and($page->height)->toBe($decoded[1])
                ->and($page->width)->toBeLessThanOrEqual((int) config('koma.images.pages.max_width'))
                ->and($page->height)->toBeLessThanOrEqual((int) config('koma.images.pages.max_height'))
                ->and($page->file_size)->toBe(strlen($storedBytes));
        }
    }

    expect($expectedPageTotal)->toBe(64)
        ->and(Chapter::query()->count())->toBe(5)
        ->and(Page::query()->count())->toBe(64)
        ->and(Storage::disk('media')->allFiles())->toHaveCount(64);
})->group('real-images');
