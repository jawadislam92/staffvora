<?php

namespace App\Http\Middleware;

use App\Models\PlatformUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate operator-portal routes by minimum role. Runs AFTER auth:platform, so a
 * user is always present. Rank: support(1) < admin(2) < owner(3).
 *
 *   ->middleware('platform.role:admin')  // admin or owner
 *   ->middleware('platform.role:owner')  // owner only
 */
class EnsurePlatformRole
{
    public function handle(Request $request, Closure $next, string $minRole): Response
    {
        $user = Auth::guard('platform')->user();
        $required = PlatformUser::ROLE_RANK[$minRole] ?? 99;

        abort_unless($user && $user->rank() >= $required, 403, 'Your operator role does not permit this action.');

        return $next($request);
    }
}
