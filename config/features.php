<?php

/*
|--------------------------------------------------------------------------
| Marketing site feature flags (SITE_BUILD_SPEC §2.2)
|--------------------------------------------------------------------------
|
| One boolean per scalable section. Routes, nav items and any home-page links
| to a section all check its flag. Flag off → the route 404s, the nav hides it,
| and links to it disappear. Flip ONE boolean to launch a whole section — no
| code change, no structural edits.
|
| Each section's engine (index + item template + data source) is built behind
| its flag with a seed or two, so turning it on later is just content.
|
*/

return [
    'integrations' => false,
    'tools' => false,        // free calculators (index + route reserved, registry empty)
    'guides' => false,
    'glossary' => false,
    'blog' => false,
    'industries' => false,
    'comparisons' => false,  // "vs / alternative" pages
    'customer_stories' => false,
    'careers' => false,
    'affiliates' => false,
];
