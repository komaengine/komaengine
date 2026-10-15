<?php

use App\Community\CommentReportService;
use App\Engine\Settings\Settings;
use App\Models\Chapter;
use App\Models\Comment;
use App\Models\Page;
use App\Models\Series;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

require '/var/www/html/vendor/autoload.php';

$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

Artisan::call('db:seed', [
    '--class' => Database\Seeders\DatabaseSeeder::class,
    '--force' => true,
]);

$empty = Series::query()->updateOrCreate(
    ['slug' => 'qa-empty-series'],
    [
        'title' => 'QA Empty Series',
        'author' => 'Koma QA',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
        'is_public' => true,
        'synopsis' => 'An intentionally empty release-QA state.',
    ],
);

Series::query()->updateOrCreate(
    ['slug' => 'qa-private-series'],
    [
        'title' => 'QA Private Series',
        'author' => 'Koma QA',
        'status' => 'ongoing',
        'reading_direction' => 'ltr',
        'content_rating' => 'safe',
        'is_public' => false,
        'synopsis' => 'An intentionally private release-QA state.',
    ],
);

$missing = Series::query()->updateOrCreate(
    ['slug' => 'qa-missing-media'],
    [
        'title' => str_repeat('QA Missing Media and Long Layout State ', 5),
        'author' => 'Koma QA',
        'status' => 'ongoing',
        'reading_direction' => 'vertical',
        'content_rating' => 'safe',
        'is_public' => true,
        'synopsis' => str_repeat('Long release-QA synopsis used to expose overflow regressions. ', 20),
    ],
);
$chapter = Chapter::query()->updateOrCreate(
    ['series_id' => $missing->id, 'number' => 1],
    [
        'title' => 'Missing media fallback',
        'status' => 'published',
        'published_at' => now()->subDay(),
    ],
);
Page::query()->updateOrCreate(
    ['chapter_id' => $chapter->id, 'sort_order' => 1],
    [
        'image_path' => 'pages/qa/missing.webp',
        'width' => 800,
        'height' => 1200,
        'file_size' => 1,
    ],
);

$member = User::query()->where('email', 'member@koma.test')->firstOrFail();
$reporter = User::query()->updateOrCreate(
    ['email' => 'qa-reporter@koma.test'],
    ['name' => 'QA Reporter', 'password' => Hash::make('password')],
);
$reporter->syncRoles(['Member']);
$comment = $empty->comments()->firstOrCreate(
    ['user_id' => $member->id, 'body' => 'QA moderation queue comment.'],
    ['status' => Comment::STATUS_APPROVED],
);
app(CommentReportService::class)->report($reporter, $comment, 'spam', 'Synthetic QA browser-QA report.');

app(Settings::class)->set('plugin_failures', [
    'hello-koma' => [
        'stage' => 'boot',
        'message' => 'Synthetic QA recovery notice.',
    ],
]);
app(Settings::class)->set('enabled_plugins', []);
