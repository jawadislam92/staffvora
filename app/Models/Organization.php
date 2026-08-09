<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use App\Tenancy\Entitlements;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A tenant. Every customer account is one Organization; all tenant-owned
 * records carry organization_id and are isolated by the global scope (see the
 * BelongsToOrganization trait). This model itself is NOT scoped — it is the
 * scope root, resolved from the authenticated user or the request host.
 */
class Organization extends Model
{
    use HasFactory;

    public const STATUS_TRIAL = 'trial';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'name',
        'slug',
        'custom_domain',
        'owner_user_id',
        'status',
        'display_name',
        'logo_path',
        'accent_color',
        'default_theme',
        'timezone',
        'workspace_type',
        'onboarding_completed_at',
        'parent_org_id',
        'trial_ends_at',
        'entitlement_overrides',
        'custom_price_cents',
        'discount_percent',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'onboarding_completed_at' => 'datetime',
        'entitlement_overrides' => 'array',
        'custom_price_cents' => 'integer',
        'discount_percent' => 'integer',
    ];

    /**
     * Per-instance memo of the active add-ons' entitlement maps, so resolving a
     * 16-key map costs ONE query rather than sixteen. Not persisted, not cast —
     * cleared by refresh().
     *
     * @var array<int, array<string, mixed>>|null
     */
    protected ?array $resolvedAddonEntitlements = null;

    /**
     * Anything that changes this workspace's access (its overrides, its plan
     * switch, an add-on grant recorded against it) also has to drop the resolved
     * map the UI is served from — otherwise the operator's change appears up to
     * a minute late. The shared prop keys off Entitlements::sharedCacheKey().
     */
    protected static function booted(): void
    {
        static::saved(function (self $organization) {
            $organization->resolvedAddonEntitlements = null;
            Entitlements::forgetForOrganization($organization->id);
        });
    }

    /** Keep the add-on memo honest: a refreshed model re-reads its add-ons. */
    public function refresh()
    {
        $this->resolvedAddonEntitlements = null;

        return parent::refresh();
    }

    /** The "main person" who owns this account. */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** Reserved for a future agency/reseller hierarchy (unused in v1). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_org_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    /**
     * The org's current subscription. The child models are org-scoped, so the
     * relation explicitly drops that scope — the FK already constrains it to
     * this org, and this lets the platform admin read any org's subscription.
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->withoutGlobalScope(OrganizationScope::class)
            ->latestOfMany();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->withoutGlobalScope(OrganizationScope::class);
    }

    public function activePlan(): ?Plan
    {
        return $this->subscription?->plan;
    }

    /**
     * Every add-on ever attached to this workspace, expired ones included.
     * The pivot carries an optional validity window — see activeAddons().
     *
     * An add-on does NOT introduce a second access path: it only contributes an
     * `entitlements` map that the single resolver folds in between the
     * per-company override and the plan.
     */
    public function addons(): BelongsToMany
    {
        return $this->belongsToMany(Addon::class, 'organization_addon')
            ->withPivot(['starts_at', 'ends_at'])
            ->withTimestamps();
    }

    /**
     * The add-ons in force right now: not archived, and inside their validity
     * window (null starts_at = live immediately, null ends_at = no expiry).
     * This is the relation the entitlement resolver must read.
     */
    public function activeAddons(): BelongsToMany
    {
        return $this->addons()
            ->whereNull('addons.archived_at')
            ->where(fn ($q) => $q->whereNull('organization_addon.starts_at')->orWhere('organization_addon.starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('organization_addon.ends_at')->orWhere('organization_addon.ends_at', '>', now()));
    }

    /**
     * Attach (or re-date) an add-on for this workspace and invalidate everything
     * derived from it. USE THIS rather than `addons()->attach()`: pivot writes
     * fire no model events, so a raw attach would leave the shared `entitlements`
     * prop serving a stale map until its TTL lapsed.
     *
     * `$startsAt` null = live immediately, `$endsAt` null = no expiry.
     */
    public function grantAddon(Addon|int $addon, ?\DateTimeInterface $startsAt = null, ?\DateTimeInterface $endsAt = null): void
    {
        $id = $addon instanceof Addon ? $addon->id : (int) $addon;

        $this->addons()->syncWithoutDetaching([
            $id => ['starts_at' => $startsAt, 'ends_at' => $endsAt],
        ]);

        $this->forgetResolvedEntitlements();
    }

    /** Remove an add-on from this workspace and invalidate the derived caches. */
    public function revokeAddon(Addon|int $addon): void
    {
        $this->addons()->detach($addon instanceof Addon ? $addon->id : (int) $addon);

        $this->forgetResolvedEntitlements();
    }

    /** Drop this instance's memo and every cache derived from its entitlements. */
    public function forgetResolvedEntitlements(): void
    {
        $this->resolvedAddonEntitlements = null;

        Entitlements::forgetForOrganization($this->id);
        Entitlements::bump();
    }

    /**
     * The EFFECTIVE value for an entitlement:
     *   per-company override → active add-ons → plan → catalogue default (deny).
     *
     * This is the ONE place all gating resolves through — the HTTP gate, the
     * shared UI prop, the desktop settings clamp and every limit check call
     * here. A tenant can never exceed what this returns. See
     * `App\Tenancy\Entitlements` for the storage shapes (including an override
     * that carries an expiry, which is ignored once it lapses).
     */
    public function entitlement(string $key): int|bool|null
    {
        return $this->resolveEntitlement($key)['value'];
    }

    /**
     * Which layer decided a key — override|addon|plan|default|unknown — for the
     * operator panel, without duplicating a single rule.
     */
    public function entitlementSource(string $key): string
    {
        return $this->resolveEntitlement($key)['source'];
    }

    /** @return array{value:int|bool|null, source:string} */
    private function resolveEntitlement(string $key): array
    {
        return $this->resolveEntitlementUnder($this->activePlan(), $key);
    }

    /**
     * The same resolution against an ARBITRARY plan — everything else about the
     * company (its overrides, its live add-ons) held constant.
     *
     * @return array{value:int|bool|null, source:string}
     */
    private function resolveEntitlementUnder(?Plan $plan, string $key): array
    {
        return Entitlements::resolveWithSource(
            $key,
            $plan !== null,
            $plan?->entitlement($key),
            data_get($this->entitlement_overrides, $key),
            $this->addonEntitlementMaps(),
        );
    }

    /**
     * "What would this workspace be able to do if it were on THIS plan?" —
     * the full resolved map with provenance, exactly as `entitlementsWithSources()`
     * returns it for the live plan.
     *
     * This exists so the operator console can show what a plan switch will
     * change BEFORE it is applied, WITHOUT a second copy of the resolution
     * rules. It is a pure read: nothing is written and the company's real
     * entitlements are untouched. Because per-company overrides and active
     * add-ons are held constant, a capability an override grants correctly does
     * NOT show up as something the switch takes away.
     *
     * @return array<string, array{value:int|bool|null, source:string}>
     */
    public function entitlementsUnderPlan(?Plan $plan): array
    {
        $out = [];
        foreach (Entitlements::keys() as $key) {
            $out[$key] = $this->resolveEntitlementUnder($plan, $key);
        }

        return $out;
    }

    /**
     * The entitlement map of every add-on currently in force, memoised for the
     * life of this instance so building the full map is ONE query, not one per
     * key. Cleared by refresh(); a fail (missing table mid-migration) yields no
     * add-ons, which is the deny direction.
     *
     * @return array<int, array<string, mixed>>
     */
    private function addonEntitlementMaps(): array
    {
        if ($this->resolvedAddonEntitlements !== null) {
            return $this->resolvedAddonEntitlements;
        }

        if (! $this->exists) {
            return $this->resolvedAddonEntitlements = [];
        }

        try {
            $this->resolvedAddonEntitlements = $this->activeAddons()
                ->get(['addons.id', 'addons.entitlements'])
                ->map(fn (Addon $addon) => is_array($addon->entitlements) ? $addon->entitlements : [])
                ->values()
                ->all();
        } catch (\Throwable $e) {
            $this->resolvedAddonEntitlements = [];
        }

        return $this->resolvedAddonEntitlements;
    }

    /** Convenience for boolean (flag) entitlements. */
    public function can(string $key): bool
    {
        return (bool) $this->entitlement($key);
    }

    /** The full resolved entitlement map (for sharing to the UI / platform). */
    public function entitlements(): array
    {
        $out = [];
        foreach (Entitlements::keys() as $key) {
            $out[$key] = $this->entitlement($key);
        }

        return $out;
    }

    /**
     * The resolved map with provenance: ['ai' => ['value' => true, 'source' => 'addon'], …].
     * Same single resolution, one extra field — the operator page renders this.
     */
    public function entitlementsWithSources(): array
    {
        $out = [];
        foreach (Entitlements::keys() as $key) {
            $out[$key] = $this->resolveEntitlement($key);
        }

        return $out;
    }

    /** Whether a tenant's requested integer setting is within its entitlement. */
    public function entitlementAllows(string $key, int $requested): bool
    {
        return Entitlements::allowsValue($key, $requested, $this->entitlement($key));
    }

    /** @deprecated use can(); kept for old call sites. */
    public function planFeature(string $key): bool
    {
        return $this->can($key);
    }

    /**
     * Seat limit — effective max_users (override → add-on → plan), null =
     * unlimited.
     *
     * FAILS SAFE. A ceiling that is missing is NOT a ceiling of zero. The
     * resolver used to hand back `false` for any key absent from the ACTIVE
     * feature catalogue, and `(int) false` is `0` — so deactivating the
     * `max_users` Feature row from the operator panel collapsed EVERY tenant's
     * ceiling to zero at once: no hire, no reactivation, no onboarding invite,
     * platform-wide, from one toggle. A limit that vanishes must never become a
     * lock-out, so anything that is not an integer reads as "no ceiling".
     */
    public function seatLimit(): ?int
    {
        $value = $this->entitlement('max_users');

        // is_int, not `!== null`: `false`/`true` mean "this key is not a numeric
        // limit right now", which is a broken catalogue, not a zero-seat plan.
        return is_int($value) ? $value : null;
    }

    /** The base price before discount: a negotiated custom price wins over the plan. */
    public function priceCents(): ?int
    {
        return $this->custom_price_cents ?? $this->activePlan()?->price_cents;
    }

    /** The price this tenant actually pays: base price minus any percentage discount. */
    public function effectivePriceCents(): ?int
    {
        $base = $this->priceCents();
        if ($base === null) {
            return null;
        }
        $pct = max(0, min(100, (int) ($this->discount_percent ?? 0)));

        return $pct > 0 ? (int) round($base * (100 - $pct) / 100) : $base;
    }

    /** The display/white-label name, falling back to the legal name. */
    public function brandName(): string
    {
        return $this->display_name ?: $this->name;
    }

    /** The workspace's business timezone for day/shift/attendance bucketing. */
    public function businessTimezone(): string
    {
        return $this->timezone ?: config('app.timezone', 'UTC');
    }

    /**
     * Whether this workspace uses the Upwork "Profiles" concept (an extra
     * attribution dimension only agencies running multiple Upwork seller
     * accounts need). Off for general in-house teams, BPOs and freelancers, so
     * they never see "Upwork" anywhere.
     *
     * ⚠️ THIS IS AN ENTITLEMENT, NOT AN EDITION. It used to read
     * `workspace_type === 'agency'`, which made the edition a SECOND, parallel
     * access-control path that the operator could not change at all. It now
     * resolves through the ONE engine (per-company override → active add-ons →
     * plan → catalogue default = deny), so the owner can put the Agency toolkit
     * on any plan, sell it as an add-on, grant it to a single company, or
     * revoke it — with no code change and no second gate.
     *
     * `workspace_type` survives ONLY as a signup HINT (which starter work
     * categories to seed, and the label wording below). It decides nothing.
     */
    public function usesProfiles(): bool
    {
        return $this->can('upwork_profiles');
    }

    /** Per-workspace label for the "client/project" entity (a no-code rename hook). */
    public function clientTerm(): string
    {
        return match ($this->workspace_type) {
            'agency' => 'Client',
            'bpo' => 'Account',
            'freelancer' => 'Client',
            default => 'Project',
        };
    }

    /** Per-workspace label for the optional attribution profile. */
    public function profileTerm(): string
    {
        return $this->workspace_type === 'agency' ? 'Upwork Profile' : 'Profile';
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_TRIAL, self::STATUS_ACTIVE], true);
    }

    /** Whether the owner still needs to run the first-run setup wizard. */
    public function needsOnboarding(): bool
    {
        return is_null($this->onboarding_completed_at);
    }
}
