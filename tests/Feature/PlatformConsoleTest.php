<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Services\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformConsoleTest extends TestCase
{
    use RefreshDatabase;

    private function platformAdmin(): User
    {
        $owner = app(TenantProvisioner::class)->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw')['owner'];
        $owner->forceFill(['is_platform_admin' => true])->save();

        return $owner;
    }

    public function test_non_platform_admin_is_forbidden(): void
    {
        $owner = app(TenantProvisioner::class)->provision('Globex', 'Owner', 'owner@globex.test', 'super-secret-pw')['owner'];

        $this->actingAs($owner)->get(route('platform.index'))->assertForbidden();
    }

    public function test_platform_admin_can_list_tenants(): void
    {
        $admin = $this->platformAdmin();
        app(TenantProvisioner::class)->provision('Globex', 'Owner', 'owner@globex.test', 'super-secret-pw');

        $this->actingAs($admin)->get(route('platform.index'))->assertOk();
    }

    public function test_platform_admin_can_suspend_and_resume_a_tenant(): void
    {
        $admin = $this->platformAdmin();
        $org = Organization::where('slug', 'acme')->first();

        $this->actingAs($admin)->post(route('platform.suspend', $org->id))->assertRedirect();
        $this->assertSame(Organization::STATUS_SUSPENDED, $org->fresh()->status);

        $this->actingAs($admin)->post(route('platform.resume', $org->id))->assertRedirect();
        $this->assertSame(Organization::STATUS_ACTIVE, $org->fresh()->status);
    }

    public function test_platform_admin_can_record_a_payment(): void
    {
        $admin = $this->platformAdmin();
        $org = Organization::where('slug', 'acme')->first();

        $this->actingAs($admin)
            ->post(route('platform.payment', $org->id), ['amount' => '80', 'months' => 1, 'currency' => 'USD'])
            ->assertRedirect();

        $this->assertSame(Organization::STATUS_ACTIVE, $org->fresh()->status);
        $this->assertSame('active', $org->fresh()->subscription->status);
    }

    public function test_platform_admin_can_override_a_tenants_entitlements_and_price(): void
    {
        $admin = $this->platformAdmin();
        $org = Organization::where('slug', 'acme')->first();

        // Put them on Free, then grant a higher screenshot cap + AI + a custom price.
        $org->subscription->update(['plan_id' => Plan::where('key', 'free')->first()->id]);

        $this->actingAs($admin)->put(route('platform.tenant.update', $org->id), [
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

    public function test_platform_admin_can_edit_a_plan(): void
    {
        $admin = $this->platformAdmin();
        Plan::ensureSeeded();
        $free = Plan::where('key', 'free')->first();

        $entitlements = collect(\App\Tenancy\Entitlements::keys())
            ->mapWithKeys(fn ($k) => [$k => $free->entitlement($k)])
            ->all();
        $entitlements['max_screenshots_per_hour'] = 9; // bump the free cap

        $this->actingAs($admin)->put(route('platform.plans.update', $free->id), [
            'name' => 'Free',
            'price_cents' => 0,
            'is_public' => true,
            'entitlements' => $entitlements,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(9, $free->fresh()->entitlement('max_screenshots_per_hour'));
    }

    public function test_plan_editor_is_off_limits_to_non_platform_admins(): void
    {
        $owner = app(TenantProvisioner::class)->provision('Globex', 'Owner', 'owner@globex.test', 'super-secret-pw')['owner'];

        $this->actingAs($owner)->get(route('platform.plans'))->assertForbidden();
    }
}
