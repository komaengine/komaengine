<?php

use App\Engine\Settings\Settings;
use App\Engine\Themes\ThemeManager;
use App\Models\Chapter;
use App\Models\Series;
use App\Support\Facades\Hook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\HtmlString;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(Settings::class)->setMany([
        'active_theme' => 'aozora',
        'installed_at' => now()->toIso8601String(),
    ]);
    app(ThemeManager::class)->registerViews();
});

function communitySlotSeries(): Series
{
    $series = Series::create([
        'title' => 'Community Slot Test',
        'slug' => 'community-slot-test',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
        'is_public' => true,
    ]);

    Chapter::create([
        'series_id' => $series->id,
        'number' => 1,
        'status' => Chapter::STATUS_PUBLISHED,
        'published_at' => now()->subMinute(),
    ]);

    return $series;
}

test('disabled Community slots produce no markup and do not call plugins', function () {
    $called = false;

    Hook::addFilter('monetization.slot', function () use (&$called): Htmlable {
        $called = true;

        return new HtmlString('<p>Should not render</p>');
    });

    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('data-community-slot', false)
        ->assertDontSee('Should not render');

    expect($called)->toBeFalse();
});

test('Community slots escape strings and emit only Htmlable content as markup', function () {
    app(Settings::class)->set('community_ads_enabled', true);

    Hook::addFilter('monetization.slot', function (Htmlable $content, string $slot): Htmlable|string {
        return $slot === 'header'
            ? '<strong>Ordinary slot string</strong>'
            : new HtmlString('<strong data-community-trusted="footer">Trusted footer slot</strong>');
    });

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('&lt;strong&gt;Ordinary slot string&lt;/strong&gt;', false)
        ->assertDontSee('<strong>Ordinary slot string</strong>', false)
        ->assertSee('<strong data-community-trusted="footer">Trusted footer slot</strong>', false);
});

test('Community slots expose minimal context once per zone in every built-in theme', function () {
    $settings = app(Settings::class);
    $themes = app(ThemeManager::class);
    $calls = [];

    $settings->set('community_ads_enabled', true);
    Hook::addFilter('monetization.slot', function (Htmlable $content, string $slot, array $context) use (&$calls): Htmlable {
        $calls[] = compact('slot', 'context');

        return new HtmlString('<span>Community '.$slot.' slot</span>');
    });

    foreach (['aozora', 'kasai', 'sumi', 'tankobon'] as $theme) {
        $settings->set('active_theme', $theme);
        $themes->registerViews();

        $content = $this->get(route('home'))->assertOk()->getContent();

        expect(substr_count($content, 'data-community-slot="header"'))->toBe(1)
            ->and(substr_count($content, 'data-community-slot="footer"'))->toBe(1);
    }

    expect($calls)->toHaveCount(8)
        ->and(collect($calls)->pluck('slot')->countBy()->all())->toBe(['header' => 4, 'footer' => 4])
        ->and(collect($calls)->pluck('context')->unique()->values()->all())->toBe([['route_name' => 'home']]);
});

test('Community slots include the route and public series identifier on series pages', function () {
    $series = communitySlotSeries();
    $calls = [];

    app(Settings::class)->set('community_ads_enabled', true);
    Hook::addFilter('monetization.slot', function (Htmlable $content, string $slot, array $context) use (&$calls): Htmlable {
        $calls[] = compact('slot', 'context');

        return new HtmlString('slot');
    });

    $this->get(route('series.show', $series))->assertOk();

    expect(collect($calls)->pluck('context')->unique()->values()->all())->toBe([[
        'route_name' => 'series.show',
        'series_id' => $series->id,
    ]]);
});
