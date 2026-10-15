<?php

use App\Archives\SeriesArchiveExporter;
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

$mediaDirectory = Storage::disk('media')->path('recovery-queue');
$mediaPath = $mediaDirectory.'/source.png';
$archivePath = storage_path('app/private/archive-imports/source/recovery-runtime.koma.zip');

File::ensureDirectoryExists($mediaDirectory);
File::ensureDirectoryExists(dirname($archivePath));

if (! function_exists('imagecreatetruecolor')) {
    throw new RuntimeException('The recovery queue archive requires the GD extension.');
}

$image = imagecreatetruecolor(16, 16);
if ($image === false) {
    throw new RuntimeException('The recovery queue image could not be created.');
}

try {
    $color = imagecolorallocate($image, 55, 90, 150);
    if ($color === false || ! imagefill($image, 0, 0, $color) || ! imagepng($image, $mediaPath)) {
        throw new RuntimeException('The recovery queue image could not be written.');
    }
} finally {
    imagedestroy($image);
}

$series = Series::query()->create([
    'title' => 'Recovery Queue Payload',
    'slug' => 'recovery-queue-payload',
    'author' => 'KomaEngine',
    'status' => 'ongoing',
    'reading_direction' => 'vertical',
    'content_rating' => 'safe',
    'is_public' => true,
]);
$chapter = Chapter::query()->create([
    'series_id' => $series->id,
    'number' => 1,
    'title' => 'Imported by the recovered worker',
    'status' => Chapter::STATUS_PUBLISHED,
    'published_at' => now()->subMinute(),
]);
Page::query()->create([
    'chapter_id' => $chapter->id,
    'sort_order' => 1,
    'image_path' => 'recovery-queue/source.png',
    'width' => 16,
    'height' => 16,
    'file_size' => filesize($mediaPath),
]);

try {
    app(SeriesArchiveExporter::class)->export($series, $archivePath);
} finally {
    $series->delete();
    File::delete($mediaPath);
    File::deleteDirectory($mediaDirectory);
}

if (! is_file($archivePath)) {
    throw new RuntimeException('The retained recovery queue archive was not created.');
}

echo "recovery-queue-archive-created\n";
