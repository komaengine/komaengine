<?php

use App\Archives\ArchiveConflictException;
use App\Archives\ArchiveException;
use App\Archives\ArchiveImportBatchOptions;
use App\Archives\ArchiveImportPolicy;
use App\Archives\ArchiveMediaStore;
use App\Archives\ArchiveUploadLimits;
use App\Archives\ArchiveValidationException;
use App\Archives\Contracts\GalleryArchiveAdapter;
use App\Archives\GalleryArchiveV1Adapter;
use App\Archives\KomaArchiveV1;
use App\Archives\SeriesArchiveExporter;
use App\Archives\SeriesArchiveImporter;
use App\Jobs\ImportSeriesArchive;
use App\Livewire\Admin\ArchiveImporter;
use App\Media\ImageProcessor;
use App\Models\Chapter;
use App\Models\Genre;
use App\Models\Page;
use App\Models\Series;
use App\Models\SeriesArchiveImport;
use App\Models\Tag;
use App\Models\User;
use App\Support\Facades\Hook;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** @return array{series:Series,archive:string,manifest:array<string,mixed>,page_bytes:list<string>} */
function p7ArchiveFixture(string $directory, string $slug = 'portable-series', bool $covers = true, int $pages = 2): array
{
    $pageBytes = [];

    for ($index = 1; $index <= $pages; $index++) {
        $bytes = UploadedFile::fake()->image("page-{$index}.png", 8 + $index, 12 + $index)->getContent();
        $pageBytes[] = $bytes;
        Storage::disk('media')->put("source/page-{$index}.png", $bytes);
    }

    $coverBytes = UploadedFile::fake()->image('cover.png', 10, 14)->getContent();
    $thumbnailBytes = UploadedFile::fake()->image('thumbnail.png', 5, 7)->getContent();

    if ($covers) {
        Storage::disk('media')->put('source/cover.png', $coverBytes);
        Storage::disk('media')->put('source/thumbnail.png', $thumbnailBytes);
    }

    $series = Series::create([
        'title' => 'Portable 物語',
        'slug' => $slug,
        'author' => 'Author É',
        'artist' => 'Artist ✓',
        'alternate_titles' => [
            ['language' => 'ja', 'title' => 'ポータブル物語', 'type' => 'original'],
        ],
        'credits' => [
            ['name' => 'Artist ✓', 'role' => 'artist', 'type' => 'person'],
            ['name' => 'Circle É', 'role' => 'creator_group', 'type' => 'organization'],
        ],
        'franchises' => ['Portable Universe'],
        'characters' => ['Aoi'],
        'source_references' => [
            ['provider' => 'example.test', 'external_id' => 'portable-1', 'url' => 'https://example.test/titles/portable-1'],
        ],
        'year' => 2025,
        'publication_date' => '2025-04-04',
        'work_format' => 'doujinshi',
        'status' => 'hiatus',
        'reading_direction' => 'rtl',
        'default_reader_mode' => 'vertical',
        'content_rating' => 'mature',
        'is_public' => false,
        'synopsis' => 'A portable résumé.',
        'cover_path' => $covers ? 'source/cover.png' : null,
        'cover_thumbnail_path' => $covers ? 'source/thumbnail.png' : null,
        'cover_width' => $covers ? 10 : null,
        'cover_height' => $covers ? 14 : null,
        'cover_file_size' => $covers ? strlen($coverBytes) : null,
        'cover_thumbnail_width' => $covers ? 5 : null,
        'cover_thumbnail_height' => $covers ? 7 : null,
        'cover_thumbnail_file_size' => $covers ? strlen($thumbnailBytes) : null,
        'cover_hue' => 287,
        'views' => 91,
    ]);
    $action = Genre::create(['name' => 'Action', 'slug' => 'action']);
    $life = Genre::create(['name' => 'Slice of Life', 'slug' => 'slice-of-life']);
    $series->genres()->attach([$life->id, $action->id]);
    $cooking = Tag::create(['name' => 'Cooking', 'slug' => 'cooking']);
    $drama = Tag::create(['name' => 'Drama', 'slug' => 'drama']);
    $series->tags()->attach([$drama->id, $cooking->id]);

    $published = Chapter::create([
        'series_id' => $series->id,
        'number' => 1.50,
        'title' => 'Published',
        'scanlation_group' => 'Authorized TL',
        'language' => 'en',
        'status' => Chapter::STATUS_PUBLISHED,
        'published_at' => CarbonImmutable::parse('2026-07-19 08:00:00 UTC'),
        'views' => 17,
    ]);

    foreach (array_reverse($pageBytes, true) as $zeroIndex => $bytes) {
        $sortOrder = $zeroIndex + 1;
        Page::create([
            'chapter_id' => $published->id,
            'sort_order' => $sortOrder,
            'image_path' => "source/page-{$sortOrder}.png",
            'width' => 8 + $sortOrder,
            'height' => 12 + $sortOrder,
            'file_size' => strlen($bytes),
        ]);
    }

    Chapter::create([
        'series_id' => $series->id,
        'number' => 2,
        'title' => 'Scheduled',
        'status' => Chapter::STATUS_SCHEDULED,
        'published_at' => CarbonImmutable::parse('2026-07-21 08:00:00 UTC'),
    ]);
    Chapter::create([
        'series_id' => $series->id,
        'number' => 3,
        'title' => 'Draft',
        'status' => Chapter::STATUS_DRAFT,
        'published_at' => null,
    ]);

    $archive = $directory.'/'.Str::uuid().'.koma.zip';
    app(SeriesArchiveExporter::class)->export($series, $archive);
    $zip = new ZipArchive;
    $zip->open($archive);
    $manifest = json_decode($zip->getFromName(KomaArchiveV1::MANIFEST_PATH), true, flags: JSON_THROW_ON_ERROR);
    $zip->close();

    return compact('series', 'archive', 'manifest', 'pageBytes');
}

/**
 * @param  null|callable(array<string,mixed>):array<string,mixed>  $mutateManifest
 * @param  array<string, string>  $overrides
 * @param  array<string, string>  $extras
 */
