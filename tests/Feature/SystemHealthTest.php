<?php

use App\Engine\Health\CronCommand;
use App\Engine\Health\Heartbeats;
use App\Engine\Settings\Settings;
use App\Livewire\Admin\SystemHealth;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    app(Settings::class)->set('installed_at', now()->toIso8601String());
    $this->admin = User::factory()->create();
    $this->admin->assignRole('Administrator');

    // A storage link the readiness checks accept, as in KomaDoctorTest.
    $this->healthRoot = storage_path('framework/testing/system-health-'.str()->uuid());
    File::ensureDirectoryExists($this->healthRoot.'/target');
    symlink($this->healthRoot.'/target', $this->healthRoot.'/public-storage');
    config([
        'installer.public_storage_link' => $this->healthRoot.'/public-storage',
        'installer.public_storage_target' => $this->healthRoot.'/target',
        'installer.legacy_public_media_path' => $this->healthRoot.'/legacy-media',
        'queue.default' => 'database',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->healthRoot);
});

test('an administrator sees every check, background work, PHP limits, disk, and release', function () {
    app(Heartbeats::class)->recordSchedulerRun();

    $this->actingAs($this->admin)
        ->get(route('admin.health'))
        ->assertOk()
        ->assertSee('System Health')
        ->assertSeeInOrder(['Application key', 'Manga storage', 'Database connection', 'Database tables',
            'Writable folders', 'Cache', 'Public storage link', 'Manga files kept private', 'Installation finished'])
        ->assertSee('Scheduler last ran')
        ->assertSee('No job finished yet')
        ->assertSeeInOrder(['memory_limit', 'upload_max_filesize', 'post_max_size', 'max_execution_time'])
        ->assertSee('PHP '.PHP_VERSION)
        ->assertSee(config('koma.version'))
        ->assertSee('data-testid="health-disk"', false)
        ->assertSee('cd '.base_path().' &amp;&amp; ', false)
        ->assertSee('artisan schedule:run &gt;/dev/null 2&gt;&amp;1', false)
        ->assertSee(route('admin.health'), false);

    $health = Livewire::actingAs($this->admin)->test(SystemHealth::class);

    expect(collect($health->viewData('checks'))->every(fn (array $check) => $check['ok']))->toBeTrue()
        ->and($health->viewData('background')['scheduler_stale'])->toBeFalse()
        ->and($health->viewData('background')['queue_stale'])->toBeFalse();
});

test('an editor cannot open the health page', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');

    $this->actingAs($editor)->get(route('admin.health'))->assertForbidden();
});

test('a failing check shows its fix and a troubleshooting link and counts as a problem', function () {
    app(Heartbeats::class)->recordSchedulerRun();
    $before = Livewire::actingAs($this->admin)->test(SystemHealth::class)->viewData('problems');

    File::deleteDirectory($this->healthRoot.'/target');

    $health = Livewire::actingAs($this->admin)->test(SystemHealth::class)
        ->assertSee(($before + 1).' '.($before === 0 ? 'item needs' : 'items need').' attention')
        ->assertSeeHtml('https://github.com/komaengine/komaengine/blob/main/docs/install/troubleshooting.md#permissions-and-storage-link')
        ->assertSee('public/storage must be a link to storage/app/public. Run php artisan storage:link.');

    expect(collect($health->viewData('checks'))->firstWhere('name', 'storage_link')['ok'])->toBeFalse()
        ->and($health->viewData('problems'))->toBe($before + 1);
});

test('a scheduler that has not run for a few minutes is flagged', function () {
    $this->travelTo('2026-10-27 12:00:00');
    app(Heartbeats::class)->recordSchedulerRun();
    $this->travelTo('2026-10-27 12:04:00');

    expect(Livewire::actingAs($this->admin)->test(SystemHealth::class)
        ->assertDontSee('The scheduler should run every minute')
        ->viewData('background')['scheduler_stale'])->toBeFalse();

    $this->travelTo('2026-10-27 12:06:00');

    expect(Livewire::actingAs($this->admin)->test(SystemHealth::class)
        ->assertSee('The scheduler should run every minute')
        ->assertSeeHtml('troubleshooting.md#scheduled-chapters-do-not-publish')
        ->viewData('background')['scheduler_stale'])->toBeTrue();
});

test('a never-seen scheduler is flagged', function () {
    expect(Livewire::actingAs($this->admin)->test(SystemHealth::class)
        ->assertSee('Not seen yet')
        ->assertSee('The scheduler should run every minute')
        ->viewData('background')['scheduler_stale'])->toBeTrue();
});

