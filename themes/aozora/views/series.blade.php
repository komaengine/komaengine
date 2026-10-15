@extends('theme::layout')

@section('title', $series->title.' · '.\App\Support\Koma::siteTitle())
@section('og_title', $series->title)
@section('description', \Illuminate\Support\Str::limit((string) $series->synopsis, 160))
@section('og_type', 'book')
@if ($series->coverUrl())
    @section('og_image', $series->coverUrl())
@endif

@section('content')
    @unless ($series->is_public)
        <div class="private-preview" role="status">
            <strong>Private preview</strong>
            <span>This series is hidden from readers. Only Editors and Administrators can view this page.</span>
        </div>
    @endunless

    <div class="series-head">
        <div class="cover" style="background: {{ $series->coverGradient() }}">
            @if ($series->coverUrl())
                <img src="{{ $series->coverUrl() }}" alt="{{ $series->title }} cover">
            @else
                {{ $series->monogram() }}
            @endif
        </div>
        <div>
            <h1 class="series-title">{{ $series->title }}</h1>
            @if (filled(data_get($series->alternate_titles, '0.title')))
                <div class="series-by">{{ data_get($series->alternate_titles, '0.title') }}</div>
            @endif
            <div class="series-by">
                by {{ $series->author ?? 'Unknown' }}@if ($series->artist && $series->artist !== $series->author) · art by {{ $series->artist }}@endif
                @if ($series->year) · {{ $series->year }}@endif
                @if ($series->work_format) · {{ \Illuminate\Support\Str::headline($series->work_format) }}@endif
                @if ($series->franchises) · {{ implode(', ', $series->franchises) }}@endif
            </div>

            <div class="series-genres">
                <span class="status-dot {{ $series->status }}">{{ $series->statusLabel() }}</span>
                @foreach ($series->genres as $genre)
                    <a href="{{ route('browse', ['genre' => $genre->slug]) }}" class="chip">{{ $genre->name }}</a>
                @endforeach
            </div>

            <div class="series-stats">
                <div><div class="n">{{ $chapters->count() }}</div><div class="l">Chapters</div></div>
                <div><div class="n">{{ \Illuminate\Support\Number::abbreviate($series->views) }}</div><div class="l">Views</div></div>
                <div><div class="n">{{ ucfirst(str_replace(['rtl', 'ltr', 'vertical'], ['Right→Left', 'Left→Right', 'Webtoon'], $series->reading_direction)) }}</div><div class="l">Direction</div></div>
            </div>

            @if ($firstChapter)
                <div class="series-actions">
                    <a class="btn" href="{{ route('reader', [$series, $firstChapter->displayNumber()]) }}">Start from Ch. {{ $firstChapter->displayNumber() }}</a>
                    <a class="btn ghost" href="{{ route('reader', [$series, $chapters->first()->displayNumber()]) }}">Latest chapter</a>
                </div>
            @endif

            @if ($series->synopsis)
                <p class="synopsis">{{ $series->synopsis }}</p>
            @endif
        </div>
    </div>

    <section class="section" style="margin-top:34px">
        <div class="section-head"><h2>Chapters</h2></div>
        @if ($chapters->isNotEmpty())
            <div class="chapter-list">
                @foreach ($chapters as $chapter)
                    <a class="chapter-row" href="{{ route('reader', [$series, $chapter->displayNumber()]) }}">
                        <span class="num">{{ $chapter->displayNumber() }}</span>
                        <span class="ct">{{ $chapter->title ?: 'Chapter '.$chapter->displayNumber() }}</span>
                        <span class="cmeta">@if ($chapter->language){{ $chapter->language }} · @endif{{ $chapter->pages_count }} pages · {{ \App\Support\Koma::timeAgo($chapter->published_at) }}</span>
                    </a>
                @endforeach
            </div>
        @else
            <div class="empty">No chapters have been published yet.</div>
        @endif
    </section>

    @include('theme::partials.comments', [
        'series' => $series,
        'chapter' => null,
        'comments' => $comments,
    ])
@endsection
