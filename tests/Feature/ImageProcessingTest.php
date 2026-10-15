<?php

use App\Install\InstallerSetupAuthority;
use App\Livewire\Admin\Series\Edit;
use App\Livewire\Admin\Uploader;
use App\Livewire\Install\Installer;
use App\Media\ImageProcessingException;
use App\Media\ImageProcessor;
use App\Models\Page;
use App\Models\Series;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('media');
});

test('page processing corrects orientation and resizes within configured bounds', function () {
    config()->set('koma.images.pages.max_width', 600);
    config()->set('koma.images.pages.max_height', 800);

    $processor = new class(null, true) extends ImageProcessor
    {
        protected function orientationFor(UploadedFile $file): int
        {
            return 6;
        }
    };

    $processed = $processor->processPage(
        UploadedFile::fake()->image('rotated.jpg', 1200, 1800),
    );
    $decoded = getimagesizefromstring($processed->bytes);

    expect($processed->extension)->toBe($processor->webpAvailable() ? 'webp' : 'jpg')
        ->and($processed->mimeType)->toBe($processor->webpAvailable() ? 'image/webp' : 'image/jpeg')
        ->and($processed->width)->toBe(600)
        ->and($processed->height)->toBe(400)
        ->and($decoded)->toBeArray()
        ->and($decoded[0])->toBe($processed->width)
        ->and($decoded[1])->toBe($processed->height)
        ->and(strlen($processed->bytes))->toBe($processed->fileSize());
});

test('JPEG EXIF orientation is read from the upload when the extension is available', function () {
    if (! function_exists('exif_read_data')) {
        $this->markTestSkipped('The EXIF extension is not installed.');
    }

    config()->set('koma.images.pages.max_width', 1000);
    config()->set('koma.images.pages.max_height', 1000);
    $source = UploadedFile::fake()->image('camera.jpg', 400, 700);
    $oriented = UploadedFile::fake()->createWithContent(
        'camera-oriented.jpg',
        imageTestJpegWithOrientation(file_get_contents($source->getRealPath()), 6),
    );
    $exif = exif_read_data($oriented->getRealPath(), 'IFD0', true, false);
    $processed = app(ImageProcessor::class)->processPage($oriented);

    expect((int) data_get($exif, 'IFD0.Orientation'))->toBe(6)
        ->and([$processed->width, $processed->height])->toBe([700, 400]);
});

test('page processing never upscales smaller source images', function () {
    config()->set('koma.images.pages.max_width', 2400);
    config()->set('koma.images.pages.max_height', 4000);

    $processed = app(ImageProcessor::class)->processPage(
        UploadedFile::fake()->image('small.png', 320, 480),
    );

    expect($processed->width)->toBe(320)
        ->and($processed->height)->toBe(480);
});

test('source-format fallback encodes JPEG and PNG when WebP is unavailable', function () {
    $processor = new ImageProcessor(false, false);
    $jpeg = $processor->processPage(UploadedFile::fake()->image('page.jpg', 400, 600));
    $png = $processor->processPage(UploadedFile::fake()->image('page.png', 400, 600));

    expect($jpeg->extension)->toBe('jpg')
        ->and($jpeg->mimeType)->toBe('image/jpeg')
        ->and($png->extension)->toBe('png')
        ->and($png->mimeType)->toBe('image/png')
        ->and(getimagesizefromstring($jpeg->bytes))->toBeArray()
        ->and(getimagesizefromstring($png->bytes))->toBeArray();
});

test('WebP fallback preserves an already bounded source and rejects derivative work without an encoder', function () {
    $processor = new ImageProcessor(false, false);
    $source = UploadedFile::fake()->image('existing.webp', 400, 600);
    $sourceBytes = file_get_contents($source->getRealPath());
    $page = $processor->processPage($source);

    expect($page->extension)->toBe('webp')
        ->and($page->mimeType)->toBe('image/webp')
        ->and($page->bytes)->toBe($sourceBytes)
        ->and(fn () => $processor->processCover($source))
        ->toThrow(ImageProcessingException::class, 'requires WebP encoding support');
});

test('corrupt and animated image payloads are rejected before persistence', function () {
    $processor = app(ImageProcessor::class);
    $validPng = UploadedFile::fake()->image('valid.png', 100, 140);
    $animatedBytes = file_get_contents($validPng->getRealPath()).'acTL';

    expect(fn () => $processor->processPage(
        UploadedFile::fake()->createWithContent('corrupt.jpg', 'not an image'),
    ))->toThrow(ImageProcessingException::class, 'JPEG, PNG, and WebP')
        ->and(fn () => $processor->processPage(
            UploadedFile::fake()->createWithContent('animated.png', $animatedBytes),
        ))->toThrow(ImageProcessingException::class, 'Animated images');

    expect(Storage::disk('media')->allFiles())->toBe([]);
});

