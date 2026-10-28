@php
    $when = fn (?\Carbon\CarbonInterface $at) => $at
        ? $at->diffForHumans().' ('.$at->utc()->format('Y-m-d H:i').' UTC)'
        : null;
@endphp
<div>
    @include('livewire.admin.partials.update-notice')

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
        <section class="rounded-2xl border border-line bg-white p-5 sm:p-6" data-testid="update-checks">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <h2 class="font-display text-xl font-extrabold tracking-tight">Update notice</h2>
                <span class="rounded-full border border-line bg-paper px-3 py-1.5 text-[11px] font-bold text-muted">
                    {{ ! $update['allowed'] ? 'Turned off on this server' : ($update['enabled'] ? 'On' : 'Off') }}
                </span>
            </div>

            <p class="mt-2 max-w-2xl text-[13.5px] leading-6 text-muted">
                When update checks are on, KomaEngine fetches one public file,
                <code class="break-all font-mono text-[12.5px]">{{ $feedUrl }}</code>, once a day and compares
                its version with this site's here. The request sends nothing about your site: no address, version,
                or counts. Like any web server, komaengine.com logs the request's IP address, and deletes its logs
                after 14 days. With checks off, KomaEngine makes no outbound requests of its own.
            </p>

            @if (! $update['allowed'])
                <p class="mt-4 text-[13px] leading-5 text-muted">
                    The server's configuration turns update checks off (<code class="font-mono">KOMA_UPDATE_CHECK=false</code>).
                </p>
            @elseif ($update['enabled'])
                <div class="mt-4 text-[13px] leading-6" data-testid="update-check-status">
                    @if ($update['checked_at'])
                        <p>Last checked {{ $when($update['checked_at']) }}.
                            {{ $update['update_available'] ? 'A newer version is available.' : 'This site is up to date.' }}</p>
                    @elseif (! $update['attempted_at'])
                        <p>The first check runs within the hour.</p>
                    @endif
                    @if ($update['failed'])
                        <p class="text-muted">The last attempt, {{ $update['attempted_at']->diffForHumans() }}, got no usable answer. It tries again within a day.</p>
                    @endif
                </div>
                <button type="button" wire:click="disableChecks" class="mt-4 rounded-[9px] border border-line bg-white px-4 py-2 text-[12.5px] font-bold hover:border-koma">Turn off update checks</button>
            @else
                <button type="button" wire:click="enableChecks" class="mt-4 rounded-[11px] bg-koma px-5 py-2.5 text-[13px] font-bold text-white hover:bg-koma-hover">Enable update checks</button>
            @endif

            <dl class="mt-6 grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl border border-line bg-paper p-4">
                    <dt class="text-[11px] font-bold uppercase tracking-wide text-faint">Installed version</dt>
                    <dd class="mt-1.5 font-mono text-[13px] font-semibold text-[#3d3935]">v{{ $update['current'] }}</dd>
                </div>
                <div class="rounded-xl border border-line bg-paper p-4">
                    <dt class="text-[11px] font-bold uppercase tracking-wide text-faint">Latest version</dt>
                    <dd class="mt-1.5 font-mono text-[13px] font-semibold text-[#3d3935]">{{ $update['latest'] ? 'v'.$update['latest']['version'] : 'Not checked' }}</dd>
                </div>
            </dl>
        </section>

        <aside class="rounded-2xl border border-line bg-white p-5">
            <h2 class="font-display text-base font-extrabold">Updating</h2>
            <p class="mt-3 text-[13px] leading-5 text-muted">
                KomaEngine never updates itself. The notice links to the release notes; you update when you are ready,
                after a verified backup.
            </p>
            <a href="{{ $updateGuideUrl }}" target="_blank" rel="noopener noreferrer" class="mt-3 inline-block text-[13px] font-bold text-koma underline">How to update</a>
        </aside>
    </div>
</div>
