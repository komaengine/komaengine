<div>
    <a href="{{ route('admin.series.index') }}" class="mb-3.5 inline-block text-[13px] font-semibold text-muted hover:text-koma">← Back to all series</a>

    <form wire:submit="save" class="grid grid-cols-1 items-start gap-5 lg:grid-cols-[1fr_300px]">
        <div class="flex flex-col gap-4">
            {{-- Details --}}
            <div class="rounded-2xl border border-line bg-white p-[22px]">
                <h2 class="mb-4 font-display text-base font-extrabold">Series details</h2>
                <div class="flex flex-col gap-3.5">
                    <div>
                        <label class="mb-1.5 block text-[12px] font-bold uppercase tracking-wide text-muted">Title</label>
                        <input wire:model="title" class="h-[42px] w-full rounded-[10px] border border-line px-3 text-sm font-semibold outline-none focus:border-koma">
                        @error('title') <p class="mt-1 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1.5 block text-[12px] font-bold uppercase tracking-wide text-muted">Author</label>
                            <input wire:model="author" class="h-[42px] w-full rounded-[10px] border border-line px-3 text-[13.5px] outline-none focus:border-koma">
                        </div>
                        <div>
                            <label class="mb-1.5 block text-[12px] font-bold uppercase tracking-wide text-muted">Artist</label>
                            <input wire:model="artist" class="h-[42px] w-full rounded-[10px] border border-line px-3 text-[13.5px] outline-none focus:border-koma">
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="mb-1.5 block text-[12px] font-bold uppercase tracking-wide text-muted">Year</label>
                            <input type="number" wire:model="year" class="h-[42px] w-full rounded-[10px] border border-line px-3 text-[13.5px] outline-none focus:border-koma">
                            @error('year') <p class="mt-1 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1.5 block text-[12px] font-bold uppercase tracking-wide text-muted">Status</label>
                            <select wire:model="status" class="h-[42px] w-full rounded-[10px] border border-line bg-white px-2.5 text-[13.5px] outline-none focus:border-koma">
                                @foreach (\App\Models\Series::STATUSES as $s)
                                    <option value="{{ $s }}">{{ ucfirst($s) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-[12px] font-bold uppercase tracking-wide text-muted">Rating</label>
                            <select wire:model="content_rating" class="h-[42px] w-full rounded-[10px] border border-line bg-white px-2.5 text-[13.5px] outline-none focus:border-koma">
                                @foreach (\App\Models\Series::RATINGS as $r)
                                    <option value="{{ $r }}">{{ ucfirst($r) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-[12px] font-bold uppercase tracking-wide text-muted">Reading direction</label>
                        <div class="flex gap-1.5">
                            @foreach (['rtl' => 'Right → Left', 'ltr' => 'Left → Right', 'vertical' => 'Webtoon'] as $val => $label)
                                <button type="button" wire:click="$set('reading_direction', '{{ $val }}')"
                                    @class([
                                        'flex-1 rounded-[9px] border px-0 py-2.5 text-[12.5px] font-bold transition',
                                        'border-koma bg-koma text-white' => $reading_direction === $val,
                                        'border-line bg-[#faf8f6] text-[#57524c] hover:border-koma/40' => $reading_direction !== $val,
                                    ])>{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-[12px] font-bold uppercase tracking-wide text-muted">Default reader presentation</label>
                        <select wire:model="default_reader_mode" class="h-[42px] w-full rounded-[10px] border border-line bg-white px-2.5 text-[13.5px] outline-none focus:border-koma">
                            <option value="">Follow page-order direction</option>
                            <option value="rtl">Right-to-left pages</option>
                            <option value="ltr">Left-to-right pages</option>
                            <option value="vertical">Vertical webtoon</option>
                        </select>
                        <p class="mt-1 text-[11px] leading-4 text-faint">Used when a signed-in reader has no saved preference. Anonymous device preferences still take priority in that browser.</p>
                        @error('default_reader_mode') <p class="mt-1 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <div class="mb-1.5 flex items-center justify-between gap-3">
                            <label class="block text-[12px] font-bold uppercase tracking-wide text-muted">Genres</label>
                            <a href="{{ route('admin.genres') }}" class="text-[11.5px] font-semibold text-muted hover:text-koma">Manage genres</a>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($genres as $genre)
                                <button type="button" wire:click="toggleGenre({{ $genre->id }})"
                                    aria-pressed="{{ in_array($genre->id, $selectedGenres, true) ? 'true' : 'false' }}"
                                    @class([
                                        'rounded-lg border px-3 py-1.5 text-[12px] font-semibold transition',
                                        'border-koma bg-koma text-white' => in_array($genre->id, $selectedGenres),
                                        'border-line bg-[#faf8f6] text-[#57524c] hover:border-koma/40' => ! in_array($genre->id, $selectedGenres),
                                    ])>{{ $genre->name }}</button>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <div class="mb-1.5 flex items-center justify-between gap-3">
                            <label class="block text-[12px] font-bold uppercase tracking-wide text-muted">Tags</label>
                            <a href="{{ route('admin.tags') }}" class="text-[11.5px] font-semibold text-muted hover:text-koma">Manage tags</a>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @forelse ($tags as $tag)
                                <button type="button" wire:click="toggleTag({{ $tag->id }})"
                                    aria-pressed="{{ in_array($tag->id, $selectedTags, true) ? 'true' : 'false' }}"
                                    @class([
                                        'rounded-lg border px-3 py-1.5 text-[12px] font-semibold transition',
                                        'border-koma bg-koma text-white' => in_array($tag->id, $selectedTags, true),
                                        'border-line bg-[#faf8f6] text-[#57524c] hover:border-koma/40' => ! in_array($tag->id, $selectedTags, true),
                                    ])>{{ $tag->name }}</button>
                            @empty
                                <p class="text-[12px] text-faint">No tags yet. Use Manage tags to create the first one.</p>
                            @endforelse
                        </div>
                        @error('selectedTags.*') <p class="mt-1 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="mb-1.5 block text-[12px] font-bold uppercase tracking-wide text-muted">Synopsis</label>
                        <textarea wire:model="synopsis" rows="4" class="w-full resize-none rounded-[10px] border border-line px-3 py-2.5 text-[13.5px] leading-relaxed outline-none focus:border-koma"></textarea>
                    </div>
                </div>
            </div>

            {{-- Koma Archive v2 catalog metadata --}}
            <div class="rounded-2xl border border-line bg-white p-[22px]">
                <div class="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <h2 class="font-display text-base font-extrabold">Catalog metadata</h2>
                        <p class="mt-1 text-[11.5px] leading-4 text-faint">Portable fields included in Koma Archive v2. Empty lists are valid.</p>
                    </div>
                    <span class="rounded-md bg-paper px-2 py-1 font-mono text-[10.5px] font-bold text-muted">archive v2</span>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-[12px] font-bold uppercase tracking-wide text-muted">Publication date</label>
                        <input type="date" wire:model="publication_date" class="h-[42px] w-full rounded-[10px] border border-line px-3 text-[13.5px] outline-none focus:border-koma">
                        @error('publication_date') <p class="mt-1 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-[12px] font-bold uppercase tracking-wide text-muted">Work format</label>
                        <input wire:model="work_format" placeholder="doujinshi, artist_cg, manga…" class="h-[42px] w-full rounded-[10px] border border-line px-3 text-[13.5px] outline-none focus:border-koma">
                        @error('work_format') <p class="mt-1 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-4">
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <label class="text-[12px] font-bold uppercase tracking-wide text-muted">Alternate titles</label>
                        <button type="button" wire:click="addAlternateTitle" class="rounded-lg bg-paper px-2.5 py-1.5 text-[11.5px] font-semibold hover:bg-line">＋ Add title</button>
                    </div>
                    <div class="space-y-2">
                        @forelse ($alternateTitles as $index => $alternateTitle)
                            <div class="grid gap-2 rounded-xl border border-line-soft p-2 sm:grid-cols-[90px_120px_1fr_auto]" wire:key="alternate-title-{{ $index }}">
                                <input wire:model="alternateTitles.{{ $index }}.language" placeholder="ja" aria-label="Alternate title language" class="h-9 rounded-lg border border-line px-2.5 text-[12.5px] outline-none focus:border-koma">
                                <select wire:model="alternateTitles.{{ $index }}.type" aria-label="Alternate title type" class="h-9 rounded-lg border border-line bg-white px-2 text-[12px] outline-none focus:border-koma">
                                    @foreach (\App\Models\Series::TITLE_TYPES as $type)
                                        <option value="{{ $type }}">{{ ucfirst($type) }}</option>
                                    @endforeach
                                </select>
                                <input wire:model="alternateTitles.{{ $index }}.title" placeholder="Localized or original title" aria-label="Alternate title" class="h-9 min-w-0 rounded-lg border border-line px-2.5 text-[12.5px] outline-none focus:border-koma">
                                <button type="button" wire:click="removeAlternateTitle({{ $index }})" aria-label="Remove alternate title" class="h-9 rounded-lg px-2.5 text-[12px] font-semibold text-koma hover:bg-koma-soft">Remove</button>
                            </div>
                            @error("alternateTitles.{$index}.*") <p class="text-[11.5px] font-semibold text-koma">{{ $message }}</p> @enderror
                        @empty
                            <p class="rounded-xl bg-paper px-3 py-2.5 text-[12px] text-faint">No alternate titles.</p>
                        @endforelse
                    </div>
                </div>

                <div class="mt-4">
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <label class="text-[12px] font-bold uppercase tracking-wide text-muted">Structured credits</label>
                        <button type="button" wire:click="addCredit" class="rounded-lg bg-paper px-2.5 py-1.5 text-[11.5px] font-semibold hover:bg-line">＋ Add credit</button>
                    </div>
                    <div class="space-y-2">
                        @forelse ($credits as $index => $credit)
                            <div class="grid gap-2 rounded-xl border border-line-soft p-2 sm:grid-cols-[1fr_130px_130px_auto]" wire:key="credit-{{ $index }}">
                                <input wire:model="credits.{{ $index }}.name" placeholder="Creator or group name" aria-label="Credit name" class="h-9 min-w-0 rounded-lg border border-line px-2.5 text-[12.5px] outline-none focus:border-koma">
                                <input wire:model="credits.{{ $index }}.role" placeholder="artist" aria-label="Credit role" class="h-9 rounded-lg border border-line px-2.5 text-[12.5px] outline-none focus:border-koma">
                                <select wire:model="credits.{{ $index }}.type" aria-label="Credit type" class="h-9 rounded-lg border border-line bg-white px-2 text-[12px] outline-none focus:border-koma">
                                    @foreach (\App\Models\Series::CREDIT_TYPES as $type)
                                        <option value="{{ $type }}">{{ ucfirst($type) }}</option>
                                    @endforeach
                                </select>
                                <button type="button" wire:click="removeCredit({{ $index }})" aria-label="Remove credit" class="h-9 rounded-lg px-2.5 text-[12px] font-semibold text-koma hover:bg-koma-soft">Remove</button>
                            </div>
                            @error("credits.{$index}.*") <p class="text-[11.5px] font-semibold text-koma">{{ $message }}</p> @enderror
                        @empty
                            <p class="rounded-xl bg-paper px-3 py-2.5 text-[12px] text-faint">No structured credits. Legacy author and artist fields remain available above.</p>
                        @endforelse
                    </div>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-[12px] font-bold uppercase tracking-wide text-muted">Franchises</label>
                        <textarea wire:model="franchisesText" rows="4" placeholder="One franchise per line" class="w-full resize-y rounded-[10px] border border-line px-3 py-2.5 text-[13px] leading-relaxed outline-none focus:border-koma"></textarea>
                        @error('franchisesText') <p class="mt-1 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-[12px] font-bold uppercase tracking-wide text-muted">Characters</label>
                        <textarea wire:model="charactersText" rows="4" placeholder="One character per line" class="w-full resize-y rounded-[10px] border border-line px-3 py-2.5 text-[13px] leading-relaxed outline-none focus:border-koma"></textarea>
                        @error('charactersText') <p class="mt-1 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-4">
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <label class="text-[12px] font-bold uppercase tracking-wide text-muted">Source references</label>
                        <button type="button" wire:click="addSourceReference" class="rounded-lg bg-paper px-2.5 py-1.5 text-[11.5px] font-semibold hover:bg-line">＋ Add source</button>
                    </div>
                    <div class="space-y-2">
                        @forelse ($sourceReferences as $index => $reference)
                            <div class="grid gap-2 rounded-xl border border-line-soft p-2 sm:grid-cols-[130px_150px_1fr_auto]" wire:key="source-reference-{{ $index }}">
                                <input wire:model="sourceReferences.{{ $index }}.provider" placeholder="provider" aria-label="Source provider" class="h-9 rounded-lg border border-line px-2.5 text-[12.5px] outline-none focus:border-koma">
                                <input wire:model="sourceReferences.{{ $index }}.external_id" placeholder="External ID" aria-label="External ID" class="h-9 rounded-lg border border-line px-2.5 text-[12.5px] outline-none focus:border-koma">
                                <input type="url" wire:model="sourceReferences.{{ $index }}.url" placeholder="https://…" aria-label="Source URL" class="h-9 min-w-0 rounded-lg border border-line px-2.5 text-[12.5px] outline-none focus:border-koma">
                                <button type="button" wire:click="removeSourceReference({{ $index }})" aria-label="Remove source reference" class="h-9 rounded-lg px-2.5 text-[12px] font-semibold text-koma hover:bg-koma-soft">Remove</button>
                            </div>
                            @error("sourceReferences.{$index}.*") <p class="text-[11.5px] font-semibold text-koma">{{ $message }}</p> @enderror
                        @empty
                            <p class="rounded-xl bg-paper px-3 py-2.5 text-[12px] text-faint">No external source references.</p>
                        @endforelse
                    </div>
                    @if ($series?->source_metadata)
                        <p class="mt-2 rounded-lg bg-paper px-3 py-2 text-[11.5px] text-muted">The original external manifest is preserved privately for diagnostics and remapping.</p>
                    @endif
                </div>
            </div>

            {{-- Chapters --}}
            @unless ($creating)
            <div class="rounded-2xl border border-line bg-white p-[22px]">
                <div class="mb-3.5 flex items-center justify-between">
                    <h2 class="font-display text-base font-extrabold">Chapters <span class="font-semibold text-faint">{{ $chapters->count() }}</span></h2>
                    <a href="{{ route('admin.upload', ['series' => $series->id]) }}" class="rounded-[9px] bg-koma px-3.5 py-2 text-[13px] font-bold text-white hover:bg-koma-hover">＋ Upload chapter</a>
                </div>
                <div class="flex flex-col gap-1">
                    @forelse ($chapters as $chapter)
                        <div class="flex flex-wrap items-center gap-3 rounded-[10px] border border-line-soft px-3 py-2.5" wire:key="ch-{{ $chapter->id }}">
                            <span class="w-11 font-display text-sm font-extrabold text-koma">{{ $chapter->displayNumber() }}</span>
                            <span class="flex-1 truncate text-[13.5px] font-semibold">{{ $chapter->title ?: 'Chapter '.$chapter->displayNumber() }}</span>
                            <input wire:model="chapterLanguages.{{ $chapter->id }}" placeholder="lang" aria-label="Chapter {{ $chapter->displayNumber() }} language" class="h-8 w-16 rounded-md border border-line px-2 font-mono text-[11.5px] outline-none focus:border-koma">
                            <span class="text-xs text-faint">{{ $chapter->pages_count }}p</span>
                            @if ($chapter->isPublished())
                                <span class="rounded-md bg-[oklch(0.7_0.13_150_/_0.15)] px-2 py-0.5 text-[10.5px] font-bold text-[oklch(0.42_0.12_150)]">Published</span>
                            @elseif ($chapter->isScheduled())
                                <span class="rounded-md bg-[oklch(0.68_0.12_245_/_0.14)] px-2 py-0.5 text-[10.5px] font-bold text-[oklch(0.42_0.12_245)]">Scheduled · {{ $chapter->published_at->timezone(config('app.timezone'))->format('M j, g:i A') }}</span>
                            @else
                                <span class="rounded-md bg-[oklch(0.7_0.14_60_/_0.18)] px-2 py-0.5 text-[10.5px] font-bold text-[oklch(0.48_0.14_60)]">Draft</span>
                            @endif
                            @if ($chapter->pages_count > 0)
                                <a href="{{ route('admin.series.chapters.pages', [$series, $chapter]) }}" class="rounded-md border border-line px-2.5 py-1 text-[12px] font-semibold hover:border-koma hover:text-koma">Manage pages</a>
                            @endif
                            <button type="button" wire:click="togglePublish({{ $chapter->id }})" class="rounded-md bg-paper px-2.5 py-1 text-[12px] font-semibold hover:bg-line">
                                {{ $chapter->isPublished() ? 'Unpublish' : ($chapter->isScheduled() ? 'Publish now' : 'Publish') }}
                            </button>
                            <button type="button" wire:click="deleteChapter({{ $chapter->id }})" wire:confirm="Delete this chapter and its pages?" class="rounded-md bg-paper px-2.5 py-1 text-[12px] font-semibold text-koma hover:bg-line">Delete</button>

                            @unless ($chapter->isPublished())
                                <div class="flex w-full flex-wrap items-end gap-2 border-t border-line-soft pt-2.5">
                                    <div class="min-w-[190px] flex-1">
                                        <label class="mb-1 block text-[10.5px] font-bold uppercase tracking-wide text-faint">{{ $chapter->isScheduled() ? 'Reschedule publication' : 'Schedule publication' }}</label>
                                        <input type="datetime-local" wire:model="scheduleTimes.{{ $chapter->id }}" min="{{ now()->addMinute()->format('Y-m-d\TH:i') }}" class="h-9 w-full rounded-[8px] border border-line px-2.5 text-[12.5px] outline-none focus:border-koma">
                                        @error("scheduleTimes.{$chapter->id}") <p class="mt-1 text-[11.5px] font-semibold text-koma">{{ $message }}</p> @enderror
                                    </div>
                                    <button type="button" wire:click="scheduleChapter({{ $chapter->id }})" class="h-9 rounded-[8px] border border-line bg-white px-3 text-[12px] font-semibold hover:border-koma hover:text-koma">{{ $chapter->isScheduled() ? 'Reschedule' : 'Schedule' }}</button>
                                    @if ($chapter->isScheduled())
                                        <button type="button" wire:click="cancelSchedule({{ $chapter->id }})" class="h-9 rounded-[8px] bg-paper px-3 text-[12px] font-semibold hover:bg-line">Cancel schedule</button>
                                    @endif
                                </div>
                            @endunless
                            @error("chapterLanguages.{$chapter->id}") <p class="w-full text-[11.5px] font-semibold text-koma">{{ $message }}</p> @enderror
                        </div>
                    @empty
                        <p class="py-6 text-center text-[13.5px] text-faint">No chapters yet. Upload the first one.</p>
                    @endforelse
                </div>
            </div>
            @endunless
        </div>

        {{-- Sidebar --}}
        <div class="flex flex-col gap-4">
            <div
                class="rounded-2xl border border-line bg-white p-[18px]"
                x-data="{
                    cropX: @entangle('coverCropX').live,
                    cropY: @entangle('coverCropY').live,
                    cropZoom: @entangle('coverCropZoom').live,
                }"
            >
                <label class="mb-2.5 block text-[12px] font-bold uppercase tracking-wide text-muted">Cover art</label>
                <div class="mb-2.5 flex aspect-[5/7] items-center justify-center overflow-hidden rounded-xl font-display text-[40px] font-extrabold text-white/50"
                     style="background: {{ $series?->coverGradient() ?? 'linear-gradient(145deg, oklch(0.66 0.15 238), oklch(0.42 0.12 210))' }}">
                    @if ($cover)
                        <img
                            src="{{ $cover->temporaryUrl() }}"
                            class="h-full w-full object-cover will-change-transform"
                            :style="`object-position: ${cropX * 100}% ${cropY * 100}%; transform: scale(${cropZoom}); transform-origin: ${cropX * 100}% ${cropY * 100}%`"
                            alt="Cover crop preview"
                        >
                    @elseif ($series?->coverUrl())
                        <img src="{{ $series->coverUrl() }}" class="h-full w-full object-cover" alt="{{ $series->title }} cover">
                    @else
                        {{ $series?->monogram() ?? 'KE' }}
                    @endif
                </div>
                <div @class([
                    'mb-2.5 rounded-lg px-3 py-2 text-[11.5px] leading-relaxed',
                    'bg-[oklch(0.7_0.13_150_/_0.10)] text-[oklch(0.35_0.08_150)]' => $imageCapabilities['gd'] && $imageCapabilities['webp'],
                    'bg-[oklch(0.75_0.13_70_/_0.13)] text-[oklch(0.4_0.09_70)]' => $imageCapabilities['gd'] && ! $imageCapabilities['webp'],
                    'bg-koma-soft font-semibold text-[#6d3329]' => ! $imageCapabilities['gd'],
                ])>
                    @if (! $imageCapabilities['gd'])
                        Cover uploads require the PHP GD extension.
                    @elseif ($imageCapabilities['webp'])
                        Generates a 1000×1400 WebP cover and 300×420 thumbnail.
                    @else
                        Generates cover derivatives using JPEG or PNG fallback encoding.
                    @endif
                </div>
                <label class="block w-full cursor-pointer rounded-[9px] border border-dashed border-[#cabfb4] bg-paper py-2.5 text-center text-[13px] font-semibold text-[#57524c] hover:bg-line">
                    <input type="file" wire:model="cover" accept="image/jpeg,image/png,image/webp" class="hidden" @disabled(! $imageCapabilities['gd'])>
                    <span wire:loading.remove wire:target="cover">Choose cover…</span>
                    <span wire:loading wire:target="cover">Uploading…</span>
                </label>
                @error('cover') <p class="mt-1.5 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror

                @if ($cover)
                    <fieldset class="mt-3 space-y-2.5 rounded-xl bg-paper p-3">
                        <legend class="px-1 text-[10.5px] font-bold uppercase tracking-wide text-muted">Crop position</legend>
                        <label class="block text-[11.5px] font-semibold text-muted">
                            Horizontal
                            <input type="range" min="0" max="1" step="0.01" x-model.number="cropX" aria-label="Cover crop horizontal position" class="mt-1 block w-full accent-koma">
                        </label>
                        <label class="block text-[11.5px] font-semibold text-muted">
                            Vertical
                            <input type="range" min="0" max="1" step="0.01" x-model.number="cropY" aria-label="Cover crop vertical position" class="mt-1 block w-full accent-koma">
                        </label>
                        <label class="block text-[11.5px] font-semibold text-muted">
                            Zoom
                            <input type="range" min="1" max="3" step="0.05" x-model.number="cropZoom" aria-label="Cover crop zoom" class="mt-1 block w-full accent-koma">
                        </label>
                    </fieldset>
                @endif
                @error('coverCropX') <p class="mt-1.5 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
                @error('coverCropY') <p class="mt-1.5 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
                @error('coverCropZoom') <p class="mt-1.5 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
            </div>

            <div class="rounded-2xl border border-line bg-white p-[18px]">
                <button type="submit" class="mb-2 w-full rounded-[10px] bg-koma py-3 text-sm font-bold text-white hover:bg-koma-hover" wire:loading.attr="disabled">
                    {{ $creating ? 'Create series' : 'Save changes' }}
                </button>
                @unless ($creating)
                    <a href="{{ route('series.show', $series) }}" target="_blank" class="block w-full rounded-[10px] bg-paper py-2.5 text-center text-[13.5px] font-semibold hover:bg-line">{{ $series->is_public ? 'View on site' : 'Preview private series' }}</a>
                @endunless
            </div>

            <div class="rounded-2xl border border-line bg-white p-[18px]">
                <div class="mb-2.5 flex items-start justify-between gap-3">
                    <div>
                        <p class="text-[12px] font-bold uppercase tracking-wide text-muted">Visibility</p>
                        <p class="mt-1 text-[12px] leading-relaxed text-faint">
                            {{ $is_public ? 'Visible wherever it has published chapters.' : 'Only Editors and Administrators can preview it.' }}
                        </p>
                    </div>
                    <span @class([
                        'shrink-0 rounded-md px-2 py-1 text-[10.5px] font-bold',
                        'bg-[oklch(0.7_0.13_150_/_0.15)] text-[oklch(0.42_0.12_150)]' => $is_public,
                        'bg-[oklch(0.7_0.14_60_/_0.18)] text-[oklch(0.48_0.14_60)]' => ! $is_public,
                    ])>{{ $is_public ? 'Public' : 'Private' }}</span>
                </div>
                <div class="grid grid-cols-2 gap-1.5 rounded-[10px] bg-paper p-1">
                    <button type="button" wire:click="$set('is_public', false)" aria-pressed="{{ $is_public ? 'false' : 'true' }}"
                        @class([
                            'rounded-[7px] px-2 py-2 text-[12.5px] font-bold transition',
                            'bg-white text-ink shadow-sm' => ! $is_public,
                            'text-muted hover:text-ink' => $is_public,
                        ])>Private</button>
                    <button type="button" wire:click="$set('is_public', true)" aria-pressed="{{ $is_public ? 'true' : 'false' }}"
                        @class([
                            'rounded-[7px] px-2 py-2 text-[12.5px] font-bold transition',
                            'bg-white text-ink shadow-sm' => $is_public,
                            'text-muted hover:text-ink' => ! $is_public,
                        ])>Public</button>
                </div>
                @error('is_public') <p class="mt-1.5 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
            </div>

            @unless ($creating)
                <div class="rounded-2xl border border-line bg-white p-[18px]">
                    <p class="text-[12px] font-bold uppercase tracking-wide text-muted">Portable archive</p>
                    <p class="mt-1.5 text-[12px] leading-relaxed text-faint">V2 preserves rich catalog metadata, ordered media, sizes, and SHA-256 checksums.</p>
                    <a href="{{ route('admin.series.export', ['series' => $series, 'schema' => 2]) }}" class="mt-3 block w-full rounded-[10px] border border-line bg-white py-2.5 text-center text-[13px] font-bold hover:border-koma hover:text-koma">Download v2 archive</a>
                    <a href="{{ route('admin.series.export', ['series' => $series, 'schema' => 1]) }}" class="mt-2 block text-center text-[11.5px] font-semibold text-muted hover:text-koma">Download v1 compatibility archive</a>
                </div>
            @endunless

        </div>
    </form>
</div>
