<?php

namespace Database\Seeders;

use App\Models\Chapter;
use App\Models\Genre;
use App\Models\Series;
use Illuminate\Database\Seeder;

/**
 * Fast fixture data for automated tests — one series, one published chapter, no images.
 */
class TestContentSeeder extends Seeder
{
    public function run(): void
    {
        $genre = Genre::create(['name' => 'Fantasy', 'slug' => 'fantasy']);

        $series = Series::create([
            'title' => 'Test Series',
            'slug' => 'test-series',
            'author' => 'Test Author',
            'status' => 'ongoing',
            'reading_direction' => 'rtl',
            'content_rating' => 'safe',
            'synopsis' => 'A short synopsis for SEO testing.',
            'cover_hue' => 200,
        ]);

        $series->genres()->attach($genre);

        Chapter::create([
            'series_id' => $series->id,
            'number' => 1,
            'title' => 'First chapter',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
    }
}
