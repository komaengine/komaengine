<?php

use App\Models\Chapter;
use App\Models\Series;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

$root = getenv('KOMA_APP_ROOT') ?: dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

class RecoveryQueueProbe implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        File::put(storage_path('app/private/recovery/queue-probe.txt'), 'queue-ok');
    }
}

$action = getenv('RECOVERY_RUNTIME_ACTION');

if ($action === 'queue') {
    RecoveryQueueProbe::dispatch();
    $status = Artisan::call('queue:work', [
        'connection' => 'database',
        '--once' => true,
        '--queue' => 'default',
        '--sleep' => 0,
        '--tries' => 1,
        '--timeout' => 10,
        '--no-interaction' => true,
    ]);

    if ($status !== 0 || File::get(storage_path('app/private/recovery/queue-probe.txt')) !== 'queue-ok') {
        throw new RuntimeException('The restored database queue did not execute its probe job.');
    }

    echo "queue-ok\n";

    exit(0);
}

if ($action === 'scheduler') {
    $series = Series::query()->create([
        'title' => 'Recovery Scheduler Probe',
        'slug' => 'recovery-scheduler-probe',
        'author' => 'KomaEngine',
        'status' => 'ongoing',
        'reading_direction' => 'vertical',
        'content_rating' => 'safe',
        'is_public' => true,
    ]);
    $chapter = Chapter::query()->create([
        'series_id' => $series->id,
        'number' => 1,
        'title' => 'Published by the recovered scheduler',
        'status' => Chapter::STATUS_SCHEDULED,
        'published_at' => now()->subMinute(),
    ]);
    $status = Artisan::call('schedule:run', ['--no-interaction' => true]);

    if ($status !== 0 || $chapter->fresh()?->status !== Chapter::STATUS_PUBLISHED) {
        throw new RuntimeException('The restored scheduler did not publish its due chapter.');
    }

    echo "scheduler-ok\n";

    exit(0);
}

throw new RuntimeException('RECOVERY_RUNTIME_ACTION must be queue or scheduler.');
