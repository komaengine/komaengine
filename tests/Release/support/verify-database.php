<?php

use App\Engine\Settings\Settings;
use App\Install\InstallationException;
use App\Install\InstallerService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

$root = getenv('KOMA_APP_ROOT') ?: dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$user = User::query()->where('email', 'process-owner@koma.test')->firstOrFail();
$settings = app(Settings::class);

if (! $user->hasRole('Administrator')
    || ! Hash::check('correct-horse-process', $user->password)
    || ! $settings->has('installed_at')
    || $settings->get('site_title') !== 'Database Process Smoke'
    || $settings->get('default_reading_direction') !== 'vertical') {
    throw new RuntimeException('The installed database did not survive process restart.');
}

try {
    app(InstallerService::class)->install([
        'language' => 'en',
        'db_connection' => getenv('DB_CONNECTION'),
        'db_host' => getenv('DB_HOST') ?: '',
        'db_port' => getenv('DB_PORT') ?: '',
        'db_database' => getenv('DB_DATABASE'),
        'db_username' => getenv('DB_USERNAME') ?: '',
        'db_password' => getenv('DB_PASSWORD') ?: '',
        'db_prefix' => getenv('DB_PREFIX') ?: '',
        'storage_driver' => 'local',
        'site_title' => 'Replay',
        'app_url' => getenv('APP_URL') ?: 'http://koma.test',
        'admin_name' => 'Replay Owner',
        'admin_email' => 'replay-owner@koma.test',
        'admin_password' => 'correct-horse-replay',
        'default_direction' => 'rtl',
        'mature_gate' => true,
        'discourage_indexing' => false,
    ]);

    throw new RuntimeException('A restarted installer accepted a replay.');
} catch (InstallationException $exception) {
    if ($exception->errorCode !== 'ALREADY_INSTALLED') {
        throw $exception;
    }
}

if (User::query()->count() !== 1) {
    throw new RuntimeException('Installer replay created another user.');
}

echo json_encode([
    'users' => User::query()->count(),
    'installed' => true,
    'direction' => $settings->get('default_reading_direction'),
], JSON_THROW_ON_ERROR).PHP_EOL;
