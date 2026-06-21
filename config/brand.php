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
        'accent' => '#4F46E5',      // Indigo — actions, links, focus (--a)
        'accent_deep' => '#4338CA', // hover/pressed
        'grad_from' => '#8B5CF6',   // Violet — button / band gradient start
        'grad_to' => '#4F46E5',     // Indigo — gradient end
        'highlight' => '#A78BFA',   // Violet-300 — on dark surfaces (--hl)
        'brand' => '#1E1B4B',       // Deep indigo navy — dark sections / CTA
        'ink' => '#15132E',         // darkest
        'mint' => '#EDE9FE',        // soft lavender tint
        'paper' => '#F5F3FF',       // light lavender surface / band
        'canvas' => '#FFFFFF',      // page background
        'surface' => '#FFFFFF',     // cards
        'border' => '#E9E7F5',
        'text' => '#1E1B2E',
        'muted' => '#56536E',       // body / secondary
        'subtle' => '#9794AE',
        'pink' => '#FCE7F3',        // hero gradient tint
        'lavender' => '#EDE9FE',    // hero gradient tint
    ],

    // Web font (Google Fonts). Loaded once in the blade shell.
    'font' => 'Hanken Grotesk',
];
