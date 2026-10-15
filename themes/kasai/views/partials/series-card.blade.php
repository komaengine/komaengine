<a class="series-card" href="{{ route('series.show', $series) }}">
    <span class="series-card-cover" style="background: {{ $series->coverGradient() }}">
        @if ($series->content_rating === 'mature')
            <span class="rating-badge">18+</span>
        @endif
        @if ($series->coverThumbnailUrl())
            <img src="{{ $series->coverThumbnailUrl() }}" alt="{{ $series->title }} cover" loading="lazy">
        @else
            <span class="cover-monogram">{{ $series->monogram() }}</span>
        @endif
        <span class="card-action" aria-hidden="true">Open <b>→</b></span>
    </span>
    <strong class="series-card-title">{{ $series->title }}</strong>
    <span class="series-card-meta">
        <span class="status-dot {{ $series->status }}"></span>{{ $series->statusLabel() }}
        @isset($series->published_chapters_count)
            <span>·</span>{{ $series->published_chapters_count }} ch
        @endisset
    </span>
</a>
