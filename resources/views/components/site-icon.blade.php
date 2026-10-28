{{-- The site icon from Settings → Branding. Without one this renders nothing
     and browsers keep using /favicon.ico. --}}
@if ($icon = \App\Support\Koma::siteIcon())
    <link rel="icon" type="{{ $icon['type'] }}" sizes="{{ $icon['size'] }}x{{ $icon['size'] }}" href="{{ $icon['url'] }}">
    <link rel="apple-touch-icon" href="{{ $icon['url'] }}">
@endif
