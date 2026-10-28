<?php

namespace App\Livewire\Admin;

use App\Engine\Health\CronCommand;
use App\Engine\Health\Heartbeats;
use App\Engine\Health\ReadinessChecks;
use App\Engine\Updates\UpdateCheck;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * System → Health: the doctor's checks, background work, PHP limits, disk
 * space, the release identity, and the update check. Read-only; "Check
 * again" only re-renders.
 */
#[Layout('components.layouts.admin')]
class SystemHealth extends Component
{
    /** Minutes without a scheduler run, or with a job left waiting, before a warning. */
    public const STALE_AFTER_MINUTES = 5;

    /** Free space under storage/ below which the page warns. */
    public const LOW_DISK_BYTES = 1024 ** 3;

    /** @var array<string, array{label: string, fix: string, anchor: string}> */
    private const CHECKS = [
        'app_key' => [
            'label' => 'Application key',
            'fix' => 'APP_KEY in .env is missing or invalid. Run php artisan koma:setup, which adds a key only when there is none. Never replace the key of a running site.',
            'anchor' => 'application-key',
        ],
        'media_driver' => [
            'label' => 'Manga storage',
            'fix' => 'Community Edition keeps manga pages on the local disk. Set MEDIA_DISK_DRIVER=local in .env.',
            'anchor' => 'permissions-and-storage-link',
        ],
        'database' => [
            'label' => 'Database connection',
            'fix' => 'The database cannot be reached. Check the DB_ settings in .env against your hosting panel.',
            'anchor' => 'database-or-migrations',
        ],
        'migrations' => [
            'label' => 'Database tables',
            'fix' => 'Some database updates have not run. Run php artisan migrate --force once.',
            'anchor' => 'database-or-migrations',
        ],
        'runtime_paths' => [
            'label' => 'Writable folders',
            'fix' => 'The PHP user must be able to create and delete files in storage/ and bootstrap/cache/. Fix their ownership; do not use chmod 777.',
            'anchor' => 'permissions-and-storage-link',
        ],
        'cache' => [
            'label' => 'Cache',
            'fix' => 'The cache cannot store values. Check CACHE_STORE in .env and that storage/framework/cache is writable.',
            'anchor' => 'permissions-and-storage-link',
        ],
        'storage_link' => [
            'label' => 'Public storage link',
            'fix' => 'public/storage must be a link to storage/app/public. Run php artisan storage:link.',
            'anchor' => 'permissions-and-storage-link',
        ],
        'legacy_public_media_absent' => [
            'label' => 'Manga files kept private',
            'fix' => 'Manga files were found in public storage. Run php artisan koma:media:protect to move them into protected storage.',
            'anchor' => 'permissions-and-storage-link',
        ],
        'installed' => [
            'label' => 'Installation finished',
            'fix' => 'The web installer has not finished. Complete it at /install.',
            'anchor' => 'installer-setup-token',
        ],
    ];

    /** @var array<string, array{recommended: string, minimum: int, unit: 'bytes'|'seconds'}> */
    private const PHP_LIMITS = [
        'memory_limit' => ['recommended' => '512M', 'minimum' => 512 * 1024 * 1024, 'unit' => 'bytes'],
        'upload_max_filesize' => ['recommended' => '512M', 'minimum' => 512 * 1024 * 1024, 'unit' => 'bytes'],
        'post_max_size' => ['recommended' => '520M', 'minimum' => 520 * 1024 * 1024, 'unit' => 'bytes'],
        'max_execution_time' => ['recommended' => '120', 'minimum' => 120, 'unit' => 'seconds'],
    ];

