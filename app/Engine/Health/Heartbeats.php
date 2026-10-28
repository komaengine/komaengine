<?php

namespace App\Engine\Health;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * When the scheduler last ran and when a queue worker last finished a job.
 * Each lives in its own cache key, not in Settings: Settings::setMany() clears
 * the whole settings cache, and a heartbeat is written every minute.
 */
final class Heartbeats
{
    public const SCHEDULER_KEY = 'koma:heartbeat:scheduler';

    public const QUEUE_KEY = 'koma:heartbeat:queue';

    public function __construct(private readonly Repository $cache) {}

    public function recordSchedulerRun(): void
    {
        $this->write(self::SCHEDULER_KEY, ['at' => now()->toIso8601String()]);
    }

    public function recordProcessedJob(string $queue): void
    {
        $this->write(self::QUEUE_KEY, ['at' => now()->toIso8601String(), 'queue' => $queue]);
    }

    public function lastSchedulerRun(): ?CarbonImmutable
    {
        return $this->read(self::SCHEDULER_KEY)['at'] ?? null;
    }

    /** @return array{at: CarbonImmutable, queue: string}|null */
    public function lastProcessedJob(): ?array
    {
        return $this->read(self::QUEUE_KEY);
    }

    /** @param  array<string, string>  $value */
    private function write(string $key, array $value): void
    {
        // A heartbeat must never fail the work it reports on: an exception
        // from a JobProcessed listener would mark a finished job as failed.
        try {
            $this->cache->forever($key, $value);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** @return array<string, mixed>|null */
    private function read(string $key): ?array
    {
        $value = $this->cache->get($key);

        if (! is_array($value) || ! is_string($value['at'] ?? null)) {
            return null;
        }

        try {
            return ['at' => CarbonImmutable::parse($value['at'])] + $value;
        } catch (Throwable) {
            return null;
        }
    }
}
