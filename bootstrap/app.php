<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\EnsureKomaInstalled::class,
            \App\Http\Middleware\DiscourageSearchEngines::class,
        ]);
        $middleware->api(prepend: [
            \App\Http\Middleware\EnsureKomaInstalled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            if ($request->is('admin/archives/import')) {
                return redirect()->route('admin.archives.import', [
                    'upload_error' => 'post_too_large',
                ]);
            }

            return null;
        });

        $exceptions->render(function (\App\Community\CommentsDisabledException $exception, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $exception->getMessage()], 403)
                : response($exception->getMessage(), 403);
        });
    })->create();
