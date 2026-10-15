<?php

use App\Archives\ArchiveException;
use App\Archives\ArchiveValidationException;
use App\Archives\KomaArchiveV1;
use App\Archives\KomaArchiveValidator;
use App\Archives\SeriesArchiveExporter;
use App\Install\InstallerSetupAuthority;
use App\Livewire\Install\Installer;
use App\Models\Chapter;
use App\Models\Genre;
use App\Models\Page;
use App\Models\Series;
use App\Models\Tag;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function archiveImageBytes(string $name, int $width = 4, int $height = 6): string
{
    return UploadedFile::fake()->image($name, $width, $height)->getContent();
}

/**
 * @return array{manifest:array<string,mixed>,json:string,names:list<string>}
 */
function readKomaArchive(string $path): array
{
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();

    $json = $zip->getFromName(KomaArchiveV1::MANIFEST_PATH);
    expect($json)->toBeString();

    $names = [];
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $names[] = $zip->getNameIndex($index);
    }

    $zip->close();

    return [
        'manifest' => json_decode($json, true, flags: JSON_THROW_ON_ERROR),
        'json' => $json,
        'names' => $names,
    ];
}

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-07-20 12:34:56 UTC');
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('media');
    $this->archiveDirectory = storage_path('framework/testing/archive-export-'.Str::uuid());
    File::ensureDirectoryExists($this->archiveDirectory);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    File::deleteDirectory($this->archiveDirectory);
    File::cleanDirectory(storage_path('app/archive-downloads'));
});

