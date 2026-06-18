<?php

namespace App\Models\Scopes;

use App\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every query on a tenant model to the active organization. When no
 * organization is bound (guest/login, or an as-yet-unbound console context) the
 * scope is a no-op so authentication and platform queries still work. The id is
 * read from the request-scoped CurrentOrganization singleton and never triggers
 * a query, so applying this scope while loading a model cannot recurse.
 */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $id = app(CurrentOrganization::class)->id();

        if ($id !== null) {
            $builder->where($model->getTable().'.organization_id', $id);
        }
    }
}
