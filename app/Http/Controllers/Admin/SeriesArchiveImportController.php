<?php

namespace App\Http\Controllers\Admin;

use App\Archives\ArchiveImportBatchOptions;
use App\Archives\ArchiveImportPolicy;
use App\Archives\ArchiveUploadLimits;
use App\Http\Controllers\Controller;
use App\Jobs\ImportSeriesArchive;
use App\Models\SeriesArchiveImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class SeriesArchiveImportController extends Controller
{
    public function __invoke(Request $request, ArchiveUploadLimits $limits): RedirectResponse
    {
        Gate::authorize('edit_series');

        $batch = app(ArchiveImportBatchOptions::class)->details();
        $policy = (string) $request->input('conflict_policy', ArchiveImportPolicy::FAIL);
        $uploadLimits = $limits->details();
        $maximumArchiveKb = (int) config('koma.archives.imports.max_archive_kb', 512000);
        $uploadMessage = "The server did not finish receiving this file. Its current effective PHP/Koma limit is {$uploadLimits['effective_label']} (PHP upload {$uploadLimits['php_upload_label']}; post {$uploadLimits['php_post_label']}). Check any web-server or proxy body-size limit too.";

        $validated = $batch['enabled']
            ? $request->validate([
                'archives' => ['required', 'array', 'min:1', 'max:'.$batch['max_files']],
                'archives.*' => ['required', 'file', 'max:'.$maximumArchiveKb],
                'conflict_policy' => ['required', Rule::in([
                    ArchiveImportPolicy::FAIL,
                    ArchiveImportPolicy::CREATE_UNIQUE,
                ])],
            ], [
                'archives.required' => 'Choose one or more Koma or supported gallery ZIP archives.',
                'archives.array' => 'Choose one or more readable archive files.',
                'archives.max' => "Choose no more than {$batch['max_files']} archives in one batch.",
                'archives.*.uploaded' => $uploadMessage,
                'archives.*.file' => 'One selected archive was not received as a readable file.',
                'archives.*.max' => "Each archive must be no larger than Koma's configured {$uploadLimits['application_label']} limit.",
            ])
            : $request->validate([
                'archive' => ['required', 'file', 'max:'.$maximumArchiveKb],
                'conflict_policy' => ['required', Rule::in(ArchiveImportPolicy::VALUES)],
                'replacement_series_id' => [
                    Rule::requiredIf($policy === ArchiveImportPolicy::REPLACE),
                    'nullable',
                    'integer',
                    'exists:series,id',
                ],
                'replace_confirmed' => [
                    Rule::requiredIf($policy === ArchiveImportPolicy::REPLACE),
                    $policy === ArchiveImportPolicy::REPLACE ? 'accepted' : 'nullable',
                ],
            ], [
                'archive.required' => 'Choose a Koma or supported gallery ZIP archive.',
                'archive.uploaded' => $uploadMessage,
                'archive.file' => 'The selected archive was not received as a readable file.',
                'archive.max' => "The archive exceeds Koma's configured {$uploadLimits['application_label']} limit.",
                'replacement_series_id.required' => 'Select the exact series to replace.',
                'replace_confirmed.accepted' => 'Confirm that the selected series will be replaced, not merged.',
            ]);

        $archives = $batch['enabled'] ? $validated['archives'] : [$validated['archive']];

        if (collect($archives)->contains(fn (mixed $archive): bool => ! $archive instanceof UploadedFile)) {
            return back()->withErrors($batch['enabled']
                ? ['archives' => 'Choose readable Koma or supported gallery ZIP archives.']
                : ['archive' => 'Choose a readable Koma or supported gallery ZIP archive.'])->withInput();
        }

        /** @var list<UploadedFile> $archives */
        $records = [];
        $sourcePaths = [];
        $pending = [];

        try {
            // Retain every file before recording any of them, then record them
            // together. A request that dies while copying uploads leaves no
            // import that is queued without a job.
            foreach ($archives as $archive) {
                $id = (string) Str::uuid();
                $sourcePath = 'archive-imports/source/'.$id.'.koma.zip';
                // Tracked before the write so a partial file is cleaned up too.
                $sourcePaths[] = $sourcePath;

                if ($archive->storeAs('archive-imports/source', $id.'.koma.zip', 'local') !== $sourcePath
                    || ! Storage::disk('local')->exists($sourcePath)) {
                    throw new \RuntimeException('An uploaded archive could not be retained for the queue worker.');
                }

                $pending[] = [
                    'id' => $id,
                    'user_id' => $request->user()->getAuthIdentifier(),
                    'source_name' => Str::limit($archive->getClientOriginalName(), 255, ''),
                    'source_path' => $sourcePath,
                    'status' => SeriesArchiveImport::STATUS_QUEUED,
                    'conflict_policy' => $validated['conflict_policy'],
                    'replacement_series_id' => $batch['enabled'] ? null : ($validated['replacement_series_id'] ?? null),
                    'replace_confirmed' => $batch['enabled'] ? false : (bool) ($validated['replace_confirmed'] ?? false),
                    'progress' => 0,
                    'stage' => 'Queued',
                    'message' => 'Waiting for an archive-import worker.',
                ];
            }

            $records = DB::transaction(fn (): array => array_map(
                fn (array $attributes): SeriesArchiveImport => SeriesArchiveImport::create($attributes),
                $pending,
            ));
        } catch (Throwable $e) {
            foreach ($sourcePaths as $sourcePath) {
                try {
                    Storage::disk('local')->delete($sourcePath);
                } catch (Throwable $cleanupError) {
                    report($cleanupError);
                }
            }

            report($e);

            return back()
                ->withErrors($batch['enabled']
                    ? ['archives' => 'The archive batch could not be queued. No import was started.']
                    : ['archive' => 'The archive could not be queued. No import was started.'])
                ->withInput();
        }

        $queueFailures = 0;

        foreach ($records as $record) {
            try {
                ImportSeriesArchive::dispatch($record->id);
            } catch (Throwable $e) {
                report($e);
                $queueFailures++;

                try {
                    $record->markQueueUnavailable();
                } catch (Throwable $statusError) {
                    report($statusError);
                }
            }
        }

        $total = count($records);
        $queued = $total - $queueFailures;

        if ($queueFailures === 0) {
            $status = $total === 1
                ? 'Archive retained and queued for validation.'
                : "{$queued} archives retained and queued for validation.";
        } elseif ($total === 1) {
            $status = 'Archive retained, but Koma could not queue it. Use Retry import after the queue issue is resolved.';
        } else {
            $status = "{$queued} of {$total} archives queued for validation. {$queueFailures} retained "
                .Str::plural('archive', $queueFailures)
                .' could not be queued; use Retry import on each after the queue issue is resolved.';
        }

        return redirect()
            ->route('admin.archives.import', ['import' => $records[array_key_last($records)]->id])
            ->with($queueFailures > 0 ? 'error' : 'status', $status);
    }
}
