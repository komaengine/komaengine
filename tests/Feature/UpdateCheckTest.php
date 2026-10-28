<?php

use App\Engine\Settings\Settings;
use App\Engine\Updates\UpdateCheck;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Updates;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const UPDATE_FEED_URL = 'https://komaengine.com/releases/latest.json';

beforeEach(function () {
    Http::preventStrayRequests();
    config(['koma.version' => '1.1.0']);
    $this->seed(RolePermissionSeeder::class);
    app(Settings::class)->set('installed_at', now()->toIso8601String());
    $this->admin = User::factory()->create();
    $this->admin->assignRole('Administrator');
});

/** @return array<string, string> */
function feedFor(string $version): array
{
    return [
        'version' => $version,
        'tag' => 'v'.$version,
        'released' => '2026-11-02',
        'release_url' => 'https://github.com/komaengine/komaengine/releases/tag/v'.$version,
    ];
}

function turnOnUpdateChecks(): void
{
    app(Settings::class)->set(UpdateCheck::SETTING, true);
}

test('checks are off until an administrator turns them on, and nothing is fetched while off', function () {
    Http::fake();

    app(UpdateCheck::class)->runIfDue();
    Artisan::call('schedule:test', ['--name' => 'koma:update-check']);

    expect(app(UpdateCheck::class)->enabled())->toBeFalse();
    Http::assertNothingSent();
});

test('the check is one GET of the feed with no query string, cookies, or site details', function () {
    Http::fake([UPDATE_FEED_URL => Http::response(feedFor('1.2.0'))]);
    turnOnUpdateChecks();

    app(UpdateCheck::class)->runIfDue();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === UPDATE_FEED_URL
        && $request->header('User-Agent') === ['KomaEngine']
        && ! $request->hasHeader('Cookie')
        && ! $request->hasHeader('Referer')
        && $request->body() === '');
});

test('a newer stable version is reported as an update', function () {
    $this->travelTo('2026-11-03 09:00:00');
    Http::fake([UPDATE_FEED_URL => Http::response(feedFor('1.2.0'))]);
    turnOnUpdateChecks();

    app(UpdateCheck::class)->runIfDue();
    $status = app(UpdateCheck::class)->status();

    expect($status['update_available'])->toBeTrue()
        ->and($status['latest']['version'])->toBe('1.2.0')
        ->and($status['checked_at']->toIso8601String())->toBe('2026-11-03T09:00:00+00:00')
        ->and($status['failed'])->toBeFalse();
});

test('the same or an older version is not an update', function (string $version) {
    Http::fake([UPDATE_FEED_URL => Http::response(feedFor($version))]);
    turnOnUpdateChecks();

    app(UpdateCheck::class)->runIfDue();
    $status = app(UpdateCheck::class)->status();

    expect($status['update_available'])->toBeFalse()
        ->and($status['latest']['version'])->toBe($version);
})->with(['same' => '1.1.0', 'older' => '1.0.1']);

test('a malformed, untrusted, or failed answer is ignored silently', function (Closure $response) {
    Http::fake([UPDATE_FEED_URL => $response]);
    turnOnUpdateChecks();

    app(UpdateCheck::class)->runIfDue();
    $status = app(UpdateCheck::class)->status();

    expect($status['failed'])->toBeTrue()
        ->and($status['latest'])->toBeNull()
        ->and($status['update_available'])->toBeFalse()
        ->and($status['attempted_at'])->not->toBeNull();
})->with([
    'not JSON' => [fn () => Http::response('<html>maintenance</html>')],
    'prerelease' => [fn () => Http::response(feedFor('1.2.0-rc.1'))],
    'tag mismatch' => [fn () => Http::response(['tag' => 'v9.9.9'] + feedFor('1.2.0'))],
    'link elsewhere' => [fn () => Http::response(['release_url' => 'https://example.com/update'] + feedFor('1.2.0'))],
    'server error' => [fn () => Http::response('', 503)],
    'redirect' => [fn () => Http::response('', 302, ['Location' => 'https://example.com/latest.json'])],
    'timeout' => [fn () => throw new ConnectionException('Connection timed out')],
]);

