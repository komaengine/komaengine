<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeriesArchiveImport extends Model
{
    use HasUuids;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_VALIDATING = 'validating';

    public const STATUS_IMPORTING = 'importing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'id', 'user_id', 'source_name', 'source_path', 'status', 'conflict_policy',
        'replacement_series_id', 'replace_confirmed', 'result_series_id',
        'progress', 'stage', 'message', 'error', 'counts', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'replacement_series_id' => 'integer',
            'replace_confirmed' => 'boolean',
            'result_series_id' => 'integer',
            'progress' => 'integer',
            'counts' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resultSeries(): BelongsTo
    {
        return $this->belongsTo(Series::class, 'result_series_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    /**
     * Record that no job could be dispatched for this import. The retained
     * source is untouched, so the import stays retryable instead of sitting
     * in the queued state with nothing behind it.
     */
    public function markQueueUnavailable(): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'stage' => 'Queue unavailable',
            'message' => 'The source archive was retained and can be retried after the queue issue is resolved.',
            'error' => 'Koma could not dispatch this archive to an import worker.',
            'completed_at' => now(),
        ])->save();
    }
}
