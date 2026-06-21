<?php

/*
|--------------------------------------------------------------------------
| Brand (marketing site) — the ONE place to swap identity
|--------------------------------------------------------------------------
|
| Per SITE_BUILD_SPEC §3: nothing in the marketing site hardcodes the brand
| name, colours or contact details — everything reads from here (or the CSS
| vars seeded from `colors` below). Rebranding = edit this file (+ the logo
| asset + the favicon set) and nothing else.
|
| Current identity = the chosen "Measured System" brand direction (calm green)
| from the Claude Design handoff. Values marked REPLACE are business details
| the owner will finalise.
|
*/

return [
    'name' => 'Tracksuite',                 // working product name
    'legal_name' => 'Sparking Asia (Pvt.) Ltd.', // REPLACE: registered company
    'tagline' => 'We measure the work, not the worker.',
    'description' => 'A calm, honest time tracker for remote teams — insight without surveillance.',

    // Contact / conversion (REPLACE with real details before launch).
    'support_email' => 'hello@satimetracker.com',
    'sales_email' => 'hello@satimetracker.com',
    'whatsapp' => '',                       // REPLACE: full intl number, e.g. 923001234567
    'phone' => '',                          // REPLACE
    'address' => 'Rawalpindi, Pakistan',    // REPLACE: full office address
    'demo_url' => '',                       // REPLACE: Calendly / demo-booking link

    // Social profiles (blank = hidden in header/footer).
    'socials' => [
        'linkedin' => '',
        'facebook' => '',
        'instagram' => '',
        'x' => '',
        'youtube' => '',
    ],

    /*
    | Brand palette — the single source of truth for the marketing site's light
    | theme. These exact hexes are emitted as CSS custom properties on the public
    | site root (see the marketing palette in app.css / Site/Show), so no block
    | ever contains a raw hex. From the "Measured System" guidelines.
    */
    'colors' => [
        'accent' => '#4F9E78',      // Sage — actions, links, focus (--a)
        'accent_deep' => '#3C8763', // Sage deep — hover/pressed
        'highlight' => '#86C9A4',   // Mint-bright — on dark surfaces (--hl)
        'brand' => '#1C3D2F',       // Evergreen — primary brand / dark sections
        'ink' => '#15201B',         // darkest text
        'mint' => '#CFE6D7',        // soft tint
        'paper' => '#F4F1E9',       // warm surface
        'canvas' => '#FBFAF6',      // page background
        'surface' => '#FFFFFF',     // cards
        'border' => '#E4E2D8',
        'text' => '#15201B',
        'muted' => '#4A5650',       // Slate — body/secondary
        'subtle' => '#8A938C',
    ],

    // Web font (Google Fonts). Loaded once in the blade shell.
    'font' => 'Hanken Grotesk',
];
