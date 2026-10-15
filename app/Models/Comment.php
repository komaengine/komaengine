<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    use SoftDeletes;

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PENDING = 'pending';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_HIDDEN = 'hidden';

    public const STATUSES = [
        self::STATUS_APPROVED,
        self::STATUS_PENDING,
        self::STATUS_REJECTED,
        self::STATUS_HIDDEN,
    ];

    protected $fillable = [
        'user_id',
        'body',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
            'moderated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(CommentReport::class);
    }

    public function scopeVisibleTo(Builder $query, ?User $viewer): Builder
    {
        return $query->where(function (Builder $visibility) use ($viewer) {
            $visibility->where('status', self::STATUS_APPROVED);

            if ($viewer?->can('moderate_comments')) {
                $visibility->orWhereIn('status', self::STATUSES);
            } elseif ($viewer !== null) {
                $visibility->orWhere('user_id', $viewer->getKey());
            }
        });
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isHidden(): bool
    {
        return in_array($this->status, [self::STATUS_HIDDEN, self::STATUS_REJECTED], true);
    }

    public function ownerWindowEndsAt(): ?\Carbon\CarbonInterface
    {
        return $this->created_at?->copy()->addMinutes((int) config('koma.comments.owner_edit_window_minutes', 15));
    }

    public function isWithinOwnerWindow(): bool
    {
        return ! $this->trashed() && $this->ownerWindowEndsAt()?->isFuture() === true;
    }
}
