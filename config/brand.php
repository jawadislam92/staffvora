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
        'brand' => '#1E1B4B',       // Heading / strong text colour (--brand)
        'deep' => '#1E1B4B',        // Deep band BACKGROUND (Stats, CTA) — stays dark in both modes
        'ink' => '#15132E',         // darkest
        'mint' => '#EDE9FE',        // soft lavender tint
        'paper' => '#F5F3FF',       // light lavender surface / band
        'canvas' => '#FFFFFF',      // page background
        'surface' => '#FFFFFF',     // cards
        'surface_2' => '#F5F3FF',   // subtle raised (logo bar, pricing toggles)
        'border' => '#E9E7F5',
        'text' => '#1E1B2E',
        'muted' => '#56536E',       // body / secondary
        'subtle' => '#9794AE',
        'pink' => '#FCE7F3',        // hero gradient tint
        'lavender' => '#EDE9FE',    // hero gradient tint
    ],

    /*
    | Dark theme palette — the night side of the day/night switcher. Same token
    | names as `colors`; the public site flips between the two when the visitor
    | toggles the sun/moon control (Site/Show). `deep` stays dark (it's a band
    | BACKGROUND), while `brand` (heading text) becomes light. Accent/gradient
    | colours brighten so they stay vivid on a dark canvas.
    */
    'colors_dark' => [
        'accent' => '#818CF8',      // Indigo-400 — brighter for dark surfaces
        'accent_deep' => '#6366F1',
        'grad_from' => '#A78BFA',   // Violet-300
        'grad_to' => '#818CF8',     // Indigo-400
        'highlight' => '#C4B5FD',   // Violet-200
        'brand' => '#EFEDFB',       // Headings → near-white lavender
        'deep' => '#1B1838',        // Deep band background (a lift above canvas)
        'ink' => '#08070F',
        'mint' => '#232046',        // soft lavender tint (dark)
        'paper' => '#171530',       // raised band tint (dark)
        'canvas' => '#0E0C1C',      // page background (indigo-tinted near-black)
        'surface' => '#191730',     // cards
        'surface_2' => '#221F3D',   // subtle raised
        'border' => '#2E2A4E',
        'text' => '#E7E4F6',
        'muted' => '#ABA6CC',       // body / secondary
        'subtle' => '#807BA3',
        'pink' => '#2A1E3A',        // hero gradient tint (dark)
        'lavender' => '#201C42',    // hero gradient tint (dark)
    ],

    // Web font (Google Fonts). Loaded once in the blade shell.
    'font' => 'Hanken Grotesk',

    // Pricing: USD comes from the live Plans; PKR is shown via this rate until
    // the owner sets real local prices. REPLACE with real PKR pricing.
    'pkr_rate' => 280,
];
