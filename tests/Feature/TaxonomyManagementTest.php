<?php

use App\Livewire\Admin\Genres;
use App\Livewire\Admin\Tags;
use App\Models\Genre;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->editor = User::factory()->create();
    $this->editor->assignRole('Editor');
    $this->member = User::factory()->create();
    $this->member->assignRole('Member');
});

test('genres and tags have separate screens and records', function () {
    Genre::create(['name' => 'Fantasy']);
    Tag::create(['name' => 'Found Family']);

    $this->actingAs($this->editor)
        ->get(route('admin.genres'))
        ->assertOk()
        ->assertSee('Add a genre')
        ->assertSee('Fantasy')
        ->assertDontSee('Add a tag')
        ->assertDontSee('Found Family');

    $this->actingAs($this->editor)
        ->get(route('admin.tags'))
        ->assertOk()
        ->assertSee('Add a tag')
        ->assertSee('Found Family')
        ->assertDontSee('Add a genre')
        ->assertDontSee('Fantasy');
});

test('editors can add and remove tags without changing genres', function () {
    $genre = Genre::create(['name' => 'Drama']);

    $component = Livewire::actingAs($this->editor)
        ->test(Tags::class)
        ->set('name', 'Slow Burn')
        ->call('add')
        ->assertHasNoErrors()
        ->assertSet('name', '');

    $tag = Tag::query()->sole();

    expect($tag->name)->toBe('Slow Burn')
        ->and($tag->slug)->toBe('slow-burn')
        ->and($genre->fresh())->not->toBeNull();

    $component
        ->call('remove', $tag->id)
        ->assertHasNoErrors();

    expect(Tag::query()->count())->toBe(0)
        ->and(Genre::query()->count())->toBe(1);
});

test('taxonomy validation keeps genre and tag names independent', function () {
    Genre::create(['name' => 'Fantasy']);
    Tag::create(['name' => 'Fantasy']);

    Livewire::actingAs($this->editor)
        ->test(Tags::class)
        ->set('name', 'Fantasy')
        ->call('add')
        ->assertHasErrors(['name' => 'unique']);

    Livewire::actingAs($this->editor)
        ->test(Genres::class)
        ->set('name', 'Fantasy')
        ->call('add')
        ->assertHasErrors(['name' => 'unique']);

    expect(Tag::query()->count())->toBe(1)
        ->and(Genre::query()->count())->toBe(1);
});

test('taxonomy screens and livewire mutations require taxonomy permission', function () {
    $this->actingAs($this->member)
        ->get(route('admin.genres'))
        ->assertForbidden();

    $this->actingAs($this->member)
        ->get(route('admin.tags'))
        ->assertForbidden();

    Livewire::actingAs($this->member)
        ->test(Genres::class)
        ->assertForbidden();

    Livewire::actingAs($this->member)
        ->test(Tags::class)
        ->assertForbidden();
});
