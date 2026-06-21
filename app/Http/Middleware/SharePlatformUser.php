<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Shares the authenticated operator (platform guard) with Inertia so the
 * operator layout can render the staff member + role. Applied to the platform
 * portal routes only — customer pages keep sharing the web user via
 * HandleInertiaRequests.
 */
class SharePlatformUser
{
    public function handle(Request $request, Closure $next)
    {
        Inertia::share('platformUser', fn () => auth('platform')->user()?->only(['id', 'name', 'email', 'role']));

        return $next($request);
    }
}
