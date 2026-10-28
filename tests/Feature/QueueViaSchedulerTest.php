<?php

use App\Engine\Health\Heartbeats;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;

uses(RefreshDatabase::class);

/** Load routes/console.php into a fresh schedule with the option set. */
function scheduleWithQueueViaScheduler(bool $enabled): Schedule
{
    config(['koma.queue.via_scheduler' => $enabled]);
    ScheduleFacade::swap($schedule = new Schedule(config('app.schedule_timezone', config('app.timezone'))));
    require base_path('routes/console.php');

    return $schedule;
}

function scheduledQueueWorker(Schedule $schedule): ?Event
{
    return collect($schedule->events())
        ->first(fn (Event $event) => str_contains((string) $event->command, 'queue:work'));
}

test('with the option on, the schedule runs a queue worker in the background without overlap', function () {
    $worker = scheduledQueueWorker(scheduleWithQueueViaScheduler(true));

    expect($worker)->not->toBeNull()
        ->and($worker->expression)->toBe('* * * * *')
        ->and($worker->withoutOverlapping)->toBeTrue()
        ->and($worker->expiresAt)->toBe(70)
        ->and($worker->runInBackground)->toBeTrue()
        ->and($worker->command)->toContain(' -d memory_limit=512M ')
        ->and($worker->command)->toEndWith(
            'queue:work database --queue=archive-imports,default --tries=1 --timeout=3600 --sleep=3 --stop-when-empty',
        );

    Artisan::call('schedule:list');

    expect(Artisan::output())
        ->toContain('koma:publish-scheduled')
        ->toContain('queue:work database --queue=archive-imports,default');
});

test('with the option off, the schedule has no queue worker', function () {
    expect(scheduledQueueWorker(scheduleWithQueueViaScheduler(false)))->toBeNull();

    Artisan::call('schedule:list');

    expect(Artisan::output())
        ->toContain('koma:publish-scheduled')
        ->not->toContain('queue:work');
});

test('the option is off unless the environment turns it on', function () {
    expect(config('koma.queue.via_scheduler'))->toBeFalse()
        ->and(File::get(base_path('.env.flatfile.example')))->toContain("\nKOMA_QUEUE_VIA_SCHEDULER=true\n")
        ->and(File::get(base_path('docker-compose.yml')))->toContain("\n  KOMA_QUEUE_VIA_SCHEDULER: \"false\"\n");
});

test('each scheduler run records a heartbeat', function () {
    $this->travelTo('2026-10-27 12:00:00');
    scheduleWithQueueViaScheduler(false);

    expect(app(Heartbeats::class)->lastSchedulerRun())->toBeNull();

    Artisan::call('schedule:test', ['--name' => 'koma:scheduler-heartbeat']);

    expect(app(Heartbeats::class)->lastSchedulerRun()?->toIso8601String())->toBe('2026-10-27T12:00:00+00:00');
});

test('a processed queue job records a heartbeat with its queue', function () {
    $this->travelTo('2026-10-27 12:05:00');
    config(['queue.default' => 'database']);

    expect(app(Heartbeats::class)->lastProcessedJob())->toBeNull();

    dispatch(function () {})->onQueue('archive-imports');
    Artisan::call('queue:work', [
        'connection' => 'database',
        '--once' => true,
        '--queue' => 'archive-imports,default',
        '--sleep' => 0,
        '--tries' => 1,
    ]);

    $job = app(Heartbeats::class)->lastProcessedJob();

    expect($job['queue'])->toBe('archive-imports')
        ->and($job['at']->toIso8601String())->toBe('2026-10-27T12:05:00+00:00');
});

test('a heartbeat that cannot be written is reported, never thrown', function () {
    Exceptions::fake();
    $cache = Mockery::mock(Repository::class);
    $cache->shouldReceive('forever')->twice()->andThrow(new RuntimeException('cache is read-only'));

    $heartbeats = new Heartbeats($cache);
    $heartbeats->recordSchedulerRun();
    $heartbeats->recordProcessedJob('default');

    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'cache is read-only');
});
