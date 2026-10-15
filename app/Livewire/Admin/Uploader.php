<?php

namespace App\Livewire\Admin;

use App\Media\ImageProcessingException;
use App\Media\ImageProcessor;
use App\Models\Chapter;
use App\Models\Series;
use App\Publishing\ChapterUploadService;
use App\Publishing\PageUploadRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

#[Layout('components.layouts.admin')]
class Uploader extends Component
{
    use WithFileUploads;

    public ?int $seriesId = null;

    public ?string $number = null;

    public ?string $chapterTitle = null;

    public ?string $scanlationGroup = null;

    public ?string $chapterLanguage = null;

    public ?string $publishAt = null;

    /** @var array<int, TemporaryUploadedFile> accumulated page images, in order */
    public array $pages = [];

    /** Bound to the file input; merged into $pages then cleared so drops add up. */
    public $incoming = [];

    /** @var array<int, int> stable selection slots in the same order as the page array */
    public array $pageSequences = [];

    /** @var array<int, array{id: int, name: string, reason: string, sequence: int}> */
    public array $failedFiles = [];

    /** @var array<int, TemporaryUploadedFile> retry inputs keyed by failed-file id */
    public array $retryIncoming = [];

    public int $nextPageSequence = 1;

    public int $nextFailureId = 1;

    public bool $finalizing = false;

    public function mount(): void
    {
        $requested = (int) request('series');

        $this->seriesId = ($requested && Series::whereKey($requested)->exists())
            ? $requested
            : Series::orderBy('title')->value('id');
    }

    public function updatedIncoming(): void
    {
        $files = is_array($this->incoming) ? array_values($this->incoming) : [$this->incoming];

        foreach ($files as $file) {
            if (! $file instanceof TemporaryUploadedFile) {
                continue;
            }

            $sequence = $this->nextPageSequence++;
            $reason = app(PageUploadRules::class)->firstFailureFor($file);

            if ($reason !== null) {
                $this->addFailedFile($file, $reason, $sequence);
                $this->discardTemporary($file);

                continue;
            }

            $this->pages[] = $file;
            $this->pageSequences[] = $sequence;
        }

        $this->incoming = [];
        $this->resetErrorBag(['incoming', 'incoming.*']);
        $this->sortPagesBySequence();
        $this->dispatchSessionState();
    }

    public function updatedRetryIncoming(mixed $file, ?string $failureId): void
    {
        $id = (int) $failureId;
        $failureIndex = $this->failedFileIndex($id);

        if (! $file instanceof TemporaryUploadedFile || $failureIndex === null) {
            unset($this->retryIncoming[$id]);

            return;
        }

        $reason = app(PageUploadRules::class)->firstFailureFor($file);

        if ($reason !== null) {
            $this->failedFiles[$failureIndex]['name'] = $file->getClientOriginalName();
            $this->failedFiles[$failureIndex]['reason'] = $reason;
            $this->discardTemporary($file);
        } else {
            $this->pages[] = $file;
            $this->pageSequences[] = $this->failedFiles[$failureIndex]['sequence'];
            unset($this->failedFiles[$failureIndex]);
            $this->failedFiles = array_values($this->failedFiles);
            $this->sortPagesBySequence();
        }

        unset($this->retryIncoming[$id]);
        $this->resetErrorBag("retryIncoming.{$id}");

        if ($this->failedFiles === []) {
            $this->resetErrorBag('failedFiles');
        }

        $this->dispatchSessionState();
    }

    public function movePage(int $index, int $direction): void
    {
        $target = $index + $direction;

        if ($target < 0 || $target >= count($this->pages)) {
            return;
        }

        [$this->pages[$index], $this->pages[$target]] = [$this->pages[$target], $this->pages[$index]];
        $this->pages = array_values($this->pages);
    }

    public function removePage(int $index): void
    {
        $this->discardTemporary($this->pages[$index] ?? null);
        unset($this->pages[$index]);
        unset($this->pageSequences[$index]);
        $this->pages = array_values($this->pages);
        $this->pageSequences = array_values($this->pageSequences);
        $this->dispatchSessionState();
    }

    public function clearPages(): void
    {
        foreach ($this->pages as $page) {
            $this->discardTemporary($page);
        }

        $this->pages = [];
        $this->pageSequences = [];
        $this->failedFiles = [];
        $this->incoming = [];
        $this->retryIncoming = [];
        $this->nextPageSequence = 1;
        $this->nextFailureId = 1;
        $this->finalizing = false;
        $this->resetErrorBag(['incoming', 'incoming.*', 'pages', 'pages.*', 'failedFiles', 'retryIncoming.*']);
        $this->dispatchSessionState();
    }

    public function dismissFailed(int $failureId): void
    {
        $index = $this->failedFileIndex($failureId);

        if ($index === null) {
            return;
        }

        unset($this->failedFiles[$index], $this->retryIncoming[$failureId]);
        $this->failedFiles = array_values($this->failedFiles);

        if ($this->failedFiles === []) {
            $this->resetErrorBag('failedFiles');
        }

        $this->dispatchSessionState();
    }

    public function publish(): void
    {
        $this->saveChapter(Chapter::STATUS_PUBLISHED);
    }

    public function saveDraft(): void
    {
        $this->saveChapter(Chapter::STATUS_DRAFT);
    }

