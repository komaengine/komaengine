<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommentReport extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_RESOLVED,
        self::STATUS_DISMISSED,
    ];

    public const REASONS = [
        'spam' => 'Spam or advertising',
        'harassment' => 'Harassment or abuse',
        'spoiler' => 'Unmarked spoiler',
        'other' => 'Something else',
    ];

    protected $fillable = [
        'user_id',
        'reason',
        'details',
        'status',
        'comment_author_id',
        'comment_author_name',
        'commentable_type',
        'commentable_id',
        'comment_body',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class)->withTrashed();
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? 'Other';
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
