<?php

namespace App\Livewire\Admin;

use App\Archives\ArchiveImportBatchOptions;
use App\Archives\ArchiveUploadLimits;
use App\Jobs\ImportSeriesArchive;
use App\Models\Series;
use App\Models\SeriesArchiveImport;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

#[Layout('components.layouts.admin')]
class ArchiveImporter extends Component
{
    public ?string $activeImportId = null;

    public ?string $intakeError = null;

    public function mount(): void
    {
        Gate::authorize('edit_series');
        $limits = app(ArchiveUploadLimits::class)->details();

        if (request('upload_error') === 'post_too_large') {
            $this->intakeError = "The web request was rejected before Koma could queue the archive. This PHP process accepts {$limits['php_upload_label']} per file and {$limits['php_post_label']} per request; also check the web-server or reverse-proxy body-size limit.";
        }

        $requestedId = request('import');
        $query = SeriesArchiveImport::query()
            ->where('user_id', auth()->id())
            ->when(is_string($requestedId), fn ($builder) => $builder->whereKey($requestedId));

        $this->activeImportId = $query->latest()->value('id')
            ?? SeriesArchiveImport::query()
                ->where('user_id', auth()->id())
                ->latest()
                ->value('id');
    }

    public function retryImport(string $importId): void
    {
        Gate::authorize('edit_series');

        $record = SeriesArchiveImport::query()
            ->where('user_id', auth()->id())
            ->whereKey($importId)
            ->firstOrFail();

        abort_unless($record->status === SeriesArchiveImport::STATUS_FAILED, 409);
        abort_unless(filled($record->source_path) && Storage::disk('local')->exists($record->source_path), 409);

        $record->forceFill([
            'status' => SeriesArchiveImport::STATUS_QUEUED,
            'progress' => 0,
            'stage' => 'Queued',
            'message' => 'Retry queued for an archive-import worker.',
            'error' => null,
            'started_at' => null,
            'completed_at' => null,
        ])->save();

        $this->activeImportId = $record->id;

        try {
            ImportSeriesArchive::dispatch($record->id);
        } catch (Throwable $exception) {
            // The record already says queued. Without a job behind it, it
            // could be neither retried nor discarded, so put it back.
            report($exception);
            $record->markQueueUnavailable();
            $this->addError('archive', 'The retry could not be queued. The source archive is still retained; try again after the queue issue is resolved.');

            return;
        }

        session()->flash('status', 'Archive import queued again.');
    }

    public function discardSource(string $importId): void
    {
        Gate::authorize('edit_series');

        $record = SeriesArchiveImport::query()
            ->where('user_id', auth()->id())
            ->whereKey($importId)
            ->firstOrFail();

        abort_unless($record->isTerminal(), 409);

        if (filled($record->source_path)
            && Storage::disk('local')->exists($record->source_path)
            && ! Storage::disk('local')->delete($record->source_path)) {
            $this->addError('archive', 'The retained source could not be deleted. Check storage permissions and retry.');

            return;
        }

        $record->forceFill([
            'source_path' => null,
            'message' => 'The retained source archive was discarded by its owner.',
        ])->save();

        session()->flash('status', 'Retained archive source discarded.');
    }

    public function render()
    {
        $batchOptions = app(ArchiveImportBatchOptions::class)->details();

        $activeImport = $this->activeImportId
            ? SeriesArchiveImport::query()
                ->where('user_id', auth()->id())
                ->with('resultSeries')
                ->find($this->activeImportId)
            : null;

        return view('livewire.admin.archive-importer', [
            'activeImport' => $activeImport,
            'seriesList' => Series::query()->orderBy('title')->get(['id', 'title', 'slug']),
            'recentImports' => SeriesArchiveImport::query()
                ->where('user_id', auth()->id())
                ->with('resultSeries')
                ->latest()
                ->limit(max(8, $batchOptions['max_files']))
                ->get(),
            'failureGuidance' => $this->failureGuidance($activeImport),
            'uploadLimits' => app(ArchiveUploadLimits::class)->details(),
            'batchOptions' => $batchOptions,
        ]);
    }

    private function failureGuidance(?SeriesArchiveImport $import): ?string
    {
        if ($import === null || blank($import->error)) {
            return null;
        }

        $error = Str::lower($import->error);

        return match (true) {
            str_contains($error, 'slug') => 'Choose “Create unique,” or select and explicitly confirm the series that should be replaced.',
            str_contains($error, 'worker'), str_contains($error, 'queue') => 'Start an archive worker, then retry the retained source.',
            str_contains($error, 'size'), str_contains($error, 'limit'), str_contains($error, 'too large') => 'Reduce the archive size or raise the matching Koma, PHP, and web-server request limit before retrying.',
            str_contains($error, 'checksum'), str_contains($error, 'mime'), str_contains($error, 'image'), str_contains($error, 'dimension') => 'At least one image is corrupt or mislabeled. Rebuild that page image and recreate the ZIP.',
            str_contains($error, 'manifest'), str_contains($error, 'metadata'), str_contains($error, 'schema') => 'Correct or remap the manifest metadata, then recreate the archive. Native archives must match strict v1 or v2.',
            str_contains($error, 'zip'), str_contains($error, 'entry'), str_contains($error, 'compression') => 'Recreate the ZIP without extra, unsafe, encrypted, or excessively compressed entries.',
            default => "Retry once. If it fails again, search the application and queue logs for import {$import->id}.",
        };
    }
}
