<?php

namespace App\Livewire\Admin\Series;

use App\Models\Genre;
use App\Models\Series as SeriesModel;
use App\Models\Tag;
use App\Publishing\SeriesDeletionService;
use Illuminate\Support\Facades\Gate;
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

    public function deleteSeries(int $seriesId, SeriesDeletionService $deletions): void
    {
        Gate::authorize('edit_series');

        $series = SeriesModel::query()->findOrFail($seriesId);
        $title = $series->title;
        $mediaDeleted = $deletions->delete($series);

        $this->resetPage();

        session()->flash(
            'status',
            $mediaDeleted
                ? "“{$title}” deleted."
                : "“{$title}” deleted, but some media cleanup failed. Check the application logs.",
        );
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
