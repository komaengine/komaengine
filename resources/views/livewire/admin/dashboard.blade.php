<div
    x-data="adminDashboard(@js($dashboardPreferences))"
    @keydown.escape.window="closeOptions()"
    @pointermove.window="movePointerDrag($event)"
    @pointerup.window="finishPointerDrag($event)"
    @pointercancel.window="finishPointerDrag($event)"
>
    <div class="relative mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-[13px] text-muted">Drag panels into your preferred order. Your dashboard is saved to this account.</p>

        <div class="flex items-center gap-2">
            <span x-text="saveState" class="text-[11.5px] font-semibold text-faint" aria-live="polite"></span>
            <button
                type="button"
                x-ref="optionsToggle"
                @click="optionsOpen = ! optionsOpen"
                :aria-expanded="optionsOpen.toString()"
                aria-controls="dashboard-screen-options"
                class="inline-flex h-9 items-center gap-2 rounded-lg border border-line bg-white px-3 text-[12.5px] font-bold text-[#4b4641] shadow-sm hover:border-[#d8d0c7] hover:bg-paper focus:outline-none focus:ring-2 focus:ring-koma/30"
            >
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3"/><path d="M1 14h6M9 8h6M17 16h6"/></svg>
                Screen options
            </button>
        </div>

        <div
            id="dashboard-screen-options"
            x-cloak
            x-show="optionsOpen"
            x-transition.origin.top.right
            @click.outside="closeOptions()"
            class="absolute right-0 top-11 z-30 w-full max-w-[420px] rounded-xl border border-line bg-white p-4 shadow-xl"
        >
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="font-display text-sm font-extrabold">Screen options</h2>
                    <p class="mt-1 text-[12px] leading-5 text-muted">Choose the panels shown on your dashboard.</p>
                </div>
                <button type="button" @click="closeOptions()" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-muted hover:bg-paper hover:text-[#282522]" aria-label="Close screen options">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m18 6-12 12M6 6l12 12"/></svg>
                </button>
            </div>

            <fieldset class="mt-4 grid gap-2 sm:grid-cols-2">
                <legend class="sr-only">Dashboard panels</legend>
                @foreach ($widgetLabels as $widgetId => $widgetLabel)
                    <label class="flex cursor-pointer items-center gap-2.5 rounded-lg border border-line-soft px-3 py-2.5 text-[12.5px] font-semibold hover:bg-paper">
                        <input
                            type="checkbox"
                            :checked="! isHidden('{{ $widgetId }}')"
                            @change="setVisible('{{ $widgetId }}', $event.target.checked)"
                            class="h-4 w-4 rounded border-line text-koma focus:ring-koma/30"
                        >
                        {{ $widgetLabel }}
                    </label>
                @endforeach
            </fieldset>

            <div class="mt-4 flex items-center justify-between border-t border-line-soft pt-3">
                <span class="text-[11.5px] text-faint">Changes save automatically.</span>
                <button type="button" @click="resetLayout()" class="text-[12px] font-bold text-koma hover:underline">Reset layout</button>
            </div>
        </div>
    </div>

    <div wire:ignore data-dashboard-board class="grid items-start gap-4 lg:grid-cols-2">
        @foreach ($dashboardPreferences['columns'] as $columnIndex => $widgetIds)
            <div
                data-dashboard-column="{{ $columnIndex }}"
                class="flex min-h-24 flex-col gap-4 rounded-2xl outline-none transition-colors"
                :class="dragging ? 'bg-koma/[0.035]' : ''"
            >
                @foreach ($widgetIds as $widgetId)
                    @include('livewire.admin.partials.dashboard-widget', [
                        'widgetId' => $widgetId,
                        'widgetLabel' => $widgetLabels[$widgetId],
                    ])
                @endforeach
            </div>
        @endforeach
    </div>

    <p x-ref="dashboardAnnouncement" class="sr-only" aria-live="assertive"></p>
</div>
