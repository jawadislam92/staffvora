<?php

namespace App\Models;

use App\Services\RegionPricing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One plan's statement inside a region rule: is this plan OFFERED here, and does
 * it carry a LOCAL price?
 *
 * Landlord-side, never tenant-scoped.
 *
 * MONEY: minor units as integers, exactly like `plans.price_cents`. The currency
 * is the REGION's (`pricing_regions.currency`), because a region quotes one
 * local currency — a per-row currency would let one region mix three.
 *
 * NULL vs 0 — both are meaningful and must stay distinguishable:
 *   price_cents = NULL  → no local price; the plan's own catalogue price applies
 *                         (quoted in the plan's own currency).
 *   price_cents = 0     → genuinely free in this region.
 *   price_cents_yearly  → NULL additionally inherits "no yearly list price /
 *                         talk to us" from the plan when the plan has none.
 *
 * @property int $pricing_region_id
 * @property int $plan_id
 * @property bool $is_offered
 * @property int|null $price_cents
 * @property int|null $price_cents_yearly
 */
class PricingRegionPlan extends Model
{
    protected $fillable = [
        'pricing_region_id', 'plan_id', 'is_offered', 'price_cents', 'price_cents_yearly',
    ];

    protected $casts = [
        'pricing_region_id' => 'integer',
        'plan_id' => 'integer',
        'is_offered' => 'boolean',
        'price_cents' => 'integer',
        'price_cents_yearly' => 'integer',
    ];

    /** Same reason as PricingRegion: a price edit must land on the next request. */
    protected static function booted(): void
    {
        static::saved(fn () => RegionPricing::bump());
        static::deleted(fn () => RegionPricing::bump());
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(PricingRegion::class, 'pricing_region_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
