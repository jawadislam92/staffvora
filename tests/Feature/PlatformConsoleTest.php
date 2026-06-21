<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlatformUser;
use App\Services\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformConsoleTest extends TestCase
{
    use RefreshDatabase;

    /** An operator-portal account — the separate `platform` guard, no tenant. */
    private function operator(string $role = 'owner'): PlatformUser
    {
        return PlatformUser::create([
            'name' => 'Operator',
            'email' => 'op@platform.test',
            'password' => 'operator-secret-pw',
            'role' => $role,
        ]);
    }

    public function test_guest_is_redirected_to_the_operator_login(): void
    {
        $this->get(route('platform.index'))->assertRedirect(route('platform.login'));
    }

    public function test_customer_cannot_reach_the_console_even_with_the_legacy_flag(): void
    {
        // The old `users.is_platform_admin` flag must no longer grant console
        // access: operators authenticate against a separate guard now, so a
        // customer (web guard) is bounced to the operator login regardless.
        $owner = app(TenantProvisioner::class)->provision('Globex', 'Owner', 'owner@globex.test', 'super-secret-pw')['owner'];
        $owner->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($owner) // web guard
            ->get(route('platform.index'))
            ->assertRedirect(route('platform.login'));

        $this->actingAs($owner)
            ->get(route('platform.plans'))
            ->assertRedirect(route('platform.login'));
    }

    public function test_operator_can_list_tenants(): void
    {
        app(TenantProvisioner::class)->provision('Globex', 'Owner', 'owner@globex.test', 'super-secret-pw');

        $this->actingAs($this->operator(), 'platform')
            ->get(route('platform.index'))
            ->assertOk();
    }

    public function test_operator_can_suspend_and_resume_a_tenant(): void
    {
        $org = app(TenantProvisioner::class)->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw')['organization'];
        $op = $this->operator();

        $this->actingAs($op, 'platform')->post(route('platform.suspend', $org->id))->assertRedirect();
        $this->assertSame(Organization::STATUS_SUSPENDED, $org->fresh()->status);

        $this->actingAs($op, 'platform')->post(route('platform.resume', $org->id))->assertRedirect();
        $this->assertSame(Organization::STATUS_ACTIVE, $org->fresh()->status);
    }

    public function test_operator_can_record_a_payment(): void
    {
        $org = app(TenantProvisioner::class)->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw')['organization'];

        $this->actingAs($this->operator(), 'platform')
            ->post(route('platform.payment', $org->id), ['amount' => '80', 'months' => 1, 'currency' => 'USD'])
            ->assertRedirect();

        $this->assertSame(Organization::STATUS_ACTIVE, $org->fresh()->status);
        $this->assertSame('active', $org->fresh()->subscription->status);
    }

    public function test_operator_can_override_a_tenants_entitlements_and_price(): void
    {
        $org = app(TenantProvisioner::class)->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw')['organization'];

        // Put them on Free, then grant a higher screenshot cap + AI + a custom price.
        $org->subscription->update(['plan_id' => Plan::where('key', 'free')->first()->id]);

        $this->actingAs($this->operator(), 'platform')->put(route('platform.tenant.update', $org->id), [
            'overrides' => ['max_screenshots_per_hour' => 25, 'ai' => '1', 'app_url_tracking' => ''],
            'custom_price_cents' => 350,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $org->refresh();
        $this->assertSame(25, $org->entitlement('max_screenshots_per_hour'));
        $this->assertTrue($org->can('ai'));
        $this->assertSame(350, $org->priceCents());
        // "Inherit" (blank) entries are not stored as overrides.
        $this->assertArrayNotHasKey('app_url_tracking', $org->entitlement_overrides ?? []);
    }

    public function test_operator_can_edit_a_plan(): void
    {
        Plan::ensureSeeded();
        $free = Plan::where('key', 'free')->first();

        $entitlements = collect(\App\Tenancy\Entitlements::keys())
            ->mapWithKeys(fn ($k) => [$k => $free->entitlement($k)])
            ->all();
        $entitlements['max_screenshots_per_hour'] = 9; // bump the free cap

        $this->actingAs($this->operator(), 'platform')->put(route('platform.plans.update', $free->id), [
            'name' => 'Free',
            'price_cents' => 0,
            'is_public' => true,
            'entitlements' => $entitlements,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(9, $free->fresh()->entitlement('max_screenshots_per_hour'));
    }

    public function test_plan_editor_is_off_limits_to_customers(): void
    {
        $owner = app(TenantProvisioner::class)->provision('Globex', 'Owner', 'owner@globex.test', 'super-secret-pw')['owner'];

        $this->actingAs($owner)->get(route('platform.plans'))->assertRedirect(route('platform.login'));
    }
}
