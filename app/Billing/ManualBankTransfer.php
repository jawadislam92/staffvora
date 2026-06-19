<?php

namespace App\Billing;

use App\Models\Organization;
use App\Models\Payment;
use App\Tenancy\CurrentOrganization;
use Carbon\Carbon;

/**
 * Manual billing: a platform admin records a received bank transfer. Writes the
 * Payment, extends the subscription period, and flips the org to active. All
 * writes run bound to the target org so organization_id is stamped correctly.
 */
class ManualBankTransfer implements PaymentProvider
{
    public function __construct(private CurrentOrganization $current) {}

    public function recordPayment(
        Organization $org,
        int $amountCents,
        string $currency,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?string $reference = null,
        ?int $recordedBy = null,
        ?string $notes = null,
    ): Payment {
        return $this->current->runFor($org, function () use ($org, $amountCents, $currency, $periodStart, $periodEnd, $reference, $recordedBy, $notes) {
            $sub = $org->subscription;
            if ($sub) {
                $sub->update([
                    'status' => 'active',
                    'current_period_start' => $periodStart,
                    'current_period_end' => $periodEnd,
                ]);
            }

            $org->update(['status' => Organization::STATUS_ACTIVE]);

            return Payment::create([
                'subscription_id' => $sub?->id,
                'amount_cents' => $amountCents,
                'currency' => $currency,
                'method' => 'bank_transfer',
                'reference' => $reference,
                'status' => 'confirmed',
                'paid_at' => now(),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'recorded_by' => $recordedBy,
                'notes' => $notes,
            ]);
        });
    }
}
