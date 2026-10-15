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
    @hasSection('og_image')
        <meta property="og:image" content="@yield('og_image')">
    @endif
    <meta name="twitter:card" content="summary_large_image">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700;800&amp;family=Schibsted+Grotesk:wght@600;700;800&amp;family=Spline+Sans+Mono:wght@500;600&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="@themeAsset('theme.css')">
    @if ($accent)
        <style>:root { --accent: {!! $accent !!}; }</style>
    @endif
    @stack('head')
</head>
<body>
    <div class="utility-strip">
        <div class="ks-wrap utility-inner">
            <span>Independent stories. Reader-first publishing.</span>
            <span class="utility-theme">Kasai / {{ \App\Support\Koma::siteTitle() }}</span>
        </div>
    </div>

    <header class="site-header">
        <div class="ks-wrap header-inner">
            <a href="{{ route('home') }}" class="brand" aria-label="{{ \App\Support\Koma::siteTitle() }} home">
                <svg class="brand-flame" viewBox="0 0 32 38" aria-hidden="true"><path d="M13.5 37C4.4 34.7.2 28.5 2.3 20.6c1.2-4.5 4.5-7.7 8.8-11.6-.4 4.8 1.9 7.5 4.1 7.4 2.7-.2 4.2-5 3-13.5 8.6 6.4 12.3 14.3 10.4 22.1C26.8 32.2 20.2 37 13.5 37Z"/><path class="flame-cut" d="M15.5 33c-4.6-1.3-6.8-4.5-5.5-8.4.8-2.3 2.5-4 4.8-6.1-.2 2.5 1 4 2.2 3.8 1.4-.2 2.2-2.5 1.7-6.7 4.2 3.2 5.8 7.1 4.7 10.9-1 3.7-4.4 6.5-7.9 6.5Z"/></svg>
                <span>{{ \App\Support\Koma::siteTitle() }}</span>
            </a>

            <nav class="primary-nav" aria-label="Primary navigation">
                <a href="{{ route('home') }}" @class(['active' => request()->routeIs('home')])>Home</a>
                <a href="{{ route('browse') }}" @class(['active' => request()->routeIs('browse', 'series.*')])>Titles</a>
                <a href="{{ route('home').'#latest' }}">Latest</a>
            </nav>

            <div class="header-actions">
                <form action="{{ route('browse') }}" method="GET" class="header-search" role="search">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                    <label class="sr-only" for="kasai-search">Search manga</label>
                    <input id="kasai-search" type="search" name="q" placeholder="Search manga…" value="{{ request('q') }}">
                </form>
                @auth
                    @if (auth()->user()->canAccessAdmin())
                        <a href="{{ route('admin.dashboard') }}" class="ks-button quiet">Dashboard</a>
                    @endif
                    <form action="{{ route('logout') }}" method="POST">@csrf<button class="ks-button dark" type="submit">Sign out</button></form>
                @else
                    <a href="{{ route('login') }}" class="ks-button dark">Sign in</a>
                @endauth
            </div>

            <details class="mobile-menu">
                <summary aria-label="Open navigation"><span></span><span></span><span></span></summary>
                <div class="mobile-menu-panel">
                    <form action="{{ route('browse') }}" method="GET" class="mobile-search" role="search">
                        <label for="kasai-mobile-search">Search manga</label>
                        <input id="kasai-mobile-search" type="search" name="q" value="{{ request('q') }}" placeholder="Title, author, or artist">
                    </form>
                    <a href="{{ route('home') }}">Home</a>
                    <a href="{{ route('browse') }}">Titles</a>
                    <a href="{{ route('home').'#latest' }}">Latest updates</a>
                    @auth
                        @if (auth()->user()->canAccessAdmin())
                            <a href="{{ route('admin.dashboard') }}">Dashboard</a>
                        @endif
                        <form action="{{ route('logout') }}" method="POST">@csrf<button type="submit">Sign out</button></form>
                    @else
                        <a href="{{ route('login') }}">Sign in</a>
                    @endauth
                </div>
            </details>
        </div>
    </header>

    <x-community-slot zone="header" />

    @if ($matureContentHidden && ! request()->routeIs('mature.*'))
        <aside class="mature-notice" aria-label="Mature content visibility">
            <div class="ks-wrap mature-notice-inner">
                <span><strong>Mature titles are hidden.</strong> Confirm your age to include them in the catalog.</span>
                <a href="{{ route('mature.confirmation', ['return_to' => request()->getRequestUri()]) }}">Review access <span aria-hidden="true">→</span></a>
            </div>
        </aside>
    @endif

    <main>
        @yield('content')
    </main>

    <footer class="site-footer">
        <div class="ks-wrap footer-inner">
            <a href="{{ route('home') }}" class="footer-brand">{{ \App\Support\Koma::siteTitle() }}</a>
            <span>Powered by Koma Engine · Community Edition</span>
            <span class="footer-theme">Kasai theme</span>
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
