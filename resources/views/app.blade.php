<!DOCTYPE html>
@php($ttTheme = in_array(request()->cookie('tt_theme'), ['light', 'dark', 'black'], true) ? request()->cookie('tt_theme') : 'light')
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $ttTheme }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'Staffvora') }}</title>

        <!-- Favicons. Modern browsers prefer the neutral SVG mark; the SA-era
             PNG/ICO remain only as a fallback until a final brand favicon ships. -->
        <link rel="icon" type="image/svg+xml" href="/images/default-logo.svg">
        <link rel="icon" type="image/x-icon" href="/favicon.ico">
        <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
        <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
        <link rel="icon" type="image/png" sizes="192x192" href="/android-chrome-192x192.png">
        <link rel="manifest" href="/manifest.json">

        {{--
            Fonts load ASYNCHRONOUSLY and must stay that way.

            A plain <link rel="stylesheet"> to a third-party font host is
            RENDER-BLOCKING: the browser paints nothing until that request
            finishes. `display=swap` does not help — it governs the font FILE,
            not the stylesheet. So if fonts.googleapis.com is slow or filtered
            on the visitor's network, the whole app hangs on a blank screen
            until the browser gives up (observed: a ~1 minute sign-in from
            Pakistan while the server itself answered in 40ms).

            `rel=preload as=style` + onload swap keeps the fetch non-blocking;
            the <noscript> keeps it working without JS. The page renders
            immediately in the system stack and upgrades when the font lands.
        --}}
        <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="preload" as="style" href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap"
              onload="this.onload=null;this.rel='stylesheet'">
        {{-- Hanken Grotesk — marketing site brand face (scoped to .site-root) --}}
        <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700;800&display=swap"
              onload="this.onload=null;this.rel='stylesheet'">
        <noscript>
            <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet">
            <link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        </noscript>

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.jsx', "resources/js/Pages/{$page['component']}.jsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
