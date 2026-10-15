@if ($paginator->hasPages())
    <nav class="pager" style="display:flex;gap:6px;align-items:center;justify-content:center;flex-wrap:wrap">
        @php
            $link = fn ($url, $label, $active = false, $disabled = false) =>
                '<a href="'.($url ?? '#').'" class="btn '.($active ? '' : 'ghost').'" '
                .($disabled ? 'style="pointer-events:none;opacity:.4" aria-disabled="true"' : '')
                .' style="height:38px;min-width:38px;padding:0 12px">'.$label.'</a>';
        @endphp

        {!! $link($paginator->previousPageUrl(), '←', false, $paginator->onFirstPage()) !!}

        @foreach ($elements as $element)
            @if (is_string($element))
                <span style="padding:0 6px;color:var(--faint)">{{ $element }}</span>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    {!! $link($url, $page, $page == $paginator->currentPage()) !!}
                @endforeach
            @endif
        @endforeach

        {!! $link($paginator->nextPageUrl(), '→', false, ! $paginator->hasMorePages()) !!}
    </nav>
@endif
