<?php

use App\Models\Chapter;
use App\Models\Series;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\TestContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(TestContentSeeder::class);
});

test('home page renders with seeded content', function () {
    $this->get('/')->assertOk()->assertSee('Latest updates');
});

test('series page uses slug url and seo tags', function () {
    $series = Series::published()->first();

    $this->get(route('series.show', $series))
        ->assertOk()
        ->assertSee($series->title)
        ->assertSee('<meta property="og:type" content="book">', false)
        ->assertSee($series->synopsis ? \Illuminate\Support\Str::limit($series->synopsis, 160) : $series->title, false);
});

test('reader page loads for a published chapter', function () {
    $chapter = Chapter::published()->with('series')->first();

    $this->get(route('reader', [$chapter->series, $chapter->displayNumber()]))
        ->assertOk()
        ->assertSee('reader.js')
        ->assertSee('Chapter '.$chapter->displayNumber());
});

test('admin dashboard requires authentication', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
});

test('admin user can access dashboard', function () {
    $admin = \App\Models\User::factory()->create();
    $admin->assignRole('Administrator');

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Published chapters');
});
