<div
    x-data="chapterUploadRecovery({{ count($pages) }}, {{ count($failedFiles) }})"
    x-on:upload-session-updated.window="settle($event.detail)"
    x-on:beforeunload.window="warnBeforeLeaving($event)"
    class="grid grid-cols-1 items-start gap-5 lg:grid-cols-[1fr_300px]"
>
    <div class="flex flex-col gap-4">
        <div @class([
            'rounded-xl border px-4 py-3 text-[12.5px] leading-relaxed',
            'border-[oklch(0.7_0.13_150_/_0.35)] bg-[oklch(0.7_0.13_150_/_0.08)] text-[oklch(0.35_0.08_150)]' => $imageCapabilities['gd'] && $imageCapabilities['webp'],
            'border-[oklch(0.75_0.13_70_/_0.45)] bg-[oklch(0.75_0.13_70_/_0.10)] text-[oklch(0.4_0.09_70)]' => $imageCapabilities['gd'] && ! $imageCapabilities['webp'],
            'border-koma/35 bg-koma-soft font-semibold text-[#6d3329]' => ! $imageCapabilities['gd'],
        ])>
            @if (! $imageCapabilities['gd'])
                Image uploads are unavailable because the PHP GD extension is missing.
            @elseif ($imageCapabilities['webp'])
                Image optimization active: new pages are resized when needed and stored as WebP.
            @else
                WebP encoding is unavailable. Pages will be optimized in JPEG or PNG fallback format.
            @endif
        </div>

        <div class="rounded-xl border border-line bg-white px-4 py-3" role="status" aria-live="polite">
            <div class="flex items-center justify-between gap-3 text-[12.5px]">
                <span class="font-semibold text-ink" x-show="transferring">
                    Transferring <span x-text="transferItems.length"></span> page<span x-show="transferItems.length !== 1">s</span>…
                </span>
                <span class="font-semibold text-ink" x-show="processing && ! transferring">Validating selected pages…</span>
                <span class="font-semibold text-ink" x-show="saving">Validating and saving the chapter atomically…</span>
                <span class="text-muted" x-show="! uploadBusy && ! saving">
                    <strong class="text-ink" x-text="readyCount"></strong> ready
                    <span aria-hidden="true"> · </span>
                    <strong :class="failedCount + clientFailures.length ? 'text-koma' : 'text-ink'" x-text="failedCount + clientFailures.length"></strong> need attention
                </span>
                <span class="font-mono text-[11.5px] text-muted" x-show="transferring" x-text="`${aggregateProgress}%`"></span>
            </div>
            <div x-show="transferring" class="mt-2 h-1.5 overflow-hidden rounded-full bg-line-soft">
                <div class="h-full rounded-full bg-koma transition-[width] duration-150" :style="`width: ${aggregateProgress}%`"></div>
            </div>
            <p class="mt-1.5 text-[11px] leading-4 text-faint">Ready pages are temporary. Nothing is published or saved until the final action succeeds.</p>
        </div>

        {{-- Dropzone --}}
        <div x-data="{ over: false }"
             x-on:dragover.prevent="if (! uploadBusy) over = true"
             x-on:dragleave.prevent="over = false"
             x-on:drop.prevent="over = false; if (! uploadBusy) selectFiles($event)"
             x-on:click="if (! uploadBusy) $refs.input.click()"
             :class="over ? 'border-koma bg-koma-soft' : 'border-[#d6c8bb]'"
             @class([
                'rounded-2xl border-2 border-dashed bg-white p-9 text-center transition',
                'cursor-pointer' => $imageCapabilities['gd'],
                'cursor-not-allowed opacity-60' => ! $imageCapabilities['gd'],
             ])>
            <input type="file" x-ref="input" x-on:change.stop="selectFiles($event)" accept="image/jpeg,image/png,image/webp" multiple class="hidden" @disabled(! $imageCapabilities['gd']) :disabled="uploadBusy">
            <input type="file" x-ref="clientRetryInput" x-on:change.stop="selectClientRetry($event)" accept="image/jpeg,image/png,image/webp" class="hidden">
            <div class="mx-auto mb-3 flex h-[52px] w-[52px] items-center justify-center rounded-[14px] bg-koma-soft">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="oklch(0.64 0.2 32)" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M12 3v13"/><path d="m7 8 5-5 5 5"/></svg>
            </div>
            <div class="text-[15px] font-bold">Drop page images here, or click to browse</div>
            <div class="mt-1 text-[13px] text-faint">JPG, PNG, WEBP up to 20&nbsp;MB · max output 2400×4000 · pages auto-number in order</div>
            <div x-show="uploadBusy" class="mt-3 text-[13px] font-semibold text-koma">Please keep this page open while the selection is checked.</div>
        </div>
        @error('incoming') <p class="text-[12.5px] font-semibold text-koma">{{ $message }}</p> @enderror
        @error('incoming.*') <p class="text-[12.5px] font-semibold text-koma">{{ $message }}</p> @enderror
        @error('pages') <p class="text-[12.5px] font-semibold text-koma">{{ $message }}</p> @enderror
        @error('pages.*') <p class="text-[12.5px] font-semibold text-koma">{{ $message }}</p> @enderror
        @error('failedFiles') <p class="text-[12.5px] font-semibold text-koma">{{ $message }}</p> @enderror

        <div x-show="transferItems.length" class="rounded-2xl border border-line bg-white p-4">
            <h2 class="mb-2.5 font-display text-sm font-extrabold">Current transfer</h2>
            <div class="space-y-2">
                <template x-for="item in transferItems" :key="item.id">
                    <div class="flex items-center gap-3 rounded-lg bg-paper px-3 py-2 text-[12px]">
                        <span class="min-w-0 flex-1 truncate font-semibold" x-text="item.name"></span>
                        <span class="font-mono text-[10.5px] text-muted" x-text="item.state === 'transferring' ? `${item.progress}%` : (item.state === 'validating' ? 'validating' : 'failed')"></span>
                    </div>
                </template>
            </div>
        </div>

        @if (count($failedFiles))
            <div class="rounded-2xl border border-koma/25 bg-koma-soft/40 p-4">
                <h2 class="font-display text-sm font-extrabold text-[#6d3329]">Files needing attention</h2>
                <p class="mt-1 text-[11.5px] leading-4 text-[#7a5048]">Retry replaces the failed slot without disturbing pages that are already ready.</p>
                <div class="mt-3 space-y-2">
                    @foreach ($failedFiles as $failure)
                        <div wire:key="failed-page-{{ $failure['id'] }}" class="rounded-xl border border-koma/20 bg-white p-3">
                            <p class="truncate text-[12.5px] font-bold text-ink">{{ $failure['name'] }}</p>
                            <p class="mt-1 text-[11.5px] leading-4 text-[#7a5048]">{{ $failure['reason'] }}</p>
                            <div class="mt-2 flex gap-2">
                                <label class="cursor-pointer rounded-md bg-koma px-2.5 py-1.5 text-[11.5px] font-bold text-white hover:bg-koma-hover">
                                    Retry selection
                                    <input type="file" x-on:change="selectServerRetry($event, {{ $failure['id'] }})" accept="image/jpeg,image/png,image/webp" class="hidden" :disabled="uploadBusy">
                                </label>
                                <button type="button" wire:click="dismissFailed({{ $failure['id'] }})" class="rounded-md bg-white px-2.5 py-1.5 text-[11.5px] font-semibold text-muted hover:text-koma">Dismiss</button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div x-show="clientFailures.length" class="rounded-2xl border border-koma/25 bg-koma-soft/40 p-4">
            <h2 class="font-display text-sm font-extrabold text-[#6d3329]">Transfers needing attention</h2>
            <div class="mt-3 space-y-2">
                <template x-for="failure in clientFailures" :key="failure.id">
                    <div class="rounded-xl border border-koma/20 bg-white p-3">
                        <p class="truncate text-[12.5px] font-bold text-ink" x-text="failure.name"></p>
                        <p class="mt-1 text-[11.5px] leading-4 text-[#7a5048]" x-text="failure.reason"></p>
                        <div class="mt-2 flex gap-2">
                            <button type="button" x-on:click="chooseClientRetry(failure.id)" class="rounded-md bg-koma px-2.5 py-1.5 text-[11.5px] font-bold text-white hover:bg-koma-hover">Retry selection</button>
                            <button type="button" x-on:click="dismissClient(failure.id)" class="rounded-md bg-white px-2.5 py-1.5 text-[11.5px] font-semibold text-muted hover:text-koma">Dismiss</button>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        {{-- Pages grid --}}
        <div class="rounded-2xl border border-line bg-white p-5">
            <div class="mb-3.5 flex items-center justify-between">
                <h2 class="font-display text-base font-extrabold">Pages <span class="font-semibold text-faint">{{ count($pages) }}</span></h2>
                @if (count($pages) || count($failedFiles))
                    <button type="button" wire:click="clearPages" wire:confirm="Clear every ready and failed page from this upload session?" class="rounded-lg bg-paper px-2.5 py-1.5 text-xs font-semibold hover:bg-line">Clear session</button>
                @endif
            </div>

            @if (count($pages))
                <div class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6">
                    @foreach ($pages as $i => $page)
                        <div wire:key="pg-{{ $pageSequences[$i] ?? $i }}-{{ $page->getFilename() }}" class="relative">
                            <div class="relative flex aspect-[0.7] items-end justify-center overflow-hidden rounded-[9px] bg-line-soft">
                                <img src="{{ $page->temporaryUrl() }}" class="absolute inset-0 h-full w-full object-cover" alt="Page {{ $i + 1 }}">
                                <span class="absolute left-1.5 top-1.5 flex h-[21px] w-[21px] items-center justify-center rounded-md bg-black/55 font-mono text-[11px] font-bold text-white">{{ $i + 1 }}</span>
                                <span class="absolute bottom-1.5 right-1.5 rounded bg-[oklch(0.45_0.1_150_/_0.88)] px-1.5 py-0.5 text-[9.5px] font-bold uppercase tracking-wide text-white">Ready</span>
                            </div>
                            <p class="mt-1 truncate text-[10.5px] text-faint" title="{{ $page->getClientOriginalName() }}">{{ $page->getClientOriginalName() }}</p>
                            <div class="mt-1.5 flex gap-1">
                                <button type="button" wire:click="movePage({{ $i }}, -1)" class="flex-1 rounded-md bg-paper py-1 text-[11px] hover:bg-line">◀</button>
                                <button type="button" wire:click="removePage({{ $i }})" class="flex-1 rounded-md bg-paper py-1 text-[11px] text-koma hover:bg-line">✕</button>
                                <button type="button" wire:click="movePage({{ $i }}, 1)" class="flex-1 rounded-md bg-paper py-1 text-[11px] hover:bg-line">▶</button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="py-8 text-center font-mono text-sm text-[#cabfb4]">no pages yet — drop images above</div>
            @endif
        </div>
    </div>

    {{-- Chapter details --}}
    <div class="flex flex-col gap-4">
        <div class="rounded-2xl border border-line bg-white p-[18px]">
            <h2 class="mb-3.5 font-display text-[15px] font-extrabold">Chapter details</h2>
            <div class="flex flex-col gap-3">
                <div>
                    <label class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">Series</label>
                    <select wire:model="seriesId" class="h-10 w-full rounded-[9px] border border-line bg-white px-2.5 text-[13px] outline-none focus:border-koma">
                        @foreach ($seriesList as $s)
                            <option value="{{ $s->id }}">{{ $s->title }}</option>
                        @endforeach
                    </select>
                    @error('seriesId') <p class="mt-1 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-[80px_1fr] gap-2.5">
                    <div>
                        <label class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">Ch. #</label>
                        <input wire:model="number" class="h-10 w-full rounded-[9px] border border-line px-2.5 text-[13px] outline-none focus:border-koma">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">Title</label>
                        <input wire:model="chapterTitle" placeholder="Optional" class="h-10 w-full rounded-[9px] border border-line px-2.5 text-[13px] outline-none focus:border-koma">
                    </div>
                </div>
                @error('number') <p class="text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
                <div>
                    <label class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">Scanlation group</label>
                    <input wire:model="scanlationGroup" placeholder="Optional" class="h-10 w-full rounded-[9px] border border-line px-2.5 text-[13px] outline-none focus:border-koma">
                </div>
                <div>
                    <label class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">Content language</label>
                    <input wire:model="chapterLanguage" placeholder="en, ja, pt-BR…" class="h-10 w-full rounded-[9px] border border-line px-2.5 font-mono text-[13px] outline-none focus:border-koma">
                    @error('chapterLanguage') <p class="mt-1 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-[11.5px] font-bold uppercase tracking-wide text-muted">Schedule publication</label>
                    <input type="datetime-local" wire:model="publishAt" min="{{ now()->addMinute()->format('Y-m-d\TH:i') }}" class="h-10 w-full rounded-[9px] border border-line px-2.5 text-[13px] outline-none focus:border-koma">
                    <p class="mt-1 text-[11px] leading-4 text-faint">Uses {{ config('app.timezone') }} time. Scheduled chapters stay hidden until the publishing task runs.</p>
                    @error('publishAt') <p class="mt-1 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-line bg-white p-[18px]">
            <button type="button" x-on:click="beginSave('publish')" :disabled="cannotSave" class="mb-2 w-full rounded-[10px] bg-koma py-3 text-sm font-bold text-white hover:bg-koma-hover disabled:cursor-not-allowed disabled:opacity-50">
                <span x-show="! saving || savingAction !== 'publish'">Publish chapter</span>
                <span x-show="saving && savingAction === 'publish'">Publishing…</span>
            </button>
            <button type="button" x-on:click="beginSave('schedule')" :disabled="cannotSave" class="mb-2 w-full rounded-[10px] border border-line bg-white py-2.5 text-[13.5px] font-semibold hover:border-koma hover:text-koma disabled:cursor-not-allowed disabled:opacity-50">
                <span x-show="! saving || savingAction !== 'schedule'">Schedule chapter</span>
                <span x-show="saving && savingAction === 'schedule'">Scheduling…</span>
            </button>
            <button type="button" x-on:click="beginSave('saveDraft')" :disabled="cannotSave" class="w-full rounded-[10px] bg-paper py-2.5 text-[13.5px] font-semibold hover:bg-line disabled:cursor-not-allowed disabled:opacity-50">
                <span x-show="! saving || savingAction !== 'saveDraft'">Save as draft</span>
                <span x-show="saving && savingAction === 'saveDraft'">Saving draft…</span>
            </button>
            <p class="mt-2 text-center text-[10.5px] leading-4 text-faint" x-show="failedCount + clientFailures.length > 0">Resolve or dismiss failed files to enable final saving.</p>
        </div>
    </div>
</div>
