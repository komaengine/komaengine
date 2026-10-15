@extends('theme::layout')

@section('title', \App\Support\Koma::siteTitle().' · Read manga online')

@section('content')
    @if ($featured)
        <a class="hero" href="{{ route('series.show', $featured) }}">
            <div class="hero-bg" style="background: {{ $featured->coverGradient() }}">
                @if ($featured->coverUrl())
                    <img src="{{ $featured->coverUrl() }}" alt="" style="width:100%;height:100%;object-fit:cover;opacity:.9">
                @endif
            </div>
            <div>
                <div class="eyebrow">Featured series</div>
                <h1>{{ $featured->title }}</h1>
                <p>{{ \Illuminate\Support\Str::limit($featured->synopsis, 180) }}</p>
                <div class="meta">
                    <span class="status-dot {{ $featured->status }}" style="color:#fff">{{ $featured->statusLabel() }}</span>
                    <span>{{ $featured->genres->pluck('name')->take(3)->implode(' · ') }}</span>
                </div>
                <span class="btn">Start reading →</span>
            </div>
        </a>
    @endif

    <section class="section">
        <div class="section-head">
            <h2>Latest updates</h2>
            <a href="{{ route('browse') }}">Browse all →</a>
        </div>
        @if ($latest->isNotEmpty())
            <div class="updates">
                @foreach ($latest as $chapter)
                    <a class="update" href="{{ route('reader', [$chapter->series, $chapter->displayNumber()]) }}">
                        <span class="cv" style="background: {{ $chapter->series->coverGradient() }}">
                            @if ($chapter->series->coverThumbnailUrl())
                                <img src="{{ $chapter->series->coverThumbnailUrl() }}" alt="" loading="lazy">
                            @else
                                {{ $chapter->series->monogram() }}
                            @endif
                        </span>
                        <span class="info">
                            <span class="t">{{ $chapter->series->title }}</span>
                            <span class="c">Chapter {{ $chapter->displayNumber() }}</span>
                            <span class="time" title="{{ \App\Support\Koma::formatDateTime($chapter->published_at) }}">{{ \App\Support\Koma::timeAgo($chapter->published_at) }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        @else
            <div class="empty">No chapters published yet.</div>
        @endif
    </section>

    <section class="section">
        <div class="section-head">
            <h2>Popular right now</h2>
        </div>
        @if ($popular->isNotEmpty())
            <div class="grid">
                @foreach ($popular as $series)
                    @include('theme::partials.series-card', ['series' => $series])
                @endforeach
            </div>
        @else
            <div class="empty">Nothing here yet.</div>
        @endif
    </section>
@endsection
