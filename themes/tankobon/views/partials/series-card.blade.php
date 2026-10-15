<a class="card" href="{{ route('series.show', $series) }}">
    <div class="card-cover" style="background: {{ $series->coverGradient() }}">
        @if ($series->content_rating === 'mature')
            <span class="rating">18+</span>
        @endif
        @if ($series->coverThumbnailUrl())
            <img src="{{ $series->coverThumbnailUrl() }}" alt="{{ $series->title }} cover" loading="lazy">
        @else
            <span class="card-monogram" aria-hidden="true">{{ $series->monogram() }}</span>
        @endif
    </div>
    <div class="card-title">{{ $series->title }}</div>
    <div class="card-sub">
        <span class="status-dot {{ $series->status }}">{{ $series->statusLabel() }}</span>
        @isset($series->published_chapters_count) · {{ $series->published_chapters_count }} ch @endisset
    </div>
</a>