function p7RewriteArchive(
    string $source,
    string $destination,
    ?callable $mutateManifest = null,
    array $overrides = [],
    array $extras = [],
    bool $deflateMedia = false,
): void {
    $input = new ZipArchive;
    expect($input->open($source))->toBeTrue();
    $entries = [];

    for ($index = 0; $index < $input->numFiles; $index++) {
        $name = $input->getNameIndex($index);
        $entries[$name] = $input->getFromIndex($index);
    }

    $input->close();

    if ($mutateManifest !== null) {
        $manifest = json_decode($entries[KomaArchiveV1::MANIFEST_PATH], true, flags: JSON_THROW_ON_ERROR);
        $entries[KomaArchiveV1::MANIFEST_PATH] = json_encode(
            $mutateManifest($manifest),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";
    }

    $entries = array_merge($entries, $overrides, $extras);
    $output = new ZipArchive;
    expect($output->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();

    foreach ($entries as $name => $bytes) {
        $output->addFromString($name, $bytes);
        $output->setCompressionName(
            $name,
            $name === KomaArchiveV1::MANIFEST_PATH || $deflateMedia ? ZipArchive::CM_DEFLATE : ZipArchive::CM_STORE,
        );
    }

    $output->close();
}

/** @return array{archive:string,page_bytes:list<string>} */
function p7ExternalGalleryFixture(string $directory): array
{
    $pageBytes = [
        UploadedFile::fake()->image('page-0001.png', 9, 13)->getContent(),
        UploadedFile::fake()->image('page-0002.png', 10, 14)->getContent(),
    ];
    $manifest = [
        'version' => 1,
        'source' => 'example-scanner',
        'source_id' => 3314583,
        'url' => 'https://gallery.example/g/3314583',
        'title' => 'External Gallery',
        'title_original' => '外部ギャラリー',
        'title_original_language' => 'ja',
        'language' => 'english',
        'published_on' => '2025-04-04',
        'work_format' => 'doujinshi',
        'artists' => ['Artist One', 'Artist Two'],
        'groups' => ['Example Circle'],
        'franchises' => ['Example Franchise'],
        'characters' => ['Example Character'],
        'tags' => ['Romance', 'Drama'],
        'pages' => ['page-0001.png', 'page-0002.png'],
    ];
    $archive = $directory.'/external-gallery.zip';
    $zip = new ZipArchive;
    expect($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('manifest.json', json_encode(
        $manifest,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    )."\n");
    $zip->addFromString('pages/page-0001.png', $pageBytes[0]);
    $zip->addFromString('pages/page-0002.png', $pageBytes[1]);
    $zip->close();

    return compact('archive', 'pageBytes');
}

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-07-20 12:34:56 UTC');
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('media');
    Storage::fake('local');
    $this->archiveDirectory = storage_path('framework/testing/archive-import-'.Str::uuid());
    File::ensureDirectoryExists($this->archiveDirectory);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    File::deleteDirectory($this->archiveDirectory);
    File::cleanDirectory(storage_path('app/archive-import-temp'));
});

test('export import round trip preserves portable metadata states order and image metadata', function () {
    $fixture = p7ArchiveFixture($this->archiveDirectory);
    $sourceManifest = $fixture['manifest']['series'];
    $fixture['series']->delete();
    $progressUpdates = [];
    $baseTransactionLevel = DB::transactionLevel();

    $result = app(SeriesArchiveImporter::class)->import(
        $fixture['archive'],
        progress: function (int $percent, string $stage, string $message) use (&$progressUpdates): void {
            $progressUpdates[] = compact('percent', 'stage', 'message') + ['transaction_level' => DB::transactionLevel()];
        },
    );
    $imported = $result->series;
    $chapters = $imported->chapters()->with('pages')->orderBy('number')->get();
    $percentages = array_column($progressUpdates, 'percent');
    $sortedPercentages = $percentages;
    sort($sortedPercentages);
    $transferUpdates = array_values(array_filter(
        $progressUpdates,
        fn (array $update) => str_starts_with($update['message'], 'Transferred '),
    ));

    expect($imported->only([
        'title', 'slug', 'author', 'artist', 'year', 'status', 'reading_direction',
        'default_reader_mode', 'content_rating', 'is_public', 'synopsis', 'cover_hue', 'views',
    ]))->toBe(collect($sourceManifest)->only([
        'title', 'slug', 'author', 'artist', 'year', 'status', 'reading_direction',
        'default_reader_mode', 'content_rating', 'is_public', 'synopsis', 'cover_hue', 'views',
    ])->all())
        ->and($fixture['manifest']['schema_version'])->toBe(2)
        ->and($imported->alternate_titles)->toBe($sourceManifest['alternate_titles'])
        ->and($imported->credits)->toBe($sourceManifest['credits'])
        ->and($imported->franchises)->toBe($sourceManifest['franchises'])
        ->and($imported->characters)->toBe($sourceManifest['characters'])
        ->and($imported->source_references)->toBe($sourceManifest['source_references'])
        ->and($imported->publication_date->format('Y-m-d'))->toBe($sourceManifest['publication_date'])
        ->and($imported->work_format)->toBe($sourceManifest['work_format'])
        ->and($imported->genres->sortBy('slug')->pluck('slug')->values()->all())->toBe(['action', 'slice-of-life'])
        ->and($imported->tags->sortBy('slug')->pluck('slug')->values()->all())->toBe(['cooking', 'drama'])
        ->and($imported->created_at->utc()->format('Y-m-d\TH:i:s\Z'))->toBe($sourceManifest['created_at'])
        ->and($imported->updated_at->utc()->format('Y-m-d\TH:i:s\Z'))->toBe($sourceManifest['updated_at'])
        ->and($imported->cover_width)->toBe(10)
        ->and($imported->cover_height)->toBe(14)
        ->and($imported->cover_file_size)->toBe($sourceManifest['cover']['bytes'])
        ->and($imported->cover_thumbnail_width)->toBe(5)
        ->and($imported->cover_thumbnail_height)->toBe(7)
        ->and($imported->cover_thumbnail_file_size)->toBe($sourceManifest['cover_thumbnail']['bytes'])
        ->and($chapters->pluck('number')->all())->toBe(['1.50', '2.00', '3.00'])
        ->and($chapters->pluck('status')->all())->toBe(['published', 'scheduled', 'draft'])
        ->and($chapters[0]->language)->toBe('en')
        ->and($chapters[0]->published_at->utc()->format('Y-m-d\TH:i:s\Z'))->toBe('2026-07-19T08:00:00Z')
        ->and($chapters[1]->published_at->utc()->format('Y-m-d\TH:i:s\Z'))->toBe('2026-07-21T08:00:00Z')
        ->and($chapters[2]->published_at)->toBeNull()
        ->and($chapters[0]->pages->pluck('sort_order')->all())->toBe([1, 2])
        ->and($chapters[0]->pages->pluck('width')->all())->toBe([9, 10])
        ->and($chapters[0]->pages->pluck('height')->all())->toBe([13, 14])
        ->and($chapters[0]->pages->pluck('file_size')->all())->toBe(array_map('strlen', $fixture['pageBytes']))
        ->and($chapters[0]->created_at->utc()->format('Y-m-d\TH:i:s\Z'))->toBe($sourceManifest['chapters'][0]['created_at'])
        ->and($chapters[0]->pages[0]->created_at->utc()->format('Y-m-d\TH:i:s\Z'))->toBe($sourceManifest['chapters'][0]['pages'][0]['created_at'])
        ->and($result->counts)->toBe(['series' => 1, 'genres' => 2, 'tags' => 2, 'chapters' => 3, 'pages' => 2, 'media' => 4])
        ->and($percentages)->toBe($sortedPercentages)
        ->and(end($progressUpdates)['percent'])->toBe(100)
        ->and(count($transferUpdates))->toBe(4)
        ->and(array_unique(array_column($transferUpdates, 'transaction_level')))->toBe([$baseTransactionLevel]);

    foreach ($chapters[0]->pages as $index => $page) {
        expect(Storage::disk('media')->get($page->image_path))->toBe($fixture['pageBytes'][$index]);
    }

    expect(Storage::disk('media')->get($imported->cover_path))->toBe(
        Storage::disk('media')->get('source/cover.png'),
    );
});

test('an archive without explicit cover media derives cover art from its first page', function () {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'first-page-cover', false, 2);
    $firstPagePath = $this->archiveDirectory.'/expected-first-page.png';
    File::put($firstPagePath, $fixture['pageBytes'][0]);
    $expected = app(ImageProcessor::class)->processCover(new UploadedFile(
        $firstPagePath,
        'expected-first-page.png',
        'image/png',
        null,
        true,
    ));
    $fixture['series']->delete();

    $result = app(SeriesArchiveImporter::class)->import($fixture['archive']);
    $series = $result->series->fresh('chapters.pages');
    $firstPage = $series->chapters->first()->pages->sortBy('sort_order')->first();

    expect($series->cover_path)->not->toBeNull()
        ->and($series->cover_thumbnail_path)->not->toBeNull()
        ->and($series->cover_path)->not->toBe($firstPage->image_path)
        ->and($series->cover_thumbnail_path)->not->toBe($firstPage->image_path)
        ->and($series->cover_width)->toBe($expected->cover->width)
        ->and($series->cover_height)->toBe($expected->cover->height)
        ->and($series->cover_thumbnail_width)->toBe($expected->thumbnail->width)
        ->and($series->cover_thumbnail_height)->toBe($expected->thumbnail->height)
        ->and(Storage::disk('media')->get($series->cover_path))->toBe($expected->cover->bytes)
        ->and(Storage::disk('media')->get($series->cover_thumbnail_path))->toBe($expected->thumbnail->bytes)
        ->and($result->counts['media'])->toBe(2);
});

test('supported external gallery maps into one safe v2 series and draft chapter without network access', function () {
    Http::preventStrayRequests();
    $fixture = p7ExternalGalleryFixture($this->archiveDirectory);

    $result = app(SeriesArchiveImporter::class)->import($fixture['archive']);
    $series = $result->series->fresh(['tags', 'chapters.pages']);
    $chapter = $series->chapters->sole();

    expect($series->title)->toBe('External Gallery')
        ->and($series->slug)->toBe('external-gallery')
        ->and($series->artist)->toBe('Artist One, Artist Two')
        ->and($series->alternate_titles)->toBe([
            ['language' => 'ja', 'title' => '外部ギャラリー', 'type' => 'original'],
        ])
        ->and($series->publication_date->format('Y-m-d'))->toBe('2025-04-04')
        ->and($series->year)->toBe(2025)
        ->and($series->work_format)->toBe('doujinshi')
        ->and($series->credits)->toBe([
            ['name' => 'Artist One', 'role' => 'artist', 'type' => 'person'],
            ['name' => 'Artist Two', 'role' => 'artist', 'type' => 'person'],
            ['name' => 'Example Circle', 'role' => 'creator_group', 'type' => 'organization'],
        ])
        ->and($series->franchises)->toBe(['Example Franchise'])
        ->and($series->characters)->toBe(['Example Character'])
        ->and($series->source_references)->toBe([[
            'provider' => 'example-scanner',
            'external_id' => '3314583',
            'url' => 'https://gallery.example/g/3314583',
        ]])
        ->and($series->source_metadata['source_id'])->toBe(3314583)
        ->and($series->toArray())->not->toHaveKey('source_metadata')
        ->and($series->is_public)->toBeFalse()
        ->and($series->content_rating)->toBe('mature')
        ->and($series->cover_path)->not->toBeNull()
        ->and($series->cover_thumbnail_path)->not->toBeNull()
        ->and($series->cover_width)->toBeGreaterThan(0)
        ->and($series->cover_height)->toBeGreaterThan(0)
        ->and($series->tags->sortBy('slug')->pluck('slug')->values()->all())->toBe(['drama', 'romance'])
        ->and($chapter->number)->toBe('1.00')
        ->and($chapter->status)->toBe(Chapter::STATUS_DRAFT)
        ->and($chapter->language)->toBe('en')
        ->and($chapter->scanlation_group)->toBeNull()
        ->and($chapter->pages->pluck('sort_order')->all())->toBe([1, 2])
        ->and($result->counts['pages'])->toBe(2);

    foreach ($chapter->pages as $index => $page) {
        expect(Storage::disk('media')->get($page->image_path))->toBe($fixture['pageBytes'][$index]);
    }
});