test('v1 manifest is exact ordered unicode safe and checksum complete', function () {
    $coverBytes = archiveImageBytes('cover.png', 10, 14);
    $thumbnailBytes = archiveImageBytes('thumbnail.png', 5, 7);
    $firstPageBytes = archiveImageBytes('first.png', 8, 12);
    $secondPageBytes = archiveImageBytes('second.png', 9, 13);

    Storage::disk('media')->put('covers/raw/unsafe title.png', $coverBytes);
    Storage::disk('media')->put('covers/raw/thumbnail.png', $thumbnailBytes);
    Storage::disk('media')->put('pages/raw/second.png', $secondPageBytes);
    Storage::disk('media')->put('pages/raw/first.png', $firstPageBytes);

    $laterGenre = Genre::create(['name' => 'Slice of Life', 'slug' => 'slice-of-life']);
    $earlierGenre = Genre::create(['name' => 'Action', 'slug' => 'action']);
    $series = Series::create([
        'title' => 'メイカさん — Café ✓',
        'slug' => 'example-cafe',
        'author' => '原作 太郎',
        'artist' => 'Artist É',
        'year' => 2026,
        'status' => 'hiatus',
        'reading_direction' => 'rtl',
        'default_reader_mode' => 'vertical',
        'content_rating' => 'mature',
        'is_public' => false,
        'synopsis' => 'Private résumé with Unicode 物語.',
        'cover_path' => 'covers/raw/unsafe title.png',
        'cover_thumbnail_path' => 'covers/raw/thumbnail.png',
        'cover_width' => 10,
        'cover_height' => 14,
        'cover_file_size' => strlen($coverBytes),
        'cover_thumbnail_width' => 5,
        'cover_thumbnail_height' => 7,
        'cover_thumbnail_file_size' => strlen($thumbnailBytes),
        'cover_hue' => 311,
        'views' => 42,
    ]);
    $series->genres()->attach([$laterGenre->id, $earlierGenre->id]);
    $laterTag = Tag::create(['name' => 'Romance', 'slug' => 'romance']);
    $earlierTag = Tag::create(['name' => 'Cooking', 'slug' => 'cooking']);
    $series->tags()->attach([$laterTag->id, $earlierTag->id]);

    $published = Chapter::create([
        'series_id' => $series->id,
        'number' => 10.50,
        'title' => '十・五話',
        'scanlation_group' => 'Authorized TL',
        'status' => Chapter::STATUS_PUBLISHED,
        'published_at' => CarbonImmutable::parse('2026-07-19 01:02:03 UTC'),
        'views' => 7,
    ]);
    $secondPage = Page::create([
        'chapter_id' => $published->id,
        'sort_order' => 2,
        'image_path' => 'pages/raw/second.png',
        'width' => 9,
        'height' => 13,
        'file_size' => strlen($secondPageBytes),
    ]);
    $firstPage = Page::create([
        'chapter_id' => $published->id,
        'sort_order' => 1,
        'image_path' => 'pages/raw/first.png',
        'width' => 8,
        'height' => 12,
        'file_size' => strlen($firstPageBytes),
    ]);
    $draft = Chapter::create([
        'series_id' => $series->id,
        'number' => 11,
        'title' => null,
        'scanlation_group' => null,
        'status' => Chapter::STATUS_DRAFT,
        'published_at' => null,
        'views' => 0,
    ]);

    $destination = $this->archiveDirectory.'/exact.koma.zip';
    $seriesBefore = $series->fresh()->toArray();
    $archive = app(SeriesArchiveExporter::class)->export($series, $destination, schemaVersion: 1);
    $read = readKomaArchive($destination);
    $timestamp = '2026-07-20T12:34:56Z';
    $coverPath = "media/series/{$series->id}/cover.png";
    $thumbnailPath = "media/series/{$series->id}/cover-thumbnail.png";
    $firstPath = "media/series/{$series->id}/chapters/{$published->id}/pages/000001-{$firstPage->id}.png";
    $secondPath = "media/series/{$series->id}/chapters/{$published->id}/pages/000002-{$secondPage->id}.png";

    expect($read['manifest'])->toBe([
        'format' => 'koma-archive',
        'schema_version' => 1,
        'exported_at' => $timestamp,
        'series' => [
            'source_id' => $series->id,
            'title' => 'メイカさん — Café ✓',
            'slug' => 'example-cafe',
            'author' => '原作 太郎',
            'artist' => 'Artist É',
            'year' => 2026,
            'status' => 'hiatus',
            'reading_direction' => 'rtl',
            'default_reader_mode' => 'vertical',
            'content_rating' => 'mature',
            'is_public' => false,
            'synopsis' => 'Private résumé with Unicode 物語.',
            'cover_hue' => 311,
            'views' => 42,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'genres' => [
                [
                    'source_id' => $earlierGenre->id,
                    'name' => 'Action',
                    'slug' => 'action',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
                [
                    'source_id' => $laterGenre->id,
                    'name' => 'Slice of Life',
                    'slug' => 'slice-of-life',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
            ],
            'tags' => [
                [
                    'source_id' => $earlierTag->id,
                    'name' => 'Cooking',
                    'slug' => 'cooking',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
                [
                    'source_id' => $laterTag->id,
                    'name' => 'Romance',
                    'slug' => 'romance',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
            ],
            'cover' => [
                'archive_path' => $coverPath,
                'mime_type' => 'image/png',
                'sha256' => hash('sha256', $coverBytes),
                'bytes' => strlen($coverBytes),
                'width' => 10,
                'height' => 14,
            ],
            'cover_thumbnail' => [
                'archive_path' => $thumbnailPath,
                'mime_type' => 'image/png',
                'sha256' => hash('sha256', $thumbnailBytes),
                'bytes' => strlen($thumbnailBytes),
                'width' => 5,
                'height' => 7,
            ],
            'chapters' => [
                [
                    'source_id' => $published->id,
                    'number' => '10.50',
                    'title' => '十・五話',
                    'scanlation_group' => 'Authorized TL',
                    'status' => 'published',
                    'published_at' => '2026-07-19T01:02:03Z',
                    'views' => 7,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                    'pages' => [
                        [
                            'source_id' => $firstPage->id,
                            'sort_order' => 1,
                            'archive_path' => $firstPath,
                            'mime_type' => 'image/png',
                            'sha256' => hash('sha256', $firstPageBytes),
                            'bytes' => strlen($firstPageBytes),
                            'width' => 8,
                            'height' => 12,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ],
                        [
                            'source_id' => $secondPage->id,
                            'sort_order' => 2,
                            'archive_path' => $secondPath,
                            'mime_type' => 'image/png',
                            'sha256' => hash('sha256', $secondPageBytes),
                            'bytes' => strlen($secondPageBytes),
                            'width' => 9,
                            'height' => 13,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ],
                    ],
                ],
                [
                    'source_id' => $draft->id,
                    'number' => '11.00',
                    'title' => null,
                    'scanlation_group' => null,
                    'status' => 'draft',
                    'published_at' => null,
                    'views' => 0,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                    'pages' => [],
                ],
            ],
        ],
    ])->and($read['names'])->toBe([
        'manifest.json',
        $coverPath,
        $thumbnailPath,
        $firstPath,
        $secondPath,
    ])->and($read['json'])->toContain('メイカさん', '物語')
        ->not->toContain('unsafe title', '\\u30e1', 'image_path', 'cover_path', 'admin@koma.test', base_path())
        ->and($archive->bytes)->toBe(filesize($destination))
        ->and($archive->sha256)->toBe(hash_file('sha256', $destination))
        ->and($series->fresh()->toArray())->toBe($seriesBefore)
        ->and(Storage::disk('media')->get('pages/raw/first.png'))->toBe($firstPageBytes);

    $zip = new ZipArchive;
    expect($zip->open($destination))->toBeTrue()
        ->and($zip->getFromName($coverPath))->toBe($coverBytes)
        ->and($zip->getFromName($thumbnailPath))->toBe($thumbnailBytes)
        ->and($zip->getFromName($firstPath))->toBe($firstPageBytes)
        ->and($zip->getFromName($secondPath))->toBe($secondPageBytes);
    $zip->close();
});

test('empty series export contains only a valid manifest', function () {
    $series = Series::create([
        'title' => 'Empty Backup',
        'slug' => 'empty-backup',
        'status' => 'ongoing',
        'reading_direction' => 'ltr',
        'content_rating' => 'safe',
        'is_public' => true,
    ]);
    $destination = $this->archiveDirectory.'/empty.koma.zip';

    app(SeriesArchiveExporter::class)->export($series, $destination);
    $read = readKomaArchive($destination);

    expect($read['names'])->toBe(['manifest.json'])
        ->and($read['manifest']['series']['genres'])->toBe([])
        ->and($read['manifest']['series']['tags'])->toBe([])
        ->and($read['manifest']['series']['cover'])->toBeNull()
        ->and($read['manifest']['series']['cover_thumbnail'])->toBeNull()
        ->and($read['manifest']['series']['chapters'])->toBe([]);

    app(KomaArchiveValidator::class)->validate($read['manifest']);
});

test('export fails closed when media does not decode to a supported image type', function () {
    Storage::disk('media')->put('covers/blob.dat', 'this is definitely not an image');
    $series = Series::create([
        'title' => 'Non Image Cover',
        'slug' => 'non-image-cover',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
        'cover_path' => 'covers/blob.dat',
    ]);
    $destination = $this->archiveDirectory.'/non-image.koma.zip';
    $stagingBefore = glob(storage_path('app/archive-temp/*')) ?: [];

    expect(fn () => app(SeriesArchiveExporter::class)->export($series, $destination))
        ->toThrow(ArchiveException::class, 'not a supported image type');

    expect(file_exists($destination))->toBeFalse()
        ->and(glob($this->archiveDirectory.'/*.partial-*') ?: [])->toBe([])
        ->and(glob(storage_path('app/archive-temp/*')) ?: [])->toBe($stagingBefore);
});

test('export rejects corrupt media that has a valid jpeg file signature', function () {
    $corruptJpeg = hex2bin('ffd8ffe000104a46494600010100000100010000ffdb004300');
    expect($corruptJpeg)->toBeString();
    Storage::disk('media')->put('covers/corrupt.jpg', $corruptJpeg);
    $series = Series::create([
        'title' => 'Corrupt JPEG Cover',
        'slug' => 'corrupt-jpeg-cover',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
        'cover_path' => 'covers/corrupt.jpg',
    ]);
    $destination = $this->archiveDirectory.'/corrupt-jpeg.koma.zip';
    $stagingBefore = glob(storage_path('app/archive-temp/*')) ?: [];

    expect(fn () => app(SeriesArchiveExporter::class)->export($series, $destination))
        ->toThrow(ArchiveException::class, 'could not be decoded safely');

    expect(file_exists($destination))->toBeFalse()
        ->and(glob($this->archiveDirectory.'/*.partial-*') ?: [])->toBe([])
        ->and(glob(storage_path('app/archive-temp/*')) ?: [])->toBe($stagingBefore);
});

test('all chapter publication states and decimal numbers are preserved', function (string $status, ?string $publishedAt) {
    $series = Series::create([
        'title' => 'State Backup',
        'slug' => 'state-backup-'.$status,
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
    ]);
    Chapter::create([
        'series_id' => $series->id,
        'number' => 7.25,
        'status' => $status,
        'published_at' => $publishedAt ? CarbonImmutable::parse($publishedAt) : null,
    ]);
    $destination = $this->archiveDirectory."/{$status}.koma.zip";

    app(SeriesArchiveExporter::class)->export($series, $destination);
    $chapter = readKomaArchive($destination)['manifest']['series']['chapters'][0];

    expect($chapter['number'])->toBe('7.25')
        ->and($chapter['status'])->toBe($status)
        ->and($chapter['published_at'])->toBe($publishedAt);
})->with([
    'draft' => [Chapter::STATUS_DRAFT, null],
    'scheduled' => [Chapter::STATUS_SCHEDULED, '2026-07-21T12:34:56Z'],
    'published' => [Chapter::STATUS_PUBLISHED, '2026-07-19T12:34:56Z'],
]);

test('unchanged sources produce deterministic logical and zip content at a fixed export time', function () {
    $pageBytes = archiveImageBytes('page.webp', 6, 9);
    Storage::disk('media')->put('pages/deterministic.webp', $pageBytes);
    $series = Series::create([
        'title' => 'Deterministic',
        'slug' => 'deterministic',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
    ]);
    $chapter = Chapter::create([
        'series_id' => $series->id,
        'number' => 1,
        'status' => Chapter::STATUS_DRAFT,
    ]);
    Page::create([
        'chapter_id' => $chapter->id,
        'sort_order' => 1,
        'image_path' => 'pages/deterministic.webp',
        'width' => 6,
        'height' => 9,
        'file_size' => strlen($pageBytes),
    ]);
    $first = $this->archiveDirectory.'/first.koma.zip';
    $second = $this->archiveDirectory.'/second.koma.zip';

    app(SeriesArchiveExporter::class)->export($series, $first);
    app(SeriesArchiveExporter::class)->export($series, $second);

    expect(readKomaArchive($first))->toBe(readKomaArchive($second))
        ->and(hash_file('sha256', $first))->toBe(hash_file('sha256', $second));
});

test('missing media aborts export and removes partial archives and staging files', function () {
    $series = Series::create([
        'title' => 'Missing Media',
        'slug' => 'missing-media',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
    ]);
    $chapter = Chapter::create([
        'series_id' => $series->id,
        'number' => 1,
        'status' => Chapter::STATUS_DRAFT,
    ]);
    Page::create([
        'chapter_id' => $chapter->id,
        'sort_order' => 1,
        'image_path' => 'pages/does-not-exist.webp',
    ]);
    $destination = $this->archiveDirectory.'/missing.koma.zip';
    $stagingBefore = glob(storage_path('app/archive-temp/*')) ?: [];

    expect(fn () => app(SeriesArchiveExporter::class)->export($series, $destination))
        ->toThrow(ArchiveException::class, 'Archive media is missing');

    expect(file_exists($destination))->toBeFalse()
        ->and(glob($this->archiveDirectory.'/*.partial-*') ?: [])->toBe([])
        ->and(glob(storage_path('app/archive-temp/*')) ?: [])->toBe($stagingBefore);
});

test('unreadable media aborts without leaving a destination', function () {
    $series = Series::create([
        'title' => 'Unreadable Media',
        'slug' => 'unreadable-media',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
        'cover_path' => 'covers/unreadable.webp',
    ]);
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->once()->with('covers/unreadable.webp')->andReturnTrue();
    $disk->shouldReceive('readStream')->once()->with('covers/unreadable.webp')->andReturnFalse();
    $disk->shouldNotReceive('get');
    Storage::shouldReceive('disk')->once()->with('media')->andReturn($disk);
    $destination = $this->archiveDirectory.'/unreadable.koma.zip';

    expect(fn () => app(SeriesArchiveExporter::class)->export($series, $destination))
        ->toThrow(ArchiveException::class, 'Archive media is unreadable');

    expect(file_exists($destination))->toBeFalse();
});

test('export streams media instead of loading complete objects through get', function () {
    $series = Series::create([
        'title' => 'Streamed Media',
        'slug' => 'streamed-media',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
        'cover_path' => 'covers/streamed.png',
    ]);
    $bytes = archiveImageBytes('streamed.png', 3, 4);
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, $bytes);
    rewind($stream);
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->once()->with('covers/streamed.png')->andReturnTrue();
    $disk->shouldReceive('readStream')->once()->with('covers/streamed.png')->andReturn($stream);
    $disk->shouldNotReceive('get');
    Storage::shouldReceive('disk')->once()->with('media')->andReturn($disk);
    $destination = $this->archiveDirectory.'/streamed.koma.zip';

    app(SeriesArchiveExporter::class)->export($series, $destination);
    $manifest = readKomaArchive($destination)['manifest'];

    expect($manifest['series']['cover']['bytes'])->toBe(strlen($bytes))
        ->and($manifest['series']['cover']['sha256'])->toBe(hash('sha256', $bytes));
});

test('validator rejects unknown fields duplicate paths and unsafe paths', function () {
    $series = Series::create([
        'title' => 'Validation Source',
        'slug' => 'validation-source',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
    ]);
    $destination = $this->archiveDirectory.'/validation.koma.zip';
    app(SeriesArchiveExporter::class)->export($series, $destination);
    $manifest = readKomaArchive($destination)['manifest'];
    $validator = app(KomaArchiveValidator::class);

    $unknown = $manifest;
    $unknown['credentials'] = ['secret' => true];
    expect(fn () => $validator->validate($unknown))
        ->toThrow(ArchiveValidationException::class, 'unexpected fields');

    $media = [
        'archive_path' => 'media/../escape.webp',
        'mime_type' => 'image/webp',
        'sha256' => str_repeat('a', 64),
        'bytes' => 1,
        'width' => 1,
        'height' => 1,
    ];
    $unsafe = $manifest;
    $unsafe['series']['cover'] = $media;
    expect(fn () => $validator->validate($unsafe))
        ->toThrow(ArchiveValidationException::class, 'unsafe media path');

    $duplicate = $manifest;
    $duplicate['series']['cover'] = array_merge($media, ['archive_path' => 'media/duplicate.webp']);
    $duplicate['series']['cover_thumbnail'] = array_merge($media, ['archive_path' => 'media/duplicate.webp']);
    expect(fn () => $validator->validate($duplicate))
        ->toThrow(ArchiveValidationException::class, 'duplicate media paths');
});

test('cli exports by slug to an explicit local path without overwriting', function () {
    $series = Series::create([
        'title' => 'CLI Backup',
        'slug' => 'cli-backup',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
    ]);
    $destination = $this->archiveDirectory.'/cli.koma.zip';

    $this->artisan('koma:archive:export', [
        'series' => $series->slug,
        '--output' => $destination,
    ])->expectsOutput('Koma Archive v2 exported successfully.')
        ->expectsOutputToContain('SHA-256: ')
        ->assertSuccessful();

    expect(file_exists($destination))->toBeTrue()
        ->and(readKomaArchive($destination)['manifest']['series']['source_id'])->toBe($series->id);

    $original = file_get_contents($destination);
    $this->artisan('koma:archive:export', [
        'series' => $series->id,
        '--output' => $destination,
    ])->expectsOutput('The archive destination already exists.')
        ->assertFailed();
    expect(file_get_contents($destination))->toBe($original);
});

test('cli rejects an unknown series', function () {
    $this->artisan('koma:archive:export', ['series' => 'not-a-series'])
        ->expectsOutput('No series exists with that ID or slug.')
        ->assertFailed();
});

test('admin archive download requires series editing permission', function (string $role, int $expectedStatus) {
    $series = Series::create([
        'title' => 'Authorized Download',
        'slug' => 'authorized-download-'.$role,
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'mature',
        'is_public' => false,
    ]);
    $user = User::factory()->create();
    $user->assignRole($role);
    $response = $this->actingAs($user)->get(route('admin.series.export', $series));

    $response->assertStatus($expectedStatus);

    if ($expectedStatus === 200) {
        $response->assertDownload("series-{$series->id}-authorized-download-".strtolower($role).'.koma.zip')
            ->assertHeader('content-type', KomaArchiveV1::MIME_TYPE)
            ->assertHeader('x-koma-archive-version', '2');
    }
})->with([
    'Member denied' => ['Member', 403],
    'Uploader denied' => ['Uploader', 403],
    'Moderator denied' => ['Moderator', 403],
    'Editor allowed' => ['Editor', 200],
    'Administrator allowed' => ['Administrator', 200],
]);

test('admin archive download keeps explicit v1 compatibility', function () {
    $series = Series::create([
        'title' => 'Compatibility Download',
        'slug' => 'compatibility-download',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
    ]);
    $user = User::factory()->create();
    $user->assignRole('Editor');

    $this->actingAs($user)
        ->get(route('admin.series.export', ['series' => $series, 'schema' => 1]))
        ->assertOk()
        ->assertHeader('x-koma-archive-version', '1');
});

test('anonymous admin archive download redirects to login', function () {
    $series = Series::create([
        'title' => 'Anonymous Download',
        'slug' => 'anonymous-download',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
    ]);

    $this->get(route('admin.series.export', $series))
        ->assertRedirect(route('login'));
});

test('installer reports zip as a required server capability', function () {
    session()->put('koma.installer.setup_grant', app(InstallerSetupAuthority::class)->sessionGrant());
    $zip = collect(Livewire::test(Installer::class)->instance()->requirements())
        ->firstWhere('label', 'ZIP archive support');

    expect($zip)->not->toBeNull()
        ->and($zip['required'])->toBeTrue()
        ->and($zip['ok'])->toBe(extension_loaded('zip'));
});
