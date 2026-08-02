<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A sellable bundle of entitlements that attaches to a company ON TOP of its
 * plan — e.g. the Agency toolkit (Upwork profiles + Machine Compliance).
 *
 * LANDLORD-SIDE. Global catalogue, not tenant-owned: no `organization_id`,
 * never BelongsToOrganization, never readable by a tenant.
 *
 * An add-on introduces NO new access-control path. It simply contributes an
 * `entitlements` map — exactly the same shape as `plans.entitlements` — which
 * the single resolver folds in. The resolution order the engine implements is:
 *
 *     per-company override → add-on → plan → catalogue default (DENY)
 *
 * so an add-on can grant a feature the plan lacks, while a per-company override
 * still wins over everything (and can revoke by storing `false`).
 *
 * Pricing mirrors plans: `price_cents` is per seat / month, `price_cents_yearly`
 * per seat / year, and a NULL yearly price means "negotiated" rather than free.
 *
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property int $price_cents
 * @property int|null $price_cents_yearly
 * @property array<string, int|bool|null>|null $entitlements
 * @property string $visibility  public|hidden|custom|legacy
 */
class Addon extends Model
{
    /** Same lifecycle vocabulary as Plan, on purpose. */
    public const VISIBILITIES = ['public', 'hidden', 'custom', 'legacy'];

    protected $fillable = [
        'key', 'name', 'description', 'price_cents', 'price_cents_yearly',
        'currency', 'entitlements', 'visibility', 'sort', 'archived_at',
    ];

    protected $casts = [
        'entitlements' => 'array',
        'price_cents' => 'integer',
        'price_cents_yearly' => 'integer',
        'sort' => 'integer',
        'archived_at' => 'datetime',
    ];

    /**
     * The companies that hold this add-on. The pivot carries an optional
     * validity window (null starts_at = live now, null ends_at = no expiry).
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_addon')
            ->withPivot(['starts_at', 'ends_at'])
            ->withTimestamps();
    }

    // --- Scopes -----------------------------------------------------------

    /** Still in service — not retired. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /** What a customer may see and buy themselves. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('visibility', 'public')->whereNull('archived_at');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** The value this add-on contributes for a key, or null if it says nothing. */
    public function entitlement(string $key): int|bool|null
    {
        return data_get($this->entitlements, $key);
    }
}
