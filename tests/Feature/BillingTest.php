<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_signup_starts_a_pro_trial_subscription(): void
    {
        app(TenantProvisioner::class)->provision('Acme Inc', 'Owner', 'owner@acme.test', 'super-secret-pw');

        $org = Organization::where('slug', 'acme-inc')->first();
        $sub = $org->subscription;

        $this->assertNotNull($sub);
        $this->assertSame('trialing', $sub->status);
        $this->assertSame('pro', $sub->plan->key);
        $this->assertSame(50, $org->seatLimit());
        $this->assertTrue($org->planFeature('ai'));
    }

    public function test_plan_drives_seat_limit_and_features(): void
    {
        Plan::ensureSeeded();
        $org = Organization::create(['name' => 'A', 'slug' => 'a-co']);
        $free = Plan::where('key', 'free')->first();

        app(CurrentOrganization::class)->runFor($org, fn () => Subscription::create([
            'plan_id' => $free->id,
            'status' => 'active',
            'current_period_end' => now()->addMonth(),
        ]));

        $org->refresh();
        $this->assertSame(3, $org->seatLimit());
        $this->assertFalse($org->planFeature('monitoring'));
        $this->assertFalse($org->planFeature('ai'));
    }

    public function test_seat_limit_blocks_adding_users_beyond_the_plan(): void
    {
        $owner = app(TenantProvisioner::class)->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw')['owner'];

        // Downgrade to Free (3 seats); the owner is seat #1.
        app(CurrentOrganization::class)->runFor($owner->organization_id, fn () =>
            $owner->organization->subscription->update(['plan_id' => Plan::where('key', 'free')->first()->id]));

        $add = fn (int $n) => $this->actingAs($owner)->post(route('users.store'), [
            'name' => "User $n", 'email' => "user$n@acme.test", 'password' => 'super-secret-pw', 'role' => 'member',
        ]);

        $add(2)->assertSessionHasNoErrors(); // seat #2
        $add(3)->assertSessionHasNoErrors(); // seat #3
        $add(4)->assertSessionHasErrors('email'); // would be #4 — blocked
    }

    public function test_record_payment_activates_the_org_and_extends_the_period(): void
    {
        $owner = app(TenantProvisioner::class)->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw')['owner'];
        $org = Organization::find($owner->organization_id);

        $this->artisan('billing:record-payment', ['org' => $org->id, 'amount' => '80', '--months' => 2])->assertExitCode(0);

        $org->refresh();
        $this->assertSame(Organization::STATUS_ACTIVE, $org->status);

        $sub = $org->subscription;
        $this->assertSame('active', $sub->status);
        $this->assertNotNull($sub->current_period_end);
        $this->assertTrue($sub->current_period_end->isFuture());

        $paymentCount = app(CurrentOrganization::class)->runFor($org->id, fn () => \App\Models\Payment::count());
        $this->assertSame(1, $paymentCount);
    }
}
