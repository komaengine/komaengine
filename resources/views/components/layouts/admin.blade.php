<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full overflow-x-hidden">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ ($title ?? 'Dashboard') }} · {{ \App\Support\Koma::siteTitle() }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@500;600;700;800&family=Hanken+Grotesk:wght@400;500;600;700;800&family=Spline+Sans+Mono:wght@500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full overflow-x-hidden bg-paper text-[#1c1a18]">
@php
    $user = auth()->user();
    $titles = [
        'admin.dashboard' => 'Dashboard',
        'admin.updates' => 'Updates',
        'admin.series.index' => 'Series & Chapters',
        'admin.series.create' => 'Add series',
        'admin.series.edit' => 'Edit series',
        'admin.series.chapters.pages' => 'Manage chapter pages',
        'admin.upload' => 'Bulk Uploader',
        'admin.archives.import' => 'Import archive',
        'admin.genres' => 'Genres',
        'admin.tags' => 'Tags',
        'admin.comments' => 'Comment Moderation',
        'admin.users' => 'Users & Roles',
        'admin.api-tokens' => 'API Access',
        'admin.themes' => 'Themes',
        'admin.plugins' => 'Plugins',
        'admin.plugins.hooks' => 'Plugins',
        'admin.settings' => 'Settings',
    ];
    $title = $titles[request()->route()?->getName()] ?? 'Dashboard';
    $primary = [
        ['route' => 'admin.dashboard', 'label' => 'Dashboard', 'match' => ['admin.dashboard', 'admin.updates'], 'can' => null,
         'children' => [
             ['route' => 'admin.dashboard', 'label' => 'Home', 'match' => 'admin.dashboard', 'can' => null],
             ['route' => 'admin.updates', 'label' => 'Updates', 'match' => 'admin.updates', 'can' => 'manage_settings'],
         ],
         'icon' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>'],
        ['route' => 'admin.series.index', 'label' => 'Series & Chapters', 'match' => ['admin.series.*', 'admin.upload', 'admin.archives.*', 'admin.genres', 'admin.tags'], 'can' => 'edit_series',
         'children' => [
             ['route' => 'admin.series.index', 'label' => 'All Series', 'match' => ['admin.series.index', 'admin.series.edit', 'admin.series.chapters.pages'], 'can' => 'edit_series'],
             ['route' => 'admin.series.create', 'label' => 'Add Series/Chapter', 'match' => 'admin.series.create', 'can' => 'edit_series'],
             ['route' => 'admin.genres', 'label' => 'Genres', 'match' => 'admin.genres', 'can' => 'manage_taxonomy'],
             ['route' => 'admin.tags', 'label' => 'Tags', 'match' => 'admin.tags', 'can' => 'manage_taxonomy'],
             ['route' => 'admin.archives.import', 'label' => 'Import Archive', 'match' => 'admin.archives.*', 'can' => 'edit_series'],
         ],
         'icon' => '<path d="M4 4h11a2 2 0 0 1 2 2v14a2 2 0 0 0-2-2H4z"/><path d="M20 18a2 2 0 0 0-2-2H6"/>'],
    ];
    $manage = [
        ['route' => 'admin.comments', 'label' => 'Comments', 'match' => 'admin.comments', 'can' => 'moderate_comments',
         'icon' => '<path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/><path d="M8 9h8M8 13h5"/>'],
        ['route' => 'admin.users', 'label' => 'Users & Roles', 'match' => 'admin.users', 'can' => 'manage_users',
         'icon' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/>'],
        ['route' => 'admin.api-tokens', 'label' => 'API Access', 'match' => 'admin.api-tokens', 'can' => 'manage_users',
         'icon' => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m12 12 8-8"/><path d="m15 7 2 2"/><path d="m18 4 2 2"/>'],
    ];
    $system = [
        ['route' => 'admin.themes', 'label' => 'Themes', 'match' => 'admin.themes', 'can' => 'manage_themes',
         'icon' => '<path d="M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z"/><path d="M7.5 10h.01M12 7.5h.01M16.5 10h.01"/><path d="M17 15.5c0 1.1-.9 2-2 2h-1.2a1.8 1.8 0 0 1-1.8-1.8c0-.9-.8-1.7-1.7-1.7H7"/>'],
        ['route' => 'admin.plugins', 'label' => 'Plugins', 'match' => 'admin.plugins*', 'can' => 'manage_plugins',
         'children' => [
             ['route' => 'admin.plugins', 'label' => 'Plugins', 'match' => 'admin.plugins', 'can' => 'manage_plugins'],
             ['route' => 'admin.plugins.hooks', 'label' => 'Hooks', 'match' => 'admin.plugins.hooks', 'can' => 'manage_plugins'],
         ],
         'icon' => '<path d="M12 2v6"/><path d="M5 8h14"/><path d="M5 8v4a7 7 0 0 0 14 0V8"/><path d="M8 22v-4"/><path d="M16 22v-4"/>'],
        ['route' => 'admin.settings', 'label' => 'Settings', 'match' => 'admin.settings', 'can' => 'manage_settings',
         // Driven by the settings schema so a new tab shows up in the menu
         // without a second list to keep in sync.
         'children' => collect(\App\Engine\Settings\SettingsSchema::tabs())
             ->map(fn (string $label, string $tab) => [
                 'route' => 'admin.settings', 'params' => ['tab' => $tab], 'active_tab' => $tab,
                 'label' => $label, 'match' => 'admin.settings', 'can' => 'manage_settings',
             ])->values()->all(),
         'icon' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>'],
    ];
    $navigation = [...$primary, ...$manage, ...$system];
    $pendingCommentCount = $user?->can('moderate_comments')
        ? \App\Models\Comment::query()->where('status', \App\Models\Comment::STATUS_PENDING)->count()
        : 0;
    $canCreateContent = $user?->can('edit_series') || $user?->can('upload_chapters');
@endphp

    <div
        x-data="{
            navOpen: false,
            newMenuOpen: false,
            accountMenuOpen: false,
            isDesktop: window.matchMedia('(min-width: 1024px)').matches,
        }"
        @resize.window="isDesktop = window.matchMedia('(min-width: 1024px)').matches; if (isDesktop) navOpen = false"
        @keydown.escape.window="
            if (navOpen) { navOpen = false; $nextTick(() => $refs.navToggle.focus()) }
            if (newMenuOpen) { newMenuOpen = false; $nextTick(() => $refs.newMenuToggle.focus()) }
            if (accountMenuOpen) { accountMenuOpen = false; $nextTick(() => $refs.accountMenuToggle.focus()) }
        "
        class="flex h-full flex-col overflow-hidden"
    >
        {{-- WordPress-style global admin toolbar --}}
        <header id="koma-admin-bar" aria-label="Admin toolbar" class="relative z-[60] flex h-11 shrink-0 items-center bg-[#1d2327] text-[13px] text-[#c3c4c7] lg:h-8">
            <button
                type="button"
                x-ref="navToggle"
                @click="navOpen = true; newMenuOpen = false; accountMenuOpen = false; $nextTick(() => $refs.sidebar.querySelector('a, button')?.focus())"
                :aria-expanded="navOpen.toString()"
                aria-controls="admin-navigation"
                aria-label="Open navigation"
                class="flex h-11 w-11 shrink-0 items-center justify-center hover:bg-[#2c3338] hover:text-white lg:hidden"
            >
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>

            <a href="{{ route('admin.dashboard') }}" title="Dashboard" aria-label="Koma Engine dashboard" class="flex h-full shrink-0 items-center gap-2 px-2.5 hover:bg-[#2c3338] hover:text-white sm:px-3">
                <span class="flex h-6 w-6 items-center justify-center rounded-md bg-koma font-display text-[13px] font-extrabold text-white">コ</span>
                <span class="hidden font-display text-[13px] font-bold text-white md:inline">Koma Engine</span>
            </a>

            <a href="{{ url('/') }}" target="_blank" rel="noopener" title="Visit site" aria-label="Visit site" class="flex h-full min-w-0 items-center gap-1.5 px-2.5 hover:bg-[#2c3338] hover:text-white sm:px-3">
                <svg class="shrink-0" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/></svg>
                <span class="hidden max-w-48 truncate sm:block">{{ \App\Support\Koma::siteTitle() }}</span>
            </a>

            @can('moderate_comments')
                <a href="{{ route('admin.comments') }}" title="Comments awaiting moderation" aria-label="Comments awaiting moderation: {{ $pendingCommentCount }}" class="flex h-full items-center gap-1.5 px-2.5 hover:bg-[#2c3338] hover:text-white sm:px-3">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20 3H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4l4 3 4-3h4a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2Z"/></svg>
                    <span class="font-semibold tabular-nums">{{ $pendingCommentCount }}</span>
                </a>
            @endcan

            @if ($canCreateContent)
                <div
                    class="relative h-full"
                    @mouseenter="newMenuOpen = true; accountMenuOpen = false"
                    @mouseleave="newMenuOpen = false"
                    @click.outside="newMenuOpen = false"
                >
                    <button
                        type="button"
                        x-ref="newMenuToggle"
                        @click="newMenuOpen = $event.detail === 0 ? ! newMenuOpen : true; accountMenuOpen = false"
                        :aria-expanded="newMenuOpen.toString()"
                        aria-controls="admin-new-menu"
                        aria-haspopup="menu"
                        aria-label="New"
                        class="flex h-full items-center gap-1.5 px-2.5 font-semibold hover:bg-[#2c3338] hover:text-white sm:px-3"
                    >
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                        <span class="hidden sm:inline">New</span>
                    </button>
                    <div
                        id="admin-new-menu"
                        x-cloak
                        x-show="newMenuOpen"
                        x-transition.opacity.duration.100ms
                        role="menu"
                        class="absolute left-0 top-full w-52 border border-[#3c434a] bg-[#2c3338] py-1.5 shadow-xl"
                    >
                        @can('edit_series')
                            <a href="{{ route('admin.series.create') }}" role="menuitem" class="block px-3.5 py-2 text-[#c3c4c7] hover:bg-[#3c434a] hover:text-white">New series</a>
                        @endcan
                        @can('upload_chapters')
                            <a href="{{ route('admin.upload') }}" role="menuitem" class="block px-3.5 py-2 text-[#c3c4c7] hover:bg-[#3c434a] hover:text-white">Upload chapter</a>
                        @endcan
                        @can('edit_series')
                            <a href="{{ route('admin.archives.import') }}" role="menuitem" class="block px-3.5 py-2 text-[#c3c4c7] hover:bg-[#3c434a] hover:text-white">Import archive</a>
                        @endcan
                    </div>
                </div>
            @endif

            <div class="ml-auto h-full">
                <div class="relative h-full" @click.outside="accountMenuOpen = false">
                    <button
                        type="button"
                        x-ref="accountMenuToggle"
                        @click="accountMenuOpen = ! accountMenuOpen; newMenuOpen = false"
                        :aria-expanded="accountMenuOpen.toString()"
                        aria-controls="admin-account-menu"
                        aria-label="Howdy, {{ $user?->name }}"
                        class="flex h-full items-center gap-2 px-2.5 hover:bg-[#2c3338] hover:text-white sm:px-3"
                    >
                        <span class="hidden sm:inline">Howdy, <strong class="font-semibold text-white">{{ $user?->name }}</strong></span>
                        <span class="h-6 w-6 shrink-0 rounded-md" style="background: {{ $user?->avatarGradient() }}"></span>
                    </button>
                    <div
                        id="admin-account-menu"
                        x-cloak
                        x-show="accountMenuOpen"
                        x-transition.opacity.duration.100ms
                        class="absolute right-0 top-full w-56 border border-[#3c434a] bg-[#2c3338] py-2 shadow-xl"
                    >
                        <div class="border-b border-white/10 px-4 pb-2.5 pt-1">
                            <div class="font-semibold text-white">{{ $user?->name }}</div>
                            <div class="mt-0.5 text-[11px] text-[#8c8f94]">{{ $user?->getRoleNames()->first() ?? 'Staff' }}</div>
                        </div>
                        <a href="{{ url('/') }}" target="_blank" rel="noopener" class="block px-4 py-2 text-[#c3c4c7] hover:bg-[#3c434a] hover:text-white">Visit site</a>
                        @can('manage_settings')
                            <a href="{{ route('admin.settings') }}" class="block px-4 py-2 text-[#c3c4c7] hover:bg-[#3c434a] hover:text-white">Settings</a>
                        @endcan
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="block w-full px-4 py-2 text-left text-[#c3c4c7] hover:bg-[#3c434a] hover:text-white">Log out</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <div class="flex min-h-0 flex-1 overflow-hidden">
            <div x-cloak x-show="navOpen" x-transition.opacity @click="navOpen = false; $nextTick(() => $refs.navToggle.focus())" class="fixed inset-x-0 bottom-0 top-11 z-40 bg-black/45 lg:hidden" aria-hidden="true"></div>
            {{-- Sidebar --}}
            <nav id="admin-navigation"
                 x-ref="sidebar"
                 inert
                 aria-hidden="true"
                 :inert="! isDesktop && ! navOpen"
                 :aria-hidden="(! isDesktop && ! navOpen).toString()"
                 :class="navOpen ? 'translate-x-0' : '-translate-x-full'"
                 class="fixed bottom-0 left-0 top-11 z-50 flex w-[min(84vw,280px)] shrink-0 flex-col overflow-y-auto bg-ink text-[#a59ea8] shadow-2xl transition-transform duration-200 lg:static lg:z-auto lg:w-[248px] lg:translate-x-0 lg:overflow-visible lg:shadow-none">

                <div class="flex flex-col gap-0.5 px-3 py-3.5">
                    @foreach ($navigation as $item)
                        @include('admin.partials.nav-item', $item)
                    @endforeach
                </div>

                <div class="mt-auto border-t border-white/[0.07] px-3 py-3.5">
                    <div class="mx-1 rounded-[11px] border border-koma/40 bg-gradient-to-br from-koma/20 to-[oklch(0.55_0.17_18_/_0.12)] px-3 py-2.5">
                        <div class="mb-0.5 flex items-center gap-2">
                            <span class="rounded bg-koma px-1.5 py-0.5 text-[10px] font-extrabold uppercase tracking-wide text-white">Community</span>
                            <span class="text-xs font-bold text-white">Edition</span>
                        </div>
                        <div class="text-[10.5px] text-[#a59ea8]">Open-source · self-hosted</div>
                    </div>
                </div>
            </nav>

            {{-- Main --}}
            <div class="flex min-w-0 flex-1 flex-col overflow-hidden">
                <main class="flex-1 overflow-x-hidden overflow-y-auto p-4 lg:p-6">
                    <div class="mx-auto max-w-[1180px]">
                        <h1 class="mb-5 min-w-0 truncate font-display text-[22px] font-extrabold tracking-tight lg:text-[24px]">{{ $title ?? 'Dashboard' }}</h1>
                    @if (session('status'))
                        <div class="mb-4 rounded-xl border border-[oklch(0.7_0.13_150_/_0.3)] bg-[oklch(0.7_0.13_150_/_0.12)] px-4 py-3 text-[13.5px] font-semibold text-[oklch(0.4_0.12_150)]">
                            {{ session('status') }}
                        </div>
                    @endif
                    @if (session('error'))
                        <div class="mb-4 rounded-xl border border-koma/30 bg-koma-soft px-4 py-3 text-[13.5px] font-semibold text-[#6d3329]">
                            {{ session('error') }}
                        </div>
                    @endif
                        {{ $slot }}
                    </div>
                    <div class="mx-auto mt-8 max-w-[1180px] text-center text-[12px] text-faint">
                        @filter('admin.footer_text', 'Koma Engine · Community Edition')
                    </div>
                </main>
            </div>
        </div>
    </div>
    @livewireScripts
</body>
</html>
