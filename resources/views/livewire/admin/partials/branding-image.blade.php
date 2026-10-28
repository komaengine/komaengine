{{-- One branding image on Settings → Branding: preview, pick a file (stored as
     soon as it is chosen), and remove. --}}
@php
    $inputId = 'branding-'.$property;
@endphp
<div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start">
    <div class="flex h-24 w-full shrink-0 items-center justify-center rounded-[10px] border border-line bg-[repeating-conic-gradient(#f1eeea_0_25%,#fff_0_50%)] bg-[length:16px_16px] p-3 sm:w-48" data-testid="{{ $property }}-preview">
        @if ($image)
            <img src="{{ $image['url'] }}" alt="" width="{{ $image['width'] }}" height="{{ $image['height'] }}" class="{{ $previewClass }} object-contain">
        @else
            <span class="text-[12px] font-semibold text-faint">None set</span>
        @endif
    </div>
    <div class="flex min-w-0 flex-col gap-2">
        <span class="text-[12px] leading-4 text-faint">{{ $help }}</span>
        @if ($image)
            <span class="text-[12px] text-muted">{{ $image['width'] }} × {{ $image['height'] }} pixels</span>
        @endif
        <div class="flex flex-wrap items-center gap-2">
            <label for="{{ $inputId }}" class="cursor-pointer rounded-[9px] border border-line bg-white px-4 py-2 text-[12.5px] font-bold hover:border-koma focus-within:border-koma">
                <span wire:loading.remove wire:target="{{ $property }}">{{ $image ? 'Replace…' : 'Upload…' }}</span>
                <span wire:loading wire:target="{{ $property }}">Uploading…</span>
                <input id="{{ $inputId }}" type="file" wire:model="{{ $property }}" accept="image/png,image/jpeg,image/webp" class="sr-only">
            </label>
            @if ($image)
                <button type="button" wire:click="{{ $remove }}" wire:confirm="{{ $confirm }}" class="rounded-[9px] border border-line bg-white px-4 py-2 text-[12.5px] font-bold text-[#9a382d] hover:bg-koma-soft">Remove</button>
            @endif
        </div>
        @error($property)<p class="text-[12px] font-semibold text-koma">{{ $message }}</p>@enderror
    </div>
</div>
