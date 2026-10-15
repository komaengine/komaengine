<?php

namespace App\Publishing;

use App\Media\ImageProcessor;
use App\Models\Chapter;
use App\Models\Page;
use App\Models\Series;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ChapterUploadService
{
    public function __construct(protected ImageProcessor $images) {}

    /**
     * @param  array{number: string|int|float, title?: ?string, scanlation_group?: ?string, language?: ?string}  $attributes
     * @param  array<int, UploadedFile>  $files
     */
    public function store(
        Series $series,
        array $attributes,
        array $files,
        bool $publish,
        ?CarbonInterface $publishAt = null,
    ): Chapter {
        $attemptedPaths = [];
        $directories = [];

        try {
            return DB::transaction(function () use (
                $series,
                $attributes,
                $files,
                $publish,
                $publishAt,
                &$attemptedPaths,
                &$directories,
            ) {
                $chapter = $series->chapters()->create([
                    'number' => $attributes['number'],
                    'title' => $attributes['title'] ?? null,
                    'scanlation_group' => $attributes['scanlation_group'] ?? null,
                    'language' => $attributes['language'] ?? null,
                    'status' => 'draft',
                ]);

                $directory = "pages/{$series->id}/{$chapter->id}";
                $directories[] = $directory;

                foreach (array_values($files) as $index => $file) {
                    $sortOrder = $index + 1;
                    $processed = $this->images->processPage($file);
                    $filename = str_pad((string) $sortOrder, 3, '0', STR_PAD_LEFT).'.'.$processed->extension;
                    $expectedPath = $directory.'/'.$filename;

                    $attemptedPaths[] = $expectedPath;
                    $stored = Storage::disk('media')->put($expectedPath, $processed->bytes);

                    if (! $stored || ! Storage::disk('media')->exists($expectedPath)) {
                        throw new RuntimeException("Page {$sortOrder} could not be stored.");
                    }

                    Page::create([
                        'chapter_id' => $chapter->id,
                        'sort_order' => $sortOrder,
                        'image_path' => $expectedPath,
                        'width' => $processed->width,
                        'height' => $processed->height,
                        'file_size' => $processed->fileSize(),
                    ]);
                }

                if ($publishAt !== null) {
                    $chapter->scheduleFor($publishAt);
                } elseif ($publish) {
                    $chapter->publish();
                }

                return $chapter->fresh('pages');
            });
        } catch (Throwable $e) {
            try {
                $this->cleanUp($attemptedPaths, $directories);
            } catch (Throwable $cleanupError) {
                report($cleanupError);
            }

            throw $e;
        }
    }

    /**
     * @param  array<int, string>  $paths
     * @param  array<int, string>  $directories
     */
    protected function cleanUp(array $paths, array $directories): void
    {
        $disk = Storage::disk('media');

        if ($paths !== []) {
            $disk->delete(array_values(array_unique($paths)));
        }

        foreach (array_unique($directories) as $directory) {
            $disk->deleteDirectory($directory);
        }
    }
}
