<?php

namespace App\Livewire\Admin\Chapters;

use App\Media\ImageProcessingException;
use App\Media\ImageProcessor;
use App\Models\Chapter;
use App\Models\Page;
use App\Models\Series;
use App\Publishing\ChapterPageService;
use App\Publishing\PageUploadRules;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

#[Layout('components.layouts.admin')]
class Pages extends Component
{
    use WithFileUploads;

    public Series $series;

    public Chapter $chapter;

    /** @var array<int, mixed> */
    public array $replacementFiles = [];

    public function mount(Series $series, Chapter $chapter): void
    {
        Gate::authorize('edit_series');
        abort_unless((int) $chapter->series_id === (int) $series->id, 404);

        $this->series = $series;
        $this->chapter = $chapter;
    }

    public function movePage(int $pageId, string $direction): void
    {
        $this->authorizeEditor();

        if (! in_array($direction, ['earlier', 'later'], true)) {
            return;
        }

        $pageIds = $this->orderedPageIds();
        $index = array_search($pageId, $pageIds, true);

        if ($index === false) {
            abort(404);
        }

        $target = $direction === 'earlier' ? $index - 1 : $index + 1;

        if (! array_key_exists($target, $pageIds)) {
            return;
        }

        [$pageIds[$index], $pageIds[$target]] = [$pageIds[$target], $pageIds[$index]];
        $this->persistOrder($pageIds);
    }

    public function dropPage(int $pageId, int $targetPageId): void
    {
        $this->authorizeEditor();

        if ($pageId === $targetPageId) {
            return;
        }

        $pageIds = $this->orderedPageIds();
        $sourceIndex = array_search($pageId, $pageIds, true);
        $targetIndex = array_search($targetPageId, $pageIds, true);

        if ($sourceIndex === false || $targetIndex === false) {
            abort(404);
        }

        array_splice($pageIds, $sourceIndex, 1);
        $adjustedTarget = array_search($targetPageId, $pageIds, true);
        $insertAt = $sourceIndex < $targetIndex ? $adjustedTarget + 1 : $adjustedTarget;
        array_splice($pageIds, $insertAt, 0, [$pageId]);

        $this->persistOrder($pageIds);
    }

    public function replacePage(int $pageId): void
    {
        $this->authorizeEditor();
        $page = $this->pageForId($pageId);

        $this->validate([
            "replacementFiles.{$pageId}" => array_merge(
                ['required'],
                app(PageUploadRules::class)->get(),
            ),
        ], [], ["replacementFiles.{$pageId}" => 'replacement page']);

        try {
            app(ChapterPageService::class)->replace(
                $this->chapter,
                $page,
                $this->replacementFiles[$pageId],
            );
        } catch (ImageProcessingException $e) {
            if (! $e->clientFault) {
                report($e);
            }

            $this->addError("replacementFiles.{$pageId}", $e->getMessage().' The existing page is unchanged.');

            return;
        } catch (Throwable $e) {
            report($e);
            $this->addError(
                "replacementFiles.{$pageId}",
                'The page could not be replaced. The existing page is unchanged.',
            );

            return;
        }

        unset($this->replacementFiles[$pageId]);
        $this->resetValidation("replacementFiles.{$pageId}");
        session()->flash('status', "Page {$page->sort_order} replaced.");
    }

    public function deletePage(int $pageId): void
    {
        $this->authorizeEditor();
        $page = $this->pageForId($pageId);

        try {
            app(ChapterPageService::class)->delete($this->chapter, $page);
        } catch (Throwable $e) {
            report($e);
            $this->addError('pageOrder', $e->getMessage());

            return;
        }

        unset($this->replacementFiles[$pageId]);
        session()->flash('status', 'Page deleted and chapter order normalized.');
    }

    public function render()
    {
        return view('livewire.admin.chapters.pages', [
            'pages' => $this->chapter->pages()->get(),
            'imageCapabilities' => app(ImageProcessor::class)->capabilities(),
        ]);
    }

    /**
     * @param  array<int, int>  $pageIds
     */
    protected function persistOrder(array $pageIds): void
    {
        try {
            app(ChapterPageService::class)->reorder($this->chapter, $pageIds);
        } catch (Throwable $e) {
            report($e);
            $this->addError('pageOrder', 'The page order could not be saved. The existing order is unchanged.');

            return;
        }

        $this->resetValidation('pageOrder');
        session()->flash('status', 'Page order saved.');
    }

    /** @return array<int, int> */
    protected function orderedPageIds(): array
    {
        return $this->chapter->pages()
            ->pluck('id')
            ->map(fn (int $id) => $id)
            ->all();
    }

    protected function pageForId(int $pageId): Page
    {
        $page = $this->chapter->pages()->find($pageId);
        abort_unless($page, 404);

        return $page;
    }

    protected function authorizeEditor(): void
    {
        Gate::authorize('edit_series');
    }
}
