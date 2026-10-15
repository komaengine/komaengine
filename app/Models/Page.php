<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Page extends Model
{
    protected $fillable = [
        'chapter_id', 'sort_order', 'image_path', 'width', 'height', 'file_size',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'file_size' => 'integer',
        ];
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    public function url(): string
    {
        $this->loadMissing('chapter.series');

        return route('media.page', [
            'series' => $this->chapter->series,
            'chapter' => $this->chapter,
            'page' => $this,
        ]);
    }
}
