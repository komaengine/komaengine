<?php

// Runs the single panel cron command, `php artisan schedule:run`, exactly as a
// hosting panel would, and waits for it to publish a due chapter and for the
// queue worker it starts in the background to process a queued job and exit.

use App\Engine\Health\Heartbeats;
use App\Models\Chapter;
use App\Models\Series;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;

$root = getenv('KOMA_APP_ROOT') ?: dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (config('koma.queue.via_scheduler') !== true) {
    throw new RuntimeException('The package .env must turn KOMA_QUEUE_VIA_SCHEDULER on.');
}

$worker = collect(app(Schedule::class)->events())
    ->first(fn (Event $event) => str_contains((string) $event->command, 'queue:work'));

if ($worker === null || ! $worker->withoutOverlapping || ! $worker->runInBackground) {
    throw new RuntimeException('The schedule has no background queue worker with an overlap lock.');
}

$series = Series::query()->create([
    'title' => 'One Cron Probe',
    'slug' => 'one-cron-probe',
    'author' => 'KomaEngine',
    'status' => 'ongoing',
    'reading_direction' => 'vertical',
    'content_rating' => 'safe',
    'is_public' => true,
]);
$chapter = Chapter::query()->create([
    'series_id' => $series->id,
    'number' => 1,
    'title' => 'Published by the one cron job',
    'status' => Chapter::STATUS_SCHEDULED,
    'published_at' => now()->subMinute(),
]);

$probe = storage_path('app/private/one-cron/queue-probe.txt');
File::delete($probe);
dispatch(function () use ($probe) {
    File::ensureDirectoryExists(dirname($probe));
    File::put($probe, 'queue-ok');
})->onConnection('database')->onQueue('archive-imports');

exec('php artisan schedule:run --no-interaction 2>&1', $output, $exitCode);

if ($exitCode !== 0) {
    throw new RuntimeException("schedule:run failed:\n".implode("\n", $output));
}

if ($chapter->fresh()?->status !== Chapter::STATUS_PUBLISHED) {
    throw new RuntimeException('schedule:run did not publish the due chapter.');
}

// The worker runs on after schedule:run returns. Wait for it to process the
// job and exit, which releases its overlap lock.
$deadline = microtime(true) + 60;

while (microtime(true) < $deadline) {
    if (is_file($probe) && ! $worker->mutex->exists($worker)) {
        break;
    }

    usleep(250_000);
}

if (! is_file($probe) || File::get($probe) !== 'queue-ok') {
    throw new RuntimeException('The worker started by schedule:run did not process the queued job.');
}

if ($worker->mutex->exists($worker)) {
    throw new RuntimeException('The worker started by schedule:run did not exit and release its lock.');
}

$heartbeats = app(Heartbeats::class);

if ($heartbeats->lastSchedulerRun() === null || ($heartbeats->lastProcessedJob()['queue'] ?? null) !== 'archive-imports') {
    throw new RuntimeException('The scheduler and queue heartbeats were not recorded.');
}

echo "one-cron-ok\n";
