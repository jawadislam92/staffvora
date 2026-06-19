<?php

namespace App\Billing;

use App\Models\Organization;
use App\Models\Payment;
use Carbon\Carbon;

/**
 * The seam between the app and however money is collected. Today the only
 * implementation is ManualBankTransfer (a platform admin records a transfer);
 * a Stripe/Paddle/Lemon Squeezy adapter can implement this same contract later
 * without touching the rest of the app.
 */
interface PaymentProvider
{
    public function recordPayment(
        Organization $org,
        int $amountCents,
        string $currency,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?string $reference = null,
        ?int $recordedBy = null,
        ?string $notes = null,
    ): Payment;
}
