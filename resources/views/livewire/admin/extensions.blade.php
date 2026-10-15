<div class="space-y-5">
    @if ($tab === 'themes')
        <div class="flex justify-end border-b border-line pb-3">
            <span class="font-mono text-[11px] text-faint">Koma {{ $komaVersion }} / PHP {{ PHP_VERSION }}</span>
        </div>
    @else
        @php
            $tabs = [
                'plugins' => [
                    'label' => 'Plugins',
                    'count' => count($plugins),
                    'route' => 'admin.plugins',
                ],
                'hooks' => [
                    'label' => 'Hooks',
                    'count' => count($hooks),
                    'route' => 'admin.plugins.hooks',
                ],
            ];
        @endphp

        <div class="flex flex-wrap items-center gap-3 border-b border-line">
            @foreach ($tabs as $key => $item)
                <a href="{{ route($item['route']) }}"
                    @class([
                        'flex h-11 items-center gap-2 border-b-2 px-1 text-[13.5px] font-bold',
                        'border-koma text-koma' => $tab === $key,
                        'border-transparent text-muted hover:text-ink' => $tab !== $key,
                    ])>
                    {{ $item['label'] }}
                    <span @class([
                        'rounded px-1.5 py-0.5 font-mono text-[10px]',
                        'bg-koma-soft text-koma' => $tab === $key,
                        'bg-line-soft text-faint' => $tab !== $key,
                    ])>{{ $item['count'] }}</span>
                </a>
            @endforeach
            <span class="ml-auto pb-3 font-mono text-[11px] text-faint">Koma {{ $komaVersion }} / PHP {{ PHP_VERSION }}</span>
        </div>
    @endif

    @if ($tab === 'themes')
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            @forelse ($themes as $theme)
                <article class="overflow-hidden border border-line bg-white" wire:key="theme-{{ $theme['slug'] }}">
                    <div class="aspect-[16/10] overflow-hidden border-b border-line bg-[#eef5ff]">
                        @if ($theme['preview_url'])
                            <img
                                src="{{ $theme['preview_url'] }}"
                                alt="{{ $theme['name'] }} theme preview"
                                class="h-full w-full object-cover"
                                loading="lazy"
                            >
                        @else
                            <div
                                class="relative flex h-full items-center justify-center overflow-hidden p-8 text-center"
                                role="img"
                                aria-label="Preview unavailable for {{ $theme['name'] }}"
                                style="background: linear-gradient(135deg, {{ $theme['accent'] }} 0 22%, #f7f5f2 22% 100%)"
                            >
                                <span class="absolute right-6 top-6 h-2 w-24 bg-white/80"></span>
                                <span class="absolute right-6 top-11 h-2 w-16 bg-white/60"></span>
                                <span class="border border-white/70 bg-white/90 px-4 py-2 font-mono text-[10px] font-bold uppercase tracking-[0.16em] text-muted shadow-sm">
                                    Preview unavailable
                                </span>
                            </div>
                        @endif
                    </div>

                    <div class="p-5">
                        <div class="flex items-start gap-4">
                            <span class="h-12 w-12 shrink-0 border border-black/10" style="background: {{ $theme['accent'] }}"></span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="font-display text-[16px] font-extrabold">{{ $theme['name'] }}</h2>
                                    @if ($theme['active'])
                                        <span class="rounded bg-[oklch(0.7_0.13_150_/_0.15)] px-2 py-1 text-[10px] font-extrabold uppercase text-[oklch(0.4_0.12_150)]">Active</span>
                                    @elseif (! $theme['compatible'])
                                        <span class="rounded bg-koma-soft px-2 py-1 text-[10px] font-extrabold uppercase text-koma">Incompatible</span>
                                    @endif
                                </div>
                                <p class="mt-0.5 text-[12px] text-faint">Version {{ $theme['version'] }} by {{ $theme['author'] ?: 'Unknown author' }}</p>
                            </div>
                        </div>

                        <p class="mt-4 min-h-10 text-[13.5px] leading-5 text-[#57524c]">{{ $theme['description'] ?: 'No description provided.' }}</p>

                        <div class="mt-4 flex flex-wrap gap-2 font-mono text-[10.5px] text-muted">
                            @if ($theme['requires']['koma'])
                                <span class="border border-line bg-[#faf8f6] px-2 py-1">Koma &gt;= {{ $theme['requires']['koma'] }}</span>
                            @endif
                            @if ($theme['requires']['php'])
                                <span class="border border-line bg-[#faf8f6] px-2 py-1">PHP &gt;= {{ $theme['requires']['php'] }}</span>
                            @endif
                        </div>

                        @foreach ($theme['issues'] as $issue)
                            <p class="mt-3 text-[12px] font-semibold text-koma">{{ $issue }}</p>
                        @endforeach

                        <div class="mt-5 flex justify-end">
                            <button type="button"
                                wire:click="activateTheme('{{ $theme['slug'] }}')"
                                wire:loading.attr="disabled"
                                @disabled($theme['active'] || ! $theme['compatible'])
                                class="h-9 border border-line px-4 text-[12.5px] font-bold hover:border-koma hover:text-koma disabled:cursor-not-allowed disabled:bg-line-soft disabled:text-faint">
                                {{ $theme['active'] ? 'Active theme' : 'Activate' }}
                            </button>
                        </div>
                    </div>
                </article>
            @empty
                <div class="border border-dashed border-line bg-white px-6 py-12 text-center text-sm text-faint lg:col-span-2">
                    No valid theme manifests were found.
                </div>
            @endforelse
        </div>
    @elseif ($tab === 'plugins')
        <div class="overflow-hidden border border-line bg-white">
            <div class="hidden grid-cols-[minmax(0,1fr)_130px_130px] gap-4 border-b border-line bg-[#faf8f6] px-5 py-3 text-[10.5px] font-extrabold uppercase text-muted md:grid">
                <span>Plugin</span>
                <span>Compatibility</span>
                <span class="text-right">Status</span>
            </div>

            @forelse ($plugins as $plugin)
                <div class="grid grid-cols-1 gap-4 border-b border-line px-5 py-4 last:border-b-0 md:grid-cols-[minmax(0,1fr)_130px_130px]" wire:key="plugin-{{ $plugin['slug'] }}">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="font-display text-[15px] font-extrabold">{{ $plugin['name'] }}</h2>
                            <span class="font-mono text-[10.5px] text-faint">v{{ $plugin['version'] }}</span>
                        </div>
                        <p class="mt-1 text-[13px] leading-5 text-[#57524c]">{{ $plugin['description'] ?: 'No description provided.' }}</p>
                        <p class="mt-1.5 text-[11.5px] text-faint">By {{ $plugin['author'] ?: 'Unknown author' }} / {{ $plugin['slug'] }}</p>

                        @if ($plugin['failure'])
                            <div class="mt-3 border-l-2 border-koma bg-koma-soft px-3 py-2.5 text-[12px] text-[#6d3329]">
                                <div class="font-bold">Disabled after {{ $plugin['failure']['stage'] }} failure</div>
                                <div class="mt-0.5">{{ $plugin['failure']['message'] }}</div>
                                <button type="button" wire:click="clearPluginFailure('{{ $plugin['slug'] }}')" class="mt-2 font-bold text-koma hover:underline">Clear notice</button>
                            </div>
                        @endif
                    </div>

                    <div class="text-[12px]">
                        @if ($plugin['compatible'])
                            <span class="font-bold text-[oklch(0.4_0.12_150)]">Compatible</span>
                        @else
                            <span class="font-bold text-koma">Blocked</span>
                            @foreach ($plugin['issues'] as $issue)
                                <p class="mt-1 text-[11px] leading-4 text-muted">{{ $issue }}</p>
                            @endforeach
                        @endif
                        <div class="mt-2 font-mono text-[10px] leading-4 text-faint">
                            @if ($plugin['requires']['koma']) Koma &gt;= {{ $plugin['requires']['koma'] }}<br> @endif
                            @if ($plugin['requires']['php']) PHP &gt;= {{ $plugin['requires']['php'] }} @endif
                        </div>
                    </div>

                    <div class="flex items-start justify-end">
                        <button type="button"
                            wire:click="togglePlugin('{{ $plugin['slug'] }}')"
                            wire:loading.attr="disabled"
                            @disabled(! $plugin['compatible'] && ! $plugin['enabled'])
                            @class([
                                'h-9 min-w-[104px] px-4 text-[12.5px] font-bold disabled:cursor-not-allowed disabled:bg-line-soft disabled:text-faint',
                                'border border-line bg-white hover:border-koma hover:text-koma' => $plugin['enabled'],
                                'bg-koma text-white hover:bg-koma-hover' => ! $plugin['enabled'] && $plugin['compatible'],
                            ])>
                            {{ $plugin['enabled'] ? 'Disable' : 'Enable' }}
                        </button>
                    </div>
                </div>
            @empty
                <div class="px-6 py-12 text-center text-sm text-faint">No valid plugin manifests were found.</div>
            @endforelse
        </div>
    @else
        <div class="overflow-hidden border border-line bg-white">
            <div class="hidden grid-cols-[190px_90px_240px_minmax(0,1fr)] gap-4 border-b border-line bg-[#faf8f6] px-5 py-3 text-[10.5px] font-extrabold uppercase text-muted md:grid">
                <span>Hook</span>
                <span>Type</span>
                <span>Arguments</span>
                <span>Trigger</span>
            </div>
            @foreach ($hooks as $hook)
                <div class="grid grid-cols-1 gap-2 border-b border-line px-5 py-4 text-[12.5px] last:border-b-0 md:grid-cols-[190px_90px_240px_minmax(0,1fr)] md:gap-4">
                    <code class="font-mono font-semibold text-ink">{{ $hook['name'] }}</code>
                    <span>
                        <span @class([
                            'rounded px-2 py-1 text-[10px] font-extrabold uppercase',
                            'bg-[oklch(0.56_0.15_245_/_0.12)] text-[oklch(0.42_0.14_245)]' => $hook['type'] === 'action',
                            'bg-[oklch(0.68_0.13_150_/_0.14)] text-[oklch(0.4_0.12_150)]' => $hook['type'] === 'filter',
                        ])>{{ $hook['type'] }}</span>
                    </span>
                    <code class="font-mono text-[11px] text-muted">{{ $hook['arguments'] }}</code>
                    <span class="text-[#57524c]">{{ $hook['trigger'] }}</span>
                </div>
            @endforeach
        </div>
    @endif
</div>
