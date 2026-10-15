<?php

use App\Livewire\Admin\ApiTokens;
use App\Models\ApiToken;
use App\Models\Chapter;
use App\Models\Series;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('Administrator');
    $this->uploader = User::factory()->create();
    $this->uploader->assignRole('Uploader');
    $this->member = User::factory()->create();
    $this->member->assignRole('Member');
});

test('only administrators can open API token management', function () {
    $this->get(route('admin.api-tokens'))
        ->assertRedirect(route('login'));

    $this->actingAs($this->uploader)
        ->get(route('admin.api-tokens'))
        ->assertForbidden();

    Livewire::actingAs($this->uploader)
        ->test(ApiTokens::class)
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->get(route('admin.api-tokens'))
        ->assertOk()
        ->assertSee('Create an API token')
        ->assertSee($this->uploader->email)
        ->assertDontSee($this->member->email);
});

test('administrator creates an expiring token whose secret is shown once and never stored', function () {
    $expiresAt = now()->addDay()->startOfMinute();

    $component = Livewire::actingAs($this->admin)
        ->test(ApiTokens::class)
        ->set('userId', $this->uploader->id)
        ->set('tokenName', 'Customer migration')
        ->set('expiresAt', $expiresAt->format('Y-m-d\TH:i'))
        ->call('issue')
        ->assertHasNoErrors()
        ->assertSee('Copy Customer migration now');

    $plainTextToken = $component->get('newPlainTextToken');
    $token = ApiToken::query()->sole();

    expect($plainTextToken)->toStartWith('koma_')
        ->and($token->user_id)->toBe($this->uploader->id)
        ->and($token->name)->toBe('Customer migration')
        ->and($token->token_hash)->toBe(hash('sha256', $plainTextToken))
        ->and($token->token_hash)->not->toContain($plainTextToken)
        ->and($token->expires_at->timestamp)->toBe($expiresAt->timestamp);

    $component->assertDontSee($token->token_hash)
        ->assertSet('issuedTokenId', $token->id)
        ->call('dismissNewToken')
        ->assertSet('newPlainTextToken', null)
        ->assertSet('issuedTokenName', null)
        ->assertSet('issuedTokenId', null)
        ->assertDontSee($plainTextToken);
});

test('token form rejects unauthorized owners and past expirations', function () {
    Livewire::actingAs($this->admin)
        ->test(ApiTokens::class)
        ->set('userId', $this->member->id)
        ->set('tokenName', 'Should not exist')
        ->call('issue')
        ->assertHasErrors(['userId'])
        ->assertSee('does not have permission to upload chapters');

    Livewire::actingAs($this->admin)
        ->test(ApiTokens::class)
        ->set('userId', $this->uploader->id)
        ->set('tokenName', 'Already expired')
        ->set('expiresAt', now()->subHour()->format('Y-m-d\TH:i'))
        ->call('issue')
        ->assertHasErrors(['expiresAt']);

    expect(ApiToken::query()->count())->toBe(0);
});

test('token list shows status and activity without exposing credential hashes', function () {
    $active = ApiToken::issue($this->uploader, 'Active importer')['token'];
    $active->forceFill(['last_used_at' => now()->subMinutes(5)])->save();
    $expired = ApiToken::issue($this->uploader, 'Old importer', now()->subMinute())['token'];

    Livewire::actingAs($this->admin)
        ->test(ApiTokens::class)
        ->assertSee('Active importer')
        ->assertSee('Old importer')
        ->assertSee('Active')
        ->assertSee('Expired')
        ->assertSee('5 minutes ago')
        ->assertDontSee($active->token_hash)
        ->assertDontSee($expired->token_hash);
});

test('revoking a token immediately blocks API authentication', function () {
    $component = Livewire::actingAs($this->admin)
        ->test(ApiTokens::class)
        ->set('userId', $this->uploader->id)
        ->set('tokenName', 'Revoke me')
        ->call('issue')
        ->assertHasNoErrors();
    $plainTextToken = $component->get('newPlainTextToken');
    $token = ApiToken::query()->sole();
    $series = Series::create([
        'title' => 'Revocation Test Series',
        'author' => 'Test Author',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
    ]);

    $component
        ->call('revoke', $token->id)
        ->assertHasNoErrors()
        ->assertSet('newPlainTextToken', null)
        ->assertSet('issuedTokenId', null)
        ->assertSee('API token Revoke me revoked.');

    expect($token->fresh())->toBeNull();

    $this->withToken($plainTextToken)
        ->post(route('api.v1.series.chapters.bulk', $series), [], ['Accept' => 'application/json'])
        ->assertUnauthorized();

    expect(Chapter::query()->count())->toBe(0);
});
