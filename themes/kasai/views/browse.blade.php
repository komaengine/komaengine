@extends('theme::layout')

@section('title', 'Browse · '.\App\Support\Koma::siteTitle())

@section('content')
    <div class="ks-wrap browse-shell">
        <aside class="catalog-filters" aria-label="Catalog filters">
            <div class="filter-intro"><p class="eyebrow">Catalog controls</p><h2>Filter titles</h2></div>
            <div class="filter-group">
                <h3>Status</h3>
                <div class="filter-options">
                    <a href="{{ route('browse', request()->except(['status', 'page'])) }}" @class(['on' => ! $activeStatus])>All</a>
                    @foreach (\App\Models\Series::STATUSES as $status)
                        <a href="{{ route('browse', array_merge(request()->except('page'), ['status' => $status])) }}" @class(['on' => $activeStatus === $status])>{{ ucfirst($status) }}</a>
                    @endforeach
                </div>
            </div>
            <div class="filter-group">
                <h3>Genre</h3>
                <div class="filter-options">
                    <a href="{{ route('browse', request()->except(['genre', 'page'])) }}" @class(['on' => ! $activeGenre])>All</a>
                    @foreach ($genres as $genre)
                        <a href="{{ route('browse', array_merge(request()->except('page'), ['genre' => $genre->slug])) }}" @class(['on' => $activeGenre === $genre->slug])>{{ $genre->name }}</a>
                    @endforeach
                </div>
            </div>
            @if ($q || $activeGenre || $activeStatus)
                <a class="clear-filters" href="{{ route('browse') }}">Clear all filters</a>
            @endif
        </aside>

        <section class="catalog-results" aria-labelledby="catalog-heading">
            <div class="catalog-heading">
                <div>
                    <p class="eyebrow">Explore the library</p>
                    <h1 id="catalog-heading">{{ $q ? 'Results for “'.$q.'”' : 'All titles' }}</h1>
                </div>
                <span class="result-count">{{ $series->total() }} {{ \Illuminate\Support\Str::plural('series', $series->total()) }}</span>
            </div>

            @if ($series->isNotEmpty())
                <div class="catalog-grid">
                    @foreach ($series as $item)
                        @include('theme::partials.series-card', ['series' => $item])
                    @endforeach
                </div>
                <div class="pagination-shell">{{ $series->links('theme::pagination') }}</div>
            @else
                <div class="empty-state catalog-empty"><span class="empty-mark" aria-hidden="true">?</span><strong>No matching series.</strong><p>Try clearing a filter or searching for another title, author, or artist.</p><a class="ks-button dark" href="{{ route('browse') }}">Reset catalog</a></div>
            @endif
        </section>
    </div>
@endsection
