<?php

namespace App\Archives;

use App\Archives\Contracts\GalleryArchiveAdapter;
use App\Media\ImageProcessingException;
use App\Media\ImageProcessor;
use App\Support\Facades\Hook;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use JsonException;
use Throwable;
use ZipArchive;

class KomaArchiveInspector
{
    /** @var array<string, string> */
    private const IMAGE_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        protected KomaArchiveValidator $validator,
        protected ImageProcessor $images,
        protected GalleryArchiveV1Adapter $gallery,
    ) {}

    /**
     * @param  null|callable(int, string, string): void  $progress
     */
    public function inspect(string $archivePath, ?callable $progress = null): ValidatedArchive
    {
        if (! class_exists(ZipArchive::class)) {
            throw new ArchiveException('Koma archive import requires the PHP ZIP extension.');
        }

        if (! is_file($archivePath) || ! is_readable($archivePath)) {
            throw new ArchiveValidationException('The source archive is missing or unreadable.');
        }

        $archiveBytes = filesize($archivePath);
        $maxArchiveBytes = $this->limit('max_archive_kb', 512000) * 1024;

        if (! is_int($archiveBytes) || $archiveBytes < 1 || $archiveBytes > $maxArchiveBytes) {
            throw new ArchiveValidationException('The source archive exceeds the configured compressed-size limit.');
        }

        $workDirectory = storage_path('app/archive-import-temp/'.Str::uuid());

        try {
            File::ensureDirectoryExists($workDirectory);
        } catch (Throwable $e) {
            throw new ArchiveException('The archive import staging directory could not be created.', previous: $e);
        }

        $zip = new ZipArchive;
        $zipOpen = false;

        try {
            $opened = $zip->open($archivePath, ZipArchive::RDONLY);

            if ($opened !== true) {
                throw new ArchiveValidationException("The source is not a readable ZIP archive (error {$opened}).");
            }

            $zipOpen = true;

            $entries = $this->inspectStructure($zip);
            $manifest = $this->readManifest($zip, $entries[KomaArchiveV1::MANIFEST_PATH]);
            $staged = [];
            $sourceMetadata = null;

            $adapter = $this->galleryAdapterFor($manifest);

            if ($adapter !== null) {
                $sourceMetadata = $manifest;
                $externalPages = $adapter->pages($manifest);
                $sourcePaths = array_column($externalPages, 'source_path');
                $this->assertExactEntries($entries, $sourcePaths);
                $this->assertExpandedLimits($entries, $sourcePaths);

                if ($progress !== null) {
                    $progress(12, 'Validating', 'External gallery structure and metadata are valid.');
                }

                $media = [];
                $total = count($externalPages);

                foreach ($externalPages as $index => $page) {
                    $stagedPath = $workDirectory.'/media-'.str_pad((string) ($index + 1), 8, '0', STR_PAD_LEFT);
                    $descriptor = $this->stageAndDescribeMedia(
                        $zip,
                        $entries[$page['source_path']],
                        $page['archive_path'],
                        $stagedPath,
                    );
                    $media[] = $descriptor;
                    $staged[$page['archive_path']] = $stagedPath;
                    $this->mediaProgress($progress, $index + 1, $total);
                }

                $manifest = $adapter->toKomaManifest($manifest, $media);
                $this->validator->validate($manifest);
            } else {
                $this->validator->validate($manifest);
                $media = $this->mediaDescriptors($manifest);
                $mediaPaths = array_column($media, 'archive_path');
                $this->assertExactEntries($entries, $mediaPaths);
                $this->assertExpandedLimits($entries, $mediaPaths);
                if ($progress !== null) {
                    $progress(12, 'Validating', 'Archive structure and manifest are valid.');
                }

                $total = count($media);

                foreach ($media as $index => $descriptor) {
                    $archiveMediaPath = $descriptor['archive_path'];
                    $stagedPath = $workDirectory.'/media-'.str_pad((string) ($index + 1), 8, '0', STR_PAD_LEFT);
                    $this->stageAndValidateMedia(
                        $zip,
                        $entries[$archiveMediaPath],
                        $descriptor,
                        $stagedPath,
                    );
                    $staged[$archiveMediaPath] = $stagedPath;
                    $this->mediaProgress($progress, $index + 1, $total);
                }
            }

            if (! $zip->close()) {
                throw new ArchiveValidationException('The archive could not be closed after validation.');
            }

            $zipOpen = false;

            return new ValidatedArchive($manifest, $staged, $workDirectory, $sourceMetadata);
        } catch (Throwable $e) {
            if ($zipOpen) {
                try {
                    $zip->close();
                } catch (Throwable $closeError) {
                    report($closeError);
                }
            }
            File::deleteDirectory($workDirectory);

            if ($e instanceof ArchiveException) {
                throw $e;
            }

            throw new ArchiveValidationException('Archive validation failed: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * Find the adapter that claims this manifest, or null if it is a native
     * Koma Archive (or nothing recognises it, in which case the strict
     * validator produces the error message).
     *
     * @param  array<string, mixed>  $manifest
     */
    protected function galleryAdapterFor(array $manifest): ?GalleryArchiveAdapter
    {
        foreach ($this->galleryAdapters() as $adapter) {
            try {
                if ($adapter->supports($manifest)) {
                    return $adapter;
                }
            } catch (Throwable $e) {
                // A plugin adapter that throws while sniffing is skipped rather
                // than allowed to fail an import it may not even own.
                report($e);
            }
        }

        return null;
    }

    /**
     * Plugin adapters first, the built-in generic adapter last.
     *
     * Order matters: the generic manifest is permissive by design, so a more
     * specific format has to be offered the manifest before it.
     *
     * @return list<GalleryArchiveAdapter>
     */
    protected function galleryAdapters(): array
    {
        $registered = [];

        try {
            $filtered = Hook::applyFilters('archive.gallery_adapters', []);

            if (is_array($filtered)) {
                $registered = array_values(array_filter(
                    $filtered,
                    fn ($adapter) => $adapter instanceof GalleryArchiveAdapter,
                ));
            }
        } catch (Throwable $e) {
            report($e);
        }

        return [...$registered, $this->gallery];
    }

    /** @return array<string, array<string, int|string>> */
    protected function inspectStructure(ZipArchive $zip): array
    {
        $maxEntries = $this->limit('max_entries', 10000);

        if ($zip->numFiles < 1 || $zip->numFiles > $maxEntries) {
            throw new ArchiveValidationException('The archive entry count is outside the configured limit.');
        }

        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
            $name = is_array($stat) ? ($stat['name'] ?? null) : null;

            if (! is_string($name) || ! $this->safeEntryPath($name)) {
                throw new ArchiveValidationException('The archive contains an unsafe or invalid entry path.');
            }

            if (isset($entries[$name])) {
                throw new ArchiveValidationException("The archive contains a duplicate entry: {$name}");
            }

            if ($this->isSymlink($zip, $index)) {
                throw new ArchiveValidationException("The archive contains a symbolic-link entry: {$name}");
            }

            $size = (int) ($stat['size'] ?? -1);
            $compressed = (int) ($stat['comp_size'] ?? -1);
            $encryption = (int) ($stat['encryption_method'] ?? ZipArchive::EM_NONE);

            if ($size < 0 || $compressed < 0 || $encryption !== ZipArchive::EM_NONE) {
                throw new ArchiveValidationException("The archive entry is unreadable or encrypted: {$name}");
            }

            $entries[$name] = [
                'index' => $index,
                'name' => $name,
                'size' => $size,
                'comp_size' => $compressed,
            ];
        }

        if (! isset($entries[KomaArchiveV1::MANIFEST_PATH])) {
            throw new ArchiveValidationException('The archive is missing manifest.json at its root.');
        }

        return $entries;
    }

    /**
     * @param  array<string, int|string>  $entry
     * @return array<string, mixed>
     */
    protected function readManifest(ZipArchive $zip, array $entry): array
    {
        if ((int) $entry['size'] > $this->limit('max_manifest_bytes', 5242880)) {
            throw new ArchiveValidationException('The archive manifest exceeds the configured size limit.');
        }

        $json = $zip->getFromIndex((int) $entry['index'], (int) $entry['size'], ZipArchive::FL_UNCHANGED);

        if (! is_string($json) || strlen($json) !== (int) $entry['size']) {
            throw new ArchiveValidationException('The archive manifest could not be read completely.');
        }

        try {
            $manifest = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ArchiveValidationException('The archive manifest is not valid JSON.', previous: $e);
        }

        if (! is_array($manifest) || array_is_list($manifest)) {
            throw new ArchiveValidationException('The archive manifest root must be a JSON object.');
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array{archive_path:string,mime_type:string,sha256:string,bytes:int,width:?int,height:?int}>
     */
    protected function mediaDescriptors(array $manifest): array
    {
        $media = [];

        foreach (['cover', 'cover_thumbnail'] as $field) {
            $descriptor = $manifest['series'][$field];

            if (is_array($descriptor)) {
                $media[] = $descriptor;
            }
        }

        foreach ($manifest['series']['chapters'] as $chapter) {
            foreach ($chapter['pages'] as $page) {
                $media[] = [
                    'archive_path' => $page['archive_path'],
                    'mime_type' => $page['mime_type'],
                    'sha256' => $page['sha256'],
                    'bytes' => $page['bytes'],
                    'width' => $page['width'],
                    'height' => $page['height'],
                ];
            }
        }

        return $media;
    }

    /**
     * @param  array<string, array<string, int|string>>  $entries
     * @param  list<string>  $mediaPaths
     */
    protected function assertExactEntries(array $entries, array $mediaPaths): void
    {
        $expected = array_merge([KomaArchiveV1::MANIFEST_PATH], $mediaPaths);
        $actual = array_keys($entries);
        sort($expected);
        sort($actual);

        if ($actual !== $expected) {
            throw new ArchiveValidationException('The ZIP entries do not exactly match the manifest.');
        }
    }

    /**
     * @param  array<string, array<string, int|string>>  $entries
     * @param  list<string>  $mediaPaths
     */
    protected function assertExpandedLimits(array $entries, array $mediaPaths): void
    {
        $maxEntry = $this->limit('max_entry_bytes', 26214400);
        $maxExpanded = $this->limit('max_expanded_bytes', 5368709120);
        $maxRatio = $this->limit('max_compression_ratio', 200);
        $expanded = (int) $entries[KomaArchiveV1::MANIFEST_PATH]['size'];

        foreach ($mediaPaths as $path) {
            $size = (int) $entries[$path]['size'];
            $compressed = (int) $entries[$path]['comp_size'];

            if ($size > $maxEntry) {
                throw new ArchiveValidationException("Archive media exceeds the per-entry expanded-size limit: {$path}");
            }

            if ($size > 0 && ($compressed === 0 || $size / $compressed > $maxRatio)) {
                throw new ArchiveValidationException("Archive media exceeds the decompression-ratio limit: {$path}");
            }

            if ($expanded > $maxExpanded - $size) {
                throw new ArchiveValidationException('The archive exceeds the total expanded-size limit.');
            }

            $expanded += $size;
        }
    }

    /**
     * @param  array<string, int|string>  $entry
     * @param  array{archive_path:string,mime_type:string,sha256:string,bytes:int,width:?int,height:?int}  $descriptor
     */
    protected function stageAndValidateMedia(
        ZipArchive $zip,
        array $entry,
        array $descriptor,
        string $stagedPath,
    ): void {
        $path = $descriptor['archive_path'];

        if ((int) $entry['size'] !== $descriptor['bytes']) {
            throw new ArchiveValidationException("Archive media byte count does not match the manifest: {$path}");
        }

        $source = $zip->getStreamIndex((int) $entry['index'], ZipArchive::FL_UNCHANGED);
        $destination = @fopen($stagedPath, 'wb');

        if (! is_resource($source) || ! is_resource($destination)) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($destination)) {
                fclose($destination);
            }

            throw new ArchiveValidationException("Archive media could not be staged: {$path}");
        }

        $hash = hash_init('sha256');
        $bytes = 0;

        try {
            while (! feof($source)) {
                $chunk = fread($source, 1024 * 1024);

                if ($chunk === false) {
                    throw new ArchiveValidationException("Archive media could not be read: {$path}");
                }

                if ($chunk === '') {
                    continue;
                }

                $bytes += strlen($chunk);

                if ($bytes > $descriptor['bytes'] || fwrite($destination, $chunk) !== strlen($chunk)) {
                    throw new ArchiveValidationException("Archive media could not be staged completely: {$path}");
                }

                hash_update($hash, $chunk);
            }
        } finally {
            fclose($source);
            fclose($destination);
        }

        if ($bytes !== $descriptor['bytes'] || hash_final($hash) !== $descriptor['sha256']) {
            throw new ArchiveValidationException("Archive media checksum does not match the manifest: {$path}");
        }

        $imageInfo = @getimagesize($stagedPath);
        $actualMime = is_array($imageInfo) ? ($imageInfo['mime'] ?? null) : null;
        $actualWidth = is_array($imageInfo) ? (int) ($imageInfo[0] ?? 0) : 0;
        $actualHeight = is_array($imageInfo) ? (int) ($imageInfo[1] ?? 0) : 0;

        if (! is_string($actualMime) || ! isset(self::IMAGE_EXTENSIONS[$actualMime])) {
            throw new ArchiveValidationException("Archive media is not a supported decodable image: {$path}");
        }

        if ($actualMime !== $descriptor['mime_type']) {
            throw new ArchiveValidationException("Archive media MIME type does not match the manifest: {$path}");
        }

        if (strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) !== self::IMAGE_EXTENSIONS[$actualMime]) {
            throw new ArchiveValidationException("Archive media extension does not match its decoded type: {$path}");
        }

        if (($descriptor['width'] !== null && $descriptor['width'] !== $actualWidth)
            || ($descriptor['height'] !== null && $descriptor['height'] !== $actualHeight)) {
            throw new ArchiveValidationException("Archive image dimensions do not match the manifest: {$path}");
        }

        try {
            $this->images->processPage(new UploadedFile(
                $stagedPath,
                basename($path),
                $actualMime,
                null,
                true,
            ));
        } catch (ImageProcessingException $e) {
            throw new ArchiveValidationException("Archive image could not be decoded safely: {$path}. {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * @param  array<string, int|string>  $entry
     * @return array{archive_path:string,mime_type:string,sha256:string,bytes:int,width:int,height:int}
     */
    protected function stageAndDescribeMedia(
        ZipArchive $zip,
        array $entry,
        string $archivePath,
        string $stagedPath,
    ): array {
        $source = $zip->getStreamIndex((int) $entry['index'], ZipArchive::FL_UNCHANGED);
        $destination = @fopen($stagedPath, 'wb');

        if (! is_resource($source) || ! is_resource($destination)) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($destination)) {
                fclose($destination);
            }

            throw new ArchiveValidationException("External archive media could not be staged: {$archivePath}");
        }

        $hash = hash_init('sha256');
        $bytes = 0;
        $expectedBytes = (int) $entry['size'];

        try {
            while (! feof($source)) {
                $chunk = fread($source, 1024 * 1024);

                if ($chunk === false) {
                    throw new ArchiveValidationException("External archive media could not be read: {$archivePath}");
                }

                if ($chunk === '') {
                    continue;
                }

                $length = strlen($chunk);
                $bytes += $length;

                if ($bytes > $expectedBytes || fwrite($destination, $chunk) !== $length) {
                    throw new ArchiveValidationException("External archive media could not be staged completely: {$archivePath}");
                }

                hash_update($hash, $chunk);
            }
        } finally {
            fclose($source);
            fclose($destination);
        }

        if ($bytes !== $expectedBytes) {
            throw new ArchiveValidationException("External archive media byte count changed while reading: {$archivePath}");
        }

        $imageInfo = @getimagesize($stagedPath);
        $mimeType = is_array($imageInfo) ? ($imageInfo['mime'] ?? null) : null;
        $width = is_array($imageInfo) ? (int) ($imageInfo[0] ?? 0) : 0;
        $height = is_array($imageInfo) ? (int) ($imageInfo[1] ?? 0) : 0;

        if (! is_string($mimeType) || ! isset(self::IMAGE_EXTENSIONS[$mimeType]) || $width < 1 || $height < 1) {
            throw new ArchiveValidationException("External archive media is not a supported decodable image: {$archivePath}");
        }

        if (strtolower((string) pathinfo($archivePath, PATHINFO_EXTENSION)) !== self::IMAGE_EXTENSIONS[$mimeType]) {
            throw new ArchiveValidationException("External archive media extension does not match its decoded type: {$archivePath}");
        }

        try {
            $this->images->processPage(new UploadedFile(
                $stagedPath,
                basename($archivePath),
                $mimeType,
                null,
                true,
            ));
        } catch (ImageProcessingException $e) {
            throw new ArchiveValidationException(
                "External archive image could not be decoded safely: {$archivePath}. {$e->getMessage()}",
                previous: $e,
            );
        }

        return [
            'archive_path' => $archivePath,
            'mime_type' => $mimeType,
            'sha256' => hash_final($hash),
            'bytes' => $bytes,
            'width' => $width,
            'height' => $height,
        ];
    }

    /** @param null|callable(int, string, string): void $progress */
    private function mediaProgress(?callable $progress, int $processed, int $total): void
    {
        if ($progress !== null) {
            $percent = 12 + (int) floor(($processed / max(1, $total)) * 36);
            $progress($percent, 'Validating', "Validated {$processed} of {$total} media files.");
        }
    }

    protected function safeEntryPath(string $path): bool
    {
        if ($path === '' || $path[0] === '/' || str_contains($path, '\\') || str_contains($path, "\0")) {
            return false;
        }

        if (str_ends_with($path, '/') || str_contains($path, '//') || preg_match('#(?:\A|/)\.\.?(/|\z)#', $path) === 1) {
            return false;
        }

        return preg_match('/\A[A-Za-z0-9._\/-]+\z/', $path) === 1;
    }

    protected function isSymlink(ZipArchive $zip, int $index): bool
    {
        $operatingSystem = 0;
        $attributes = 0;

        if (! $zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)) {
            return false;
        }

        return $operatingSystem === ZipArchive::OPSYS_UNIX
            && (($attributes >> 16) & 0170000) === 0120000;
    }

    protected function limit(string $key, int $default): int
    {
        return max(1, (int) config("koma.archives.imports.{$key}", $default));
    }
}
