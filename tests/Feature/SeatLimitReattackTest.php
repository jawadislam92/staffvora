<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\BillingPricing;
use App\Services\DemoDataService;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ADVERSARIAL RE-ATTACK on the plan seat limit. Every test here TRIES to break
 * the invariant; a test that reports "I got a free seat" means the hole is open.
 */
class SeatLimitReattackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array{0: User, 1: Organization} */
    private function tenant(int $seats, string $slug = 'acme'): array
    {
        $result = app(TenantProvisioner::class)->provision(
            'Acme '.$slug, 'Owner', "owner@{$slug}.test", 'super-secret-pw-123', null, 14, 'general'
        );
        app(CurrentOrganization::class)->set($result['organization']);
        $result['organization']->update(['entitlement_overrides' => ['max_users' => $seats]]);
        $result['organization']->forgetResolvedEntitlements();

        return [$result['owner'], $result['organization']->fresh()];
    }

    private function usage(Organization $org): int
    {
        return app(CurrentOrganization::class)->runFor($org, fn () => User::seatUsage());
    }

    /** Every ACTIVE user row in the org that DemoDataService did not create. */
    private function realActiveRows(Organization $org): int
    {
        return app(CurrentOrganization::class)->runFor($org, fn () => User::query()
            ->where('is_active', true)
            ->get()
            ->reject(fn (User $u) => str_contains(strtolower($u->email), 'staffvora.invalid'))
            ->count());
    }

    private function payload(string $email, array $extra = []): array
    {
        return array_merge([
            'name' => 'Hire',
            'email' => $email,
            'password' => 'a-very-strong-password-1',
        ], $extra);
    }

    // ── A1: brute-force the admin create endpoint ──────────────────────────

    public function test_attack_admin_create_cannot_exceed_the_plan(): void
    {
        [$owner, $org] = $this->tenant(3);

        for ($i = 1; $i <= 12; $i++) {
            $this->actingAs($owner)->post('/users', $this->payload("hire{$i}@acme.test"));
        }

        $this->assertLessThanOrEqual(3, $this->usage($org->fresh()), 'ADMIN CREATE BROKE THE LIMIT');
        $this->assertLessThanOrEqual(3, $this->realActiveRows($org->fresh()));
    }

    // ── A2: deactivate → hire → reactivate ─────────────────────────────────

    public function test_attack_deactivate_hire_reactivate_is_not_a_free_seat(): void
    {
        [$owner, $org] = $this->tenant(2);

        $this->actingAs($owner)->post('/users', $this->payload('a@acme.test'));
        $a = User::where('email', 'a@acme.test')->firstOrFail();
        $this->assertSame(2, $this->usage($org->fresh()));

        // Free a seat, fill it, then try to get the freed one back.
        $this->actingAs($owner)->patch("/users/{$a->id}/status", ['is_active' => false]);
        $this->actingAs($owner)->post('/users', $this->payload('b@acme.test'));
        $this->assertSame(2, $this->usage($org->fresh()));

        $this->actingAs($owner)->patch("/users/{$a->id}/status", ['is_active' => true]);

        $this->assertSame(2, $this->usage($org->fresh()), 'REACTIVATION MINTED A FREE SEAT');
        $this->assertFalse((bool) $a->fresh()->is_active);
    }

    // ── A3/A4: bulk reactivation, explicit ids AND select-all-matching ─────

    public function test_attack_bulk_reactivate_by_ids_cannot_exceed(): void
    {
        [$owner, $org] = $this->tenant(3);
        $ids = $this->makeDeactivated($owner, $org, 8);

        $this->actingAs($owner)->post('/users/bulk-status', ['is_active' => true, 'ids' => $ids]);

        $this->assertSame(3, $this->usage($org->fresh()), 'BULK IDS BROKE THE LIMIT');
    }

    public function test_attack_bulk_reactivate_all_matching_cannot_exceed(): void
    {
        [$owner, $org] = $this->tenant(3);
        $this->makeDeactivated($owner, $org, 8);

        $this->actingAs($owner)->post('/users/bulk-status', ['is_active' => true, 'all_matching' => true]);

        $this->assertSame(3, $this->usage($org->fresh()), 'ALL-MATCHING BULK BROKE THE LIMIT');
    }

    public function test_attack_repeated_bulk_reactivation_calls_cannot_accumulate(): void
    {
        [$owner, $org] = $this->tenant(3);
        $ids = $this->makeDeactivated($owner, $org, 8);

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($owner)->post('/users/bulk-status', ['is_active' => true, 'ids' => $ids]);
        }

        $this->assertSame(3, $this->usage($org->fresh()), 'REPEATED BULK CALLS ACCUMULATED SEATS');
    }

    /** Seed $n deactivated members straight into the org (bypassing the limit deliberately). */
    private function makeDeactivated(User $owner, Organization $org, int $n): array
    {
        return app(CurrentOrganization::class)->runFor($org, function () use ($n) {
            $ids = [];
            for ($i = 1; $i <= $n; $i++) {
                $u = User::create([
                    'name' => "Dormant {$i}",
                    'email' => "dormant{$i}@acme.test",
                    'password' => Hash::make('x-very-strong-pw-123'),
                    'role' => 'member',
                ]);
                $u->is_active = false;
                $u->save();
                $ids[] = $u->id;
            }

            return $ids;
        });
    }

    // ── A5: the demo namespace, attacked from every request-reachable write ─

    public static function reservedEmailVariants(): array
    {
        return [
            'exact marker' => ['demo-1-1@demo.staffvora.invalid'],
            'uppercase domain' => ['demo-1-1@DEMO.STAFFVORA.INVALID'],
            'mixed case' => ['DeMo-1-1@Demo.Staffvora.Invalid'],
            'other org id' => ['demo-999-3@demo.staffvora.invalid'],
            'non numeric local' => ['demo-abc-1@demo.staffvora.invalid'],
            'leading space' => [' demo-1-1@demo.staffvora.invalid'],
            'trailing space' => ['demo-1-1@demo.staffvora.invalid '],
            'not the marker shape' => ['anything@demo.staffvora.invalid'],
            'plus tag' => ['demo-1-1+x@demo.staffvora.invalid'],
        ];
    }

    #[DataProvider('reservedEmailVariants')]
    public function test_attack_admin_create_in_the_reserved_namespace_is_refused(string $email): void
    {
        [$owner, $org] = $this->tenant(2);
        $before = $this->usage($org);

        try {
            $this->actingAs($owner)->post('/users', $this->payload($email));
        } catch (\Throwable $e) {
            // A throw is also a refusal.
        }

        $created = app(CurrentOrganization::class)->runFor($org, fn () => User::query()->count());
        $this->assertSame(1, $created, "A ROW WAS CREATED FOR [{$email}]");
        $this->assertSame($before, $this->usage($org->fresh()));
    }

    #[DataProvider('reservedEmailVariants')]
    public function test_attack_onboarding_invite_in_the_reserved_namespace_is_refused(string $email): void
    {
        [$owner, $org] = $this->tenant(5);

        try {
            $this->actingAs($owner)->post('/onboarding/team', ['name' => 'X', 'email' => $email]);
        } catch (\Throwable $e) {
        }

        $created = app(CurrentOrganization::class)->runFor($org, fn () => User::query()->count());
        $this->assertSame(1, $created, "INVITE CREATED A FREE ROW FOR [{$email}]");
    }

    #[DataProvider('reservedEmailVariants')]
    public function test_attack_self_registration_in_the_reserved_namespace_is_refused(string $email): void
    {
        try {
            $this->post('/register', [
                'company' => 'Evil', 'name' => 'Evil', 'email' => $email,
                'password' => 'a-very-strong-password-1',
                'password_confirmation' => 'a-very-strong-password-1',
            ]);
        } catch (\Throwable $e) {
        }

        $this->assertSame(0, User::query()->withoutGlobalScopes()->count(), "REGISTRATION CREATED [{$email}]");
    }

    #[DataProvider('reservedEmailVariants')]
    public function test_attack_self_profile_rename_into_the_namespace_is_refused(string $email): void
    {
        [$owner, $org] = $this->tenant(2);
        $this->actingAs($owner)->post('/users', $this->payload('victim@acme.test'));
        $victim = User::where('email', 'victim@acme.test')->firstOrFail();

        try {
            $this->actingAs($victim)->patch('/profile', ['name' => 'Victim', 'email' => $email]);
        } catch (\Throwable $e) {
        }

        $this->assertSame('victim@acme.test', $victim->fresh()->email, "PROFILE RENAME SUCCEEDED FOR [{$email}]");
        $this->assertSame(2, $this->usage($org->fresh()), 'SELF-RENAME BOUGHT A FREE SEAT');
    }

    #[DataProvider('reservedEmailVariants')]
    public function test_attack_admin_rename_into_the_namespace_is_refused(string $email): void
    {
        [$owner, $org] = $this->tenant(2);
        $this->actingAs($owner)->post('/users', $this->payload('victim@acme.test'));
        $victim = User::where('email', 'victim@acme.test')->firstOrFail();

        try {
            $this->actingAs($owner)->put("/users/{$victim->id}", [
                'name' => 'Victim', 'email' => $email, 'role' => 'member',
            ]);
        } catch (\Throwable $e) {
        }

        $this->assertSame('victim@acme.test', $victim->fresh()->email, "ADMIN RENAME SUCCEEDED FOR [{$email}]");
        $this->assertSame(2, $this->usage($org->fresh()));
    }

    // ── A6: convert a real demo row into a real seat, by any route ─────────

    public function test_attack_convert_a_demo_teammate_by_every_route(): void
    {
        [$owner, $org] = $this->tenant(2);
        app(CurrentOrganization::class)->runFor($org, fn () => app(DemoDataService::class)->load());

        $demo = app(CurrentOrganization::class)->runFor(
            $org, fn () => User::query()->demoIdentities()->firstOrFail()
        );

        // 1. the admin edit form
        $this->actingAs($owner)
            ->put("/users/{$demo->id}", ['name' => 'Real', 'email' => 'real@acme.test', 'role' => 'member'])
            ->assertForbidden();

        // 2. bulk edit
        $this->actingAs($owner)->post('/users/bulk-update', [
            'user_ids' => [$demo->id], 'set_designation' => true, 'designation' => 'Owned',
        ]);

        // 3. status toggle
        $this->actingAs($owner)->patch("/users/{$demo->id}/status", ['is_active' => true])->assertForbidden();

        // 4. bulk status
        $this->actingAs($owner)->post('/users/bulk-status', ['is_active' => true, 'ids' => [$demo->id]]);

        // 5. the model, directly (mass assignment)
        try {
            app(CurrentOrganization::class)->runFor($org, fn () => $demo->update([
                'email' => 'real2@acme.test', 'password' => Hash::make('x-very-strong-pw-123'), 'role' => 'super_admin',
            ]));
            $this->fail('MASS ASSIGNMENT CONVERTED A DEMO ROW');
        } catch (\RuntimeException $e) {
        }

        // 6. forceFill, which skips $fillable
        try {
            app(CurrentOrganization::class)->runFor($org, fn () => $demo->forceFill(['email' => 'real3@acme.test'])->save());
            $this->fail('FORCEFILL CONVERTED A DEMO ROW');
        } catch (\RuntimeException $e) {
        }

        // 7. saveQuietly — skips events? (it must not be reachable, but prove the state)
        $this->assertTrue(User::isReservedDemoEmail($demo->fresh()->email), 'DEMO ROW LEFT THE NAMESPACE');
        $this->assertSame(1, $this->usage($org->fresh()), 'A DEMO ROW BECAME A SEAT');
    }

    // ── A7: the load / remove cycle ────────────────────────────────────────

    public function test_attack_demo_load_remove_cycle_grants_nothing(): void
    {
        [$owner, $org] = $this->tenant(2);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($owner)->post('/onboarding/demo-data');
            $this->assertSame(1, $this->usage($org->fresh()), 'DEMO LOAD CONSUMED/GRANTED SEATS');
            $this->actingAs($owner)->delete('/onboarding/demo-data');
        }

        // and a second load without removing must not double the cast
        $this->actingAs($owner)->post('/onboarding/demo-data');
        $this->actingAs($owner)->post('/onboarding/demo-data');
        $demoRows = app(CurrentOrganization::class)->runFor($org, fn () => User::query()->demoIdentities()->count());

        $this->assertSame(3, $demoRows, 'A SECOND LOAD MINTED MORE DEMO ROWS');
        $this->assertSame(1, $this->usage($org->fresh()));

        // the real hire the customer paid for still fits
        $this->actingAs($owner)->post('/users', $this->payload('real@acme.test'));
        $this->assertSame(2, $this->usage($org->fresh()));
    }

    public function test_attack_delete_demo_rows_then_reload_grants_no_real_seats(): void
    {
        [$owner, $org] = $this->tenant(2);
        app(CurrentOrganization::class)->runFor($org, fn () => app(DemoDataService::class)->load());

        // DELETE /users/{user} does NOT carry the demo guard — delete them all,
        // then reload to try to accumulate rows.
        $ids = app(CurrentOrganization::class)->runFor($org, fn () => User::query()->demoIdentities()->pluck('id')->all());
        foreach ($ids as $id) {
            $this->actingAs($owner)->delete("/users/{$id}");
        }

        app(CurrentOrganization::class)->runFor($org, fn () => app(DemoDataService::class)->load());

        $this->assertSame(1, $this->usage($org->fresh()), 'DELETE+RELOAD MINTED SEATS');
        $this->assertSame(1, $this->realActiveRows($org->fresh()));
    }

    // ── A8: the two counters must agree in EVERY state, including mid-demo ─

    public function test_billing_and_limit_counters_agree_in_every_state(): void
    {
        [$owner, $org] = $this->tenant(5);
        $states = [];

        $check = function (string $label) use ($org, &$states) {
            $org = $org->fresh();
            $limit = $this->usage($org);
            $billed = app()->makeWith(BillingPricing::class, [])->billableSeats($org);
            $states[$label] = [$limit, $billed];
            $this->assertSame(max(1, $limit), $billed, "COUNTERS DISAGREE: {$label}");
        };

        $check('fresh');

        $this->actingAs($owner)->post('/users', $this->payload('a@acme.test'));
        $check('one hire');

        app(CurrentOrganization::class)->runFor($org, fn () => app(DemoDataService::class)->load());
        $check('demo loaded');

        $a = User::query()->withoutGlobalScopes()->where('email', 'a@acme.test')->firstOrFail();
        $this->actingAs($owner)->patch("/users/{$a->id}/status", ['is_active' => false]);
        $check('demo loaded + one deactivated');

        $this->actingAs($owner)->post('/users', $this->payload('b@acme.test'));
        $check('demo loaded + deactivated + new hire');

        app(CurrentOrganization::class)->runFor($org, fn () => app(DemoDataService::class)->remove());
        $check('demo removed');

        $this->assertNotEmpty($states);
    }

    // ── A9: can a tenant lift its own ceiling? ─────────────────────────────

    public function test_attack_tenant_cannot_raise_its_own_seat_ceiling(): void
    {
        [$owner, $org] = $this->tenant(2);

        // mass-assign an override through every tenant-writable org endpoint
        $this->actingAs($owner)->put('/onboarding/workspace-type', [
            'workspace_type' => 'agency',
            'entitlement_overrides' => ['max_users' => 'unlimited'],
        ]);
        $this->actingAs($owner)->post('/onboarding/complete', [
            'entitlement_overrides' => ['max_users' => 'unlimited'],
        ]);
        $this->actingAs($owner)->post('/settings/branding', [
            'display_name' => 'X',
            'entitlement_overrides' => ['max_users' => 'unlimited'],
        ]);
        $this->actingAs($owner)->post('/billing/addons', ['addon' => 'agency_toolkit', 'cycle' => 'monthly']);

        $this->assertSame(2, $org->fresh()->seatLimit(), 'A TENANT RAISED ITS OWN CEILING');

        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($owner)->post('/users', $this->payload("hire{$i}@acme.test"));
        }
        $this->assertSame(2, $this->usage($org->fresh()));
    }

    // ── A10: agency signup add-on must not carry seats ─────────────────────

    public function test_attack_agency_signup_does_not_buy_seats(): void
    {
        $this->post('/register', [
            'company' => 'Agency', 'name' => 'Owner', 'email' => 'agency@x.test',
            'password' => 'a-very-strong-password-1',
            'password_confirmation' => 'a-very-strong-password-1',
            'workspace_type' => 'agency',
        ]);

        $general = Organization::query()->firstOrFail();
        $this->assertNotNull($general->seatLimit(), 'AGENCY SIGNUP BOUGHT UNLIMITED SEATS');
    }

    // ── COLLATERAL: honest admin work must still function ──────────────────

    public function test_collateral_an_honest_admin_can_still_manage_real_users(): void
    {
        [$owner, $org] = $this->tenant(10);

        // create
        $this->actingAs($owner)->post('/users', $this->payload('real@acme.test'))
            ->assertRedirect(route('users.index'));
        $real = User::query()->withoutGlobalScopes()->where('email', 'real@acme.test')->firstOrFail();

        // edit (including renaming to another ordinary address)
        $this->actingAs($owner)->put("/users/{$real->id}", [
            'name' => 'Renamed', 'email' => 'renamed@acme.test', 'role' => 'member',
        ])->assertRedirect();
        $this->assertSame('renamed@acme.test', $real->fresh()->email);

        // deactivate + reactivate
        $this->actingAs($owner)->patch("/users/{$real->id}/status", ['is_active' => false]);
        $this->assertFalse((bool) $real->fresh()->is_active);
        $this->actingAs($owner)->patch("/users/{$real->id}/status", ['is_active' => true]);
        $this->assertTrue((bool) $real->fresh()->is_active);

        // bulk edit
        $this->actingAs($owner)->post('/users/bulk-update', [
            'user_ids' => [$real->id], 'set_designation' => true, 'designation' => 'Engineer',
        ])->assertOk();
        $this->assertSame('Engineer', $real->fresh()->designation);

        // bulk deactivate + bulk reactivate, within headroom
        $this->actingAs($owner)->post('/users/bulk-status', ['is_active' => false, 'ids' => [$real->id]]);
        $this->assertFalse((bool) $real->fresh()->is_active);
        $this->actingAs($owner)->post('/users/bulk-status', ['is_active' => true, 'ids' => [$real->id]]);
        $this->assertTrue((bool) $real->fresh()->is_active);

        // self profile edit still works
        $this->actingAs($real)->patch('/profile', ['name' => 'Self', 'email' => 'self@acme.test'])
            ->assertRedirect();
        $this->assertSame('self@acme.test', $real->fresh()->email);

        $this->assertSame(2, $this->usage($org->fresh()));
    }

    public function test_collateral_an_unlimited_plan_is_never_blocked_or_serialised(): void
    {
        [$owner, $org] = $this->tenant(2);
        $org->update(['entitlement_overrides' => ['max_users' => 'unlimited']]);
        $org->forgetResolvedEntitlements();
        $this->assertNull($org->fresh()->seatLimit());

        $queries = 0;
        DB::listen(function ($q) use (&$queries) {
            if (str_contains(strtolower($q->sql), 'for update')) {
                $queries++;
            }
        });

        for ($i = 1; $i <= 6; $i++) {
            $this->actingAs($owner)->post('/users', $this->payload("many{$i}@acme.test"));
        }

        $this->assertSame(7, $this->usage($org->fresh()), 'AN UNLIMITED PLAN WAS BLOCKED');
        $this->assertSame(0, $queries, 'AN UNLIMITED PLAN TOOK A ROW LOCK');
    }
}
