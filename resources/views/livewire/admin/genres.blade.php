<div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-[1fr_300px]">
    <div class="rounded-2xl border border-line bg-white p-5">
        <h2 class="mb-4 font-display text-base font-extrabold">Genres <span class="font-semibold text-faint">{{ $genres->count() }}</span></h2>
        <div class="flex flex-wrap gap-2.5">
            @forelse ($genres as $genre)
                <span class="inline-flex items-center gap-2 rounded-lg border border-line bg-[#faf8f6] py-1.5 pl-3 pr-1.5 text-[13px] font-semibold" wire:key="genre-{{ $genre->id }}">
                    {{ $genre->name }}
                    <span class="rounded bg-white px-1.5 py-0.5 text-[11px] font-bold text-faint">{{ $genre->series_count }}</span>
                    <button wire:click="remove({{ $genre->id }})" wire:confirm="Remove “{{ $genre->name }}”? It will be detached from {{ $genre->series_count }} series." class="flex h-5 w-5 items-center justify-center rounded text-faint hover:bg-koma-soft hover:text-koma">✕</button>
                </span>
            @empty
                <p class="py-6 text-sm text-faint">No genres yet.</p>
            @endforelse
        </div>
    </div>

    <div class="rounded-2xl border border-line bg-white p-[18px]">
        <h2 class="mb-3.5 font-display text-[15px] font-extrabold">Add a genre</h2>
        <form wire:submit="add" class="flex flex-col gap-3">
            <input wire:model="name" placeholder="e.g. Mecha" class="h-10 w-full rounded-[9px] border border-line px-3 text-[13.5px] outline-none focus:border-koma">
            @error('name') <p class="text-[12px] font-semibold text-koma">{{ $message }}</p> @enderror
            <button type="submit" class="rounded-[10px] bg-koma py-2.5 text-[13.5px] font-bold text-white hover:bg-koma-hover">Add genre</button>
        </form>
    </div>
</div>
