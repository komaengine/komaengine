@extends('theme::layout')

@section('title', 'Browse · '.\App\Support\Koma::siteTitle())

@section('content')
    <div class="sm-page browse-page">
        <header class="browse-header"><div><p class="eyebrow">The complete archive</p><h1>{{ $q ? 'Results for “'.$q.'”' : 'Browse titles' }}</h1><p>Explore every public series by status or genre.</p></div><span class="result-count">{{ $series->total() }} {{ \Illuminate\Support\Str::plural('series', $series->total()) }}</span></header>
        <nav class="filter-deck" aria-label="Catalog filters">
            <div class="filter-row"><strong>Status</strong><div><a href="{{ route('browse', request()->except(['status', 'page'])) }}" @class(['active' => ! $activeStatus])>All</a>@foreach (\App\Models\Series::STATUSES as $status)<a href="{{ route('browse', array_merge(request()->except('page'), ['status' => $status])) }}" @class(['active' => $activeStatus === $status])>{{ ucfirst($status) }}</a>@endforeach</div></div>
            <div class="filter-row"><strong>Genre</strong><div><a href="{{ route('browse', request()->except(['genre', 'page'])) }}" @class(['active' => ! $activeGenre])>All</a>@foreach ($genres as $genre)<a href="{{ route('browse', array_merge(request()->except('page'), ['genre' => $genre->slug])) }}" @class(['active' => $activeGenre === $genre->slug])>{{ $genre->name }}</a>@endforeach</div></div>
            @if ($q || $activeGenre || $activeStatus)<a class="clear-filters" href="{{ route('browse') }}">Clear all filters</a>@endif
        </nav>
        @if ($series->isNotEmpty())
            <div class="catalog-grid">@foreach ($series as $item)@include('theme::partials.series-card', ['series' => $item])@endforeach</div>
            <div class="pagination-shell">{{ $series->links('theme::pagination') }}</div>
        @else
            <div class="empty-state catalog-empty"><span aria-hidden="true">⌕</span><strong>No matching series.</strong><p>Try clearing a filter or searching for another title, author, or artist.</p><a class="theme-button accent" href="{{ route('browse') }}">Reset catalog</a></div>
        @endif
    </div>
@endsection
