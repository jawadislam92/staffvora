<?php

return [
    // The host the operator/landlord portal is served on. The customer site +
    // tenant app live on the bare domain; the platform console is bound to this
    // host only (Route::domain). Wildcard *.satimetracker.com DNS already points
    // here, so no new DNS record is needed — just nginx + a cert expand.
    'host' => env('PLATFORM_HOST', 'platform.satimetracker.com'),
];
