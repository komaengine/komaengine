@php
    $series->loadMissing('genres');
    $latestChapter = $latestChapter ?? $chapters->first();
    $direction = str_replace(['rtl', 'ltr', 'vertical'], ['Right → Left', 'Left → Right', 'Webtoon'], $series->reading_direction);
@endphp
<article class="volume">
    @unless ($series->is_public)
        <p class="vol-private" role="status"><strong>Private preview</strong> — this series is hidden from readers. Only Editors and Administrators can view this page.</p>
    @endunless

    <div class="vol-hero">
        <div class="vol-cover" style="background: {{ $series->coverGradient() }}">
            @if ($series->coverUrl())
                <img src="{{ $series->coverUrl() }}" alt="{{ $series->title }} cover">
            @else
                <span class="vol-monogram" aria-hidden="true">{{ $series->monogram() }}</span>
            @endif
        </div>
        <div class="vol-info">
            <p class="eyebrow">Now reading</p>
            <h1 class="vol-title">{{ $series->title }}</h1>
            <p class="vol-by">
                by {{ $series->author ?? 'Unknown' }}@if ($series->artist && $series->artist !== $series->author) · art by {{ $series->artist }}@endif@if ($series->year) · {{ $series->year }}@endif
            </p>
            <div class="vol-tags">
                <span class="status-dot {{ $series->status }}">{{ $series->statusLabel() }}</span>
                @foreach ($series->genres as $genre)
                    <a class="chip" href="{{ route('browse', ['genre' => $genre->slug]) }}">{{ $genre->name }}</a>
                @endforeach
            </div>

            @if ($firstChapter)
                <div class="vol-actions">
                    <a class="btn" href="{{ route('reader', [$series, $firstChapter->displayNumber()]) }}">Start reading · Ch. {{ $firstChapter->displayNumber() }}</a>
                    @if ($latestChapter && $latestChapter->id !== $firstChapter->id)
                        <a class="btn ghost" href="{{ route('reader', [$series, $latestChapter->displayNumber()]) }}">Latest · Ch. {{ $latestChapter->displayNumber() }}</a>
                    @endif
                </div>
            @endif

            @if ($series->synopsis)
                <p class="vol-synopsis">{{ $series->synopsis }}</p>
            @endif

            <dl class="vol-stats">
                <div><dt>Chapters</dt><dd>{{ $chapters->count() }}</dd></div>
                <div><dt>Status</dt><dd>{{ $series->statusLabel() }}</dd></div>
                <div><dt>Updated</dt><dd>{{ \App\Support\Koma::timeAgo($latestChapter?->published_at) ?: '—' }}</dd></div>
            </dl>
        </div>
    </div>

    <div class="vol-body">
        <section class="toc" aria-labelledby="toc-title">
            <div class="toc-head">
                <h2 id="toc-title">Chapters</h2>
                <span class="toc-count">{{ $chapters->count() }}</span>
            </div>

            @if ($chapters->isNotEmpty())
                <label class="toc-filter">
                    <span class="r-sr-only">Filter chapters</span>
                    <input type="search" id="tocFilter" placeholder="Jump to a chapter…" autocomplete="off">
                </label>
                <ol class="chapter-list" id="chapterList">
                    @foreach ($chapters as $chapter)
                        <li class="chapter-row" data-title="{{ \Illuminate\Support\Str::lower(($chapter->title ?: 'chapter '.$chapter->displayNumber()).' '.$chapter->displayNumber()) }}">
                            <a href="{{ route('reader', [$series, $chapter->displayNumber()]) }}">
                                <span class="num">{{ $chapter->displayNumber() }}</span>
                                <span class="ct">{{ $chapter->title ?: 'Chapter '.$chapter->displayNumber() }}</span>
                                <span class="cmeta">{{ $chapter->pages_count }} pages · {{ \App\Support\Koma::timeAgo($chapter->published_at) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ol>
            @else
                <div class="empty">No chapters have been published yet.</div>
            @endif
        </section>

        <aside class="vol-aside">
            <section class="about-card">
                <h2>About</h2>
                @if ($series->synopsis)
                    <p>{{ \Illuminate\Support\Str::limit((string) $series->synopsis, 320) }}</p>
                @else
                    <p class="muted">No synopsis has been added yet.</p>
                @endif
                <dl class="about-facts">
                    <div><dt>Author</dt><dd>{{ $series->author ?? 'Unknown' }}</dd></div>
                    @if ($series->artist)<div><dt>Artist</dt><dd>{{ $series->artist }}</dd></div>@endif
                    @if ($series->year)<div><dt>Year</dt><dd>{{ $series->year }}</dd></div>@endif
                    <div><dt>Reading</dt><dd>{{ $direction }}</dd></div>
                </dl>
            </section>

            @if ($chapters->isNotEmpty())
                <section class="about-card">
                    <h2>Latest chapters</h2>
                    <ul class="latest-list">
                        @foreach ($chapters->take(8) as $chapter)
                            <li>
                                <a href="{{ route('reader', [$series, $chapter->displayNumber()]) }}">
                                    <span class="ln">Ch. {{ $chapter->displayNumber() }}</span>
                                    <span class="lt">{{ $chapter->title ?: 'Chapter '.$chapter->displayNumber() }}</span>
                                    <span class="ld">{{ \App\Support\Koma::timeAgo($chapter->published_at) }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </aside>
    </div>
</article>

@if ($chapters->isNotEmpty())
    <script>
        (function () {
            var input = document.getElementById('tocFilter');
            var list = document.getElementById('chapterList');
            if (!input || !list) return;
            input.addEventListener('input', function () {
                var q = this.value.toLowerCase().trim();
                list.querySelectorAll('.chapter-row').forEach(function (row) {
                    var hay = row.getAttribute('data-title') || '';
                    row.style.display = (!q || hay.indexOf(q) > -1) ? '' : 'none';
                });
            });
        })();
    </script>
@endif
