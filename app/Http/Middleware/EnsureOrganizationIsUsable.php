<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\OrganizationAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Block tenant product access when the workspace or subscription is inactive. */
class EnsureOrganizationIsUsable
{
    private const ALWAYS_AVAILABLE_ROUTES = [
        'billing.*',
        'logout',
        'profile.*',
        'password.*',
        'verification.*',
        'theme.update',
        'desktop.logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs(...self::ALWAYS_AVAILABLE_ROUTES)) {
            return $next($request);
        }

        $user = $request->user();

        // Platform operators use a separate model/guard and public routes have
        // no user. Neither belongs to a customer subscription.
        if (! $user instanceof User) {
            return $next($request);
        }

        $denial = OrganizationAccess::denialFor($user);

        if (! $denial) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $denial['message'],
                'code' => $denial['code'],
            ], $denial['status']);
        }

        return redirect()->route('billing.index')->with('error', $denial['message']);
    }
}