test('a failed check keeps the last good answer and says when it succeeded', function () {
    $this->travelTo('2026-11-01 09:00:00');
    Http::fake([UPDATE_FEED_URL => Http::sequence()->push(feedFor('1.2.0'))->pushStatus(503)]);
    turnOnUpdateChecks();
    app(UpdateCheck::class)->runIfDue();

    $this->travelTo('2026-11-02 10:00:00');
    app(UpdateCheck::class)->runIfDue();
    $status = app(UpdateCheck::class)->status();

    expect($status['failed'])->toBeTrue()
        ->and($status['update_available'])->toBeTrue()
        ->and($status['checked_at']->toIso8601String())->toBe('2026-11-01T09:00:00+00:00');
});

test('the feed is fetched at most once a day', function () {
    $this->travelTo('2026-11-01 09:00:00');
    Http::fake([UPDATE_FEED_URL => Http::response(feedFor('1.1.0'))]);
    turnOnUpdateChecks();

    app(UpdateCheck::class)->runIfDue();
    $this->travelTo('2026-11-02 08:59:00');
    app(UpdateCheck::class)->runIfDue();
    Http::assertSentCount(1);

    $this->travelTo('2026-11-02 09:01:00');
    app(UpdateCheck::class)->runIfDue();
    Http::assertSentCount(2);
});

test('KOMA_UPDATE_CHECK=false wins over the administrator setting', function () {
    Http::fake();
    config(['koma.updates.allowed' => false]);
    turnOnUpdateChecks();

    app(UpdateCheck::class)->runIfDue();

    Livewire::actingAs($this->admin)->test(Updates::class)
        ->assertSee('Turned off on this server')
        ->assertSee('KOMA_UPDATE_CHECK=false')
        ->assertDontSee('Enable update checks')
        ->call('enableChecks');

    expect(app(UpdateCheck::class)->enabled())->toBeFalse();
    Http::assertNothingSent();
});

test('an administrator turns checks on and off on the Updates page', function () {
    Http::fake([UPDATE_FEED_URL => Http::response(feedFor('1.2.0'))]);

    Livewire::actingAs($this->admin)->test(Updates::class)
        ->assertSee('sends nothing about your site')
        ->assertSee('after 14 days')
        ->assertDontSeeHtml('data-testid="update-notice"')
        ->call('enableChecks')
        ->assertSee('Last checked')
        ->assertSeeHtml('data-testid="update-notice"')
        ->assertSee('KomaEngine 1.2.0 is available. This site runs 1.1.0.')
        ->assertSeeHtml('https://github.com/komaengine/komaengine/releases/tag/v1.2.0')
        ->assertSeeHtml('https://github.com/komaengine/komaengine/blob/main/docs/install/shared-hosting.md#update-and-rollback')
        ->call('disableChecks')
        ->assertSee('Enable update checks')
        ->assertDontSeeHtml('data-testid="update-notice"');

    expect(app(UpdateCheck::class)->enabled())->toBeFalse()
        ->and(cache()->get(UpdateCheck::CACHE_KEY))->toBeNull();
    Http::assertSentCount(1);
});

test('the dashboard shows the notice to administrators only', function () {
    Http::fake([UPDATE_FEED_URL => Http::response(feedFor('1.2.0'))]);
    turnOnUpdateChecks();
    app(UpdateCheck::class)->runIfDue();

    Livewire::actingAs($this->admin)->test(Dashboard::class)
        ->assertSee('KomaEngine 1.2.0 is available.');

    $editor = User::factory()->create();
    $editor->assignRole('Editor');

    Livewire::actingAs($editor)->test(Dashboard::class)
        ->assertDontSee('is available.');

    Livewire::actingAs($editor)->test(Updates::class)->call('enableChecks')->assertForbidden();
});

test('the health page reports the update check', function () {
    Http::fake([UPDATE_FEED_URL => Http::response(feedFor('1.2.0'))]);

    $this->actingAs($this->admin)->get(route('admin.health'))
        ->assertSeeInOrder(['Update checks', 'Off'], false);

    turnOnUpdateChecks();
    app(UpdateCheck::class)->runIfDue();

    $this->actingAs($this->admin)->get(route('admin.health'))
        ->assertSee('1.2.0 is available');
});
