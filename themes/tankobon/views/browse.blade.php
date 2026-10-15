@extends('theme::layout')

@section('title', ($q ? 'Search · ' : 'Library · ').\App\Support\Koma::siteTitle())

@section('content')
    <div class="browse-head">
        <div>
            <p class="eyebrow">Library</p>
            <h1>{{ $q ? 'Results for “'.$q.'”' : 'All series' }}</h1>
        </div>
        <form action="{{ route('browse') }}" method="GET" class="search wide" role="search">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            <input type="search" name="q" value="{{ $q }}" placeholder="Search series…">
        </form>
    </div>

    @if ($activeGenre || $activeStatus || $q)
        <p class="browse-filters">
            Filtered{{ $activeGenre ? ' · genre: '.$activeGenre : '' }}{{ $activeStatus ? ' · status: '.$activeStatus : '' }}
            <a href="{{ route('browse') }}">Clear all filters</a>
        </p>
    @endif

    @if ($series->isNotEmpty())
        <div class="grid">
            @foreach ($series as $item)
                @include('theme::partials.series-card', ['series' => $item])
            @endforeach
        </div>
        @if ($series->hasPages())
            <div class="browse-pager">{{ $series->links('theme::pagination') }}</div>
        @endif
    @else
        <div class="empty-shell">
            <h2>No matching series.</h2>
            <a class="btn ghost" href="{{ route('browse') }}">Reset catalog</a>
        </div>
    @endif
@endsection
