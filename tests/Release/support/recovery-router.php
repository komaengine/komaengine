<?php

$root = getenv('KOMA_APP_ROOT') ?: '/app';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (is_string($path) && $path !== '/' && is_file($root.'/public'.$path)) {
    return false;
}

require $root.'/public/index.php';