test('external gallery adapter rejects undeclared zip entries before persistence', function () {
    $fixture = p7ExternalGalleryFixture($this->archiveDirectory);
    $unsafe = $this->archiveDirectory.'/external-with-extra.zip';
    p7RewriteArchive($fixture['archive'], $unsafe, extras: ['notes.txt' => 'undeclared']);

    expect(fn () => app(SeriesArchiveImporter::class)->import($unsafe))
        ->toThrow(ArchiveValidationException::class, 'do not exactly match');

    expect(Series::query()->count())->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBe([]);
});

test('external gallery adapter rejects unsupported manifest keys before persistence', function () {
    $fixture = p7ExternalGalleryFixture($this->archiveDirectory);
    $unknown = $this->archiveDirectory.'/external-unknown-key.zip';
    p7RewriteArchive($fixture['archive'], $unknown, function (array $manifest): array {
        $manifest['downloaded_from'] = 'https://example.test/whatever';

        return $manifest;
    });

    expect(fn () => app(SeriesArchiveImporter::class)->import($unknown))
        ->toThrow(ArchiveValidationException::class, 'unsupported keys');

    expect(Series::query()->count())->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBe([]);
});

test('a gallery with no language imports with an unset chapter language', function () {
    // Image-only works and game CG sets carry no language at all.
    $fixture = p7ExternalGalleryFixture($this->archiveDirectory);
    $languageless = $this->archiveDirectory.'/external-no-language.zip';
    p7RewriteArchive($fixture['archive'], $languageless, function (array $manifest): array {
        unset($manifest['language'], $manifest['title_original_language']);

        return $manifest;
    });

    $series = app(SeriesArchiveImporter::class)->import($languageless)->series->fresh('chapters');

    expect($series->chapters->sole()->language)->toBeNull()
        ->and($series->alternate_titles)->toBe([
            ['language' => 'und', 'title' => '外部ギャラリー', 'type' => 'original'],
        ]);
});

test('a gallery source url with a non-ascii path survives the import', function () {
    // Real gallery URLs routinely carry Unicode. The adapter must not be
    // stricter about them than the Koma Archive validator it feeds.
    $fixture = p7ExternalGalleryFixture($this->archiveDirectory);
    $unicode = $this->archiveDirectory.'/external-unicode-url.zip';
    p7RewriteArchive($fixture['archive'], $unicode, function (array $manifest): array {
        $manifest['url'] = 'https://gallery.example/manga/injuu-no-jikan-|-음란한-복수의-시간-3568267.html';

        return $manifest;
    });

    $series = app(SeriesArchiveImporter::class)->import($unicode)->series->fresh();

    expect($series->source_references[0]['url'])
        ->toBe('https://gallery.example/manga/injuu-no-jikan-|-음란한-복수의-시간-3568267.html');
});

test('external gallery adapter is provider neutral and drops a non-http source url', function () {
    $fixture = p7ExternalGalleryFixture($this->archiveDirectory);
    $neutral = $this->archiveDirectory.'/external-neutral.zip';
    p7RewriteArchive($fixture['archive'], $neutral, function (array $manifest): array {
        $manifest['source'] = 'club-scanner.2';
        $manifest['url'] = '/relative/path.html';

        return $manifest;
    });

    $series = app(SeriesArchiveImporter::class)->import($neutral)->series->fresh();

    expect($series->source_references)->toBe([[
        'provider' => 'club-scanner.2',
        'external_id' => '3314583',
        'url' => null,
    ]]);
});

test('explicit v1 compatibility archives remain importable with empty v2 metadata', function () {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'v1-compatibility');
    $v1Archive = $this->archiveDirectory.'/v1-compatibility.koma.zip';
    app(SeriesArchiveExporter::class)->export(
        $fixture['series'],
        $v1Archive,
        schemaVersion: 1,
    );
    $fixture['series']->delete();

    $result = app(SeriesArchiveImporter::class)->import($v1Archive);

    expect($result->series->alternate_titles)->toBe([])
        ->and($result->series->credits)->toBe([])
        ->and($result->series->franchises)->toBe([])
        ->and($result->series->characters)->toBe([])
        ->and($result->series->source_references)->toBe([])
        ->and($result->series->publication_date)->toBeNull()
        ->and($result->series->work_format)->toBeNull()
        ->and($result->series->chapters()->whereNotNull('language')->exists())->toBeFalse();
});

test('default conflict policy fails without changing existing data', function () {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'same-slug', false, 1);
    $before = Series::query()->count();
    $files = Storage::disk('media')->allFiles();

    expect(fn () => app(SeriesArchiveImporter::class)->import($fixture['archive']))
        ->toThrow(ArchiveConflictException::class, 'already uses the slug');

    expect(Series::query()->count())->toBe($before)
        ->and($fixture['series']->fresh()->chapters()->count())->toBe(3)
        ->and(Storage::disk('media')->allFiles())->toBe($files)
        ->and(file_exists($fixture['archive']))->toBeTrue();
});

test('fail-policy conflict message names the series already using the slug', function () {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'occupied-slug', false, 1);

    try {
        app(SeriesArchiveImporter::class)->import($fixture['archive']);
        $this->fail('Expected an ArchiveConflictException for the slug conflict.');
    } catch (ArchiveConflictException $e) {
        expect($e->getMessage())
            ->toContain("already uses the slug 'occupied-slug'")
            ->toContain($fixture['series']->title)
            ->toContain('ID '.$fixture['series']->id);
    }
});

test('create unique policy creates a separate series without merging chapters', function () {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'same-slug', false, 1);

    $result = app(SeriesArchiveImporter::class)->import(
        $fixture['archive'],
        ArchiveImportPolicy::CREATE_UNIQUE,
    );

    expect($result->series->slug)->toBe('same-slug-2')
        ->and($result->series->chapters()->count())->toBe(3)
        ->and($fixture['series']->fresh()->chapters()->count())->toBe(3)
        ->and(Series::query()->count())->toBe(2);
});

test('replace requires an explicit target and confirmation', function () {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'replacement-source', false, 1);

    expect(fn () => app(SeriesArchiveImporter::class)->import(
        $fixture['archive'],
        ArchiveImportPolicy::REPLACE,
        $fixture['series']->id,
        false,
    ))->toThrow(ArchiveConflictException::class, 'requires an explicitly selected series');

    expect($fixture['series']->fresh())->not->toBeNull();
});

test('confirmed replacement keeps the selected url until the imported copy is durable', function () {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'replacement-source', false, 1);
    $target = Series::create([
        'title' => 'Old target',
        'slug' => 'stable-url',
        'status' => 'ongoing',
        'reading_direction' => 'ltr',
        'content_rating' => 'safe',
    ]);
    $oldChapter = Chapter::create(['series_id' => $target->id, 'number' => 99, 'status' => 'draft']);
    Storage::disk('media')->put('old/page.png', UploadedFile::fake()->image('old.png')->getContent());
    Page::create(['chapter_id' => $oldChapter->id, 'sort_order' => 1, 'image_path' => 'old/page.png']);
    $targetId = $target->id;

    $result = app(SeriesArchiveImporter::class)->import(
        $fixture['archive'],
        ArchiveImportPolicy::REPLACE,
        $targetId,
        true,
    );

    expect($result->series->slug)->toBe('stable-url')
        ->and($result->series->title)->toBe('Portable 物語')
        ->and($result->series->chapters()->count())->toBe(3)
        ->and(Series::query()->find($targetId))->toBeNull()
        ->and(Storage::disk('media')->exists('old/page.png'))->toBeFalse()
        ->and($fixture['series']->fresh())->not->toBeNull();
});

test('corrupt zip and corrupt json are rejected before writes', function () {
    $badZip = $this->archiveDirectory.'/bad.koma.zip';
    File::put($badZip, 'not a zip');

    expect(fn () => app(SeriesArchiveImporter::class)->import($badZip))
        ->toThrow(ArchiveValidationException::class, 'not a readable ZIP');

    $fixture = p7ArchiveFixture($this->archiveDirectory, 'json-source', false, 1);
    $badJson = $this->archiveDirectory.'/bad-json.koma.zip';
    p7RewriteArchive($fixture['archive'], $badJson, overrides: ['manifest.json' => '{broken']);

    expect(fn () => app(SeriesArchiveImporter::class)->import($badJson))
        ->toThrow(ArchiveValidationException::class, 'not valid JSON');
});