test('cover processing enforces crop bounds and creates fixed-aspect derivatives', function () {
    $processor = app(ImageProcessor::class);
    $source = UploadedFile::fake()->image('landscape.jpg', 3000, 2100);
    $processed = $processor->processCover($source, 0.8, 0.25, 1.25);

    expect([$processed->cover->width, $processed->cover->height])->toBe([1000, 1400])
        ->and([$processed->thumbnail->width, $processed->thumbnail->height])->toBe([300, 420])
        ->and($processed->cover->width / $processed->cover->height)->toEqualWithDelta(5 / 7, 0.001)
        ->and($processed->thumbnail->width / $processed->thumbnail->height)->toEqualWithDelta(5 / 7, 0.001)
        ->and(getimagesizefromstring($processed->cover->bytes))->toBeArray()
        ->and(getimagesizefromstring($processed->thumbnail->bytes))->toBeArray();

    expect(fn () => $processor->processCover($source, 1.01, 0.5, 1))
        ->toThrow(ImageProcessingException::class, 'outside the allowed range')
        ->and(fn () => $processor->processCover($source, 0.5, 0.5, 3.01))
        ->toThrow(ImageProcessingException::class, 'outside the allowed range');
});

test('cover processing does not upscale undersized source images', function () {
    $processed = app(ImageProcessor::class)->processCover(
        UploadedFile::fake()->image('small-cover.png', 100, 140),
    );

    expect([$processed->cover->width, $processed->cover->height])->toBe([100, 140])
        ->and([$processed->thumbnail->width, $processed->thumbnail->height])->toBe([100, 140]);
});

test('series cover replacement stores both derivatives and deletes old media only after commit', function () {
    $this->seed(RolePermissionSeeder::class);
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $series = Series::create([
        'title' => 'Cover Workflow',
        'slug' => 'cover-workflow',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
        'cover_path' => 'covers/legacy-cover.jpg',
        'cover_thumbnail_path' => 'covers/legacy-thumbnail.jpg',
    ]);
    Storage::disk('media')->put($series->cover_path, 'legacy cover');
    Storage::disk('media')->put($series->cover_thumbnail_path, 'legacy thumbnail');

    Livewire::actingAs($editor)
        ->test(Edit::class, ['series' => $series])
        ->set('cover', UploadedFile::fake()->image('portrait.png', 1400, 2000))
        ->set('coverCropX', 0.35)
        ->set('coverCropY', 0.65)
        ->set('coverCropZoom', 1.2)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.series.edit', $series));

    $series->refresh();
    $coverBytes = Storage::disk('media')->get($series->cover_path);
    $thumbnailBytes = Storage::disk('media')->get($series->cover_thumbnail_path);

    expect($series->cover_path)->not->toBe('covers/legacy-cover.jpg')
        ->and($series->cover_thumbnail_path)->not->toBe('covers/legacy-thumbnail.jpg')
        ->and([$series->cover_width, $series->cover_height])->toBe([1000, 1400])
        ->and([$series->cover_thumbnail_width, $series->cover_thumbnail_height])->toBe([300, 420])
        ->and($series->cover_file_size)->toBe(strlen($coverBytes))
        ->and($series->cover_thumbnail_file_size)->toBe(strlen($thumbnailBytes))
        ->and(getimagesizefromstring($coverBytes))->toBeArray()
        ->and(getimagesizefromstring($thumbnailBytes))->toBeArray();

    Storage::disk('media')->assertExists($series->cover_path);
    Storage::disk('media')->assertExists($series->cover_thumbnail_path);
    Storage::disk('media')->assertMissing('covers/legacy-cover.jpg');
    Storage::disk('media')->assertMissing('covers/legacy-thumbnail.jpg');
});

