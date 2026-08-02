<?php

namespace App\Models;

use App\Tenancy\Entitlements;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A subscription plan in the global catalogue (landlord-side, NOT tenant-scoped
 * — it must never carry BelongsToOrganization).
 *
 * Its `entitlements` map (caps + flags, see App\Tenancy\Entitlements and the
 * `features` table) drives all gating; the operator edits it in the plan editor.
 * `features` is the old flat boolean map, kept readable for back-compat.
 *
 * PRICING: `price_cents` is per seat / MONTH, `price_cents_yearly` is per seat /
 * YEAR. A NULL yearly price is meaningful — it means "no yearly list price,
 * talk to us" (Enterprise) — and is NOT the same as 0, which is genuinely free.
 *
 * LIFECYCLE:
 *   visibility        public | hidden | custom | legacy
 *                     public — listed on the pricing page and self-serve signup
 *                     hidden — exists but is not advertised
 *                     custom — negotiated; an operator assigns it by hand
 *                     legacy — grandfathered; current subscribers keep it,
 *                              nobody new can land on it
 *   archived_at       set = retired. Existing subscriptions keep resolving, but
 *                     the plan disappears from every picker.
 *   is_default_trial  exactly ONE plan carries this. It is the plan a brand-new
 *                     signup is put on. It exists so nothing anywhere has to
 *                     look up a literal plan key (the old `where('key','pro')`).
 *
 * ⚠️ Nothing in the application may branch on `key`. "Growth" is only the
 * DISPLAY NAME of the row whose key happens to be `pro`; the owner can rename
 * or reorder any plan at any time.
 */
class Plan extends Model
{
    /** The plan visibility states, in the order the operator UI should offer them. */
    public const VISIBILITIES = ['public', 'hidden', 'custom', 'legacy'];

    protected $fillable = [
        'key', 'name', 'price_cents', 'price_cents_yearly', 'currency', 'interval',
        'max_users', 'trial_days', 'features', 'entitlements',
        'is_public', 'visibility', 'archived_at', 'is_default_trial', 'sort',
    ];

    protected $casts = [
        'features' => 'array',
        'entitlements' => 'array',
        'is_public' => 'boolean',
        'is_default_trial' => 'boolean',
        'archived_at' => 'datetime',
        'price_cents' => 'integer',
        'price_cents_yearly' => 'integer',
        'max_users' => 'integer',
        'trial_days' => 'integer',
        'sort' => 'integer',
    ];

    // --- Scopes -----------------------------------------------------------

    /** Plans customers may see and land on themselves. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('visibility', 'public')->whereNull('archived_at');
    }

    /** Everything still in service (any visibility), i.e. not retired. */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /** The operator-controlled display order. `id` breaks ties deterministically. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * The plan a brand-new signup starts its trial on. Falls back to the first
     * unarchived plan in display order so signup can never break, whatever the
     * operator does to the catalogue.
     */
    public static function defaultTrial(): ?self
    {
        return static::where('is_default_trial', true)->notArchived()->ordered()->first()
            ?? static::notArchived()->ordered()->first();
    }

    // --- Entitlements -----------------------------------------------------

    /** @deprecated use entitlement(); kept for any old call sites. */
    public function feature(string $key, mixed $default = false): mixed
    {
        $value = $this->entitlement($key);

        return $value ?? data_get($this->features, $key, $default);
    }

    /** The plan-level value for an entitlement, or the registry default (deny). */
    public function entitlement(string $key): int|bool|null
    {
        return data_get($this->entitlements, $key, Entitlements::default($key));
    }

    /** The full resolved entitlement map for this plan (every key). */
    public function entitlementMap(): array
    {
        $out = [];
        foreach (Entitlements::keys() as $key) {
            $out[$key] = $this->entitlement($key);
        }

        return $out;
    }

    public function isUnlimitedUsers(): bool
    {
        return $this->max_users === null;
    }

    /**
     * Idempotently seed the default plan catalogue (safe to re-run). Existing
     * rows keep any platform edits, but a row missing its `entitlements` map is
     * backfilled so upgrades don't leave a plan ungated.
     */
    public static function ensureSeeded(): void
    {
        foreach (static::defaults() as $plan) {
            $row = static::firstOrCreate(['key' => $plan['key']], $plan);

            if (empty($row->entitlements)) {
                $row->entitlements = $plan['entitlements'];
                $row->save();
            }
        }
    }

