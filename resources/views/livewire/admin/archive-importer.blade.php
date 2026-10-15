<div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
    <div class="space-y-5">
        <section class="rounded-2xl border border-line bg-white p-5">
            <div class="flex items-start gap-3">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-koma-soft text-koma">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>
                </div>
                <div>
                    <h2 class="font-display text-lg font-extrabold">Import Series</h2>
                    @if ($batchOptions['enabled'])
                        <p class="mt-1 max-w-2xl text-[13px] leading-5 text-muted">Choose up to {{ $batchOptions['max_files'] }} Koma or supported gallery archives. Each ZIP is retained, validated, and imported as its own Series by the normal archive worker.</p>
                    @else
                        <p class="mt-1 max-w-2xl text-[13px] leading-5 text-muted">Hello! Upload your Koma archives and we will import everything for you. If you wish to import more than one item at a time use the API.</p>
                    @endif
                </div>
            </div>

            @if ($intakeError)
                <div class="mt-4 rounded-xl border border-koma/30 bg-koma-soft/55 px-4 py-3 text-[#6d3329]" role="alert">
                    <p class="text-[12.5px] font-extrabold">The upload never reached Koma’s import queue.</p>
                    <p class="mt-1 text-[12px] leading-5">{{ $intakeError }}</p>
                    <p class="mt-1 text-[11.5px] leading-5">No import record or retained source was created, so it is safe to correct the request limit and try again.</p>
                </div>
            @endif

            @if ($uploadLimits['constrained_by_server'])
                <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-amber-900" role="status">
                    <p class="text-[12.5px] font-extrabold">This server is configured below Koma’s archive limit.</p>
                    <p class="mt-1 text-[11.5px] leading-5">Effective request limit: {{ $uploadLimits['effective_label'] }}. Koma permits {{ $uploadLimits['application_label'] }}, but PHP currently reports upload {{ $uploadLimits['php_upload_label'] }} and post {{ $uploadLimits['php_post_label'] }}.</p>
                </div>
            @endif

            <form
                method="POST"
                action="{{ route('admin.archives.import.store') }}"
                enctype="multipart/form-data"
                x-data="{
                    policy: @js(old('conflict_policy', 'fail')),
                    submitting: false,
                    files: [],
                    totalBytes: 0,
                    selectionError: '',
                    effectiveMax: @js($uploadLimits['effective_bytes']),
                    effectiveLabel: @js($uploadLimits['effective_label']),
                    postMax: @js($uploadLimits['php_post_bytes']),
                    postLabel: @js($uploadLimits['php_post_label']),
                    batchEnabled: @js($batchOptions['enabled']),
                    maximumFiles: @js($batchOptions['max_files']),
                    inspectFile(event) {
                        this.files = Array.from(event.target.files ?? []);
                        this.totalBytes = this.files.reduce((total, file) => total + file.size, 0);
                        const oversized = this.files.find((file) => file.size > this.effectiveMax);

                        this.selectionError = this.batchEnabled && this.files.length > this.maximumFiles
                            ? `Choose no more than ${this.maximumFiles} archives in one batch.`
                            : oversized
                                ? `${oversized.name} is ${this.formatBytes(oversized.size)}, above this server’s ${this.effectiveLabel} effective limit.`
                                : this.batchEnabled && this.postMax && this.totalBytes >= this.postMax
                                    ? `The selected archives total ${this.formatBytes(this.totalBytes)}. Choose fewer files so the complete request stays below PHP’s ${this.postLabel} post limit.`
                                    : '';
                    },
                    formatBytes(bytes) {
                        if (bytes >= 1073741824) return `${(bytes / 1073741824).toFixed(1)} GiB`;
                        if (bytes >= 1048576) return `${(bytes / 1048576).toFixed(1)} MiB`;
                        return `${(bytes / 1024).toFixed(1)} KiB`;
                    },
                }"
                x-on:submit="if (selectionError) { $event.preventDefault(); } else { submitting = true; }"
                class="mt-5 space-y-5"
            >
                @csrf
                <div>
                    <label class="block rounded-xl border-2 border-dashed border-[#d6c8bb] bg-paper/50 p-6 text-center transition hover:border-koma">
                        <span class="block text-sm font-bold">Choose{{ $batchOptions['enabled'] ? ' one or more' : '' }} Koma or supported gallery archive{{ $batchOptions['enabled'] ? 's' : '' }}</span>
                        @if ($batchOptions['enabled'])
                            <span class="mt-1 block text-[11.5px] text-faint">ZIP or .koma.zip · up to {{ $batchOptions['max_files'] }} files · each file up to {{ $uploadLimits['effective_label'] }}</span>
                            <input name="archives[]" type="file" multiple accept=".zip,.koma.zip,application/zip,application/x-zip-compressed" required x-on:change="inspectFile($event)" class="mx-auto mt-3 block max-w-full text-[12px] text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-koma file:px-3 file:py-2 file:font-bold file:text-white">
                        @else
                            <span class="mt-1 block text-[11.5px] text-faint">ZIP or .koma.zip · current effective limit {{ $uploadLimits['effective_label'] }}</span>
                            <input name="archive" type="file" accept=".zip,.koma.zip,application/zip,application/x-zip-compressed" required x-on:change="inspectFile($event)" class="mx-auto mt-3 block max-w-full text-[12px] text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-koma file:px-3 file:py-2 file:font-bold file:text-white">
                        @endif
                    </label>
                    <template x-if="files.length === 1">
                        <p class="mt-2 break-all text-[11.5px] font-semibold text-muted"><span x-text="files[0].name"></span> · <span x-text="formatBytes(files[0].size)"></span></p>
                    </template>
                    <template x-if="files.length > 1">
                        <div class="mt-2 rounded-lg bg-paper px-3 py-2 text-[11.5px] text-muted">
                            <p class="font-semibold"><span x-text="files.length"></span> archives selected · <span x-text="formatBytes(totalBytes)"></span> total</p>
                            <ul class="mt-1 max-h-28 space-y-0.5 overflow-y-auto break-all text-[10.5px] leading-4 text-faint">
                                <template x-for="file in files" :key="`${file.name}-${file.size}-${file.lastModified}`"><li x-text="file.name"></li></template>
                            </ul>
                        </div>
                    </template>
                    <p x-cloak x-show="selectionError" x-text="selectionError" class="mt-2 text-[12.5px] font-semibold text-koma" role="alert"></p>
                    @if ($batchOptions['enabled'])
                        @error('archives') <p class="mt-2 text-[12.5px] font-semibold text-koma">{{ $message }}</p> @enderror
                        @foreach (collect($errors->get('archives.*'))->flatten()->unique() as $message)
                            <p class="mt-2 text-[12.5px] font-semibold text-koma">{{ $message }}</p>
                        @endforeach
                    @else
                        @error('archive') <p class="mt-2 text-[12.5px] font-semibold text-koma">{{ $message }}</p> @enderror
                    @endif
                </div>

                <fieldset>
                    <legend class="text-[11.5px] font-bold uppercase tracking-wide text-muted">Slug conflict policy</legend>
                    <div class="mt-2 grid gap-2 sm:grid-cols-3">
                        <label class="rounded-xl border p-3 transition" :class="policy === 'fail' ? 'border-koma bg-koma-soft/45' : 'border-line'">
                            <span class="flex items-center gap-2 text-[13px] font-bold"><input x-model="policy" name="conflict_policy" type="radio" value="fail" @checked(old('conflict_policy', 'fail') === 'fail') class="text-koma"> Fail safely</span>
                            <span class="mt-1 block text-[11px] leading-4 text-faint">Default. Keep all existing data unchanged.</span>
                        </label>
                        <label class="rounded-xl border p-3 transition" :class="policy === 'create_unique' ? 'border-koma bg-koma-soft/45' : 'border-line'">
                            <span class="flex items-center gap-2 text-[13px] font-bold"><input x-model="policy" name="conflict_policy" type="radio" value="create_unique" @checked(old('conflict_policy') === 'create_unique') class="text-koma"> Create unique</span>
                            <span class="mt-1 block text-[11px] leading-4 text-faint">Create a new series with a numbered slug.</span>
                        </label>
                        @if (! $batchOptions['enabled'])
                            <label class="rounded-xl border p-3 transition" :class="policy === 'replace' ? 'border-koma bg-koma-soft/45' : 'border-line'">
                                <span class="flex items-center gap-2 text-[13px] font-bold"><input x-model="policy" name="conflict_policy" type="radio" value="replace" @checked(old('conflict_policy') === 'replace') class="text-koma"> Replace selected</span>
                                <span class="mt-1 block text-[11px] leading-4 text-faint">Replace one explicit series. Chapters never merge.</span>
                            </label>
                        @endif
                    </div>
                    @error('conflict_policy') <p class="mt-2 text-[12.5px] font-semibold text-koma">{{ $message }}</p> @enderror
                </fieldset>

                @if (! $batchOptions['enabled'])
                    <div x-cloak x-show="policy === 'replace'" class="rounded-xl border border-koma/30 bg-koma-soft/35 p-4">
                    <label for="replacement_series_id" class="text-[11.5px] font-bold uppercase tracking-wide text-[#6d3329]">Series to replace</label>
                    <select id="replacement_series_id" name="replacement_series_id" class="mt-1.5 h-10 w-full rounded-[9px] border border-line bg-white px-3 text-[13px] outline-none focus:border-koma">
                        <option value="">Select an existing series…</option>
                        @foreach ($seriesList as $series)
                            <option value="{{ $series->id }}" @selected((string) old('replacement_series_id') === (string) $series->id)>{{ $series->title }} ({{ $series->slug }})</option>
                        @endforeach
                    </select>
                    @error('replacement_series_id') <p class="mt-1.5 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror

                    <label class="mt-3 flex items-start gap-2 text-[12px] font-semibold leading-5 text-[#6d3329]">
                        <input name="replace_confirmed" value="1" type="checkbox" @checked(old('replace_confirmed')) class="mt-1 rounded border-koma/40 text-koma">
                        <span>I confirm the selected series, all of its chapters, and its media will be replaced only after the imported copy is durable.</span>
                    </label>
                    @error('replace_confirmed') <p class="mt-1.5 text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
                    </div>
                @endif

                <button type="submit" :disabled="submitting || selectionError.length > 0" class="inline-flex min-h-11 items-center justify-center rounded-[10px] bg-koma px-5 text-[13.5px] font-bold text-white hover:bg-koma-hover disabled:cursor-not-allowed disabled:opacity-55">
                    <span x-show="! submitting">{{ $batchOptions['enabled'] ? 'Upload, validate, and queue all' : 'Upload, validate, and queue' }}</span>
                    <span x-cloak x-show="submitting">Retaining archive{{ $batchOptions['enabled'] ? 's' : '' }}…</span>
                </button>
                <p class="text-[10.5px] leading-4 text-faint">After intake, a worker must process <code class="font-mono">archive-imports</code>.{{ $batchOptions['enabled'] ? ' Each selected archive gets its own queue job, status, and retry path.' : ' A queued record will remain visible here until the worker starts.' }}</p>
            </form>
        </section>

        @if ($activeImport)
            <section
                @if (! $activeImport->isTerminal()) wire:poll.2s @endif
                class="rounded-2xl border border-line bg-white p-5"
                aria-live="polite"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-[10.5px] font-bold uppercase tracking-[0.1em] text-faint">Current import</p>
                        <h2 class="mt-1 break-all font-display text-base font-extrabold">{{ $activeImport->source_name }}</h2>
                    </div>
                    <span @class([
                        'rounded-full px-2.5 py-1 text-[10px] font-extrabold uppercase tracking-wide',
                        'bg-[oklch(0.7_0.13_150_/_0.14)] text-[oklch(0.4_0.12_150)]' => $activeImport->status === 'completed',
                        'bg-koma-soft text-[#6d3329]' => $activeImport->status === 'failed',
                        'bg-paper text-muted' => ! in_array($activeImport->status, ['completed', 'failed'], true),
                    ])>{{ str_replace('_', ' ', $activeImport->status) }}</span>
                </div>

                <div class="mt-4 flex items-center justify-between text-[12px] font-semibold"><span>{{ $activeImport->stage }}</span><span class="font-mono">{{ $activeImport->progress }}%</span></div>
                <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-line-soft"><div class="h-full rounded-full bg-koma transition-[width]" style="width: {{ $activeImport->progress }}%"></div></div>
                @if ($activeImport->message)<p class="mt-2 text-[12px] leading-5 text-muted">{{ $activeImport->message }}</p>@endif

                @if ($activeImport->error)
                    <div class="mt-3 rounded-xl border border-koma/25 bg-koma-soft/45 px-3 py-3 text-[#6d3329]" role="alert">
                        <p class="text-[10.5px] font-extrabold uppercase tracking-wide">Why it failed</p>
                        <p class="mt-1 text-[12px] font-semibold leading-5">{{ $activeImport->error }}</p>
                        @if ($failureGuidance)
                            <p class="mt-2 text-[10.5px] font-extrabold uppercase tracking-wide">What to do next</p>
                            <p class="mt-1 text-[11.5px] leading-5">{{ $failureGuidance }}</p>
                        @endif
                        <p class="mt-2 break-all font-mono text-[10px] text-[#8a5147]">Import reference: {{ $activeImport->id }}</p>
                        @if ($activeImport->source_path)
                            <span class="mt-1 block text-[11.5px] font-normal">The original upload remains in private storage for diagnosis or retry.</span>
                        @endif
                    </div>
                @endif

                @if ($activeImport->status === 'failed' && $activeImport->source_path)
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" wire:click="retryImport('{{ $activeImport->id }}')" wire:confirm="Retry this retained archive?" class="rounded-lg bg-koma px-3 py-2 text-[12px] font-bold text-white hover:bg-koma-hover">Retry import</button>
                        <button type="button" wire:click="discardSource('{{ $activeImport->id }}')" wire:confirm="Permanently delete the retained source archive?" class="rounded-lg bg-paper px-3 py-2 text-[12px] font-semibold text-[#6d3329] hover:bg-line">Discard source</button>
                    </div>
                @endif

                @if ($activeImport->status === 'completed' && $activeImport->resultSeries)
                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <a href="{{ route('admin.series.edit', $activeImport->resultSeries) }}" class="rounded-lg bg-koma px-3 py-2 text-[12px] font-bold text-white hover:bg-koma-hover">Open imported series</a>
                        @if ($activeImport->resultSeries->is_public)
                            <a href="{{ route('series.show', $activeImport->resultSeries) }}" class="rounded-lg bg-paper px-3 py-2 text-[12px] font-semibold hover:bg-line">View public series</a>
                        @endif
                        @if ($activeImport->counts)
                            <span class="text-[11.5px] text-faint">{{ $activeImport->counts['genres'] ?? 0 }} genres · {{ $activeImport->counts['tags'] ?? 0 }} tags · {{ $activeImport->counts['chapters'] ?? 0 }} chapters · {{ $activeImport->counts['pages'] ?? 0 }} pages</span>
                        @endif
                    </div>
                @endif
            </section>
        @endif
    </div>

    <aside class="space-y-4">
        <section class="rounded-2xl border border-line bg-white p-4">
            <div class="flex items-center justify-between gap-2">
                <h2 class="font-display text-sm font-extrabold">Status</h2>
                @if ($uploadLimits['constrained_by_server'])
                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-extrabold uppercase tracking-wide text-amber-900">Server-limited</span>
                @else
                    <span class="rounded-full bg-[oklch(0.7_0.13_150_/_0.14)] px-2 py-0.5 text-[10px] font-extrabold uppercase tracking-wide text-[oklch(0.4_0.12_150)]">Ready</span>
                @endif
            </div>
            <dl class="mt-3 space-y-2 text-[11.5px] leading-4">
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-muted">Koma archive limit</dt>
                    <dd class="font-mono font-semibold">{{ $uploadLimits['application_label'] }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-muted">PHP upload_max_filesize</dt>
                    <dd class="font-mono font-semibold">{{ $uploadLimits['php_upload_label'] }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-muted">PHP post_max_size</dt>
                    <dd class="font-mono font-semibold">{{ $uploadLimits['php_post_label'] }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-muted">Effective limit</dt>
                    <dd class="font-mono font-semibold">{{ $uploadLimits['effective_label'] }}</dd>
                </div>
            </dl>
            <p class="mt-3 text-[10.5px] leading-4 text-faint">A web server or reverse proxy may impose a lower body-size limit before PHP.</p>
        </section>

        <section class="rounded-2xl border border-line bg-white p-4">
            <h2 class="font-display text-sm font-extrabold">Recent imports</h2>
            <div class="mt-3 space-y-2">
                @forelse ($recentImports as $item)
                    <button type="button" wire:click="$set('activeImportId', '{{ $item->id }}')" class="w-full rounded-lg bg-paper px-3 py-2 text-left hover:bg-line-soft">
                        <span class="block truncate text-[11.5px] font-bold">{{ $item->source_name }}</span>
                        <span class="mt-0.5 flex justify-between gap-2 text-[10px] uppercase tracking-wide text-faint"><span>{{ str_replace('_', ' ', $item->status) }}</span><span>{{ \App\Support\Koma::timeAgo($item->created_at) }}</span></span>
                    </button>
                @empty
                    <p class="py-5 text-center font-mono text-[11px] text-[#cabfb4]">no imports yet</p>
                @endforelse
            </div>
        </section>
    </aside>
</div>
