<?php

use App\Engine\Settings\Settings;
use App\Models\Chapter;
use App\Models\Series;
use App\Support\MatureContentAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    app(Settings::class)->set('mature_gate', true);

    $this->safeSeries = Series::create([
        'title' => 'Safe Harbor Stories',
        'slug' => 'safe-harbor-stories',
        'author' => 'Koma Test',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
        'synopsis' => 'A safe series used to test public visibility.',
        'views' => 10,
    ]);
    $this->safeChapter = Chapter::create([
        'series_id' => $this->safeSeries->id,
        'number' => 1,
        'title' => 'Safe beginning',
        'status' => 'published',
        'published_at' => now()->subDay(),
    ]);

    $this->matureSeries = Series::create([
        'title' => 'Restricted Midnight Stories',
        'slug' => 'restricted-midnight-stories',
        'author' => 'Koma Test',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'mature',
        'synopsis' => 'Mature metadata must not leak through the confirmation screen.',
        'views' => 100,
    ]);
    $this->matureChapter = Chapter::create([
        'series_id' => $this->matureSeries->id,
        'number' => 1,
        'title' => 'Restricted beginning',
        'status' => 'published',
        'published_at' => now(),
    ]);
});

test('unconfirmed sessions see only safe series in public catalogs', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee($this->safeSeries->title)
        ->assertDontSee($this->matureSeries->title)
        ->assertSee('Mature titles are hidden.');

    $this->get(route('browse'))
        ->assertOk()
        ->assertSee($this->safeSeries->title)
        ->assertDontSee($this->matureSeries->title)
        ->assertSee('1 series')
        ->assertSee('Review access');
});

test('direct mature series and reader requests require confirmation', function () {
    $seriesPath = route('series.show', $this->matureSeries, false);
    $readerPath = route('reader', [$this->matureSeries, $this->matureChapter->displayNumber()], false);

    $this->get($seriesPath)
        ->assertRedirect(route('mature.confirmation'))
        ->assertSessionHas(MatureContentAccess::INTENDED_SESSION_KEY, $seriesPath);

    $this->get($readerPath)
        ->assertRedirect(route('mature.confirmation'))
        ->assertSessionHas(MatureContentAccess::INTENDED_SESSION_KEY, $readerPath);

    $this->getJson($seriesPath)
        ->assertForbidden()
        ->assertJson([
            'message' => 'Age confirmation is required to access this series.',
            'confirmation_url' => route('mature.confirmation'),
        ]);
});

test('confirmation returns to the intended local page and unlocks the session', function () {
    $seriesPath = route('series.show', $this->matureSeries, false);

    $this->get($seriesPath)->assertRedirect(route('mature.confirmation'));

    $this->get(route('mature.confirmation'))
        ->assertOk()
        ->assertSee('Age confirmation required')
        ->assertSee('I am 18 or older')
        ->assertDontSee($this->matureSeries->title)
        ->assertDontSee($this->matureSeries->synopsis);

    $this->post(route('mature.confirm'))
        ->assertRedirect($seriesPath)
        ->assertSessionHas(MatureContentAccess::CONFIRMED_SESSION_KEY, true)
        ->assertSessionMissing(MatureContentAccess::INTENDED_SESSION_KEY);

    $this->get($seriesPath)
        ->assertOk()
        ->assertSee($this->matureSeries->title);

    $this->get(route('reader', [$this->matureSeries, $this->matureChapter->displayNumber()]))
        ->assertOk()
        ->assertSee('Chapter '.$this->matureChapter->displayNumber());

    $this->get(route('browse'))
        ->assertOk()
        ->assertSee($this->matureSeries->title)
        ->assertDontSee('Mature titles are hidden.');
});

test('declining clears intended access and keeps mature content hidden', function () {
    $seriesPath = route('series.show', $this->matureSeries, false);

    $this->get($seriesPath)->assertRedirect(route('mature.confirmation'));

    $this->post(route('mature.decline'))
        ->assertRedirect(route('home'))
        ->assertSessionMissing(MatureContentAccess::CONFIRMED_SESSION_KEY)
        ->assertSessionMissing(MatureContentAccess::INTENDED_SESSION_KEY);

    $this->get($seriesPath)->assertRedirect(route('mature.confirmation'));
});

test('mature gate can be disabled without restricting content', function () {
    app(Settings::class)->set('mature_gate', false);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee($this->matureSeries->title)
        ->assertDontSee('Mature titles are hidden.');

    $this->get(route('series.show', $this->matureSeries))
        ->assertOk()
        ->assertSee($this->matureSeries->title);

    $this->get(route('reader', [$this->matureSeries, $this->matureChapter->displayNumber()]))
        ->assertOk();

    $this->get(route('mature.confirmation'))->assertRedirect(route('home'));
});

test('confirmation rejects external return destinations', function (string $returnTo) {
    $this->get(route('mature.confirmation', ['return_to' => $returnTo]))
        ->assertOk()
        ->assertSessionMissing(MatureContentAccess::INTENDED_SESSION_KEY);

    $this->post(route('mature.confirm'))
        ->assertRedirect(route('home'));
})->with([
    'absolute URL' => 'https://example.test/phishing',
    'protocol-relative URL' => '//example.test/phishing',
    'backslash URL' => '/\\example.test/phishing',
    'control-character URL' => "/safe\nLocation: https://example.test/phishing",
]);

test('safe series remain directly accessible while the gate is enabled', function () {
    $this->get(route('series.show', $this->safeSeries))
        ->assertOk()
        ->assertSee($this->safeSeries->title);

    $this->get(route('reader', [$this->safeSeries, $this->safeChapter->displayNumber()]))
        ->assertOk();
});
