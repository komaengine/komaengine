@php
    $user = auth()->user();
    $parentVisible = empty($can) || ($user?->can($can) ?? false);
    $visibleChildren = collect($children ?? [])->filter(
        fn (array $child) => empty($child['can']) || ($user?->can($child['can']) ?? false),
    );
    $visible = $parentVisible || $visibleChildren->isNotEmpty();
    $active = request()->routeIs(...\Illuminate\Support\Arr::wrap($match));
    $destination = $parentVisible ? $route : data_get($visibleChildren->first(), 'route');
@endphp
@if ($visible)
    <div class="group relative">
        <a href="{{ route($destination) }}"
           @if ($active) aria-current="true" @endif
           @class([
               'flex items-center gap-3 rounded-[10px] px-3 py-2.5 text-[13.5px] font-semibold transition',
               'bg-koma text-white' => $active,
               'text-[#a59ea8] hover:bg-white/5 hover:text-white' => ! $active,
           ])>
            <svg class="shrink-0" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">{!! $icon !!}</svg>
            <span class="min-w-0 flex-1 truncate">{{ $label }}</span>
            @if ($visibleChildren->isNotEmpty())
                <svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" @class(['shrink-0 transition-transform', 'rotate-90' => $active])><path d="m9 18 6-6-6-6"/></svg>
            @endif
        </a>

        @if ($visibleChildren->isNotEmpty())
            <ul
                data-nav-submenu="{{ \Illuminate\Support\Str::slug($label) }}"
                data-nav-mode="{{ $active ? 'inline' : 'flyout' }}"
                aria-label="{{ $label }} submenu"
                @class([
                    'py-1',
                    'mx-2 mb-1 mt-0.5 rounded-[9px] bg-black/15' => $active,
                    'hidden lg:invisible lg:absolute lg:left-full lg:top-0 lg:z-50 lg:ml-2 lg:block lg:w-52 lg:translate-x-1 lg:rounded-[10px] lg:border lg:border-white/10 lg:bg-ink lg:opacity-0 lg:shadow-2xl lg:transition lg:duration-150 lg:before:absolute lg:before:-left-2 lg:before:top-0 lg:before:h-full lg:before:w-2 lg:group-hover:visible lg:group-hover:translate-x-0 lg:group-hover:opacity-100 lg:group-focus-within:visible lg:group-focus-within:translate-x-0 lg:group-focus-within:opacity-100' => ! $active,
                ])
            >
                @foreach ($visibleChildren as $child)
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
                               'pl-9 pr-3' => $active,
                               'px-3' => ! $active,
                               'text-white before:absolute before:left-[18px] before:top-1/2 before:h-1.5 before:w-1.5 before:-translate-y-1/2 before:rounded-full before:bg-koma' => $childActive,
                               'text-[#8f8893] hover:bg-white/5 hover:text-white' => ! $childActive,
                           ])>
                            {{ $child['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
