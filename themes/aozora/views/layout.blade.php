@php
    $accent = \App\Support\Koma::themes()->active()['accent'] ?? null;
    $matureAccess = app(\App\Support\MatureContentAccess::class);
    $matureContentHidden = $matureAccess->hidesMature(request());
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', \App\Support\Koma::siteTitle())</title>
    <meta name="description" content="@yield('description', \App\Support\Koma::setting('tagline', 'Read manga online'))">

    {{-- Open Graph / Twitter --}}
    <meta property="og:site_name" content="{{ \App\Support\Koma::siteTitle() }}">
    <meta property="og:title" content="@yield('og_title', \App\Support\Koma::siteTitle())">
    <meta property="og:description" content="@yield('description', \App\Support\Koma::setting('tagline', 'Read manga online'))">
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:url" content="{{ url()->current() }}">
    @hasSection('og_image')<meta property="og:image" content="@yield('og_image')">@endif
    <meta name="twitter:card" content="summary_large_image">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@600;700;800&family=Hanken+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="@themeAsset('theme.css')">
    @if ($accent)<style>:root { --accent: {!! $accent !!}; }</style>@endif
    @stack('head')
</head>
<body>
    <header class="site-header">
        <div class="wrap bar">
            <a href="{{ route('home') }}" class="brand">
                <span class="glyph">コ</span>
                <span class="brand-name">{{ \App\Support\Koma::siteTitle() }}</span>
            </a>
            <nav class="nav">
                <a href="{{ route('home') }}" class="{{ request()->routeIs('home') ? 'active' : '' }}">Home</a>
                <a href="{{ route('browse') }}" class="{{ request()->routeIs('browse') ? 'active' : '' }}">Browse</a>
            </nav>
            <div class="header-actions">
                <form action="{{ route('browse') }}" method="GET" class="search">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#9aa1ac" stroke-width="2.2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                    <input type="search" name="q" placeholder="Search manga…" value="{{ request('q') }}">
                </form>
                @auth
                    @if (auth()->user()->canAccessAdmin())
                        <a href="{{ route('admin.dashboard') }}" class="btn ghost">Dashboard</a>
                    @endif
                    <form action="{{ route('logout') }}" method="POST">@csrf<button class="btn ghost" type="submit">Sign out</button></form>
                @else
                    <a href="{{ route('login') }}" class="btn ghost">Sign in</a>
                @endauth
            </div>
        </div>
    </header>

    <x-community-slot zone="header" />

    @if ($matureContentHidden && ! request()->routeIs('mature.*'))
        <aside class="mature-notice" aria-label="Mature content visibility">
            <div class="wrap mature-notice-inner">
                <span><strong>Mature titles are hidden.</strong> Confirm your age to include them in the catalog.</span>
                <a href="{{ route('mature.confirmation', ['return_to' => request()->getRequestUri()]) }}">Review access →</a>
            </div>
        </aside>
    @endif

    <main class="wrap">
        @yield('content')
    </main>

    <footer class="site-footer">
        <div class="wrap bar">
            <a href="{{ route('home') }}" class="brand" style="font-size:15px"><span class="glyph" style="width:26px;height:26px;font-size:14px;border-radius:7px">コ</span> {{ \App\Support\Koma::siteTitle() }}</a>
            <span>Powered by Koma Engine · Community Edition</span>
            <span style="margin-left:auto">Theme: {{ \App\Support\Koma::themes()->active()['name'] ?? 'Aozora' }}</span>
            @php($komaPublisher = \App\Support\Koma::publisherIdentity())
            @php($komaSupport = \App\Support\Koma::supportLink())
            @if ($komaPublisher || $komaSupport)
                <div class="footer-community" role="group" aria-label="Publisher and support" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;width:100%;margin-top:10px;font-size:12px;opacity:.72">
                    @if ($komaPublisher)
                        <span>@if ($komaPublisher['url'])<a href="{{ $komaPublisher['url'] }}">{{ $komaPublisher['name'] }}</a>@else{{ $komaPublisher['name'] }}@endif</span>
                    @endif
                    @if ($komaPublisher && $komaSupport)<span aria-hidden="true">·</span>@endif
                    @if ($komaSupport)<a href="{{ $komaSupport['url'] }}">{{ $komaSupport['label'] }}</a>@endif
                </div>
            @endif
            @php($komaLegal = \App\Support\Koma::legalLinks())
            @if ($komaLegal)<nav class="footer-legal" aria-label="Legal" style="display:flex;flex-wrap:wrap;gap:14px;justify-content:center;width:100%;margin-top:10px;font-size:12px;opacity:.72">@foreach ($komaLegal as $l)<a href="{{ $l['url'] }}">{{ $l['label'] }}</a>@endforeach</nav>@endif
    <x-community-slot zone="footer" />
        </div>
    </footer>
    @stack('scripts')
</body>
</html>
