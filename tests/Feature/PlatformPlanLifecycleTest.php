<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlatformAuditLog;
use App\Models\PlatformUser;
use App\Tenancy\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PLAN LIFECYCLE — the owner must be able to run pricing without a developer:
 * create, rename, duplicate, archive, restore, reorder, set the monthly AND
 * yearly price, choose the visibility, and pick the plan new signups land on.
 *
 * Role gate under test: `platform.role:admin` on every action.
 */
class PlatformPlanLifecycleTest extends TestCase
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
    }

    private function operator(string $role = 'admin', ?string $email = null): PlatformUser
    {
        return PlatformUser::create([
            'name' => ucfirst($role),
            'email' => $email ?: $role.'@platform.test',
            'password' => 'operator-secret-pw',
            'role' => $role,
        ]);
    }

    // -----------------------------------------------------------------------
    // Create
    // -----------------------------------------------------------------------

    public function test_admin_can_create_a_plan_and_it_grants_nothing_until_told_to(): void
    {
        $this->actingAs($this->operator(), 'platform')
            ->post(route('platform.plans.store'), [
                'name' => 'Team',
                'price_cents' => 1200,
                'price_cents_yearly' => 12000,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $plan = Plan::where('name', 'Team')->firstOrFail();

        $this->assertSame('team', $plan->key);
        $this->assertSame(1200, $plan->price_cents);
        $this->assertSame(12000, $plan->price_cents_yearly);

        // A new plan is a DRAFT: hidden, and not the signup plan.
        $this->assertSame('hidden', $plan->visibility);
        $this->assertFalse($plan->is_public);
        $this->assertFalse($plan->is_default_trial);

        // DEFAULT-DENY. Every flag off, and no ceiling silently set to unlimited.
        foreach (Entitlements::registry() as $key => $entry) {
            $this->assertSame(
                $entry['default'],
                $plan->entitlement($key),
                "New plan should start at the deny default for [$key]."
            );
        }
        $this->assertNotNull($plan->max_users, 'A new plan must not start with unlimited seats.');
    }

    public function test_plan_keys_do_not_collide(): void
    {
        $op = $this->operator();

        $this->actingAs($op, 'platform')->post(route('platform.plans.store'), ['name' => 'Team'])->assertRedirect();
        $this->actingAs($op, 'platform')->post(route('platform.plans.store'), ['name' => 'Team'])->assertRedirect();

        $this->assertSame(2, Plan::where('name', 'Team')->count());
        $this->assertSame(2, Plan::whereIn('key', ['team', 'team_2'])->count());
    }

    public function test_an_explicit_duplicate_key_is_rejected(): void
    {
        $this->actingAs($this->operator(), 'platform')
            ->post(route('platform.plans.store'), ['name' => 'Another Free', 'key' => 'free'])
            ->assertSessionHasErrors('key');
    }

    // -----------------------------------------------------------------------
    // Update
    // -----------------------------------------------------------------------

    public function test_admin_can_set_name_both_prices_visibility_and_entitlements(): void
    {
        $plan = Plan::where('key', 'starter')->firstOrFail();

        $entitlements = $plan->entitlementMap();
        $entitlements['ai'] = true;
        $entitlements['max_screenshots_per_hour'] = 40;
        // Blank on a CEILING means "no ceiling" in the plan editor.
        $entitlements['max_retention_days'] = '';

        $this->actingAs($this->operator(), 'platform')
            ->put(route('platform.plans.update', $plan->id), [
                'name' => 'Starter Plus',
                'price_cents' => 700,
                'price_cents_yearly' => 7000,
                'currency' => 'USD',
                'trial_days' => 21,
                'visibility' => 'legacy',
                'entitlements' => $entitlements,
                'reason' => 'Q3 repricing',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $plan->refresh();

        $this->assertSame('Starter Plus', $plan->name);
        $this->assertSame(700, $plan->price_cents);
        $this->assertSame(7000, $plan->price_cents_yearly);
        $this->assertSame('legacy', $plan->visibility);
        // The legacy boolean must not disagree with the lifecycle column.
        $this->assertFalse($plan->is_public);
        $this->assertTrue($plan->entitlement('ai'));
        $this->assertSame(40, $plan->entitlement('max_screenshots_per_hour'));
        $this->assertNull($plan->entitlement('max_retention_days'), 'Blank ceiling = unlimited.');

        $log = PlatformAuditLog::where('action', PlatformAuditLog::ACTION_PLAN_UPDATED)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('Q3 repricing', $log->reason);
        $this->assertSame('Starter', $log->before['name']);
        $this->assertSame('Starter Plus', $log->after['name']);
    }

    public function test_a_blank_floor_never_becomes_zero(): void
    {
        // 0 is the MOST permissive floor there is. Clearing the box must fall
        // back to the catalogue's strict default, not hand the feature away.
        $plan = Plan::where('key', 'free')->firstOrFail();

        $entitlements = $plan->entitlementMap();
        $entitlements['min_auto_pause_minutes'] = '';

        $this->actingAs($this->operator(), 'platform')
            ->put(route('platform.plans.update', $plan->id), [
                'name' => $plan->name,
                'price_cents' => $plan->price_cents,
                'visibility' => 'public',
                'entitlements' => $entitlements,
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(
            Entitlements::default('min_auto_pause_minutes'),
            $plan->fresh()->entitlement('min_auto_pause_minutes')
        );
    }

    // -----------------------------------------------------------------------
    // Duplicate / archive / restore
    // -----------------------------------------------------------------------

    public function test_duplicate_creates_a_hidden_copy_that_is_not_the_signup_plan(): void
    {
        $source = Plan::where('is_default_trial', true)->firstOrFail();

        $this->actingAs($this->operator(), 'platform')
            ->post(route('platform.plans.duplicate', $source->id))
            ->assertRedirect()->assertSessionHasNoErrors();

        $copy = Plan::where('key', $source->key.'_copy')->firstOrFail();

        $this->assertSame($source->price_cents, $copy->price_cents);
        $this->assertSame($source->entitlements, $copy->entitlements);
        $this->assertSame('hidden', $copy->visibility);
        $this->assertFalse($copy->is_default_trial);
        $this->assertNull($copy->archived_at);
    }

    public function test_archiving_hides_a_plan_but_keeps_existing_subscribers_resolving(): void
    {
        $plan = Plan::where('key', 'starter')->firstOrFail();

        $this->actingAs($this->operator(), 'platform')
            ->post(route('platform.plans.archive', $plan->id), ['reason' => 'Replaced by Team'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $plan->refresh();
        $this->assertNotNull($plan->archived_at);
        $this->assertFalse($plan->is_public);

        // The row survives, so a company still on it keeps its entitlements.
        $this->assertTrue($plan->entitlement('monitoring'));
        $this->assertFalse(Plan::visible()->pluck('key')->contains('starter'));

        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => PlatformAuditLog::ACTION_PLAN_ARCHIVED,
            'target_id' => $plan->id,
            'reason' => 'Replaced by Team',
        ]);
    }

    public function test_the_signup_plan_cannot_be_archived(): void
    {
        $plan = Plan::where('is_default_trial', true)->firstOrFail();

        $this->actingAs($this->operator(), 'platform')
            ->post(route('platform.plans.archive', $plan->id))
            ->assertSessionHasErrors('plan');

        $this->assertNull($plan->fresh()->archived_at);
    }

    public function test_the_last_active_plan_cannot_be_archived(): void
    {
        $keep = Plan::where('is_default_trial', true)->firstOrFail();
        // Retire everything else directly, leaving exactly one live plan…
        Plan::whereKeyNot($keep->id)->update(['archived_at' => now()]);
        // …and move the signup flag off it so that guard isn't the one that fires.
        $keep->forceFill(['is_default_trial' => false])->save();

        $this->actingAs($this->operator(), 'platform')
            ->post(route('platform.plans.archive', $keep->id))
            ->assertSessionHasErrors('plan');

        $this->assertNull($keep->fresh()->archived_at);
    }

    public function test_restore_brings_a_plan_back_hidden_not_public(): void
    {
        $plan = Plan::where('key', 'starter')->firstOrFail();
        $plan->forceFill(['archived_at' => now()])->save();

        $this->actingAs($this->operator(), 'platform')
            ->post(route('platform.plans.restore', $plan->id))
            ->assertRedirect()->assertSessionHasNoErrors();

        $plan->refresh();
        $this->assertNull($plan->archived_at);
        $this->assertSame('hidden', $plan->visibility);
        $this->assertFalse($plan->is_public);
    }

    // -----------------------------------------------------------------------
    // Reorder + default trial
    // -----------------------------------------------------------------------

    public function test_reorder_writes_sort_and_ignores_ids_that_are_not_plans(): void
    {
        $ids = Plan::ordered()->pluck('id')->all();
        $reversed = array_reverse($ids);

        $this->actingAs($this->operator(), 'platform')
            ->post(route('platform.plans.reorder'), ['order' => array_merge($reversed, [999999])])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($reversed, Plan::ordered()->pluck('id')->all());

        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => PlatformAuditLog::ACTION_PLAN_REORDERED,
        ]);
    }

    public function test_exactly_one_plan_can_be_the_signup_plan(): void
    {
        $target = Plan::where('key', 'free')->firstOrFail();

        $this->actingAs($this->operator(), 'platform')
            ->post(route('platform.plans.default-trial', $target->id))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue($target->fresh()->is_default_trial);
        $this->assertSame(1, Plan::where('is_default_trial', true)->count());
        $this->assertSame($target->id, Plan::defaultTrial()->id);
    }

    public function test_an_archived_plan_cannot_become_the_signup_plan(): void
    {
        $plan = Plan::where('key', 'starter')->firstOrFail();
        $plan->forceFill(['archived_at' => now()])->save();

        $this->actingAs($this->operator(), 'platform')
            ->post(route('platform.plans.default-trial', $plan->id))
            ->assertSessionHasErrors('plan');

        $this->assertFalse($plan->fresh()->is_default_trial);
    }

    // -----------------------------------------------------------------------
    // Seeding must never clobber the operator
    // -----------------------------------------------------------------------

    public function test_reseeding_never_overwrites_operator_pricing_edits(): void
    {
        // ensureSeeded() runs on every tenant provision and on the public
        // pricing page, so it must be a no-op for a catalogue that already
        // exists — otherwise every price change would be silently reverted.
        $plan = Plan::where('key', 'starter')->firstOrFail();

        $this->actingAs($this->operator(), 'platform')
            ->put(route('platform.plans.update', $plan->id), [
                'name' => 'Crew',
                'price_cents' => 4242,
                'price_cents_yearly' => 42420,
                'visibility' => 'hidden',
                'trial_days' => 3,
                'entitlements' => array_merge($plan->entitlementMap(), ['ai' => true]),
            ])->assertRedirect()->assertSessionHasNoErrors();

        Plan::ensureSeeded();

        $plan->refresh();
        $this->assertSame('Crew', $plan->name);
        $this->assertSame(4242, $plan->price_cents);
        $this->assertSame(42420, $plan->price_cents_yearly);
        $this->assertSame('hidden', $plan->visibility);
        $this->assertSame(3, $plan->trial_days);
        $this->assertTrue($plan->entitlement('ai'));
    }

    public function test_the_plans_page_carries_everything_the_editor_needs(): void
    {
        $this->actingAs($this->operator(), 'platform')
            ->get(route('platform.plans'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Plans')
                ->has('registry')
                ->has('visibilities', 4)
                ->has('plans.0.price_cents_yearly')
                ->has('plans.0.visibility')
                ->has('plans.0.is_default_trial')
                ->has('plans.0.subscribers')
                ->has('plans.0.entitlements'));
    }

    // -----------------------------------------------------------------------
    // Role gate
    // -----------------------------------------------------------------------

    public function test_support_cannot_touch_pricing(): void
    {
        $support = $this->operator('support');
        $plan = Plan::where('key', 'free')->firstOrFail();

        $this->actingAs($support, 'platform')->post(route('platform.plans.store'), ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($support, 'platform')->post(route('platform.plans.duplicate', $plan->id))->assertForbidden();
        $this->actingAs($support, 'platform')->post(route('platform.plans.archive', $plan->id))->assertForbidden();
        $this->actingAs($support, 'platform')->post(route('platform.plans.restore', $plan->id))->assertForbidden();
        $this->actingAs($support, 'platform')->post(route('platform.plans.reorder'), ['order' => [$plan->id]])->assertForbidden();
        $this->actingAs($support, 'platform')->post(route('platform.plans.default-trial', $plan->id))->assertForbidden();

        $this->assertDatabaseMissing('plans', ['name' => 'Nope']);
    }

    public function test_a_customer_cannot_reach_the_plan_lifecycle_endpoints(): void
    {
        $owner = app(\App\Services\TenantProvisioner::class)
            ->provision('Globex', 'Owner', 'owner@globex.test', 'super-secret-pw')['owner'];

        $this->actingAs($owner) // the customer `web` guard
            ->post(route('platform.plans.store'), ['name' => 'Sneaky'])
            ->assertRedirect(route('platform.login'));

        $this->assertDatabaseMissing('plans', ['name' => 'Sneaky']);
    }
}
