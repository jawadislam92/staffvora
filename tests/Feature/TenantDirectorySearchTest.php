<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PlatformUser;
use App\Services\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The operator tenant directory: search must reach the customer's EMAIL (a
 * support ticket rarely carries the workspace name), and the "Trial ends"
 * column must only show a date for tenants that are actually trialing.
 */
class TenantDirectorySearchTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): PlatformUser
    {
        return PlatformUser::create([
            'name' => 'Operator',
            'email' => 'op@platform.test',
            'password' => 'operator-secret-pw',
            'role' => 'owner',
        ]);
    }

    public function test_operator_can_find_a_tenant_by_its_owners_email(): void
    {
        app(TenantProvisioner::class)->provision('Globex', 'Owner', 'owner@globex.test', 'super-secret-pw');
        app(TenantProvisioner::class)->provision('Initech', 'Owner', 'owner@initech.test', 'super-secret-pw');

        // The workspace name is "Globex" — the operator only has the email.
        $this->actingAs($this->operator(), 'platform')
            ->get(route('platform.index', ['q' => 'owner@globex.test']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Platform/Index')
                ->where('pagination.total', 1)
                ->has('organizations', 1)
                ->where('organizations.0.name', 'Globex'));
    }

    public function test_trial_end_date_is_hidden_for_non_trialing_tenants(): void
    {
        $active = app(TenantProvisioner::class)->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw')['organization'];
        $trial = app(TenantProvisioner::class)->provision('Umbrella', 'Owner', 'owner@umbrella.test', 'super-secret-pw')['organization'];

        // Both carry a trial_ends_at (it is set at signup and never cleared),
        // but only the trialing one is genuinely on a trial.
        $active->forceFill(['status' => Organization::STATUS_ACTIVE, 'trial_ends_at' => now()->addDays(14)])->save();
        $trial->forceFill(['status' => Organization::STATUS_TRIAL, 'trial_ends_at' => now()->addDays(14)])->save();

        $this->actingAs($this->operator(), 'platform')
            ->get(route('platform.index'))
            ->assertOk()
            ->assertInertia(function ($p) {
                $rows = collect($p->toArray()['props']['organizations']);
                $this->assertNull($rows->firstWhere('name', 'Acme')['trial_ends_at'], 'Active tenant must not show a trial-end date.');
                $this->assertNotNull($rows->firstWhere('name', 'Umbrella')['trial_ends_at'], 'Trialing tenant must show its trial-end date.');
            });
    }
}