    public function render(): View
    {
        $readiness = app(ReadinessChecks::class)->run('operational');
        $checks = collect($readiness['checks'])
            ->map(fn (bool $ok, string $name) => ['name' => $name, 'ok' => $ok] + (self::CHECKS[$name] ?? [
                'label' => $name, 'fix' => '', 'anchor' => '',
            ]))
            ->values()
            ->all();

        $background = $this->background();
        $php = $this->phpLimits();
        $disk = $this->disk();

        $problems = count(array_filter($checks, fn (array $check) => ! $check['ok']))
            + (int) $background['scheduler_stale']
            + (int) $background['queue_stale']
            + count(array_filter($php, fn (array $limit) => ! $limit['ok']))
            + (int) ($disk['low'] ?? false);

        return view('livewire.admin.system-health', [
            'checks' => $checks,
            'background' => $background,
            'php' => $php,
            'phpVersion' => PHP_VERSION,
            'disk' => $disk,
            'release' => [
                'version' => (string) config('koma.version'),
                'commit' => (string) config('koma.release.commit'),
                'build_id' => (string) config('koma.release.build_id'),
                'trusted' => filter_var(config('koma.release.trusted'), FILTER_VALIDATE_BOOL),
            ],
            'update' => app(UpdateCheck::class)->status(),
            'problems' => $problems,
            'docsUrl' => rtrim((string) config('koma.docs_url'), '/'),
            'staleMinutes' => self::STALE_AFTER_MINUTES,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function background(): array
    {
        $heartbeats = app(Heartbeats::class);
        $cutoff = CarbonImmutable::now()->subMinutes(self::STALE_AFTER_MINUTES);
        $scheduler = $heartbeats->lastSchedulerRun();
        $queue = $this->queueCounts();

        return [
            'scheduler_at' => $scheduler,
            'scheduler_stale' => $scheduler === null || $scheduler->lessThan($cutoff),
            'last_job' => $heartbeats->lastProcessedJob(),
            'queue' => $queue,
            // A running job keeps the next one waiting; that is not a stall.
            'queue_stale' => $queue !== null && $queue['running'] === 0
                && $queue['oldest_waiting'] !== null && $queue['oldest_waiting']->lessThan($cutoff),
            'worker' => match (true) {
                config('koma.runtime') === 'docker-compose' => 'docker',
                (bool) config('koma.queue.via_scheduler') => 'scheduler',
                default => 'separate',
            },
            'cron' => CronCommand::forThisSite(),
        ];
    }

    /**
     * @return array{waiting: int, running: int, oldest_waiting: ?CarbonImmutable, failed: int}|null
     */
    private function queueCounts(): ?array
    {
        if (config('queue.default') !== 'database') {
            return null;
        }

        try {
            $jobs = DB::connection(config('queue.connections.database.connection'));
            $table = (string) config('queue.connections.database.table', 'jobs');
            $failed = DB::connection(config('queue.failed.database'));
            $failedTable = (string) config('queue.failed.table', 'failed_jobs');
            $oldest = $jobs->table($table)
                ->whereNull('reserved_at')
                ->where('available_at', '<=', CarbonImmutable::now()->getTimestamp())
                ->min('available_at');

            return [
                'waiting' => $jobs->table($table)->whereNull('reserved_at')->count(),
                'running' => $jobs->table($table)->whereNotNull('reserved_at')->count(),
                'oldest_waiting' => $oldest === null ? null : CarbonImmutable::createFromTimestamp((int) $oldest),
                'failed' => $failed->getSchemaBuilder()->hasTable($failedTable) ? $failed->table($failedTable)->count() : 0,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<array{name: string, current: string, recommended: string, ok: bool}>
     */
    private function phpLimits(): array
    {
        $limits = [];

        foreach (self::PHP_LIMITS as $name => $limit) {
            $current = (string) ini_get($name);
            $value = $limit['unit'] === 'bytes' ? self::iniBytes($current) : (int) $current;
            // -1 (memory) and 0 (execution time) mean no limit.
            $unlimited = $limit['unit'] === 'bytes' ? $value === null : $value === 0;

            $limits[] = [
                'name' => $name,
                'current' => $current === '' ? 'not set' : $current,
                'recommended' => $limit['recommended'],
                'ok' => $unlimited || $value >= $limit['minimum'],
            ];
        }

        return $limits;
    }

    /** Bytes for a php.ini size such as 512M, or null for -1 (no limit). */
    private static function iniBytes(string $value): ?int
    {
        $value = trim($value);

        if ($value === '-1') {
            return null;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * @return array{free: int, total: int, low: bool}|null
     */
    private function disk(): ?array
    {
        // Hosts often disable these, and a disabled function does not exist.
        if (! function_exists('disk_free_space') || ! function_exists('disk_total_space')) {
            return null;
        }

        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());

        if ($free === false || $total === false) {
            return null;
        }

        return ['free' => (int) $free, 'total' => (int) $total, 'low' => $free < self::LOW_DISK_BYTES];
    }
}
