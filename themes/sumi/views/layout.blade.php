@php
    $activeTheme = \App\Support\Koma::themes()->active();
    $accent = $activeTheme['accent'] ?? null;
    $matureAccess = app(\App\Support\MatureContentAccess::class);
    $matureContentHidden = $matureAccess->hidesMature(request());
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', \App\Support\Koma::siteTitle())</title>
    <meta name="description" content="@yield('description', \App\Support\Koma::setting('tagline', 'Read manga online'))">
    <meta property="og:site_name" content="{{ \App\Support\Koma::siteTitle() }}">
    <meta property="og:title" content="@yield('og_title', \App\Support\Koma::siteTitle())">
    <meta property="og:description" content="@yield('description', \App\Support\Koma::setting('tagline', 'Read manga online'))">
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:url" content="{{ url()->current() }}">
    @hasSection('og_image')<meta property="og:image" content="@yield('og_image')">@endif
    <meta name="twitter:card" content="summary_large_image">
    <link rel="stylesheet" href="@themeAsset('theme.css')">
    @if ($accent)<style>:root { --accent: {!! $accent !!}; }</style>@endif
    @stack('head')
</head>
<body>
    <a class="skip-link" href="#content">Skip to content</a>
    <aside class="site-rail" aria-label="Site navigation">
        <a class="rail-brand" href="{{ route('home') }}" aria-label="{{ \App\Support\Koma::siteTitle() }} home"><span class="rail-mark">墨</span><span>{{ \App\Support\Koma::siteTitle() }}</span></a>
        <p class="rail-label">Discover</p>
        <nav class="rail-nav" aria-label="Primary navigation">
            <a href="{{ route('home') }}" @class(['active' => request()->routeIs('home')])><span aria-hidden="true">⌂</span>Home</a>
            <a href="{{ route('browse') }}" @class(['active' => request()->routeIs('browse', 'series.*')])><span aria-hidden="true">▦</span>Titles</a>
            <a href="{{ route('home').'#latest' }}"><span aria-hidden="true">↳</span>Latest</a>
        </nav>
        <div class="rail-note"><span>Sumi</span><p>Quiet surfaces for long reading sessions.</p></div>
    </aside>

    <div class="app-shell">
        <header class="topbar">
            <details class="mobile-menu">
                <summary aria-label="Open navigation"><span></span><span></span><span></span></summary>
                <nav aria-label="Mobile navigation">
                    <a href="{{ route('home') }}">Home</a><a href="{{ route('browse') }}">Titles</a><a href="{{ route('home').'#latest' }}">Latest</a>
                    @auth
                        @if (auth()->user()->canAccessAdmin())<a href="{{ route('admin.dashboard') }}">Dashboard</a>@endif
                        <form action="{{ route('logout') }}" method="POST">@csrf<button type="submit">Sign out</button></form>
                    @else<a href="{{ route('login') }}">Sign in</a>@endauth
                </nav>
            </details>
            <a class="mobile-brand" href="{{ route('home') }}"><span>墨</span>{{ \App\Support\Koma::siteTitle() }}</a>
            <span class="topbar-path">Catalog / {{ request()->routeIs('home') ? 'Home' : (request()->routeIs('browse') ? 'Titles' : 'Reading') }}</span>
            <form action="{{ route('browse') }}" method="GET" class="top-search" role="search">
                <label class="sr-only" for="sumi-search">Search manga</label>
                <button class="top-search-submit" type="submit" aria-label="Search">⌕</button>
                <input id="sumi-search" type="search" name="q" placeholder="Search titles, authors, artists" value="{{ request('q') }}">
            </form>
            <div class="top-actions">
                @auth
                    @if (auth()->user()->canAccessAdmin())<a class="theme-button quiet" href="{{ route('admin.dashboard') }}">Dashboard</a>@endif
                    <form action="{{ route('logout') }}" method="POST">@csrf<button class="theme-button accent" type="submit">Sign out</button></form>
                @else<a class="theme-button accent" href="{{ route('login') }}">Sign in</a>@endauth
            </div>
        </header>

    <x-community-slot zone="header" />

        @if ($matureContentHidden && ! request()->routeIs('mature.*'))
            <aside class="mature-notice" aria-label="Mature content visibility"><span><strong>Mature titles are hidden.</strong> Confirm your age to include them.</span><a href="{{ route('mature.confirmation', ['return_to' => request()->getRequestUri()]) }}">Review access →</a></aside>
        @endif

        <main id="content">@yield('content')</main>
        <footer class="site-footer">
            <a href="{{ route('home') }}">{{ \App\Support\Koma::siteTitle() }}</a>
            <span>Powered by Koma Engine · Community Edition</span>
            <span class="footer-theme">Sumi theme</span>
            @php($komaPublisher = \App\Support\Koma::publisherIdentity())
            @php($komaSupport = \App\Support\Koma::supportLink())
            @if ($komaPublisher || $komaSupport)
                <div class="footer-community" role="group" aria-label="Publisher and support" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;width:100%;margin-top:8px;font-size:12px;opacity:.72">
                    @if ($komaPublisher)
                        <span>@if ($komaPublisher['url'])<a href="{{ $komaPublisher['url'] }}">{{ $komaPublisher['name'] }}</a>@else{{ $komaPublisher['name'] }}@endif</span>
                    @endif
                    @if ($komaPublisher && $komaSupport)<span aria-hidden="true">·</span>@endif
                    @if ($komaSupport)<a href="{{ $komaSupport['url'] }}">{{ $komaSupport['label'] }}</a>@endif
                </div>
            @endif
            @php($komaLegal = \App\Support\Koma::legalLinks())
            @if ($komaLegal)<nav aria-label="Legal" style="display:flex;flex-wrap:wrap;gap:12px;width:100%;justify-content:center;margin-top:8px;font-size:12px;opacity:.72">@foreach ($komaLegal as $l)<a href="{{ $l['url'] }}">{{ $l['label'] }}</a>@endforeach</nav>@endif
    <x-community-slot zone="footer" />
        </footer>
    </div>
    @stack('scripts')
</body>
</html>
