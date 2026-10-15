<?php

use App\Models\Chapter;
use App\Models\Page;
use App\Models\Series;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

$root = getenv('KOMA_APP_ROOT') ?: dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$series = Series::query()->create([
    'title' => 'Recovery Fixture',
    'slug' => 'recovery-fixture',
    'author' => 'KomaEngine',
    'status' => 'ongoing',
    'reading_direction' => 'vertical',
    'content_rating' => 'safe',
    'is_public' => true,
]);
$chapter = Chapter::query()->create([
    'series_id' => $series->id,
    'number' => 1,
    'title' => 'Recovered chapter',
    'status' => Chapter::STATUS_PUBLISHED,
    'published_at' => now()->subMinute(),
]);
Page::query()->create([
    'chapter_id' => $chapter->id,
    'sort_order' => 1,
    'image_path' => 'recovery/reader.txt',
    'width' => 1,
    'height' => 1,
    'file_size' => 12,
]);

$media = Storage::disk('media')->path('recovery');
File::ensureDirectoryExists($media);
File::put($media.'/reader.txt', 'reader-media');
File::ensureDirectoryExists(storage_path('app/private/recovery'));
File::put(storage_path('app/private/recovery/input.txt'), 'private-media');
