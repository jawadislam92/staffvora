<?php

/*
|--------------------------------------------------------------------------
| Marketing site navigation (SITE_BUILD_SPEC §2.3) — structure as data
|--------------------------------------------------------------------------
|
| Header + footer are defined here, not in JSX. A server-side resolver
| (App\Support\SiteNavigation) renders these, automatically omitting any item
| whose `flag` is off (config/features.php) or whose `page` slug isn't a
| published site_page. Adding/removing a link = a data edit here, never JSX.
|
| Item keys:
|   label    — display text
|   url      — href (lowercase site path, or /login /register /dashboard app routes)
|   page     — optional site_pages slug; item auto-hides until that page is published
|   flag     — optional config/features.php key; item auto-hides while the flag is off
|   children — optional dropdown (header only)
|
*/

return [

    'header' => [
        ['label' => 'Features', 'url' => '/features', 'page' => 'features'],
        ['label' => 'Solutions', 'url' => '/solutions', 'page' => 'solutions', 'children' => [
            ['label' => 'Agencies', 'url' => '/solutions/agencies', 'page' => 'solutions/agencies'],
            ['label' => 'BPOs & Call Centers', 'url' => '/solutions/bpos', 'page' => 'solutions/bpos'],
            ['label' => 'Software Houses', 'url' => '/solutions/software-houses', 'page' => 'solutions/software-houses'],
            ['label' => 'Remote Teams', 'url' => '/solutions/remote-teams', 'page' => 'solutions/remote-teams'],
            ['label' => 'Freelancers', 'url' => '/solutions/freelancers', 'page' => 'solutions/freelancers'],
        ]],
        ['label' => 'Pricing', 'url' => '/pricing', 'page' => 'pricing'],
        ['label' => 'Integrations', 'url' => '/integrations', 'flag' => 'integrations'],
        ['label' => 'Resources', 'children' => [
            ['label' => 'Blog', 'url' => '/blog', 'flag' => 'blog'],
            ['label' => 'Guides', 'url' => '/guides', 'flag' => 'guides'],
            ['label' => 'Security', 'url' => '/security', 'page' => 'security'],
            ['label' => 'Help', 'url' => '/help'],
        ]],
        ['label' => 'Contact', 'url' => '/contact', 'page' => 'contact'],
    ],

    'footer' => [
        ['title' => 'Product', 'links' => [
            ['label' => 'Features', 'url' => '/features', 'page' => 'features'],
            ['label' => 'Pricing', 'url' => '/pricing', 'page' => 'pricing'],
            ['label' => 'Integrations', 'url' => '/integrations', 'flag' => 'integrations'],
            ['label' => 'Download', 'url' => '/download', 'page' => 'download'],
            ['label' => 'Security', 'url' => '/security', 'page' => 'security'],
        ]],
        ['title' => 'Solutions', 'links' => [
            ['label' => 'Agencies', 'url' => '/solutions/agencies', 'page' => 'solutions/agencies'],
            ['label' => 'BPOs & Call Centers', 'url' => '/solutions/bpos', 'page' => 'solutions/bpos'],
            ['label' => 'Software Houses', 'url' => '/solutions/software-houses', 'page' => 'solutions/software-houses'],
            ['label' => 'Remote Teams', 'url' => '/solutions/remote-teams', 'page' => 'solutions/remote-teams'],
            ['label' => 'Freelancers', 'url' => '/solutions/freelancers', 'page' => 'solutions/freelancers'],
        ]],
        ['title' => 'Company', 'links' => [
            ['label' => 'About', 'url' => '/about', 'page' => 'about'],
            ['label' => 'Contact', 'url' => '/contact', 'page' => 'contact'],
            ['label' => 'Blog', 'url' => '/blog', 'flag' => 'blog'],
            ['label' => 'Careers', 'url' => '/careers', 'flag' => 'careers'],
        ]],
        ['title' => 'Compare', 'flag' => 'comparisons', 'links' => [
            ['label' => 'vs Time Doctor', 'url' => '/compare/time-doctor'],
            ['label' => 'vs Hubstaff', 'url' => '/compare/hubstaff'],
            ['label' => 'vs Clockify', 'url' => '/compare/clockify'],
        ]],
        ['title' => 'Resources', 'links' => [
            ['label' => 'Guides', 'url' => '/guides', 'flag' => 'guides'],
            ['label' => 'Tools', 'url' => '/tools', 'flag' => 'tools'],
            ['label' => 'Help', 'url' => '/help'],
        ]],
        ['title' => 'Legal', 'links' => [
            ['label' => 'Terms', 'url' => '/terms', 'page' => 'terms'],
            ['label' => 'Privacy', 'url' => '/privacy', 'page' => 'privacy'],
        ]],
    ],
];
