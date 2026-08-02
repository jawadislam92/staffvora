<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A billing line for an organization — either money RECEIVED or money OWED.
 *
 * ─── STATUS ──────────────────────────────────────────────────────────────────
 *  pending    the charge is owed and NOT paid. Grants nothing. This is what a
 *             customer's add-on request writes while no payment gateway is
 *             wired: the workspace has asked for the add-on, the price is
 *             derived and frozen on the row, and an operator confirms it once
 *             the money actually arrives.
 *  confirmed  money received (a bank transfer an operator recorded, or later a
 *             gateway webhook writing the same shape).
 *  canceled   the request was withdrawn before it was paid. Kept, never
 *             deleted — a billing ledger is append-only in spirit.
 *
 * ─── KIND ────────────────────────────────────────────────────────────────────
 *  subscription  the plan itself (what ManualBankTransfer has always written)
 *  addon         one add-on, for `seats` seats at `unit_price_cents` each on
 *                `billing_cycle`, prorated to the end of the current period.
 *
 * The seat count and unit price are SNAPSHOTTED on the row on purpose: the
 * invoice must keep saying what was actually charged even after the operator
 * edits the catalogue price or the team grows.
 *
 * Org-scoped like every other tenant record.
 *
 * @property int $amount_cents
 * @property string $status
 * @property string $kind
 */
class Payment extends Model
{
    use BelongsToOrganization;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELED = 'canceled';

    public const KIND_SUBSCRIPTION = 'subscription';

    public const KIND_ADDON = 'addon';

    protected $fillable = [
        'organization_id', 'subscription_id', 'kind', 'addon_id', 'billing_cycle',
        'seats', 'unit_price_cents', 'amount_cents', 'currency', 'method',
        'reference', 'description', 'status', 'paid_at', 'period_start', 'period_end',
        'recorded_by', 'requested_by', 'notes',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'amount_cents' => 'integer',
        'unit_price_cents' => 'integer',
        'seats' => 'integer',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * The add-on this line is for. Landlord-side catalogue row, so the relation
     * carries no tenant scope of its own (Addon has none by design).
     */
    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
    }

    // --- Scopes -------------------------------------------------------------

    /** Money owed but not received. */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /** Money received. */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CONFIRMED);
    }

    public function scopeForAddon(Builder $query, Addon|int $addon): Builder
    {
        return $query->where('kind', self::KIND_ADDON)
            ->where('addon_id', $addon instanceof Addon ? $addon->id : (int) $addon);
    }

    // --- State --------------------------------------------------------------

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    /** The `kind` of line this is, for the invoice list. */
    public function isAddonCharge(): bool
    {
        return $this->kind === self::KIND_ADDON;
    }
}
