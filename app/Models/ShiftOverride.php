<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A one-day shift change for a user (see the migration). Absent columns fall
 * back to the standing shift; {@see User::effectiveShiftFor()} resolves the two.
 *
 * `date` is the BUSINESS day (the workspace's operating timezone, see
 * App\Support\BusinessTime) — not a UTC instant. `shift_start_time` is a
 * wall-clock time in that same business timezone, exactly like the standing
 * users.shift_start_time column it replaces for the day.
 *
 * Tenant-owned: reads are restricted to the active workspace and
 * organization_id is stamped on create by the trait.
 */
class ShiftOverride extends Model
{
    use BelongsToOrganization;
    use HasFactory;

    protected $fillable = [
        'user_id',
        'date',
        'shift_start_time',
        'shift_hours',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'shift_start_time' => 'datetime:H:i',
        'shift_hours' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
