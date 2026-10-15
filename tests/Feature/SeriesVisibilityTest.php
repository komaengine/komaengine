<?php

use App\Livewire\Admin\Series\Edit;
use App\Livewire\Admin\Series\Index;
use App\Models\Chapter;
use App\Models\Series;
use App\Models\User;
use App\Support\MatureContentAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->publicSeries = Series::create([
        'title' => 'Visible Workshop',
        'slug' => 'visible-workshop',
        'author' => 'Koma Test',
        'content_rating' => 'safe',
        'is_public' => true,
        'synopsis' => 'Public catalog copy.',
        'views' => 20,
    ]);
    $this->publicChapter = Chapter::create([
        'series_id' => $this->publicSeries->id,
        'number' => 1,
        'title' => 'Visible chapter update',
        'status' => 'published',
        'published_at' => now()->subDay(),
    ]);

    $this->privateSeries = Series::create([
        'title' => 'Hidden Workshop',
        'slug' => 'hidden-workshop',
        'author' => 'Koma Test',
        'content_rating' => 'safe',
        'is_public' => false,
        'synopsis' => 'Private metadata must never reach readers.',
        'views' => 100,
    ]);
    $this->privateChapter = Chapter::create([
        'series_id' => $this->privateSeries->id,
        'number' => 987,
        'title' => 'Hidden chapter update',
        'status' => 'published',
        'published_at' => now(),
    ]);
});

test('the database and model default preserve public visibility for existing creation paths', function () {
    DB::table('series')->insert([
        'title' => 'Raw Legacy Import',
        'slug' => 'raw-legacy-import',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $series = Series::create([
        'title' => 'Legacy Import',
        'slug' => 'legacy-import',
    ]);

    expect((bool) DB::table('series')->where('slug', 'raw-legacy-import')->value('is_public'))->toBeTrue()
        ->and($series->is_public)->toBeTrue()
        ->and($series->fresh()->is_public)->toBeTrue();
});

test('public catalogs and latest updates exclude private series', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee($this->publicSeries->title)
        ->assertSee('Chapter 1')
        ->assertDontSee($this->privateSeries->title)
        ->assertDontSee('Chapter 987');

    $this->get(route('browse'))
        ->assertOk()
        ->assertSee($this->publicSeries->title)
        ->assertDontSee($this->privateSeries->title)
        ->assertSee('1 series');
});

test('anonymous readers receive metadata-free not found responses for private urls', function () {
    $seriesPath = route('series.show', $this->privateSeries, false);
    $readerPath = route('reader', [$this->privateSeries, $this->privateChapter->displayNumber()], false);

    foreach ([$seriesPath, $readerPath] as $path) {
        $this->get($path)
            ->assertNotFound()
            ->assertDontSee($this->privateSeries->title)
            ->assertDontSee($this->privateSeries->synopsis);

        $this->getJson($path)
            ->assertNotFound()
            ->assertJsonMissing(['title' => $this->privateSeries->title])
            ->assertDontSee($this->privateSeries->synopsis);
    }
});

test('authenticated readers without series editing permission cannot preview private urls', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)
        ->get(route('series.show', $this->privateSeries))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('reader', [$this->privateSeries, $this->privateChapter->displayNumber()]))
        ->assertNotFound();
})->with(['Member', 'Uploader', 'Moderator']);

test('editors and administrators can preview private series and published chapters', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)
        ->get(route('series.show', $this->privateSeries))
        ->assertOk()
        ->assertSee('Private preview')
        ->assertSee($this->privateSeries->title)
        ->assertSee($this->privateChapter->title);

    $this->actingAs($user)
        ->get(route('reader', [$this->privateSeries, $this->privateChapter->displayNumber()]))
        ->assertOk()
        ->assertSee('Private preview')
        ->assertSee($this->privateSeries->title);
})->with(['Editor', 'Administrator']);

test('private visibility is checked before the mature content gate', function () {
    $this->privateSeries->update(['content_rating' => 'mature']);

    $this->get(route('series.show', $this->privateSeries))
        ->assertNotFound()
        ->assertSessionMissing(MatureContentAccess::INTENDED_SESSION_KEY);

    $this->getJson(route('series.show', $this->privateSeries))
        ->assertNotFound()
        ->assertJsonMissing(['confirmation_url' => route('mature.confirmation')]);
});

test('new series default private in the editor and can be made public', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');

    Livewire::actingAs($editor)
        ->test(Edit::class)
        ->assertSet('is_public', false)
        ->set('title', 'Prepared in Private')
        ->call('save')
        ->assertHasNoErrors();

    $created = Series::query()->where('title', 'Prepared in Private')->sole();

    expect($created->is_public)->toBeFalse();

    Livewire::actingAs($editor)
        ->test(Edit::class, ['series' => $created])
        ->assertSet('is_public', false)
        ->set('is_public', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($created->fresh()->is_public)->toBeTrue();
});

test('the admin series index clearly labels public and private entries', function () {
    $editor = User::factory()->create();
    $editor->assignRole('Editor');

    Livewire::actingAs($editor)
        ->test(Index::class)
        ->assertSee($this->publicSeries->title)
        ->assertSee($this->privateSeries->title)
        ->assertSee('Public')
        ->assertSee('Private');
});

test('public series and reader urls remain available to everyone', function () {
    $this->get(route('series.show', $this->publicSeries))
        ->assertOk()
        ->assertSee($this->publicSeries->title)
        ->assertDontSee('Private preview');

    $this->get(route('reader', [$this->publicSeries, $this->publicChapter->displayNumber()]))
        ->assertOk()
        ->assertDontSee('Private preview');
});
