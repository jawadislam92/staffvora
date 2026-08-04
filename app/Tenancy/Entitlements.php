<?php

namespace App\Tenancy;

use App\Models\Feature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The canonical catalogue of every gated capability ("entitlement") in the
 * product, and the rules for resolving an effective value.
 *
 * THIS IS THE ONLY ACCESS-DECISION PATH. Nothing anywhere may branch on a plan
 * name, a plan key, an add-on key or `organizations.workspace_type`; everything
 * asks this engine for a value. There is exactly one gate on top of it
 * (`EnsureEntitlement`) and one UI mirror of it (the shared `entitlements`
 * Inertia prop) — both read the same resolution.
 *
 * ─── WHERE THE CATALOGUE LIVES ───────────────────────────────────────────────
 * The catalogue is the `features` TABLE (see `App\Models\Feature`), so the owner
 * can add, rename, re-group, re-order, retire or re-default a feature from the
 * operator panel with no code change. It is cached (`Feature::catalogue()`) plus
 * memoised per request, because it is read on essentially every request.
 *
 * The `REGISTRY` constant below is now only the BOOT FALLBACK: it is used when
 * the `features` table does not exist or is empty (a fresh checkout that has not
 * migrated yet), so the app can always boot. It is not the source of truth.
 *
 * ─── KINDS ───────────────────────────────────────────────────────────────────
 *   flag : boolean.
 *   max  : integer CEILING. A tenant setting may not exceed it.
 *          `null` means UNLIMITED — never "zero", never "deny".
 *   min  : integer FLOOR. A tenant setting may not go below it (e.g. auto-pause
 *          minutes: cheaper plans are forced to a gentler cadence).
 *          `null` means NO FLOOR.
 *
 * ─── RESOLUTION ORDER ────────────────────────────────────────────────────────
 *      per-company override  →  active add-ons  →  plan  →  catalogue default
 *
 * and the catalogue default is DENY (false / 0 / a strict floor), so anything
 * nobody granted is refused. A key that is unknown or has been DEACTIVATED in
 * the catalogue is denied outright — it never even reads the plan, so switching
 * a feature off in the panel revokes it everywhere at once.
 *
 * ─── HOW AN OVERRIDE IS STORED (organizations.entitlement_overrides) ─────────
 * A map of feature key → value. A key that is ABSENT means "inherit" (fall
 * through to add-ons / plan). The value is either a scalar or an envelope:
 *
 *   "ai": true                      grant
 *   "ai": false                     REVOKE (beats an add-on and the plan)
 *   "max_users": 250                a raised (or lowered) ceiling
 *   "max_users": "unlimited"        no ceiling at all — distinct from "inherit"
 *   "machine_compliance": {         a TEMPORARY grant: ignored once it expires,
 *       "value": true,              at which point resolution simply falls
 *       "expires_at": "2026-09-01T00:00:00+00:00",
 *       "reason": "Sales trial"     through to add-ons / plan again
 *   }
 *
 * `expires_at` is an ISO-8601 string (UTC recommended); `reason` is free text
 * for the operator's own record. Both are optional; a bare scalar is permanent.
 *
 * ─── HOW AN ADD-ON CONTRIBUTES (addons.entitlements) ─────────────────────────
 * Exactly the same map shape as `plans.entitlements`. An add-on is NOT a second
 * gate — it is just another layer this one resolver folds in. A key the add-on
 * omits (or sets to `null`/`""`) says nothing and falls through; to lift a
 * ceiling an add-on stores the string `"unlimited"`. When a company holds
 * several add-ons that speak about the same key, the MOST PERMISSIVE value wins
 * — add-ons are things the customer bought, so they may only ever add.
 */