test('series database failure discards prepared derivatives and preserves existing cover files', function () {
    $this->seed(RolePermissionSeeder::class);
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $series = Series::create([
        'title' => 'Cover Rollback',
        'slug' => 'cover-rollback',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
        'cover_path' => 'covers/original.jpg',
        'cover_thumbnail_path' => 'covers/original-thumb.jpg',
    ]);
    Storage::disk('media')->put($series->cover_path, 'original cover');
    Storage::disk('media')->put($series->cover_thumbnail_path, 'original thumbnail');

    Series::updating(function () {
        throw new RuntimeException('Intentional series update failure.');
    });

    try {
        Livewire::actingAs($editor)
            ->test(Edit::class, ['series' => $series])
            ->set('cover', UploadedFile::fake()->image('replacement.jpg', 1400, 2000))
            ->call('save')
            ->assertHasErrors('cover')
            ->assertSee('No media changes were kept.');
    } finally {
        Series::flushEventListeners();
        Model::clearBootedModels();
    }

    expect($series->fresh()->cover_path)->toBe('covers/original.jpg')
        ->and($series->fresh()->cover_thumbnail_path)->toBe('covers/original-thumb.jpg')
        ->and(Storage::disk('media')->allFiles())->toBe([
            'covers/original-thumb.jpg',
            'covers/original.jpg',
        ]);
});

test('series cover storage failure leaves the existing database row and files untouched', function () {
    $this->seed(RolePermissionSeeder::class);
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $series = Series::create([
        'title' => 'Cover Storage Rollback',
        'slug' => 'cover-storage-rollback',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
        'cover_path' => 'legacy/original.jpg',
        'cover_thumbnail_path' => 'legacy/original-thumb.jpg',
    ]);
    Storage::disk('media')->put($series->cover_path, 'original cover');
    Storage::disk('media')->put($series->cover_thumbnail_path, 'original thumbnail');
    Storage::disk('media')->put('covers', 'blocks cover directory creation');

    Livewire::actingAs($editor)
        ->test(Edit::class, ['series' => $series])
        ->set('cover', UploadedFile::fake()->image('replacement.jpg', 1400, 2000))
        ->call('save')
        ->assertHasErrors('cover')
        ->assertSee('existing cover is unchanged');

    expect($series->fresh()->cover_path)->toBe('legacy/original.jpg')
        ->and($series->fresh()->cover_thumbnail_path)->toBe('legacy/original-thumb.jpg');
    Storage::disk('media')->assertExists('legacy/original.jpg');
    Storage::disk('media')->assertExists('legacy/original-thumb.jpg');
});

test('legacy cover and page paths remain readable without generated metadata', function () {
    $series = Series::create([
        'title' => 'Legacy Media',
        'slug' => 'legacy-media',
        'cover_path' => 'legacy/cover.jpg',
    ]);
    $chapter = $series->chapters()->create(['number' => 1, 'status' => 'draft']);
    $page = Page::create([
        'chapter_id' => $chapter->id,
        'sort_order' => 1,
        'image_path' => 'legacy/page.jpg',
    ]);

    expect($series->coverThumbnailUrl())->toBe($series->coverUrl())
        ->and($page->url())->toBe(route('media.page', [$series, $chapter, $page]));
});

test('installer reports required GD and optional WebP capability explicitly', function () {
    session()->put('koma.installer.setup_grant', app(InstallerSetupAuthority::class)->sessionGrant());
    $requirements = Livewire::test(Installer::class)->instance()->requirements();
    $gd = collect($requirements)->firstWhere('label', 'GD image processing');
    $webp = collect($requirements)->firstWhere('label', 'WebP optimization');

    expect($gd)->not->toBeNull()
        ->and($gd['required'])->toBeTrue()
        ->and($gd['ok'])->toBe(extension_loaded('gd'))
        ->and($webp)->not->toBeNull()
        ->and($webp['required'])->toBeFalse()
        ->and($webp['value'])->toBe(function_exists('imagewebp') ? 'available' : 'source-format fallback');
});

test('admin uploader surfaces a missing GD capability instead of promising optimization', function () {
    $this->seed(RolePermissionSeeder::class);
    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    Series::create([
        'title' => 'Capability Surface',
        'slug' => 'capability-surface',
    ]);
    app()->instance(ImageProcessor::class, new class extends ImageProcessor
    {
        public function gdAvailable(): bool
        {
            return false;
        }

        public function webpAvailable(): bool
        {
            return false;
        }
    });

    Livewire::actingAs($editor)
        ->test(Uploader::class)
        ->assertSee('Image uploads are unavailable because the PHP GD extension is missing.');
});

function imageTestJpegWithOrientation(string $jpeg, int $orientation): string
{
    $tiff = "II\x2A\x00\x08\x00\x00\x00"
        ."\x01\x00"
        ."\x12\x01\x03\x00\x01\x00\x00\x00"
        .pack('v', $orientation)."\x00\x00"
        ."\x00\x00\x00\x00";
    $payload = "Exif\x00\x00".$tiff;
    $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

    return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
}
