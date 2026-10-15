<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'reader_mode',
        'admin_dashboard_preferences',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'admin_dashboard_preferences' => 'array',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('Administrator');
    }

    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function moderatedComments(): HasMany
    {
        return $this->hasMany(Comment::class, 'moderated_by');
    }

    public function commentReports(): HasMany
    {
        return $this->hasMany(CommentReport::class);
    }

    public function reviewedCommentReports(): HasMany
    {
        return $this->hasMany(CommentReport::class, 'reviewed_by');
    }

    /**
     * May this user reach the admin dashboard at all? Members (readers) cannot;
     * every staff role can, and section-level access is checked per capability.
     */
    public function canAccessAdmin(): bool
    {
        return $this->hasAnyRole(['Administrator', 'Editor', 'Uploader', 'Moderator']);
    }

    /**
     * A stable decorative gradient for the user's avatar placeholder.
     */
    public function avatarGradient(): string
    {
        $h = (strlen($this->name) * 40) % 360;

        return "linear-gradient(135deg, oklch(0.72 0.13 {$h}), oklch(0.55 0.15 ".(($h + 60) % 360).'))';
    }
}
