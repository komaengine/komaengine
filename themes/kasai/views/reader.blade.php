@php
    $pages = $chapter->pages->map(fn ($page) => [
        'url' => $page->url(),
        'w' => $page->width,
        'h' => $page->height,
        'available' => \Illuminate\Support\Facades\Storage::disk('media')->exists($page->image_path),
    ])->values();
    $config = [
        'direction' => $series->reading_direction,
        'initialMode' => $readerMode,
        'authenticated' => auth()->check(),
        'preferenceUrl' => auth()->check() ? route('reader.preferences.mode') : null,
        'prev' => $prev ? route('reader', [$series, $prev->displayNumber()]) : null,
        'next' => $next ? route('reader', [$series, $next->displayNumber()]) : null,
        'seriesUrl' => route('series.show', $series),
        'pages' => $pages,
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $series->title }} · Ch. {{ $chapter->displayNumber() }}</title>
    <meta name="description" content="Read {{ $series->title }} chapter {{ $chapter->displayNumber() }} online.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;600;700;800&amp;family=Schibsted+Grotesk:wght@700;800&amp;family=Spline+Sans+Mono:wght@500;600&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="@themeAsset('theme.css')">
    @php $accent = \App\Support\Koma::themes()->active()['accent'] ?? null; @endphp
    @if ($accent)
        <style>:root { --accent: {!! $accent !!}; }</style>
    @endif
</head>
<body class="reader">
    <header class="reader-bar" id="readerBar">
        <a class="r-back" href="{{ route('series.show', $series) }}" aria-label="Back to {{ $series->title }}">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <div class="r-title">
            <div class="s"><span class="r-series-name">{{ $series->title }}</span>@unless ($series->is_public)<span class="r-private">Private preview</span>@endunless</div>
            <div class="c">Chapter {{ $chapter->displayNumber() }}{{ $chapter->title ? ' · '.$chapter->title : '' }}</div>
        </div>
        <div class="r-tools">
            <a class="r-chap-btn r-nav-text" href="{{ $config['prev'] ?? '#' }}" @if (! $prev) aria-disabled="true" @endif>‹ Prev</a>
            <span class="r-counter" id="rCounter"></span>
            <a class="r-chap-btn r-nav-text" href="{{ $config['next'] ?? '#' }}" @if (! $next) aria-disabled="true" @endif>Next ›</a>
            <a class="r-chap-btn r-discuss-btn" href="#comments" aria-label="Jump to chapter discussion">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/></svg><span class="r-discuss-label">Discuss</span>
            </a>
            <div class="r-modes" id="rModes" role="group" aria-label="Reader presentation" aria-describedby="rPreferenceHint">
                <button type="button" data-mode="rtl" aria-label="Read pages right to left" aria-pressed="{{ $readerMode === 'rtl' ? 'true' : 'false' }}" @class(['on' => $readerMode === 'rtl'])>RTL</button>
                <button type="button" data-mode="ltr" aria-label="Read pages left to right" aria-pressed="{{ $readerMode === 'ltr' ? 'true' : 'false' }}" @class(['on' => $readerMode === 'ltr'])>LTR</button>
                <button type="button" data-mode="vertical" aria-label="Read as a vertical webtoon" aria-pressed="{{ $readerMode === 'vertical' ? 'true' : 'false' }}" @class(['on' => $readerMode === 'vertical'])>Webtoon</button>
            </div>
            <span id="rPreferenceHint" class="sr-only">{{ auth()->check() ? 'Changes are saved to your account.' : 'Changes are saved on this device.' }}</span>
            <span id="rPreferenceStatus" class="r-pref-status" role="status" aria-live="polite"></span>
        </div>
    </header>

    <main class="reader-stage {{ $readerMode === 'vertical' ? 'webtoon' : 'paged' }}" id="readerStage">
        @forelse ($pages as $index => $page)
            <div @class(['page', 'current' => $readerMode !== 'vertical' && $index === 0]) data-index="{{ $index }}">
                @if ($page['available'])
                    <img @if ($index < 2) src="{{ $page['url'] }}" @else data-src="{{ $page['url'] }}" @endif alt="Page {{ $index + 1 }}" loading="lazy" @if ($page['w']) width="{{ $page['w'] }}" height="{{ $page['h'] }}" @endif>
                @else
                    <div class="reader-missing" role="img" aria-label="Page {{ $index + 1 }} image unavailable"><strong>Page {{ $index + 1 }} is unavailable.</strong><span>The source image is missing from storage.</span></div>
                @endif
            </div>
        @empty
            <div class="reader-empty"><strong>No pages available.</strong><a href="{{ route('series.show', $series) }}">Back to series</a></div>
        @endforelse

        <div class="reader-end" id="readerEnd" @if ($readerMode !== 'vertical') style="display:none" @endif>
            <p>End of chapter {{ $chapter->displayNumber() }}</p>
            @if ($next)
                <a class="ks-button accent" href="{{ $config['next'] }}">Next chapter →</a>
            @else
                <a class="ks-button reader-quiet" href="{{ route('series.show', $series) }}">Back to series</a>
            @endif
        </div>
    </main>

    <div class="reader-zones" id="readerZones" @if ($readerMode === 'vertical') style="display:none" @endif aria-hidden="true">
        <div class="z" data-zone="left"></div>
        <div class="z" data-zone="right"></div>
    </div>

    <div class="reader-progress" id="readerProgress" aria-hidden="true"><span id="readerProgressFill"></span></div>

    @include('theme::partials.comments', ['series' => $series, 'chapter' => $chapter, 'comments' => $comments])

    <script id="readerConfig" type="application/json">{!! json_encode($config) !!}</script>
    <script src="@themeAsset('reader.js')" defer></script>
</body>
</html>
