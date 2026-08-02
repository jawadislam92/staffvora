<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlatformAuditLog;
use App\Models\PlatformUser;
use App\Services\TenantProvisioner;
use App\Tenancy\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * THE FEATURE CATALOGUE — the thing that makes "add or remove features from any
 * plan" real rather than a code edit.
 *
 * Role gate under test: `platform.role:owner`, deliberately stricter than the
 * rest of the console, because deactivating a row here revokes a capability for
 * every workspace at once.
 */
class PlatformFeatureCatalogueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The new operator pages are not in the committed Vite manifest until
        // the next `npm run build`; stub Vite so a page render under test is
        // about the CONTROLLER, not the asset pipeline.
        $this->withoutVite();
        Entitlements::flushMemo();
        Feature::flushCatalogue();
    }

    private function operator(string $role = 'owner', ?string $email = null): PlatformUser
    {
        return PlatformUser::create([
            'name' => ucfirst($role),
            'email' => $email ?: $role.'@platform.test',
            'password' => 'operator-secret-pw',
            'role' => $role,
        ]);
    }

    private function tenant(): Organization
    {
        return app(TenantProvisioner::class)
            ->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw')['organization'];
    }

    // -----------------------------------------------------------------------
    // Adding a feature needs no code change
    // -----------------------------------------------------------------------

    public function test_a_brand_new_feature_appears_everywhere_and_is_denied_by_default(): void
    {
        $org = $this->tenant();

        $this->assertNotContains('call_recording', Entitlements::keys());

        $this->actingAs($this->operator(), 'platform')
            ->post(route('platform.features.store'), [
                'key' => 'call_recording',
                'label' => 'Call recording',
                'help' => 'Record support calls.',
                'kind' => 'flag',
                'group' => 'Features',
                'default_value' => false,
                'reason' => 'New upsell',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        Entitlements::flushMemo();

        // It is now part of the catalogue — every editor and the UI lock map
        // pick it up with no code change…
        $this->assertContains('call_recording', Entitlements::keys());
        $this->assertArrayHasKey('call_recording', $org->fresh()->entitlements());

        // …and default-deny means nobody has it yet.
        $this->assertFalse($org->fresh()->can('call_recording'));

        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => PlatformAuditLog::ACTION_FEATURE_CREATED,
            'reason' => 'New upsell',
        ]);
    }

    public function test_a_plan_can_then_grant_the_new_feature_without_a_deploy(): void
    {
        $org = $this->tenant();
        $owner = $this->operator();

        $this->actingAs($owner, 'platform')->post(route('platform.features.store'), [
            'key' => 'call_recording', 'label' => 'Call recording', 'kind' => 'flag',
            'group' => 'Features', 'default_value' => false,
        ])->assertRedirect();

        Entitlements::flushMemo();

        $plan = $org->activePlan();
        $this->actingAs($owner, 'platform')->put(route('platform.plans.update', $plan->id), [
            'name' => $plan->name,
            'price_cents' => $plan->price_cents,
            'visibility' => $plan->visibility ?: 'public',
            'entitlements' => array_merge($plan->entitlementMap(), ['call_recording' => true]),
        ])->assertRedirect()->assertSessionHasNoErrors();

        Entitlements::flushMemo();

        $this->assertTrue($org->fresh()->can('call_recording'));
    }

    // -----------------------------------------------------------------------
    // Safety rules
    // -----------------------------------------------------------------------

    public function test_deactivating_a_feature_revokes_it_for_everyone_immediately(): void
    {
        $org = $this->tenant();
        $plan = $org->activePlan();

        // Make sure the plan really does grant it first.
        $plan->forceFill(['entitlements' => array_merge($plan->entitlements, ['ai' => true])])->save();
        Entitlements::flushMemo();
        $this->assertTrue($org->fresh()->can('ai'));

        $feature = Feature::where('key', 'ai')->firstOrFail();

        $this->actingAs($this->operator(), 'platform')
            ->put(route('platform.features.update', $feature->id), [
                'label' => $feature->label,
                'kind' => $feature->kind,
                'group' => $feature->group,
                'default_value' => false,
                'is_active' => false,
                'reason' => 'Retiring the AI add-on',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        Entitlements::flushMemo();

        // The plan's JSON still says true; the catalogue is the authority.
        $this->assertTrue((bool) $plan->fresh()->entitlements['ai']);
        $this->assertFalse($org->fresh()->can('ai'));
        $this->assertNotContains('ai', Entitlements::keys());
    }

    public function test_a_feature_key_can_never_be_changed(): void
    {
        $feature = Feature::where('key', 'ai')->firstOrFail();

        $this->actingAs($this->operator(), 'platform')
            ->put(route('platform.features.update', $feature->id), [
                'key' => 'something_else',
                'label' => 'Renamed label',
                'kind' => 'flag',
                'group' => 'Features',
                'default_value' => false,
                'is_active' => true,
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $feature->refresh();
        // Renaming means the LABEL moved; the identifier every stored grant is
        // written against did not.
        $this->assertSame('ai', $feature->key);
        $this->assertSame('Renamed label', $feature->label);
    }

    public function test_a_numeric_feature_can_never_default_to_unlimited(): void
    {
        // A default is what applies when nobody granted anything, so an
        // unlimited default would give the feature away for free.
        $this->actingAs($this->operator(), 'platform')
            ->post(route('platform.features.store'), [
                'key' => 'max_projects',
                'label' => 'Projects',
                'kind' => 'max',
                'group' => 'Limits',
                'default_value' => null,
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $feature = Feature::where('key', 'max_projects')->firstOrFail();

        $this->assertNotNull($feature->default_value);
        $this->assertSame(0, $feature->defaultValue());
    }

    public function test_a_bad_key_is_rejected(): void
    {
        $this->actingAs($this->operator(), 'platform')
            ->post(route('platform.features.store'), [
                'key' => 'Not A Key!',
                'label' => 'Nope',
                'kind' => 'flag',
                'group' => 'Features',
            ])
            ->assertSessionHasErrors('key');

        $this->assertDatabaseMissing('features', ['label' => 'Nope']);
    }

    public function test_reorder_writes_the_display_order(): void
    {
        $ids = Feature::ordered()->pluck('id')->all();
        $reversed = array_reverse($ids);

        $this->actingAs($this->operator(), 'platform')
            ->post(route('platform.features.reorder'), ['order' => array_merge($reversed, [987654])])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($reversed, Feature::ordered()->pluck('id')->all());
    }

    public function test_the_catalogue_page_shows_who_grants_each_feature(): void
    {
        $plan = Plan::where('key', 'pro')->firstOrFail();
        $plan->forceFill(['entitlements' => array_merge($plan->entitlements, ['ai' => true])])->save();
        Entitlements::flushMemo();

        $owner = $this->operator();

        $this->actingAs($owner, 'platform')
            ->get(route('platform.features'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Features')
                ->has('features')
                ->has('kinds')
                ->where('features.0.key', 'max_users'));

        $payload = $this->actingAs($owner, 'platform')
            ->get(route('platform.features'))
            ->viewData('page')['props']['features'];

        $ai = collect($payload)->firstWhere('key', 'ai');
        $this->assertContains($plan->name, $ai['granted_by']['plans']);
    }

    // -----------------------------------------------------------------------
    // Role gate — owner only
    // -----------------------------------------------------------------------

    public function test_the_catalogue_is_owner_only(): void
    {
        $admin = $this->operator('admin', 'admin@platform.test');
        $support = $this->operator('support', 'support@platform.test');
        $feature = Feature::where('key', 'ai')->firstOrFail();

        foreach ([$admin, $support] as $actor) {
            $this->actingAs($actor, 'platform')->get(route('platform.features'))->assertForbidden();
            $this->actingAs($actor, 'platform')->post(route('platform.features.store'), [
                'key' => 'sneaky', 'label' => 'Sneaky', 'kind' => 'flag', 'group' => 'Features',
            ])->assertForbidden();
            $this->actingAs($actor, 'platform')->put(route('platform.features.update', $feature->id), [
                'label' => 'x', 'kind' => 'flag', 'group' => 'Features', 'is_active' => false,
            ])->assertForbidden();
            $this->actingAs($actor, 'platform')->post(route('platform.features.reorder'), ['order' => [$feature->id]])->assertForbidden();
        }

        $this->assertDatabaseMissing('features', ['key' => 'sneaky']);
        $this->assertTrue($feature->fresh()->is_active);
    }

    public function test_a_customer_cannot_reach_the_catalogue(): void
    {
        $owner = app(TenantProvisioner::class)
            ->provision('Globex', 'Owner', 'owner@globex.test', 'super-secret-pw')['owner'];

        $this->actingAs($owner)->get(route('platform.features'))->assertRedirect(route('platform.login'));
    }
}
