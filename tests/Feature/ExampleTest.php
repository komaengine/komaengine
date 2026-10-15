<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('uninstalled sites redirect to the installer', function () {
    $this->get('/')->assertRedirect(route('install'));
});

test('uninstalled API requests fail before authentication or route binding', function () {
    $this->postJson('/api/v1/series/not-installed/chapters/bulk')
        ->assertServiceUnavailable()
        ->assertJsonPath('message', 'Koma Engine is not installed.');
});