class Entitlements
{
    /**
     * BOOT FALLBACK ONLY — the live catalogue is the `features` table.
     * Kept so a fresh, unmigrated checkout still boots (and still denies).
     *
     * @var array<string, array{kind:string, default:int|bool, group:string, label:string, help:string, unit?:string}>
     */
    public const REGISTRY = [
        // Seats
        'max_users' => [
            'kind' => 'max', 'default' => 1, 'group' => 'Limits', 'unit' => 'seats',
            'label' => 'User seats', 'help' => 'Maximum number of users in the workspace (blank = unlimited).',
        ],

        // Monitoring caps
        'max_screenshots_per_hour' => [
            'kind' => 'max', 'default' => 0, 'group' => 'Monitoring', 'unit' => '/hour',
            'label' => 'Screenshots per hour (max)', 'help' => 'The highest screenshot rate this plan may set.',
        ],
        'min_auto_pause_minutes' => [
            'kind' => 'min', 'default' => 10, 'group' => 'Monitoring', 'unit' => 'min',
            'label' => 'Auto-pause floor', 'help' => 'The tightest idle auto-pause this plan may set (lower = more aggressive). Free is forced to a gentler floor.',
        ],
        'max_retention_days' => [
            'kind' => 'max', 'default' => 7, 'group' => 'Monitoring', 'unit' => 'days',
            'label' => 'Screenshot retention (max days)', 'help' => 'How long screenshots may be kept.',
        ],

        // Feature flags
        'monitoring' => [
            'kind' => 'flag', 'default' => false, 'group' => 'Features',
            'label' => 'Screenshot monitoring', 'help' => 'Capture desktop screenshots at all.',
        ],
        'activity_tracking' => [
            'kind' => 'flag', 'default' => false, 'group' => 'Features',
            'label' => 'Activity tracking', 'help' => 'Keyboard/mouse activity percentage.',
        ],
        'app_url_tracking' => [
            'kind' => 'flag', 'default' => false, 'group' => 'Features',
            'label' => 'App & URL tracking', 'help' => 'Record active application and browser URL.',
        ],
        'weekly_limit' => [
            'kind' => 'flag', 'default' => false, 'group' => 'Features',
            'label' => 'Weekly time limit', 'help' => 'Allow setting a weekly hours cap per user.',
        ],
        'attendance_alerts' => [
            'kind' => 'flag', 'default' => false, 'group' => 'Features',
            'label' => 'Attendance Slack alerts', 'help' => 'Notify Slack on clock-in / clock-out.',
        ],
        'ai' => [
            'kind' => 'flag', 'default' => false, 'group' => 'Features',
            'label' => 'AI assistant', 'help' => 'AI day-summary and assistant features.',
        ],
        'integrations' => [
            'kind' => 'flag', 'default' => false, 'group' => 'Features',
            'label' => 'Integrations', 'help' => 'Connect the workspace\'s own Slack bot.',
        ],
        'api' => [
            'kind' => 'flag', 'default' => false, 'group' => 'Features',
            'label' => 'API access', 'help' => 'Programmatic API access.',
        ],
        'white_label' => [
            'kind' => 'flag', 'default' => false, 'group' => 'Branding',
            'label' => 'White-label branding', 'help' => 'Custom logo, name, accent and theme across the app.',
        ],
    ];

    /**
     * The stored value that means "no ceiling / no floor" on a numeric key.
     * Deliberately a STRING, because `null` and an absent key are already taken:
     * absent = inherit, and in a plan map `null` is unlimited. On a per-company
     * override we need "unlimited" to be expressible WITHOUT it colliding with
     * "inherit", and JSON gives us no third empty value.
     */
    public const UNLIMITED = 'unlimited';

    /** Cache key for the DERIVED half of the catalogue freshness stamp (see stamp()). */
    public const STAMP_KEY = 'entitlements.catalogue.stamp';

    /** Cache key for the MONOTONIC half — bumped explicitly on every write. */
    public const EPOCH_KEY = 'entitlements.catalogue.epoch';

    /** Seconds the freshness stamp is trusted before it is re-derived. */
    private const STAMP_TTL = 30;

    /**
     * Internal sentinel for "this layer says nothing about the key". It cannot
     * be `null`, because `null` is a MEANINGFUL resolved value (unlimited).
     */
    private const SAYS_NOTHING = "\0entitlement-says-nothing";

    /** Per-request memo of the catalogue — it is read dozens of times a request. */
    private static ?array $memo = null;

    /** The epoch the memo was built at, so a long-lived process can notice a change. */
    private static ?int $memoEpoch = null;

    // -----------------------------------------------------------------------
    // The catalogue
    // -----------------------------------------------------------------------

    /**
     * The live catalogue, keyed by feature key: the `features` table when it has
     * rows, else the REGISTRY constant so a fresh install still boots.
     *
     * @return array<string, array{key:string, kind:string, default:int|bool|null, group:string, label:string, help:string|null, unit:string|null, sort:int}>
     */
    public static function registry(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $catalogue = [];

        try {
            $catalogue = Feature::catalogue();
        } catch (\Throwable $e) {
            // No table yet / DB unavailable — fall through to the constant.
            $catalogue = [];
        }

        return self::$memo = ($catalogue !== [] ? $catalogue : self::fallbackRegistry());
    }

