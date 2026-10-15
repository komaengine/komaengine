<?php

use App\Engine\Analytics\CommunityAnalyticsEvent;
use App\Engine\Settings\Settings;
use App\Models\Chapter;
use App\Models\Series;
use App\Models\User;
use App\Support\Facades\Hook;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(Settings::class)->set('installed_at', now()->toIso8601String());
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function communityAnalyticsSeries(array $overrides = []): Series
{
    $series = Series::create(array_replace([
        'title' => 'Community Analytics Test',
        'slug' => 'community-analytics-test',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
        'is_public' => true,
        'views' => 7,
    ], $overrides));

    Chapter::create([
        'series_id' => $series->id,
        'number' => 1,
        'status' => Chapter::STATUS_PUBLISHED,
        'published_at' => now()->subMinute(),
        'views' => 3,
    ]);

    return $series;
}

test('an authorized public series detail emits the exact privacy-minimized event', function () {
    CarbonImmutable::setTestNow('2026-09-02 12:34:56 UTC');
    $series = communityAnalyticsSeries();
    $events = [];

    Hook::addAction('analytics.event', function (CommunityAnalyticsEvent $event) use (&$events): void {
        $events[] = $event;
    });

    $this->get(route('series.show', $series))->assertOk();

    expect($events)->toHaveCount(1);

    $event = $events[0];
    expect($event)->toBeInstanceOf(CommunityAnalyticsEvent::class)
        ->and(array_keys(get_object_vars($event)))->toBe(['name', 'occurredAt', 'seriesId', 'chapterId', 'authenticated'])
        ->and($event->name)->toBe('series.view')
        ->and($event->occurredAt->format('Y-m-d\\TH:i:s\\Z'))->toBe('2026-09-02T12:34:56Z')
        ->and($event->occurredAt->getTimezone()->getName())->toBe('UTC')
        ->and($event->seriesId)->toBe($series->id)
        ->and($event->chapterId)->toBeNull()
        ->and($event->authenticated)->toBeFalse();
});

test('an authorized public reader view preserves counters and existing hooks before publishing analytics', function () {
    CarbonImmutable::setTestNow('2026-09-02 12:34:56 UTC');
    $series = communityAnalyticsSeries();
    $chapter = $series->chapters()->sole();
    $readerViews = 0;
    $events = [];
    $order = [];
    $member = User::factory()->create();

    Hook::addAction('reader.view', function () use (&$readerViews, &$order): void {
        $readerViews++;
        $order[] = 'reader.view';
    });
    Hook::addAction('analytics.event', function (CommunityAnalyticsEvent $event) use (&$events, &$order): void {
        $events[] = $event;
        $order[] = 'analytics.event';
    });

    $this->actingAs($member)
        ->get(route('reader', [$series, $chapter->displayNumber()]))
        ->assertOk();

    expect($readerViews)->toBe(1)
        ->and($order)->toBe(['reader.view', 'analytics.event'])
        ->and($series->fresh()->views)->toBe(8)
        ->and($chapter->fresh()->views)->toBe(4)
        ->and($events)->toHaveCount(1);

    $event = $events[0];
    expect($event->name)->toBe('reader.view')
        ->and($event->occurredAt->format('Y-m-d\\TH:i:s\\Z'))->toBe('2026-09-02T12:34:56Z')
        ->and($event->seriesId)->toBe($series->id)
        ->and($event->chapterId)->toBe($chapter->id)
        ->and($event->authenticated)->toBeTrue();
});

test('denied private and unconfirmed mature requests do not publish analytics', function () {
    $private = communityAnalyticsSeries([
        'title' => 'Private Analytics Test',
        'slug' => 'private-analytics-test',
        'is_public' => false,
    ]);
    $mature = communityAnalyticsSeries([
        'title' => 'Mature Analytics Test',
        'slug' => 'mature-analytics-test',
        'content_rating' => 'mature',
    ]);
    $events = [];

    app(Settings::class)->set('mature_gate', true);
    Hook::addAction('analytics.event', function (CommunityAnalyticsEvent $event) use (&$events): void {
        $events[] = $event;
    });

    $this->get(route('series.show', $private))->assertNotFound();
    $this->get(route('reader', [$private, $private->chapters()->sole()->displayNumber()]))->assertNotFound();
    $this->get(route('series.show', $mature))->assertRedirect(route('mature.confirmation'));
    $this->get(route('reader', [$mature, $mature->chapters()->sole()->displayNumber()]))->assertRedirect(route('mature.confirmation'));

    expect($events)->toBe([]);
});

test('private staff previews do not publish public analytics', function () {
    $this->seed(RolePermissionSeeder::class);
    $private = communityAnalyticsSeries([
        'title' => 'Preview Analytics Test',
        'slug' => 'preview-analytics-test',
        'is_public' => false,
    ]);
    $events = [];
    $editor = User::factory()->create();
    $editor->assignRole('Editor');

    Hook::addAction('analytics.event', function (CommunityAnalyticsEvent $event) use (&$events): void {
        $events[] = $event;
    });

    $this->actingAs($editor)
        ->get(route('series.show', $private))
        ->assertOk();
    $this->actingAs($editor)
        ->get(route('reader', [$private, $private->chapters()->sole()->displayNumber()]))
        ->assertOk();

    expect($events)->toBe([]);
});
