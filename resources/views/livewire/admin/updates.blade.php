<div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
    <section class="rounded-2xl border border-line bg-white p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="mb-2 inline-flex rounded-md bg-koma-soft px-2 py-1 text-[10px] font-extrabold uppercase tracking-[0.08em] text-koma">Roadmap</div>
                <h2 class="font-display text-xl font-extrabold tracking-tight">Automatic updates will live here</h2>
                <p class="mt-2 max-w-2xl text-[13.5px] leading-6 text-muted">
                    This build does not contact an update service or change your installation automatically. The Updates screen is ready for the future signed update channel and rollback-safe updater.
                </p>
            </div>
            <span class="rounded-full border border-line bg-paper px-3 py-1.5 text-[11px] font-bold text-muted">Not configured</span>
        </div>

        <dl class="mt-6 grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-line bg-paper p-4">
                <dt class="text-[11px] font-bold uppercase tracking-wide text-faint">Installed version</dt>
                <dd class="mt-1.5 font-mono text-[13px] font-semibold text-[#3d3935]">v{{ $komaVersion }}</dd>
            </div>
            <div class="rounded-xl border border-line bg-paper p-4">
                <dt class="text-[11px] font-bold uppercase tracking-wide text-faint">Update channel</dt>
                <dd class="mt-1.5 text-[13px] font-bold text-[#3d3935]">Community</dd>
            </div>
            <div class="rounded-xl border border-line bg-paper p-4">
                <dt class="text-[11px] font-bold uppercase tracking-wide text-faint">Automatic updates</dt>
                <dd class="mt-1.5 text-[13px] font-bold text-[#3d3935]">Unavailable</dd>
            </div>
        </dl>
    </section>

    <aside class="rounded-2xl border border-line bg-white p-5">
        <h2 class="font-display text-base font-extrabold">Planned safeguards</h2>
        <ul class="mt-3 space-y-3 text-[13px] leading-5 text-muted">
            <li class="flex gap-2.5"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-koma"></span>Signed release metadata and package verification.</li>
            <li class="flex gap-2.5"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-koma"></span>Backup and rollback checks before files or migrations change.</li>
            <li class="flex gap-2.5"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-koma"></span>Separate Community and future Pro update channels.</li>
        </ul>
    </aside>
</div>