test('zip slip extra entries and manifest urls are rejected without extraction or fetching', function () {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'unsafe-source', false, 1);
    $zipSlip = $this->archiveDirectory.'/zip-slip.koma.zip';
    p7RewriteArchive($fixture['archive'], $zipSlip, extras: ['../outside.txt' => 'owned']);

    expect(fn () => app(SeriesArchiveImporter::class)->import($zipSlip))
        ->toThrow(ArchiveValidationException::class, 'unsafe or invalid entry path');
    expect(File::exists($this->archiveDirectory.'/outside.txt'))->toBeFalse();

    $remote = $this->archiveDirectory.'/remote.koma.zip';
    p7RewriteArchive($fixture['archive'], $remote, function (array $manifest): array {
        $manifest['series']['chapters'][0]['pages'][0]['archive_path'] = 'https://example.test/page.png';

        return $manifest;
    });

    expect(fn () => app(SeriesArchiveImporter::class)->import($remote))
        ->toThrow(ArchiveValidationException::class, 'unsafe media path');
});

test('unreferenced safe zip entries are rejected before persistence', function () {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'extra-source', false, 1);
    $extra = $this->archiveDirectory.'/extra.koma.zip';
    p7RewriteArchive(
        $fixture['archive'],
        $extra,
        extras: ['media/unreferenced.png' => UploadedFile::fake()->image('extra.png')->getContent()],
    );

    expect(fn () => app(SeriesArchiveImporter::class)->import($extra))
        ->toThrow(ArchiveValidationException::class, 'do not exactly match the manifest');
});

test('decompression ratio limits reject a zip bomb before image decoding', function () {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'bomb-source', false, 1);
    $pagePath = $fixture['manifest']['series']['chapters'][0]['pages'][0]['archive_path'];
    $bombBytes = str_repeat('A', 200000);
    $bomb = $this->archiveDirectory.'/bomb.koma.zip';
    p7RewriteArchive(
        $fixture['archive'],
        $bomb,
        function (array $manifest) use ($bombBytes): array {
            $manifest['series']['chapters'][0]['pages'][0]['bytes'] = strlen($bombBytes);
            $manifest['series']['chapters'][0]['pages'][0]['sha256'] = hash('sha256', $bombBytes);

            return $manifest;
        },
        [$pagePath => $bombBytes],
        deflateMedia: true,
    );
    config()->set('koma.archives.imports.max_compression_ratio', 5);

    expect(fn () => app(SeriesArchiveImporter::class)->import($bomb))
        ->toThrow(ArchiveValidationException::class, 'decompression-ratio limit');
});

test('checksum mime dimension and unsupported version mismatches are actionable', function (string $kind, string $message) {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'mismatch-'.$kind, false, 1);
    $archive = $this->archiveDirectory."/{$kind}.koma.zip";
    p7RewriteArchive($fixture['archive'], $archive, function (array $manifest) use ($kind): array {
        if ($kind === 'version') {
            $manifest['schema_version'] = 99;
        } elseif ($kind === 'checksum') {
            $manifest['series']['chapters'][0]['pages'][0]['sha256'] = str_repeat('0', 64);
        } elseif ($kind === 'mime') {
            $manifest['series']['chapters'][0]['pages'][0]['mime_type'] = 'image/jpeg';
        } else {
            $manifest['series']['chapters'][0]['pages'][0]['width'] = 999;
        }

        return $manifest;
    });

    expect(fn () => app(SeriesArchiveImporter::class)->import($archive))
        ->toThrow(ArchiveValidationException::class, $message);
})->with([
    'unsupported version' => ['version', 'manifest validation failed'],
    'checksum mismatch' => ['checksum', 'checksum does not match'],
    'mime mismatch' => ['mime', 'MIME type does not match'],
    'dimension mismatch' => ['dimension', 'dimensions do not match'],
]);

test('duplicate chapter numbers are rejected during whole archive validation', function () {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'duplicates-source', false, 1);
    $duplicate = $this->archiveDirectory.'/duplicate.koma.zip';
    p7RewriteArchive($fixture['archive'], $duplicate, function (array $manifest): array {
        $manifest['series']['chapters'][1]['number'] = $manifest['series']['chapters'][0]['number'];

        return $manifest;
    });

    expect(fn () => app(SeriesArchiveImporter::class)->import($duplicate))
        ->toThrow(ArchiveValidationException::class, 'duplicate chapter numbers');
});

test('manifest collections import at the configured limit and fail one above it', function (string $key, int $present, string $label) {
    // The fixture exports two genres, two tags, and three chapters.
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'counted-'.$label, false, 1);
    $fixture['series']->delete();

    config(["koma.archives.imports.{$key}" => $present - 1]);
    expect(fn () => app(SeriesArchiveImporter::class)->import($fixture['archive']))->toThrow(
        ArchiveValidationException::class,
        "Archive manifest lists {$present} {$label}; the configured limit is ".($present - 1).'.',
    );
    expect(Series::query()->count())->toBe(0);

    config(["koma.archives.imports.{$key}" => $present]);
    expect(app(SeriesArchiveImporter::class)->import($fixture['archive'])->series->exists)->toBeTrue();
})->with([
    'genres' => ['max_genres', 2, 'genres'],
    'tags' => ['max_tags', 2, 'tags'],
    'chapters' => ['max_chapters', 3, 'chapters'],
]);

test('a manifest cannot describe more media than one archive can hold', function () {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'media-count', false, 1);
    $fixture['series']->delete();
    $inflated = $this->archiveDirectory.'/inflated.koma.zip';
    p7RewriteArchive($fixture['archive'], $inflated, function (array $manifest): array {
        $page = $manifest['series']['chapters'][0]['pages'][0];
        $manifest['series']['chapters'][0]['pages'] = array_fill(0, 3, $page);

        return $manifest;
    });
    // Two real ZIP entries fit; the manifest claims three media files.
    config(['koma.archives.imports.max_entries' => 3]);

    expect(fn () => app(SeriesArchiveImporter::class)->import($inflated))->toThrow(
        ArchiveValidationException::class,
        'Archive manifest lists 3 media files; the configured limit is 2.',
    );
    expect(app(SeriesArchiveImporter::class)->import($fixture['archive'])->series->exists)->toBeTrue();
});

test('wrongly typed manifest collections are rejected by validation, not by counting', function (string $path) {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'mistyped-source', false, 1);
    $fixture['series']->delete();
    $mistyped = $this->archiveDirectory.'/mistyped.koma.zip';
    p7RewriteArchive($fixture['archive'], $mistyped, function (array $manifest) use ($path): array {
        data_set($manifest, $path, 'not-a-collection');

        return $manifest;
    });

    expect(fn () => app(SeriesArchiveImporter::class)->import($mistyped))
        ->toThrow(ArchiveValidationException::class, 'Archive manifest validation failed');
    expect(Series::query()->count())->toBe(0);
})->with([
    'series' => ['series'],
    'genres' => ['series.genres'],
    'chapters' => ['series.chapters'],
    'pages' => ['series.chapters.0.pages'],
]);

test('an external gallery shares the tag limit before any page is staged', function () {
    $fixture = p7ExternalGalleryFixture($this->archiveDirectory);

    config(['koma.archives.imports.max_tags' => 1]);
    expect(fn () => app(SeriesArchiveImporter::class)->import($fixture['archive']))
        ->toThrow(ArchiveValidationException::class, 'External gallery manifest validation failed');
    expect(Series::query()->count())->toBe(0);

    config(['koma.archives.imports.max_tags' => 2]);
    expect(app(SeriesArchiveImporter::class)->import($fixture['archive'])->series->tags()->count())->toBe(2);
});

test('export refuses a series the importer would reject for its collection counts', function () {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'export-count', false, 1);
    $destination = $this->archiveDirectory.'/refused.koma.zip';
    config(['koma.archives.imports.max_tags' => 1]);

    expect(fn () => app(SeriesArchiveExporter::class)->export($fixture['series'], $destination))->toThrow(
        ArchiveValidationException::class,
        'Archive manifest lists 2 tags; the configured limit is 1.',
    );
    expect(File::exists($destination))->toBeFalse()
        ->and(File::glob($this->archiveDirectory.'/.refused.koma.zip.partial-*'))->toBe([]);
});

test('mid import storage failure rolls back rows and cleans new media', function () {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'rollback-source', false, 2);
    $fixture['series']->delete();
    Storage::fake('media');

    $failingStore = new class extends ArchiveMediaStore
    {
        public int $writes = 0;

        public function store(string $stagedPath, string $destination, int $expectedBytes): void
        {
            $this->writes++;

            if ($this->writes === 2) {
                throw new ArchiveException('Forced second media write failure.');
            }

            parent::store($stagedPath, $destination, $expectedBytes);
        }
    };
    app()->instance(ArchiveMediaStore::class, $failingStore);

    expect(fn () => app(SeriesArchiveImporter::class)->import($fixture['archive']))
        ->toThrow(ArchiveException::class, 'Forced second media write failure');

    expect(Series::query()->where('slug', 'rollback-source')->exists())->toBeFalse()
        ->and(Storage::disk('media')->allFiles())->toBe([])
        ->and(file_exists($fixture['archive']))->toBeTrue();
});

