<?php

namespace App\Livewire\Admin;

use App\Models\ApiToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class ApiTokens extends Component
{
    public ?int $userId = null;

    public string $tokenName = 'Bulk uploader';

    public string $expiresAt = '';

    public ?string $newPlainTextToken = null;

    public ?string $issuedTokenName = null;

    public ?int $issuedTokenId = null;

    public ?string $statusMessage = null;

    public function mount(): void
    {
        Gate::authorize('manage_users');

        $user = auth()->user();
        $this->userId = $user?->can('upload_chapters') ? $user->getKey() : null;
    }

    public function issue(): void
    {
        Gate::authorize('manage_users');
        $this->statusMessage = null;

        $validated = $this->validate([
            'userId' => ['required', 'integer', 'exists:users,id'],
            'tokenName' => ['required', 'string', 'max:100'],
            'expiresAt' => ['nullable', 'date_format:Y-m-d\TH:i', 'after:now'],
        ], [
            'userId.required' => 'Choose the user who will own this token.',
            'expiresAt.after' => 'The expiration must be in the future.',
        ]);

        $user = User::query()->findOrFail($validated['userId']);

        if (! $user->can('upload_chapters')) {
            $this->addError('userId', 'That user does not have permission to upload chapters.');

            return;
        }

        $expiresAt = filled($validated['expiresAt'] ?? null)
            ? CarbonImmutable::createFromFormat(
                'Y-m-d\TH:i',
                $validated['expiresAt'],
                config('app.timezone'),
            )
            : null;

        $issued = ApiToken::issue($user, trim($validated['tokenName']), $expiresAt);

        $this->newPlainTextToken = $issued['plain_text_token'];
        $this->issuedTokenName = $issued['token']->name;
        $this->issuedTokenId = $issued['token']->id;
        $this->tokenName = 'Bulk uploader';
        $this->expiresAt = '';
        $this->resetValidation();
    }

    public function revoke(int $tokenId): void
    {
        Gate::authorize('manage_users');

        $token = ApiToken::query()->findOrFail($tokenId);
        $name = $token->name;
        $token->delete();

        if ($this->issuedTokenId === $tokenId) {
            $this->clearIssuedToken();
        }

        $this->statusMessage = "API token {$name} revoked.";
    }

    public function dismissNewToken(): void
    {
        Gate::authorize('manage_users');

        $this->clearIssuedToken();
    }

    public function render()
    {
        Gate::authorize('manage_users');

        return view('livewire.admin.api-tokens', [
            'eligibleUsers' => User::permission('upload_chapters')->orderBy('name')->get(),
            'tokens' => ApiToken::query()->with('user')->latest()->get(),
        ]);
    }

    protected function clearIssuedToken(): void
    {
        $this->newPlainTextToken = null;
        $this->issuedTokenName = null;
        $this->issuedTokenId = null;
    }
}
