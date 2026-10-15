<div>
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <div>
            <h2 class="font-display text-lg font-extrabold">All Series</h2>
            <p class="mt-0.5 text-[12.5px] text-faint">Search, filter, and manage your catalog.</p>
        </div>
        <div class="ml-auto flex flex-wrap items-center justify-end gap-2">
            <a href="{{ route('admin.series.create') }}" class="rounded-[10px] bg-koma px-4 py-2.5 text-[13.5px] font-bold text-white hover:bg-koma-hover">＋ New Series</a>
            @can('upload_chapters')
                <a href="{{ route('admin.upload') }}" class="rounded-[10px] border border-line bg-white px-4 py-2.5 text-[13.5px] font-bold text-[#57524c] hover:border-koma hover:text-koma">＋ New chapter</a>
            @endcan
        </div>
    </div>

    <form wire:submit="applyFilters" class="mb-4 rounded-2xl border border-line bg-white p-3.5">
        <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2 xl:grid-cols-[minmax(220px,1.4fr)_repeat(4,minmax(125px,0.7fr))_auto]">
            <div class="relative sm:col-span-2 xl:col-span-1">
                <input wire:model="search" placeholder="Search title, author, or artist…" aria-label="Search series"
                       class="h-10 w-full rounded-[9px] border border-line bg-white pl-9 pr-3 text-[13px] outline-none focus:border-koma">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" class="pointer-events-none absolute left-3 top-3 text-faint"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            </div>

            <select wire:model="statusFilter" aria-label="Filter by status" class="h-10 rounded-[9px] border border-line bg-white px-2.5 text-[12.5px] outline-none focus:border-koma">
                <option value="">All statuses</option>
                @foreach (\App\Models\Series::STATUSES as $status)
                    <option value="{{ $status }}">{{ ucfirst($status) }}</option>
                @endforeach
            </select>

            <select wire:model="visibilityFilter" aria-label="Filter by visibility" class="h-10 rounded-[9px] border border-line bg-white px-2.5 text-[12.5px] outline-none focus:border-koma">
                <option value="">All visibility</option>
                <option value="public">Public</option>
                <option value="private">Private</option>
            </select>

            <select wire:model="genreFilter" aria-label="Filter by genre" class="h-10 rounded-[9px] border border-line bg-white px-2.5 text-[12.5px] outline-none focus:border-koma">
                <option value="">All genres</option>
                @foreach ($genres as $genre)
                    <option value="{{ $genre->id }}">{{ $genre->name }}</option>
                @endforeach
            </select>

            <select wire:model="tagFilter" aria-label="Filter by tag" class="h-10 rounded-[9px] border border-line bg-white px-2.5 text-[12.5px] outline-none focus:border-koma">
                <option value="">All tags</option>
                @foreach ($tags as $tag)
                    <option value="{{ $tag->id }}">{{ $tag->name }}</option>
                @endforeach
            </select>

            <div class="flex gap-2 sm:col-span-2 xl:col-span-1">
                <button type="submit" class="h-10 flex-1 rounded-[9px] border border-koma bg-koma px-3.5 text-[12.5px] font-bold text-white hover:bg-koma-hover xl:flex-none">Filter</button>
                @if ($filtersActive)
                    <button type="button" wire:click="clearFilters" class="h-10 rounded-[9px] border border-line bg-white px-3 text-[12px] font-semibold text-muted hover:border-koma hover:text-koma">Clear</button>
                @endif
            </div>
        </div>
    </form>

    <div class="mb-2.5 flex items-center justify-between gap-3 px-1 text-[12px] text-faint">
        <span>
            @if ($series->total() > 0)
                Showing {{ $series->firstItem() }}–{{ $series->lastItem() }} of {{ $series->total() }}
            @else
                0 series
            @endif
        </span>
        @if ($filtersActive)
            <span>Filtered results</span>
        @endif
    </div>

    <div class="overflow-hidden rounded-2xl border border-line bg-white">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[830px] table-fixed border-collapse text-left">
                <thead class="bg-[#faf8f6] text-[10.5px] font-bold uppercase tracking-wide text-faint">
                    <tr>
                        <th class="w-[195px] px-3 py-3">Series</th>
                        <th class="w-[108px] px-2 py-3">Genres</th>
                        <th class="w-[120px] px-2 py-3">Tags</th>
                        <th class="w-[68px] px-2 py-3 text-center">Comments</th>
                        <th class="w-[84px] px-2 py-3">Status</th>
                        <th class="w-[64px] px-2 py-3 text-center">Chapters</th>
                        <th class="w-[56px] px-2 py-3 text-right">Views</th>
                        <th class="w-[72px] px-2 py-3">Updated</th>
                        <th class="w-[54px] px-2 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft">
                    @forelse ($series as $item)
                        @php
                            $commentCount = $item->comments_count + $item->chapter_comments_count;
                        @endphp
                        <tr data-testid="series-row-{{ $item->id }}" class="align-middle hover:bg-[#fcfbfa]">
                            <td class="px-3 py-3">
                                <a href="{{ route('admin.series.edit', $item) }}" class="flex min-w-0 items-center gap-3">
                                    <span class="flex h-[46px] w-[34px] shrink-0 items-center justify-center overflow-hidden rounded-[6px] font-display text-[12px] font-extrabold text-white/50" style="background: {{ $item->coverGradient() }}">
                                        @if ($item->coverThumbnailUrl())
                                            <img src="{{ $item->coverThumbnailUrl() }}" alt="" class="h-full w-full object-cover" loading="lazy">
                                        @else
                                            {{ $item->monogram() }}
                                        @endif
                                    </span>
                                    <span class="min-w-0">
                                        <span class="flex min-w-0 items-center gap-2">
                                            <span class="truncate text-[12.5px] font-bold hover:text-koma">{{ $item->title }}</span>
                                            <span @class([
                                                'shrink-0 rounded px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide',
                                                'bg-[oklch(0.7_0.13_150_/_0.15)] text-[oklch(0.42_0.12_150)]' => $item->is_public,
                                                'bg-[oklch(0.7_0.14_60_/_0.18)] text-[oklch(0.48_0.14_60)]' => ! $item->is_public,
                                            ])>{{ $item->is_public ? 'Public' : 'Private' }}</span>
                                        </span>
                                        <span class="mt-0.5 block truncate text-[11.5px] text-faint">{{ $item->author ?: ($item->artist ?: 'No creator credited') }}</span>
                                    </span>
                                </a>
                            </td>
                            <td class="px-2 py-3 text-[11px] leading-4">
                                <div class="flex flex-wrap gap-1">
                                    @forelse ($item->genres as $genre)
                                        <button type="button" wire:click="filterByGenre({{ $genre->id }})" data-testid="genre-chip-{{ $genre->id }}" class="inline-flex items-center gap-1 rounded-md bg-paper px-2 py-1 text-left font-semibold text-koma hover:bg-line">
                                            <svg data-icon="genre" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 5h6v6H4zM14 5h6v6h-6zM4 15h6v4H4zM14 15h6v4h-6z"/></svg>
                                            {{ $genre->name }}
                                        </button>
                                    @empty
                                        <span class="text-faint">—</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="px-2 py-3 text-[11px] leading-4">
                                @php
                                    $visibleTags = $item->tags->take(3);
                                    $additionalTags = $item->tags->slice(3);
                                    $additionalTagNames = $additionalTags->pluck('name')->implode(', ');
                                @endphp
                                <div class="flex flex-wrap gap-1" x-data="{ expanded: false }">
                                    @forelse ($visibleTags as $tag)
                                        <button type="button" wire:click="filterByTag({{ $tag->id }})" data-testid="tag-chip-{{ $tag->id }}" class="inline-flex items-center gap-1 rounded-md bg-paper px-2 py-1 text-left font-semibold text-koma hover:bg-line">
                                            <svg data-icon="tag" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 13 13 20l-9-9V4h7z"/><circle cx="8.5" cy="8.5" r="1.5"/></svg>
                                            {{ $tag->name }}
                                        </button>
                                    @empty
                                        <span class="text-faint">—</span>
                                    @endforelse

                                    @if ($additionalTags->isNotEmpty())
                                        @foreach ($additionalTags as $tag)
                                            <button x-cloak x-show="expanded" type="button" wire:click="filterByTag({{ $tag->id }})" data-testid="additional-tag-chip-{{ $tag->id }}" class="inline-flex items-center gap-1 rounded-md bg-paper px-2 py-1 text-left font-semibold text-koma hover:bg-line">
                                                <svg data-icon="tag" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 13 13 20l-9-9V4h7z"/><circle cx="8.5" cy="8.5" r="1.5"/></svg>
                                                {{ $tag->name }}
                                            </button>
                                        @endforeach

                                        <button
                                            type="button"
                                            x-on:click="expanded = ! expanded"
                                            x-bind:aria-expanded="expanded"
                                            aria-label="Show {{ $additionalTags->count() }} additional tags for {{ $item->title }}: {{ $additionalTagNames }}"
                                            title="{{ $additionalTagNames }}"
                                            data-testid="tag-overflow-{{ $item->id }}"
                                            class="inline-flex items-center rounded-md border border-line bg-white px-2 py-1 font-bold text-muted hover:border-koma hover:text-koma focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-koma"
                                        >
                                            <span x-show="! expanded">+{{ $additionalTags->count() }} more</span>
                                            <span x-cloak x-show="expanded">Show less</span>
                                        </button>
                                    @endif
                                </div>
                            </td>
                            <td class="px-2 py-3 text-center" data-comment-count="{{ $commentCount }}">
                                <a href="{{ route('admin.comments') }}" class="inline-flex min-w-8 items-center justify-center gap-1 rounded-md bg-paper px-2 py-1 text-[11.5px] font-bold text-muted hover:bg-line hover:text-koma" aria-label="{{ $commentCount }} comments for {{ $item->title }}">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/></svg>
                                    {{ $commentCount }}
                                </a>
                            </td>
                            <td class="px-2 py-3"><x-status-pill :status="$item->status" /></td>
                            <td class="px-2 py-3 text-center text-[12px] font-semibold">
                                <span data-testid="chapter-count-{{ $item->id }}" class="inline-flex min-w-8 items-center justify-center gap-1 rounded-md bg-paper px-2 py-1 text-muted" aria-label="{{ $item->chapters_count }} chapters for {{ $item->title }}">
                                    {{ $item->chapters_count }}
                                </span>
                            </td>
                            <td class="px-2 py-3 text-right text-[12px] text-[#57524c]">
                                <span data-testid="view-count-{{ $item->id }}" class="inline-flex min-w-8 items-center justify-center gap-1 rounded-md bg-paper px-2 py-1">
                                    <svg data-icon="views" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"/><circle cx="12" cy="12" r="2.5"/></svg>
                                    {{ \Illuminate\Support\Number::abbreviate($item->views) }}
                                </span>
                            </td>
                            <td class="px-2 py-3 text-[11px] text-faint" title="{{ $item->updated_at->format('M j, Y g:i A') }}">
                                <span data-testid="updated-chip-{{ $item->id }}" class="inline-flex items-center gap-1 whitespace-nowrap rounded-md bg-paper px-2 py-1">
                                    <svg data-icon="updated" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                                    {{ \App\Support\Koma::timeAgo($item->updated_at, short: true) }}
                                </span>
                            </td>
                            <td class="px-2 py-3">
                                <div
                                    x-data="{ open: false }"
                                    x-on:keydown.escape.window="open = false"
                                    class="relative flex justify-end"
                                >
                                    <button
                                        type="button"
                                        x-on:click="open = ! open"
                                        x-bind:aria-expanded="open"
                                        aria-haspopup="menu"
                                        aria-label="Actions for {{ $item->title }}"
                                        data-testid="series-actions-{{ $item->id }}"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-paper text-muted hover:bg-line hover:text-koma focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-koma"
                                    >
                                        <svg width="17" height="17" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                            <circle cx="5" cy="12" r="1.7"/>
                                            <circle cx="12" cy="12" r="1.7"/>
                                            <circle cx="19" cy="12" r="1.7"/>
                                        </svg>
                                    </button>

                                    <div
                                        x-cloak
                                        x-show="open"
                                        x-on:click.outside="open = false"
                                        role="menu"
                                        aria-label="Actions for {{ $item->title }}"
                                        data-menu-placement="{{ $loop->last ? 'up' : 'down' }}"
                                        @class([
                                            'absolute right-0 z-30 w-32 overflow-hidden rounded-xl border border-line bg-white p-1 shadow-[0_12px_30px_rgba(30,22,16,0.16)]',
                                            'bottom-full -mb-0.5' => $loop->last && $loop->first,
                                            'bottom-full mb-1.5' => $loop->last && ! $loop->first,
                                            'top-full mt-1.5' => ! $loop->last,
                                        ])
                                    >
                                        <a href="{{ route('admin.series.edit', $item) }}" role="menuitem" class="block rounded-lg px-3 py-2 text-[12px] font-semibold text-[#57524c] hover:bg-paper hover:text-koma">Edit</a>
                                        <button
                                            type="button"
                                            role="menuitem"
                                            wire:click="deleteSeries({{ $item->id }})"
                                            wire:confirm="Permanently delete “{{ $item->title }}”, all chapters, pages, comments, and media? This cannot be undone."
                                            class="block w-full rounded-lg px-3 py-2 text-left text-[12px] font-semibold text-[#9a382d] hover:bg-koma-soft"
                                        >Delete</button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-5 py-16 text-center text-sm text-faint">
                                @if ($filtersActive)
                                    No series match these filters.
                                    <button type="button" wire:click="clearFilters" class="font-semibold text-koma hover:underline">Clear filters →</button>
                                @else
                                    No series found. <a href="{{ route('admin.series.create') }}" class="font-semibold text-koma hover:underline">Create your first series →</a>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">{{ $series->links() }}</div>
</div>
