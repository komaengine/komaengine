<?php

namespace App\Publishing;

use App\Media\ImageProcessor;
use App\Models\Chapter;
use App\Models\Page;
use App\Support\Facades\Hook;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ChapterPageService
{
    public function __construct(
        protected PageUploadRules $uploadRules,
        protected ImageProcessor $images,
    ) {}

    /**
     * @param  array<int, int>  $pageIds
     * @return Collection<int, Page>
     */
    public function reorder(Chapter $chapter, array $pageIds): Collection
    {
        $pageIds = array_values(array_map('intval', $pageIds));

        $pages = DB::transaction(function () use ($chapter, $pageIds) {
            $lockedPages = Page::query()
                ->where('chapter_id', $chapter->id)
                ->lockForUpdate()
                ->get();

            $existingIds = $lockedPages->pluck('id')->map(fn (int $id) => $id)->all();

            if (
                $pageIds === []
                || count($pageIds) !== count(array_unique($pageIds))
                || count($pageIds) !== count($existingIds)
                || array_diff($pageIds, $existingIds) !== []
                || array_diff($existingIds, $pageIds) !== []
            ) {
                throw new InvalidArgumentException('The page order must contain every chapter page exactly once.');
            }

            $pagesById = $lockedPages->keyBy('id');
            $temporaryOffset = ((int) $lockedPages->max('sort_order')) + count($pageIds) + 1;

            foreach ($pageIds as $index => $pageId) {
                $pagesById[$pageId]->forceFill([
                    'sort_order' => $temporaryOffset + $index,
                ])->saveQuietly();
            }

            foreach ($pageIds as $index => $pageId) {
                $pagesById[$pageId]->forceFill([
                    'sort_order' => $index + 1,
                ])->saveQuietly();
            }

            return $chapter->pages()->get();
        });

        $this->emitSavedHooks($chapter, $pages);

        return $pages;
    }

    public function replace(Chapter $chapter, Page $page, UploadedFile $replacement): Page
    {
        $this->assertBelongsToChapter($chapter, $page);

        Validator::make(
            ['replacement' => $replacement],
            ['replacement' => array_merge(['required'], $this->uploadRules->get())],
        )->validate();

        $processed = $this->images->processPage($replacement);
        $directory = "pages/{$chapter->series_id}/{$chapter->id}";
        $filename = 'page-'.$page->id.'-'.Str::uuid().'.'.$processed->extension;
        $disk = Storage::disk('media');
        $storedPath = $directory.'/'.$filename;
        $stored = $disk->put($storedPath, $processed->bytes);

        if (! $stored || ! $disk->exists($storedPath)) {
            $disk->delete($storedPath);

            throw new RuntimeException('The replacement page could not be stored.');
        }

        try {
            [$updatedPage, $oldPath] = DB::transaction(function () use (
                $chapter,
                $page,
                $storedPath,
                $processed,
            ) {
                $lockedPage = Page::query()
                    ->where('chapter_id', $chapter->id)
                    ->whereKey($page->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $oldPath = $lockedPage->image_path;

                $lockedPage->fill([
                    'image_path' => $storedPath,
                    'width' => $processed->width,
                    'height' => $processed->height,
                    'file_size' => $processed->fileSize(),
                ])->save();

                return [$lockedPage->fresh(), $oldPath];
            });
        } catch (Throwable $e) {
            try {
                $disk->delete($storedPath);
            } catch (Throwable $cleanupError) {
                report($cleanupError);
            }

            throw $e;
        }

        $this->deleteAfterCommit($oldPath);
        $this->emitSavedHooks($chapter, new Collection([$updatedPage]));

        return $updatedPage;
    }

    /**
     * @return Collection<int, Page>
     */
    public function delete(Chapter $chapter, Page $page): Collection
    {
        $this->assertBelongsToChapter($chapter, $page);

        [$deletedPage, $remainingPages] = DB::transaction(function () use ($chapter, $page) {
            $lockedPages = Page::query()
                ->where('chapter_id', $chapter->id)
                ->lockForUpdate()
                ->get();

            if ($lockedPages->count() <= 1) {
                throw new InvalidArgumentException('A chapter must retain at least one page.');
            }

            $lockedPage = $lockedPages->firstWhere('id', $page->id);

            if (! $lockedPage) {
                throw new InvalidArgumentException('That page does not belong to this chapter.');
            }

            $deletedPage = $lockedPage->replicate();
            $deletedPage->id = $lockedPage->id;
            $deletedPage->exists = false;
            $lockedPage->delete();

            $remainingIds = $lockedPages
                ->reject(fn (Page $candidate) => $candidate->is($lockedPage))
                ->sortBy('sort_order')
                ->pluck('id')
                ->values()
                ->all();

            $this->normalizeLockedPages($chapter, $remainingIds);

            return [$deletedPage, $chapter->pages()->get()];
        });

        $this->deleteAfterCommit($deletedPage->image_path);
        $this->emitAction('page.deleted', $deletedPage);
        $this->emitSavedHooks($chapter, $remainingPages);

        return $remainingPages;
    }

    /**
     * @param  array<int, int>  $pageIds
     */
    protected function normalizeLockedPages(Chapter $chapter, array $pageIds): void
    {
        $pages = Page::query()
            ->where('chapter_id', $chapter->id)
            ->whereIn('id', $pageIds)
            ->get()
            ->keyBy('id');

        $temporaryOffset = ((int) $pages->max('sort_order')) + count($pageIds) + 1;

        foreach ($pageIds as $index => $pageId) {
            $pages[$pageId]->forceFill([
                'sort_order' => $temporaryOffset + $index,
            ])->saveQuietly();
        }

        foreach ($pageIds as $index => $pageId) {
            $pages[$pageId]->forceFill([
                'sort_order' => $index + 1,
            ])->saveQuietly();
        }
    }

    protected function assertBelongsToChapter(Chapter $chapter, Page $page): void
    {
        if ((int) $page->chapter_id !== (int) $chapter->id) {
            throw new InvalidArgumentException('That page does not belong to this chapter.');
        }
    }

    protected function deleteAfterCommit(string $path): void
    {
        try {
            if (! Storage::disk('media')->delete($path)) {
                report(new RuntimeException("The old page file could not be removed: {$path}"));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  Collection<int, Page>  $pages
     */
    protected function emitSavedHooks(Chapter $chapter, Collection $pages): void
    {
        foreach ($pages as $page) {
            $this->emitAction('page.saved', $page);
        }

        $this->emitAction('chapter.saved', $chapter->fresh());
    }

    protected function emitAction(string $hook, mixed $payload): void
    {
        try {
            Hook::doAction($hook, $payload);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