    /** The REGISTRY constant normalised into catalogue shape. */
    private static function fallbackRegistry(): array
    {
        $out = [];
        $sort = 0;
        foreach (self::REGISTRY as $key => $entry) {
            $out[$key] = [
                'key' => $key,
                'kind' => $entry['kind'],
                'default' => $entry['default'],
                'group' => $entry['group'],
                'label' => $entry['label'],
                'help' => $entry['help'] ?? null,
                'unit' => $entry['unit'] ?? null,
                'sort' => $sort += 10,
            ];
        }

        return $out;
    }

    /** All ACTIVE entitlement keys, in display order. */
    public static function keys(): array
    {
        return array_keys(self::registry());
    }

    /** The catalogue entry for a key, or null when it is unknown or retired. */
    public static function entry(string $key): ?array
    {
        return self::registry()[$key] ?? null;
    }

    /** Everything grouped for the plan / override editors: ['Limits' => […], …]. */
    public static function grouped(): array
    {
        $out = [];
        foreach (self::registry() as $entry) {
            $out[$entry['group']][] = $entry;
        }

        return $out;
    }

    /**
     * A key's kind. Falls back to the BOOT REGISTRY for a key that has been
     * RETIRED from the live catalogue, so a retired numeric limit is still
     * known to be a limit rather than degrading into an untyped `false` — see
     * {@see resolveWithSource()} for why that mattered.
     */
    public static function kind(string $key): ?string
    {
        return self::registry()[$key]['kind'] ?? self::REGISTRY[$key]['kind'] ?? null;
    }

    /**
     * The default-DENY value for an entitlement (false / 0 / a strict floor).
     *
     * A key nobody has ever heard of returns `false` — deny — and never `null`,
     * because on a numeric key `null` means UNLIMITED.
     *
     * A RETIRED numeric limit is the exception, and it is a deliberate one: a
     * ceiling that has been switched off is *absent*, and an absent ceiling is
     * no ceiling. Returning `0`/`false` here is what let one operator toggle
     * lock every tenant out of hiring at once (see `resolveWithSource()`).
     */
    public static function default(string $key): int|bool|null
    {
        $entry = self::registry()[$key] ?? null;

        if ($entry !== null) {
            return $entry['default'];
        }

        return self::isNumeric($key) ? null : false;
    }

    public static function isFlag(string $key): bool
    {
        return self::kind($key) === 'flag';
    }

    public static function isNumeric(string $key): bool
    {
        return in_array(self::kind($key), ['max', 'min'], true);
    }

    // -----------------------------------------------------------------------
    // Resolution — the one decision path
    // -----------------------------------------------------------------------

    /**
     * Resolve the effective value:
     *   per-company override → active add-ons → plan → catalogue default (deny).
     *
     * @param  bool  $hasPlan  whether the company has an active plan at all
     * @param  mixed  $planValue  the plan's value for this key
     * @param  mixed  $override  the raw stored override (scalar OR envelope, see the class docblock)
     * @param  array<int, array<string, mixed>>  $addonValues  each active add-on's entitlement map
     */
    public static function resolve(string $key, bool $hasPlan, mixed $planValue, mixed $override, array $addonValues = []): int|bool|null
    {
        return self::resolveWithSource($key, $hasPlan, $planValue, $override, $addonValues)['value'];
    }

