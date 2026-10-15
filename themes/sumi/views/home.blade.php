@extends('theme::layout')

@section('title', \App\Support\Koma::siteTitle().' · Read manga online')

@section('content')
    <div class="sm-page home-page">
        <section class="editorial-hero" aria-labelledby="editorial-heading">
            @if ($featured)
                <div class="hero-veil" style="background: {{ $featured->coverGradient() }}"></div>
                <div class="hero-copy">
                    <p class="eyebrow">Editorial selection</p><h1 id="editorial-heading">{{ $featured->title }}</h1>
                    <p>{{ \Illuminate\Support\Str::limit((string) $featured->synopsis, 220) ?: 'A considered selection from the catalog, ready for your next reading session.' }}</p>
                    <div class="meta-line"><span class="status-chip">{{ $featured->statusLabel() }}</span>@foreach ($featured->genres->take(3) as $genre)<span>{{ $genre->name }}</span>@endforeach</div>
                    <a class="theme-button accent" href="{{ route('series.show', $featured) }}">Open series <span aria-hidden="true">→</span></a>
                </div>
                <a class="hero-cover" href="{{ route('series.show', $featured) }}" style="background: {{ $featured->coverGradient() }}" aria-label="View {{ $featured->title }}">
                    @if ($featured->coverUrl())<img src="{{ $featured->coverUrl() }}" alt="{{ $featured->title }} cover">@else<span>{{ $featured->monogram() }}</span>@endif
                </a>
            @else
                <div class="hero-copy hero-empty"><p class="eyebrow">A quiet place for stories</p><h1 id="editorial-heading">Every panel, given room.</h1><p>Published titles will settle into this editorial catalog as your library grows.</p><a class="theme-button accent" href="{{ route('browse') }}">Browse the catalog</a></div>
                <div class="hero-glyph" aria-hidden="true">墨</div>
            @endif
        </section>

        <section id="latest" class="home-section" aria-labelledby="latest-heading">
            <header class="section-header"><div><p class="eyebrow">New in the archive</p><h2 id="latest-heading">Latest updates</h2></div><a href="{{ route('browse') }}">View all titles →</a></header>
            @if ($latest->isNotEmpty())
                <div class="update-grid">
                    @foreach ($latest as $chapter)
                        <a class="update-card" href="{{ route('reader', [$chapter->series, $chapter->displayNumber()]) }}">
                            <span class="mini-cover" style="background: {{ $chapter->series->coverGradient() }}">@if ($chapter->series->coverThumbnailUrl())<img src="{{ $chapter->series->coverThumbnailUrl() }}" alt="" loading="lazy">@else{{ $chapter->series->monogram() }}@endif</span>
                            <span class="update-copy"><strong>{{ $chapter->series->title }}</strong><span>Chapter {{ $chapter->displayNumber() }}{{ $chapter->title ? ' · '.$chapter->title : '' }}</span></span>
                            <time datetime="{{ $chapter->published_at?->toIso8601String() }}" title="{{ \App\Support\Koma::formatDateTime($chapter->published_at) }}">{{ \App\Support\Koma::timeAgo($chapter->published_at) }}</time><span aria-hidden="true">↗</span>
                        </a>
                    @endforeach
                </div>
            @else<div class="empty-state"><span aria-hidden="true">＋</span><strong>No chapters published yet.</strong><p>New releases will appear here automatically.</p></div>@endif
        </section>

        <section class="home-section" aria-labelledby="popular-heading">
            <header class="section-header"><div><p class="eyebrow">Reader attention</p><h2 id="popular-heading">Popular in the archive</h2></div></header>
            @if ($popular->isNotEmpty())
                <div class="popular-rail">@foreach ($popular->take(7) as $index => $series)<a class="popular-card" href="{{ route('series.show', $series) }}"><span class="popular-rank">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span><span class="popular-cover" style="background: {{ $series->coverGradient() }}">@if ($series->coverThumbnailUrl())<img src="{{ $series->coverThumbnailUrl() }}" alt="" loading="lazy">@else{{ $series->monogram() }}@endif</span><span><strong>{{ $series->title }}</strong><small>{{ $series->statusLabel() }} · {{ $series->published_chapters_count }} ch</small></span></a>@endforeach</div>
            @else<div class="empty-state compact"><strong>The archive is still settling.</strong><p>Popular titles will appear as readers explore.</p></div>@endif
        </section>
    </div>
@endsection
