<?php

namespace App\Models;

use App\Services\RegionPricing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A REGION PRICING RULE — "which plans may this part of the world buy, and at
 * what price".
 *
 * Landlord-side catalogue data, exactly like Plan / Addon / Feature: it must
 * NEVER carry BelongsToOrganization, because it is not owned by any tenant.
 *
 * ─── WHAT A REGION IS ────────────────────────────────────────────────────────
 * A named group of ISO-3166-1 alpha-2 country codes ("South Asia" = PK, IN, BD)
 * or a single country, plus:
 *   • `currency`   — what local prices are quoted in (PKR, USD…)
 *   • `offer_mode` — all_except (subtract from the public catalogue) or
 *                    only_listed (an allow-list of public plans)
 *   • `priority`   — lower wins when two rules claim the same country
 * and a set of per-plan rows (`PricingRegionPlan`) saying whether each public
 * plan is offered here and what it costs locally.
 *
 * ─── WHAT A REGION IS *NOT* ──────────────────────────────────────────────────
 * It is NOT an access-control object. There is no `entitlements` column and
 * there never may be: the country a visitor claims is attacker-controlled, so a
 * region may only narrow WHICH PUBLIC PLANS are visible and change WHAT THEY
 * COST. Capabilities stay where they belong — the ONE resolution path in
 * `Organization::can()` (per-company override → add-ons → plan → deny), which
 * never reads this table.
 *
 * ─── RESOLUTION (deterministic, and explainable) ─────────────────────────────
 * See App\Services\RegionPricing. Nothing anywhere may branch on a region KEY,
 * for the same reason nothing may branch on a plan key.
 *
 * @property string $key
 * @property string $name
 * @property array<int, string>|null $country_codes
 * @property string $currency
 * @property string $offer_mode
 * @property int $priority
 * @property bool $is_active
 */
class PricingRegion extends Model
{
    /**
     * all_except  — every public plan is offered here EXCEPT the ones explicitly
     *               switched off. The safe default: a new plan is sold in this
     *               region automatically.
     * only_listed — only the plans explicitly switched on are offered. Stricter:
     *               a new plan is NOT sold here until the operator says so.
     */
    public const OFFER_MODES = ['all_except', 'only_listed'];

    /** Audit action strings for this surface (see PlatformAuditLog::record). */
    public const AUDIT_CREATED = 'region.created';

    public const AUDIT_UPDATED = 'region.updated';

    public const AUDIT_ARCHIVED = 'region.archived';

    public const AUDIT_RESTORED = 'region.restored';

    protected $fillable = [
        'key', 'name', 'description', 'country_codes', 'currency',
        'offer_mode', 'priority', 'is_active', 'archived_at',
    ];

    protected $casts = [
        'country_codes' => 'array',
        'is_active' => 'boolean',
        'priority' => 'integer',
        'archived_at' => 'datetime',
    ];

    /**
     * Any write to a rule re-prices whole countries, so the resolver's cache is
     * dropped on the spot rather than within its TTL. Cheap: one cache key.
     */
    protected static function booted(): void
    {
        static::saved(fn () => RegionPricing::bump());
        static::deleted(fn () => RegionPricing::bump());
    }

    public function planRules(): HasMany
    {
        return $this->hasMany(PricingRegionPlan::class);
    }

    // --- Scopes -----------------------------------------------------------

    /** Rules that actually take part in resolution. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('archived_at');
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /** Resolution order: priority, then the more specific rule, then the older. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('priority')->orderBy('id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @return array<int, string> uppercase alpha-2 codes, de-duplicated */
    public function countries(): array
    {
        return RegionPricing::normalizeCountryList($this->country_codes ?? []);
    }

    public function matchesCountry(?string $country): bool
    {
        $country = RegionPricing::normalizeCountry($country);

        return $country !== null && in_array($country, $this->countries(), true);
    }
}