test('failed confirmed replacement keeps the selected series and old media readable', function () {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'replace-failure-source', false, 2);
    $target = Series::create([
        'title' => 'Recoverable target',
        'slug' => 'recoverable-url',
        'status' => 'ongoing',
        'reading_direction' => 'ltr',
        'content_rating' => 'safe',
    ]);
    $chapter = Chapter::create(['series_id' => $target->id, 'number' => 88, 'status' => 'published']);
    $oldBytes = UploadedFile::fake()->image('old.png')->getContent();
    Storage::disk('media')->put('recoverable/old.png', $oldBytes);
    Page::create(['chapter_id' => $chapter->id, 'sort_order' => 1, 'image_path' => 'recoverable/old.png']);

    $failingStore = new class extends ArchiveMediaStore
    {
        public function store(string $stagedPath, string $destination, int $expectedBytes): void
        {
            throw new ArchiveException('Forced replacement failure.');
        }
    };
    app()->instance(ArchiveMediaStore::class, $failingStore);

    expect(fn () => app(SeriesArchiveImporter::class)->import(
        $fixture['archive'],
        ArchiveImportPolicy::REPLACE,
        $target->id,
        true,
    ))->toThrow(ArchiveException::class, 'Forced replacement failure');

    expect($target->fresh()->slug)->toBe('recoverable-url')
        ->and($target->fresh()->chapters()->where('number', 88)->exists())->toBeTrue()
        ->and(Storage::disk('media')->get('recoverable/old.png'))->toBe($oldBytes);
});

test('archive import route requires series editing permission', function (string $role, int $status) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('admin.archives.import'))->assertStatus($status);

    if ($status === 403) {
        $this->post(route('admin.archives.import.store'))->assertForbidden();
    }
})->with([
    'member denied' => ['Member', 403],
    'uploader denied' => ['Uploader', 403],
    'moderator denied' => ['Moderator', 403],
    'editor allowed' => ['Editor', 200],
    'administrator allowed' => ['Administrator', 200],
]);

test('anonymous archive import redirects to login', function () {
    $this->get(route('admin.archives.import'))->assertRedirect(route('login'));
    $this->post(route('admin.archives.import.store'))->assertRedirect(route('login'));
});

test('post size rejection returns to the importer with an actionable explanation', function () {
    $postBytes = ArchiveUploadLimits::parseIniBytes(ini_get('post_max_size'));

    if ($postBytes === null) {
        $this->markTestSkipped('PHP post_max_size is unlimited in this test environment.');
    }

    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $location = route('admin.archives.import', ['upload_error' => 'post_too_large']);

    $this->actingAs($admin)
        ->withServerVariables(['CONTENT_LENGTH' => (string) ($postBytes + 1)])
        ->post(route('admin.archives.import.store'))
        ->assertRedirect($location);

    $this->withServerVariables(['CONTENT_LENGTH' => '0'])
        ->get($location)
        ->assertOk()
        ->assertSee('The upload never reached Koma’s import queue.')
        ->assertSee('No import record or retained source was created');
});

test('admin workspace validates replacement confirmation and queues retained source', function () {
    Queue::fake();
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'queued-source', false, 1);
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    expect(config('livewire.temporary_file_upload.rules'))->toContain('max:25600');
    $this->actingAs($admin)
        ->get(route('admin.archives.import'))
        ->assertOk()
        ->assertSee('for="replacement_series_id"', false)
        ->assertSee('id="replacement_series_id"', false)
        ->assertSee('current effective limit')
        ->assertSee('Status')
        ->assertSee('PHP upload_max_filesize')
        ->assertSee('x-on:change="inspectFile($event)"', false)
        ->assertSee('A web server or reverse proxy may impose a lower body-size limit');

    $oversized = UploadedFile::fake()->create(
        'too-large.koma.zip',
        (int) config('koma.archives.imports.max_archive_kb') + 1,
        'application/zip',
    );
    $this->actingAs($admin)
        ->from(route('admin.archives.import'))
        ->post(route('admin.archives.import.store'), [
            'archive' => $oversized,
            'conflict_policy' => ArchiveImportPolicy::FAIL,
        ])
        ->assertRedirect(route('admin.archives.import'))
        ->assertSessionHasErrors('archive');
    expect(session('errors')->first('archive'))->toContain("exceeds Koma's configured");

    $incompleteUpload = new UploadedFile(
        $fixture['archive'],
        'incomplete.koma.zip',
        'application/zip',
        UPLOAD_ERR_INI_SIZE,
        true,
    );
    $this->actingAs($admin)
        ->from(route('admin.archives.import'))
        ->post(route('admin.archives.import.store'), [
            'archive' => $incompleteUpload,
            'conflict_policy' => ArchiveImportPolicy::FAIL,
        ])
        ->assertRedirect(route('admin.archives.import'))
        ->assertSessionHasErrors('archive');
    expect(session('errors')->first('archive'))
        ->toContain('The server did not finish receiving this file')
        ->toContain('PHP upload');

    $this->actingAs($admin)
        ->post(route('admin.archives.import.store'), [
            'archive' => UploadedFile::fake()->createWithContent('replace.koma.zip', File::get($fixture['archive'])),
            'conflict_policy' => ArchiveImportPolicy::REPLACE,
            'replacement_series_id' => $fixture['series']->id,
        ])
        ->assertSessionHasErrors(['replace_confirmed']);

    $response = $this->post(route('admin.archives.import.store'), [
        'archive' => UploadedFile::fake()->createWithContent('queued.koma.zip', File::get($fixture['archive'])),
        'conflict_policy' => ArchiveImportPolicy::FAIL,
    ]);

    $record = SeriesArchiveImport::query()->latest()->firstOrFail();
    expect($record->status)->toBe(SeriesArchiveImport::STATUS_QUEUED)
        ->and(Storage::disk('local')->exists($record->source_path))->toBeTrue()
        ->and($response->headers->get('Location'))->toBe(route('admin.archives.import', ['import' => $record->id]));
    Livewire::actingAs($admin)
        ->test(ArchiveImporter::class)
        ->set('activeImportId', $record->id)
        ->assertSee('queued.koma.zip')
        ->assertSee('Waiting for an archive-import worker.');
    Queue::assertPushed(ImportSeriesArchive::class, fn (ImportSeriesArchive $job) => $job->importId === $record->id);
});

test('archive intake stays single-file unless a plugin opts in with a strict true', function () {
    Queue::fake();
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'single-default', false, 1);
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    expect(app(ArchiveImportBatchOptions::class)->details())->toBe(['enabled' => false, 'max_files' => 1]);

    // A truthy value is not consent, and a file count alone enables nothing.
    Hook::addFilter('archive.import.multiple_enabled', fn (bool $enabled) => 1);
    Hook::addFilter('archive.import.multiple_max_files', fn (int $maximum): int => 9);
    expect(app(ArchiveImportBatchOptions::class)->details())->toBe(['enabled' => false, 'max_files' => 1]);

    $this->actingAs($admin)
        ->get(route('admin.archives.import'))
        ->assertOk()
        ->assertSee('name="archive"', false)
        ->assertDontSee('name="archives[]"', false)
        ->assertSee('Replace selected');

    $this->from(route('admin.archives.import'))
        ->post(route('admin.archives.import.store'), [
            'archives' => [
                UploadedFile::fake()->createWithContent('unsolicited.koma.zip', File::get($fixture['archive'])),
            ],
            'conflict_policy' => ArchiveImportPolicy::FAIL,
        ])
        ->assertRedirect(route('admin.archives.import'))
        ->assertSessionHasErrors('archive');

    expect(SeriesArchiveImport::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Queue::assertNothingPushed();
});

test('a batch filter that throws leaves archive intake single-file', function () {
    Hook::addFilter('archive.import.multiple_enabled', function (bool $enabled): bool {
        throw new RuntimeException('plugin failure');
    });

    expect(app(ArchiveImportBatchOptions::class)->details())->toBe(['enabled' => false, 'max_files' => 1]);
});

test('batch intake clamps the plugin file count to runtime and application limits', function () {
    $runtime = (int) ini_get('max_file_uploads');
    $ceiling = $runtime > 0 ? min(25, $runtime) : 25;
    $requested = 12;

    Hook::addFilter('archive.import.multiple_enabled', fn (bool $enabled): bool => true);
    expect(app(ArchiveImportBatchOptions::class)->details())
        ->toBe(['enabled' => true, 'max_files' => min(12, $ceiling)]);

    Hook::addFilter('archive.import.multiple_max_files', function (int $maximum) use (&$requested): int {
        return $requested;
    });

    foreach ([0 => 1, -4 => 1, 2 => min(2, $ceiling), 5000 => $ceiling] as $requested => $expected) {
        expect(app(ArchiveImportBatchOptions::class)->details())
            ->toBe(['enabled' => true, 'max_files' => $expected]);
    }
});

