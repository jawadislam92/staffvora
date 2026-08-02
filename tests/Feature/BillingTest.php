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
        $this->assertSame(2, $org->seatLimit());
        // Free is time tracking only: screenshots + activity start at Starter.
        $this->assertFalse($org->planFeature('monitoring'));
        $this->assertSame(0, $org->entitlement('max_screenshots_per_hour'));
        $this->assertSame(7, $org->entitlement('max_retention_days'));
        $this->assertFalse($org->planFeature('ai'));
        $this->assertFalse($org->planFeature('activity_tracking'));
    }

    public function test_seat_limit_blocks_adding_users_beyond_the_plan(): void
    {
        $owner = app(TenantProvisioner::class)->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw')['owner'];

        // Downgrade to Free (2 seats); the owner is seat #1.
        app(CurrentOrganization::class)->runFor($owner->organization_id, fn () =>
            $owner->organization->subscription->update(['plan_id' => Plan::where('key', 'free')->first()->id]));

        $add = fn (int $n) => $this->actingAs($owner)->post(route('users.store'), [
            'name' => "User $n", 'email' => "user$n@acme.test", 'password' => 'super-secret-pw', 'role' => 'member',
        ]);

        $add(2)->assertSessionHasNoErrors(); // seat #2
        $add(3)->assertSessionHasErrors('email'); // would be #3 — blocked
    }

    public function test_billing_page_shows_plan_entitlements_and_the_orgs_price(): void
    {
        $owner = app(TenantProvisioner::class)->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw')['owner'];

        // A negotiated custom price should be what the org sees as "your price".
        app(CurrentOrganization::class)->runFor($owner->organization_id, fn () =>
            \App\Models\Organization::find($owner->organization_id)->update(['custom_price_cents' => 333]));

        $this->actingAs($owner)
            ->get(route('billing.index'))
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->where('subscription.price_cents', 333)
                ->where('subscription.has_custom_price', true)
                ->has('plans.0.caps')
                ->has('plans.0.flags')
            );
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

    public function test_suspended_workspace_is_blocked_but_billing_and_logout_remain_available(): void
    {
        $owner = app(TenantProvisioner::class)->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw')['owner'];
        $owner->organization->update(['status' => Organization::STATUS_SUSPENDED]);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertRedirect(route('billing.index'))
            ->assertSessionHas('error');

        $this->actingAs($owner)->get(route('billing.index'))->assertOk();
        $this->actingAs($owner)->post(route('logout'))->assertRedirect('/');
    }

    public function test_expired_trial_is_blocked_until_payment_is_recorded(): void
    {
        $owner = app(TenantProvisioner::class)->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw')['owner'];
        $owner->organization->subscription->update([
            'status' => 'trialing',
            'trial_ends_at' => now()->subMinute(),
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertRedirect(route('billing.index'));

        $this->actingAs($owner)->get(route('billing.index'))->assertOk();
    }
}
