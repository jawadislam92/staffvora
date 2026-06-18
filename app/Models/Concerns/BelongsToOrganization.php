<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every tenant-owned model. It (1) registers the OrganizationScope so
 * reads are restricted to the active tenant, and (2) stamps organization_id on
 * create from the active tenant, so callers never have to pass it. Models keep
 * working unchanged for guest/console contexts where no tenant is bound.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function ($model) {
            if (empty($model->organization_id)) {
                $id = app(CurrentOrganization::class)->id();
                if ($id !== null) {
                    $model->organization_id = $id;
                }
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Escape hatch for platform/landlord queries that must cross tenants. */
    public function scopeWithoutOrganizationScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(OrganizationScope::class);
    }
}
