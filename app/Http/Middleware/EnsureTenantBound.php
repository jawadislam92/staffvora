<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail-CLOSED tenancy guard for the authenticated customer surface.
 *
 * OrganizationScope is deliberately a no-op when no tenant is bound: login,
 * password reset, TenantProvisioner and console commands all legitimately run
 * unbound, and making the scope itself abort would break authentication. The
 * consequence is that an authenticated User whose organization_id is NULL would
 * otherwise run EVERY tenant query with no organization predicate — unrestricted
 * cross-customer read *and* write from the ordinary login page.
 *
 * This middleware closes that hole at the request boundary, which is the only
 * place where "there is an authenticated customer, therefore a tenant MUST be
 * bound" is true. It is a no-op for guests (login/password reset keep working)
 * and for the platform/landlord guard, which has its own user model.
 *
 * Registered as the `tenant.required` alias and applied to the whole `web` and
 * `api` middleware groups in app/Http/Kernel.php.
 */
class EnsureTenantBound
{
    /**
     * @param  string  $guard  Guard to inspect — `web` for the customer UI,
     *                         `sanctum` for the desktop tracker API.
     */
    public function handle(Request $request, Closure $next, string $guard = 'web'): Response
    {
        $user = $request->user($guard);

        if (! $user instanceof User) {
            // Guest, or the platform-portal guard: no customer tenant expected.
            return $next($request);
        }

        if (! $user->organization_id) {
            return $this->deny($request, $user, 'no_workspace');
        }

        $current = app(CurrentOrganization::class);
        $bound = $current->id();

        if ($bound === null) {
            // Belt and braces. SetCurrentOrganization covers the web stack and the
            // Authenticated listener covers session logins, but Sanctum's
            // RequestGuard resolves a token without firing Authenticated, so a
            // desktop API request can reach here unbound — which would run every
            // query with no organization predicate. Bind it before proceeding.
            $current->set((int) $user->organization_id);
        } elseif ($bound !== (int) $user->organization_id) {
            // The bound tenant disagrees with the authenticated user: never guess.
            return $this->deny($request, $user, 'tenant_mismatch');
        }

        return $next($request);
    }

    private function deny(Request $request, User $user, string $code): Response
    {
        Log::warning('Blocked request from a user with no bound organization.', [
            'user_id' => $user->getKey(),
            'email' => $user->email,
            'code' => $code,
            'path' => $request->path(),
            'ip' => $request->ip(),
        ]);

        // Kill the session so the account cannot keep probing the surface.
        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        $message = 'This account is not attached to a workspace. Contact support.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'code' => $code,
            ], 403);
        }

        abort(403, $message);
    }
}
