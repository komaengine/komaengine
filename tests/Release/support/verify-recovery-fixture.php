<?php

use App\Engine\Settings\Settings;
use App\Models\Chapter;
use App\Models\Page;
use App\Models\Series;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

$root = getenv('KOMA_APP_ROOT') ?: dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$user = User::query()->where('email', 'process-owner@koma.test')->firstOrFail();
$series = Series::query()->where('slug', 'recovery-fixture')->firstOrFail();
$chapter = $series->chapters()->where('number', 1)->firstOrFail();
$page = Page::query()->where('chapter_id', $chapter->id)->where('sort_order', 1)->firstOrFail();
$media = Storage::disk('media')->path('recovery/reader.txt');
$privateInput = storage_path('app/private/recovery/input.txt');

$checks = [
    'administrator role' => $user->hasRole('Administrator'),
    'administrator credential' => Hash::check('correct-horse-process', $user->password),
    'installer marker' => app(Settings::class)->has('installed_at'),
    'series title' => $series->title === 'Recovery Fixture',
    'chapter title' => $chapter->title === 'Recovered chapter',
    'chapter status' => $chapter->status === Chapter::STATUS_PUBLISHED,
    'page path' => $page->image_path === 'recovery/reader.txt',
    'protected media path' => is_file($media),
    'private input path' => is_file($privateInput),
    'protected media content' => is_file($media) && file_get_contents($media) === 'reader-media',
    'private input content' => is_file($privateInput) && file_get_contents($privateInput) === 'private-media',
];
$missing = array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed));

if ($missing !== []) {
    throw new RuntimeException('The recovered durable fixture is incomplete: '.implode(', ', $missing).'.');
}

echo json_encode([
    'administrator' => [
        'email' => $user->email,
        'role' => 'Administrator',
    ],
    'catalog' => [
        'series' => $series->slug,
        'chapter' => $chapter->displayNumber(),
        'page' => $page->image_path,
    ],
    'migrations' => DB::table('migrations')->orderBy('id')->pluck('migration')->all(),
    'media_sha256' => hash_file('sha256', $media),
    'private_input_sha256' => hash_file('sha256', $privateInput),
    'app_key_sha256' => hash('sha256', (string) config('app.key')),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
