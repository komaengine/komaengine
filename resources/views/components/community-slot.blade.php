@props(['zone'])

@php($currentSeries = request()->route('series'))
@php($currentSeries = $currentSeries instanceof \App\Models\Series ? $currentSeries : null)
@php($content = app(\App\Engine\Monetization\CommunitySlotRenderer::class)->render($zone, $currentSeries))

@if ($content)
    <aside class="community-slot community-slot-{{ $zone }}" data-community-slot="{{ $zone }}" aria-label="Community sponsor">
        {!! $content->toHtml() !!}
    </aside>
@endif
