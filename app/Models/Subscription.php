<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An organization's current plan + billing period. Org-scoped, so a tenant only
 * ever reads its own subscription; the platform admin queries via the
 * Organization relation (which bypasses the scope) or runUnscoped().
 *
 * `billing_cycle` records WHICH price the company is billed on — monthly or
 * yearly. It is never an access decision (nothing may gate on it); it only
 * selects which column of the plan/add-on row the pricing service reads.
 */
class Subscription extends Model
{
    use BelongsToOrganization;

    public const CYCLE_MONTHLY = 'monthly';

    public const CYCLE_YEARLY = 'yearly';

    /** The only cycles the pricing service knows how to derive a price for. */
    public const CYCLES = [self::CYCLE_MONTHLY, self::CYCLE_YEARLY];

    /**
     * The lifecycle states this table has always modelled (see the
     * `create_billing_tables` migration). They are named here rather than
     * scattered as string literals; nothing new was invented.
     */
    public const STATUS_TRIALING = 'trialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    /** Single "l" — the spelling the column has stored since day one. */
    public const STATUS_CANCELED = 'canceled';

    public const STATUSES = [
        self::STATUS_TRIALING,
        self::STATUS_ACTIVE,
        self::STATUS_PAST_DUE,
        self::STATUS_CANCELED,
    ];

    /**
     * The states an operator may set from the status control.
     *
     * `trialing` is deliberately absent: a trial is a DATE as well as a state,
     * so it is owned by the trial control (SubscriptionManager::setTrial()),
     * which writes both. Offering it in two places is how a status and a trial
     * date drift apart.
     */
    public const OPERATOR_SETTABLE_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_PAST_DUE,
        self::STATUS_CANCELED,
    ];

    protected $fillable = [
        'organization_id', 'plan_id', 'status', 'seats', 'billing_cycle',
        'trial_ends_at', 'current_period_start', 'current_period_end',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'seats' => 'integer',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function onTrial(): bool
    {
        return $this->status === self::STATUS_TRIALING && $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function hasExpired(): bool
    {
        if ($this->status === self::STATUS_TRIALING) {
            return ! $this->trial_ends_at || $this->trial_ends_at->isPast();
        }
        if ($this->status === 'active') {
            return ! $this->current_period_end || $this->current_period_end->isPast();
        }

        return true; // past_due / canceled
    }

    public function isUsable(): bool
    {
        return in_array($this->status, ['trialing', 'active'], true) && ! $this->hasExpired();
    }

    // --- Billing period -----------------------------------------------------

    /** The cycle this company is billed on, defaulting to monthly. */
    public function cycle(): string
    {
        return in_array($this->billing_cycle, self::CYCLES, true)
            ? $this->billing_cycle
            : self::CYCLE_MONTHLY;
    }

    public function isYearly(): bool
    {
        return $this->cycle() === self::CYCLE_YEARLY;
    }

    /**
     * When the current paid/trial period ends — the date everything else on the
     * account renews against, and the anchor proration is measured to. A trial
     * is a period too, so it counts.
     */
    public function periodEndsAt(): ?CarbonInterface
    {
        return $this->current_period_end ?? $this->trial_ends_at;
    }

    /**
     * When the current period began. Falls back to the row's creation time so a
     * subscription that has never been paid still yields a bounded window
     * (never null, never zero-length) for the proration maths.
     */
    public function periodStartsAt(): ?CarbonInterface
    {
        return $this->current_period_start ?? $this->created_at;
    }
}
