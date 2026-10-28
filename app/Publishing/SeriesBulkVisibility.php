<?php

namespace App\Publishing;

use App\Models\Series;
use App\Support\Facades\Hook;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SeriesBulkVisibility
{
    /**
     * Make the selected series public or private. Chapters are not touched,
     * so a public series stays hidden from readers until it has a published
     * chapter.
     *
     * @param  list<int>  $seriesIds
     * @return int the number of series that changed
     */
    public function apply(array $seriesIds, bool $public): int
    {
        $changed = DB::transaction(function () use ($seriesIds, $public): Collection {
            $changed = new Collection;

            foreach ($this->lockedSeries($seriesIds) as $series) {
                if ($series->is_public === $public) {
                    continue;
                }

                $series->forceFill(['is_public' => $public])->saveQuietly();
                $changed->push($series);
            }

            return $changed;
        });

        // Hooks run after commit, as the chapter bulk actions do, so a plugin
        // never sees a state that was rolled back.
        foreach ($changed as $series) {
            Hook::doAction('series.saved', $series);
        }

        return $changed->count();
    }

    /**
     * @param  list<int>  $seriesIds
     * @return Collection<int, Series>
     */
    protected function lockedSeries(array $seriesIds): Collection
    {
        $seriesIds = array_values(array_unique(array_map('intval', $seriesIds)));
        $series = Series::query()
            ->whereKey($seriesIds)
            ->lockForUpdate()
            ->get();

        if ($series->count() !== count($seriesIds)) {
            throw ValidationException::withMessages([
                'selectedSeries' => 'One or more selected series no longer exist. Reload and try again.',
            ]);
        }

        return $series;
    }
}
