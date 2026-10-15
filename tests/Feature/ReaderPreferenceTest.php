<?php

use App\Livewire\Admin\Series\Edit;
use App\Models\Chapter;
use App\Models\Page;
use App\Models\Series;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->series = Series::create([
        'title' => 'Reader Preference Workshop',
        'slug' => 'reader-preference-workshop',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
        'is_public' => true,
    ]);
    $this->chapter = Chapter::create([
        'series_id' => $this->series->id,
        'number' => 1,
        'title' => 'Presentation choices',
        'status' => 'published',
        'published_at' => now()->subMinute(),
    ]);
    Page::create([
        'chapter_id' => $this->chapter->id,
        'sort_order' => 0,
        'image_path' => 'reader-preferences/page-001.webp',
        'width' => 800,
        'height' => 1200,
        'file_size' => 1000,
    ]);
});

test('saved user mode overrides the series default and page-order direction', function () {
    $this->series->update([
        'reading_direction' => 'rtl',
        'default_reader_mode' => 'ltr',
    ]);
    $member = User::factory()->create(['reader_mode' => 'vertical']);

    $this->actingAs($member)
        ->get(route('reader', [$this->series, $this->chapter->displayNumber()]))
        ->assertOk()
        ->assertSee('"initialMode":"vertical"', false)
        ->assertSee('"authenticated":true', false)
        ->assertSee('aria-pressed="true" class="on">Webtoon', false);
});

test('series mode overrides page-order direction when a user has no preference', function () {
    $this->series->update([
        'reading_direction' => 'rtl',
        'default_reader_mode' => 'ltr',
    ]);
    $member = User::factory()->create(['reader_mode' => null]);

    $this->actingAs($member)
        ->get(route('reader', [$this->series, $this->chapter->displayNumber()]))
        ->assertOk()
        ->assertSee('"initialMode":"ltr"', false)
        ->assertSee('aria-pressed="true" class="on">LTR', false);
});

test('page-order direction is the final server fallback for guests and users', function (string $direction) {
    $this->series->update([
        'reading_direction' => $direction,
        'default_reader_mode' => null,
    ]);

    $this->get(route('reader', [$this->series, $this->chapter->displayNumber()]))
        ->assertOk()
        ->assertSee('"initialMode":"'.$direction.'"', false)
        ->assertSee('"authenticated":false', false)
        ->assertSee('Changes are saved on this device.');

    $member = User::factory()->create(['reader_mode' => null]);

    $this->actingAs($member)
        ->get(route('reader', [$this->series, $this->chapter->displayNumber()]))
        ->assertOk()
        ->assertSee('"initialMode":"'.$direction.'"', false);
})->with(['rtl', 'ltr', 'vertical']);

test('the fallback theme renders an explicit empty reader state when a chapter has no pages', function () {
    $this->chapter->pages()->delete();

    $this->get(route('reader', [$this->series, $this->chapter->displayNumber()]))
        ->assertOk()
        ->assertSee('No pages available.')
        ->assertSee('"pages":[]', false)
        ->assertSee('style="display:none"', false);
});

test('an authenticated reader can persist only their own valid mode', function () {
    $member = User::factory()->create(['reader_mode' => null]);
    $otherMember = User::factory()->create(['reader_mode' => 'ltr']);

    $this->actingAs($member)
        ->putJson(route('reader.preferences.mode'), ['mode' => 'vertical'])
        ->assertOk()
        ->assertExactJson(['mode' => 'vertical']);

    expect($member->fresh()->reader_mode)->toBe('vertical')
        ->and($otherMember->fresh()->reader_mode)->toBe('ltr');
});

test('anonymous preference updates require authentication', function () {
    $this->putJson(route('reader.preferences.mode'), ['mode' => 'ltr'])
        ->assertUnauthorized();
});

test('invalid preference updates are rejected without changing the account', function () {
    $member = User::factory()->create(['reader_mode' => 'rtl']);

    $this->actingAs($member)
        ->putJson(route('reader.preferences.mode'), ['mode' => 'sideways'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('mode');

    expect($member->fresh()->reader_mode)->toBe('rtl');
});

test('the preference endpoint stays inside the authenticated csrf-protected web stack', function () {
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn (Route $candidate) => $candidate->getName() === 'reader.preferences.mode');
    $middleware = app('router')->gatherRouteMiddleware($route);

    expect($route)->not->toBeNull()
        ->and($route->methods())->toContain('PUT')
        ->and($route->uri())->toBe('reader/preferences/mode')
        ->and($middleware)->toContain(Authenticate::class)
        ->and($middleware)->toContain(ValidateCsrfToken::class);
});

test('editors can set clear and validate the series reader default', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');

    Livewire::actingAs($editor)
        ->test(Edit::class, ['series' => $this->series])
        ->assertSet('default_reader_mode', null)
        ->set('default_reader_mode', 'vertical')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->series->fresh()->default_reader_mode)->toBe('vertical');

    Livewire::actingAs($editor)
        ->test(Edit::class, ['series' => $this->series->fresh()])
        ->set('default_reader_mode', 'sideways')
        ->call('save')
        ->assertHasErrors(['default_reader_mode' => 'in']);

    expect($this->series->fresh()->default_reader_mode)->toBe('vertical');

    Livewire::actingAs($editor)
        ->test(Edit::class, ['series' => $this->series->fresh()])
        ->set('default_reader_mode', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->series->fresh()->default_reader_mode)->toBeNull();
});
