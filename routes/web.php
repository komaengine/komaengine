<?php

use App\Http\Controllers\Admin\SeriesArchiveController;
use App\Http\Controllers\Admin\SeriesArchiveImportController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\CommentReportController;
use App\Http\Controllers\MatureContentController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\ReaderController;
use App\Http\Controllers\ReaderPreferenceController;
use App\Http\Controllers\ThemeAssetController;
use App\Http\Middleware\EnsureMatureContentAccess;
use App\Http\Middleware\EnsureSeriesIsVisible;
use App\Livewire\Admin\ApiTokens as AdminApiTokens;
use App\Livewire\Admin\ArchiveImporter;
use App\Livewire\Admin\Chapters\Pages as ChapterPages;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Extensions;
use App\Livewire\Admin\Genres;
use App\Livewire\Admin\Moderation;
use App\Livewire\Admin\Series\Edit as SeriesEdit;
use App\Livewire\Admin\Series\Index as SeriesIndex;
use App\Livewire\Admin\Settings as AdminSettings;
use App\Livewire\Admin\Tags;
use App\Livewire\Admin\Updates;
use App\Livewire\Admin\Uploader;
use App\Livewire\Admin\Users as AdminUsers;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Install\Installer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/install', Installer::class)->name('install');

/*
|--------------------------------------------------------------------------
| Theme assets (precompiled files served from the active theme)
|--------------------------------------------------------------------------
*/
Route::get('theme-assets/{path}', [ThemeAssetController::class, 'show'])
    ->where('path', '.*')
    ->name('theme.asset');

Route::get('/robots.txt', function (App\Engine\Settings\Settings $settings) {
    $body = (bool) $settings->get('discourage_search_engines', false)
        ? "User-agent: *\nDisallow: /\n"
        : "User-agent: *\nDisallow: /admin\nDisallow: /install\n";

    return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
})->name('robots');

/*
|--------------------------------------------------------------------------
| Public catalog + reader (rendered through the active theme)
|--------------------------------------------------------------------------
*/
Route::get('/', [CatalogController::class, 'home'])->name('home');
Route::get('/browse', [CatalogController::class, 'browse'])->name('browse');
Route::get('/mature-content', [MatureContentController::class, 'show'])->name('mature.confirmation');
Route::post('/mature-content/confirm', [MatureContentController::class, 'confirm'])->name('mature.confirm');
Route::post('/mature-content/decline', [MatureContentController::class, 'decline'])->name('mature.decline');
Route::middleware([EnsureSeriesIsVisible::class, EnsureMatureContentAccess::class])->group(function () {
    Route::get('/media/series/{series:slug}/{variant}', [MediaController::class, 'series'])
        ->where('variant', 'cover|thumbnail')
        ->name('media.series');
    Route::get('/media/series/{series:slug}/chapters/{chapter}/pages/{page}', [MediaController::class, 'page'])
        ->scopeBindings()
        ->name('media.page');
});
// Permalink bases are operator-configurable (Settings → Permalinks). Named
// routes mean URL generation follows automatically. Falls back to defaults
// before the settings table exists (fresh install).
$seriesBase = trim((string) app(App\Engine\Settings\Settings::class)->get('permalink_series_base', 'series'), '/') ?: 'series';
$chapterBase = trim((string) app(App\Engine\Settings\Settings::class)->get('permalink_chapter_base', 'chapter'), '/') ?: 'chapter';

Route::middleware([EnsureSeriesIsVisible::class, EnsureMatureContentAccess::class])->group(function () use ($seriesBase, $chapterBase) {
    Route::get("/{$seriesBase}/{series:slug}", [CatalogController::class, 'show'])->name('series.show');
    Route::get("/{$seriesBase}/{series:slug}/{$chapterBase}/{number}", [ReaderController::class, 'show'])->name('reader');

    Route::middleware(['auth', 'throttle:comments'])->group(function () use ($seriesBase, $chapterBase) {
        Route::post("/{$seriesBase}/{series:slug}/comments", [CommentController::class, 'storeSeries'])
            ->name('comments.series.store');
        Route::post("/{$seriesBase}/{series:slug}/{$chapterBase}/{chapter:number}/comments", [CommentController::class, 'storeChapter'])
            ->scopeBindings()
            ->name('comments.chapter.store');
        Route::patch("/{$seriesBase}/{series:slug}/comments/{comment}", [CommentController::class, 'update'])
            ->name('comments.update');
        Route::delete("/{$seriesBase}/{series:slug}/comments/{comment}", [CommentController::class, 'destroy'])
            ->name('comments.destroy');
    });

    Route::post("/{$seriesBase}/{series:slug}/comments/{comment}/report", [CommentReportController::class, 'store'])
        ->middleware(['auth', 'throttle:comment-reports'])
        ->name('comments.reports.store');
});

