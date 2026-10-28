@php
    $user = auth()->user();
    $parentVisible = empty($can) || ($user?->can($can) ?? false);
    $visibleChildren = collect($children ?? [])->filter(
        fn (array $child) => empty($child['can']) || ($user?->can($child['can']) ?? false),
    );
    $visible = $parentVisible || $visibleChildren->isNotEmpty();
    $active = request()->routeIs(...\Illuminate\Support\Arr::wrap($match));
    $destination = $parentVisible ? $route : data_get($visibleChildren->first(), 'route');
    $slug = \Illuminate\Support\Str::slug($label);
    // A section that is not the current one shows its sub-menu on demand: a
    // CSS pop-out in the wide sidebar, and in the narrow drawer a pop-out for
    // mouse users or an inline list for touch and keyboard users.
    $collapsible = $visibleChildren->isNotEmpty() && ! $active;
@endphp
@if ($visible)
    <div class="group relative"
        @if ($collapsible)
            x-data="{
                expanded: false,
                flyout: false,
                top: 0,
                left: 0,
                closer: null,
                show() {
                    if (isDesktop || ! canHover) return;
                    clearTimeout(this.closer);
                    const box = this.$refs.item.getBoundingClientRect();
                    this.top = box.top;
                    this.left = this.$refs.item.closest('nav').getBoundingClientRect().right + 6;
                    this.flyout = true;
                    // Keep a long sub-menu on screen once its height is known.
                    this.$nextTick(() => {
                        this.top = Math.max(8, Math.min(box.top, window.innerHeight - this.$refs.flyout.offsetHeight - 8));
                    });
                },
                hide() {
                    clearTimeout(this.closer);
                    this.closer = setTimeout(() => this.flyout = false, 150);
                },
            }"
            @mouseenter="show()"
            @mouseleave="hide()"
            @koma-nav-close.window="flyout = false"
        @endif
    >
        <a href="{{ route($destination) }}"
           @if ($active) aria-current="true" @endif
           @if ($collapsible)
               x-ref="item"
               @click="if (! isDesktop && ! canHover && ! expanded) { $event.preventDefault(); expanded = true }"
           @endif
           @class([
               'flex items-center gap-3 rounded-[10px] px-3 py-2.5 text-[13.5px] font-semibold transition',
               'bg-koma text-white' => $active,
               'text-[#a59ea8] hover:bg-white/5 hover:text-white' => ! $active,
               'pr-11 lg:pr-3' => $collapsible,
           ])>
            <svg class="shrink-0" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">{!! $icon !!}</svg>
            <span class="min-w-0 flex-1 truncate">{{ $label }}</span>
            @if ($visibleChildren->isNotEmpty())
                <svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" @class(['shrink-0 transition-transform', 'rotate-90' => $active, 'hidden lg:block' => $collapsible])><path d="m9 18 6-6-6-6"/></svg>
            @endif
        </a>

        @if ($collapsible)
            {{-- Narrow drawer: an explicit control to expand the section in place. --}}
            <button type="button"
                    @click="expanded = ! expanded"
                    :aria-expanded="expanded.toString()"
                    aria-controls="nav-inline-{{ $slug }}"
                    aria-label="Show {{ $label }} submenu"
                    class="absolute right-1 top-1 flex h-8 w-9 items-center justify-center rounded-lg text-[#a59ea8] hover:bg-white/5 hover:text-white lg:hidden">
                <svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="transition-transform" :class="expanded && 'rotate-90'"><path d="m9 18 6-6-6-6"/></svg>
            </button>

            <ul id="nav-inline-{{ $slug }}"
                data-nav-inline="{{ $slug }}"
                x-show="expanded"
                x-cloak
                aria-label="{{ $label }} submenu"
                class="mx-2 mb-1 mt-0.5 rounded-[9px] bg-black/15 py-1 lg:hidden">
                @include('admin.partials.nav-children', ['children' => $visibleChildren, 'inline' => true])
            </ul>

            {{-- Narrow drawer with a mouse: pop the sub-menu out beside the
                 drawer. It lives under <body> so the drawer's scrolling cannot
                 clip it. --}}
            <template x-teleport="body">
                <ul x-ref="flyout"
                    data-nav-flyout="{{ $slug }}"
                    x-show="flyout && navOpen && ! isDesktop"
                    x-cloak
                    @mouseenter="show()"
                    @mouseleave="hide()"
                    :style="`top: ${top}px; left: ${left}px`"
                    aria-label="{{ $label }} submenu"
                    class="fixed z-[70] w-52 rounded-[10px] border border-white/10 bg-ink py-1 shadow-2xl lg:hidden">
                    @include('admin.partials.nav-children', ['children' => $visibleChildren, 'inline' => false])
                </ul>
            </template>
        @endif

        @if ($visibleChildren->isNotEmpty())
            <ul
                data-nav-submenu="{{ $slug }}"
                data-nav-mode="{{ $active ? 'inline' : 'flyout' }}"
                aria-label="{{ $label }} submenu"
                @class([
                    'py-1',
                    'mx-2 mb-1 mt-0.5 rounded-[9px] bg-black/15' => $active,
                    'hidden lg:invisible lg:absolute lg:left-full lg:top-0 lg:z-50 lg:ml-2 lg:block lg:w-52 lg:translate-x-1 lg:rounded-[10px] lg:border lg:border-white/10 lg:bg-ink lg:opacity-0 lg:shadow-2xl lg:transition lg:duration-150 lg:before:absolute lg:before:-left-2 lg:before:top-0 lg:before:h-full lg:before:w-2 lg:group-hover:visible lg:group-hover:translate-x-0 lg:group-hover:opacity-100 lg:group-focus-within:visible lg:group-focus-within:translate-x-0 lg:group-focus-within:opacity-100' => ! $active,
                ])
            >
                @include('admin.partials.nav-children', ['children' => $visibleChildren, 'inline' => $active])
            </ul>
        @endif
    </div>
@endif