test('an opted-in plugin queues each selected archive as its own import', function () {
    Queue::fake();
    Hook::addFilter('archive.import.multiple_enabled', fn (bool $enabled): bool => true);
    Hook::addFilter('archive.import.multiple_max_files', fn (int $maximum): int => 2);
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'batch-source', false, 1);
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $upload = fn (string $name): UploadedFile => UploadedFile::fake()
        ->createWithContent($name, File::get($fixture['archive']));

    $this->actingAs($admin)
        ->get(route('admin.archives.import'))
        ->assertOk()
        ->assertSee('Choose up to 2 Koma or supported gallery archives')
        ->assertSee('name="archives[]"', false)
        ->assertSee(' multiple ', false)
        ->assertDontSee('Replace selected')
        ->assertDontSee('id="replacement_series_id"', false);

    $this->from(route('admin.archives.import'))
        ->post(route('admin.archives.import.store'), [
            'archives' => [$upload('one.koma.zip'), $upload('two.koma.zip'), $upload('three.koma.zip')],
            'conflict_policy' => ArchiveImportPolicy::FAIL,
        ])
        ->assertRedirect(route('admin.archives.import'))
        ->assertSessionHasErrors('archives');
    expect(session('errors')->first('archives'))->toBe('Choose no more than 2 archives in one batch.');

    // Replacing one explicit series stays a single-archive action.
    $this->from(route('admin.archives.import'))
        ->post(route('admin.archives.import.store'), [
            'archives' => [$upload('one.koma.zip')],
            'conflict_policy' => ArchiveImportPolicy::REPLACE,
            'replacement_series_id' => $fixture['series']->id,
            'replace_confirmed' => '1',
        ])
        ->assertSessionHasErrors('conflict_policy');

    $this->from(route('admin.archives.import'))
        ->post(route('admin.archives.import.store'), [
            'archive' => $upload('one.koma.zip'),
            'conflict_policy' => ArchiveImportPolicy::FAIL,
        ])
        ->assertSessionHasErrors('archives');

    expect(SeriesArchiveImport::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Queue::assertNothingPushed();

    $response = $this->post(route('admin.archives.import.store'), [
        'archives' => [$upload('a-first.koma.zip'), $upload('b-second.koma.zip')],
        'conflict_policy' => ArchiveImportPolicy::CREATE_UNIQUE,
        // A crafted request cannot smuggle a replacement target into a batch.
        'replacement_series_id' => $fixture['series']->id,
        'replace_confirmed' => '1',
    ]);

    $records = SeriesArchiveImport::query()
        ->where('user_id', $admin->id)
        ->orderBy('source_name')
        ->get();

    expect($records)->toHaveCount(2)
        ->and($records->pluck('source_name')->all())->toBe(['a-first.koma.zip', 'b-second.koma.zip'])
        ->and($records->pluck('status')->unique()->all())->toBe([SeriesArchiveImport::STATUS_QUEUED])
        ->and($records->pluck('conflict_policy')->unique()->all())->toBe([ArchiveImportPolicy::CREATE_UNIQUE])
        ->and($records->pluck('replacement_series_id')->filter()->all())->toBe([])
        ->and($records->pluck('replace_confirmed')->unique()->all())->toBe([false])
        ->and($records->pluck('source_path')->unique())->toHaveCount(2);

    foreach ($records as $record) {
        expect(Storage::disk('local')->exists($record->source_path))->toBeTrue();
        Queue::assertPushed(
            ImportSeriesArchive::class,
            fn (ImportSeriesArchive $job) => $job->importId === $record->id,
        );
    }

    $response
        ->assertRedirect(route('admin.archives.import', ['import' => $records->last()->id]))
        ->assertSessionHas('status', '2 archives retained and queued for validation.');
    Queue::assertPushed(ImportSeriesArchive::class, 2);
});

test('batch intake retains nothing when one archive cannot be recorded', function () {
    Queue::fake();
    Hook::addFilter('archive.import.multiple_enabled', fn (bool $enabled): bool => true);
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'batch-rollback', false, 1);
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $created = 0;
    SeriesArchiveImport::creating(function () use (&$created): void {
        if (++$created === 2) {
            throw new RuntimeException('import ledger unavailable');
        }
    });

    $this->actingAs($admin)
        ->from(route('admin.archives.import'))
        ->post(route('admin.archives.import.store'), [
            'archives' => [
                UploadedFile::fake()->createWithContent('kept-out-1.koma.zip', File::get($fixture['archive'])),
                UploadedFile::fake()->createWithContent('kept-out-2.koma.zip', File::get($fixture['archive'])),
            ],
            'conflict_policy' => ArchiveImportPolicy::FAIL,
        ])
        ->assertRedirect(route('admin.archives.import'))
        ->assertSessionHasErrors('archives');

    expect(session('errors')->first('archives'))
        ->toBe('The archive batch could not be queued. No import was started.')
        ->and($created)->toBe(2)
        ->and(SeriesArchiveImport::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Queue::assertNothingPushed();
});

test('an over-long client file name is shortened instead of failing the intake', function () {
    Queue::fake();
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'long-name', false, 1);
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    $this->actingAs($admin)->post(route('admin.archives.import.store'), [
        'archive' => UploadedFile::fake()
            ->createWithContent(str_repeat('é', 300).'.koma.zip', File::get($fixture['archive'])),
        'conflict_policy' => ArchiveImportPolicy::FAIL,
    ])->assertSessionHasNoErrors();

    expect(SeriesArchiveImport::query()->sole()->source_name)->toBe(str_repeat('é', 255));
    Queue::assertPushed(ImportSeriesArchive::class, 1);
});

test('intake keeps a retained source retryable when queue dispatch fails', function () {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'dispatch-failure', false, 1);
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    // A database queue whose table is unavailable rejects every push.
    config(['queue.default' => 'database', 'queue.connections.database.table' => 'unavailable_jobs']);

    $response = $this->actingAs($admin)->post(route('admin.archives.import.store'), [
        'archive' => UploadedFile::fake()->createWithContent('stranded.koma.zip', File::get($fixture['archive'])),
        'conflict_policy' => ArchiveImportPolicy::FAIL,
    ]);

    $record = SeriesArchiveImport::query()->sole();
    expect($record->status)->toBe(SeriesArchiveImport::STATUS_FAILED)
        ->and($record->stage)->toBe('Queue unavailable')
        ->and($record->error)->toBe('Koma could not dispatch this archive to an import worker.')
        ->and($record->completed_at)->not->toBeNull()
        ->and(Storage::disk('local')->exists($record->source_path))->toBeTrue();
    // Reported as an error, never as the success banner.
    $response
        ->assertRedirect(route('admin.archives.import', ['import' => $record->id]))
        ->assertSessionMissing('status')
        ->assertSessionHas(
            'error',
            'Archive retained, but Koma could not queue it. Use Retry import after the queue issue is resolved.',
        );

    Queue::fake();
    Livewire::actingAs($admin)
        ->test(ArchiveImporter::class)
        ->set('activeImportId', $record->id)
        ->assertSee('Koma could not dispatch this archive to an import worker.')
        ->assertSee('Start an archive worker, then retry the retained source.')
        ->assertSee('Retry import')
        ->call('retryImport', $record->id);

    expect($record->fresh()->status)->toBe(SeriesArchiveImport::STATUS_QUEUED);
    Queue::assertPushed(ImportSeriesArchive::class, fn (ImportSeriesArchive $job) => $job->importId === $record->id);
});

test('a retry whose dispatch fails stays retryable instead of stranding the import', function () {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'retry-dispatch', false, 1);
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $sourcePath = 'archive-imports/source/retry.koma.zip';
    Storage::disk('local')->put($sourcePath, File::get($fixture['archive']));
    $record = SeriesArchiveImport::create([
        'user_id' => $admin->id,
        'source_name' => 'retry.koma.zip',
        'source_path' => $sourcePath,
        'status' => SeriesArchiveImport::STATUS_FAILED,
        'conflict_policy' => ArchiveImportPolicy::FAIL,
        'stage' => 'Worker stopped',
        'error' => 'The retained source archive can be retried after the worker issue is resolved.',
        'completed_at' => now(),
    ]);
    // A database queue whose table is unavailable rejects every push.
    config(['queue.default' => 'database', 'queue.connections.database.table' => 'unavailable_jobs']);

    Livewire::actingAs($admin)
        ->test(ArchiveImporter::class)
        ->call('retryImport', $record->id)
        ->assertHasErrors('archive')
        ->assertSet('activeImportId', $record->id)
        ->assertSee('Koma could not dispatch this archive to an import worker.')
        ->assertSee('Retry import')
        ->assertSee('Discard source');

    $record->refresh();
    expect($record->status)->toBe(SeriesArchiveImport::STATUS_FAILED)
        ->and($record->stage)->toBe('Queue unavailable')
        ->and($record->completed_at)->not->toBeNull()
        ->and($record->source_path)->toBe($sourcePath)
        ->and(Storage::disk('local')->exists($sourcePath))->toBeTrue();

    Queue::fake();
    Livewire::actingAs($admin)
        ->test(ArchiveImporter::class)
        ->call('retryImport', $record->id)
        ->assertHasNoErrors();

    expect($record->fresh()->status)->toBe(SeriesArchiveImport::STATUS_QUEUED);
    Queue::assertPushed(ImportSeriesArchive::class, fn (ImportSeriesArchive $job) => $job->importId === $record->id);
});

