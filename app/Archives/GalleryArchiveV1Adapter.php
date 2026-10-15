<?php

namespace App\Archives;

use App\Archives\Contracts\GalleryArchiveAdapter;
use App\Models\Chapter;
use App\Models\Series;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Input adapter for a plain external gallery ZIP.
 *
 * This is deliberately provider-neutral: any tool that can write a small
 * `manifest.json` and a `pages/` directory can hand KomaEngine a gallery, and
 * the engine never knows or cares which site or scanner produced it. The
 * `source` string is recorded verbatim as the series' source reference so the
 * provenance survives the import, but nothing branches on its value.
 *
 * One gallery becomes one private series with a single draft chapter. Nothing
 * an adapter produces is published automatically — a human decides that.
 *
 * The manifest is version 1:
 *
 *     {
 *       "version": 1,
 *       "source": "my-scanner",              // required, free-form provider name
 *       "source_id": 1234,                   // required, the provider's own id
 *       "url": "https://example.test/g/1234",// optional, absolute http(s)
 *       "title": "Gallery title",            // required
 *       "title_original": "原題",             // optional
 *       "title_original_language": "ja",     // optional, defaults to `language`
 *       "language": "english",               // required, name or BCP-47 tag
 *       "published_on": "2019-05-04",        // optional, Y-m-d
 *       "work_format": "doujinshi",          // optional
 *       "content_rating": "mature",          // optional, safe|mature
 *       "artists": ["…"], "groups": ["…"],   // optional
 *       "franchises": ["…"], "characters": ["…"], "tags": ["…"],
 *       "pages": ["001.webp", "002.webp"]    // required, files under pages/
 *     }
 *
 * @see docs/koma-archive-v2.md
 */
final class GalleryArchiveV1Adapter implements GalleryArchiveAdapter
{
    /** Optional list-of-strings fields, in the order they are validated. */
    private const NAME_LISTS = ['artists', 'groups', 'franchises', 'characters', 'tags'];

