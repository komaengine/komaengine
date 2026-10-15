<?php

namespace App\Publishing;

use App\Models\Chapter;
use App\Models\Comment;
use App\Models\Series;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class SeriesDeletionService
{
    /**
     * Delete a series and its relational data, then remove its media.
     *
     * Returns false only when the database deletion committed but one or more
     * media files could not be removed.
     */
    public function delete(Series $series): bool
    {
        $mediaPaths = DB::transaction(function () use ($series): array {
            $lockedSeries = Series::query()
                ->whereKey($series->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedSeries->load('chapters.pages');
            $chapterIds = $lockedSeries->chapters->pluck('id')->all();
            $mediaPaths = collect([
                $lockedSeries->cover_path,
                $lockedSeries->cover_thumbnail_path,
            ])->merge(
                $lockedSeries->chapters->flatMap(
                    fn (Chapter $chapter) => $chapter->pages->pluck('image_path'),
                ),
            )->filter()->unique()->values()->all();

            Comment::withTrashed()
                ->where(function (Builder $comments) use ($lockedSeries, $chapterIds): void {
                    $comments->where(function (Builder $seriesComments) use ($lockedSeries): void {
                        $seriesComments
                            ->where('commentable_type', Series::class)
                            ->where('commentable_id', $lockedSeries->getKey());
                    });

                    if ($chapterIds !== []) {
                        $comments->orWhere(function (Builder $chapterComments) use ($chapterIds): void {
                            $chapterComments
                                ->where('commentable_type', Chapter::class)
                                ->whereIn('commentable_id', $chapterIds);
                        });
                    }
                })
                ->forceDelete();

            foreach ($lockedSeries->chapters as $chapter) {
                $chapter->delete();
            }

            $lockedSeries->delete();

            return $mediaPaths;
        });

        if ($mediaPaths === []) {
            return true;
        }

        try {
            if (Storage::disk('media')->delete($mediaPaths)) {
                return true;
            }

            report(new RuntimeException('One or more deleted-series media files could not be removed.'));
        } catch (Throwable $exception) {
            report($exception);
        }

        return false;
    }
}
