<?php

use App\Livewire\Admin\Dashboard;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('Administrator');
});

test('dashboard exposes useful wordpress style movable panels', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Screen options')
        ->assertSee('Drag panels into your preferred order')
        ->assertSee('data-dashboard-column="0"', false)
        ->assertSee('data-dashboard-column="1"', false)
        ->assertSee('data-dashboard-widget="at_a_glance"', false)
        ->assertSee('data-dashboard-widget="quick_actions"', false)
        ->assertSee('data-dashboard-widget="content_status"', false)
        ->assertSee('data-dashboard-widget="recent_activity"', false)
        ->assertSee('data-dashboard-widget="popular_series"', false)
        ->assertSee('data-dashboard-widget="moderation"', false)
        ->assertSee('data-dashboard-widget="archive_activity"', false)
        ->assertSee('data-dashboard-handle', false)
        ->assertSee('startPointerDrag', false)
        ->assertSee('Use arrow keys or drag');
});

test('dashboard layout is normalized and saved per account', function () {
    Livewire::actingAs($this->admin)
        ->test(Dashboard::class)
        ->call('saveDashboardPreferences', [
            'columns' => [
                ['recent_activity', 'popular_series', 'popular_series', 'not-a-widget'],
                ['at_a_glance'],
                ['moderation'],
            ],
            'hidden' => ['content_status', 'not-a-widget'],
            'collapsed' => ['recent_activity', 'not-a-widget'],
        ])
        ->assertHasNoErrors();

    $preferences = $this->admin->fresh()->admin_dashboard_preferences;

    expect($preferences['columns'])->toBe([
        ['recent_activity', 'popular_series', 'content_status'],
        ['at_a_glance', 'quick_actions', 'moderation', 'archive_activity'],
    ])->and($preferences['hidden'])->toBe(['content_status'])
        ->and($preferences['collapsed'])->toBe(['recent_activity']);

    $otherAdmin = User::factory()->create();
    $otherAdmin->assignRole('Administrator');

    expect($otherAdmin->admin_dashboard_preferences)->toBeNull();
});

test('dashboard rejects widgets outside the staff members capabilities', function () {
    $uploader = User::factory()->create();
    $uploader->assignRole('Uploader');

    $this->actingAs($uploader)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('data-dashboard-widget="at_a_glance"', false)
        ->assertSee('data-dashboard-widget="quick_actions"', false)
        ->assertSee('data-dashboard-widget="recent_activity"', false)
        ->assertDontSee('data-dashboard-widget="content_status"', false)
        ->assertDontSee('data-dashboard-widget="popular_series"', false)
        ->assertDontSee('data-dashboard-widget="moderation"', false)
        ->assertDontSee('data-dashboard-widget="archive_activity"', false)
        ->assertSee('Upload chapter')
        ->assertDontSee('New series');

    Livewire::actingAs($uploader)
        ->test(Dashboard::class)
        ->call('saveDashboardPreferences', [
            'columns' => [['moderation', 'archive_activity'], ['quick_actions']],
            'hidden' => ['popular_series'],
            'collapsed' => ['content_status'],
        ])
        ->assertHasNoErrors();

    $preferences = $uploader->fresh()->admin_dashboard_preferences;

    expect(collect($preferences['columns'])->flatten()->sort()->values()->all())
        ->toBe(['at_a_glance', 'quick_actions', 'recent_activity'])
        ->and($preferences['hidden'])->toBe([])
        ->and($preferences['collapsed'])->toBe([]);
});

test('dashboard layout can be reset to account defaults', function () {
    $this->admin->forceFill([
        'admin_dashboard_preferences' => [
            'columns' => [['recent_activity'], ['at_a_glance']],
            'hidden' => ['popular_series'],
            'collapsed' => ['recent_activity'],
        ],
    ])->save();

    Livewire::actingAs($this->admin)
        ->test(Dashboard::class)
        ->call('resetDashboardPreferences')
        ->assertSet('dashboardPreferences.hidden', [])
        ->assertSet('dashboardPreferences.collapsed', []);

    expect($this->admin->fresh()->admin_dashboard_preferences)->toBeNull();
});