    /**
     * A Koma Archive always carries a `format` key; a gallery manifest never
     * does. That, plus the version and a page list, tells the two apart without
     * either format having to know about the other.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function supports(array $manifest): bool
    {
        return ! isset($manifest['format'])
            && ($manifest['version'] ?? null) === 1
            && is_string($manifest['source'] ?? null)
            && is_array($manifest['pages'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array{source_path:string,archive_path:string}>
     */
    public function pages(array $manifest): array
    {
        $this->validate($manifest);
        $pages = [];

        foreach ($manifest['pages'] as $index => $filename) {
            $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
            $pages[] = [
                'source_path' => 'pages/'.$filename,
                'archive_path' => 'media/external/pages/'
                    .str_pad((string) ($index + 1), 6, '0', STR_PAD_LEFT)
                    .'.'.$extension,
            ];
        }

        return $pages;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<array{archive_path:string,mime_type:string,sha256:string,bytes:int,width:int,height:int}>  $media
     * @return array<string, mixed>
     */
    public function toKomaManifest(
        array $source,
        array $media,
        ?CarbonImmutable $importedAt = null,
    ): array {
        $this->validate($source);
        $importedAt ??= CarbonImmutable::now('UTC');
        $timestamp = $importedAt->utc()->format('Y-m-d\TH:i:s\Z');

        $artists = $this->names($source['artists'] ?? []);
        $groups = $this->names($source['groups'] ?? []);
        $credits = [];

        foreach ($artists as $artist) {
            $credits[] = ['name' => $artist, 'role' => 'artist', 'type' => 'person'];
        }

        foreach ($groups as $group) {
            $credits[] = ['name' => $group, 'role' => 'creator_group', 'type' => 'organization'];
        }

        // Galleries with no language at all are normal — image-only works, game
        // CG sets. The chapter simply carries no language rather than a guess.
        $language = $this->language((string) ($source['language'] ?? ''));
        $alternateTitles = [];
        $originalTitle = trim((string) ($source['title_original'] ?? ''));

        if ($originalTitle !== '' && $originalTitle !== $source['title']) {
            $alternateTitles[] = [
                'language' => $this->language((string) ($source['title_original_language'] ?? $source['language'] ?? ''))
                    ?? $language
                    ?? 'und',
                'title' => $originalTitle,
                'type' => 'original',
            ];
        }

        $publishedOn = $source['published_on'] ?? null;
        $sourceId = (int) $source['source_id'];
        $url = $this->galleryUrl($source['url'] ?? null);
        $pages = [];

        foreach ($media as $index => $descriptor) {
            $pages[] = array_merge([
                'source_id' => $index + 1,
                'sort_order' => $index + 1,
            ], $descriptor, [
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }

        return [
            'format' => KomaArchiveV2::FORMAT,
            'schema_version' => KomaArchiveV2::SCHEMA_VERSION,
            'exported_at' => $timestamp,
            'series' => [
                'source_id' => $sourceId,
                'title' => $source['title'],
                'alternate_titles' => $alternateTitles,
                'slug' => Str::slug($source['title']) ?: 'gallery-'.$sourceId,
                'author' => null,
                'artist' => $artists === [] ? null : implode(', ', $artists),
                'credits' => $credits,
                'franchises' => $this->names($source['franchises'] ?? []),
                'characters' => $this->names($source['characters'] ?? []),
                'source_references' => [[
                    'provider' => trim((string) $source['source']),
                    'external_id' => (string) $sourceId,
                    'url' => $url,
                ]],
                'year' => is_string($publishedOn) ? (int) substr($publishedOn, 0, 4) : null,
                'publication_date' => $publishedOn,
                'work_format' => $this->workFormat($source['work_format'] ?? null),
                'status' => $this->seriesStatus(),
                'reading_direction' => $this->readingDirection(),
                'default_reader_mode' => null,
                'content_rating' => $this->contentRating($source['content_rating'] ?? null),
                'is_public' => false,
                'synopsis' => null,
                'cover_hue' => $sourceId % 360,
                'views' => 0,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'genres' => [],
                'tags' => $this->tags($source['tags'] ?? [], $timestamp),
                'cover' => null,
                'cover_thumbnail' => null,
                'chapters' => [[
                    'source_id' => $sourceId,
                    'number' => '1.00',
                    'title' => null,
                    // Deliberately null: a gallery's "group" is the circle that
                    // made the work, which is credited above, not a scanlation team.
                    'scanlation_group' => null,
                    'language' => $language,
                    'status' => Chapter::STATUS_DRAFT,
                    'published_at' => null,
                    'views' => 0,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                    'pages' => $pages,
                ]],
            ],
        ];
    }

    /** @param array<string, mixed> $manifest */
    private function validate(array $manifest): void
    {
        $rules = [
            'version' => ['required', 'integer', 'in:1'],
            // Same shape the Koma v2 validator enforces for a source reference
            // provider, so a valid gallery manifest can never produce a Koma
            // manifest that the validator then rejects.
            'source' => ['required', 'string', 'max:100', 'regex:/\A[a-z0-9]+(?:[._-][a-z0-9]+)*\z/'],
            'source_id' => ['required', 'integer', 'min:1'],
            'url' => ['nullable', 'string', 'max:2048'],
            'title' => ['required', 'string', 'max:255'],
            'title_original' => ['nullable', 'string', 'max:255'],
            'title_original_language' => ['nullable', 'string', 'max:100'],
            'language' => ['nullable', 'string', 'max:100'],
            'published_on' => ['nullable', 'date_format:Y-m-d'],
            'work_format' => ['nullable', 'string', 'max:100'],
            'content_rating' => ['nullable', 'in:safe,mature'],
            'pages' => ['required', 'array', 'min:1', 'max:9999'],
            'pages.*' => [
                'required',
                'string',
                'distinct:strict',
                'max:255',
                'regex:/\A[A-Za-z0-9._-]+\.(?:jpe?g|png|webp)\z/i',
            ],
        ];

        foreach (self::NAME_LISTS as $list) {
            $rules[$list] = ['nullable', 'array', 'max:1000'];
            $rules[$list.'.*'] = ['required', 'string', 'max:255'];
        }

        // Gallery tags become Koma tags, so they share the Koma tag limit and
        // an oversized list is refused before any page is staged.
        $rules['tags'][2] = 'max:'.min(1000, max(1, (int) config('koma.archives.imports.max_tags', 1000)));

        try {
            Validator::make($manifest, $rules)->validate();
        } catch (ValidationException $e) {
            throw new ArchiveValidationException(
                'External gallery manifest validation failed: '.$e->validator->errors()->first(),
                previous: $e,
            );
        }

        $unknown = array_diff(array_keys($manifest), array_keys($rules));

        if ($unknown !== []) {
            throw new ArchiveValidationException(
                'External gallery manifest contains unsupported keys: '.implode(', ', $unknown),
            );
        }
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function names(array $names): array
    {
        $names = array_map(fn (string $name): string => trim($name), $names);

        return array_values(array_unique(array_filter($names), SORT_STRING));
    }

    /**
     * @param  list<string>  $names
     * @return list<array<string, mixed>>
     */
    private function tags(array $names, string $timestamp): array
    {
        $tags = [];
        $slugs = [];

        foreach ($this->names($names) as $index => $name) {
            $base = Str::slug($name) ?: 'tag-'.($index + 1);
            $slug = $base;
            $suffix = 2;

            while (isset($slugs[$slug])) {
                $slug = $base.'-'.$suffix++;
            }

            $slugs[$slug] = true;
            $tags[] = [
                'source_id' => $index + 1,
                'name' => $name,
                'slug' => $slug,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        return $tags;
    }

    /** Accept a BCP-47 tag as-is, or translate the handful of common language names. */
    private function language(string $language): ?string
    {
        return match (Str::lower(trim($language))) {
            'english' => 'en',
            'japanese' => 'ja',
            'korean' => 'ko',
            'chinese' => 'zh',
            default => preg_match('/\A[a-z]{2,3}(?:-[a-z0-9]{2,8})*\z/i', $language) === 1
                ? Str::lower($language)
                : null,
        };
    }

    private function workFormat(?string $type): string
    {
        $normalized = Str::of((string) $type)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();

        return $normalized === 'artistcg' ? 'artist_cg' : ($normalized ?: 'other');
    }

    /**
     * Only absolute http(s) URLs are recorded; anything else is dropped rather
     * than guessed at.
     *
     * This deliberately uses the same rule the Koma Archive validator applies to
     * `source_references.*.url`. `FILTER_VALIDATE_URL` is stricter — it rejects
     * non-ASCII paths — and real gallery URLs routinely carry them, so using it
     * here would silently throw away provenance the validator would have kept.
     */
    private function galleryUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        return preg_match('/\Ahttps?:\/\/[^\s]+\z/u', $url) === 1 ? $url : null;
    }

    private function contentRating(?string $rating): string
    {
        if (in_array($rating, ['safe', 'mature'], true)) {
            return $rating;
        }

        $default = (string) config('koma.archives.external_defaults.content_rating', 'mature');

        return in_array($default, ['safe', 'mature'], true) ? $default : 'mature';
    }

    private function seriesStatus(): string
    {
        $status = (string) config('koma.archives.external_defaults.series_status', 'completed');

        return in_array($status, Series::STATUSES, true) ? $status : 'completed';
    }

    private function readingDirection(): string
    {
        $direction = (string) config('koma.archives.external_defaults.reading_direction', 'rtl');

        return in_array($direction, Series::DIRECTIONS, true) ? $direction : 'rtl';
    }
}
