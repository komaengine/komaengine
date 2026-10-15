<?php

use App\Http\Controllers\Api\V1\BulkChapterController;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware([AuthenticateApiToken::class, 'can:upload_chapters', 'throttle:api'])
    ->group(function () {
        Route::post('/series/{series:slug}/chapters/bulk', [BulkChapterController::class, 'store'])
            ->name('series.chapters.bulk');
    });
