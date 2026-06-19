<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An organization's current plan + billing period. Org-scoped, so a tenant only
 * ever reads its own subscription; the platform admin queries via the
 * Organization relation (which bypasses the scope) or runUnscoped().
 */
class Subscription extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'plan_id', 'status', 'seats',
        'trial_ends_at', 'current_period_start', 'current_period_end',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function onTrial(): bool
    {
        return $this->status === 'trialing' && $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function hasExpired(): bool
    {
        if ($this->status === 'trialing') {
            return $this->trial_ends_at && $this->trial_ends_at->isPast();
        }
        if ($this->status === 'active') {
            return $this->current_period_end && $this->current_period_end->isPast();
        }

        return true; // past_due / canceled
    }

    public function isUsable(): bool
    {
        return in_array($this->status, ['trialing', 'active'], true) && ! $this->hasExpired();
    }
}
