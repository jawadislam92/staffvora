<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PricingRegion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REGION RESOLUTION — the ONE place that answers "what does a visitor from this
 * country get to see, and what does it cost?".
 *
 * Signup, checkout and the operator's simulator all call the SAME method, so the
 * price an operator previews is by construction the price a customer is charged.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * HOW A COUNTRY IS DETECTED — honestly
 * ─────────────────────────────────────────────────────────────────────────────
 * NO IP-geolocation service is wired into Staffvora, and this class does not
 * pretend one is. A country comes from, in order:
 *   1. an EXPLICIT choice the customer makes (a `country` field at signup, or
 *      the value already stored on their organization), then
 *   2. a country header, IF the edge in front of the app happens to set one
 *      (Cloudflare's CF-IPCountry, CloudFront-Viewer-Country, X-Vercel-IP-Country).
 *      This is a HINT used to pre-fill the choice — never a trust boundary: a
 *      client can send these headers itself, which is exactly why a region may
 *      only change price and visibility (see the security note below), and why
 *      the header list is opt-in via `config('platform.country_headers')`.
 *   3. nothing → null → the standard public catalogue.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * SECURITY — why a forged country is worthless
 * ─────────────────────────────────────────────────────────────────────────────
 * The country is attacker-controlled. Therefore, by construction:
 *   • A region NEVER contributes entitlements. `Organization::can()` does not
 *     read this class, this service does not read entitlements, and
 *     `pricing_regions` has no entitlements column.
 *   • A region can only SUBTRACT FROM or RE-PRICE the standard public catalogue
 *     (`Plan::visible()`). It can never surface a hidden / custom / legacy /
 *     archived plan, so claiming a country can never reveal a plan the operator
 *     has not published.
 *   • Every price is re-derived HERE from the plan row and the region row. A
 *     price in a request is never trusted, ever — `quote()` is the checkout's
 *     only legitimate source of an amount.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DETERMINISM — and the documented default
 * ─────────────────────────────────────────────────────────────────────────────
 * Candidate rules are the live (active, unarchived) rules whose country list
 * contains the country. The winner is chosen by, in strict order:
 *   1. LOWEST `priority`      — the operator's explicit control
 *   2. FEWEST countries       — a single-country rule beats a continent group
 *   3. LOWEST id              — the older rule, so the result never wobbles
 *
 * DEFAULT WHEN NOTHING MATCHES: FAIL OPEN to the standard public catalogue.
 * No country, an unknown country, no matching rule, or a rule that would leave
 * the visitor with ZERO plans → they see exactly the public catalogue at its
 * catalogue prices. A pricing rule must never be able to make the product
 * unbuyable.
 *
 * Every resolution carries a human-readable `reason` so the operator can always
 * answer "why is this visitor seeing these prices?".
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * SCALE
 * ─────────────────────────────────────────────────────────────────────────────
 * The whole rule set is tiny and read on hot paths (signup, checkout, pricing),
 * so it is loaded with TWO queries (regions + their plan rows), flattened to
 * plain arrays and cached under an epoch that every model write bumps. No query
 * per plan, no query per region, no Eloquent hydration in the cache.
 */
class RegionPricing
{
    /** Monotonic cache epoch; every rule write advances it. */
    public const EPOCH_KEY = 'pricing_regions.epoch';

    /** How long a resolved rule set may be served for (self-heals if a writer forgets to bump). */
    private const TTL = 300;

    /** Hard ceiling on countries in one rule, so a rule stays a rule. */
    public const MAX_COUNTRIES = 250;

    /**
     * Edge headers that may CARRY a country hint. Deliberately opt-in and
     * overridable — an installation behind no such proxy gets nothing, which is
     * the correct (fail-open) answer.
     *
     * @var array<int, string>
     */
    private const DEFAULT_COUNTRY_HEADERS = [
        'CF-IPCountry',              // Cloudflare
        'CloudFront-Viewer-Country', // AWS CloudFront
        'X-Vercel-IP-Country',       // Vercel
    ];

    /** Per-request memo: resolving twice in one request must not re-read cache. */
    private static ?array $memo = null;

    private static ?int $memoEpoch = null;

    // -----------------------------------------------------------------------
    // Country normalisation
    // -----------------------------------------------------------------------

    /**
     * The single normalisation rule: ISO-3166-1 alpha-2, uppercase. Anything
     * else (empty, "PAK", "P1", 40 characters of junk) becomes null, which
     * resolves to the standard public catalogue.
     */
    public static function normalizeCountry(mixed $country): ?string
    {
        if (! is_string($country)) {
            return null;
        }

        $country = strtoupper(trim($country));

        return preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;
    }

    /**
     * Normalise a list of codes: uppercase, valid-only, de-duplicated, bounded.
     *
     * @param  iterable<mixed>  $codes
     * @return array<int, string>
     */
    public static function normalizeCountryList(iterable $codes): array
    {
        $out = [];

        foreach ($codes as $code) {
            $code = self::normalizeCountry($code);

            if ($code !== null && ! in_array($code, $out, true)) {
                $out[] = $code;

                if (count($out) >= self::MAX_COUNTRIES) {
                    break;
                }
            }
        }

        sort($out);

        return $out;
    }

    /**
     * The country to use for THIS request: the customer's explicit choice first,
     * an edge header only as a pre-fill hint. Never an IP lookup.
     *
     * @param  string  $field  the explicit input field name
     */
    public static function countryFromRequest(Request $request, string $field = 'country'): ?string
    {
        $explicit = self::normalizeCountry($request->input($field));

        if ($explicit !== null) {
            return $explicit;
        }

        $headers = config('platform.country_headers', self::DEFAULT_COUNTRY_HEADERS);
        $headers = is_array($headers) ? $headers : self::DEFAULT_COUNTRY_HEADERS;

        foreach ($headers as $header) {
            $value = self::normalizeCountry($request->header($header));

            // Cloudflare sends "XX" when it cannot tell; normalisation lets it
            // through as a valid-looking code, so drop the known placeholders.
            if ($value !== null && ! in_array($value, ['XX', 'T1'], true)) {
                return $value;
            }
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Resolution
    // -----------------------------------------------------------------------

    /**
     * THE offer for a country: which plans are sold there and at what price.
     *
     * @return array{
     *     country: string|null,
     *     region: array{id:int,key:string,name:string,currency:string,offer_mode:string,priority:int}|null,
     *     fell_back: bool,
     *     reason: string,
     *     plans: array<int, array{plan_id:int,key:string,name:string,price_cents:int|null,price_cents_yearly:int|null,currency:string,price_source:string,max_users:int|null}>
     * }
     */
    public static function offer(mixed $country): array
    {
        $country = self::normalizeCountry($country);
        $catalogue = self::catalogue();
        $region = $country === null ? null : self::regionFor($country);

        if ($region === null) {
            return [
                'country' => $country,
                'region' => null,
                'fell_back' => false,
                'reason' => $country === null
                    ? 'No country was stated, so the standard public catalogue applies.'
                    : "No region rule covers {$country}, so the standard public catalogue applies.",
                'plans' => array_map(fn (array $plan) => self::catalogueEntry($plan), $catalogue),
            ];
        }

        $rules = $region['plans'];          // plan_id => rule row
        $offered = [];

        foreach ($catalogue as $plan) {
            $rule = $rules[$plan['id']] ?? null;

            // all_except  → offered unless a rule explicitly switches it off.
            // only_listed → offered only when a rule explicitly switches it on.
            $isOffered = $region['offer_mode'] === 'only_listed'
                ? ($rule !== null && $rule['is_offered'])
                : ($rule === null || $rule['is_offered']);

            if (! $isOffered) {
                continue;
            }

            $offered[] = self::catalogueEntry($plan, $rule, $region['currency']);
        }

        // FAIL OPEN. A rule that would leave a visitor with nothing to buy is a
        // misconfiguration, not a business decision — show the catalogue.
        if ($offered === []) {
            return [
                'country' => $country,
                'region' => self::regionSummary($region),
                'fell_back' => true,
                'reason' => "Region “{$region['name']}” matched {$country} but offers no plan at all, "
                    .'so the standard public catalogue applies instead.',
                'plans' => array_map(fn (array $plan) => self::catalogueEntry($plan), $catalogue),
            ];
        }

        return [
            'country' => $country,
            'region' => self::regionSummary($region),
            'fell_back' => false,
            'reason' => "Region “{$region['name']}” (rule “{$region['key']}”, priority {$region['priority']}) "
                ."matched {$country}: ".count($offered).' of '.count($catalogue)
                .' public plans offered, priced in '.$region['currency'].'.',
            'plans' => $offered,
        ];
    }

    /**
     * A CHECKOUT QUOTE for one plan — the only legitimate source of an amount.
     *
     * The caller passes the plan the customer picked and the country stored on
     * their organization; everything else is re-derived here. A plan the region
     * does not offer comes back `offered => false` with a price of null, so a
     * caller that forgets to check still cannot charge a number it invented.
     *
     * @param  string  $interval  'monthly' | 'yearly'
     * @return array{offered:bool,plan_id:int|null,price_cents:int|null,currency:string,interval:string,price_source:string,region:array|null,reason:string}
     */
    public static function quote(Plan|int|null $plan, mixed $country, string $interval = 'monthly'): array
    {
        $interval = $interval === 'yearly' ? 'yearly' : 'monthly';
        $planId = $plan instanceof Plan ? $plan->id : $plan;
        $offer = self::offer($country);

        $entry = null;
        foreach ($offer['plans'] as $candidate) {
            if ($candidate['plan_id'] === $planId) {
                $entry = $candidate;
                break;
            }
        }

        if ($entry === null) {
            return [
                'offered' => false,
                'plan_id' => $planId,
                'price_cents' => null,
                'currency' => 'USD',
                'interval' => $interval,
                'price_source' => 'none',
                'region' => $offer['region'],
                'reason' => 'That plan is not offered in this region. '.$offer['reason'],
            ];
        }

        return [
            'offered' => true,
            'plan_id' => $entry['plan_id'],
            'price_cents' => $interval === 'yearly' ? $entry['price_cents_yearly'] : $entry['price_cents'],
            'currency' => $entry['currency'],
            'interval' => $interval,
            'price_source' => $entry['price_source'],
            'region' => $offer['region'],
            'reason' => $offer['reason'],
        ];
    }

    /** Is this plan sellable to this country at all? */
    public static function planIsOffered(Plan|int|null $plan, mixed $country): bool
    {
        return self::quote($plan, $country)['offered'];
    }

    /**
     * Checkout guard. Throws the same ValidationException shape the rest of the
     * billing surface uses, so a blocked plan reads as a form error rather than
     * a 500.
     *
     * @return array the quote, so the caller charges the number we derived
     */
    public static function assertPlanOffered(Plan|int|null $plan, mixed $country, string $interval = 'monthly', string $field = 'plan'): array
    {
        $quote = self::quote($plan, $country, $interval);

        if (! $quote['offered']) {
            throw ValidationException::withMessages([
                $field => 'That plan is not available in your region. Please choose one of the plans shown to you.',
            ]);
        }

        return $quote;
    }

    /**
     * The rule that wins for a country, or null. Deterministic: priority, then
     * specificity (fewest countries), then the older row.
     *
     * @return array<string, mixed>|null the flattened rule
     */
    public static function regionFor(mixed $country): ?array
    {
        $country = self::normalizeCountry($country);

        if ($country === null) {
            return null;
        }

        $candidates = array_values(array_filter(
            self::rules(),
            fn (array $region) => in_array($country, $region['countries'], true),
        ));

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $a, array $b) {
            return [$a['priority'], count($a['countries']), $a['id']]
                <=> [$b['priority'], count($b['countries']), $b['id']];
        });

        return $candidates[0];
    }

    /** The region rule a company falls under, for the operator's tenant view. */
    public static function regionForOrganization(?object $organization): ?array
    {
        return self::regionFor($organization?->country ?? null);
    }

    // -----------------------------------------------------------------------
    // Data loading (2 queries, cached, plain arrays)
    // -----------------------------------------------------------------------

    /**
     * The live rule set, flattened.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rules(): array
    {
        $epoch = self::epoch();

        if (self::$memo !== null && self::$memoEpoch === $epoch) {
            return self::$memo;
        }

        try {
            $rules = Cache::remember('pricing_regions.rules.'.$epoch, self::TTL, fn () => self::loadRules());
        } catch (\Throwable $e) {
            // A missing/broken cache store must never take pricing down.
            $rules = self::loadRules();
        }

        self::$memo = $rules;
        self::$memoEpoch = $epoch;

        return $rules;
    }

    /** @return array<int, array<string, mixed>> */
    private static function loadRules(): array
    {
        try {
            $regions = DB::table('pricing_regions')
                ->where('is_active', true)
                ->whereNull('archived_at')
                ->orderBy('priority')
                ->orderBy('id')
                ->get();
        } catch (\Throwable $e) {
            return []; // table not migrated yet → fail open
        }

        if ($regions->isEmpty()) {
            return [];
        }

        // ONE query for every region's plan rows — never one query per region.
        try {
            $planRows = DB::table('pricing_region_plans')
                ->whereIn('pricing_region_id', $regions->pluck('id'))
                ->get()
                ->groupBy('pricing_region_id');
        } catch (\Throwable $e) {
            $planRows = collect();
        }

        return $regions->map(function ($region) use ($planRows) {
            $codes = json_decode((string) ($region->country_codes ?? '[]'), true);

            $plans = [];
            foreach ($planRows->get($region->id, collect()) as $row) {
                $plans[(int) $row->plan_id] = [
                    'plan_id' => (int) $row->plan_id,
                    'is_offered' => (bool) $row->is_offered,
                    'price_cents' => $row->price_cents === null ? null : (int) $row->price_cents,
                    'price_cents_yearly' => $row->price_cents_yearly === null ? null : (int) $row->price_cents_yearly,
                ];
            }

            return [
                'id' => (int) $region->id,
                'key' => (string) $region->key,
                'name' => (string) $region->name,
                'currency' => strtoupper((string) ($region->currency ?: 'USD')),
                'offer_mode' => in_array($region->offer_mode, PricingRegion::OFFER_MODES, true)
                    ? $region->offer_mode
                    : 'all_except',
                'priority' => (int) $region->priority,
                'countries' => self::normalizeCountryList(is_array($codes) ? $codes : []),
                'plans' => $plans,
            ];
        })->all();
    }

    /**
     * THE BASE CATALOGUE — the standard public plans, in the operator's order.
     * A region can only subtract from or re-price THIS set; it can never add to
     * it, which is what makes a forged country worthless.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function catalogue(): array
    {
        try {
            return Plan::visible()->ordered()->get()
                ->map(fn (Plan $plan) => [
                    'id' => $plan->id,
                    'key' => $plan->key,
                    'name' => $plan->name,
                    'price_cents' => $plan->price_cents === null ? null : (int) $plan->price_cents,
                    'price_cents_yearly' => $plan->price_cents_yearly === null ? null : (int) $plan->price_cents_yearly,
                    'currency' => strtoupper($plan->currency ?: 'USD'),
                    'max_users' => $plan->max_users,
                ])
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * One plan entry in an offer. With no region rule (or no local price on it)
     * the plan keeps its own catalogue price AND its own currency — a region
     * currency is only claimed when a local price is actually set, so a region
     * can never relabel a USD price as PKR.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>|null  $rule
     */
    private static function catalogueEntry(array $plan, ?array $rule = null, ?string $regionCurrency = null): array
    {
        $hasLocalMonthly = $rule !== null && $rule['price_cents'] !== null;
        $hasLocalYearly = $rule !== null && $rule['price_cents_yearly'] !== null;
        $hasLocal = $hasLocalMonthly || $hasLocalYearly;

        return [
            'plan_id' => $plan['id'],
            'key' => $plan['key'],
            'name' => $plan['name'],
            'price_cents' => $hasLocalMonthly ? $rule['price_cents'] : $plan['price_cents'],
            'price_cents_yearly' => $hasLocalYearly ? $rule['price_cents_yearly'] : $plan['price_cents_yearly'],
            'currency' => $hasLocal ? ($regionCurrency ?: $plan['currency']) : $plan['currency'],
            'price_source' => $hasLocal ? 'region' : 'catalogue',
            'max_users' => $plan['max_users'],
        ];
    }

    /** @param  array<string, mixed>  $region */
    private static function regionSummary(array $region): array
    {
        return [
            'id' => $region['id'],
            'key' => $region['key'],
            'name' => $region['name'],
            'currency' => $region['currency'],
            'offer_mode' => $region['offer_mode'],
            'priority' => $region['priority'],
        ];
    }

    // -----------------------------------------------------------------------
    // Cache control
    // -----------------------------------------------------------------------

    private static function epoch(): int
    {
        try {
            return (int) Cache::get(self::EPOCH_KEY, 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Invalidate the rule set — called automatically by every model write. */
    public static function bump(): void
    {
        self::$memo = null;
        self::$memoEpoch = null;

        try {
            // Portable "increment or start at 1": not every cache store supports
            // Cache::increment, and only the fact that it MOVES matters.
            Cache::forever(self::EPOCH_KEY, self::epoch() + 1);
        } catch (\Throwable $e) {
            // A missing cache store must never break a write.
        }
    }

    /** Drop the per-request memo (tests, queue workers, Octane). */
    public static function flushMemo(): void
    {
        self::$memo = null;
        self::$memoEpoch = null;
    }
}
