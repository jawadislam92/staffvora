<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A curated shift NAME (Morning / Noon / Evening / Night / …) admins manage in
 * one place; users pick one via users.shift_id — pick-only, so no typos and no
 * free text. Primarily a label managers filter and group by.
 *
 * Unlike the single-tenant source app (where a shift was purely a label and the
 * timing always lived on the user), a shift here MAY also carry the timing:
 * start_time / hours / grace_minutes, all nullable. When a shift defines a start
 * time it becomes the source of truth for everyone assigned to it; when it does
 * not, the per-user `shift_start_time` column keeps driving the schedule exactly
 * as before. {@see User::effectiveShiftFor()} is the single resolver.
 *
 * Tenant-owned: the OrganizationScope restricts every read to the active
 * workspace and organization_id is stamped on create, so shift names only have
 * to be unique WITHIN a workspace.
 */
class Shift extends Model
{
    use BelongsToOrganization;
    use HasFactory;

    /** Starter shifts every workspace gets. Deliberately timing-free. */
    public const DEFAULTS = ['Morning', 'Noon', 'Evening', 'Night'];

    protected $fillable = [
        'name',
        'start_time',
        'hours',
        'grace_minutes',
        'sort_order',
    ];

    protected $casts = [
        'start_time' => 'datetime:H:i',
        'hours' => 'decimal:2',
        'grace_minutes' => 'integer',
        'sort_order' => 'integer',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * Give the ACTIVE workspace the starter shift list the first time someone
     * opens a surface that needs it. Idempotent, and a no-op when the workspace
     * already curated its own list (or when no tenant is bound, so we can never
     * create orphan rows visible to nobody).
     */
    public static function ensureSeeded(): void
    {
        if (app(CurrentOrganization::class)->id() === null) {
            return;
        }

        if (static::query()->exists()) {
            return;
        }

        foreach (static::DEFAULTS as $index => $name) {
            static::create(['name' => $name, 'sort_order' => $index]);
        }
    }
}
