<?php

namespace App\Publishing;

use App\Models\Chapter;
use App\Models\Series;
use App\Support\Facades\Hook;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChapterBulkPublishing
{
    /**
     * Publish the selected chapters now. A scheduled chapter is published
     * immediately; an already published one keeps its date.
     *
     * @param  list<int>  $chapterIds
     * @return int the number of chapters that changed
     */
    public function publish(Series $series, array $chapterIds): int
    {
        $published = new Collection;

        $changed = DB::transaction(function () use ($series, $chapterIds, $published): Collection {
            $changed = new Collection;

            foreach ($this->lockedChapters($series, $chapterIds) as $chapter) {
                if ($chapter->isPublished()) {
                    continue;
                }

                $chapter->forceFill([
                    'status' => Chapter::STATUS_PUBLISHED,
                    'published_at' => now(),
                ])->saveQuietly();
                $changed->push($chapter);
                $published->push($chapter);
            }

            return $changed;
        });

        // Hooks run after commit, as the moderation actions do, so a plugin
        // never sees a state that was rolled back.
        foreach ($changed as $chapter) {
            Hook::doAction('chapter.saved', $chapter);
        }

        foreach ($published as $chapter) {
            Hook::doAction('chapter.published', $chapter);
        }

        return $changed->count();
    }

    /**
     * Return the selected chapters to draft, cancelling any schedule.
     *
     * @param  list<int>  $chapterIds
     * @return int the number of chapters that changed
     */
    public function unpublish(Series $series, array $chapterIds): int
    {
        $changed = DB::transaction(function () use ($series, $chapterIds): Collection {
            $changed = new Collection;

            foreach ($this->lockedChapters($series, $chapterIds) as $chapter) {
                if ($chapter->status === Chapter::STATUS_DRAFT && $chapter->published_at === null) {
                    continue;
                }

                $chapter->forceFill([
                    'status' => Chapter::STATUS_DRAFT,
                    'published_at' => null,
                ])->saveQuietly();
                $changed->push($chapter);
            }

            return $changed;
        });

        foreach ($changed as $chapter) {
            Hook::doAction('chapter.saved', $chapter);
        }

        return $changed->count();
    }

    /**
     * @param  list<int>  $chapterIds
     * @return Collection<int, Chapter>
     */
    protected function lockedChapters(Series $series, array $chapterIds): Collection
    {
        $chapterIds = array_values(array_unique(array_map('intval', $chapterIds)));
        $chapters = Chapter::query()
            ->where('series_id', $series->getKey())
            ->whereIn('id', $chapterIds)
            ->lockForUpdate()
            ->get();

        if ($chapters->count() !== count($chapterIds)) {
            throw ValidationException::withMessages([
                'selectedChapters' => 'One or more selected chapters no longer belong to this series. Reload and try again.',
            ]);
        }

        return $chapters;
    }
}
