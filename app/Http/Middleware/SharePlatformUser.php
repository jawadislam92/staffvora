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
        $operator = auth('platform')->user();

        Inertia::share('platformUser', fn () => $operator?->only(['id', 'name', 'email', 'role']));

        // The customer `web` user is always null on the platform host, so
        // HandleInertiaRequests resolves `theme` to the workspace default (light)
        // and every navigation would clobber the operator's choice back to light.
        // Re-resolve it from the tt_theme cookie (set client-side by the operator
        // theme switcher) so dark and black persist. Runs inside the platform
        // group, AFTER the global share, so this value wins for operator pages.
        if ($operator) {
            $cookie = $request->cookie('tt_theme');
            Inertia::share('theme', in_array($cookie, ['light', 'dark', 'black'], true) ? $cookie : 'light');
        }

        return $next($request);
    }
}
