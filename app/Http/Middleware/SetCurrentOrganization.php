<?php

namespace App\Http\Middleware;

use App\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the active tenant for the request from the authenticated user, early in
 * the web stack so every subsequent query is org-scoped. Calling $request->user()
 * also fires the Authenticated event, which binds the tenant for API/Sanctum
 * requests where this middleware runs before the route's auth guard. Guests
 * leave the tenant unbound (the scope no-ops), so login still works.
 */
class SetCurrentOrganization
{
    public function __construct(private CurrentOrganization $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->organization_id) {
            $this->current->set($user->organization_id);
        }

        return $next($request);
    }
}
