<?php

namespace App\Archives;

use App\Models\Chapter;
use App\Models\Series;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class KomaArchiveValidator
{
    public const SUPPORTED_SCHEMA_VERSIONS = [
        KomaArchiveV1::SCHEMA_VERSION,
        KomaArchiveV2::SCHEMA_VERSION,
    ];

    private const ROOT_KEYS = ['format', 'schema_version', 'exported_at', 'series'];

    private const SERIES_V1_KEYS = [
        'source_id', 'title', 'slug', 'author', 'artist', 'year', 'status',
        'reading_direction', 'default_reader_mode', 'content_rating', 'is_public',
        'synopsis', 'cover_hue', 'views', 'created_at', 'updated_at', 'genres',
        'tags', 'cover', 'cover_thumbnail', 'chapters',
    ];

    private const SERIES_V2_KEYS = [
        'source_id', 'title', 'alternate_titles', 'slug', 'author', 'artist',
        'credits', 'franchises', 'characters', 'source_references', 'year',
        'publication_date', 'work_format', 'status', 'reading_direction',
        'default_reader_mode', 'content_rating', 'is_public', 'synopsis',
        'cover_hue', 'views', 'created_at', 'updated_at', 'genres', 'tags',
        'cover', 'cover_thumbnail', 'chapters',
    ];

    private const GENRE_KEYS = ['source_id', 'name', 'slug', 'created_at', 'updated_at'];

    private const TAG_KEYS = ['source_id', 'name', 'slug', 'created_at', 'updated_at'];

    private const CHAPTER_V1_KEYS = [
        'source_id', 'number', 'title', 'scanlation_group', 'status',
        'published_at', 'views', 'created_at', 'updated_at', 'pages',
    ];

    private const CHAPTER_V2_KEYS = [
        'source_id', 'number', 'title', 'scanlation_group', 'language', 'status',
        'published_at', 'views', 'created_at', 'updated_at', 'pages',
    ];

    private const PAGE_KEYS = [
        'source_id', 'sort_order', 'archive_path', 'mime_type', 'sha256',
        'bytes', 'width', 'height', 'created_at', 'updated_at',
    ];

    private const MEDIA_KEYS = [
        'archive_path', 'mime_type', 'sha256', 'bytes', 'width', 'height',
    ];

    private const ALTERNATE_TITLE_KEYS = ['language', 'title', 'type'];

    private const CREDIT_KEYS = ['name', 'role', 'type'];

    private const SOURCE_REFERENCE_KEYS = ['provider', 'external_id', 'url'];

    /**
     * @param  array<string, mixed>  $manifest
     *
     * @throws ArchiveValidationException
     */
    public function validate(array $manifest): void
    {
        $version = $manifest['schema_version'] ?? null;

        if (! is_int($version) || ! in_array($version, self::SUPPORTED_SCHEMA_VERSIONS, true)) {
            throw new ArchiveValidationException('Archive manifest validation failed: unsupported schema version.');
        }

        $this->assertManifestCounts($manifest);

        try {
            Validator::make($manifest, $this->rules($version))->validate();
        } catch (ValidationException $e) {
            throw new ArchiveValidationException(
                'Archive manifest validation failed: '.$e->validator->errors()->first(),
                previous: $e,
            );
        }

        $isV2 = $version === KomaArchiveV2::SCHEMA_VERSION;
        $this->assertKeys($manifest, self::ROOT_KEYS, 'manifest');
        $this->assertKeys(
            $manifest['series'],
            $isV2 ? self::SERIES_V2_KEYS : self::SERIES_V1_KEYS,
            'series',
        );

        foreach ($manifest['series']['genres'] as $index => $genre) {
            $this->assertKeys($genre, self::GENRE_KEYS, "series.genres.{$index}");
        }

        foreach ($manifest['series']['tags'] as $index => $tag) {
            $this->assertKeys($tag, self::TAG_KEYS, "series.tags.{$index}");
        }

        if ($isV2) {
            foreach ($manifest['series']['alternate_titles'] as $index => $title) {
                $this->assertKeys($title, self::ALTERNATE_TITLE_KEYS, "series.alternate_titles.{$index}");
            }

            foreach ($manifest['series']['credits'] as $index => $credit) {
                $this->assertKeys($credit, self::CREDIT_KEYS, "series.credits.{$index}");
            }

            foreach ($manifest['series']['source_references'] as $index => $reference) {
                $this->assertKeys($reference, self::SOURCE_REFERENCE_KEYS, "series.source_references.{$index}");
            }
        }

        foreach (['cover', 'cover_thumbnail'] as $name) {
            if ($manifest['series'][$name] !== null) {
                $this->assertKeys($manifest['series'][$name], self::MEDIA_KEYS, "series.{$name}");
            }
        }

        foreach ($manifest['series']['chapters'] as $chapterIndex => $chapter) {
            $this->assertKeys(
                $chapter,
                $isV2 ? self::CHAPTER_V2_KEYS : self::CHAPTER_V1_KEYS,
                "series.chapters.{$chapterIndex}",
            );

            foreach ($chapter['pages'] as $pageIndex => $page) {
                $this->assertKeys($page, self::PAGE_KEYS, "series.chapters.{$chapterIndex}.pages.{$pageIndex}");
            }
        }

        $paths = $this->mediaPaths($manifest);

        if (count($paths) !== count(array_unique($paths))) {
            throw new ArchiveValidationException('Archive manifest contains duplicate media paths.');
        }

        foreach ($paths as $path) {
            if (! $this->safeMediaPath($path)) {
                throw new ArchiveValidationException("Archive manifest contains an unsafe media path: {$path}");
            }
        }

        $this->assertUnique(array_column($manifest['series']['genres'], 'source_id'), 'genre source IDs');
        $this->assertUnique(array_column($manifest['series']['genres'], 'slug'), 'genre slugs');
        $this->assertUnique(array_column($manifest['series']['tags'], 'source_id'), 'tag source IDs');
        $this->assertUnique(array_column($manifest['series']['tags'], 'slug'), 'tag slugs');
        $this->assertUnique(array_column($manifest['series']['chapters'], 'source_id'), 'chapter source IDs');
        $this->assertUnique(array_column($manifest['series']['chapters'], 'number'), 'chapter numbers');

        if ($isV2) {
            $this->assertUnique($manifest['series']['franchises'], 'franchises');
            $this->assertUnique($manifest['series']['characters'], 'characters');
            $this->assertUnique(
                array_map(
                    fn (array $title): string => $title['language']."\0".$title['type']."\0".$title['title'],
                    $manifest['series']['alternate_titles'],
                ),
                'alternate titles',
            );
            $this->assertUnique(
                array_map(
                    fn (array $reference): string => $reference['provider']."\0".$reference['external_id'],
                    $manifest['series']['source_references'],
                ),
                'source references',
            );
        }

        foreach ($manifest['series']['chapters'] as $index => $chapter) {
            $this->assertUnique(array_column($chapter['pages'], 'source_id'), "page source IDs in chapter {$index}");
            $this->assertUnique(array_column($chapter['pages'], 'sort_order'), "page sort orders in chapter {$index}");
        }
    }

    /**
     * Reject a series that describes more records than one archive may carry.
     *
     * The exporter calls this with database counts before it stages any media;
     * validate() calls it with manifest counts before any per-record rule runs.
     *
     * @throws ArchiveValidationException
     */
    public function assertCollectionCounts(int $genres, int $tags, int $chapters, int $media): void
    {
        $limits = [
            'genres' => [$genres, $this->limit('max_genres', 100)],
            'tags' => [$tags, $this->limit('max_tags', 1000)],
            'chapters' => [$chapters, $this->limit('max_chapters', 5000)],
            // Every media file is one ZIP entry beside manifest.json.
            'media files' => [$media, $this->limit('max_entries', 10000) - 1],
        ];

        foreach ($limits as $label => [$count, $limit]) {
            if ($count > $limit) {
                throw new ArchiveValidationException(
                    "Archive manifest lists {$count} {$label}; the configured limit is {$limit}.",
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    public function mediaPaths(array $manifest): array
    {
        $paths = [];

        foreach (['cover', 'cover_thumbnail'] as $name) {
            $path = data_get($manifest, "series.{$name}.archive_path");

            if (is_string($path)) {
                $paths[] = $path;
            }
        }

        foreach (data_get($manifest, 'series.chapters', []) as $chapter) {
            foreach ($chapter['pages'] ?? [] as $page) {
                if (isset($page['archive_path']) && is_string($page['archive_path'])) {
                    $paths[] = $page['archive_path'];
                }
            }
        }

        return $paths;
    }

    /**
     * Count an untrusted manifest's collections without assuming their shape.
     * A value of the wrong type counts as empty here and is rejected by the
     * rules that run next.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function assertManifestCounts(array $manifest): void
    {
        $series = is_array($manifest['series'] ?? null) ? $manifest['series'] : [];
        $count = fn (string $key): int => is_array($series[$key] ?? null) ? count($series[$key]) : 0;
        $media = 0;

        foreach (['cover', 'cover_thumbnail'] as $name) {
            $media += is_array($series[$name] ?? null) ? 1 : 0;
        }

        foreach (is_array($series['chapters'] ?? null) ? $series['chapters'] : [] as $chapter) {
            $media += is_array($chapter) && is_array($chapter['pages'] ?? null) ? count($chapter['pages']) : 0;
        }

        $this->assertCollectionCounts($count('genres'), $count('tags'), $count('chapters'), $media);
    }

    private function limit(string $key, int $default): int
    {
        return max(1, (int) config("koma.archives.imports.{$key}", $default));
    }

    /** @return array<string, mixed> */
    private function rules(int $version): array
    {
        $timestamp = 'date_format:Y-m-d\TH:i:s\Z';
        $language = 'regex:/\A[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*\z/';
        $media = [
            'archive_path' => ['required', 'string', 'max:500'],
            'mime_type' => ['required', 'string', 'max:255'],
            'sha256' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'bytes' => ['required', 'integer', 'min:0'],
            'width' => ['nullable', 'integer', 'min:1'],
            'height' => ['nullable', 'integer', 'min:1'],
        ];
        $rules = [
            'format' => ['required', Rule::in([KomaArchiveV1::FORMAT])],
            'schema_version' => ['required', 'integer', Rule::in(self::SUPPORTED_SCHEMA_VERSIONS)],
            'exported_at' => ['required', $timestamp],
            'series' => ['required', 'array'],
            'series.source_id' => ['required', 'integer', 'min:1'],
            'series.title' => ['required', 'string', 'max:255'],
            'series.slug' => ['required', 'string', 'max:255'],
            'series.author' => ['nullable', 'string', 'max:255'],
            'series.artist' => ['nullable', 'string', 'max:255'],
            'series.year' => ['nullable', 'integer'],
            'series.status' => ['required', Rule::in(Series::STATUSES)],
            'series.reading_direction' => ['required', Rule::in(Series::DIRECTIONS)],
            'series.default_reader_mode' => ['nullable', Rule::in(Series::READER_MODES)],
            'series.content_rating' => ['required', Rule::in(Series::RATINGS)],
            'series.is_public' => ['required', 'boolean'],
            'series.synopsis' => ['nullable', 'string'],
            'series.cover_hue' => ['required', 'integer', 'between:0,359'],
            'series.views' => ['required', 'integer', 'min:0'],
            'series.created_at' => ['required', $timestamp],
            'series.updated_at' => ['required', $timestamp],
            'series.genres' => ['present', 'array'],
            'series.genres.*' => ['required', 'array'],
            'series.genres.*.source_id' => ['required', 'integer', 'min:1'],
            'series.genres.*.name' => ['required', 'string', 'max:255'],
            'series.genres.*.slug' => ['required', 'string', 'max:255'],
            'series.genres.*.created_at' => ['required', $timestamp],
            'series.genres.*.updated_at' => ['required', $timestamp],
            'series.tags' => ['present', 'array'],
            'series.tags.*' => ['required', 'array'],
            'series.tags.*.source_id' => ['required', 'integer', 'min:1'],
            'series.tags.*.name' => ['required', 'string', 'max:255'],
            'series.tags.*.slug' => ['required', 'string', 'max:255'],
            'series.tags.*.created_at' => ['required', $timestamp],
            'series.tags.*.updated_at' => ['required', $timestamp],
            'series.cover' => ['nullable', 'array'],
            'series.cover_thumbnail' => ['nullable', 'array'],
            'series.chapters' => ['present', 'array'],
            'series.chapters.*' => ['required', 'array'],
            'series.chapters.*.source_id' => ['required', 'integer', 'min:1'],
            'series.chapters.*.number' => ['required', 'string', 'regex:/\A\d{1,6}\.\d{2}\z/'],
            'series.chapters.*.title' => ['nullable', 'string', 'max:255'],
            'series.chapters.*.scanlation_group' => ['nullable', 'string', 'max:255'],
            'series.chapters.*.status' => ['required', Rule::in(Chapter::STATUSES)],
            'series.chapters.*.published_at' => ['nullable', $timestamp],
            'series.chapters.*.views' => ['required', 'integer', 'min:0'],
            'series.chapters.*.created_at' => ['required', $timestamp],
            'series.chapters.*.updated_at' => ['required', $timestamp],
            'series.chapters.*.pages' => ['present', 'array'],
            'series.chapters.*.pages.*' => ['required', 'array'],
            'series.chapters.*.pages.*.source_id' => ['required', 'integer', 'min:1'],
            'series.chapters.*.pages.*.sort_order' => ['required', 'integer', 'min:0'],
            'series.chapters.*.pages.*.created_at' => ['required', $timestamp],
            'series.chapters.*.pages.*.updated_at' => ['required', $timestamp],
        ];

        if ($version === KomaArchiveV2::SCHEMA_VERSION) {
            $rules += [
                'series.alternate_titles' => ['present', 'array', 'max:100'],
                'series.alternate_titles.*' => ['required', 'array'],
                'series.alternate_titles.*.language' => ['required', 'string', 'max:35', $language],
                'series.alternate_titles.*.title' => ['required', 'string', 'max:255'],
                'series.alternate_titles.*.type' => ['required', Rule::in(Series::TITLE_TYPES)],
                'series.publication_date' => ['nullable', 'date_format:Y-m-d'],
                'series.work_format' => ['nullable', 'string', 'max:100', 'regex:/\A[a-z0-9]+(?:[_-][a-z0-9]+)*\z/'],
                'series.credits' => ['present', 'array', 'max:100'],
                'series.credits.*' => ['required', 'array'],
                'series.credits.*.name' => ['required', 'string', 'max:255'],
                'series.credits.*.role' => ['required', 'string', 'max:50', 'regex:/\A[a-z0-9]+(?:_[a-z0-9]+)*\z/'],
                'series.credits.*.type' => ['required', Rule::in(Series::CREDIT_TYPES)],
                'series.franchises' => ['present', 'array', 'max:100'],
                'series.franchises.*' => ['required', 'string', 'max:255', 'distinct:strict'],
                'series.characters' => ['present', 'array', 'max:500'],
                'series.characters.*' => ['required', 'string', 'max:255', 'distinct:strict'],
                'series.source_references' => ['present', 'array', 'max:50'],
                'series.source_references.*' => ['required', 'array'],
                'series.source_references.*.provider' => ['required', 'string', 'max:100', 'regex:/\A[a-z0-9]+(?:[._-][a-z0-9]+)*\z/'],
                'series.source_references.*.external_id' => ['required', 'string', 'max:255'],
                'series.source_references.*.url' => ['nullable', 'string', 'max:2048', 'regex:/\Ahttps?:\/\/[^\s]+\z/u'],
                'series.chapters.*.language' => ['nullable', 'string', 'max:35', $language],
            ];
        }

        foreach ($media as $field => $fieldRules) {
            $optionalFieldRules = array_values(array_filter($fieldRules, fn (mixed $rule) => $rule !== 'required'));
            $rules["series.cover.{$field}"] = $optionalFieldRules;
            $rules["series.cover_thumbnail.{$field}"] = $optionalFieldRules;
            $rules["series.chapters.*.pages.*.{$field}"] = $fieldRules;
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $expected
     */
    private function assertKeys(array $value, array $expected, string $path): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);

        if ($actual !== $expected) {
            throw new ArchiveValidationException("Archive manifest has unexpected fields at {$path}.");
        }
    }

    private function safeMediaPath(string $path): bool
    {
        if (! str_starts_with($path, 'media/') || str_contains($path, '\\') || str_contains($path, "\0")) {
            return false;
        }

        if (str_contains($path, '//') || preg_match('#(?:\A|/)\.\.?(/|\z)#', $path) === 1) {
            return false;
        }

        return preg_match('/\A[A-Za-z0-9._\/-]+\z/', $path) === 1;
    }

    /** @param list<int|string> $values */
    private function assertUnique(array $values, string $label): void
    {
        if (count($values) !== count(array_unique($values, SORT_REGULAR))) {
            throw new ArchiveValidationException("Archive manifest contains duplicate {$label}.");
        }
    }
}