    /**
     * The same resolution, but it also reports WHICH layer decided — so the
     * operator panel can show "granted by add-on" / "overridden for this
     * company" without ever re-implementing the rules.
     *
     * @return array{value:int|bool|null, source:string} source: override|addon|plan|default|unknown
     */
    public static function resolveWithSource(string $key, bool $hasPlan, mixed $planValue, mixed $override, array $addonValues = []): array
    {
        $isActiveKey = isset(self::registry()[$key]);

        // A CAPABILITY (flag) that is not in the ACTIVE catalogue is denied
        // outright — it never reads the plan. Retiring a feature in the panel
        // therefore revokes it everywhere immediately, even for plans whose JSON
        // still grants it. Same for a key nobody has ever heard of.
        //
        // 🔴 A LIMIT IS THE OPPOSITE CASE, and getting this wrong took the
        // product down in review. `max`/`min` keys are CEILINGS and FLOORS, not
        // grants: denying one does not withhold a capability, it sets the
        // ceiling to zero. Because an unknown key returned `false` and
        // `Organization::seatLimit()` cast that to `0`, deactivating the
        // `max_users` Feature row flipped every tenant's seat limit 2 → 0 at
        // once — every new hire, every reactivation and every onboarding invite
        // refused, platform-wide, from a single operator toggle.
        //
        // So a retired LIMIT keeps resolving through the normal layers
        // (override → add-on → plan), and if no layer speaks it is UNLIMITED.
        // A limit that vanished is not a limit of zero.
        if (! $isActiveKey && ! self::isNumeric($key)) {
            return ['value' => self::cast($key, self::default($key)), 'source' => 'unknown'];
        }

        $fromOverride = self::overrideValue($override);
        if ($fromOverride !== self::SAYS_NOTHING) {
            return ['value' => self::cast($key, $fromOverride), 'source' => 'override'];
        }

        $fromAddons = self::addonValue($key, $addonValues);
        if ($fromAddons !== self::SAYS_NOTHING) {
            return ['value' => self::cast($key, $fromAddons), 'source' => 'addon'];
        }

        if ($hasPlan) {
            return ['value' => self::cast($key, $planValue), 'source' => 'plan'];
        }

        // A retired limit with nothing left to say is unlimited, never zero.
        if (! $isActiveKey) {
            return ['value' => null, 'source' => 'unknown'];
        }

        return ['value' => self::cast($key, self::default($key)), 'source' => 'default'];
    }

    /**
     * Unwrap a stored override into the value it contributes, or SAYS_NOTHING
     * when it is absent, blank or EXPIRED. An expired grant is simply invisible:
     * resolution carries on to the add-ons and the plan.
     */
    private static function overrideValue(mixed $stored): mixed
    {
        if (is_array($stored)) {
            if (! array_key_exists('value', $stored)) {
                return self::SAYS_NOTHING;
            }

            $expiresAt = $stored['expires_at'] ?? null;
            if ($expiresAt !== null && $expiresAt !== '' && self::hasExpired($expiresAt)) {
                return self::SAYS_NOTHING;
            }

            $stored = $stored['value'];
        }

        if ($stored === null || $stored === '') {
            return self::SAYS_NOTHING;
        }

        return $stored;
    }