// Keep the default structure working as redirects when the bases are customized.
// The series redirect is only needed when the series base moved; the reader
// redirect when either base moved (its old path embeds both).
if ($seriesBase !== 'series') {
    Route::get('/series/{series}', fn (string $series) => redirect()->route('series.show', $series));
}
if ($seriesBase !== 'series' || $chapterBase !== 'chapter') {
    Route::get('/series/{series}/chapter/{number}', fn (string $series, string $number) => redirect()->route('reader', [$series, $number]));
}

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
    Route::get('/register', Register::class)->name('register');
});

Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect('/');
})->middleware('auth')->name('logout');

Route::put('/reader/preferences/mode', [ReaderPreferenceController::class, 'update'])
    ->middleware('auth')
    ->name('reader.preferences.mode');

/*
|--------------------------------------------------------------------------
| Admin (Livewire full-page components), gated per capability
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'can:access-admin'])->prefix('admin')->group(function () {
    Route::get('/', Dashboard::class)->name('admin.dashboard');
    Route::get('/updates', Updates::class)->middleware('can:manage_settings')->name('admin.updates');

    Route::get('/series', SeriesIndex::class)->middleware('can:edit_series')->name('admin.series.index');
    Route::get('/series/create', SeriesEdit::class)->middleware('can:edit_series')->name('admin.series.create');
    Route::get('/series/{series:slug}/export', SeriesArchiveController::class)
        ->middleware('can:edit_series')
        ->name('admin.series.export');
    Route::get('/series/{series:slug}/chapters/{chapter}/pages', ChapterPages::class)
        ->scopeBindings()
        ->middleware('can:edit_series')
        ->name('admin.series.chapters.pages');
    Route::get('/series/{series:slug}', SeriesEdit::class)->middleware('can:edit_series')->name('admin.series.edit');

    Route::get('/upload', Uploader::class)->middleware('can:upload_chapters')->name('admin.upload');
    Route::get('/archives/import', ArchiveImporter::class)->middleware('can:edit_series')->name('admin.archives.import');
    Route::post('/archives/import', SeriesArchiveImportController::class)
        ->middleware('can:edit_series')
        ->name('admin.archives.import.store');
    Route::get('/genres', Genres::class)->middleware('can:manage_taxonomy')->name('admin.genres');
    Route::get('/tags', Tags::class)->middleware('can:manage_taxonomy')->name('admin.tags');
    Route::get('/comments', Moderation::class)->middleware('can:moderate_comments')->name('admin.comments');
    Route::get('/users', AdminUsers::class)->middleware('can:manage_users')->name('admin.users');
    Route::get('/api-tokens', AdminApiTokens::class)->middleware('can:manage_users')->name('admin.api-tokens');
    Route::get('/themes/{theme}/preview', [ThemeAssetController::class, 'preview'])
        ->where('theme', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->middleware('can:manage_themes')
        ->name('admin.themes.preview');
    Route::get('/themes', Extensions::class)
        ->defaults('tab', 'themes')
        ->middleware('can:manage_themes')
        ->name('admin.themes');
    Route::get('/plugins', Extensions::class)
        ->defaults('tab', 'plugins')
        ->middleware('can:manage_plugins')
        ->name('admin.plugins');
    Route::get('/plugins/hooks', Extensions::class)
        ->defaults('tab', 'hooks')
        ->middleware('can:manage_plugins')
        ->name('admin.plugins.hooks');
    Route::redirect('/extensions', '/admin/themes')
        ->middleware('can:manage_themes')
        ->name('admin.extensions');
    Route::redirect('/extensions/themes', '/admin/themes')
        ->middleware('can:manage_themes')
        ->name('admin.extensions.themes');
    Route::redirect('/extensions/plugins', '/admin/plugins')
        ->middleware('can:manage_plugins')
        ->name('admin.extensions.plugins');
    Route::redirect('/extensions/hooks', '/admin/plugins/hooks')
        ->middleware('can:manage_plugins')
        ->name('admin.extensions.hooks');
    Route::get('/settings/{tab?}', AdminSettings::class)->middleware('can:manage_settings')->name('admin.settings');
});
