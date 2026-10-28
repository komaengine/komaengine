<?php

namespace App\Archives;

use App\Engine\Settings\Settings;
use App\Models\Chapter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * How an import sets the visibility of the new series and the status of its
 * chapters, applied to the validated manifest before anything is written.
 */
final class ArchivePublishingMode
{
    /** Koma Archives keep their own values; adapter-produced archives use the site defaults. */
    public const AUTOMATIC = 'automatic';

    public const SITE_DEFAULTS = 'site_defaults';

    public const PRIVATE_DRAFTS = 'private_drafts';

    public const VALUES = [self::AUTOMATIC, self::SITE_DEFAULTS, self::PRIVATE_DRAFTS];

    private function __construct() {}

    /**
     * @return array{is_public: bool, chapter_status: string}
     */
    public static function siteDefaults(Settings $settings): array
    {
        return [
            'is_public' => $settings->get('default_series_visibility', 'private') === 'public',
            'chapter_status' => $settings->get('default_chapter_status', Chapter::STATUS_DRAFT) === Chapter::STATUS_PUBLISHED
                ? Chapter::STATUS_PUBLISHED
                : Chapter::STATUS_DRAFT,
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest  a validated Koma manifest
     * @param  bool  $fromAdapter  whether a gallery adapter produced the manifest
     * @return array<string, mixed>
     */
    public static function apply(
        array $manifest,
        ?string $mode,
        bool $fromAdapter,
        Settings $settings,
        CarbonInterface $now,
    ): array {
        $mode = in_array($mode, self::VALUES, true) ? $mode : self::AUTOMATIC;

        if ($mode === self::AUTOMATIC && ! $fromAdapter) {
            return $manifest;
        }

        [$isPublic, $chapterStatus] = $mode === self::PRIVATE_DRAFTS
            ? [false, Chapter::STATUS_DRAFT]
            : array_values(self::siteDefaults($settings));

        // Manifest timestamps use the archive format the importer parses.
        $publishedAt = $chapterStatus === Chapter::STATUS_PUBLISHED
            ? CarbonImmutable::instance($now)->utc()->format('Y-m-d\\TH:i:s\\Z')
            : null;

        $manifest['series']['is_public'] = $isPublic;

        foreach ($manifest['series']['chapters'] as $index => $chapter) {
            $manifest['series']['chapters'][$index]['status'] = $chapterStatus;
            $manifest['series']['chapters'][$index]['published_at'] = $publishedAt;
        }

        return $manifest;
    }
}
