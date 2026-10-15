@extends('theme::layout')

@section('title', $series->title.' · '.\App\Support\Koma::siteTitle())
@section('og_title', $series->title)
@section('description', \Illuminate\Support\Str::limit((string) $series->synopsis, 160))
@section('og_type', 'book')
@if ($series->coverUrl())
    @section('og_image', $series->coverUrl())
@endif

@section('content')
    @include('theme::partials.landing', [
        'series' => $series,
        'chapters' => $chapters,
        'firstChapter' => $firstChapter,
        'latestChapter' => $chapters->first(),
    ])

    @include('theme::partials.comments', [
        'series' => $series,
        'chapter' => null,
        'comments' => $comments,
    ])
@endsection
