<?php

use App\Install\InstallerService;
use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = getenv('DB_DATABASE');

if (! is_string($database) || $database === '') {
    throw new RuntimeException('DB_DATABASE is required.');
}

$user = app(InstallerService::class)->install([
    'language' => 'en',
    'db_connection' => 'sqlite',
    'db_host' => '',
    'db_port' => '',
    'db_database' => $database,
    'db_username' => '',
    'db_password' => '',
    'db_prefix' => '',
    'storage_driver' => 'local',
    'site_title' => 'SQLite Process Smoke',
    'app_url' => 'http://koma.test',
    'admin_name' => 'Process Owner',
    'admin_email' => 'process-owner@koma.test',
    'admin_password' => 'correct-horse-process',
    'default_direction' => 'vertical',
    'mature_gate' => true,
    'discourage_indexing' => false,
]);

echo json_encode([
    'user_id' => $user->id,
    'email' => $user->email,
], JSON_THROW_ON_ERROR).PHP_EOL;
