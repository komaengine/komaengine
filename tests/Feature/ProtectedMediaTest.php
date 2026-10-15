<?php

use App\Engine\Settings\Settings;
use App\Media\ProtectMediaStorage;
use App\Models\Chapter;
use App\Models\Page;
use App\Models\Series;
use App\Models\User;
use App\Support\MatureContentAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('media');
    app(Settings::class)->set('mature_gate', true);

    $this->series = Series::create([
        'title' => 'Protected Pages',
        'slug' => 'protected-pages',
        'content_rating' => 'safe',
        'is_public' => true,
        'cover_path' => 'covers/cover.jpg',
        'cover_thumbnail_path' => 'covers/thumb.jpg',
    ]);
    $this->chapter = Chapter::create([
        'series_id' => $this->series->id,
        'number' => 1,
        'status' => Chapter::STATUS_PUBLISHED,
        'published_at' => now()->subMinute(),
    ]);
    $this->page = Page::create([
        'chapter_id' => $this->chapter->id,
        'sort_order' => 1,
        'image_path' => 'pages/page.jpg',
    ]);
    Storage::disk('media')->put('covers/cover.jpg', 'cover-bytes');
    Storage::disk('media')->put('covers/thumb.jpg', 'thumbnail-bytes');
    Storage::disk('media')->put('pages/page.jpg', 'page-bytes');
});

test('published safe media is served through guarded private responses', function () {
    $this->get($this->series->coverUrl())
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->get($this->page->url())
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

    $this->get('/storage/media/pages/page.jpg')
        ->assertClientError()
        ->assertDontSee('page-bytes');
});

test('draft and private media is hidden unless the viewer can edit series', function () {
    $this->chapter->update(['status' => Chapter::STATUS_DRAFT, 'published_at' => null]);
    $this->get($this->page->url())->assertNotFound();

    $this->series->update(['is_public' => false]);
    $this->get($this->series->coverUrl())->assertNotFound();

    $member = User::factory()->create();
    $member->assignRole('Member');
    $this->actingAs($member)->get($this->page->url())->assertNotFound();

    $editor = User::factory()->create();
    $editor->assignRole('Editor');
    $this->actingAs($editor)->get($this->series->coverUrl())->assertOk();
    $this->actingAs($editor)->get($this->page->url())->assertOk();
});

test('mature media requires the same session confirmation as its series', function () {
    $this->series->update(['content_rating' => 'mature']);

    $this->get($this->page->url())
        ->assertRedirect(route('mature.confirmation'));

    $this->withSession([MatureContentAccess::CONFIRMED_SESSION_KEY => true])
        ->get($this->page->url())
        ->assertOk();
});

test('legacy public media migration is idempotent and rejects conflicts', function () {
    $token = (string) str()->uuid();
    $source = storage_path('app/public/media-protect-'.$token);
    $target = storage_path('app/media-protect-'.$token);
    File::ensureDirectoryExists($source.'/pages');
    File::put($source.'/pages/one.jpg', 'one');
    config()->set([
        'installer.legacy_public_media_path' => $source,
        'filesystems.disks.media.root' => $target,
    ]);

    try {
        expect(app(ProtectMediaStorage::class)->migrate())->toBe(1)
            ->and(File::get($target.'/pages/one.jpg'))->toBe('one')
            ->and(File::exists($source))->toBeFalse()
            ->and(app(ProtectMediaStorage::class)->migrate())->toBe(0);

        File::ensureDirectoryExists($source.'/pages');
        File::put($source.'/pages/one.jpg', 'different');

        expect(fn () => app(ProtectMediaStorage::class)->migrate())
            ->toThrow(RuntimeException::class, 'conflicts with legacy media');
    } finally {
        File::deleteDirectory($source);
        File::deleteDirectory($target);
    }
});
