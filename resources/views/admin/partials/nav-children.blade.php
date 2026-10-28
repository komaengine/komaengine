{{-- Sub-menu links. $inline: indented under the parent (open section or
     expanded drawer item); otherwise a pop-out panel. --}}
@foreach ($children as $child)
    @php
        $childActive = request()->routeIs(...\Illuminate\Support\Arr::wrap($child['match']));
        // Children that share a route but differ by a route parameter
        // (e.g. the Settings tabs) resolve active state by that param.
        if ($childActive && isset($child['active_tab'])) {
            $childActive = (request()->route('tab') ?? 'general') === $child['active_tab'];
        }
    @endphp
    <li>
        <a href="{{ route($child['route'], $child['params'] ?? []) }}"
           @if ($childActive) aria-current="page" @endif
           @class([
               'relative block rounded-md py-1.5 text-[12.5px] font-semibold transition',
               'pl-9 pr-3' => $inline,
               'px-3' => ! $inline,
               'text-white before:absolute before:left-[18px] before:top-1/2 before:h-1.5 before:w-1.5 before:-translate-y-1/2 before:rounded-full before:bg-koma' => $childActive,
               'text-[#8f8893] hover:bg-white/5 hover:text-white' => ! $childActive,
           ])>
            {{ $child['label'] }}
        </a>
    </li>
@endforeach
