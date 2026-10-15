<a class="card" href="{{ route('series.show', $series) }}">
    <div class="cover" style="background: {{ $series->coverGradient() }}">
        @if ($series->content_rating === 'mature')
            <span class="rating">18+</span>
        @endif
        @if ($series->coverThumbnailUrl())
            <img src="{{ $series->coverThumbnailUrl() }}" alt="{{ $series->title }} cover" loading="lazy">
        @else
            {{ $series->monogram() }}
        @endif
    </div>
    <div class="title">{{ $series->title }}</div>
    <div class="sub">
        <span class="status-dot {{ $series->status }}">{{ $series->status }}</span>
        @isset($series->published_chapters_count)
            · {{ $series->published_chapters_count }} ch
        @endisset
    </div>
</a>
