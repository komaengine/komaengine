<?php

namespace App\Models;

use App\Support\Facades\Hook;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use InvalidArgumentException;

class Chapter extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PUBLISHED = 'published';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SCHEDULED,
        self::STATUS_PUBLISHED,
    ];

    protected $fillable = [
        'series_id', 'number', 'title', 'scanlation_group', 'language',
        'status', 'published_at', 'views',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'decimal:2',
            'published_at' => 'datetime',
            'views' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn (Chapter $chapter) => Hook::doAction('chapter.saved', $chapter));
        static::deleted(fn (Chapter $chapter) => Hook::doAction('chapter.deleted', $chapter));
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class)->orderBy('sort_order');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED)
            ->where(function (Builder $q) {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            });
    }

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SCHEDULED)
            ->whereNotNull('published_at');
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->scheduled()->where('published_at', '<=', now());
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && ($this->published_at === null || $this->published_at->lessThanOrEqualTo(now()));
    }

    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_SCHEDULED
            && $this->published_at !== null;
    }

    /**
     * Publish the chapter and fire the `chapter.published` hook so plugins
     * (Discord announcer, feeds, cache purge, …) can react.
     */
    public function publish(): void
    {
        $wasPublished = $this->isPublished();

        $this->forceFill([
            'status' => self::STATUS_PUBLISHED,
            'published_at' => $wasPublished ? $this->published_at : now(),
        ])->save();

        if (! $wasPublished) {
            Hook::doAction('chapter.published', $this);
        }
    }

    public function scheduleFor(CarbonInterface $publishAt): void
    {
        $publishAt = CarbonImmutable::instance($publishAt)->utc();

        if ($publishAt->lessThanOrEqualTo(now())) {
            throw new InvalidArgumentException('A scheduled publication time must be in the future.');
        }

        $this->forceFill([
            'status' => self::STATUS_SCHEDULED,
            'published_at' => $publishAt,
        ])->save();

        Hook::doAction('chapter.scheduled', $this);
    }

    public function unpublish(): void
    {
        $this->forceFill([
            'status' => self::STATUS_DRAFT,
            'published_at' => null,
        ])->save();
    }

    /**
     * Atomically claim and publish a due chapter. The status predicate makes
     * repeated or concurrent command runs no-ops after the first claim.
     */
    public function publishDue(): bool
    {
        $claimed = static::query()
            ->whereKey($this->getKey())
            ->where('status', self::STATUS_SCHEDULED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->update([
                'status' => self::STATUS_PUBLISHED,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return false;
        }

        $this->refresh();
        Hook::doAction('chapter.saved', $this);
        Hook::doAction('chapter.published', $this);

        return true;
    }

    /**
     * A trimmed chapter number: 10.00 -> "10", 10.50 -> "10.5".
     */
    public function displayNumber(): string
    {
        return rtrim(rtrim(number_format((float) $this->number, 2, '.', ''), '0'), '.');
    }

    public function label(): string
    {
        $label = 'Chapter '.$this->displayNumber();

        return $this->title ? $label.' · '.$this->title : $label;
    }
}
