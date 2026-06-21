<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'parent_org_id',
        'trial_ends_at',
        'entitlement_overrides',
        'custom_price_cents',
        'discount_percent',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'entitlement_overrides' => 'array',
        'custom_price_cents' => 'integer',
        'discount_percent' => 'integer',
    ];

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
     * The EFFECTIVE value for an entitlement: the platform's per-tenant override
     * wins (it may raise or lower the plan value); otherwise the plan value;
     * otherwise the registry default, which is deny. This is the one place all
     * gating resolves through — a tenant can never exceed what's granted here.
     */
    public function entitlement(string $key): int|bool|null
    {
        $plan = $this->activePlan();
        $override = data_get($this->entitlement_overrides, $key);

        return \App\Tenancy\Entitlements::resolve($key, $plan !== null, $plan?->entitlement($key), $override);
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
        foreach (\App\Tenancy\Entitlements::keys() as $key) {
            $out[$key] = $this->entitlement($key);
        }

        return $out;
    }

    /** Whether a tenant's requested integer setting is within its entitlement. */
    public function entitlementAllows(string $key, int $requested): bool
    {
        return \App\Tenancy\Entitlements::allowsValue($key, $requested, $this->entitlement($key));
    }

    /** @deprecated use can(); kept for old call sites. */
    public function planFeature(string $key): bool
    {
        return $this->can($key);
    }

    /** Seat limit — effective max_users (override → plan), null = unlimited. */
    public function seatLimit(): ?int
    {
        $value = $this->entitlement('max_users');

        return $value === null ? null : (int) $value;
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

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_TRIAL, self::STATUS_ACTIVE], true);
    }
}
