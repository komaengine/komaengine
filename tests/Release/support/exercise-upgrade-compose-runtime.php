<?php

use App\Archives\ArchiveImportPolicy;
use App\Engine\Settings\Settings;
use App\Jobs\ImportSeriesArchive;
use App\Models\Chapter;
use App\Models\Series;
use App\Models\SeriesArchiveImport;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;

$root = getenv('KOMA_APP_ROOT') ?: '/var/www/html';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$action = getenv('KOMA_UPGRADE_COMPOSE_ACTION');
$sourcePath = 'archive-imports/source/recovery-runtime.koma.zip';

if ($action === 'dispatch') {
    $user = User::query()->where('email', 'upgrade-owner@koma.test')->firstOrFail();

    if (! Storage::disk('local')->exists($sourcePath)) {
        throw new RuntimeException('The retained upgrade worker archive is missing.');
    }

    $import = SeriesArchiveImport::query()->create([
        'id' => (string) str()->uuid(),
        'user_id' => $user->id,
        'source_name' => 'recovery-runtime.koma.zip',
        'source_path' => $sourcePath,
        'status' => SeriesArchiveImport::STATUS_QUEUED,
        'conflict_policy' => ArchiveImportPolicy::FAIL,
        'replace_confirmed' => false,
        'progress' => 0,
        'stage' => 'Queued',
        'message' => 'MariaDB upgrade worker probe queued.',
    ]);

    ImportSeriesArchive::dispatch($import->id);

    $series = Series::query()->create([
        'title' => 'MariaDB Upgrade Scheduler Probe',
        'slug' => 'mariadb-upgrade-scheduler-probe',
        'author' => 'KomaEngine',
        'status' => 'ongoing',
        'reading_direction' => 'vertical',
        'content_rating' => 'safe',
        'is_public' => true,
    ]);
    $chapter = Chapter::query()->create([
        'series_id' => $series->id,
        'number' => 1,
        'title' => 'Published by the MariaDB upgrade scheduler',
        'status' => Chapter::STATUS_SCHEDULED,
        'published_at' => now()->subMinute(),
    ]);

    echo json_encode([
        'import_id' => $import->id,
        'chapter_id' => $chapter->id,
        'key_fingerprint' => hash('sha256', (string) config('app.key')),
    ], JSON_THROW_ON_ERROR).PHP_EOL;

    exit(0);
}

if ($action !== 'status') {
    throw new RuntimeException('KOMA_UPGRADE_COMPOSE_ACTION must be dispatch or status.');
}

$import = SeriesArchiveImport::query()
    ->where('source_name', 'recovery-runtime.koma.zip')
    ->latest('created_at')
    ->first();
$chapter = Chapter::query()
    ->whereHas('series', fn ($query) => $query->where('slug', 'mariadb-upgrade-scheduler-probe'))
    ->first();
$result = $import?->resultSeries;
$readerChapter = $result?->chapters()
    ->published()
    ->with('pages')
    ->orderBy('number')
    ->first();

echo json_encode([
    'import_status' => $import?->status,
    'import_error' => $import?->error,
    'import_series_id' => $import?->result_series_id,
    'import_chapters' => $result?->chapters()->count(),
    'import_pages' => $result?->chapters()->withCount('pages')->get()->sum('pages_count'),
    'series_slug' => $result?->slug,
    'chapter_number' => $readerChapter?->displayNumber(),
    'page_urls' => $readerChapter?->pages->map->url()->values()->all() ?? [],
    'scheduled_chapter_status' => $chapter?->status,
    'installed' => app(Settings::class)->has('installed_at'),
    'key_fingerprint' => hash('sha256', (string) config('app.key')),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
