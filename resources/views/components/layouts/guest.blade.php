<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@500;600;700;800&family=Hanken+Grotesk:wght@400;500;600;700&family=Spline+Sans+Mono:wght@500&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-paper text-[#1c1a18]">
    <div class="flex min-h-full flex-col items-center justify-center px-4 py-12">
        <a href="{{ url('/') }}" class="mb-7 flex flex-col items-center gap-3">
            <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-koma text-2xl font-extrabold text-white shadow-lg shadow-koma/30" style="font-family:var(--font-display)">コ</span>
            <span class="text-center">
                <span class="block font-display text-xl font-extrabold tracking-tight">{{ \App\Support\Koma::setting('site_title', config('app.name')) }}</span>
                <span class="mt-0.5 block text-[12.5px] text-faint">Community Edition</span>
            </span>
        </a>

        <div class="w-full max-w-md rounded-2xl border border-line bg-white p-7 shadow-sm">
            {{ $slot }}
        </div>

        <p class="mt-6 text-center text-xs text-faint">
            Koma Engine &middot; open-source manga CMS
        </p>
    </div>
    @livewireScripts
</body>
</html>
