<?php

namespace App\Archives;

use App\Media\ImageProcessingException;
use App\Media\ImageProcessor;
use App\Models\Chapter;
use App\Models\Page;
use App\Models\Series;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

final class SeriesArchiveExporter
{
    public function __construct(
        private readonly KomaArchiveValidator $validator,
        private readonly ImageProcessor $images,
    ) {}

    public function export(
        Series $sourceSeries,
        string $destination,
        ?CarbonInterface $exportedAt = null,
        ?int $schemaVersion = null,
    ): ExportedArchive {
        if (! class_exists(ZipArchive::class)) {
            throw new ArchiveException('Koma archive export requires the PHP ZIP extension.');
        }

        if (! $sourceSeries->exists) {
            throw new ArchiveException('Only a saved series can be exported.');
        }

        $schemaVersion ??= (int) config('koma.archives.default_schema_version', KomaArchiveV2::SCHEMA_VERSION);

        if (! in_array($schemaVersion, KomaArchiveValidator::SUPPORTED_SCHEMA_VERSIONS, true)) {
            throw new ArchiveException("Koma archive schema version {$schemaVersion} is not supported.");
        }

        if (file_exists($destination)) {
            throw new ArchiveException('The archive destination already exists.');
        }

        $parent = dirname($destination);

        try {
            File::ensureDirectoryExists($parent);
        } catch (Throwable $e) {
            throw new ArchiveException('The archive destination directory could not be created.', previous: $e);
        }

        if (! is_writable($parent)) {
            throw new ArchiveException('The archive destination directory is not writable.');
        }

        $token = (string) Str::uuid();
        $partialPath = $parent.DIRECTORY_SEPARATOR.'.'.basename($destination).'.partial-'.$token;
        $workDirectory = storage_path('app/archive-temp/'.$token);

        try {
            File::ensureDirectoryExists($workDirectory);
        } catch (Throwable $e) {
            throw new ArchiveException('The archive staging directory could not be created.', previous: $e);
        }

        $zip = null;
        $zipOpen = false;
        $moved = false;

        try {
            $archive = $this->buildArchive(
                $sourceSeries,
                $workDirectory,
                $exportedAt ? CarbonImmutable::instance($exportedAt)->utc() : CarbonImmutable::now('UTC'),
                $schemaVersion,
            );
            $manifestJson = $archive->manifestJson();

            $zip = new ZipArchive;
            $opened = $zip->open($partialPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

            if ($opened !== true) {
                throw new ArchiveException("The ZIP archive could not be created (error {$opened}).");
            }

            $zipOpen = true;

            if (! $zip->addFromString(KomaArchiveV1::MANIFEST_PATH, $manifestJson)) {
                throw new ArchiveException('The archive manifest could not be written.');
            }

            $zip->setMtimeName(KomaArchiveV1::MANIFEST_PATH, KomaArchiveV1::ZIP_ENTRY_TIMESTAMP);
            $zip->setCompressionName(KomaArchiveV1::MANIFEST_PATH, ZipArchive::CM_DEFLATE);

            foreach ($archive->media as $media) {
                if (! $zip->addFile($media->stagedPath, $media->archivePath)) {
                    throw new ArchiveException("Archive media could not be added: {$media->archivePath}");
                }

                $zip->setMtimeName($media->archivePath, KomaArchiveV1::ZIP_ENTRY_TIMESTAMP);
                $zip->setCompressionName($media->archivePath, ZipArchive::CM_STORE);
            }

            // Closing writes every staged file into the ZIP and can take a
            // long time without touching the workspace; mark it as in use.
            touch($workDirectory);

            if (! $zip->close()) {
                throw new ArchiveException('The ZIP archive could not be finalized.');
            }

            $zipOpen = false;
            $bytes = filesize($partialPath);
            $sha256 = hash_file('sha256', $partialPath);

            if (! is_int($bytes) || ! is_string($sha256)) {
                throw new ArchiveException('The completed archive could not be verified.');
            }

            if (! rename($partialPath, $destination)) {
                throw new ArchiveException('The completed archive could not be moved into place.');
            }

            $moved = true;

            return new ExportedArchive(
                path: $destination,
                downloadName: $this->downloadName($sourceSeries),
                bytes: $bytes,
                sha256: $sha256,
                manifest: $archive->manifest,
            );
        } catch (Throwable $e) {
            if ($zipOpen && $zip instanceof ZipArchive) {
                $zip->close();
            }

            if ($e instanceof ArchiveException) {
                throw $e;
            }

            throw new ArchiveException('Series archive export failed: '.$e->getMessage(), previous: $e);
        } finally {
            if (! $moved) {
                File::delete($partialPath);
            }

            File::deleteDirectory($workDirectory);
        }
    }

    public function downloadName(Series $series): string
    {
        $slug = Str::slug($series->slug) ?: 'series';

        return "series-{$series->getKey()}-{$slug}.koma.zip";
    }

    private function buildArchive(
        Series $sourceSeries,
        string $workDirectory,
        CarbonImmutable $exportedAt,
        int $schemaVersion,
    ): KomaArchive {
        $series = Series::query()
            ->with([
                'genres' => fn ($query) => $query->orderBy('genres.slug')->orderBy('genres.id'),
                'tags' => fn ($query) => $query->orderBy('tags.slug')->orderBy('tags.id'),
                'chapters' => fn ($query) => $query->orderBy('number')->orderBy('id'),
                'chapters.pages' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            ])
            ->findOrFail($sourceSeries->getKey());

        // An archive the importer would refuse is not a usable export. Check
        // the same counts now, before staging gigabytes of media for it.
        $this->validator->assertCollectionCounts(
            $series->genres->count(),
            $series->tags->count(),
            $series->chapters->count(),
            $series->chapters->sum(fn (Chapter $chapter): int => $chapter->pages->count())
                + (filled($series->cover_path) ? 1 : 0)
                + (filled($series->cover_thumbnail_path) ? 1 : 0),
        );

        $disk = Storage::disk('media');
        $media = [];
        $sequence = 0;

        $cover = null;
        if (filled($series->cover_path)) {
            $coverMedia = $this->stageMedia(
                $disk,
                $series->cover_path,
                "media/series/{$series->getKey()}/cover",
                $workDirectory,
                ++$sequence,
            );
            $media[] = $coverMedia;
            $cover = $coverMedia->manifestEntry($series->cover_width, $series->cover_height);
        }

        $coverThumbnail = null;
        if (filled($series->cover_thumbnail_path)) {
            $thumbnailMedia = $this->stageMedia(
                $disk,
                $series->cover_thumbnail_path,
                "media/series/{$series->getKey()}/cover-thumbnail",
                $workDirectory,
                ++$sequence,
            );
            $media[] = $thumbnailMedia;
            $coverThumbnail = $thumbnailMedia->manifestEntry(
                $series->cover_thumbnail_width,
                $series->cover_thumbnail_height,
            );
        }

        $chapters = [];
        foreach ($series->chapters as $chapter) {
            $pages = [];

            foreach ($chapter->pages as $page) {
                $pageMedia = $this->stageMedia(
                    $disk,
                    $page->image_path,
                    $this->pageArchiveBase($series, $chapter, $page),
                    $workDirectory,
                    ++$sequence,
                );
                $media[] = $pageMedia;
                $pages[] = array_merge([
                    'source_id' => $page->getKey(),
                    'sort_order' => $page->sort_order,
                ], $pageMedia->manifestEntry($page->width, $page->height), [
                    'created_at' => $this->timestamp($page->created_at),
                    'updated_at' => $this->timestamp($page->updated_at),
                ]);
            }

            $chapterManifest = [
                'source_id' => $chapter->getKey(),
                'number' => (string) $chapter->number,
                'title' => $chapter->title,
                'scanlation_group' => $chapter->scanlation_group,
            ];

            if ($schemaVersion === KomaArchiveV2::SCHEMA_VERSION) {
                $chapterManifest['language'] = $chapter->language;
            }

            $chapters[] = array_merge($chapterManifest, [
                'status' => $chapter->status,
                'published_at' => $this->nullableTimestamp($chapter->published_at),
                'views' => $chapter->views,
                'created_at' => $this->timestamp($chapter->created_at),
                'updated_at' => $this->timestamp($chapter->updated_at),
                'pages' => $pages,
            ]);
        }

        $seriesManifest = [
            'source_id' => $series->getKey(),
            'title' => $series->title,
        ];

        if ($schemaVersion === KomaArchiveV2::SCHEMA_VERSION) {
            $seriesManifest = array_merge($seriesManifest, [
                'alternate_titles' => $this->sortedRecords(
                    $series->alternate_titles ?? [],
                    fn (array $item): string => ($item['language'] ?? '')."\0".($item['type'] ?? '')."\0".($item['title'] ?? ''),
                ),
            ]);
        }

        $seriesManifest = array_merge($seriesManifest, [
            'slug' => $series->slug,
            'author' => $series->author,
            'artist' => $series->artist,
        ]);

        if ($schemaVersion === KomaArchiveV2::SCHEMA_VERSION) {
            $seriesManifest = array_merge($seriesManifest, [
                'credits' => $this->sortedRecords(
                    $series->credits ?? [],
                    fn (array $item): string => ($item['role'] ?? '')."\0".($item['type'] ?? '')."\0".($item['name'] ?? ''),
                ),
                'franchises' => $this->sortedStrings($series->franchises ?? []),
                'characters' => $this->sortedStrings($series->characters ?? []),
                'source_references' => $this->sortedRecords(
                    $series->source_references ?? [],
                    fn (array $item): string => ($item['provider'] ?? '')."\0".($item['external_id'] ?? ''),
                ),
            ]);
        }

        $seriesManifest = array_merge($seriesManifest, [
            'year' => $series->year,
        ]);

        if ($schemaVersion === KomaArchiveV2::SCHEMA_VERSION) {
            $seriesManifest = array_merge($seriesManifest, [
                'publication_date' => $series->publication_date?->format('Y-m-d'),
                'work_format' => $series->work_format,
            ]);
        }

        $seriesManifest = array_merge($seriesManifest, [
            'status' => $series->status,
            'reading_direction' => $series->reading_direction,
            'default_reader_mode' => $series->default_reader_mode,
            'content_rating' => $series->content_rating,
            'is_public' => $series->is_public,
            'synopsis' => $series->synopsis,
            'cover_hue' => $series->cover_hue,
            'views' => $series->views,
            'created_at' => $this->timestamp($series->created_at),
            'updated_at' => $this->timestamp($series->updated_at),
            'genres' => $series->genres->map(fn ($genre) => [
                'source_id' => $genre->getKey(),
                'name' => $genre->name,
                'slug' => $genre->slug,
                'created_at' => $this->timestamp($genre->created_at),
                'updated_at' => $this->timestamp($genre->updated_at),
            ])->values()->all(),
            'tags' => $series->tags->map(fn ($tag) => [
                'source_id' => $tag->getKey(),
                'name' => $tag->name,
                'slug' => $tag->slug,
                'created_at' => $this->timestamp($tag->created_at),
                'updated_at' => $this->timestamp($tag->updated_at),
            ])->values()->all(),
            'cover' => $cover,
            'cover_thumbnail' => $coverThumbnail,
            'chapters' => $chapters,
        ]);

        $manifest = [
            'format' => KomaArchiveV1::FORMAT,
            'schema_version' => $schemaVersion,
            'exported_at' => $this->timestamp($exportedAt),
            'series' => $seriesManifest,
        ];

        $this->validator->validate($manifest);

        $manifestPaths = $this->validator->mediaPaths($manifest);
        $stagedPaths = array_map(fn (ArchiveMedia $item) => $item->archivePath, $media);
        sort($manifestPaths);
        sort($stagedPaths);

        if ($manifestPaths !== $stagedPaths) {
            throw new ArchiveException('Archive manifest media does not match the staged files.');
        }

        return new KomaArchive($manifest, $media);
    }

    private function stageMedia(
        mixed $disk,
        string $sourcePath,
        string $archiveBase,
        string $workDirectory,
        int $sequence,
    ): ArchiveMedia {
        if (! $disk->exists($sourcePath)) {
            throw new ArchiveException("Archive media is missing: {$sourcePath}");
        }

        $source = $disk->readStream($sourcePath);

        if (! is_resource($source)) {
            throw new ArchiveException("Archive media is unreadable: {$sourcePath}");
        }

        $stagedPath = $workDirectory.DIRECTORY_SEPARATOR.str_pad((string) $sequence, 8, '0', STR_PAD_LEFT).'.media';
        $target = fopen($stagedPath, 'xb');

        if (! is_resource($target)) {
            fclose($source);
            throw new ArchiveException('A temporary archive media file could not be created.');
        }

        $hash = hash_init('sha256');
        $bytes = 0;

        try {
            while (! feof($source)) {
                $chunk = fread($source, 1024 * 1024);

                if ($chunk === false) {
                    throw new ArchiveException("Archive media could not be read: {$sourcePath}");
                }

                if ($chunk === '') {
                    continue;
                }

                hash_update($hash, $chunk);
                $length = strlen($chunk);
                $offset = 0;

                while ($offset < $length) {
                    $written = fwrite($target, substr($chunk, $offset));

                    if ($written === false || $written === 0) {
                        throw new ArchiveException('A temporary archive media file could not be written.');
                    }

                    $offset += $written;
                    $bytes += $written;
                }
            }
        } finally {
            fclose($source);
            fclose($target);
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($stagedPath) ?: 'application/octet-stream';
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new ArchiveException(
                "Archive media is not a supported image type ({$mimeType}): {$sourcePath}",
            ),
        };

        try {
            $this->images->processPage(new UploadedFile(
                $stagedPath,
                basename($sourcePath),
                $mimeType,
                null,
                true,
            ));
        } catch (ImageProcessingException $e) {
            throw new ArchiveException(
                "Archive media could not be decoded safely: {$sourcePath}. {$e->getMessage()}",
                previous: $e,
            );
        }

        return new ArchiveMedia(
            sourcePath: $sourcePath,
            archivePath: $archiveBase.'.'.$extension,
            stagedPath: $stagedPath,
            sha256: hash_final($hash),
            bytes: $bytes,
            mimeType: $mimeType,
        );
    }

    private function pageArchiveBase(Series $series, Chapter $chapter, Page $page): string
    {
        $order = str_pad((string) $page->sort_order, 6, '0', STR_PAD_LEFT);

        return "media/series/{$series->getKey()}/chapters/{$chapter->getKey()}/pages/{$order}-{$page->getKey()}";
    }

    private function timestamp(CarbonInterface $value): string
    {
        return CarbonImmutable::instance($value)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function nullableTimestamp(?CarbonInterface $value): ?string
    {
        return $value ? $this->timestamp($value) : null;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    private function sortedRecords(array $records, callable $key): array
    {
        usort($records, fn (array $left, array $right): int => $key($left) <=> $key($right));

        return array_values($records);
    }

    /** @param list<string> $values */
    private function sortedStrings(array $values): array
    {
        natcasesort($values);

        return array_values($values);
    }
}
