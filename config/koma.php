<?php

$releaseVersionPath = base_path('VERSION');
$releaseVersion = is_file($releaseVersionPath)
    ? trim((string) file_get_contents($releaseVersionPath))
    : '';

if (preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?\z/D', $releaseVersion) !== 1) {
    throw new RuntimeException('KomaEngine VERSION is missing or invalid.');
}

return [
    'version' => $releaseVersion,

    // Set by the shipped Docker Compose stack, which provides MariaDB.
    'runtime' => env('KOMA_RUNTIME', 'standard'),

    'release' => [
        'commit' => env('KOMA_RELEASE_COMMIT', 'unavailable'),
        'build_id' => env('KOMA_RELEASE_BUILD_ID', 'development'),
        'source_dirty' => env('KOMA_RELEASE_DIRTY', 'unknown'),
        'trusted' => env('KOMA_TRUSTED_BUILD', false),
        'metadata_path' => base_path('KOMA-BUILD-METADATA.txt'),
    ],

    'paths' => [
        'plugins' => base_path('plugins'),
        'themes' => base_path('themes'),
    ],

    'uploads' => [
        'page_mimes' => ['jpg', 'jpeg', 'png', 'webp'],
        'page_max_kb' => 20480,
        // Lets the uploader return per-file recovery feedback just above the
        // durable 20 MB limit instead of failing an entire browser selection.
        'page_temporary_max_kb' => 25600,
        'page_max_width' => 20000,
        'page_max_height' => 20000,
        'bulk_max_chapters' => 25,
        'bulk_max_pages_per_chapter' => 500,
    ],

    'images' => [
        // Guards decompression bombs before GD allocates the decoded bitmap.
        'decode_max_pixels' => 12000000,
        'pages' => [
            'max_width' => 2400,
            'max_height' => 4000,
        ],
        'covers' => [
            'width' => 1000,
            'height' => 1400,
            'thumbnail_width' => 300,
            'thumbnail_height' => 420,
        ],
        'quality' => [
            'webp' => 82,
            'jpeg' => 88,
            'png_compression' => 7,
        ],
    ],

    'archives' => [
        'default_schema_version' => 2,
        'imports' => [
            'max_archive_kb' => 512000,
            'max_entries' => 10000,
            'max_manifest_bytes' => 5242880,
            'max_entry_bytes' => 26214400,
            'max_expanded_bytes' => 5368709120,
            'max_compression_ratio' => 200,
            // Record counts one manifest may describe. They are checked
            // before any per-record validation, and exports are held to the
            // same counts so every exported archive stays importable. Media
            // files are already bounded by max_entries.
            'max_genres' => 100,
            'max_tags' => 1000,
            'max_chapters' => 5000,
        ],
        // Applied to an imported external gallery when its manifest does not
        // say. Galleries land private and unpublished either way.
        'external_defaults' => [
            'series_status' => 'completed',
            'reading_direction' => 'rtl',
            'content_rating' => 'mature',
        ],
    ],

    'reading' => [
        'series_per_page' => 24,
    ],

    'comments' => [
        'enabled' => true,
        'hold_for_moderation' => false,
        'body_min_length' => 2,
        'body_max_length' => 2000,
        'max_links' => 5,
        'owner_edit_window_minutes' => 15,
        'per_page' => 20,
        'rate_limit_per_minute' => 5,
        'report_rate_limit_per_minute' => 6,
        'report_details_max_length' => 1000,
        'minimum_submit_seconds' => 2,
        'form_token_max_age_seconds' => 7200,
    ],

    'hooks' => [
        [
            'name' => 'koma.booted',
            'type' => 'action',
            'arguments' => 'none',
            'trigger' => 'After the engine, active theme, and enabled plugins boot.',
        ],
        [
            'name' => 'series.saved',
            'type' => 'action',
            'arguments' => 'App\Models\Series $series',
            'trigger' => 'After a series is created or updated.',
        ],
        [
            'name' => 'series.deleted',
            'type' => 'action',
            'arguments' => 'App\Models\Series $series',
            'trigger' => 'After a series is deleted.',
        ],
        [
            'name' => 'chapter.saved',
            'type' => 'action',
            'arguments' => 'App\Models\Chapter $chapter',
            'trigger' => 'After a chapter is created or updated.',
        ],
        [
            'name' => 'chapter.deleted',
            'type' => 'action',
            'arguments' => 'App\Models\Chapter $chapter',
            'trigger' => 'After a chapter is deleted.',
        ],
        [
            'name' => 'chapter.published',
            'type' => 'action',
            'arguments' => 'App\Models\Chapter $chapter',
            'trigger' => 'When a chapter becomes publicly published, immediately or when its schedule is due.',
        ],
        [
            'name' => 'chapter.scheduled',
            'type' => 'action',
            'arguments' => 'App\Models\Chapter $chapter',
            'trigger' => 'When a chapter is scheduled or rescheduled for future publication.',
        ],
        [
            'name' => 'page.saved',
            'type' => 'action',
            'arguments' => 'App\Models\Page $page',
            'trigger' => 'After an existing chapter page is reordered or replaced.',
        ],
        [
            'name' => 'page.deleted',
            'type' => 'action',
            'arguments' => 'App\Models\Page $page',
            'trigger' => 'After a chapter page is deleted and the remaining order is durable.',
        ],
        [
            'name' => 'reader.view',
            'type' => 'action',
            'arguments' => 'App\Models\Chapter $chapter',
            'trigger' => 'Before a published chapter reader response is rendered.',
        ],
        [
            'name' => 'comment.reported',
            'type' => 'action',
            'arguments' => 'App\Models\CommentReport $report, App\Models\Comment $comment',
            'trigger' => 'After a unique reader report is stored.',
        ],
        [
            'name' => 'comment.moderated',
            'type' => 'action',
            'arguments' => 'App\Models\Comment $comment, string $action, App\Models\User $moderator',
            'trigger' => 'After a comment moderation action is durable.',
        ],
        [
            'name' => 'admin.footer_text',
            'type' => 'filter',
            'arguments' => 'string $text',
            'trigger' => 'When the admin footer text is rendered.',
        ],
        [
            'name' => 'monetization.slot',
            'type' => 'filter',
            'arguments' => 'Htmlable $content, string $slot, array $context',
            'trigger' => 'When an enabled Community header or footer slot is rendered.',
        ],
        [
            'name' => 'analytics.event',
            'type' => 'action',
            'arguments' => 'App\\Engine\\Analytics\\CommunityAnalyticsEvent $event',
            'trigger' => 'After an authorized public series-detail or reader view is counted.',
        ],
    ],
];
