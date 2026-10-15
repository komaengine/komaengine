@extends('theme::layout')

@section('title', \App\Support\Koma::siteTitle().' · Read manga online')

@section('content')
    <section class="spotlight" aria-labelledby="spotlight-heading">
        <div class="ks-wrap spotlight-inner">
            @if ($featured)
                <div class="spotlight-copy">
                    <p class="eyebrow">Seasonal spotlight</p>
                    <h1 id="spotlight-heading">{{ $featured->title }}</h1>
                    <p class="spotlight-synopsis">{{ \Illuminate\Support\Str::limit((string) $featured->synopsis, 210) ?: 'A standout title from the catalog, ready for your next reading session.' }}</p>
                    <div class="spotlight-meta">
                        <span class="status-pill {{ $featured->status }}">{{ $featured->statusLabel() }}</span>
                        @foreach ($featured->genres->take(3) as $genre)
                            <span>{{ $genre->name }}</span>
                        @endforeach
                    </div>
                    <a class="ks-button accent" href="{{ route('series.show', $featured) }}">Open series <span aria-hidden="true">→</span></a>
                </div>
                <a class="spotlight-cover" href="{{ route('series.show', $featured) }}" style="background: {{ $featured->coverGradient() }}" aria-label="View {{ $featured->title }}">
                    @if ($featured->coverUrl())
                        <img src="{{ $featured->coverUrl() }}" alt="{{ $featured->title }} cover">
                    @else
                        <span>{{ $featured->monogram() }}</span>
                    @endif
                </a>
            @else
                <div class="spotlight-copy spotlight-empty-copy">
                    <p class="eyebrow">Your catalog, your momentum</p>
                    <h1 id="spotlight-heading">Stories that burn bright.</h1>
                    <p class="spotlight-synopsis">Published titles will take center stage here as your library grows.</p>
                    <a class="ks-button accent" href="{{ route('browse') }}">Browse the catalog</a>
                </div>
                <div class="spotlight-placeholder" aria-hidden="true"><span>火</span></div>
            @endif
        </div>
    </section>

    <div class="ks-wrap home-columns">
        <section id="latest" class="latest-panel" aria-labelledby="latest-heading">
            <div class="section-heading">
                <div><p class="eyebrow">Fresh from the press</p><h2 id="latest-heading">Latest updates</h2></div>
                <a href="{{ route('browse') }}">View all titles <span aria-hidden="true">→</span></a>
            </div>

            @if ($latest->isNotEmpty())
                <div class="update-feed">
                    @foreach ($latest as $chapter)
                        <a class="update-row" href="{{ route('reader', [$chapter->series, $chapter->displayNumber()]) }}">
                            <span class="update-cover" style="background: {{ $chapter->series->coverGradient() }}">
                                @if ($chapter->series->coverThumbnailUrl())
                                    <img src="{{ $chapter->series->coverThumbnailUrl() }}" alt="" loading="lazy">
                                @else
                                    <span>{{ $chapter->series->monogram() }}</span>
                                @endif
                            </span>
                            <span class="update-copy">
                                <strong>{{ $chapter->series->title }}</strong>
                                <span>Chapter {{ $chapter->displayNumber() }}{{ $chapter->title ? ' · '.$chapter->title : '' }}</span>
                            </span>
                            <time datetime="{{ $chapter->published_at?->toIso8601String() }}" title="{{ \App\Support\Koma::formatDateTime($chapter->published_at) }}">{{ \App\Support\Koma::timeAgo($chapter->published_at) }}</time>
                            <span class="update-arrow" aria-hidden="true">→</span>
                        </a>
                    @endforeach
                </div>
            @else
                <div class="empty-state"><span class="empty-mark" aria-hidden="true">火</span><strong>No chapters published yet.</strong><p>New releases will appear here automatically.</p></div>
            @endif
        </section>

        <aside class="ranking-panel" aria-labelledby="ranking-heading">
            <div class="section-heading compact"><div><p class="eyebrow">Reader favorites</p><h2 id="ranking-heading">Popular now</h2></div></div>
            @if ($popular->isNotEmpty())
                <ol class="ranking-list">
                    @foreach ($popular->take(6) as $rank => $series)
                        <li>
                            <a href="{{ route('series.show', $series) }}">
                                <span class="rank-number">{{ str_pad((string) ($rank + 1), 2, '0', STR_PAD_LEFT) }}</span>
                                <span class="rank-cover" style="background: {{ $series->coverGradient() }}">
                                    @if ($series->coverThumbnailUrl())
                                        <img src="{{ $series->coverThumbnailUrl() }}" alt="" loading="lazy">
                                    @else
                                        {{ $series->monogram() }}
                                    @endif
                                </span>
                                <span class="rank-copy"><strong>{{ $series->title }}</strong><span>{{ $series->statusLabel() }} · {{ $series->published_chapters_count }} ch</span></span>
                            </a>
                        </li>
                    @endforeach
                </ol>
            @else
                <div class="empty-state small"><strong>Rankings are warming up.</strong><p>Popular titles will appear once the catalog has activity.</p></div>
            @endif
        </aside>
    </div>
@endsection
