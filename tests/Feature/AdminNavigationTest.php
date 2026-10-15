<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('Administrator');
});

test('admin navigation uses wordpress style parent menus', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('id="koma-admin-bar"', false)
        ->assertSee('aria-label="Admin toolbar"', false)
        ->assertSee('aria-label="Visit site"', false)
        ->assertSee('aria-controls="admin-new-menu"', false)
        ->assertSee('aria-haspopup="menu"', false)
        ->assertSee('aria-label="New"', false)
        ->assertSee('@mouseenter="newMenuOpen = true; accountMenuOpen = false"', false)
        ->assertSee('@mouseleave="newMenuOpen = false"', false)
        ->assertSee('role="menu"', false)
        ->assertSee('role="menuitem"', false)
        ->assertSee('aria-controls="admin-account-menu"', false)
        ->assertSee('aria-label="Howdy, '.$this->admin->name.'"', false)
        ->assertSee('Comments awaiting moderation: 0')
        ->assertSee('Howdy,')
        ->assertSeeInOrder(['New series', 'Upload chapter', 'Import archive'])
        ->assertSeeInOrder(['Dashboard', 'Home', 'Updates'])
        ->assertSee('aria-label="Dashboard submenu"', false)
        ->assertSee('data-nav-submenu="dashboard"', false)
        ->assertSee('data-nav-mode="inline"', false)
        ->assertSee('data-nav-submenu="series-chapters"', false)
        ->assertSee('data-nav-mode="flyout"', false)
        ->assertSee('lg:group-hover:visible', false)
        ->assertSee('lg:group-focus-within:visible', false)
        ->assertSee('id="admin-navigation"', false)
        ->assertSee(':inert="! isDesktop && ! navOpen"', false)
        ->assertSee(':aria-hidden="(! isDesktop && ! navOpen).toString()"', false)
        ->assertSee('aria-controls="admin-navigation"', false);

    $this->actingAs($this->admin)
        ->get(route('admin.upload'))
        ->assertOk()
        ->assertSeeInOrder([
            'Series &amp; Chapters',
            'All Series',
            'Add Series/Chapter',
            'Genres',
            'Tags',
            'Import Archive',
        ], false)
        ->assertSee('aria-label="Series &amp; Chapters submenu"', false)
        ->assertSee('data-nav-submenu="series-chapters"', false)
        ->assertSee('data-nav-mode="inline"', false)
        ->assertDontSee('>Add New</a>', false)
        ->assertDontSee('>Bulk Upload</a>', false)
        ->assertDontSee('Grow &amp; System', false)
        ->assertDontSee('>Manage</div>', false)
        ->assertDontSee('>Analytics<', false)
        ->assertDontSee('>Monetization<', false)
        ->assertDontSee('>Storage<', false);
});

test('themes and plugins are separate menus with plugins and hooks grouped together', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.themes'))
        ->assertOk()
        ->assertSeeInOrder(['Themes', 'Plugins', 'Settings'])
        ->assertDontSee('Themes &amp; Plugins', false)
        ->assertDontSee('aria-label="Themes submenu"', false)
        ->assertSee('aria-label="Plugins submenu"', false)
        ->assertSee('data-nav-submenu="plugins"', false);

    $this->actingAs($this->admin)
        ->get(route('admin.plugins'))
        ->assertOk()
        ->assertSeeInOrder(['Plugins', 'Hooks'])
        ->assertSee('data-nav-mode="inline"', false)
        ->assertSee('aria-current="true"', false);

    $this->actingAs($this->admin)
        ->get(route('admin.plugins.hooks'))
        ->assertOk()
        ->assertSee('aria-current="page"', false)
        ->assertSee(route('admin.plugins.hooks'), false);
});

test('updates screen is restricted to administrators and describes its current state honestly', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.updates'))
        ->assertOk()
        ->assertSee('Automatic updates will live here')
        ->assertSee('Not configured');

    $editor = User::factory()->create();
    $editor->assignRole('Editor');

    $this->actingAs($editor)
        ->get(route('admin.updates'))
        ->assertForbidden();
});

test('settings is a collapsible menu whose tabs are submenu items', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.settings', ['tab' => 'reading']))
        ->assertOk()
        ->assertSee('aria-label="Settings submenu"', false)
        ->assertSee('data-nav-submenu="settings"', false)
        ->assertSee('data-nav-mode="inline"', false)
        ->assertSeeInOrder(['General', 'Publishing', 'Reading', 'Discussion', 'Media & Storage', 'Permalinks', 'Privacy & Legal'])
        ->assertSee(route('admin.settings', ['tab' => 'permalinks']), false)
        ->assertSee('aria-current="page"', false);

    // Away from settings the menu still exists (as a hover flyout).
    $this->actingAs($this->admin)
        ->get(route('admin.themes'))
        ->assertOk()
        ->assertSee('aria-label="Settings submenu"', false);
});

test('uploader route remains available after its submenu item is removed', function () {
    $uploader = User::factory()->create();
    $uploader->assignRole('Uploader');

    $this->actingAs($uploader)
        ->get(route('admin.upload'))
        ->assertOk()
        ->assertSee('Bulk Uploader')
        ->assertSee('aria-controls="admin-new-menu"', false)
        ->assertSee('Upload chapter')
        ->assertDontSee('New chapter')
        ->assertDontSee('New series')
        ->assertDontSee('Import archive')
        ->assertDontSee('Comments awaiting moderation')
        ->assertDontSee('>Bulk Upload</a>', false)
        ->assertDontSee('All Series')
        ->assertDontSee('Import Archive')
        ->assertDontSee('>Genres</a>', false)
        ->assertDontSee('>Tags</a>', false);
});

test('chapter creation action lives beside series creation instead of in the global header', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.series.index'))
        ->assertOk()
        ->assertSeeInOrder(['Visit site', 'All Series', 'New Series', 'New chapter'])
        ->assertSee('href="'.route('admin.upload').'"', false);

    $this->actingAs($this->admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertDontSee('New chapter');
});
