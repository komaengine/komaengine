<?php

namespace App\Archives;

use App\Media\PreparedCover;
use App\Media\SeriesCoverService;
use App\Models\Chapter;
use App\Models\Genre;
use App\Models\Page;
use App\Models\Series;
use App\Models\Tag;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SeriesArchiveImporter
{
    /** @var array<string, string> */
    private const IMAGE_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        protected KomaArchiveInspector $inspector,
        protected ArchiveMediaStore $media,
        protected SeriesCoverService $covers,
    ) {}

    /**
     * $committing runs inside the import transaction, after every row exists
     * and before it commits. Whatever it writes becomes durable together with
     * the imported content or not at all, and an exception from it rolls the
     * whole import back.
     *
     * @param  null|callable(int, string, string): void  $progress
     * @param  null|callable(Series, array<string, int>): void  $committing
     */
    public function import(
        string $archivePath,
        string $conflictPolicy = ArchiveImportPolicy::FAIL,
        ?int $replacementSeriesId = null,
        bool $replaceConfirmed = false,
        ?callable $progress = null,
        ?callable $committing = null,
    ): ImportedArchive {
        if (! in_array($conflictPolicy, ArchiveImportPolicy::VALUES, true)) {
            throw new ArchiveConflictException('The selected archive conflict policy is invalid.');
        }

        if ($conflictPolicy === ArchiveImportPolicy::REPLACE && (! $replacementSeriesId || ! $replaceConfirmed)) {
            throw new ArchiveConflictException('Replacement requires an explicitly selected series and confirmation.');
        }

        $this->progress($progress, 5, 'Validating', 'Opening the archive safely.');
        $validated = $this->inspector->inspect($archivePath, $progress);
        $importToken = (string) Str::uuid();
        $mediaDirectory = "imports/{$importToken}";
        $oldMediaPaths = [];
        $generatedCover = null;
        $reachedCommit = false;

        try {
            $manifest = $validated->manifest;
            $counts = $this->counts($manifest);
            $this->progress($progress, 50, 'Importing', 'The archive is valid. Transferring verified media.');

            try {
                $storedMedia = $this->storeMedia(
                    $validated,
                    $manifest['series'],
                    $mediaDirectory,
                    $counts['media'],
                    $progress,
                );
                $coverAttributes = $this->coverAttributes($manifest['series'], $storedMedia);

                if ($manifest['series']['cover'] === null && $manifest['series']['cover_thumbnail'] === null) {
                    $generatedCover = $this->prepareFirstPageCover($validated, $manifest['series']);

                    if ($generatedCover !== null) {
                        $coverAttributes = $generatedCover->attributes();
                        $this->progress($progress, 93, 'Importing', 'Generated cover art from the first page.');
                    }
                }

                $this->progress($progress, 94, 'Importing', 'Committing series, chapter, and page records.');
                $sourceMetadata = $validated->sourceMetadata;

                $series = DB::transaction(function () use (
                    $manifest,
                    $conflictPolicy,
                    $replacementSeriesId,
                    $storedMedia,
                    $coverAttributes,
                    $sourceMetadata,
                    $counts,
                    $committing,
                    &$oldMediaPaths,
                    &$reachedCommit,
                ) {
                    $seriesManifest = $manifest['series'];
                    [$slug, $replacement] = $this->resolveConflict(
                        $seriesManifest['slug'],
                        $conflictPolicy,
                        $replacementSeriesId,
                    );

                    if ($replacement !== null) {
                        $replacement->loadMissing('chapters.pages');
                        $oldMediaPaths = $this->seriesMediaPaths($replacement);
                        $replacement->timestamps = false;
                        $replacement->forceFill(['slug' => '__koma-replaced-'.$replacement->id.'-'.Str::lower(Str::random(12))]);
                        $replacement->saveQuietly();
                    }

                    $series = $this->createSeries(
                        $seriesManifest,
                        $slug,
                        $coverAttributes,
                        $sourceMetadata,
                    );
                    $this->attachGenres($series, $seriesManifest['genres']);
                    $this->attachTags($series, $seriesManifest['tags']);

                    foreach ($seriesManifest['chapters'] as $chapterManifest) {
                        $chapter = $this->createChapter($series, $chapterManifest);

                        foreach ($chapterManifest['pages'] as $pageManifest) {
                            $this->createPage(
                                $chapter,
                                $pageManifest,
                                $storedMedia[$pageManifest['archive_path']],
                            );
                        }
                    }

                    if ($replacement !== null) {
                        $replacement->deleteQuietly();
                    }

                    if ($committing !== null) {
                        $committing($series, $counts);
                    }

                    $reachedCommit = true;

                    return $series;
                }, 1);
            } catch (Throwable $e) {
                // A failure after every row was written happened at COMMIT,
                // and the server may have applied it. Keep the media then:
                // unreferenced files can be removed later, but a committed
                // series whose pages were deleted cannot be repaired.
                if (! $reachedCommit) {
                    if ($generatedCover instanceof PreparedCover) {
                        $this->covers->discard($generatedCover);
                    }

                    try {
                        $this->media->deleteDirectory($mediaDirectory);
                    } catch (Throwable $cleanupError) {
                        report($cleanupError);
                    }
                }

                if ($e instanceof ArchiveException) {
                    throw $e;
                }

                throw new ArchiveException('Archive import rolled back safely: '.$e->getMessage(), previous: $e);
            }

            if ($oldMediaPaths !== []) {
                try {
                    $this->media->deletePaths($oldMediaPaths);
                } catch (Throwable $cleanupError) {
                    report($cleanupError);
                }
            }

            $this->progress($progress, 100, 'Completed', "Imported {$series->title} successfully.");

            return new ImportedArchive($series->fresh(['genres', 'tags', 'chapters.pages']), $counts);
        } finally {
            $validated->discard();
        }
    }

    /**
     * @return array{0:string,1:?Series}
     */
    protected function resolveConflict(string $sourceSlug, string $policy, ?int $replacementSeriesId): array
    {
        if ($policy === ArchiveImportPolicy::REPLACE) {
            $replacement = Series::query()->lockForUpdate()->find($replacementSeriesId);

            if ($replacement === null) {
                throw new ArchiveConflictException('The selected replacement series no longer exists.');
            }

            return [$replacement->slug, $replacement];
        }

        $conflict = Series::query()->where('slug', $sourceSlug)->lockForUpdate()->first();

        if ($conflict === null) {
            return [$sourceSlug, null];
        }

        if ($policy === ArchiveImportPolicy::FAIL) {
            throw new ArchiveConflictException(
                "A series already uses the slug '{$sourceSlug}': “{$conflict->title}” (ID {$conflict->getKey()}). Choose a different conflict policy explicitly, or replace that series.",
            );
        }

        return [$this->uniqueSourceSlug($sourceSlug), null];
    }

    protected function uniqueSourceSlug(string $sourceSlug): string
    {
        $base = Str::limit($sourceSlug, 240, '');
        $suffix = 2;
        $candidate = $base.'-'.$suffix;

        while (Series::query()->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.++$suffix;
        }

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  null|callable(int, string, string): void  $progress
     * @return array<string, string>
     */
    protected function storeMedia(
        ValidatedArchive $archive,
        array $manifest,
        string $directory,
        int $total,
        ?callable $progress,
    ): array {
        $stored = [];
        $processed = 0;

        foreach (['cover', 'cover_thumbnail'] as $field) {
            $descriptor = $manifest[$field];

            if (! is_array($descriptor)) {
                continue;
            }

            $extension = $this->extension($descriptor['mime_type']);
            $destination = $directory.'/'.str_replace('_', '-', $field).'.'.$extension;
            $this->media->store($archive->stagedPath($descriptor['archive_path']), $destination, $descriptor['bytes']);
            $stored[$descriptor['archive_path']] = $destination;
            $this->mediaProgress($progress, ++$processed, $total);
        }

        foreach ($manifest['chapters'] as $chapter) {
            foreach ($chapter['pages'] as $page) {
                $extension = $this->extension($page['mime_type']);
                $filename = str_pad((string) $page['sort_order'], 6, '0', STR_PAD_LEFT)
                    .'-'.Str::lower(Str::random(12)).'.'.$extension;
                $destination = "{$directory}/chapters/{$chapter['source_id']}/{$filename}";
                $this->media->store($archive->stagedPath($page['archive_path']), $destination, $page['bytes']);
                $stored[$page['archive_path']] = $destination;
                $this->mediaProgress($progress, ++$processed, $total);
            }
        }

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $storedMedia
     * @return array<string, int|string|null>
     */
    protected function coverAttributes(array $manifest, array $storedMedia): array
    {
        $attributes = [
            'cover_path' => null,
            'cover_thumbnail_path' => null,
            'cover_width' => null,
            'cover_height' => null,
            'cover_file_size' => null,
            'cover_thumbnail_width' => null,
            'cover_thumbnail_height' => null,
            'cover_thumbnail_file_size' => null,
        ];

        foreach ([
            'cover' => ['cover_path', 'cover_width', 'cover_height', 'cover_file_size'],
            'cover_thumbnail' => ['cover_thumbnail_path', 'cover_thumbnail_width', 'cover_thumbnail_height', 'cover_thumbnail_file_size'],
        ] as $field => [$pathKey, $widthKey, $heightKey, $bytesKey]) {
            $descriptor = $manifest[$field];

            if (is_array($descriptor)) {
                $attributes[$pathKey] = $storedMedia[$descriptor['archive_path']];
                $attributes[$widthKey] = $descriptor['width'];
                $attributes[$heightKey] = $descriptor['height'];
                $attributes[$bytesKey] = $descriptor['bytes'];
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    protected function prepareFirstPageCover(
        ValidatedArchive $archive,
        array $manifest,
    ): ?PreparedCover {
        foreach ($manifest['chapters'] as $chapter) {
            if ($chapter['pages'] === []) {
                continue;
            }

            $pages = $chapter['pages'];
            usort(
                $pages,
                fn (array $left, array $right): int => [$left['sort_order'], $left['source_id']]
                    <=> [$right['sort_order'], $right['source_id']],
            );
            $firstPage = $pages[0];
            $sourcePath = $archive->stagedPath($firstPage['archive_path']);
            $upload = new UploadedFile(
                $sourcePath,
                basename($firstPage['archive_path']),
                $firstPage['mime_type'],
                null,
                true,
            );

            return $this->covers->prepare($upload, 0.5, 0.5, 1.0);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, int|string|null>  $coverAttributes
     */
    protected function createSeries(
        array $manifest,
        string $slug,
        array $coverAttributes,
        ?array $sourceMetadata = null,
    ): Series {
        $isV2 = array_key_exists('alternate_titles', $manifest);
        $series = new Series;
        $series->timestamps = false;
        $series->forceFill(array_merge([
            'title' => $manifest['title'],
            'alternate_titles' => $isV2 ? $manifest['alternate_titles'] : [],
            'slug' => $slug,
            'author' => $manifest['author'],
            'artist' => $manifest['artist'],
            'credits' => $isV2 ? $manifest['credits'] : [],
            'franchises' => $isV2 ? $manifest['franchises'] : [],
            'characters' => $isV2 ? $manifest['characters'] : [],
            'source_references' => $isV2 ? $manifest['source_references'] : [],
            'source_metadata' => $sourceMetadata,
            'year' => $manifest['year'],
            'publication_date' => $isV2 ? $manifest['publication_date'] : null,
            'work_format' => $isV2 ? $manifest['work_format'] : null,
            'status' => $manifest['status'],
            'reading_direction' => $manifest['reading_direction'],
            'default_reader_mode' => $manifest['default_reader_mode'],
            'content_rating' => $manifest['content_rating'],
            'is_public' => $manifest['is_public'],
            'synopsis' => $manifest['synopsis'],
            'cover_hue' => $manifest['cover_hue'],
            'views' => $manifest['views'],
            'created_at' => $this->timestamp($manifest['created_at']),
            'updated_at' => $this->timestamp($manifest['updated_at']),
        ], $coverAttributes));
        $series->saveQuietly();

        return $series;
    }

    /** @param list<array<string, mixed>> $genres */
    protected function attachGenres(Series $series, array $genres): void
    {
        $ids = [];

        foreach ($genres as $manifest) {
            $genre = Genre::query()->where('slug', $manifest['slug'])->first();

            if ($genre === null) {
                $genre = new Genre;
                $genre->timestamps = false;
                $genre->forceFill([
                    'name' => $manifest['name'],
                    'slug' => $manifest['slug'],
                    'created_at' => $this->timestamp($manifest['created_at']),
                    'updated_at' => $this->timestamp($manifest['updated_at']),
                ]);
                $genre->saveQuietly();
            }

            $ids[] = $genre->id;
        }

        $series->genres()->sync($ids);
    }

    /** @param list<array<string, mixed>> $tags */
    protected function attachTags(Series $series, array $tags): void
    {
        $ids = [];

        foreach ($tags as $manifest) {
            $tag = Tag::query()->where('slug', $manifest['slug'])->first();

            if ($tag === null) {
                $tag = new Tag;
                $tag->timestamps = false;
                $tag->forceFill([
                    'name' => $manifest['name'],
                    'slug' => $manifest['slug'],
                    'created_at' => $this->timestamp($manifest['created_at']),
                    'updated_at' => $this->timestamp($manifest['updated_at']),
                ]);
                $tag->saveQuietly();
            }

            $ids[] = $tag->id;
        }

        $series->tags()->sync($ids);
    }

    /** @param array<string, mixed> $manifest */
    protected function createChapter(Series $series, array $manifest): Chapter
    {
        $chapter = new Chapter;
        $chapter->timestamps = false;
        $chapter->forceFill([
            'series_id' => $series->id,
            'number' => $manifest['number'],
            'title' => $manifest['title'],
            'scanlation_group' => $manifest['scanlation_group'],
            'language' => $manifest['language'] ?? null,
            'status' => $manifest['status'],
            'published_at' => $manifest['published_at'] === null ? null : $this->timestamp($manifest['published_at']),
            'views' => $manifest['views'],
            'created_at' => $this->timestamp($manifest['created_at']),
            'updated_at' => $this->timestamp($manifest['updated_at']),
        ]);
        $chapter->saveQuietly();

        return $chapter;
    }

    /** @param array<string, mixed> $manifest */
    protected function createPage(
        Chapter $chapter,
        array $manifest,
        string $destination,
    ): Page {
        $page = new Page;
        $page->timestamps = false;
        $page->forceFill([
            'chapter_id' => $chapter->id,
            'sort_order' => $manifest['sort_order'],
            'image_path' => $destination,
            'width' => $manifest['width'],
            'height' => $manifest['height'],
            'file_size' => $manifest['bytes'],
            'created_at' => $this->timestamp($manifest['created_at']),
            'updated_at' => $this->timestamp($manifest['updated_at']),
        ]);
        $page->saveQuietly();

        return $page;
    }

    /** @param null|callable(int, string, string): void $progress */
    protected function mediaProgress(?callable $progress, int $processed, int $total): void
    {
        $percent = 50 + (int) floor(($processed / max(1, $total)) * 42);
        $this->progress(
            $progress,
            $percent,
            'Importing',
            "Transferred {$processed} of {$total} verified media files.",
        );
    }

    /** @return list<string> */
    protected function seriesMediaPaths(Series $series): array
    {
        return array_values(array_filter(array_merge(
            [$series->cover_path, $series->cover_thumbnail_path],
            $series->chapters->flatMap(fn (Chapter $chapter) => $chapter->pages->pluck('image_path'))->all(),
        )));
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{series:int,genres:int,tags:int,chapters:int,pages:int,media:int}
     */
    protected function counts(array $manifest): array
    {
        $chapters = $manifest['series']['chapters'];
        $pages = array_sum(array_map(fn (array $chapter) => count($chapter['pages']), $chapters));
        $covers = ($manifest['series']['cover'] === null ? 0 : 1)
            + ($manifest['series']['cover_thumbnail'] === null ? 0 : 1);

        return [
            'series' => 1,
            'genres' => count($manifest['series']['genres']),
            'tags' => count($manifest['series']['tags']),
            'chapters' => count($chapters),
            'pages' => $pages,
            'media' => $pages + $covers,
        ];
    }

    protected function extension(string $mimeType): string
    {
        return self::IMAGE_EXTENSIONS[$mimeType]
            ?? throw new ArchiveValidationException("Unsupported imported media type: {$mimeType}");
    }

    protected function timestamp(string $value): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $value, 'UTC');
    }

    /** @param null|callable(int, string, string): void $progress */
    protected function progress(?callable $progress, int $percent, string $stage, string $message): void
    {
        if ($progress !== null) {
            $progress(max(0, min(100, $percent)), $stage, $message);
        }
    }
}
