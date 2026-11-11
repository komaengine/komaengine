@php
    $troubleshooting = $docsUrl.'/install/troubleshooting.md';
    $okPill = 'rounded-full bg-[oklch(0.7_0.13_150_/_0.12)] px-2.5 py-1 text-[11px] font-bold text-[oklch(0.38_0.1_150)]';
    $badPill = 'rounded-full bg-[#fbeae6] px-2.5 py-1 text-[11px] font-bold text-[#8a2f25]';
    $section = 'rounded-2xl border border-line bg-white p-5 sm:p-[22px]';
    $when = fn (?\Carbon\CarbonInterface $at) => $at
        ? $at->diffForHumans().' ('.$at->utc()->format('Y-m-d H:i').' UTC)'
        : null;
    $queue = $background['queue'];
@endphp
<div class="flex max-w-[960px] flex-col gap-4">
    <section class="{{ $section }}" data-testid="health-summary">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-display text-xl font-extrabold tracking-tight">
                    @if ($problems === 0)
                        Everything looks healthy
                    @else
                        {{ $problems }} {{ $problems === 1 ? 'item needs' : 'items need' }} attention
                    @endif
                </h2>
                <p class="mt-1.5 max-w-2xl text-[13.5px] leading-6 text-muted">
                    The checks run each time this page loads. Nothing on this page changes your site.
                </p>
            </div>
            <button type="button" wire:click="$refresh" wire:loading.attr="disabled"
                class="rounded-[9px] border border-line bg-white px-4 py-2 text-[12.5px] font-bold hover:border-koma disabled:opacity-60">
                <span wire:loading.remove wire:target="$refresh">Check again</span>
                <span wire:loading wire:target="$refresh">Checking…</span>
            </button>
        </div>
    </section>

    <section class="{{ $section }}">
        <h2 class="font-display text-base font-extrabold">Readiness</h2>
        <p class="mt-1 text-[12.5px] leading-5 text-faint">The same checks as <code class="font-mono">php artisan koma:doctor --mode=operational</code>.</p>
        <ul class="mt-4 divide-y divide-line">
            @foreach ($checks as $check)
                <li class="py-3" data-testid="health-check-{{ $check['name'] }}">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-[13.5px] font-bold">{{ $check['label'] }}</span>
                        <span class="{{ $check['ok'] ? $okPill : $badPill }}">{{ $check['ok'] ? 'OK' : 'Needs attention' }}</span>
                    </div>
                    @unless ($check['ok'])
                        <p class="mt-1.5 text-[12.5px] leading-5 text-muted">
                            {{ $check['fix'] }}
                            @if ($check['anchor'] !== '')
                                <a href="{{ $troubleshooting }}#{{ $check['anchor'] }}" target="_blank" rel="noopener noreferrer" class="font-bold text-[#8a2f25] underline">Troubleshooting</a>
                            @endif
                        </p>
                    @endunless
                </li>
            @endforeach
        </ul>
    </section>

    <section class="{{ $section }}">
        <h2 class="font-display text-base font-extrabold">Background work</h2>
        <div class="mt-4 divide-y divide-line text-[13px]">
            <div class="py-3" data-testid="health-scheduler">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="font-bold">Scheduler last ran</h3>
                    <p class="flex items-center gap-2">
                        <span class="text-muted">{{ $when($background['scheduler_at']) ?? 'Not seen yet' }}</span>
                        <span class="{{ $background['scheduler_stale'] ? $badPill : $okPill }}">{{ $background['scheduler_stale'] ? 'Needs attention' : 'OK' }}</span>
                    </p>
                </div>
                @if ($background['scheduler_stale'])
                    <p class="mt-1.5 text-[12.5px] leading-5 text-muted">
                        {{ $background['worker'] === 'scheduler'
                            ? 'The scheduler should run every minute; it publishes scheduled chapters and starts the queue worker.'
                            : 'The scheduler should run every minute; it publishes scheduled chapters.' }}
                        @if ($background['worker'] === 'docker')
                            Check that the Docker <code class="font-mono">scheduler</code> service is running.
                        @else
                            Check that the cron job below exists and uses the full path to PHP.
                        @endif
                        <a href="{{ $troubleshooting }}#scheduled-chapters-do-not-publish" target="_blank" rel="noopener noreferrer" class="font-bold text-[#8a2f25] underline">Troubleshooting</a>
                    </p>
                @endif
            </div>
            <div class="py-3" data-testid="health-queue">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="font-bold">Queue worker last finished a job</h3>
                    <p class="text-muted">
                        @if ($background['last_job'])
                            {{ $when($background['last_job']['at']) }} on <span class="font-mono">{{ $background['last_job']['queue'] ?? 'unknown' }}</span>
                        @else
                            No job finished yet
                        @endif
                    </p>
                </div>
                @if ($queue)
                    <dl class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <div class="rounded-xl border border-line bg-paper p-3"><dt class="text-[11px] font-bold uppercase tracking-wide text-faint">Waiting</dt><dd class="mt-1 font-mono font-semibold">{{ $queue['waiting'] }}</dd></div>
                        <div class="rounded-xl border border-line bg-paper p-3"><dt class="text-[11px] font-bold uppercase tracking-wide text-faint">Running</dt><dd class="mt-1 font-mono font-semibold">{{ $queue['running'] }}</dd></div>
                        <div class="rounded-xl border border-line bg-paper p-3"><dt class="text-[11px] font-bold uppercase tracking-wide text-faint">Oldest waiting</dt><dd class="mt-1 font-mono font-semibold">{{ $queue['oldest_waiting']?->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) ?? '—' }}</dd></div>
                        <div class="rounded-xl border border-line bg-paper p-3"><dt class="text-[11px] font-bold uppercase tracking-wide text-faint">Failed</dt><dd class="mt-1 font-mono font-semibold">{{ $queue['failed'] }}</dd></div>
                    </dl>
                @else
                    <p class="mt-1.5 text-[12.5px] leading-5 text-faint">Job counts are shown for the database queue only.</p>
                @endif
                @if ($background['queue_stale'])
                    <p class="mt-2 flex flex-wrap items-center gap-2 text-[12.5px] leading-5 text-muted" data-testid="health-queue-warning">
                        <span class="{{ $badPill }}">Needs attention</span>
                        A job has waited more than {{ $staleMinutes }} minutes and none is running, so the queue worker is not starting.
                        <a href="{{ $troubleshooting }}#archive-jobs-stay-queued-or-fail" target="_blank" rel="noopener noreferrer" class="font-bold text-[#8a2f25] underline">Troubleshooting</a>
                    </p>
                @endif
                @if ($queue && $queue['failed'] > 0)
                    <p class="mt-2 text-[12.5px] leading-5 text-muted">Run <code class="font-mono">php artisan queue:failed</code> to see why. A failed archive import can be retried from Import Archive.</p>
                @endif
            </div>
            <div class="py-3">
                <h3 class="font-bold">How the queue runs</h3>
                <p class="mt-1 text-[12.5px] leading-5 text-muted" data-testid="health-worker-mode">
                    @switch ($background['worker'])
                        @case('docker')
                            Docker Compose runs the scheduler and the queue worker as their own services.
                            @break
                        @case('scheduler')
                            The scheduler starts the queue worker (<code class="font-mono">KOMA_QUEUE_VIA_SCHEDULER=true</code>), so the one cron job below runs both.
                            @break
                        @default
                            A separate queue worker must run beside the scheduler, such as the Ubuntu guide's systemd service or a second cron job.
                    @endswitch
                </p>
            </div>
        </div>

        @if ($background['worker'] !== 'docker')
            <div class="mt-2 rounded-[10px] border border-line bg-[#faf8f6] p-4"
                x-data="{
                    copied: false,
                    copy() {
                        const text = this.$refs.cron.textContent.trim();
                        const select = () => {
                            const range = document.createRange();
                            range.selectNodeContents(this.$refs.cron);
                            window.getSelection().removeAllRanges();
                            window.getSelection().addRange(range);
                        };
                        if (! navigator.clipboard) { select(); return; }
                        navigator.clipboard.writeText(text)
                            .then(() => { this.copied = true; setTimeout(() => this.copied = false, 2000); })
                            .catch(select);
                    },
                }">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-[12px] font-bold uppercase tracking-wide text-muted">Cron job for this site</h3>
                    <button type="button" x-on:click="copy()" class="rounded-[9px] border border-line bg-white px-3 py-1.5 text-[12px] font-bold hover:border-koma">
                        <span x-show="! copied">Copy</span>
                        <span x-show="copied" x-cloak>Copied</span>
                    </button>
                </div>
                <code x-ref="cron" class="mt-2 block break-all font-mono text-[12.5px] leading-5 text-[#3d3935]" data-testid="health-cron">{{ $background['cron']['line'] }}</code>
                <p class="mt-2 text-[12px] leading-4 text-faint">
                    Add it in your hosting panel to run every minute (<code class="font-mono">* * * * *</code>).
                    @unless ($background['cron']['php_known'])
                        Replace <code class="font-mono">php</code> with the full path to the PHP your panel guide names.
                    @endunless
                </p>
            </div>
        @endif
    </section>

    <section class="{{ $section }}">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-display text-base font-extrabold">PHP</h2>
            <span class="font-mono text-[12.5px] text-muted">PHP {{ $phpVersion }}</span>
        </div>
        <p class="mt-1 text-[12.5px] leading-5 text-faint">The values this website runs with. Change them in your hosting panel's PHP settings.</p>
        {{-- Focusable, so keyboard users can scroll the table on a narrow screen. --}}
        <div class="mt-3 overflow-x-auto rounded-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-koma" tabindex="0" role="region" aria-label="PHP limits">
            <table class="w-full text-left text-[13px]">
                <thead>
                    <tr class="border-b border-line text-[11px] uppercase tracking-wide text-faint">
                        <th scope="col" class="py-2 pr-3 font-bold">Setting</th>
                        <th scope="col" class="py-2 pr-3 font-bold">This site</th>
                        <th scope="col" class="py-2 pr-3 font-bold">Recommended</th>
                        <th scope="col" class="py-2 font-bold"><span class="sr-only">Status</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($php as $limit)
                        <tr data-testid="health-php-{{ $limit['name'] }}">
                            <th scope="row" class="py-2.5 pr-3 font-mono font-semibold">{{ $limit['name'] }}</th>
                            <td class="py-2.5 pr-3 font-mono">{{ $limit['current'] }}</td>
                            <td class="py-2.5 pr-3 font-mono text-muted">{{ $limit['recommended'] }} or more</td>
                            <td class="py-2.5 text-right"><span class="{{ $limit['ok'] ? $okPill : $badPill }}">{{ $limit['ok'] ? 'OK' : 'Too low' }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <div class="grid gap-4 md:grid-cols-2">
        <section class="{{ $section }}" data-testid="health-disk">
            <h2 class="font-display text-base font-extrabold">Disk space</h2>
            @if ($disk)
                <p class="mt-3 flex flex-wrap items-center gap-2 text-[13px]">
                    <span><span class="font-mono font-semibold">{{ \Illuminate\Support\Number::fileSize($disk['free'], 1) }}</span> free of {{ \Illuminate\Support\Number::fileSize($disk['total'], 1) }} for <code class="font-mono">storage/</code></span>
                    <span class="{{ $disk['low'] ? $badPill : $okPill }}">{{ $disk['low'] ? 'Low' : 'OK' }}</span>
                </p>
                @if ($disk['low'])
                    <p class="mt-1.5 text-[12.5px] leading-5 text-muted">Uploads and archive imports need room to unpack. Free some space or ask your host for more.</p>
                @endif
            @else
                <p class="mt-3 text-[13px] text-muted">This host does not report disk space to PHP.</p>
            @endif
        </section>

        <section class="{{ $section }}" data-testid="health-release">
            <h2 class="font-display text-base font-extrabold">Release</h2>
            <dl class="mt-3 grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-[13px]">
                <dt class="text-muted">Version</dt><dd class="font-mono font-semibold">{{ $release['version'] }}</dd>
                <dt class="text-muted">Commit</dt><dd class="break-all font-mono">{{ $release['commit'] }}</dd>
                <dt class="text-muted">Build</dt><dd class="break-all font-mono">{{ $release['build_id'] }}</dd>
                <dt class="text-muted">Official build</dt><dd data-testid="health-official">{{ $release['official'] ? 'Yes' : 'No' }}</dd>
                <dd class="col-start-2 text-[12.5px] leading-5 text-muted">
                    @if ($release['reason'])
                        <span class="block" data-testid="health-official-reason">{{ $release['reason'] }}</span>
                    @endif
                    This page cannot check signatures. <a href="{{ $docsUrl }}/SUPPLY_CHAIN.md#verify-a-release" target="_blank" rel="noopener noreferrer" class="font-bold text-[#8a2f25] underline">Verify a release</a> tells you how.
                </dd>
                <dt class="text-muted">Update checks</dt>
                <dd data-testid="health-update">
                    @if (! $update['allowed'])
                        Turned off on this server
                    @elseif (! $update['enabled'])
                        Off · <a href="{{ route('admin.updates') }}" class="font-bold text-[#8a2f25] underline">Updates</a>
                    @elseif ($update['update_available'])
                        <a href="{{ $update['latest']['release_url'] }}" target="_blank" rel="noopener noreferrer" class="font-bold text-[#8a2f25] underline">{{ $update['latest']['version'] }} is available</a>
                    @elseif ($update['checked_at'])
                        Up to date, checked {{ $update['checked_at']->diffForHumans() }}
                    @else
                        On, not checked yet
                    @endif
                </dd>
            </dl>
        </section>
    </div>
</div>
