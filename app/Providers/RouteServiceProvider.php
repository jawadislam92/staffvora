<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/dashboard';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // The `web` middleware group ships NO throttling at all, so every
        // mutating endpoint below was reachable as fast as a script could send.
        // These are keyed on the authenticated user, never the IP: one big
        // customer is 100+ people behind a single office NAT, and an IP key
        // would let one person's loop lock out their whole company.

        /**
         * POST /feedback and friends. Each submission fans out to blocking
         * Slack calls, so an unthrottled loop is both a worker-exhaustion
         * vector and a DM-spam amplifier. The daily ceiling matters as much as
         * the per-minute one: 5/minute on its own still allows 7,200 rows per
         * user per day in the feedback table.
         */
        RateLimiter::for('feedback', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            return [
                Limit::perMinute(5)->by('feedback-min:'.$key),
                Limit::perDay(50)->by('feedback-day:'.$key),
            ];
        });

        /**
         * Bulk mutation endpoints (bulk-delete / bulk-update / bulk-status).
         * Their array caps bound ONE request; without a throttle an attacker
         * simply sends 250 capped requests instead of one uncapped one.
         */
        RateLimiter::for('bulk', function (Request $request) {
            return Limit::perMinute(20)->by('bulk:'.($request->user()?->id ?: $request->ip()));
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