test('batch intake reports only the archives whose queue dispatch failed', function () {
    Hook::addFilter('archive.import.multiple_enabled', fn (bool $enabled): bool => true);
    Hook::addFilter('archive.import.multiple_max_files', fn (int $maximum): int => 3);
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'batch-dispatch', false, 1);
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $dispatched = 0;
    Bus::shouldReceive('dispatch')->times(3)->andReturnUsing(function () use (&$dispatched): void {
        if (++$dispatched === 2) {
            throw new RuntimeException('queue unavailable');
        }
    });

    $response = $this->actingAs($admin)->post(route('admin.archives.import.store'), [
        'archives' => [
            UploadedFile::fake()->createWithContent('a.koma.zip', File::get($fixture['archive'])),
            UploadedFile::fake()->createWithContent('b.koma.zip', File::get($fixture['archive'])),
            UploadedFile::fake()->createWithContent('c.koma.zip', File::get($fixture['archive'])),
        ],
        'conflict_policy' => ArchiveImportPolicy::FAIL,
    ]);

    $records = SeriesArchiveImport::query()->orderBy('source_name')->get();

    expect($records->pluck('status', 'source_name')->all())->toBe([
        'a.koma.zip' => SeriesArchiveImport::STATUS_QUEUED,
        'b.koma.zip' => SeriesArchiveImport::STATUS_FAILED,
        'c.koma.zip' => SeriesArchiveImport::STATUS_QUEUED,
    ])->and($records->firstWhere('source_name', 'b.koma.zip')->stage)->toBe('Queue unavailable');

    foreach ($records as $record) {
        expect(Storage::disk('local')->exists($record->source_path))->toBeTrue();
    }

    $response->assertSessionMissing('status')->assertSessionHas(
        'error',
        '2 of 3 archives queued for validation. 1 retained archive could not be queued; use Retry import on each after the queue issue is resolved.',
    );
});

test('queue job records completed progress counts and removes a successful source upload', function () {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'job-success', false, 1);
    $fixture['series']->delete();
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $sourcePath = 'archive-imports/source/success.koma.zip';
    Storage::disk('local')->put($sourcePath, File::get($fixture['archive']));
    $record = SeriesArchiveImport::create([
        'user_id' => $admin->id,
        'source_name' => 'success.koma.zip',
        'source_path' => $sourcePath,
        'status' => 'queued',
        'conflict_policy' => 'fail',
    ]);

    (new ImportSeriesArchive($record->id))->handle(app(SeriesArchiveImporter::class));
    $record->refresh();

    expect($record->status)->toBe(SeriesArchiveImport::STATUS_COMPLETED)
        ->and($record->progress)->toBe(100)
        ->and($record->resultSeries)->not->toBeNull()
        ->and($record->counts)->toBe(['series' => 1, 'genres' => 2, 'tags' => 2, 'chapters' => 3, 'pages' => 1, 'media' => 1])
        ->and($record->source_path)->toBeNull()
        ->and(Storage::disk('local')->exists($sourcePath))->toBeFalse();

    Livewire::actingAs($admin)
        ->test(ArchiveImporter::class)
        ->set('activeImportId', $record->id)
        ->assertSee('2 genres · 2 tags · 3 chapters · 1 pages');
});

test('queue job records a terminal failure and retains the source archive', function () {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'job-conflict', false, 1);
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $sourcePath = 'archive-imports/source/failure.koma.zip';
    Storage::disk('local')->put($sourcePath, File::get($fixture['archive']));
    $record = SeriesArchiveImport::create([
        'user_id' => $admin->id,
        'source_name' => 'failure.koma.zip',
        'source_path' => $sourcePath,
        'status' => 'queued',
        'conflict_policy' => 'fail',
    ]);

    (new ImportSeriesArchive($record->id))->handle(app(SeriesArchiveImporter::class));
    $record->refresh();

    expect($record->status)->toBe(SeriesArchiveImport::STATUS_FAILED)
        ->and($record->stage)->toBe('Failed safely')
        ->and($record->error)->toContain('already uses the slug')
        ->and($record->source_path)->toBe($sourcePath)
        ->and(Storage::disk('local')->exists($sourcePath))->toBeTrue()
        ->and($fixture['series']->fresh())->not->toBeNull();

    Livewire::actingAs($admin)
        ->test(ArchiveImporter::class)
        ->set('activeImportId', $record->id)
        ->assertSee('Why it failed')
        ->assertSee('What to do next')
        ->assertSee('Choose “Create unique,”')
        ->assertSee("Import reference: {$record->id}");
});

/**
 * Queue an import for the fixture under one conflict policy.
 *
 * @param  array{series:Series,archive:string}  $fixture
 */
function p10QueuedImport(array $fixture, User $admin, string $policy): SeriesArchiveImport
{
    $sourcePath = 'archive-imports/source/'.Str::uuid().'.koma.zip';
    Storage::disk('local')->put($sourcePath, File::get($fixture['archive']));

    if ($policy === ArchiveImportPolicy::FAIL) {
        $fixture['series']->delete();
    }

    return SeriesArchiveImport::create([
        'user_id' => $admin->id,
        'source_name' => 'interrupted.koma.zip',
        'source_path' => $sourcePath,
        'status' => SeriesArchiveImport::STATUS_QUEUED,
        'conflict_policy' => $policy,
        'replacement_series_id' => $policy === ArchiveImportPolicy::REPLACE ? $fixture['series']->id : null,
        'replace_confirmed' => $policy === ArchiveImportPolicy::REPLACE,
    ]);
}

test('an import interrupted after its content committed yields one result when it runs again', function (string $policy, int $expectedSeries) {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'interrupted', false, 1);
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $record = p10QueuedImport($fixture, $admin, $policy);
    // From the importer's final progress report onward, every write to the
    // import record fails: the content is committed but nothing can say so.
    $killed = false;
    SeriesArchiveImport::saving(function (SeriesArchiveImport $import) use (&$killed): void {
        $killed = $killed || $import->stage === 'Completed';

        if ($killed) {
            throw new RuntimeException('simulated worker kill');
        }
    });

    expect(fn () => (new ImportSeriesArchive($record->id))->handle(app(SeriesArchiveImporter::class)))
        ->toThrow(RuntimeException::class, 'simulated worker kill');

    $interrupted = $record->fresh();
    $imported = Series::query()->findOrFail($interrupted->result_series_id);

    expect($interrupted->isTerminal())->toBeFalse()
        ->and($interrupted->counts)->toMatchArray(['series' => 1, 'chapters' => 3, 'pages' => 1])
        ->and(Series::query()->count())->toBe($expectedSeries);

    // The worker comes back and the queue hands the same import out again.
    SeriesArchiveImport::flushEventListeners();
    (new ImportSeriesArchive($record->id))->handle(app(SeriesArchiveImporter::class));
    $record->refresh();

    expect($record->status)->toBe(SeriesArchiveImport::STATUS_COMPLETED)
        ->and($record->result_series_id)->toBe($imported->id)
        ->and($record->error)->toBeNull()
        ->and($record->source_path)->toBeNull()
        ->and(Series::query()->count())->toBe($expectedSeries)
        ->and($imported->fresh()->chapters()->count())->toBe(3);
})->with([
    'fail' => [ArchiveImportPolicy::FAIL, 1],
    'create unique' => [ArchiveImportPolicy::CREATE_UNIQUE, 2],
    'replace' => [ArchiveImportPolicy::REPLACE, 1],
]);

test('a stopped worker does not mark an import failed once its content is committed', function () {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'stopped-worker', false, 1);
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $record = p10QueuedImport($fixture, $admin, ArchiveImportPolicy::CREATE_UNIQUE);
    $killed = false;
    SeriesArchiveImport::saving(function (SeriesArchiveImport $import) use (&$killed): void {
        $killed = $killed || $import->stage === 'Completed';

        if ($killed) {
            throw new RuntimeException('simulated worker kill');
        }
    });

    try {
        (new ImportSeriesArchive($record->id))->handle(app(SeriesArchiveImporter::class));
    } catch (RuntimeException) {
        // The queue would now report this job as failed.
    }

    SeriesArchiveImport::flushEventListeners();
    (new ImportSeriesArchive($record->id))->failed(new RuntimeException('worker stopped'));
    $record->refresh();

    expect($record->status)->toBe(SeriesArchiveImport::STATUS_COMPLETED)
        ->and($record->stage)->toBe('Completed')
        ->and($record->resultSeries->slug)->toBe('stopped-worker-2')
        ->and(Series::query()->count())->toBe(2);
});

