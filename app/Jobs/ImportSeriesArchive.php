<?php

namespace App\Jobs;

use App\Archives\ArchiveException;
use App\Archives\ImportedArchive;
use App\Archives\SeriesArchiveImporter;
use App\Models\Series;
use App\Models\SeriesArchiveImport;
use App\Support\Facades\Hook;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportSeriesArchive implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    /**
     * Imports this process is inside right now. The queue's timeout handler
     * calls failed() in the same process, possibly inside the import's still
     * open transaction, where an uncommitted result link is already visible.
     *
     * @var array<string, true>
     */
    private static array $importing = [];

    public function __construct(public string $importId)
    {
        $this->onQueue('archive-imports');
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('archive-import:'.$this->importId))
                ->dontRelease()
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(SeriesArchiveImporter $importer): void
    {
        $record = SeriesArchiveImport::query()->with('user')->find($this->importId);
        $result = null;

        if ($record === null || $record->isTerminal()) {
            return;
        }

        if ($this->reconcile($record)) {
            return;
        }

        try {
            if ($record->user === null) {
                throw new ArchiveException('The importing user no longer exists.');
            }

            Gate::forUser($record->user)->authorize('edit_series');

            if (blank($record->source_path) || ! Storage::disk('local')->exists($record->source_path)) {
                throw new ArchiveException('The retained source archive is missing.');
            }

            $record->forceFill([
                'status' => SeriesArchiveImport::STATUS_VALIDATING,
                'progress' => 1,
                'stage' => 'Validating',
                'message' => 'Opening the retained source archive.',
                'error' => null,
                'started_at' => now(),
            ])->save();

            self::$importing[$this->importId] = true;
            $result = $importer->import(
                Storage::disk('local')->path($record->source_path),
                $record->conflict_policy,
                $record->replacement_series_id,
                $record->replace_confirmed,
                function (int $percent, string $stage, string $message) use ($record): void {
                    $record->forceFill([
                        'status' => $stage === 'Importing'
                            ? SeriesArchiveImport::STATUS_IMPORTING
                            : SeriesArchiveImport::STATUS_VALIDATING,
                        'progress' => min(99, $percent),
                        'stage' => $stage,
                        'message' => $message,
                    ])->save();
                },
                function (Series $series, array $counts) use ($record): void {
                    // Through the query builder so a rolled-back transaction
                    // cannot leave the in-memory record claiming a result.
                    SeriesArchiveImport::query()->whereKey($record->getKey())->update([
                        'result_series_id' => $series->getKey(),
                        'counts' => json_encode($counts, JSON_THROW_ON_ERROR),
                    ]);
                },
            );

            unset(self::$importing[$this->importId]);
            $this->complete($record, $result->series, $result->counts);
        } catch (Throwable $e) {
            unset(self::$importing[$this->importId]);

            if ($result instanceof ImportedArchive) {
                report($e);

                try {
                    $record->forceFill([
                        'status' => SeriesArchiveImport::STATUS_COMPLETED,
                        'result_series_id' => $result->series->id,
                        'progress' => 100,
                        'stage' => 'Completed',
                        'message' => "Imported {$result->series->title}; source-file cleanup needs operator attention.",
                        'error' => null,
                        'counts' => $result->counts,
                        'completed_at' => now(),
                    ])->save();
                    $this->announce($record);
                } catch (Throwable $statusError) {
                    report($statusError);
                }

                return;
            }

            if (! $e instanceof ArchiveException) {
                report($e);
            }

            // The importer can still throw after its transaction committed.
            // Saying "rolled back" then would invite a second, duplicate run.
            try {
                if ($this->reconcile($record)) {
                    return;
                }
            } catch (Throwable $reconcileError) {
                report($reconcileError);
            }

            $record->forceFill([
                'status' => SeriesArchiveImport::STATUS_FAILED,
                'stage' => 'Failed safely',
                'message' => 'Database changes were rolled back. Any failed media cleanup is logged for operator review.',
                'error' => $e instanceof ArchiveException
                    ? $e->getMessage()
                    : 'The import failed unexpectedly and was rolled back safely.',
                'completed_at' => now(),
            ])->save();

            $this->announce($record);
        }
    }

    public function failed(?Throwable $exception): void
    {
        try {
            $record = SeriesArchiveImport::query()->find($this->importId);

            if ($record !== null && ! $record->isTerminal()
                && ! isset(self::$importing[$this->importId]) && $this->reconcile($record)) {
                return;
            }
        } catch (Throwable $reconcileError) {
            report($reconcileError);
        }

        $marked = SeriesArchiveImport::query()
            ->whereKey($this->importId)
            ->whereNotIn('status', [
                SeriesArchiveImport::STATUS_COMPLETED,
                SeriesArchiveImport::STATUS_FAILED,
            ])
            ->update([
                'status' => SeriesArchiveImport::STATUS_FAILED,
                'stage' => 'Worker stopped',
                'message' => 'The queue worker stopped before the import completed. The retained source can be retried.',
                'error' => 'The retained source archive can be retried after the worker issue is resolved.',
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        // handle() announces its own terminal states. Only announce here when
        // this call is what actually ended the import.
        if ($marked > 0) {
            $record = SeriesArchiveImport::query()->find($this->importId);

            if ($record !== null) {
                $this->announce($record);
            }
        }
    }

    /**
     * Tell anyone listening that this import reached a terminal state.
     *
     * Imports run on the queue with nothing on screen once the admin navigates
     * away, so this is the only signal an operator gets. It must never turn a
     * finished import back into a failed one.
     */
    protected function announce(SeriesArchiveImport $record): void
    {
        try {
            Hook::doAction('archive.import.finished', $record);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Finish an import whose content is already committed.
     *
     * The importer links the record to its series inside the import
     * transaction, so a link proves an earlier attempt committed the content
     * and stopped before it could say so. Importing again would create a
     * second series, fail on the slug it just took, or replace the result with
     * itself, so the committed result is adopted instead.
     */
    protected function reconcile(SeriesArchiveImport $record): bool
    {
        $linked = SeriesArchiveImport::query()
            ->whereKey($record->getKey())
            ->first(['id', 'result_series_id', 'counts']);
        $series = $linked?->result_series_id === null
            ? null
            : Series::query()->find($linked->result_series_id);

        if ($series === null) {
            return false;
        }

        $this->complete($record, $series, $linked->counts ?? []);

        return true;
    }

    /**
     * @param  array<string, int>  $counts
     */
    protected function complete(SeriesArchiveImport $record, Series $series, array $counts): void
    {
        $sourcePath = $record->source_path;

        $record->forceFill([
            'status' => SeriesArchiveImport::STATUS_COMPLETED,
            'result_series_id' => $series->id,
            'progress' => 100,
            'stage' => 'Completed',
            'message' => "Imported {$series->title} successfully.",
            'error' => null,
            'counts' => $counts,
            'completed_at' => now(),
        ])->save();

        $this->announce($record);

        try {
            if (filled($sourcePath)) {
                if (Storage::disk('local')->delete($sourcePath)) {
                    $record->forceFill(['source_path' => null])->save();
                } else {
                    $record->forceFill([
                        'message' => "Imported {$series->title}; the private source archive still needs cleanup.",
                    ])->save();
                }
            }
        } catch (Throwable $cleanupError) {
            report($cleanupError);

            try {
                $record->forceFill([
                    'message' => "Imported {$series->title}; the private source archive still needs cleanup.",
                ])->save();
            } catch (Throwable $statusError) {
                report($statusError);
            }
        }
    }
}
