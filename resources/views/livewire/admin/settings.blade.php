@php
    $inputClass = 'h-[42px] w-full rounded-[10px] border border-line px-3 text-[13.5px] outline-none focus:border-koma';
@endphp
<div class="max-w-[760px]">
    @if (session('status'))
        <div class="mb-4 rounded-[10px] border border-koma/25 bg-koma-soft/50 px-4 py-3 text-[13px] font-semibold text-[#6d3329]" role="status">
            {{ session('status') }}
        </div>
    @endif

    {{-- `dep` mirrors the fields other fields watch, so a `depends` rule can
         show or hide its field without a server round-trip. --}}
    <form wire:submit="save" class="flex flex-col gap-4"
        x-data="{ dep: @js(collect($dependencyKeys)->mapWithKeys(fn ($k) => [$k => (string) ($values[$k] ?? '')])->all()) }">
        @foreach ($sections as $section => $fields)
            <section class="rounded-2xl border border-line bg-white p-[22px]">
                <h2 class="mb-4 font-display text-base font-extrabold">{{ $section }}</h2>
                <div class="flex flex-col gap-4">
                    @foreach ($fields as $key => $field)
                        @php
                            $model = 'values.'.$key;
                            $fieldId = 'setting-'.str_replace('_', '-', $key);
                            $depends = $field['depends'] ?? null;
                            $dependsOn = $depends ? array_key_first($depends) : null;
                            $dependsValues = $depends ? (array) reset($depends) : [];
                            $watched = in_array($key, $dependencyKeys, true);
                        @endphp
                        <div
                            @if ($depends) x-show="@js(array_map('strval', $dependsValues)).includes(dep['{{ $dependsOn }}'])" x-cloak @endif
                            @class(['flex flex-col gap-1.5', 'toggle-row' => $field['type'] === 'toggle'])>

                            @if ($field['type'] === 'toggle')
                                <label class="flex cursor-pointer items-center justify-between gap-4 rounded-[10px] border border-line bg-[#faf8f6] px-4 py-3">
                                    <span>
                                        <span class="block text-[13.5px] font-bold">{{ $field['label'] }}</span>
                                        @isset($field['help'])<span class="mt-0.5 block text-[12px] leading-4 text-faint">{{ $field['help'] }}</span>@endisset
                                    </span>
                                    <input type="checkbox" wire:model="{{ $model }}" class="h-5 w-5 shrink-0 rounded accent-koma">
                                </label>
                            @else
                                <label for="{{ $fieldId }}" class="text-[12px] font-bold uppercase tracking-wide text-muted">{{ $field['label'] }}</label>
                                @isset($field['help'])<span class="-mt-1 text-[12px] leading-4 text-faint">{{ $field['help'] }}</span>@endisset

                                @if ($field['type'] === 'select')
                                    <select id="{{ $fieldId }}" wire:model="{{ $model }}"
                                        @if ($watched) x-on:change="dep['{{ $key }}'] = $event.target.value" @endif
                                        class="{{ $inputClass }} font-semibold">
                                        @foreach ($this->optionsFor($field) as $value => $label)
                                            @if (is_array($label))
                                                <optgroup label="{{ $value }}">
                                                    @foreach ($label as $optValue => $optLabel)
                                                        <option value="{{ $optValue }}">{{ $optLabel }}</option>
                                                    @endforeach
                                                </optgroup>
                                            @else
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                @elseif ($field['type'] === 'number')
                                    <div class="flex items-center gap-2">
                                        <input id="{{ $fieldId }}" type="number" wire:model="{{ $model }}" class="{{ $inputClass }} max-w-[160px]">
                                        @isset($field['suffix'])<span class="text-[12px] text-faint">{{ $field['suffix'] }}</span>@endisset
                                    </div>
                                @elseif ($field['type'] === 'slug')
                                    <div class="flex items-center gap-1.5 font-mono text-[13px]">
                                        <span class="text-faint">{{ rtrim(url('/'), '/') }}/</span>
                                        <input id="{{ $fieldId }}" type="text" wire:model="{{ $model }}" class="{{ $inputClass }} max-w-[220px]">
                                    </div>
                                @elseif ($field['type'] === 'password')
                                    {{-- Clear the mask on focus so typing replaces the stored
                                         credential instead of appending to a row of dots. --}}
                                    <input id="{{ $fieldId }}" type="password" wire:model="{{ $model }}" autocomplete="new-password"
                                        x-on:focus="if ($event.target.value === @js(\App\Livewire\Admin\Settings::SECRET_PLACEHOLDER)) { $event.target.value = ''; $event.target.dispatchEvent(new Event('input')) }"
                                        class="{{ $inputClass }}">
                                @else
                                    <input id="{{ $fieldId }}" type="{{ $field['type'] === 'email' ? 'email' : ($field['type'] === 'url' ? 'url' : 'text') }}"
                                        wire:model="{{ $model }}"
                                        @if ($watched) x-on:change="dep['{{ $key }}'] = $event.target.value" @endif
                                        class="{{ $inputClass }}">
                                @endif
                            @endif

                            @error($model)<p class="text-[12px] font-semibold text-koma">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </div>

                {{-- Mail: prove delivery works before switching notices on --}}
                @if ($tab === 'mail' && $section === 'Delivery')
                    <div class="mt-4 flex flex-wrap items-center gap-3 rounded-[10px] border border-line bg-[#faf8f6] p-4 text-[12.5px]">
                        <button type="button" wire:click="sendTestEmail" wire:loading.attr="disabled"
                            class="rounded-[9px] border border-line bg-white px-4 py-2 font-bold hover:border-koma disabled:opacity-60">
                            <span wire:loading.remove wire:target="sendTestEmail">Send a test message</span>
                            <span wire:loading wire:target="sendTestEmail">Sending…</span>
                        </button>
                        <span class="leading-4 text-faint">
                            Goes to the administration email set under General. Save this tab first — the test uses the saved values.
                        </span>
                    </div>
                @endif

                {{-- Permalinks: live preview + redirect note --}}
                @if ($tab === 'permalinks' && $section === 'URL structure')
                    <div class="mt-4 rounded-[10px] border border-line bg-[#faf8f6] p-4 text-[12.5px]">
                        <p class="font-bold text-muted">Preview</p>
                        <p class="mt-1 font-mono text-faint">{{ rtrim(url('/'), '/') }}/{{ $values['permalink_series_base'] ?? 'series' }}/your-series</p>
                        <p class="font-mono text-faint">{{ rtrim(url('/'), '/') }}/{{ $values['permalink_series_base'] ?? 'series' }}/your-series/{{ $values['permalink_chapter_base'] ?? 'chapter' }}/1</p>
                        <p class="mt-2 leading-4 text-faint">Old URLs redirect to the new structure automatically, so existing links keep working.</p>
                    </div>
                @endif
            </section>
        @endforeach

        {{-- Media: storage panel --}}
        @if ($storage)
            <section class="rounded-2xl border border-line bg-white p-[22px]">
                <h2 class="mb-4 font-display text-base font-extrabold">Storage</h2>
                <dl class="flex flex-col gap-2.5 text-[13px]">
                    <div class="flex justify-between gap-3"><dt class="text-muted">Media disk</dt><dd class="font-mono font-semibold">{{ $storage['media_disk'] }} (local)</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted">Media stored</dt><dd class="font-mono font-semibold">{{ \Illuminate\Support\Number::fileSize($storage['used_bytes']) }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted">PHP upload_max_filesize</dt><dd class="font-mono font-semibold">{{ $storage['php_upload'] }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted">PHP post_max_size</dt><dd class="font-mono font-semibold">{{ $storage['php_post'] }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted">PHP memory_limit</dt><dd class="font-mono font-semibold">{{ $storage['php_memory'] }}</dd></div>
                </dl>
                <div class="mt-4 rounded-[10px] border border-dashed border-line bg-[#faf8f6] p-4 text-[12.5px] leading-5 text-faint">
                    <span class="font-bold text-muted">Remote storage (S3 / R2 / CDN)</span> is a Pro feature. Community Edition serves media from the local disk.
                </div>
            </section>
        @endif

        <div>
            <button type="submit" class="rounded-[11px] bg-koma px-6 py-3 text-sm font-bold text-white hover:bg-koma-hover">
                Save {{ strtolower($tabs[$tab]) }} settings
            </button>
        </div>
    </form>
</div>
