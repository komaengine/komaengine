<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
if (! is_file(__DIR__.'/../vendor/autoload.php')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "KomaEngine cannot start: vendor/autoload.php is missing.\n"
        ."This looks like GitHub's \"Source code\" download or a Git checkout. Install the\n"
        ."release ZIP (komaengine-YYYYMMDD-HHMMSS.zip) from https://komaengine.com/download/.\n"
        ."Developers: run composer install.\n";
    exit;
}

require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
