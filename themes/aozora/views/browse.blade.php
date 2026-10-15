@extends('theme::layout')

@section('title', 'Browse · '.\App\Support\Koma::siteTitle())

@section('content')
    <div class="browse-layout">
        <aside class="filters">
            <div class="filter-group">
                <h3>Status</h3>
                <div class="filter-list">
                    <a href="{{ route('browse', array_merge(request()->except(['status', 'page']))) }}" class="{{ $activeStatus ? '' : 'on' }}">All</a>
                    @foreach (\App\Models\Series::STATUSES as $s)
                        <a href="{{ route('browse', array_merge(request()->except('page'), ['status' => $s])) }}" class="{{ $activeStatus === $s ? 'on' : '' }}">{{ ucfirst($s) }}</a>
                    @endforeach
                </div>
            </div>
            <div class="filter-group">
                <h3>Genre</h3>
                <div class="filter-list">
                    <a href="{{ route('browse', array_merge(request()->except(['genre', 'page']))) }}" class="{{ $activeGenre ? '' : 'on' }}">All</a>
                    @foreach ($genres as $genre)
                        <a href="{{ route('browse', array_merge(request()->except('page'), ['genre' => $genre->slug])) }}" class="{{ $activeGenre === $genre->slug ? 'on' : '' }}">{{ $genre->name }}</a>
                    @endforeach
                </div>
            </div>
        </aside>

        <div>
            <div class="section-head" style="margin-bottom:18px">
                <h2>{{ $q ? 'Results for “'.$q.'”' : 'All series' }}</h2>
                <span style="color:var(--faint);font-size:13px">{{ $series->total() }} series</span>
            </div>

            @if ($series->isNotEmpty())
                <div class="grid">
                    @foreach ($series as $item)
                        @include('theme::partials.series-card', ['series' => $item])
                    @endforeach
                </div>
                <div style="margin-top:28px">{{ $series->links('theme::pagination') }}</div>
            @else
                <div class="empty">No series match your filters.</div>
            @endif
        </div>
    </div>
@endsection
