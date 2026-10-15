<?php

use App\Install\InstallerService;
use Illuminate\Contracts\Console\Kernel;

$root = getenv('KOMA_APP_ROOT') ?: dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$driver = getenv('DB_CONNECTION') ?: 'sqlite';
$database = getenv('DB_DATABASE');

if (! is_string($database) || $database === '') {
    throw new RuntimeException('DB_DATABASE is required.');
}

$user = app(InstallerService::class)->install([
    'language' => 'en',
    'db_connection' => $driver,
    'db_host' => getenv('DB_HOST') ?: '',
    'db_port' => getenv('DB_PORT') ?: '',
    'db_database' => $database,
    'db_username' => getenv('DB_USERNAME') ?: '',
    'db_password' => getenv('DB_PASSWORD') ?: '',
    'db_prefix' => getenv('DB_PREFIX') ?: '',
    'storage_driver' => 'local',
    'site_title' => 'Database Process Smoke',
    'app_url' => getenv('APP_URL') ?: 'http://koma.test',
    'admin_name' => 'Process Owner',
    'admin_email' => 'process-owner@koma.test',
    'admin_password' => 'correct-horse-process',
    'default_direction' => 'vertical',
    'mature_gate' => true,
    'discourage_indexing' => false,
]);

echo json_encode([
    'driver' => $driver,
    'user_id' => $user->id,
    'email' => $user->email,
], JSON_THROW_ON_ERROR).PHP_EOL;
