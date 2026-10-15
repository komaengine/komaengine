@props(['status'])
@php
    $map = [
        'ongoing' => ['oklch(0.42 0.12 150)', 'oklch(0.7 0.13 150 / 0.16)'],
        'completed' => ['oklch(0.4 0.14 245)', 'oklch(0.62 0.14 245 / 0.16)'],
        'hiatus' => ['oklch(0.48 0.14 60)', 'oklch(0.7 0.14 60 / 0.18)'],
    ];
    [$fg, $bg] = $map[$status] ?? ['#57524c', '#f0ebe5'];
@endphp
<span class="inline-block rounded-md px-2.5 py-1 text-[11.5px] font-bold capitalize"
      style="color: {{ $fg }}; background: {{ $bg }}">{{ $status }}</span>
