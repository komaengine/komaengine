<article
    data-dashboard-widget="{{ $widgetId }}"
    data-widget-label="{{ $widgetLabel }}"
    x-show="! isHidden('{{ $widgetId }}')"
    class="overflow-hidden rounded-2xl border border-line bg-white shadow-[0_1px_2px_rgba(31,26,22,0.025)] transition-[opacity,box-shadow] duration-150"
>
    <header class="flex min-h-12 items-center gap-2 border-b border-line-soft px-3.5 py-2.5">
        <button
            type="button"
            data-dashboard-handle
            @pointerdown.prevent="startPointerDrag($event, '{{ $widgetId }}')"
            @keydown.up.prevent="moveWithKeyboard('{{ $widgetId }}', 'up')"
            @keydown.down.prevent="moveWithKeyboard('{{ $widgetId }}', 'down')"
            @keydown.left.prevent="moveWithKeyboard('{{ $widgetId }}', 'left')"
            @keydown.right.prevent="moveWithKeyboard('{{ $widgetId }}', 'right')"
            class="flex h-8 w-7 touch-none select-none shrink-0 cursor-grab items-center justify-center rounded-md text-faint hover:bg-paper hover:text-muted active:cursor-grabbing focus:outline-none focus:ring-2 focus:ring-koma/30"
            aria-label="Move {{ $widgetLabel }} panel. Use arrow keys or drag."
            title="Drag to move · arrow keys also work"
        >
            <svg width="14" height="16" viewBox="0 0 14 18" fill="currentColor" aria-hidden="true"><circle cx="4" cy="3" r="1.2"/><circle cx="10" cy="3" r="1.2"/><circle cx="4" cy="9" r="1.2"/><circle cx="10" cy="9" r="1.2"/><circle cx="4" cy="15" r="1.2"/><circle cx="10" cy="15" r="1.2"/></svg>
        </button>

        <h2 class="min-w-0 flex-1 truncate font-display text-[14.5px] font-extrabold">{{ $widgetLabel }}</h2>

        <button
            type="button"
            @click="toggleCollapsed('{{ $widgetId }}')"
            :aria-expanded="(! isCollapsed('{{ $widgetId }}')).toString()"
            aria-controls="dashboard-widget-{{ $widgetId }}"
            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted hover:bg-paper hover:text-[#282522] focus:outline-none focus:ring-2 focus:ring-koma/30"
            aria-label="Toggle {{ $widgetLabel }} panel"
            :title="isCollapsed('{{ $widgetId }}') ? 'Expand panel' : 'Collapse panel'"
        >
            <svg class="transition-transform" :class="isCollapsed('{{ $widgetId }}') ? 'rotate-180' : ''" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="m18 15-6-6-6 6"/></svg>
        </button>
    </header>

    <div id="dashboard-widget-{{ $widgetId }}" x-show="! isCollapsed('{{ $widgetId }}')">
        @switch($widgetId)
            @case('at_a_glance')
                <dl class="grid grid-cols-2 divide-x divide-y divide-line-soft sm:grid-cols-4 lg:grid-cols-2 xl:grid-cols-4">
                    @foreach ($stats as $stat)
                        <div class="p-4">
                            <dt class="text-[11.5px] font-semibold leading-4 text-muted">{{ $stat['label'] }}</dt>
                            <dd class="mt-1 font-display text-[25px] font-extrabold tracking-tight text-[#24211f]">{{ $stat['value'] }}</dd>
                        </div>
                    @endforeach
                </dl>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-line-soft bg-paper/60 px-4 py-3 text-[12px] font-semibold">
                    @can('edit_series')
                        <a href="{{ route('admin.series.index') }}" class="text-koma hover:underline">Manage series</a>
                    @endcan
                    @can('moderate_comments')
                        <a href="{{ route('admin.comments') }}" class="text-koma hover:underline">Review comments</a>
                    @endcan
                    <a href="{{ url('/') }}" target="_blank" class="text-koma hover:underline">View public site ↗</a>
                </div>
                @break

            @case('quick_actions')
                <div class="grid gap-2.5 p-4 sm:grid-cols-2">
                    @can('edit_series')
                        <a href="{{ route('admin.series.create') }}" class="group/action flex items-center gap-3 rounded-xl border border-line bg-paper/70 p-3.5 hover:border-koma/25 hover:bg-koma-soft">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-koma shadow-sm">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                            </span>
                            <span><span class="block text-[13px] font-bold">New series</span><span class="mt-0.5 block text-[11px] text-muted">Build the catalog entry</span></span>
                        </a>
                    @endcan
                    @can('upload_chapters')
                        <a href="{{ route('admin.upload') }}" class="group/action flex items-center gap-3 rounded-xl border border-line bg-paper/70 p-3.5 hover:border-koma/25 hover:bg-koma-soft">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-koma shadow-sm">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5"/><path d="M5 20h14"/></svg>
                            </span>
                            <span><span class="block text-[13px] font-bold">Upload chapter</span><span class="mt-0.5 block text-[11px] text-muted">Add ordered page images</span></span>
                        </a>
                    @endcan
                    @can('edit_series')
                        <a href="{{ route('admin.archives.import') }}" class="group/action flex items-center gap-3 rounded-xl border border-line bg-paper/70 p-3.5 hover:border-koma/25 hover:bg-koma-soft">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-koma shadow-sm">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16v13H4zM8 3h8l2 4H6z"/><path d="M12 10v6M9 13h6"/></svg>
                            </span>
                            <span><span class="block text-[13px] font-bold">Import archive</span><span class="mt-0.5 block text-[11px] text-muted">Bring in Koma or gallery ZIPs</span></span>
                        </a>
                    @endcan
                    @can('moderate_comments')
                        <a href="{{ route('admin.comments') }}" class="group/action flex items-center gap-3 rounded-xl border border-line bg-paper/70 p-3.5 hover:border-koma/25 hover:bg-koma-soft">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-koma shadow-sm">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/></svg>
                            </span>
                            <span><span class="block text-[13px] font-bold">Moderate comments</span><span class="mt-0.5 block text-[11px] text-muted">Handle reports and review</span></span>
                        </a>
                    @endcan
                </div>
                @break

            @case('content_status')
                <div class="grid grid-cols-2 gap-px bg-line-soft">
                    @foreach ($contentStatus as $status)
                        <div class="bg-white px-4 py-3.5">
                            <div class="font-display text-[22px] font-extrabold">{{ number_format($status['value']) }}</div>
                            <div class="mt-0.5 text-[11.5px] font-semibold text-muted">{{ $status['label'] }}</div>
                        </div>
                    @endforeach
                </div>
                <div class="border-t border-line-soft bg-paper/60 px-4 py-3 text-right">
                    <a href="{{ route('admin.series.index') }}" class="text-[12px] font-bold text-koma hover:underline">Open content manager →</a>
                </div>
                @break

            @case('recent_activity')
                <div class="divide-y divide-line-soft">
                    @forelse ($recentChapters as $chapter)
                        <div class="flex gap-3 px-4 py-3">
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-koma"></span>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-[12.5px] leading-5">
                                    Chapter {{ $chapter->displayNumber() }} added to
                                    <span class="font-bold">{{ $chapter->series->title }}</span>
                                </div>
                                <div class="text-[11px] text-faint">{{ \App\Support\Koma::timeAgo($chapter->created_at) }} · {{ ucfirst($chapter->status) }}</div>
                            </div>
                        </div>
                    @empty
                        <div class="px-5 py-8 text-center">
                            <p class="text-[13px] font-semibold text-muted">No publishing activity yet.</p>
                            @can('upload_chapters')
                                <a href="{{ route('admin.upload') }}" class="mt-2 inline-block text-[12px] font-bold text-koma hover:underline">Upload the first chapter →</a>
                            @endcan
                        </div>
                    @endforelse
                </div>
                @break

            @case('popular_series')
                <div class="flex flex-col gap-3.5 p-4">
                    @forelse ($topSeries as $series)
                        <div class="flex items-center gap-3">
                            <span class="h-[38px] w-[28px] shrink-0 rounded-[5px]" style="background: {{ $series->coverGradient() }}"></span>
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('admin.series.edit', $series) }}" class="block truncate text-[12.5px] font-semibold hover:text-koma">{{ $series->title }}</a>
                                <div class="mt-1.5 h-[5px] overflow-hidden rounded-full bg-line-soft">
                                    <div class="h-full rounded-full bg-koma" style="width: {{ max(4, (int) round($series->views / $maxViews * 100)) }}%"></div>
                                </div>
                            </div>
                            <span class="text-[11.5px] font-bold text-[#57524c]">{{ \Illuminate\Support\Number::abbreviate($series->views) }}</span>
                        </div>
                    @empty
                        <p class="py-6 text-center text-[13px] text-faint">Reader activity will appear here.</p>
                    @endforelse
                </div>
                @break

            @case('moderation')
                <div class="grid grid-cols-2 gap-px bg-line-soft">
                    <div class="bg-white p-4">
                        <div class="font-display text-[24px] font-extrabold">{{ number_format($moderationCounts['reported']) }}</div>
                        <div class="mt-0.5 text-[11.5px] font-semibold text-muted">Open reports</div>
                    </div>
                    <div class="bg-white p-4">
                        <div class="font-display text-[24px] font-extrabold">{{ number_format($moderationCounts['pending']) }}</div>
                        <div class="mt-0.5 text-[11.5px] font-semibold text-muted">Pending review</div>
                    </div>
                </div>
                <div class="border-t border-line-soft bg-paper/60 px-4 py-3 text-right">
                    <a href="{{ route('admin.comments') }}" class="text-[12px] font-bold text-koma hover:underline">Open moderation queue →</a>
                </div>
                @break

            @case('archive_activity')
                <div class="divide-y divide-line-soft">
                    @forelse ($recentImports as $import)
                        @php
                            $statusClasses = match ($import->status) {
                                \App\Models\SeriesArchiveImport::STATUS_COMPLETED => 'bg-[oklch(0.7_0.13_150_/_0.13)] text-[oklch(0.42_0.12_150)]',
                                \App\Models\SeriesArchiveImport::STATUS_FAILED => 'bg-koma-soft text-[#843c31]',
                                default => 'bg-[oklch(0.72_0.12_250_/_0.12)] text-[oklch(0.43_0.1_250)]',
                            };
                        @endphp
                        <a href="{{ route('admin.archives.import', ['import' => $import->id]) }}" class="flex items-center gap-3 px-4 py-3 hover:bg-paper/70">
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-[12.5px] font-semibold">{{ $import->source_name }}</div>
                                <div class="mt-0.5 text-[11px] text-faint">{{ \App\Support\Koma::timeAgo($import->created_at) }}</div>
                            </div>
                            <span class="rounded-full px-2 py-1 text-[10px] font-extrabold uppercase tracking-wide {{ $statusClasses }}">{{ $import->status }}</span>
                        </a>
                    @empty
                        <div class="px-5 py-8 text-center">
                            <p class="text-[13px] font-semibold text-muted">No archive imports yet.</p>
                            <a href="{{ route('admin.archives.import') }}" class="mt-2 inline-block text-[12px] font-bold text-koma hover:underline">Import an archive →</a>
                        </div>
                    @endforelse
                </div>
                @break
        @endswitch
    </div>
</article>
