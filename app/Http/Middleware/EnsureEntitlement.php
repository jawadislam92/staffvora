<?php

namespace App\Http\Middleware;

use App\Models\Addon;
use App\Models\Plan;
use App\Models\User;
use App\Tenancy\Entitlements;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The ONE HTTP gate for gated capabilities. It owns no rules of its own: it
 * asks `Organization::can()`, which is the single resolution path
 * (per-company override → active add-ons → plan → catalogue default = deny).
 *
 * Consequences worth knowing:
 *  • an add-on can open a route the plan does not include;
 *  • a per-company override can shut a route the add-on/plan opens;
 *  • a temporary grant closes the route by itself once it expires;
 *  • retiring a feature in the catalogue closes it everywhere at once, even
 *    for plans whose stored JSON still says `true`.
 *
 * Never add a second middleware that decides access — add a feature key here.
 *
 * ─── WHAT A REFUSAL LOOKS LIKE ───────────────────────────────────────────────
 * A refusal is still a refusal: the guarded controller NEVER runs and the
 * status is 403. What changed is the BODY. A bare error page teaches the
 * customer nothing and sells nothing, so a browser gets the `Upgrade/Locked`
 * Inertia page instead — it names the missing capability, says what it unlocks,
 * and offers the upgrade path (the cheapest public plan or add-on that grants
 * it, priced from the catalogue row, never from the request).
 *
 * A machine caller (`expectsJson`) keeps the compact JSON refusal it always
 * had, because an API client wants a code, not a page.
 */
class EnsureEntitlement
{
    /** How long the "which plan/add-on grants this?" lookup is cached, in seconds. */
    private const OFFER_TTL = 300;

    public function handle(Request $request, Closure $next, string $entitlement): Response
    {
        $user = $request->user();

        // Platform operators and public requests are outside the customer plan
        // model and are handled by their own guards.
        if (! $user instanceof User) {
            return $next($request);
        }

        // Fail CLOSED for a customer with no workspace: no organization means no
        // plan, and letting the request through would also mean running unscoped.
        // EnsureTenantBound should already have refused this request.
        if ($user->organization_id && $user->organization?->can($entitlement)) {
            return $next($request);
        }

        if (! $user->organization_id) {
            $message = 'This account is not attached to a workspace. Contact support.';

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                    'code' => 'workspace_missing',
                ], 403);
            }

            abort(403, $message);
        }

        // Name the capability using the operator's own label when the catalogue
        // knows it, so the message follows a renamed feature with no code edit.
        $entry = Entitlements::entry($entitlement);
        $label = $entry['label'] ?? null;
        $message = $label
            ? "{$label} is not included in your current plan."
            : 'This feature is not included in your current plan.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'code' => 'feature_not_in_plan',
                'feature' => $entitlement,
                'upgrade_url' => route('billing.index'),
            ], 403);
        }

        return Inertia::render('Upgrade/Locked', [
            'feature' => $entitlement,
            'name' => $label ?: 'This feature',
            'blurb' => $entry['help'] ?? null,
            'group' => $entry['group'] ?? null,
            'message' => $message,
            'billingUrl' => route('billing.index'),
            'offers' => self::offersFor($entitlement),
        ])->toResponse($request)->setStatusCode(403);
    }

    /**
     * The cheapest self-serve ways to obtain a capability: the least expensive
     * PUBLIC, unarchived plan whose entitlement map grants it, and the least
     * expensive public add-on that grants it.
     *
     * SECURITY: every figure here is read from the catalogue ROW. Nothing is
     * taken from the request — a client cannot name a plan, name a price, or
     * make a refusal advertise something the operator did not publish.
     *
     * SCALE: the catalogue is a handful of rows, the lookup runs only on the
     * (rare) refusal path, and the result is cached against the entitlement
     * stamp so a plan edit invalidates it immediately.
     *
     * @return array{plan: ?array<string, mixed>, addon: ?array<string, mixed>}
     */
    public static function offersFor(string $entitlement): array
    {
        // An unknown or retired key is granted by nothing — do not query at all
        // and do not imply the customer could buy their way in.
        if (Entitlements::entry($entitlement) === null) {
            return ['plan' => null, 'addon' => null];
        }

        $key = 'entitlements.offer.'.$entitlement.'.'.Entitlements::stamp();

        return Cache::remember($key, self::OFFER_TTL, function () use ($entitlement) {
            return [
                'plan' => self::cheapestPlanGranting($entitlement),
                'addon' => self::cheapestAddonGranting($entitlement),
            ];
        });
    }

    /** @return array<string, mixed>|null */
    private static function cheapestPlanGranting(string $entitlement): ?array
    {
        $best = null;

        // Bounded by construction: the plan catalogue is operator-authored and
        // tiny. `visible()` already excludes archived and non-public rows.
        Plan::query()->visible()->ordered()->get()
            ->each(function (Plan $plan) use ($entitlement, &$best) {
                if (! self::grants($plan->entitlements ?? [], $entitlement)) {
                    return;
                }

                if ($best === null || (int) $plan->price_cents < (int) $best->price_cents) {
                    $best = $plan;
                }
            });

        if ($best === null) {
            return null;
        }

        return [
            'kind' => 'plan',
            'name' => $best->name,
            'price_cents' => (int) $best->price_cents,
            'price_cents_yearly' => $best->price_cents_yearly === null ? null : (int) $best->price_cents_yearly,
            'currency' => $best->currency ?: 'USD',
        ];
    }

    /** @return array<string, mixed>|null */
    private static function cheapestAddonGranting(string $entitlement): ?array
    {
        $best = null;

        Addon::query()->visible()->ordered()->get()
            ->each(function (Addon $addon) use ($entitlement, &$best) {
                if (! self::grants($addon->entitlements ?? [], $entitlement)) {
                    return;
                }

                if ($best === null || (int) $addon->price_cents < (int) $best->price_cents) {
                    $best = $addon;
                }
            });

        if ($best === null) {
            return null;
        }

        return [
            'kind' => 'addon',
            'name' => $best->name,
            'description' => $best->description,
            'price_cents' => (int) $best->price_cents,
            'price_cents_yearly' => $best->price_cents_yearly === null ? null : (int) $best->price_cents_yearly,
            'currency' => $best->currency ?: 'USD',
        ];
    }

    /**
     * Does a stored entitlement map (plan or add-on) grant this key?
     *
     * Only ever asked about a route gate, i.e. a flag. A numeric ceiling is not
     * something a customer "unlocks", so a `max`/`min` key advertises nothing.
     *
     * @param  array<string, mixed>  $map
     */
    private static function grants(array $map, string $entitlement): bool
    {
        if (! Entitlements::isFlag($entitlement)) {
            return false;
        }

        return ($map[$entitlement] ?? null) === true;
    }
}