test('queue counts and a job left waiting with no worker are shown', function () {
    $this->travelTo('2026-10-27 12:00:00');
    app(Heartbeats::class)->recordSchedulerRun();
    app(Heartbeats::class)->recordProcessedJob('archive-imports');
    DB::table('jobs')->insert([
        'queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
        'available_at' => now()->subMinutes(10)->getTimestamp(), 'created_at' => now()->subMinutes(10)->getTimestamp(),
    ]);
    DB::table('failed_jobs')->insert([
        'uuid' => (string) str()->uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => '{}', 'exception' => 'RuntimeException', 'failed_at' => now(),
    ]);

    Livewire::actingAs($this->admin)->test(SystemHealth::class)
        ->assertSeeHtml('data-testid="health-queue-warning"')
        ->assertSee('A job has waited more than 5 minutes and none is running')
        ->assertSee('php artisan queue:failed')
        ->assertSeeHtml('on <span class="font-mono">archive-imports</span>');

    // A running job keeps the next one waiting; that is not a stall.
    DB::table('jobs')->insert([
        'queue' => 'archive-imports', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => now()->getTimestamp(),
        'available_at' => now()->subMinutes(20)->getTimestamp(), 'created_at' => now()->subMinutes(20)->getTimestamp(),
    ]);

    Livewire::actingAs($this->admin)->test(SystemHealth::class)
        ->assertDontSeeHtml('data-testid="health-queue-warning"');
});

test('the page says how the queue runs and hides the cron job under Docker', function () {
    app(Heartbeats::class)->recordSchedulerRun();

    config(['koma.queue.via_scheduler' => true]);
    Livewire::actingAs($this->admin)->test(SystemHealth::class)
        ->assertSee('so the one cron job below runs both')
        ->assertSeeHtml('data-testid="health-cron"');

    config(['koma.queue.via_scheduler' => false]);
    Livewire::actingAs($this->admin)->test(SystemHealth::class)
        ->assertSee('A separate queue worker must run beside the scheduler');

    config(['koma.runtime' => 'docker-compose']);
    Livewire::actingAs($this->admin)->test(SystemHealth::class)
        ->assertSee('Docker Compose runs the scheduler and the queue worker as their own services.')
        ->assertDontSeeHtml('data-testid="health-cron"');
});

test('a PHP limit below the recommended value is flagged', function () {
    $previous = ini_set('memory_limit', '256M');

    try {
        $limit = collect(Livewire::actingAs($this->admin)->test(SystemHealth::class)
            ->assertSeeHtml('data-testid="health-php-memory_limit"')
            ->assertSee('Too low')
            ->viewData('php'))->firstWhere('name', 'memory_limit');

        expect($limit['current'])->toBe('256M')
            ->and($limit['ok'])->toBeFalse();
    } finally {
        ini_set('memory_limit', (string) $previous);
    }
});

test('the cron command uses the command-line PHP that sits beside the web server PHP', function (string $binary, array $present, ?string $expected) {
    expect(CronCommand::cliBinary($binary, fn (string $path) => in_array($path, $present, true)))->toBe($expected);
})->with([
    'command-line PHP' => ['/usr/local/bin/php', [], '/usr/local/bin/php'],
    'Plesk' => ['/opt/plesk/php/8.3/sbin/php-fpm', ['/opt/plesk/php/8.3/bin/php'], '/opt/plesk/php/8.3/bin/php'],
    'cPanel' => ['/opt/cpanel/ea-php83/root/usr/sbin/php-fpm', ['/opt/cpanel/ea-php83/root/usr/bin/php'], '/opt/cpanel/ea-php83/root/usr/bin/php'],
    'Ubuntu' => ['/usr/sbin/php-fpm8.3', ['/usr/bin/php8.3', '/usr/bin/php'], '/usr/bin/php8.3'],
    'LiteSpeed' => ['/usr/local/lsws/lsphp83/bin/lsphp', ['/usr/local/lsws/lsphp83/bin/php'], '/usr/local/lsws/lsphp83/bin/php'],
    'not found' => ['/usr/sbin/php-fpm', [], null],
    'unknown' => ['', [], null],
]);

test('the health page is in the admin navigation for administrators only', function () {
    $this->actingAs($this->admin)->get(route('admin.dashboard'))
        ->assertSee(route('admin.health'), false);

    $editor = User::factory()->create();
    $editor->assignRole('Editor');

    $this->actingAs($editor)->get(route('admin.dashboard'))
        ->assertDontSee(route('admin.health'), false);
});
