{{-- Shown to administrators when the optional update check found a newer stable release. --}}
@if ($update['update_available'])
    <div role="status" data-testid="update-notice" class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-koma/25 bg-koma-soft/50 px-5 py-4">
        <p class="text-[13.5px] font-semibold text-[#6d3329]">
            KomaEngine {{ $update['latest']['version'] }} is available. This site runs {{ $update['current'] }}.
        </p>
        <p class="flex flex-wrap gap-3 text-[13px] font-bold">
            <a href="{{ $update['latest']['release_url'] }}" target="_blank" rel="noopener noreferrer" class="text-[#6d3329] underline">Release notes</a>
            <a href="{{ $updateGuideUrl }}" target="_blank" rel="noopener noreferrer" class="text-[#6d3329] underline">How to update</a>
        </p>
    </div>
@endif