    /** Is this ISO-8601 timestamp in the past? An unparseable one is treated as expired (fail closed). */
    private static function hasExpired(mixed $expiresAt): bool
    {
        try {
            return Carbon::parse($expiresAt)->isPast();
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * What the company's active add-ons contribute for a key. When more than one
     * speaks, the MOST PERMISSIVE wins — an add-on is bought capability, so it
     * may only ever add.
     *
     * @param  array<int, array<string, mixed>>  $maps
     */
    private static function addonValue(string $key, array $maps): mixed
    {
        $best = self::SAYS_NOTHING;

        foreach ($maps as $map) {
            if (! is_array($map) || ! array_key_exists($key, $map)) {
                continue;
            }

            $raw = $map[$key];
            // In an ADD-ON map, null/"" says nothing (use "unlimited" to lift a
            // ceiling) — otherwise an add-on could silently cap a plan at null.
            if ($raw === null || $raw === '') {
                continue;
            }

            $value = self::cast($key, $raw);
            $best = $best === self::SAYS_NOTHING ? $value : self::morePermissive($key, $best, $value);
        }

        return $best;
    }

    /** The more generous of two already-cast values for a key. */
    private static function morePermissive(string $key, int|bool|null $a, int|bool|null $b): int|bool|null
    {
        $kind = self::kind($key);

        if ($kind === 'flag') {
            return ((bool) $a) || ((bool) $b);
        }

        // null = unlimited ceiling / no floor: the most permissive value there is.
        if ($a === null || $b === null) {
            return null;
        }

        return $kind === 'min' ? min((int) $a, (int) $b) : max((int) $a, (int) $b);
    }

    /**
     * Normalise a raw value to its kind: flags → bool, numerics → int with
     * `null` (and the "unlimited" sentinel) preserved as UNLIMITED.
     *
     * An unknown key casts to `false` — deny — never `null`, so a stray key can
     * never resolve to "unlimited".
     */
    public static function cast(string $key, mixed $value): int|bool|null
    {
        $kind = self::kind($key);

        if ($kind === 'flag') {
            return (bool) $value;
        }

        if ($kind === null) {
            return false;
        }

        if ($value === null || self::meansUnlimited($value)) {
            return null;
        }

        // A blank numeric is NOT "unlimited" — that would be a silent grant.
        // Fall back to the catalogue's strict default instead.
        if ($value === '') {
            $default = self::registry()[$key]['default'] ?? 0;

            return is_numeric($default) ? (int) $default : 0;
        }

        return (int) $value;
    }

    /** Does this raw value express "no limit"? */
    public static function meansUnlimited(mixed $value): bool
    {
        return is_string($value) && strtolower(trim($value)) === self::UNLIMITED;
    }

    /**
     * Is a requested integer setting allowed by the effective entitlement?
     * For `max`, requested must be ≤ effective. For `min`, requested must be ≥
     * effective. A null effective value means unlimited / no floor.
     */
    public static function allowsValue(string $key, int $requested, int|bool|null $effective): bool
    {
        $kind = self::kind($key);

        if ($kind === 'max') {
            return $effective === null || $requested <= (int) $effective;
        }
        if ($kind === 'min') {
            return $effective === null || $requested >= (int) $effective;
        }

        return true;
    }

    // -----------------------------------------------------------------------
    // Sanitisers — the ONE place operator input becomes stored entitlement data
    // -----------------------------------------------------------------------

    /**
     * Clean the operator's per-company override form into storage shape.
     *
     * Rules (kind-aware — this is the fix for the floor-field bug):
     *   • unknown / retired key            → dropped
     *   • blank ("" or null) on ANY kind   → dropped = INHERIT.
     *                                        It must NEVER become `0`: on a
     *                                        `min` key 0 is the MOST permissive
     *                                        floor there is, so blanking a field
     *                                        used to silently grant.
     *   • flag `false`                     → KEPT. That is a deliberate REVOKE
     *                                        and it beats add-ons and the plan.
     *   • "unlimited" on a numeric key     → kept as the "unlimited" sentinel,
     *                                        which is NOT the same as blank.
     *   • an envelope {value, expires_at, reason} → kept, so a grant can expire
     *                                        by itself.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> storage-shaped overrides (may be empty)
     */
    public static function sanitizeOverrides(array $input): array
    {
        $clean = [];

        foreach ($input as $key => $raw) {
            if (! is_string($key) || ! isset(self::registry()[$key])) {
                continue;
            }

            $expiresAt = null;
            $reason = null;
            $value = $raw;

            if (is_array($raw)) {
                if (! array_key_exists('value', $raw)) {
                    continue;
                }
                $value = $raw['value'];
                $expiresAt = self::normaliseExpiry($raw['expires_at'] ?? null);
                $reason = self::normaliseReason($raw['reason'] ?? null);
            }

            // Blank = inherit, for every kind. Never coerce to 0.
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if (self::isFlag($key)) {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($value === null) {
                    continue;
                }
            } elseif (self::meansUnlimited($value)) {
                $value = self::UNLIMITED;
            } else {
                if (! is_numeric($value)) {
                    continue;
                }
                $value = max(0, (int) $value);
            }

            $clean[$key] = ($expiresAt === null && $reason === null)
                ? $value
                : array_filter(
                    ['value' => $value, 'expires_at' => $expiresAt, 'reason' => $reason],
                    fn ($v) => $v !== null,
                );
        }

        return $clean;
    }

    /**
     * Clean the operator's PLAN (or add-on) entitlement form into storage shape.
     * Every active key is written, so the stored map is always complete.
     *
     *   • flag                       → strict boolean
     *   • max, blank or "unlimited"  → `null` = UNLIMITED (the field help says so)
     *   • min, blank                 → the key is OMITTED, so it inherits the
     *                                  catalogue's strict default. A floor must
     *                                  never fall to 0 just because a box was
     *                                  cleared; to allow "no floor" the operator
     *                                  types "unlimited" explicitly.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, int|bool|null>
     */
    public static function sanitizePlanValues(array $input): array
    {
        $clean = [];

        foreach (self::registry() as $key => $entry) {
            $value = $input[$key] ?? null;
            $kind = $entry['kind'];

            if ($kind === 'flag') {
                $clean[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);

                continue;
            }

            $blank = $value === null || $value === '' || $value === [];

            if (self::meansUnlimited($value)) {
                $clean[$key] = null;

                continue;
            }

            if ($blank) {
                // A ceiling left blank means "no ceiling"; a floor left blank
                // means "inherit the strict default" and is therefore omitted.
                if ($kind === 'max') {
                    $clean[$key] = null;
                }

                continue;
            }

            $clean[$key] = is_numeric($value) ? max(0, (int) $value) : self::default($key);
        }

        return $clean;
    }

    /** ISO-8601 (UTC) or null. Unparseable input is discarded rather than stored. */
    private static function normaliseExpiry(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc()->toIso8601String();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function normaliseReason(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 500);
    }

    /**
     * Read the expiry/reason recorded on a stored override, for the operator UI.
     *
     * @return array{value:mixed, expires_at:string|null, reason:string|null, expired:bool}
     */
    public static function describeOverride(mixed $stored): array
    {
        $expiresAt = null;
        $reason = null;
        $value = $stored;

        if (is_array($stored)) {
            $value = $stored['value'] ?? null;
            $expiresAt = $stored['expires_at'] ?? null;
            $reason = $stored['reason'] ?? null;
        }

        return [
            'value' => $value,
            'expires_at' => $expiresAt ?: null,
            'reason' => $reason ?: null,
            'expired' => $expiresAt ? self::hasExpired($expiresAt) : false,
        ];
    }

    // -----------------------------------------------------------------------
    // Cache control for the shared `entitlements` Inertia prop
    // -----------------------------------------------------------------------

    /** The per-company cache key for the resolved map shared with the UI. */
    public static function sharedCacheKey(int $organizationId): string
    {
        return 'entitlements.shared.'.$organizationId;
    }

    /** Drop one company's cached map (call after changing ITS overrides/add-ons). */
    public static function forgetForOrganization(int $organizationId): void
    {
        try {
            Cache::forget(self::sharedCacheKey($organizationId));
        } catch (\Throwable $e) {
            // Never let a cache store take a write down with it.
        }
    }

    /**
     * A cheap freshness token for the whole catalogue. Every cached resolved map
     * is stored WITH the stamp it was built from and thrown away when the stamp
     * moves, so one plan/feature/add-on edit invalidates every company at once
     * without having to enumerate them.
     *
     * It has two halves:
     *   • a MONOTONIC epoch that `bump()` advances, so an operator write lands
     *     on the very next request. This half is essential: `updated_at` is
     *     stored to the SECOND, so two edits inside one second would otherwise
     *     derive an identical stamp and keep serving the stale map.
     *   • a DERIVED digest (row counts + max updated_at) held for a few seconds,
     *     which self-heals if some writer ever forgets to call bump().
     */
    public static function stamp(): string
    {
        try {
            $epoch = (int) Cache::get(self::EPOCH_KEY, 0);

            // A worker or Octane process outlives the request that memoised the
            // catalogue; if another process bumped the epoch, drop the memo so
            // this one stops serving a retired feature.
            if (self::$memoEpoch !== null && self::$memoEpoch !== $epoch) {
                self::flushMemo();
            }
            self::$memoEpoch = $epoch;

            $derived = (string) Cache::remember(self::STAMP_KEY, self::STAMP_TTL, fn () => self::deriveStamp());

            return $epoch.':'.$derived;
        } catch (\Throwable $e) {
            return 'nostamp';
        }
    }

    private static function deriveStamp(): string
    {
        $parts = [];

        foreach (['features', 'plans', 'addons', 'organization_addon'] as $table) {
            try {
                $row = DB::table($table)
                    ->selectRaw('COUNT(*) as c, MAX(updated_at) as m')
                    ->first();
                $parts[] = $table.':'.($row->c ?? 0).':'.($row->m ?? '');
            } catch (\Throwable $e) {
                $parts[] = $table.':na';
            }
        }

        return substr(md5(implode('|', $parts)), 0, 16);
    }

    /**
     * Invalidate everything derived from the catalogue — call this after ANY
     * write to a feature, plan, add-on or add-on grant so the change is visible
     * on the very next request instead of within the stamp TTL.
     */
    public static function bump(): void
    {
        self::flushMemo();

        try {
            Feature::flushCatalogue();
            Cache::forget(self::STAMP_KEY);
            // Portable "increment or start at 1" — Cache::increment is not
            // supported by every store and the exact number never matters, only
            // that it MOVES.
            Cache::forever(self::EPOCH_KEY, ((int) Cache::get(self::EPOCH_KEY, 0)) + 1);
        } catch (\Throwable $e) {
            // A missing cache store must never break a write.
        }
    }

    /** Drop the per-request catalogue memo (tests and long-running workers). */
    public static function flushMemo(): void
    {
        self::$memo = null;
        self::$memoEpoch = null;
    }

    // -----------------------------------------------------------------------
    // Presentation
    // -----------------------------------------------------------------------

    /**
     * A human-readable breakdown of a resolved entitlement map, for plan cards
     * and the billing page: numeric caps as labelled lines, feature flags as
     * on/off rows. Driven off the live catalogue, so a feature the owner adds
     * from the panel shows up here with no code change.
     *
     * @param  array<string, mixed>  $map  a full resolved entitlement map
     * @return array{caps: array<int, array{label:string, value:string}>, flags: array<int, array{label:string, on:bool}>}
     */
    public static function describe(array $map): array
    {
        $caps = [];
        $flags = [];

        foreach (self::registry() as $key => $entry) {
            if ($entry['kind'] === 'flag') {
                $flags[] = ['label' => $entry['label'], 'on' => (bool) ($map[$key] ?? false)];

                continue;
            }

            // array_key_exists, NOT ??: a stored `null` MEANS unlimited and must
            // not be mistaken for "absent" and replaced by the deny default.
            $value = array_key_exists($key, $map) ? $map[$key] : self::default($key);
            $caps[] = ['label' => $entry['label'], 'value' => self::describeCap($entry, $value)];
        }

        return ['caps' => $caps, 'flags' => $flags];
    }

    /** One cap rendered for a customer: "Unlimited" / "Not included" / "12 /hour". */
    private static function describeCap(array $entry, mixed $value): string
    {
        if ($value === null || self::meansUnlimited($value)) {
            return 'Unlimited';
        }

        $value = (int) $value;

        if ($entry['kind'] === 'max' && $value === 0) {
            return 'Not included';
        }

        $unit = $entry['unit'] ?? null;
        $rendered = $unit ? $value.' '.$unit : (string) $value;

        return $entry['kind'] === 'min' ? 'from '.$rendered : $rendered;
    }

    /**
     * The full plan-level entitlement map for a named plan tier, used ONLY to
     * seed a fresh install (see `Plan::defaults()`, which is the live seed).
     * Nothing in the application may branch on these keys at runtime.
     *
     * @return array<string, int|bool|null>
     */
    public static function planDefaults(string $key): array
    {
        return match ($key) {
            'free' => [
                'max_users' => 3,
                'max_screenshots_per_hour' => 6,
                'min_auto_pause_minutes' => 10,
                'max_retention_days' => 14,
                'monitoring' => true,
                'activity_tracking' => false,
                'app_url_tracking' => false,
                'weekly_limit' => false,
                'attendance_alerts' => false,
                'ai' => false,
                'integrations' => false,
                'api' => false,
                'white_label' => false,
            ],
            'starter' => [
                'max_users' => 10,
                'max_screenshots_per_hour' => 12,
                'min_auto_pause_minutes' => 5,
                'max_retention_days' => 30,
                'monitoring' => true,
                'activity_tracking' => true,
                'app_url_tracking' => true,
                'weekly_limit' => true,
                'attendance_alerts' => true,
                'ai' => false,
                'integrations' => true,
                'api' => false,
                'white_label' => false,
            ],
            'pro' => [
                'max_users' => 50,
                'max_screenshots_per_hour' => 30,
                'min_auto_pause_minutes' => 1,
                'max_retention_days' => 90,
                'monitoring' => true,
                'activity_tracking' => true,
                'app_url_tracking' => true,
                'weekly_limit' => true,
                'attendance_alerts' => true,
                'ai' => true,
                'integrations' => true,
                'api' => true,
                'white_label' => true,
            ],
            'enterprise' => [
                'max_users' => null,
                'max_screenshots_per_hour' => 60,
                'min_auto_pause_minutes' => 1,
                'max_retention_days' => 365,
                'monitoring' => true,
                'activity_tracking' => true,
                'app_url_tracking' => true,
                'weekly_limit' => true,
                'attendance_alerts' => true,
                'ai' => true,
                'integrations' => true,
                'api' => true,
                'white_label' => true,
            ],
            default => [],
        };
    }
}
