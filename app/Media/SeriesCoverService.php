<?php

namespace App\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SeriesCoverService
{
    public function __construct(protected ImageProcessor $images) {}

    public function prepare(
        UploadedFile $file,
        float $centerX,
        float $centerY,
        float $zoom,
    ): PreparedCover {
        $processed = $this->images->processCover($file, $centerX, $centerY, $zoom);
        $directory = 'covers/'.Str::uuid();
        $coverPath = $directory.'/cover.'.$processed->cover->extension;
        $thumbnailPath = $directory.'/thumbnail.'.$processed->thumbnail->extension;
        $prepared = new PreparedCover(
            $coverPath,
            $thumbnailPath,
            $processed->cover,
            $processed->thumbnail,
        );

        try {
            $disk = Storage::disk('media');

            if (! $disk->put($coverPath, $processed->cover->bytes) || ! $disk->exists($coverPath)) {
                throw new RuntimeException('The optimized cover could not be stored.');
            }

            if (! $disk->put($thumbnailPath, $processed->thumbnail->bytes) || ! $disk->exists($thumbnailPath)) {
                throw new RuntimeException('The cover thumbnail could not be stored.');
            }
        } catch (Throwable $e) {
            $this->discard($prepared);
            throw $e;
        }

        return $prepared;
    }

    public function discard(PreparedCover $cover): void
    {
        try {
            Storage::disk('media')->deleteDirectory(dirname($cover->coverPath));
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function deletePrevious(?string $coverPath, ?string $thumbnailPath): void
    {
        $paths = array_values(array_filter([$coverPath, $thumbnailPath]));

        if ($paths === []) {
            return;
        }

        try {
            if (! Storage::disk('media')->delete($paths)) {
                report(new RuntimeException('One or more replaced cover files could not be removed.'));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
