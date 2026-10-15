<?php

use App\Archives\KomaArchiveInspector;
use App\Engine\Plugins\PluginManager;
use App\Engine\Themes\ThemeManager;
use App\Models\Chapter;
use App\Models\Series;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

$root = getenv('KOMA_APP_ROOT') ?: dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

class UpgradeQueueProbe implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        File::ensureDirectoryExists(storage_path('app/private/upgrade-runtime'));
        File::put(storage_path('app/private/upgrade-runtime/queue-probe.txt'), 'queue-ok');
    }
}

$action = getenv('KOMA_UPGRADE_RUNTIME_ACTION');

if ($action === 'identity') {
    $user = User::query()->where('email', 'upgrade-owner@koma.test')->firstOrFail();
    $plugins = app(PluginManager::class);

    $state = [
        'password_verified' => Hash::check('upgrade-fixture-password', $user->password),
        'active_theme' => app(ThemeManager::class)->activeSlug(),
        'enabled_plugins' => $plugins->enabledSlugs(),
        'hello_koma_compatible' => (bool) ($plugins->all()['hello-koma']['compatible'] ?? false),
        'hello_koma_failure' => $plugins->failures()['hello-koma'] ?? null,
        'app_key_sha256' => hash('sha256', (string) config('app.key')),
    ];

    if (! $state['password_verified']
        || $state['active_theme'] !== 'aozora'
        || ! in_array('hello-koma', $state['enabled_plugins'], true)
        || ! $state['hello_koma_compatible']
        || $state['hello_koma_failure'] !== null) {
        throw new RuntimeException('Authentication or extension state did not survive the transition.');
    }

    echo json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

    exit(0);
}

if ($action === 'archives') {
    $inspector = app(KomaArchiveInspector::class);
    $archives = [
        'v1' => storage_path('app/private/upgrade/predecessor-v1.koma.zip'),
        'v2' => storage_path('app/private/upgrade/current-v2.koma.zip'),
    ];
    $state = [];

    foreach ($archives as $name => $path) {
        $validated = $inspector->inspect($path);

        try {
            $state[$name] = [
                'schema_version' => $validated->manifest['schema_version'] ?? null,
                'series_slug' => $validated->manifest['series']['slug'] ?? null,
                'bytes' => filesize($path),
                'sha256' => hash_file('sha256', $path),
            ];
        } finally {
            $validated->discard();
        }
    }

    if ($state['v1']['schema_version'] !== 1
        || $state['v2']['schema_version'] !== 2
        || $state['v1']['series_slug'] !== 'pre-release-upgrade-fixture'
        || $state['v2']['series_slug'] !== 'pre-release-upgrade-fixture') {
        throw new RuntimeException('Archive v1/v2 compatibility did not survive the upgrade.');
    }

    echo json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

    exit(0);
}

if ($action === 'queue') {
    UpgradeQueueProbe::dispatch();
    $status = Artisan::call('queue:work', [
        'connection' => 'database',
        '--once' => true,
        '--queue' => 'default',
        '--sleep' => 0,
        '--tries' => 1,
        '--timeout' => 10,
        '--no-interaction' => true,
    ]);

    if ($status !== 0 || File::get(storage_path('app/private/upgrade-runtime/queue-probe.txt')) !== 'queue-ok') {
        throw new RuntimeException('The transition database queue did not execute its probe job.');
    }

    echo "queue-ok\n";

    exit(0);
}

if ($action === 'scheduler') {
    $series = Series::query()->create([
        'title' => 'Upgrade Scheduler Probe',
        'slug' => 'upgrade-scheduler-probe',
        'author' => 'KomaEngine',
        'status' => 'ongoing',
        'reading_direction' => 'vertical',
        'content_rating' => 'safe',
        'is_public' => true,
    ]);
    $chapter = Chapter::query()->create([
        'series_id' => $series->id,
        'number' => 1,
        'title' => 'Published by the transition scheduler',
        'status' => Chapter::STATUS_SCHEDULED,
        'published_at' => now()->subMinute(),
    ]);
    $status = Artisan::call('schedule:run', ['--no-interaction' => true]);

    if ($status !== 0 || $chapter->fresh()?->status !== Chapter::STATUS_PUBLISHED) {
        throw new RuntimeException('The transition scheduler did not publish its due chapter.');
    }

    echo "scheduler-ok\n";

    exit(0);
}

if (in_array($action, ['damage', 'verify-damage'], true)) {
    if ($action === 'damage') {
        DB::transaction(function (): void {
            DB::table('comments')->delete();
            DB::table('pages')->delete();
            DB::table('chapters')->delete();
            DB::table('series')->delete();
            DB::table('model_has_roles')->delete();
            DB::table('users')->delete();
            DB::table('settings')->whereIn('key', ['active_theme', 'enabled_plugins', 'installed_at', 'site_title'])->delete();
        });
        File::put(Storage::disk('media')->path('upgrade/reader.png'), 'damaged-media');
        File::delete(storage_path('app/private/upgrade/input.txt'));
    }

    $state = [
        'users' => DB::table('users')->count(),
        'series' => DB::table('series')->count(),
        'pages' => DB::table('pages')->count(),
        'private_input_exists' => File::exists(storage_path('app/private/upgrade/input.txt')),
        'media_sha256' => hash_file('sha256', Storage::disk('media')->path('upgrade/reader.png')),
    ];

    if ($state['users'] !== 0
        || $state['series'] !== 0
        || $state['pages'] !== 0
        || $state['private_input_exists']) {
        throw new RuntimeException('The deliberate upgrade damage was not applied completely.');
    }

    echo json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

    exit(0);
}

throw new RuntimeException('KOMA_UPGRADE_RUNTIME_ACTION must be identity, archives, queue, scheduler, damage, or verify-damage.');