test('an import that fails after committing is reported completed in the same run', function () {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'late-failure', false, 1);
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $record = p10QueuedImport($fixture, $admin, ArchiveImportPolicy::FAIL);
    $failed = false;
    SeriesArchiveImport::saving(function (SeriesArchiveImport $import) use (&$failed): void {
        if (! $failed && $import->stage === 'Completed') {
            $failed = true;

            throw new RuntimeException('one lost write');
        }
    });

    (new ImportSeriesArchive($record->id))->handle(app(SeriesArchiveImporter::class));
    $record->refresh();

    expect($failed)->toBeTrue()
        ->and($record->status)->toBe(SeriesArchiveImport::STATUS_COMPLETED)
        ->and($record->error)->toBeNull()
        ->and($record->resultSeries->slug)->toBe('late-failure')
        ->and(Series::query()->count())->toBe(1);
});

test('a rolled back import leaves no result link and can be retried normally', function () {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'no-link', false, 1);
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    expect(fn () => app(SeriesArchiveImporter::class)->import(
        $fixture['archive'],
        ArchiveImportPolicy::CREATE_UNIQUE,
        committing: fn () => throw new RuntimeException('ledger unavailable'),
    ))->toThrow(ArchiveException::class, 'rolled back safely');

    expect(Series::query()->pluck('slug')->all())->toBe(['no-link'])
        ->and(Storage::disk('media')->allFiles('imports'))->toBe([]);

    $record = p10QueuedImport($fixture, $admin, ArchiveImportPolicy::CREATE_UNIQUE);
    $record->update(['conflict_policy' => ArchiveImportPolicy::FAIL]);
    (new ImportSeriesArchive($record->id))->handle(app(SeriesArchiveImporter::class));

    expect($record->fresh()->status)->toBe(SeriesArchiveImport::STATUS_FAILED)
        ->and($record->fresh()->result_series_id)->toBeNull();

    // Retrying under a policy that can succeed imports exactly once.
    $record->refresh()->update([
        'status' => SeriesArchiveImport::STATUS_QUEUED,
        'conflict_policy' => ArchiveImportPolicy::CREATE_UNIQUE,
    ]);
    (new ImportSeriesArchive($record->id))->handle(app(SeriesArchiveImporter::class));

    expect($record->fresh()->status)->toBe(SeriesArchiveImport::STATUS_COMPLETED)
        ->and($record->fresh()->resultSeries->slug)->toBe('no-link-2')
        ->and(Series::query()->count())->toBe(2);
});

test('media is kept when the failure happens at commit, after every row was written', function () {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'commit-ambiguity', false, 1);
    $fixture['series']->delete();
    // The callback completes, then the connection is lost while committing:
    // the server may or may not have applied it.
    DB::shouldReceive('transaction')->once()->andReturnUsing(function (Closure $callback): never {
        $callback();

        throw new RuntimeException('connection lost during commit');
    });

    expect(fn () => app(SeriesArchiveImporter::class)->import($fixture['archive']))
        ->toThrow(ArchiveException::class, 'connection lost during commit');

    $series = Series::query()->where('slug', 'commit-ambiguity')->sole();

    expect(Storage::disk('media')->allFiles('imports'))->not->toBeEmpty()
        ->and(Storage::disk('media')->exists($series->chapters()->orderBy('number')->first()->pages()->first()->image_path))
        ->toBeTrue();
});

test('a timeout handler running inside the open import does not adopt its uncommitted result', function () {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'in-process-timeout', false, 1);
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $record = p10QueuedImport($fixture, $admin, ArchiveImportPolicy::FAIL);
    $sourcePath = $record->source_path;
    $interrupted = false;
    // The queue's timeout handler calls failed() in the worker process. Do
    // that at the worst moment: the result link is written, nothing is committed.
    DB::listen(function ($query) use (&$interrupted, $record): void {
        if ($interrupted || ! str_contains($query->sql, 'result_series_id') || ! str_starts_with($query->sql, 'update')) {
            return;
        }

        $interrupted = true;
        (new ImportSeriesArchive($record->id))->failed(new RuntimeException('job timed out'));

        throw new RuntimeException('worker killed after timeout');
    });

    (new ImportSeriesArchive($record->id))->handle(app(SeriesArchiveImporter::class));
    $record->refresh();

    expect($interrupted)->toBeTrue()
        ->and(Series::query()->count())->toBe(0)
        ->and($record->status)->not->toBe(SeriesArchiveImport::STATUS_COMPLETED)
        ->and($record->result_series_id)->toBeNull()
        ->and($record->source_path)->toBe($sourcePath)
        ->and(Storage::disk('local')->exists($sourcePath))->toBeTrue();
});

test('queue worker rechecks authorization and retains an unauthorized source', function () {
    $fixture = p7ArchiveFixture($this->archiveDirectory, 'worker-auth-source', false, 1);
    $fixture['series']->delete();
    $member = User::factory()->create();
    $member->assignRole('Member');
    $sourcePath = 'archive-imports/source/worker-auth.koma.zip';
    Storage::disk('local')->put($sourcePath, File::get($fixture['archive']));
    $record = SeriesArchiveImport::create([
        'user_id' => $member->id,
        'source_name' => 'worker-auth.koma.zip',
        'source_path' => $sourcePath,
        'status' => 'queued',
        'conflict_policy' => 'fail',
    ]);

    (new ImportSeriesArchive($record->id))->handle(app(SeriesArchiveImporter::class));
    $record->refresh();

    expect($record->status)->toBe(SeriesArchiveImport::STATUS_FAILED)
        ->and($record->error)->toContain('failed unexpectedly')
        ->and($record->source_path)->toBe($sourcePath)
        ->and(Storage::disk('local')->exists($sourcePath))->toBeTrue()
        ->and(Series::query()->where('slug', 'worker-auth-source')->exists())->toBeFalse();
});

/**
 * Stands in for a plugin-supplied adapter. It claims any version-1 manifest —
 * exactly the overlap the ordering rule exists to resolve — and marks the Koma
 * manifest so a test can tell which adapter produced it.
 */
final class P7PluginGalleryAdapter implements GalleryArchiveAdapter
{
    public static bool $used = false;

    public function __construct(private GalleryArchiveV1Adapter $generic) {}

    public function supports(array $manifest): bool
    {
        return ($manifest['version'] ?? null) === 1;
    }

    public function pages(array $manifest): array
    {
        return $this->generic->pages($manifest);
    }

    public function toKomaManifest(array $source, array $media, ?CarbonImmutable $importedAt = null): array
    {
        self::$used = true;
        $manifest = $this->generic->toKomaManifest($source, $media, $importedAt);
        $manifest['series']['title'] = 'Claimed By Plugin';

        return $manifest;
    }
}

/** A plugin adapter that blows up while sniffing must not fail an import it may not even own. */
final class P7ExplodingGalleryAdapter implements GalleryArchiveAdapter
{
    public function supports(array $manifest): bool
    {
        throw new RuntimeException('adapter exploded while sniffing');
    }

    public function pages(array $manifest): array
    {
        throw new RuntimeException('never reached');
    }

    public function toKomaManifest(array $source, array $media, ?CarbonImmutable $importedAt = null): array
    {
        throw new RuntimeException('never reached');
    }
}

test('a plugin adapter is offered a gallery manifest before the built-in adapter', function () {
    P7PluginGalleryAdapter::$used = false;
    Hook::addFilter(
        'archive.gallery_adapters',
        fn (array $adapters) => [...$adapters, app(P7PluginGalleryAdapter::class)],
    );

    $fixture = p7ExternalGalleryFixture($this->archiveDirectory);
    $series = app(SeriesArchiveImporter::class)->import($fixture['archive'])->series->fresh();

    expect(P7PluginGalleryAdapter::$used)->toBeTrue()
        ->and($series->title)->toBe('Claimed By Plugin');
});

test('a plugin adapter that throws while sniffing is skipped and the built-in still imports', function () {
    Hook::addFilter(
        'archive.gallery_adapters',
        fn (array $adapters) => [...$adapters, new P7ExplodingGalleryAdapter],
    );

    $fixture = p7ExternalGalleryFixture($this->archiveDirectory);
    $series = app(SeriesArchiveImporter::class)->import($fixture['archive'])->series->fresh();

    expect($series->title)->toBe('External Gallery');
});

test('native koma archives ignore gallery adapters entirely', function () {
    P7PluginGalleryAdapter::$used = false;
    Hook::addFilter(
        'archive.gallery_adapters',
        fn (array $adapters) => [...$adapters, app(P7PluginGalleryAdapter::class)],
    );

    $fixture = p7ArchiveFixture($this->archiveDirectory, 'adapter-bypass');
    $archive = $this->archiveDirectory.'/adapter-bypass.koma.zip';
    app(SeriesArchiveExporter::class)->export($fixture['series'], $archive);
    $fixture['series']->delete();

    $series = app(SeriesArchiveImporter::class)->import($archive)->series->fresh();

    expect(P7PluginGalleryAdapter::$used)->toBeFalse()
        ->and($series->title)->not->toBe('Claimed By Plugin');
});
