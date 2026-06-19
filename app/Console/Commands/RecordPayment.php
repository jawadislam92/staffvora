<?php

namespace App\Console\Commands;

use App\Billing\PaymentProvider;
use App\Models\Organization;
use App\Models\Plan;
use App\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Platform-admin tool: record a received bank transfer for a tenant. Optionally
 * switches the org's plan first, then extends the period and activates the org.
 */
class RecordPayment extends Command
{
    protected $signature = 'billing:record-payment
        {org : Organization id or slug}
        {amount : Amount paid, in major currency units (e.g. 80 for $80.00)}
        {--currency=USD}
        {--months=1 : Number of months the payment covers}
        {--plan= : Switch the org to this plan key first (free|starter|pro|enterprise)}
        {--reference= : Bank reference / receipt number}
        {--notes=}';

    protected $description = 'Record a manual (bank transfer) payment for a tenant and extend its subscription.';

    public function handle(PaymentProvider $provider, CurrentOrganization $current): int
    {
        $org = Organization::where('id', $this->argument('org'))
            ->orWhere('slug', $this->argument('org'))
            ->first();

        if (! $org) {
            $this->error("No organization matching '{$this->argument('org')}'.");

            return self::FAILURE;
        }

        // Optionally move the org to a different plan before charging.
        if ($plan = $this->option('plan')) {
            $target = Plan::where('key', $plan)->first();
            if (! $target) {
                $this->error("No plan with key '{$plan}'.");

                return self::FAILURE;
            }
            $current->runFor($org, fn () => $org->subscription?->update(['plan_id' => $target->id]));
        }

        $months = max(1, (int) $this->option('months'));
        $start = Carbon::now();
        // Extend from the current period end when it's still in the future.
        $existingEnd = $current->runFor($org, fn () => $org->subscription?->current_period_end);
        if ($existingEnd && $existingEnd->isFuture()) {
            $start = $existingEnd->copy();
        }
        $end = $start->copy()->addMonths($months);

        $payment = $provider->recordPayment(
            org: $org,
            amountCents: (int) round(((float) $this->argument('amount')) * 100),
            currency: strtoupper($this->option('currency')),
            periodStart: $start,
            periodEnd: $end,
            reference: $this->option('reference'),
            recordedBy: null,
            notes: $this->option('notes'),
        );

        $this->info('Payment recorded.');
        $this->table(['Field', 'Value'], [
            ['Organization', $org->name.' (#'.$org->id.')'],
            ['Amount', number_format($payment->amount_cents / 100, 2).' '.$payment->currency],
            ['Period', $start->toDateString().' → '.$end->toDateString()],
            ['Status', 'active'],
        ]);

        return self::SUCCESS;
    }
}
