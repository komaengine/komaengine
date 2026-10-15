@extends('theme::layout')

@section('title', $series->title.' · '.\App\Support\Koma::siteTitle())
@section('og_title', $series->title)
@section('description', \Illuminate\Support\Str::limit((string) $series->synopsis, 160))
@section('og_type', 'book')
@if ($series->coverUrl())@section('og_image', $series->coverUrl())@endif

@php
    $directionLabel = match ($series->reading_direction) { 'ltr' => 'Left to right', 'vertical' => 'Webtoon', default => 'Right to left' };
@endphp

@section('content')
    @unless ($series->is_public)<div class="private-preview" role="status"><strong>Private preview</strong><span>This series is hidden from readers. Only Editors and Administrators can view this page.</span></div>@endunless
    <section class="series-hero">
        <div class="series-backdrop" style="background: {{ $series->coverGradient() }}"></div>
        <div class="series-hero-inner">
            <div class="series-cover" style="background: {{ $series->coverGradient() }}">@if ($series->content_rating === 'mature')<span class="rating-badge">18+</span>@endif @if ($series->coverUrl())<img src="{{ $series->coverUrl() }}" alt="{{ $series->title }} cover">@else<span>{{ $series->monogram() }}</span>@endif</div>
            <div class="series-copy"><p class="eyebrow">{{ $series->statusLabel() }} series</p><h1>{{ $series->title }}</h1>@if (filled(data_get($series->alternate_titles, '0.title')))<p class="credits">{{ data_get($series->alternate_titles, '0.title') }}</p>@endif<p class="credits">by {{ $series->author ?: 'Unknown' }} @if ($series->artist && $series->artist !== $series->author)<span>·</span> art by {{ $series->artist }}@endif @if ($series->year)<span>·</span> {{ $series->year }}@endif</p>
                <div class="series-chips"><span class="status-chip">{{ $series->statusLabel() }}</span>@foreach ($series->genres as $genre)<a href="{{ route('browse', ['genre' => $genre->slug]) }}">{{ $genre->name }}</a>@endforeach</div>
                @if ($firstChapter)<div class="series-actions"><a class="theme-button accent" href="{{ route('reader', [$series, $firstChapter->displayNumber()]) }}">Start at Ch. {{ $firstChapter->displayNumber() }} →</a><a class="theme-button quiet" href="{{ route('reader', [$series, $chapters->first()->displayNumber()]) }}">Latest chapter</a></div>@endif
            </div>
        </div>
    </section>
    <div class="series-stats"><div><strong>{{ $chapters->count() }}</strong><span>Chapters</span></div><div><strong>{{ \Illuminate\Support\Number::abbreviate($series->views) }}</strong><span>Views</span></div><div><strong>{{ $directionLabel }}</strong><span>Reading</span></div><div><strong>{{ ucfirst($series->content_rating) }}</strong><span>Rating</span></div></div>

    <div class="sm-page series-content">
        <section class="chapter-section" aria-labelledby="chapters-heading">
            <header class="section-header"><div><p class="eyebrow">Release archive</p><h2 id="chapters-heading">Chapters</h2></div><span class="result-count">Newest first</span></header>
            @if ($chapters->isNotEmpty())<ol class="chapter-list">@foreach ($chapters as $chapter)<li><a href="{{ route('reader', [$series, $chapter->displayNumber()]) }}"><span class="chapter-number">{{ $chapter->displayNumber() }}</span><span class="chapter-copy"><strong>{{ $chapter->title ?: 'Chapter '.$chapter->displayNumber() }}</strong><small>@if ($chapter->language){{ $chapter->language }} · @endif{{ $chapter->pages_count }} {{ \Illuminate\Support\Str::plural('page', $chapter->pages_count) }}</small></span><time datetime="{{ $chapter->published_at?->toIso8601String() }}" title="{{ \App\Support\Koma::formatDateTime($chapter->published_at) }}">{{ \App\Support\Koma::timeAgo($chapter->published_at) }}</time><span aria-hidden="true">↗</span></a></li>@endforeach</ol>
            @else<div class="empty-state"><span aria-hidden="true">＋</span><strong>No chapters published yet.</strong><p>This title is in the catalog, but its release archive is still empty.</p></div>@endif
        </section>
        <aside class="series-notes" aria-labelledby="about-heading"><p class="eyebrow">Series notes</p><h2 id="about-heading">Synopsis</h2>@if ($series->synopsis)<p>{{ $series->synopsis }}</p>@else<p class="muted-copy">No synopsis has been added for this series.</p>@endif<dl><div><dt>Author</dt><dd>{{ $series->author ?: 'Unknown' }}</dd></div><div><dt>Artist</dt><dd>{{ $series->artist ?: ($series->author ?: 'Unknown') }}</dd></div>@if ($series->year)<div><dt>Year</dt><dd>{{ $series->year }}</dd></div>@endif @if ($series->publication_date)<div><dt>Published</dt><dd>{{ $series->publication_date->format('Y-m-d') }}</dd></div>@endif @if ($series->work_format)<div><dt>Format</dt><dd>{{ \Illuminate\Support\Str::headline($series->work_format) }}</dd></div>@endif @if ($series->franchises)<div><dt>Franchise</dt><dd>{{ implode(', ', $series->franchises) }}</dd></div>@endif @if ($series->characters)<div><dt>Characters</dt><dd>{{ implode(', ', $series->characters) }}</dd></div>@endif</dl></aside>
    </div>
    <div class="sm-page">@include('theme::partials.comments', ['series' => $series, 'chapter' => null, 'comments' => $comments])</div>
@endsection
