<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UpworkProfile extends Model
{
    use BelongsToOrganization;

    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function workHours()
    {
        return $this->hasMany(WorkHour::class, 'tracker', 'name');
    }

    /**
     * Active (not archived) profiles — the only ones offered in dropdowns and
     * assignment pickers. Tenant scoping comes from the OrganizationScope.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Archived profiles only — the "Archived" list tab. */
    public function scopeArchived($query)
    {
        return $query->where('is_active', false);
    }

    public function clients()
    {
        return $this->belongsToMany(Client::class);
    }
}
