<?php

namespace App\Models;

use App\Support\Facades\Hook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

class Series extends Model
{
    // Laravel would otherwise pluralise this to "series" incorrectly; the table
    // is already "series", so pin it explicitly.
    protected $table = 'series';

    protected $attributes = [
        'is_public' => true,
    ];

    protected $fillable = [
        'title', 'alternate_titles', 'slug', 'author', 'artist', 'credits',
        'franchises', 'characters', 'source_references',
        'year', 'publication_date', 'work_format', 'status',
        'reading_direction', 'default_reader_mode', 'content_rating', 'is_public', 'synopsis', 'cover_path',
        'cover_thumbnail_path', 'cover_width', 'cover_height', 'cover_file_size',
        'cover_thumbnail_width', 'cover_thumbnail_height', 'cover_thumbnail_file_size',
        'cover_hue', 'views',
    ];

    protected $hidden = [
        'source_metadata',
    ];

    protected function casts(): array
    {
        return [
            'alternate_titles' => 'array',
            'credits' => 'array',
            'franchises' => 'array',
            'characters' => 'array',
            'source_references' => 'array',
            'source_metadata' => 'array',
            'year' => 'integer',
            'publication_date' => 'date:Y-m-d',
            'is_public' => 'boolean',
            'cover_width' => 'integer',
            'cover_height' => 'integer',
            'cover_file_size' => 'integer',
            'cover_thumbnail_width' => 'integer',
            'cover_thumbnail_height' => 'integer',
            'cover_thumbnail_file_size' => 'integer',
            'cover_hue' => 'integer',
            'views' => 'integer',
        ];
    }

    public const STATUSES = ['ongoing', 'completed', 'hiatus'];

    public const DIRECTIONS = ['rtl', 'ltr', 'vertical'];

    public const READER_MODES = ['rtl', 'ltr', 'vertical'];

    public const RATINGS = ['safe', 'mature'];

    public const CREDIT_TYPES = ['person', 'organization'];

    public const TITLE_TYPES = ['original', 'alternate', 'official', 'romanized'];

    public function readerModeFor(?User $user = null): string
    {
        foreach ([$user?->reader_mode, $this->default_reader_mode, $this->reading_direction] as $mode) {
            if (in_array($mode, self::READER_MODES, true)) {
                return $mode;
            }
        }

        return 'rtl';
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected static function booted(): void
    {
        static::saving(function (Series $series) {
            if (blank($series->slug)) {
                $series->slug = static::uniqueSlug($series->title);
            }
        });

        static::saved(fn (Series $series) => Hook::doAction('series.saved', $series));
        static::deleted(fn (Series $series) => Hook::doAction('series.deleted', $series));
    }

    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'series';
        $slug = $base;
        $i = 2;

        while (static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class);
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function chapterComments(): HasManyThrough
    {
        return $this->hasManyThrough(
            Comment::class,
            Chapter::class,
            'series_id',
            'commentable_id',
        )->where('comments.commentable_type', Chapter::class);
    }

    public function publishedChapters(): HasMany
    {
        return $this->chapters()->published();
    }

    /**
     * Public series that have at least one published chapter.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('is_public', true)
            ->whereHas('chapters', fn (Builder $q) => $q->published());
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('title', 'like', "%{$term}%")
                ->orWhere('author', 'like', "%{$term}%")
                ->orWhere('artist', 'like', "%{$term}%");
        });
    }

    public function coverUrl(): ?string
    {
        if (blank($this->cover_path)) {
            return null;
        }

        return route('media.series', ['series' => $this, 'variant' => 'cover']);
    }

    public function coverThumbnailUrl(): ?string
    {
        if (filled($this->cover_thumbnail_path)) {
            return route('media.series', ['series' => $this, 'variant' => 'thumbnail']);
        }

        return $this->coverUrl();
    }

    /**
     * Two-letter monogram used on tonal placeholder covers, mirroring the mockup.
     */
    public function monogram(): string
    {
        $words = preg_split('/\s+/', trim(preg_replace('/[^A-Za-z ]/', '', $this->title) ?? '')) ?: [];
        $words = array_values(array_filter($words));

        if (count($words) === 0) {
            return Str::upper(Str::substr($this->title, 0, 2));
        }

        if (count($words) === 1) {
            return Str::upper(Str::substr($words[0], 0, 2));
        }

        return Str::upper(Str::substr($words[0], 0, 1).Str::substr($words[1], 0, 1));
    }

    /**
     * CSS gradient used for the placeholder cover, derived from the series hue.
     */
    public function coverGradient(): string
    {
        $h = $this->cover_hue;
        $c1 = 'oklch(0.66 0.15 '.(($h + 28) % 360).')';
        $c2 = "oklch(0.42 0.12 {$h})";

        return "linear-gradient(145deg, {$c1}, {$c2})";
    }

    public function statusLabel(): string
    {
        return Str::title($this->status);
    }
}
