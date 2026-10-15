<?php

namespace App\Engine\Analytics;

use Carbon\CarbonImmutable;

/**
 * Privacy-minimized public-view event delivered only to enabled plugins.
 */
final readonly class CommunityAnalyticsEvent
{
    public function __construct(
        public string $name,
        public CarbonImmutable $occurredAt,
        public int $seriesId,
        public ?int $chapterId,
        public bool $authenticated,
    ) {}
}
