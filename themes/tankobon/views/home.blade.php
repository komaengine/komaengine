@extends('theme::layout')

@php
    $series = $featured;
    $chapters = $series
        ? $series->chapters()->published()->withCount('pages')->orderByDesc('number')->get()
        : collect();
    $firstChapter = $chapters->last();
    $latestChapter = $chapters->first();
@endphp

@section('title', ($series?->title ? $series->title.' · ' : '').\App\Support\Koma::siteTitle())
@if ($series)
    @section('og_title', $series->title)
    @section('description', \Illuminate\Support\Str::limit((string) $series->synopsis, 160))
    @section('og_type', 'book')
    @if ($series->coverUrl())
        @section('og_image', $series->coverUrl())
    @endif
@endif

@section('content')
    @if ($series)
        @include('theme::partials.landing', [
            'series' => $series,
            'chapters' => $chapters,
            'firstChapter' => $firstChapter,
            'latestChapter' => $latestChapter,
        ])

        <p class="vol-discuss-link">
            <a href="{{ route('series.show', $series) }}#comments">Join the discussion →</a>
        </p>
    @else
        <div class="empty-shell">
            <p class="eyebrow">Nothing bound yet</p>
            <h1>No series has been published</h1>
            <p>Tankōbon is a single-series theme: it features your published work front and center. Publish a series in the admin and it will appear here.</p>
            @auth
                @if (auth()->user()->canAccessAdmin())
                    <a class="btn" href="{{ route('admin.dashboard') }}">Go to the dashboard</a>
                @endif
            @endauth
        </div>
    @endif
@endsection
