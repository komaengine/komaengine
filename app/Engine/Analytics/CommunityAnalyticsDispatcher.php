<?php

namespace App\Engine\Analytics;

use App\Engine\Hooks\HookManager;
use App\Models\Chapter;
use App\Models\Series;
use Carbon\CarbonImmutable;

final class CommunityAnalyticsDispatcher
{
    public function __construct(private readonly HookManager $hooks) {}

    public function recordSeriesView(Series $series, bool $authenticated): void
    {
        $this->dispatch('series.view', $series, null, $authenticated);
    }

    public function recordReaderView(Series $series, Chapter $chapter, bool $authenticated): void
    {
        $this->dispatch('reader.view', $series, $chapter, $authenticated);
    }

    private function dispatch(string $name, Series $series, ?Chapter $chapter, bool $authenticated): void
    {
        $this->hooks->doAction('analytics.event', new CommunityAnalyticsEvent(
            name: $name,
            occurredAt: CarbonImmutable::now('UTC'),
            seriesId: (int) $series->getKey(),
            chapterId: $chapter === null ? null : (int) $chapter->getKey(),
            authenticated: $authenticated,
        ));
    }
}
