<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customizable group of users inside an organization. Org-scoped via the
 * BelongsToOrganization trait, so one tenant's teams are never visible to
 * another.
 */
class Team extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'name',
        'color',
        'organization_id',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
