<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <a href="{{ route('admin.series.edit', $series) }}" class="text-[13px] font-semibold text-muted hover:text-koma">← Back to {{ $series->title }}</a>
            <h2 class="mt-1 font-display text-xl font-extrabold">Chapter {{ $chapter->displayNumber() }} pages</h2>
            <p class="mt-1 text-[13px] text-faint">Drag thumbnails or use the move buttons. Reader order updates as soon as a change succeeds.</p>
        </div>
        @if ($chapter->isPublished())
            <a href="{{ route('reader', [$series, $chapter->displayNumber()]) }}" target="_blank" class="rounded-[9px] bg-paper px-3.5 py-2 text-[13px] font-semibold hover:bg-line">Open reader ↗</a>
        @endif
    </div>

    @error('pageOrder')
        <div class="mb-4 rounded-xl border border-koma/30 bg-koma-soft px-4 py-3 text-[13px] font-semibold text-[#6d3329]">{{ $message }}</div>
    @enderror

    <div @class([
        'mb-4 rounded-xl border px-4 py-3 text-[12.5px]',
        'border-[oklch(0.7_0.13_150_/_0.35)] bg-[oklch(0.7_0.13_150_/_0.08)] text-[oklch(0.35_0.08_150)]' => $imageCapabilities['gd'] && $imageCapabilities['webp'],
        'border-[oklch(0.75_0.13_70_/_0.45)] bg-[oklch(0.75_0.13_70_/_0.10)] text-[oklch(0.4_0.09_70)]' => $imageCapabilities['gd'] && ! $imageCapabilities['webp'],
        'border-koma/35 bg-koma-soft font-semibold text-[#6d3329]' => ! $imageCapabilities['gd'],
    ])>
        @if (! $imageCapabilities['gd'])
            Page replacement is unavailable because the PHP GD extension is missing.
        @elseif ($imageCapabilities['webp'])
            Replacements are optimized to WebP before the old page is removed.
        @else
            Replacements use optimized JPEG or PNG fallback encoding before the old page is removed.
        @endif
    </div>

    <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-line bg-white px-4 py-3">
            <p class="text-[10.5px] font-bold uppercase tracking-wide text-faint">Pages</p>
            <p class="mt-1 font-display text-xl font-extrabold">{{ $pages->count() }}</p>
        </div>
        <div class="rounded-xl border border-line bg-white px-4 py-3">
            <p class="text-[10.5px] font-bold uppercase tracking-wide text-faint">Chapter state</p>
            <p class="mt-1 text-[13px] font-bold">{{ ucfirst($chapter->status) }}</p>
        </div>
        <div class="rounded-xl border border-line bg-white px-4 py-3">
            <p class="text-[10.5px] font-bold uppercase tracking-wide text-faint">Safety</p>
            <p class="mt-1 text-[12px] leading-relaxed text-muted">Replacements are stored before the old file is removed.</p>
        </div>
    </div>

    <p id="page-order-instructions" class="sr-only">Drag a page onto another page to move it, or use the earlier and later buttons on each page.</p>

    <div
        x-data="{ dragging: null }"
        class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3"
        aria-describedby="page-order-instructions"
    >
        @forelse ($pages as $page)
            <article
                wire:key="page-{{ $page->id }}"
                aria-labelledby="page-heading-{{ $page->id }}"
                draggable="true"
                @dragstart="dragging = {{ $page->id }}"
                @dragend="dragging = null"
                @dragover.prevent
                @drop.prevent="if (dragging && dragging !== {{ $page->id }}) { $wire.dropPage(dragging, {{ $page->id }}) } dragging = null"
                :class="dragging === {{ $page->id }} ? 'opacity-55 ring-2 ring-koma' : ''"
                class="overflow-hidden rounded-2xl border border-line bg-white transition"
            >
                <div class="flex items-center justify-between gap-3 border-b border-line-soft px-3.5 py-3">
                    <div class="flex min-w-0 items-center gap-2.5">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-koma-soft font-display text-sm font-extrabold text-koma">{{ $page->sort_order }}</span>
                        <span class="min-w-0">
                            <span id="page-heading-{{ $page->id }}" class="block text-[13px] font-bold">Page {{ $page->sort_order }}</span>
                            <span class="block truncate font-mono text-[10.5px] text-faint">{{ basename($page->image_path) }}</span>
                        </span>
                    </div>
                    <span class="cursor-grab select-none text-lg text-faint" title="Drag to reorder" aria-hidden="true">⠿</span>
                </div>

                <div class="flex h-72 items-center justify-center bg-paper p-3">
                    <img src="{{ $page->url() }}" alt="Page {{ $page->sort_order }} thumbnail" class="max-h-full max-w-full rounded-lg object-contain shadow-sm" loading="lazy">
                </div>

                <div class="space-y-3 p-3.5">
                    <div class="grid grid-cols-2 gap-2">
                        <button
                            type="button"
                            wire:click="movePage({{ $page->id }}, 'earlier')"
                            @disabled($loop->first)
                            aria-label="Move page {{ $page->sort_order }} earlier"
                            class="rounded-[9px] border border-line px-2.5 py-2 text-[12px] font-semibold hover:border-koma hover:text-koma disabled:cursor-not-allowed disabled:opacity-40"
                        >↑ Earlier</button>
                        <button
                            type="button"
                            wire:click="movePage({{ $page->id }}, 'later')"
                            @disabled($loop->last)
                            aria-label="Move page {{ $page->sort_order }} later"
                            class="rounded-[9px] border border-line px-2.5 py-2 text-[12px] font-semibold hover:border-koma hover:text-koma disabled:cursor-not-allowed disabled:opacity-40"
                        >↓ Later</button>
                    </div>

                    <div class="rounded-xl bg-paper p-3">
                        <label for="replacement-{{ $page->id }}" class="block text-[10.5px] font-bold uppercase tracking-wide text-muted">Replace image</label>
                        <input id="replacement-{{ $page->id }}" type="file" wire:model="replacementFiles.{{ $page->id }}" accept="image/jpeg,image/png,image/webp" aria-label="Replace page {{ $page->sort_order }} image" class="mt-2 block w-full text-[11.5px] text-muted file:mr-2 file:rounded-md file:border-0 file:bg-white file:px-2.5 file:py-1.5 file:text-[11px] file:font-semibold" @disabled(! $imageCapabilities['gd'])>
                        @error("replacementFiles.{$page->id}") <p class="mt-1.5 text-[11.5px] font-semibold text-koma">{{ $message }}</p> @enderror

                        <button
                            type="button"
                            wire:click="replacePage({{ $page->id }})"
                            wire:loading.attr="disabled"
                            wire:target="replacementFiles.{{ $page->id }},replacePage"
                            @disabled(! isset($replacementFiles[$page->id]) || ! $imageCapabilities['gd'])
                            aria-label="Replace page {{ $page->sort_order }} with selected image"
                            class="mt-2 w-full rounded-[8px] bg-ink px-3 py-2 text-[12px] font-bold text-white hover:bg-[#2c2830] disabled:cursor-not-allowed disabled:opacity-45"
                        >
                            <span wire:loading.remove wire:target="replacementFiles.{{ $page->id }},replacePage">Replace selected image</span>
                            <span wire:loading wire:target="replacementFiles.{{ $page->id }},replacePage">Checking replacement…</span>
                        </button>
                    </div>

                    <button
                        type="button"
                        wire:click="deletePage({{ $page->id }})"
                        wire:confirm="Delete page {{ $page->sort_order }}? Remaining pages will be renumbered."
                        @disabled($pages->count() <= 1)
                        aria-label="Delete page {{ $page->sort_order }}"
                        class="w-full rounded-[8px] px-3 py-2 text-[12px] font-semibold text-koma hover:bg-koma-soft disabled:cursor-not-allowed disabled:text-faint disabled:hover:bg-transparent"
                    >{{ $pages->count() <= 1 ? 'Last page cannot be deleted' : 'Delete page' }}</button>
                </div>
            </article>
        @empty
            <div class="col-span-full rounded-2xl border border-dashed border-line bg-white px-5 py-12 text-center text-[13px] text-faint">This chapter has no pages.</div>
        @endforelse
    </div>
</div>
