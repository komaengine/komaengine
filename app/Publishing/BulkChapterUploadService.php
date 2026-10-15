<?php

namespace App\Publishing;

use App\Models\Chapter;
use App\Models\Series;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class BulkChapterUploadService
{
    public function __construct(protected ChapterUploadService $chapterUploads) {}

    /**
     * Store every chapter as one atomic batch. If any chapter fails, database
     * changes and files created for earlier chapters are removed.
     *
     * @param  array<int, array<string, mixed>>  $chapters
     * @return array<int, Chapter>
     */
    public function store(Series $series, array $chapters): array
    {
        $createdDirectories = [];

        try {
            return DB::transaction(function () use ($series, $chapters, &$createdDirectories) {
                $created = [];

                foreach ($chapters as $attributes) {
                    $chapter = $this->chapterUploads->store(
                        $series,
                        [
                            'number' => $attributes['number'],
                            'title' => $attributes['title'] ?? null,
                            'scanlation_group' => $attributes['scanlation_group'] ?? null,
                            'language' => $attributes['language'] ?? null,
                        ],
                        $attributes['pages'],
                        (bool) $attributes['publish'],
                        $attributes['publish_at'] ?? null,
                    );

                    $createdDirectories[] = "pages/{$series->id}/{$chapter->id}";
                    $created[] = $chapter;
                }

                return $created;
            });
        } catch (Throwable $exception) {
            try {
                $disk = Storage::disk('media');

                foreach ($createdDirectories as $directory) {
                    $disk->deleteDirectory($directory);
                }
            } catch (Throwable $cleanupError) {
                report($cleanupError);
            }

            throw $exception;
        }
    }
}
