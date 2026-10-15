<?php

use App\Engine\Plugins\PluginManager;
use App\Engine\Themes\ThemeManager;
use App\Models\Chapter;
use App\Models\Comment;
use App\Models\Page;
use App\Models\Series;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

$root = getenv('KOMA_APP_ROOT') ?: dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$action = getenv('KOMA_UPGRADE_FIXTURE_ACTION') ?: 'verify';

if (! in_array($action, ['create', 'verify'], true)) {
    throw new RuntimeException('KOMA_UPGRADE_FIXTURE_ACTION must be create or verify.');
}

$timestamp = '2026-07-28 12:00:00';

if ($action === 'create') {
    if (User::query()->where('email', 'upgrade-owner@koma.test')->exists()) {
        throw new RuntimeException('The upgrade fixture already exists.');
    }

    $image = imagecreatetruecolor(1, 1);
    $color = imagecolorallocate($image, 76, 110, 245);
    imagefill($image, 0, 0, $color);
    ob_start();
    imagepng($image);
    $mediaBytes = ob_get_clean();
    imagedestroy($image);

    if (! is_string($mediaBytes) || $mediaBytes === '') {
        throw new RuntimeException('The upgrade fixture image could not be generated.');
    }

    DB::transaction(function () use ($mediaBytes, $timestamp): void {
        $user = User::query()->create([
            'name' => 'Upgrade Owner',
            'email' => 'upgrade-owner@koma.test',
            'password' => 'upgrade-fixture-password',
            'reader_mode' => 'vertical',
        ]);
        $user->assignRole('Administrator');

        DB::table('settings')->insert([
            [
                'key' => 'site_title',
                'value' => json_encode('Pre-release Upgrade Fixture', JSON_THROW_ON_ERROR),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'key' => 'active_theme',
                'value' => json_encode('aozora', JSON_THROW_ON_ERROR),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'key' => 'enabled_plugins',
                'value' => json_encode(['hello-koma'], JSON_THROW_ON_ERROR),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'key' => 'installed_at',
                'value' => json_encode('2026-07-28T12:00:00+00:00', JSON_THROW_ON_ERROR),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ]);

        $series = Series::query()->create([
            'title' => 'Pre-release Upgrade Fixture',
            'slug' => 'pre-release-upgrade-fixture',
            'author' => 'KomaEngine',
            'artist' => 'Compatibility Harness',
            'year' => 2026,
            'status' => 'ongoing',
            'reading_direction' => 'vertical',
            'default_reader_mode' => 'vertical',
            'content_rating' => 'safe',
            'is_public' => true,
            'synopsis' => 'Representative predecessor state for upgrade and rollback verification.',
        ]);

        $published = Chapter::query()->create([
            'series_id' => $series->id,
            'number' => 1,
            'title' => 'Published predecessor chapter',
            'status' => Chapter::STATUS_PUBLISHED,
            'published_at' => $timestamp,
        ]);
        Chapter::query()->create([
            'series_id' => $series->id,
            'number' => 2,
            'title' => 'Scheduled predecessor chapter',
            'status' => Chapter::STATUS_SCHEDULED,
            'published_at' => '2035-01-02 12:00:00',
        ]);
        Page::query()->create([
            'chapter_id' => $published->id,
            'sort_order' => 1,
            'image_path' => 'upgrade/reader.png',
            'width' => 1,
            'height' => 1,
            'file_size' => strlen($mediaBytes),
        ]);
        $series->comments()->create([
            'user_id' => $user->id,
            'body' => 'Predecessor comment survives the full transition.',
            'status' => Comment::STATUS_APPROVED,
        ]);
    });

    File::ensureDirectoryExists(Storage::disk('media')->path('upgrade'));
    File::put(Storage::disk('media')->path('upgrade/reader.png'), $mediaBytes);
    File::ensureDirectoryExists(storage_path('app/private/upgrade'));
    File::put(storage_path('app/private/upgrade/input.txt'), 'upgrade-private-input');
}

$user = User::query()->where('email', 'upgrade-owner@koma.test')->firstOrFail();
$series = Series::query()->where('slug', 'pre-release-upgrade-fixture')->firstOrFail();
$chapters = Chapter::query()
    ->where('series_id', $series->id)
    ->orderBy('number')
    ->get();
$page = Page::query()->where('chapter_id', $chapters->first()->id)->firstOrFail();
$comment = Comment::query()
    ->where('commentable_type', Series::class)
    ->where('commentable_id', $series->id)
    ->firstOrFail();

$media = Storage::disk('media')->path('upgrade/reader.png');
$private = storage_path('app/private/upgrade/input.txt');

foreach ([$media, $private] as $path) {
    if (! is_file($path)) {
        throw new RuntimeException("Upgrade fixture file is missing: {$path}");
    }
}

$state = [
    'format' => 'koma-upgrade-fixture-v1',
    'migrations' => DB::table('migrations')->orderBy('id')->pluck('migration')->all(),
    'user' => [
        'name' => $user->name,
        'email' => $user->email,
        'reader_mode' => $user->reader_mode,
        'roles' => $user->getRoleNames()->sort()->values()->all(),
        'password_verified' => Hash::check('upgrade-fixture-password', $user->password),
    ],
    'settings' => DB::table('settings')
        ->whereIn('key', ['active_theme', 'enabled_plugins', 'installed_at', 'site_title'])
        ->orderBy('key')
        ->get(['key', 'value'])
        ->mapWithKeys(fn (object $setting): array => [
            $setting->key => json_decode($setting->value, true, 512, JSON_THROW_ON_ERROR),
        ])
        ->all(),
    'series' => [
        'title' => $series->title,
        'slug' => $series->slug,
        'reading_direction' => $series->reading_direction,
        'default_reader_mode' => $series->default_reader_mode,
        'is_public' => (bool) $series->is_public,
    ],
    'chapters' => $chapters->map(fn (Chapter $chapter): array => [
        'number' => $chapter->displayNumber(),
        'title' => $chapter->title,
        'status' => $chapter->status,
        'published_at' => $chapter->published_at?->utc()->format('Y-m-d H:i:s'),
    ])->all(),
    'page' => [
        'sort_order' => $page->sort_order,
        'image_path' => $page->image_path,
        'file_size' => $page->file_size,
    ],
    'comment' => [
        'body' => $comment->body,
        'status' => $comment->status,
        'user_email' => $comment->user?->email,
    ],
    'extensions' => [
        'active_theme' => app(ThemeManager::class)->activeSlug(),
        'enabled_plugins' => app(PluginManager::class)->enabledSlugs(),
        'hello_koma_compatible' => (bool) (app(PluginManager::class)->all()['hello-koma']['compatible'] ?? false),
        'hello_koma_failure' => app(PluginManager::class)->failures()['hello-koma'] ?? null,
    ],
    'files' => [
        'media' => [
            'bytes' => filesize($media),
            'sha256' => hash_file('sha256', $media),
        ],
        'private' => [
            'bytes' => filesize($private),
            'sha256' => hash_file('sha256', $private),
        ],
    ],
];

$archive = storage_path('app/private/upgrade/predecessor-v1.koma.zip');

if ($action === 'verify') {
    if (! is_file($archive)) {
        throw new RuntimeException("Upgrade fixture archive is missing: {$archive}");
    }

    $state['files']['archive_v1'] = [
        'bytes' => filesize($archive),
        'sha256' => hash_file('sha256', $archive),
    ];
}

echo json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
