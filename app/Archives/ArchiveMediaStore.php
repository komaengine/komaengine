<?php

namespace App\Archives;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ArchiveMediaStore
{
    public function store(string $stagedPath, string $destination, int $expectedBytes): void
    {
        $stream = @fopen($stagedPath, 'rb');

        if (! is_resource($stream)) {
            throw new ArchiveException('Validated archive media could not be reopened for import.');
        }

        try {
            $disk = Storage::disk('media');

            if (! $disk->put($destination, $stream) || ! $disk->exists($destination)) {
                throw new RuntimeException("Imported media could not be stored: {$destination}");
            }

            if ((int) $disk->size($destination) !== $expectedBytes) {
                throw new RuntimeException("Imported media size verification failed: {$destination}");
            }
        } catch (Throwable $e) {
            try {
                Storage::disk('media')->delete($destination);
            } catch (Throwable $cleanupError) {
                report($cleanupError);
            }

            if ($e instanceof ArchiveException) {
                throw $e;
            }

            throw new ArchiveException('Archive media storage failed: '.$e->getMessage(), previous: $e);
        } finally {
            fclose($stream);
        }
    }

    public function deleteDirectory(string $directory): void
    {
        if (! Storage::disk('media')->deleteDirectory($directory)) {
            throw new ArchiveException("Imported media directory could not be removed: {$directory}");
        }
    }

    /** @param list<string> $paths */
    public function deletePaths(array $paths): void
    {
        if ($paths !== []) {
            if (! Storage::disk('media')->delete(array_values(array_unique($paths)))) {
                throw new ArchiveException('One or more replaced media files could not be removed.');
            }
        }
    }
}
