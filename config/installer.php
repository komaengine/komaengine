<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Installer file writes
    |--------------------------------------------------------------------------
    |
    | The web installer updates .env and robots.txt during a real browser setup.
    | Tests keep these disabled so the local developer environment is not changed.
    |
    */

    'write_env' => env('INSTALLER_WRITE_ENV', true),
    'write_env_in_tests' => false,
    'write_robots' => env('INSTALLER_WRITE_ROBOTS', true),
    'environment_mode' => env('INSTALLER_ENVIRONMENT_MODE', 'file'),
    'environment_path' => base_path('.env'),
    'environment_example_path' => base_path('.env.example'),
    'cached_config_path' => app()->getCachedConfigPath(),
    'lock_path' => env('INSTALLER_LOCK_PATH', storage_path('framework/koma-install.lock')),
    'installed_marker_path' => env('INSTALLER_MARKER_PATH', storage_path('framework/koma-installed')),
    'legacy_public_media_path' => storage_path('app/public/media'),
    'write_installed_marker_in_tests' => false,
    'public_storage_link' => public_path('storage'),
    'public_storage_target' => storage_path('app/public'),
    'verify_storage_link_in_tests' => false,
    'verify_environment_in_tests' => false,
    'runtime_paths' => [
        'storage/framework/cache' => storage_path('framework/cache'),
        'storage/framework/sessions' => storage_path('framework/sessions'),
        'storage/framework/views' => storage_path('framework/views'),
        'storage/logs' => storage_path('logs'),
        'storage/app/media' => storage_path('app/media'),
        'storage/app/private' => storage_path('app/private'),
        'storage/app/archive-import-temp' => storage_path('app/archive-import-temp'),
        'bootstrap/cache' => base_path('bootstrap/cache'),
    ],
];
