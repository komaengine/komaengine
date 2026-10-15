<?php

use App\Media\ImageProcessor;
use App\Models\ApiToken;
use App\Models\Chapter;
use App\Models\Page;
use App\Models\Series;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File as TestFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('bulk API atomically uploads the five-chapter real manga fixture', function () {
    $fixtureRoot = getenv('KOMA_REAL_IMAGE_FIXTURE') ?: base_path('test-data/real-images');
    $manifestPath = $fixtureRoot.'/manifest.json';

    if (! is_file($manifestPath) || (glob($fixtureRoot.'/chapter-*', GLOB_ONLYDIR) ?: []) === []) {
        $this->markTestSkipped('Local authorized real-image fixture is not installed.');
    }

    $this->seed(RolePermissionSeeder::class);
    $uploader = User::factory()->create();
    $uploader->assignRole('Uploader');
    $plainTextToken = ApiToken::issue($uploader, 'Real image API test')['plain_text_token'];
    $series = Series::create([
        'title' => 'Real Image Fixture API Smoke Test',
        'author' => 'Test fixture',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
    ]);
    $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    $sourcePagesByChapter = [];
    $payload = ['publish' => true, 'chapters' => []];

    Storage::fake('media');

    foreach ($manifest['chapters'] as $index => $fixtureChapter) {
        $sourcePages = glob(
            $fixtureRoot.'/'.$fixtureChapter['folder'].'/*.{jpg,jpeg,png,webp}',
            GLOB_BRACE,
        ) ?: [];
        sort($sourcePages, SORT_NATURAL);
        $sourcePagesByChapter[$fixtureChapter['number']] = $sourcePages;

        $payload['chapters'][] = [
            'number' => $fixtureChapter['number'],
            'title' => $fixtureChapter['title'],
            'scanlation_group' => $fixtureChapter['scanlation_group'],
            'pages' => array_map(function (string $path): TestFile {
                $stream = fopen($path, 'rb');

                if ($stream === false) {
                    throw new RuntimeException("Unable to open fixture page: {$path}");
                }

                return new TestFile(basename($path), $stream);
            }, $sourcePages),
        ];

        expect($sourcePages)->toHaveCount($fixtureChapter['page_count'])
            ->and($payload['chapters'][$index]['number'])->toBe($fixtureChapter['number']);
    }

    $this->withToken($plainTextToken)
        ->post(route('api.v1.series.chapters.bulk', $series), $payload, ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('meta.created', 5)
        ->assertJsonPath('meta.atomic', true)
        ->assertJsonCount(5, 'data.chapters');

    $chapters = Chapter::query()->whereBelongsTo($series)->with('pages')->orderBy('number')->get();

    expect($chapters)->toHaveCount(5)
        ->and(Page::query()->count())->toBe(64)
        ->and(Storage::disk('media')->allFiles())->toHaveCount(64);

    foreach ($chapters as $chapter) {
        $sourcePages = $sourcePagesByChapter[$chapter->displayNumber()];

        expect($chapter->status)->toBe('published')
            ->and($chapter->pages)->toHaveCount(count($sourcePages))
            ->and($chapter->pages->pluck('sort_order')->all())->toBe(range(1, count($sourcePages)));

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

            expect($decoded)->toBeArray()
                ->and(pathinfo($page->image_path, PATHINFO_EXTENSION))->toBe($expectedExtension)
                ->and($page->width)->toBe($decoded[0])
                ->and($page->height)->toBe($decoded[1])
                ->and($page->width)->toBeLessThanOrEqual((int) config('koma.images.pages.max_width'))
                ->and($page->height)->toBeLessThanOrEqual((int) config('koma.images.pages.max_height'))
                ->and($page->file_size)->toBe(strlen($storedBytes));
        }
    }
})->group('real-images');
