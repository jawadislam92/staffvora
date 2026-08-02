<?php

namespace App\Http\Controllers\Api\Desktop;

use App\Http\Controllers\Controller;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/**
 * Base for every desktop-tracker API controller.
 *
 * The desktop agent runs on the employee's own machine and holds a Sanctum
 * token that never expires, so "this person no longer works here" has to be
 * enforced on EVERY request, not only at login. Deactivating a user revokes
 * their tokens, but that leaves a race: a token minted moments before the flag
 * flipped would otherwise keep logging billable hours, clocking in and
 * uploading screenshots. This guard is the same rule the web login enforces
 * (App\Http\Requests\Auth\LoginRequest), applied to the token surface.
 *
 * Registered as controller middleware rather than route middleware because a
 * closure in routes/api.php cannot survive `php artisan route:cache`, which the
 * production deploy script runs.
 */
abstract class DesktopController extends Controller
{
    /** Routes a deactivated client must still be able to reach. */
    private const ALWAYS_AVAILABLE = [
        'desktop.login',
        'desktop.logout',
    ];

    public function __construct()
    {
        $this->middleware(function (Request $request, Closure $next) {
            $user = $request->user();

            if (! $user instanceof User || $user->isActive()) {
                return $next($request);
            }

            // Letting a deactivated client clear its own token is desirable.
            if (in_array($request->route()?->getName(), self::ALWAYS_AVAILABLE, true)) {
                return $next($request);
            }

            return response()->json([
                'message' => 'Your account has been deactivated. Please contact your administrator.',
                'code' => 'account_deactivated',
            ], 403);
        });
    }
}
