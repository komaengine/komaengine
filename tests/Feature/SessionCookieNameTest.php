<?php

// The installer writes the site title into APP_NAME. Names derived from it
// would change when installation finishes and sign out the new administrator.

function withSessionTestEnvironment(array $values, callable $callback): mixed
{
    $previous = [];

    foreach ($values as $key => $value) {
        $previous[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];

        if ($value === null) {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);
        } else {
            $_SERVER[$key] = $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    try {
        return $callback();
    } finally {
        foreach ($previous as $key => [$server, $env, $process]) {
            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }

            if ($env === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }

            putenv($process === false ? $key : "{$key}={$process}");
        }
    }
}

test('the session cookie and cache prefix do not follow the site title', function () {
    [$session, $cache] = withSessionTestEnvironment(
        ['APP_NAME' => 'My Manga Library', 'SESSION_COOKIE' => null, 'CACHE_PREFIX' => null],
        fn () => [require base_path('config/session.php'), require base_path('config/cache.php')],
    );

    expect($session['cookie'])->toBe('komaengine_session')
        ->and($cache['prefix'])->toBe('komaengine_cache_')
        ->and(config('session.cookie'))->toBe('komaengine_session');
});

test('SESSION_COOKIE still overrides the session cookie name', function () {
    $session = withSessionTestEnvironment(
        ['SESSION_COOKIE' => 'custom_session'],
        fn () => require base_path('config/session.php'),
    );

    expect($session['cookie'])->toBe('custom_session');
});