    public function schedule(): void
    {
        $this->saveChapter(Chapter::STATUS_SCHEDULED);
    }

    protected function saveChapter(string $publicationStatus): void
    {
        if ($this->finalizing) {
            return;
        }

        $this->finalizing = true;

        try {
            $validated = $this->validate([
                'seriesId' => ['required', 'exists:series,id'],
                'number' => [
                    'required', 'numeric', 'min:0',
                    Rule::unique('chapters', 'number')->where('series_id', $this->seriesId),
                ],
                'chapterTitle' => ['nullable', 'string', 'max:255'],
                'scanlationGroup' => ['nullable', 'string', 'max:255'],
                'chapterLanguage' => ['nullable', 'string', 'max:35', 'regex:/\A[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*\z/'],
                'publishAt' => $publicationStatus === Chapter::STATUS_SCHEDULED
                    ? ['required', 'date_format:Y-m-d\TH:i', 'after:now']
                    : ['nullable'],
                'pages' => ['required', 'array', 'min:1'],
                'pages.*' => $this->pageRules(),
                'failedFiles' => ['array', 'size:0'],
            ], array_merge([
                'pages.required' => 'Add at least one page before publishing.',
                'number.unique' => 'This series already has a chapter with that number.',
                'failedFiles.size' => 'Retry or dismiss every failed file before saving the chapter.',
            ], app(PageUploadRules::class)->messages('pages.*')));
        } catch (Throwable $e) {
            $this->finalizing = false;

            throw $e;
        }

        $series = Series::findOrFail($this->seriesId);
        $publishAt = $publicationStatus === Chapter::STATUS_SCHEDULED
            ? CarbonImmutable::createFromFormat(
                'Y-m-d\TH:i',
                $validated['publishAt'],
                config('app.timezone'),
            )->utc()
            : null;

        try {
            $chapter = app(ChapterUploadService::class)->store($series, [
                'number' => $validated['number'],
                'title' => $validated['chapterTitle'] ?: null,
                'scanlation_group' => $validated['scanlationGroup'] ?: null,
                'language' => $validated['chapterLanguage'] ?: null,
            ],
                $this->pages,
                $publicationStatus === Chapter::STATUS_PUBLISHED,
                $publishAt,
            );
        } catch (UniqueConstraintViolationException) {
            $this->finalizing = false;
            $this->addError('number', 'This series already has a chapter with that number.');

            return;
        } catch (ImageProcessingException $e) {
            $this->finalizing = false;
            if (! $e->clientFault) {
                report($e);
            }

            $this->addError('pages', $e->getMessage().' No chapter or page files were kept.');

            return;
        } catch (Throwable $e) {
            $this->finalizing = false;
            report($e);
            $this->addError('pages', 'The chapter could not be saved. No chapter or page files were kept.');

            return;
        }

        foreach ($this->pages as $page) {
            $this->discardTemporary($page);
        }

        $this->pages = [];
        $this->pageSequences = [];

        session()->flash('status', match ($publicationStatus) {
            Chapter::STATUS_PUBLISHED => "Chapter {$chapter->displayNumber()} published to {$series->title}.",
            Chapter::STATUS_SCHEDULED => "Chapter {$chapter->displayNumber()} scheduled for {$chapter->published_at->timezone(config('app.timezone'))->format('M j, Y g:i A')}.",
            default => "Chapter {$chapter->displayNumber()} saved as a draft.",
        });

        $this->redirect(route('admin.series.edit', $series), navigate: true);
    }

    protected function addFailedFile(TemporaryUploadedFile $file, string $reason, int $sequence): void
    {
        $this->failedFiles[] = [
            'id' => $this->nextFailureId++,
            'name' => $file->getClientOriginalName(),
            'reason' => $reason,
            'sequence' => $sequence,
        ];
    }

    protected function failedFileIndex(int $failureId): ?int
    {
        foreach ($this->failedFiles as $index => $failure) {
            if ($failure['id'] === $failureId) {
                return $index;
            }
        }

        return null;
    }

    protected function sortPagesBySequence(): void
    {
        $entries = [];

        foreach ($this->pages as $index => $page) {
            $entries[] = [
                'page' => $page,
                'sequence' => $this->pageSequences[$index] ?? $this->nextPageSequence++,
            ];
        }

        usort($entries, fn (array $left, array $right) => $left['sequence'] <=> $right['sequence']);

        $this->pages = array_column($entries, 'page');
        $this->pageSequences = array_column($entries, 'sequence');
    }

    protected function dispatchSessionState(): void
    {
        $this->dispatch(
            'upload-session-updated',
            readyCount: count($this->pages),
            failedCount: count($this->failedFiles),
        );
    }

    protected function discardTemporary(mixed $file): void
    {
        if (! $file instanceof TemporaryUploadedFile) {
            return;
        }

        try {
            $file->delete();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return array<int, string>
     */
    protected function pageRules(): array
    {
        return app(PageUploadRules::class)->get();
    }

    public function render()
    {
        return view('livewire.admin.uploader', [
            'seriesList' => Series::orderBy('title')->get(['id', 'title']),
            'imageCapabilities' => app(ImageProcessor::class)->capabilities(),
        ]);
    }
}
