@if ($paginator->hasPages())
    <nav class="pager" role="navigation" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span class="pager-link disabled" aria-disabled="true" aria-label="Previous page">←</span>
        @else
            <a class="pager-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous page">←</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="pager-ellipsis">{{ $element }}</span>
            @elseif (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="pager-link current" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="pager-link" href="{{ $url }}" aria-label="Go to page {{ $page }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a class="pager-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next page">→</a>
        @else
            <span class="pager-link disabled" aria-disabled="true" aria-label="Next page">→</span>
        @endif
    </nav>
@endif
