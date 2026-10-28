<?php

use App\Engine\Health\Heartbeats;
use App\Engine\Updates\UpdateCheck;
use Illuminate\Console\Application;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('koma:publish-scheduled')
    ->everyMinute()
    ->withoutOverlapping(5);

// The optional update notice: does nothing until an administrator turns it
// on, then fetches the release feed at most once a day.
Schedule::call(fn (UpdateCheck $updates) => $updates->runIfDue())
    ->hourly()
    ->name('koma:update-check');

// Record when schedule:run last ran, for the health page.
Schedule::call(fn (Heartbeats $heartbeats) => $heartbeats->recordSchedulerRun())
    ->everyMinute()
    ->name('koma:scheduler-heartbeat');

// With KOMA_QUEUE_VIA_SCHEDULER, one cron job is enough on a hosting panel:
// schedule:run also starts a background worker that drains both queues and
// exits. The lock outlives the longest import (3,600 seconds), so a running
// import never gets a second worker; schedule:finish releases it when the
// worker exits. The memory limit is set here because a panel's PHP settings
// apply to the website, not to cron jobs.
if (config('koma.queue.via_scheduler')) {
    Schedule::exec(Application::phpBinary().' -d memory_limit=512M '.Application::artisanBinary()
        .' queue:work database --queue=archive-imports,default --tries=1 --timeout=3600 --sleep=3 --stop-when-empty')
        ->everyMinute()
        ->withoutOverlapping(70)
        ->runInBackground();
}
