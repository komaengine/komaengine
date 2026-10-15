<?php

namespace App\Engine\Monetization;

use App\Engine\Hooks\HookManager;
use App\Engine\Settings\Settings;
use App\Models\Chapter;
use App\Models\Series;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

final class CommunitySlotRenderer
{
    public function __construct(
        private readonly Settings $settings,
        private readonly HookManager $hooks,
    ) {}

    /**
     * Render plugin-provided content for a named Community slot.
     *
     * Only Htmlable values may opt into trusted markup. String values are
     * deliberately converted into escaped text before the Blade component
     * emits them.
     */
    public function render(string $slot, ?Series $series = null, ?Chapter $chapter = null): ?Htmlable
    {
        if (! (bool) $this->settings->get('community_ads_enabled', false)) {
            return null;
        }

        $content = $this->hooks->applyFilters(
            'monetization.slot',
            new HtmlString(''),
            $slot,
            $this->context($series, $chapter),
        );

        if ($content instanceof Htmlable) {
            return $content->toHtml() === '' ? null : $content;
        }

        if (is_string($content) && $content !== '') {
            return new HtmlString(e($content));
        }

        return null;
    }

    /** @return array{route_name: string|null, series_id?: int, chapter_id?: int} */
    private function context(?Series $series, ?Chapter $chapter): array
    {
        $context = ['route_name' => request()->route()?->getName()];

        if ($series !== null) {
            $context['series_id'] = (int) $series->getKey();
        }

        if ($chapter !== null) {
            $context['chapter_id'] = (int) $chapter->getKey();
        }

        return $context;
    }
}
