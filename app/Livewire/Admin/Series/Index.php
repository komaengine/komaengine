<?php

namespace App\Livewire\Admin\Series;

use App\Models\Genre;
use App\Models\Series as SeriesModel;
use App\Models\Tag;
use App\Publishing\SeriesBulkVisibility;
use App\Publishing\SeriesDeletionService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'visibility')]
    public string $visibilityFilter = '';

    #[Url(as: 'genre')]
    public string $genreFilter = '';

    #[Url(as: 'tag')]
    public string $tagFilter = '';

    /** @var list<int> */
    public array $selectedSeries = [];

    public ?string $seriesNotice = null;

    public bool $seriesNoticeIsError = false;

    public function applyFilters(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'statusFilter', 'visibilityFilter', 'genreFilter', 'tagFilter');
        $this->resetPage();
    }

    public function filterByGenre(int $genreId): void
    {
        $this->genreFilter = (string) $genreId;
        $this->resetPage();
    }

    public function filterByTag(int $tagId): void
    {
        $this->tagFilter = (string) $tagId;
        $this->resetPage();
    }

    /**
     * A selection only ever covers the page it was made on, so any filter or
     * page change clears it.
     */
    public function updatedPaginators(): void
    {
        $this->selectedSeries = [];
    }

    public function deleteSeries(int $seriesId, SeriesDeletionService $deletions): void
    {
        Gate::authorize('edit_series');

        $series = SeriesModel::query()->findOrFail($seriesId);
        $title = $series->title;
        $mediaDeleted = $deletions->delete($series);

        $this->resetPage();

        $this->notifySeries(
            $mediaDeleted
                ? "“{$title}” deleted."
                : "“{$title}” deleted, but some media cleanup failed. Check the application logs.",
            ! $mediaDeleted,
        );
    }

    public function applySeriesBulk(string $action, SeriesBulkVisibility $visibility, SeriesDeletionService $deletions): void
    {
        Gate::authorize('edit_series');

        $this->validate([
            'selectedSeries' => ['required', 'array', 'min:1'],
            'selectedSeries.*' => ['integer', 'distinct'],
        ], [
            'selectedSeries.required' => 'Select at least one series.',
            'selectedSeries.min' => 'Select at least one series.',
        ]);

        abort_unless(in_array($action, ['public', 'private', 'delete'], true), 422);

        $ids = array_map('intval', $this->selectedSeries);
        $selected = count($ids);
        $label = fn (int $count): string => "{$count} series";

        if ($action === 'delete') {
            $series = SeriesModel::query()->whereKey($ids)->get();

            if ($series->count() !== $selected) {
                throw ValidationException::withMessages([
                    'selectedSeries' => 'One or more selected series no longer exist. Reload and try again.',
                ]);
            }

            // Each series is its own transaction; a media cleanup failure on one
            // is reported and the rest are still deleted.
            $cleanupFailed = false;
            foreach ($series as $item) {
                $cleanupFailed = ! $deletions->delete($item) || $cleanupFailed;
            }

            $this->resetPage();
            $this->notifySeries(
                $cleanupFailed
                    ? "Deleted {$label($selected)}, but some media files could not be removed. Check the application logs."
                    : "Deleted {$label($selected)}.",
                $cleanupFailed,
            );

            return;
        }

        $public = $action === 'public';
        $changed = $visibility->apply($ids, $public);
        $unchanged = $selected - $changed;
        $message = match (true) {
            $changed === 0 => 'The selected series '.($selected === 1 ? 'was' : 'were')." already {$action}.",
            $unchanged === 0 => "Made {$label($changed)} {$action}.",
            default => "Made {$label($changed)} {$action}. {$unchanged} ".($unchanged === 1 ? 'was' : 'were')." already {$action}.",
        };

        if ($public) {
            $hidden = SeriesModel::query()
                ->whereKey($ids)
                ->whereDoesntHave('chapters', fn ($chapters) => $chapters->published())
                ->count();

            if ($hidden > 0) {
                $message .= $hidden === 1
                    ? ' 1 series stays hidden from readers until it has a published chapter.'
                    : " {$hidden} series stay hidden from readers until they have a published chapter.";
            }
        }

        $this->selectedSeries = [];
        $this->notifySeries($message);
    }

    protected function notifySeries(string $message, bool $error = false): void
    {
        $this->seriesNotice = $message;
        $this->seriesNoticeIsError = $error;
    }

    public function render()
    {
        $series = SeriesModel::query()
            ->with(['genres:id,name', 'tags:id,name'])
            ->withCount(['chapters', 'comments', 'chapterComments'])
            ->search($this->search)
            ->when(
                in_array($this->statusFilter, SeriesModel::STATUSES, true),
                fn ($query) => $query->where('status', $this->statusFilter),
            )
            ->when(
                in_array($this->visibilityFilter, ['public', 'private'], true),
                fn ($query) => $query->where('is_public', $this->visibilityFilter === 'public'),
            )
            ->when(
                ctype_digit($this->genreFilter) && (int) $this->genreFilter > 0,
                fn ($query) => $query->whereHas(
                    'genres',
                    fn ($genres) => $genres->whereKey((int) $this->genreFilter),
                ),
            )
            ->when(
                ctype_digit($this->tagFilter) && (int) $this->tagFilter > 0,
                fn ($query) => $query->whereHas(
                    'tags',
                    fn ($tags) => $tags->whereKey((int) $this->tagFilter),
                ),
            )
            ->orderByDesc('updated_at')
            ->paginate(20);

        return view('livewire.admin.series.index', [
            'series' => $series,
            'genres' => Genre::query()->orderBy('name')->get(),
            'tags' => Tag::query()->orderBy('name')->get(),
            'filtersActive' => collect([
                $this->search,
                $this->statusFilter,
                $this->visibilityFilter,
                $this->genreFilter,
                $this->tagFilter,
            ])->contains(fn ($value) => filled($value)),
        ]);
    }
}
