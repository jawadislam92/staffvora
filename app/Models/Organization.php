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
        'parent_org_id',
        'trial_ends_at',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
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

    /** Whether this org's plan includes a feature flag (defaults to false). */
    public function planFeature(string $key): bool
    {
        return (bool) ($this->activePlan()?->feature($key, false));
    }

    /** Seat limit from the current plan, or null for unlimited. */
    public function seatLimit(): ?int
    {
        return $this->activePlan()?->max_users;
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
