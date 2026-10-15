<?php

use App\Livewire\Admin\Series\Edit;
use App\Livewire\Admin\Uploader;
use App\Models\Chapter;
use App\Models\Series;
use App\Models\User;
use App\Support\Facades\Hook;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-07-19 12:00:00 UTC'));

    $this->editor = User::factory()->create();
    $this->editor->assignRole('Editor');
    $this->series = Series::create([
        'title' => 'Scheduling Test Series',
        'slug' => 'scheduling-test-series',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
        'is_public' => true,
    ]);

    Storage::fake('media');
});

test('future scheduled chapters stay out of catalogs and reader routes', function () {
    $chapter = $this->series->chapters()->create([
        'number' => 1,
        'title' => 'Tomorrow is hidden',
    ]);
    $chapter->scheduleFor(now()->addDay());

    expect($chapter->isScheduled())->toBeTrue()
        ->and($chapter->isPublished())->toBeFalse()
        ->and(Chapter::published()->count())->toBe(0)
        ->and(Chapter::scheduled()->count())->toBe(1);

    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee($this->series->title)
        ->assertDontSee($chapter->title);

    $this->get(route('browse'))
        ->assertOk()
        ->assertDontSee($this->series->title);

    $this->get(route('reader', [$this->series, $chapter->displayNumber()]))
        ->assertNotFound();
});

test('due command publishes once and emits the publication hook once', function () {
    $publishedHooks = 0;
    Hook::addAction('chapter.published', function () use (&$publishedHooks) {
        $publishedHooks++;
    });

    $chapter = $this->series->chapters()->create(['number' => 2]);
    $scheduledFor = now()->addMinutes(10);
    $chapter->scheduleFor($scheduledFor);

    $this->artisan('koma:publish-scheduled')
        ->expectsOutput('Published 0 scheduled chapters.')
        ->assertSuccessful();

    $this->travelTo($scheduledFor->copy()->addSecond());

    $this->artisan('koma:publish-scheduled')
        ->expectsOutput('Published 1 scheduled chapter.')
        ->assertSuccessful();

    expect($chapter->fresh()->status)->toBe(Chapter::STATUS_PUBLISHED)
        ->and($chapter->fresh()->published_at->equalTo($scheduledFor))->toBeTrue()
        ->and($publishedHooks)->toBe(1)
        ->and(Chapter::published()->count())->toBe(1);

    $this->artisan('koma:publish-scheduled')
        ->expectsOutput('Published 0 scheduled chapters.')
        ->assertSuccessful();

    expect($publishedHooks)->toBe(1);
});

test('scheduling rejects past times and immediate publishing replaces a future time', function () {
    $publishedHooks = 0;
    Hook::addAction('chapter.published', function () use (&$publishedHooks) {
        $publishedHooks++;
    });

    $chapter = $this->series->chapters()->create(['number' => 3]);

    expect(fn () => $chapter->scheduleFor(now()->subMinute()))
        ->toThrow(InvalidArgumentException::class);

    $chapter->scheduleFor(now()->addHour());
    $chapter->publish();

    expect($chapter->status)->toBe(Chapter::STATUS_PUBLISHED)
        ->and($chapter->published_at->equalTo(now()))->toBeTrue()
        ->and($publishedHooks)->toBe(1);

    $chapter->publish();
    expect($publishedHooks)->toBe(1);
});

test('uploader can schedule a complete chapter and validates future time', function () {
    $past = now()->subMinute()->format('Y-m-d\TH:i');

    Livewire::actingAs($this->editor)
        ->test(Uploader::class)
        ->set('seriesId', $this->series->id)
        ->set('number', '4')
        ->set('incoming', [UploadedFile::fake()->image('page.jpg', 800, 1200)])
        ->set('publishAt', $past)
        ->call('schedule')
        ->assertHasErrors('publishAt');

    expect(Chapter::query()->count())->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBe([]);

    $future = now()->addHours(2)->format('Y-m-d\TH:i');

    Livewire::actingAs($this->editor)
        ->test(Uploader::class)
        ->set('seriesId', $this->series->id)
        ->set('number', '4')
        ->set('incoming', [UploadedFile::fake()->image('page.jpg', 800, 1200)])
        ->set('publishAt', $future)
        ->call('schedule')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.series.edit', $this->series));

    $chapter = Chapter::query()->sole();

    expect($chapter->status)->toBe(Chapter::STATUS_SCHEDULED)
        ->and($chapter->published_at->equalTo(CarbonImmutable::parse($future, config('app.timezone'))->utc()))->toBeTrue()
        ->and($chapter->pages)->toHaveCount(1);
});

test('series editor can reschedule cancel and publish a scheduled chapter now', function () {
    $chapter = $this->series->chapters()->create(['number' => 5]);
    $chapter->scheduleFor(now()->addHour());
    $rescheduled = now()->addHours(3)->format('Y-m-d\TH:i');

    Livewire::actingAs($this->editor)
        ->test(Edit::class, ['series' => $this->series])
        ->assertSet("scheduleTimes.{$chapter->id}", now()->addHour()->format('Y-m-d\TH:i'))
        ->set("scheduleTimes.{$chapter->id}", $rescheduled)
        ->call('scheduleChapter', $chapter->id)
        ->assertHasNoErrors();

    expect($chapter->fresh()->published_at->equalTo(CarbonImmutable::parse($rescheduled)->utc()))->toBeTrue();

    Livewire::actingAs($this->editor)
        ->test(Edit::class, ['series' => $this->series])
        ->call('cancelSchedule', $chapter->id)
        ->assertSet("scheduleTimes.{$chapter->id}", null);

    expect($chapter->fresh()->status)->toBe(Chapter::STATUS_DRAFT)
        ->and($chapter->fresh()->published_at)->toBeNull();

    $chapter->scheduleFor(now()->addDay());

    Livewire::actingAs($this->editor)
        ->test(Edit::class, ['series' => $this->series])
        ->call('togglePublish', $chapter->id);

    expect($chapter->fresh()->status)->toBe(Chapter::STATUS_PUBLISHED)
        ->and($chapter->fresh()->published_at->equalTo(now()))->toBeTrue();
});
