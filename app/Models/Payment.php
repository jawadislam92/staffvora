<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded payment against a subscription. Manual for now (a platform admin
 * records a bank transfer), but shaped so a gateway can write the same rows.
 * Org-scoped like every other tenant record.
 */
class Payment extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'subscription_id', 'amount_cents', 'currency', 'method',
        'reference', 'status', 'paid_at', 'period_start', 'period_end', 'recorded_by', 'notes',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'amount_cents' => 'integer',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
