<?php

use App\Archives\ArchiveImportPolicy;
use App\Archives\SeriesArchiveExporter;
use App\Archives\SeriesArchiveImporter;
use App\Community\CommentModerationService;
use App\Community\CommentReportService;
use App\Media\ImageProcessor;
use App\Models\Chapter;
use App\Models\Comment;
use App\Models\CommentReport;
use App\Models\Series;
use App\Models\User;
use App\Publishing\BulkChapterUploadService;
use App\Publishing\ChapterPageService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File as TestFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function workflowFixtureUpload(string $path): TestFile
{
    $stream = fopen($path, 'rb');

    if ($stream === false) {
        throw new RuntimeException("Unable to open fixture image: {$path}");
    }

    return new TestFile(basename($path), $stream);
}

test('the real fixture completes the combined publishing workflow', function () {
    $fixtureRoot = getenv('KOMA_REAL_IMAGE_FIXTURE') ?: base_path('test-data/real-images');
    $manifestPath = $fixtureRoot.'/manifest.json';

    if (! is_file($manifestPath) || (glob($fixtureRoot.'/chapter-*', GLOB_ONLYDIR) ?: []) === []) {
        $this->markTestSkipped('Local authorized real-image fixture is not installed.');
    }

    $this->seed(RolePermissionSeeder::class);
    Storage::fake('media');

    $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    $series = Series::create([
        'title' => 'Real Image Fixture Combined Workflow',
        'author' => 'Authorized test fixture',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
        'is_public' => true,
    ]);
    $chapterPayloads = [];
    $sourcePages = [];

    foreach ($manifest['chapters'] as $fixtureChapter) {
        $chapterPages = glob(
            $fixtureRoot.'/'.$fixtureChapter['folder'].'/*.{jpg,jpeg,png,webp}',
            GLOB_BRACE,
        ) ?: [];
        sort($chapterPages, SORT_NATURAL);
        $sourcePages = [...$sourcePages, ...$chapterPages];
        $chapterPayloads[] = [
            'number' => $fixtureChapter['number'],
            'title' => $fixtureChapter['title'],
            'scanlation_group' => $fixtureChapter['scanlation_group'],
            'pages' => array_map(workflowFixtureUpload(...), $chapterPages),
            'publish' => true,
        ];
    }

    $chapters = app(BulkChapterUploadService::class)->store($series, $chapterPayloads);
    expect($manifest['language'])->toBe('en')
        ->and($chapters)->toHaveCount(5)
        ->and(collect($chapters)->sum(fn (Chapter $chapter) => $chapter->pages->count()))->toBe(64);

    $scheduled = $chapters[4];
    $scheduled->unpublish();
    $scheduled->scheduleFor(now()->addHour());
    expect($scheduled->fresh()->isScheduled())->toBeTrue();
    $scheduled->forceFill(['published_at' => now()->subMinute()])->save();
    expect($scheduled->publishDue())->toBeTrue()
        ->and($scheduled->fresh()->isPublished())->toBeTrue();

    $chapter = $chapters[0]->fresh('pages');
    $originalOrder = $chapter->pages->pluck('id')->all();
    $reordered = app(ChapterPageService::class)->reorder($chapter, array_reverse($originalOrder));
    expect($reordered->pluck('id')->all())->toBe(array_reverse($originalOrder))
        ->and($reordered->pluck('sort_order')->all())->toBe(range(1, count($originalOrder)));

    $page = $reordered->first();
    $oldPath = $page->image_path;
    $replacement = app(ChapterPageService::class)->replace(
        $chapter,
        $page,
        workflowFixtureUpload($sourcePages[0]),
    );
    $replacementBytes = Storage::disk('media')->get($replacement->image_path);
    $replacementInfo = getimagesizefromstring($replacementBytes);
    expect($replacement->image_path)->not->toBe($oldPath)
        ->and(Storage::disk('media')->exists($oldPath))->toBeFalse()
        ->and($replacementInfo)->toBeArray()
        ->and($replacement->width)->toBe($replacementInfo[0])
        ->and($replacement->height)->toBe($replacementInfo[1])
        ->and($replacement->file_size)->toBe(strlen($replacementBytes));

    $processedCover = app(ImageProcessor::class)->processCover(workflowFixtureUpload($sourcePages[1]));
    expect($processedCover->cover->width)->toBeGreaterThan(0)
        ->and($processedCover->cover->height)->toBeGreaterThan(0)
        ->and($processedCover->thumbnail->width)->toBeLessThanOrEqual($processedCover->cover->width)
        ->and($processedCover->thumbnail->height)->toBeLessThanOrEqual($processedCover->cover->height);

    $author = User::factory()->create(['reader_mode' => null]);
    $author->assignRole('Member');
    $reporter = User::factory()->create();
    $reporter->assignRole('Member');
    $moderator = User::factory()->create();
    $moderator->assignRole('Moderator');
    $comment = $series->comments()->create([
        'user_id' => $author->id,
        'body' => 'Combined workflow comment.',
        'status' => Comment::STATUS_APPROVED,
    ]);
    $report = app(CommentReportService::class)->report($reporter, $comment, 'spam', 'Workflow audit report.');
    app(CommentModerationService::class)->moderate(
        $moderator,
        $comment,
        CommentModerationService::ACTION_HIDE,
        'Workflow audit moderation.',
    );
    expect($comment->fresh()->status)->toBe(Comment::STATUS_HIDDEN)
        ->and($report->fresh()->status)->toBe(CommentReport::STATUS_RESOLVED)
        ->and($comment->fresh()->moderated_by)->toBe($moderator->id);

    $this->actingAs($author)
        ->putJson(route('reader.preferences.mode'), ['mode' => 'vertical'])
        ->assertOk()
        ->assertJsonPath('mode', 'vertical');
    expect($author->fresh()->reader_mode)->toBe('vertical');

    $archiveDirectory = storage_path('framework/testing/combined-workflow-'.Str::uuid());
    $archivePath = $archiveDirectory.'/fixture.koma.zip';
    File::ensureDirectoryExists($archiveDirectory);

    try {
        $export = app(SeriesArchiveExporter::class)->export($series, $archivePath);
        $import = app(SeriesArchiveImporter::class)->import($archivePath, ArchiveImportPolicy::CREATE_UNIQUE);
        $imported = $import->series->load('chapters.pages');

        expect(is_file($export->path))->toBeTrue()
            ->and($export->bytes)->toBe(filesize($archivePath))
            ->and($export->sha256)->toBe(hash_file('sha256', $archivePath))
            ->and($imported->slug)->toBe($series->slug.'-2')
            ->and($imported->chapters)->toHaveCount(5)
            ->and($imported->chapters->sum(fn (Chapter $item) => $item->pages->count()))->toBe(64)
            ->and($imported->is_public)->toBeTrue()
            ->and($imported->content_rating)->toBe('safe')
            ->and($import->counts)->toBe([
                'series' => 1,
                'genres' => 0,
                'tags' => 0,
                'chapters' => 5,
                'pages' => 64,
                'media' => 64,
            ]);
    } finally {
        File::deleteDirectory($archiveDirectory);
    }
})->group('real-images');