    /**
     * The owner-approved starting catalogue.
     *
     * ⚠️ EVERY NUMBER HERE IS A PLACEHOLDER the operator can edit from the
     * panel — this array is the fresh-install seed and nothing else. No code
     * anywhere may read these values or branch on a plan key.
     *
     *   Free        $0/mo   $0/yr    2 seats   7-day retention
     *   Starter     $5/mo   $50/yr  10 seats  90-day retention
     *   Growth      $9/mo   $90/yr  50 seats 365-day retention   ← default trial
     *   Enterprise "from $18"/mo, yearly by negotiation, unlimited seats & retention
     *
     * Feature spread (spec Part 2): screenshots + activity from Starter;
     * app/URL tracking, AI summaries and white-label from Growth. `api` ships
     * off everywhere — a customer API does not exist, so it is not sold.
     * `upwork_profiles` and `machine_compliance` ship off on every plan: they
     * are sold through the Agency toolkit add-on, or granted to a single
     * company from the operator panel.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function defaults(): array
    {
        $features = fn (array $on) => array_merge(
            ['monitoring' => false, 'app_url' => false, 'ai' => false, 'white_label' => false, 'api' => false, 'integrations' => false],
            $on,
        );

        return [
            [
                'key' => 'free', 'name' => 'Free', 'sort' => 1,
                'price_cents' => 0, 'price_cents_yearly' => 0, 'currency' => 'USD',
                'max_users' => 2, 'is_public' => true, 'visibility' => 'public',
                'archived_at' => null, 'is_default_trial' => false,
                'features' => $features([]),
                'entitlements' => [
                    'max_users' => 2,
                    'max_storage_gb' => 1,
                    'max_screenshots_per_hour' => 0,
                    'min_auto_pause_minutes' => 10,
                    'max_retention_days' => 7,
                    'monitoring' => false,
                    'activity_tracking' => false,
                    'app_url_tracking' => false,
                    'weekly_limit' => false,
                    'attendance_alerts' => false,
                    'ai' => false,
                    'integrations' => false,
                    'api' => false,
                    'white_label' => false,
                    'upwork_profiles' => false,
                    'machine_compliance' => false,
                ],
            ],
            [
                'key' => 'starter', 'name' => 'Starter', 'sort' => 2,
                'price_cents' => 500, 'price_cents_yearly' => 5000, 'currency' => 'USD',
                'max_users' => 10, 'is_public' => true, 'visibility' => 'public',
                'archived_at' => null, 'is_default_trial' => false,
                'features' => $features(['monitoring' => true]),
                'entitlements' => [
                    'max_users' => 10,
                    'max_storage_gb' => 10,
                    'max_screenshots_per_hour' => 12,
                    'min_auto_pause_minutes' => 5,
                    'max_retention_days' => 90,
                    'monitoring' => true,
                    'activity_tracking' => true,
                    'app_url_tracking' => false,
                    'weekly_limit' => true,
                    'attendance_alerts' => true,
                    'ai' => false,
                    'integrations' => true,
                    'api' => false,
                    'white_label' => false,
                    'upwork_profiles' => false,
                    'machine_compliance' => false,
                ],
            ],
            [
                // DISPLAY NAME "Growth". The key stays `pro` — renaming a key
                // would be a code-visible change, and nothing may depend on it.
                'key' => 'pro', 'name' => 'Growth', 'sort' => 3,
                'price_cents' => 900, 'price_cents_yearly' => 9000, 'currency' => 'USD',
                'max_users' => 50, 'is_public' => true, 'visibility' => 'public',
                'archived_at' => null, 'is_default_trial' => true,
                'features' => $features(['monitoring' => true, 'app_url' => true, 'ai' => true, 'white_label' => true, 'integrations' => true]),
                'entitlements' => [
                    'max_users' => 50,
                    'max_storage_gb' => 50,
                    'max_screenshots_per_hour' => 30,
                    'min_auto_pause_minutes' => 1,
                    'max_retention_days' => 365,
                    'monitoring' => true,
                    'activity_tracking' => true,
                    'app_url_tracking' => true,
                    'weekly_limit' => true,
                    'attendance_alerts' => true,
                    'ai' => true,
                    'integrations' => true,
                    'api' => false,
                    'white_label' => true,
                    'upwork_profiles' => false,
                    'machine_compliance' => false,
                ],
            ],
            [
                // "from $18" — the monthly figure is a floor we publish; the
                // yearly price is deliberately NULL (negotiated), and seats and
                // retention are NULL = unlimited.
                'key' => 'enterprise', 'name' => 'Enterprise', 'sort' => 4,
                'price_cents' => 1800, 'price_cents_yearly' => null, 'currency' => 'USD',
                'max_users' => null, 'is_public' => true, 'visibility' => 'public',
                'archived_at' => null, 'is_default_trial' => false,
                'features' => $features(['monitoring' => true, 'app_url' => true, 'ai' => true, 'white_label' => true, 'integrations' => true]),
                'entitlements' => [
                    'max_users' => null,
                    'max_storage_gb' => null,
                    'max_screenshots_per_hour' => 60,
                    'min_auto_pause_minutes' => 1,
                    'max_retention_days' => null,
                    'monitoring' => true,
                    'activity_tracking' => true,
                    'app_url_tracking' => true,
                    'weekly_limit' => true,
                    'attendance_alerts' => true,
                    'ai' => true,
                    'integrations' => true,
                    'api' => false,
                    'white_label' => true,
                    'upwork_profiles' => false,
                    'machine_compliance' => false,
                ],
            ],
        ];
    }
}
