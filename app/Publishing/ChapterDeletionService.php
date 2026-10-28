<?php

namespace App\Publishing;

use App\Models\Chapter;
use App\Models\Comment;
use App\Models\Series;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class ChapterDeletionService
{
    /**
     * Delete one chapter with its pages and comments, then remove its media.
     *
     * Returns false only when the database deletion committed but one or more
     * media files could not be removed.
     */
    public function delete(Chapter $chapter): bool
    {
        return $this->deleteMany($chapter->series, [$chapter->getKey()]) !== false;
    }

    /**
     * Delete several chapters of one series in a single transaction.
     *
     * @param  list<int>  $chapterIds
     * @return int|false the number of chapters deleted, or false when they were
     *                   deleted but some media files could not be removed
     *
     * @throws ValidationException when an id does not belong to the series
     */
    public function deleteMany(Series $series, array $chapterIds): int|false
    {
        $chapterIds = array_values(array_unique(array_map('intval', $chapterIds)));

        [$count, $mediaPaths] = DB::transaction(function () use ($series, $chapterIds): array {
            $chapters = Chapter::query()
                ->where('series_id', $series->getKey())
                ->whereIn('id', $chapterIds)
                ->lockForUpdate()
                ->with('pages')
                ->get();

            if ($chapters->count() !== count($chapterIds)) {
                throw ValidationException::withMessages([
                    'selectedChapters' => 'One or more selected chapters no longer belong to this series. Reload and try again.',
                ]);
            }

            $mediaPaths = $chapters
                ->flatMap(fn (Chapter $chapter) => $chapter->pages->pluck('image_path'))
                ->filter()
                ->unique()
                ->values()
                ->all();

            // Comments are polymorphic with no foreign key, so they would
            // otherwise outlive the chapter.
            Comment::withTrashed()
                ->where('commentable_type', Chapter::class)
                ->whereIn('commentable_id', $chapterIds)
                ->forceDelete();

            foreach ($chapters as $chapter) {
                $chapter->delete();
            }

            return [$chapters->count(), $mediaPaths];
        });

        if ($mediaPaths === []) {
            return $count;
        }

        try {
            if (Storage::disk('media')->delete($mediaPaths)) {
                return $count;
            }

            report(new RuntimeException('One or more deleted-chapter media files could not be removed.'));
        } catch (Throwable $exception) {
            report($exception);
        }

        return false;
    }
}
