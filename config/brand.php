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
    | theme. Emitted as CSS custom properties on the public site root (Site/Show),
    | so no block ever contains a raw hex. Change a value here = rebrand the whole
    | site. Direction: a Hubstaff-style indigo/violet gradient identity (the owner
    | chose to match the category's premium blue/purple look), with a pink→lavender
    | hero and deep-indigo sections building down the page.
    */
    'colors' => [
        'accent' => '#7C3AED',      // Violet — actions, links, focus (--a)
        'accent_deep' => '#6D28D9', // hover/pressed
        'grad_from' => '#22D3EE',   // Cyan — button / band gradient start
        'grad_to' => '#A855F7',     // Purple — gradient end (cyan→purple = vibrant aurora)
        'highlight' => '#C4B5FD',   // Violet-300 — on dark surfaces (--hl)
        'brand' => '#2A1257',       // Heading / strong text colour (--brand)
        'deep' => '#2A1257',        // Deep band BACKGROUND (Stats, CTA) — stays dark in both modes
        'ink' => '#1A0A3C',         // darkest
        'mint' => '#F3E8FF',        // soft violet tint
        'paper' => '#FAF5FF',       // lightest violet surface / band
        'canvas' => '#FFFFFF',      // page background
        'surface' => '#FFFFFF',     // cards
        'surface_2' => '#F6F2FE',   // subtle raised (logo bar, pricing toggles)
        'border' => '#ECE6F7',
        'text' => '#271F3D',
        'muted' => '#59506F',       // body / secondary
        'subtle' => '#968CAE',
        'pink' => '#FCE7F3',        // hero wash tint (pink glow)
        'lavender' => '#CFFAFE',    // hero wash tint (cyan glow — the aurora mix)
    ],

    /*
    | Dark theme palette — the night side of the day/night switcher. Same token
    | names as `colors`; the public site flips between the two when the visitor
    | toggles the sun/moon control (Site/Show). `deep` stays dark (it's a band
    | BACKGROUND), while `brand` (heading text) becomes light. Accent/gradient
    | colours brighten so they stay vivid on a dark canvas.
    */
    'colors_dark' => [
        'accent' => '#A78BFA',      // Violet-400 — brighter for dark surfaces
        'accent_deep' => '#8B5CF6',
        'grad_from' => '#22D3EE',   // Cyan — stays vivid on dark
        'grad_to' => '#C084FC',     // Purple-400
        'highlight' => '#DDD6FE',   // Violet-200
        'brand' => '#F3EEFF',       // Headings → near-white violet
        'deep' => '#241046',        // Deep band background (a lift above canvas)
        'ink' => '#0A0418',
        'mint' => '#281C44',        // soft violet tint (dark)
        'paper' => '#181030',       // raised band tint (dark)
        'canvas' => '#0E0A1C',      // page background (violet-tinted near-black)
        'surface' => '#191230',     // cards
        'surface_2' => '#231A3C',   // subtle raised
        'border' => '#322747',
        'text' => '#ECE6FA',
        'muted' => '#ABA0CC',       // body / secondary
        'subtle' => '#7E73A0',
        'pink' => '#2E1F3C',        // hero wash tint (dark, pink glow)
        'lavender' => '#103A4A',    // hero wash tint (dark, cyan glow)
    ],

    // Web font (Google Fonts). Loaded once in the blade shell.
    'font' => 'Hanken Grotesk',

    // Pricing: USD comes from the live Plans; PKR is shown via this rate until
    // the owner sets real local prices. REPLACE with real PKR pricing.
    'pkr_rate' => 280,
];
