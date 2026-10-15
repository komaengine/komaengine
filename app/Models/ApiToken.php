<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    protected $fillable = [
        'name',
        'token_hash',
        'last_used_at',
        'expires_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{token: self, plain_text_token: string}
     */
    public static function issue(User $user, string $name, ?\DateTimeInterface $expiresAt = null): array
    {
        $plainTextToken = 'koma_'.Str::random(64);

        $token = $user->apiTokens()->create([
            'name' => $name,
            'token_hash' => hash('sha256', $plainTextToken),
            'expires_at' => $expiresAt,
        ]);

        return [
            'token' => $token,
            'plain_text_token' => $plainTextToken,
        ];
    }

    public function expired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
