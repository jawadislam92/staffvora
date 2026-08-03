<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DemoDataService;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REGRESSION: sample-data teammates cannot be LAUNDERED into real seats.
 *
 * This is the inversion of the audit's proof test. The hole it proved:
 * `UserController::update()` rewrote a user's e-mail and password with no seat
 * check, and a demo teammate is a real `users` row that is merely EXCLUDED from
 * {@see User::seatUsage()} by its address. Renaming it turned a free row into a
 * real, counted, LOGIN-CAPABLE seat — and because
 * {@see DemoDataService::isLoaded()} keys on that same address, the workspace
 * then read as "no demo loaded" and could mint three more. Proven to eight real
 * users on a two-seat plan.
 *
 * The defence is at the MODEL layer (User::booted freezes a demo row's identity
 * in BOTH directions) with a 403 at the controller so the admin sees a refusal
 * rather than a 500, plus the directory no longer offering the Edit button at
 * all. Each of those is asserted separately below — any one of them being
 * "simplified away" must fail here.
 */
class DemoIdentitiesAreImmutableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function makeOwner(): User
    {
        $result = app(TenantProvisioner::class)
            ->provision('Acme Co', 'Owner', 'owner@acme.test', 'super-secret-pw', null, 14, 'general');
        app(CurrentOrganization::class)->set($result['organization']);

        return $result['owner'];
    }

    public function test_demo_teammates_cannot_be_laundered_into_real_seats(): void
    {
        $owner = $this->makeOwner();
        $owner->organization->update(['entitlement_overrides' => ['max_users' => 2]]);
        $owner->organization->forgetResolvedEntitlements();

        $this->assertSame(2, $owner->organization->seatLimit());

        // Fill the plan honestly: owner + 1 real hire = 2 of 2.
        $this->actingAs($owner)->post(route('users.store'), [
            'name' => 'Real Hire', 'email' => 'real@acme.test', 'password' => 'a-very-long-password',
        ])->assertRedirect(route('users.index'));
        $this->assertSame(2, User::seatUsage());

        // A third real hire is correctly refused.
        $this->actingAs($owner)->post(route('users.store'), [
            'name' => 'Blocked', 'email' => 'blocked@acme.test', 'password' => 'a-very-long-password',
        ])->assertSessionHasErrors('email');

        // Load the sample (free, by design).
        $this->actingAs($owner)->post(route('onboarding.demo.load'));
        $demoUsers = User::demoIdentities()->get();
        $this->assertCount(3, $demoUsers);
        $this->assertSame(2, User::seatUsage(), 'demo rows start off the seat count');

        // ── The bypass, refused ─────────────────────────────────────────────
        foreach ($demoUsers as $i => $demoUser) {
            $this->actingAs($owner)
                ->put(route('users.update', $demoUser), [
                    'name' => 'Smuggled '.$i,
                    'email' => "smuggled{$i}@acme.test",
                    'password' => 'a-very-long-password',
                ])
                ->assertForbidden();
        }

        // Nothing moved: still two real seats, still three demo rows, and not
        // one of the smuggled addresses exists.
        $this->assertSame(2, User::seatUsage());
        $this->assertCount(3, User::demoIdentities()->get());
        $this->assertSame(0, User::where('email', 'like', 'smuggled%')->count());

        // The demo rows are still un-signed-into-able: the laundered account
        // the attack was after does not exist, so the login cannot succeed.
        $this->post(route('logout'));
        $this->assertGuest();
        $this->post(route('login'), [
            'email' => 'smuggled0@acme.test',
            'password' => 'a-very-long-password',
        ]);
        $this->assertGuest();

        // And the workspace still knows a demo IS loaded, so it cannot mint a
        // second free cast.
        $this->assertTrue(app(DemoDataService::class)->isLoaded());
        $this->actingAs($owner)->post(route('onboarding.demo.load'));
        $this->assertCount(3, User::demoIdentities()->get());
        $this->assertSame(2, User::seatUsage(), 'a 2-seat plan still carries exactly 2 real users');
    }

    /**
     * The controller 403 is a courtesy. The floor is the model, which every
     * write path — console command, factory, a feature written next year —
     * goes through.
     */
    public function test_the_model_refuses_to_rename_a_demo_row_out_of_the_namespace(): void
    {
        $this->makeOwner();
        app(DemoDataService::class)->load();

        $demoUser = User::demoIdentities()->firstOrFail();

        try {
            $demoUser->update(['email' => 'stolen@acme.test', 'password' => 'a-very-long-password']);
            $this->fail('renaming a demo identity out of the reserved namespace must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('reserved', $e->getMessage());
        }

        $this->assertSame(1, User::seatUsage());
        $this->assertCount(3, User::demoIdentities()->get());
    }

    /** Role / permission / tenancy escalation on a demo row is frozen too. */
    public function test_a_demo_rows_role_permissions_and_tenancy_are_frozen(): void
    {
        $this->makeOwner();
        app(DemoDataService::class)->load();

        // Set directly rather than via update(): organization_id is not
        // fillable, and the point is that the GUARD stops these, not mass
        // assignment.
        foreach ([
            ['role', 'super_admin'],
            ['permissions', ['users.manage']],
            ['organization_id', 999999],
            ['password', 'a-very-long-password'],
        ] as [$attribute, $value]) {
            $demoUser = User::demoIdentities()->firstOrFail();
            $demoUser->{$attribute} = $value;

            $refused = false;
            try {
                $demoUser->save();
            } catch (\RuntimeException $e) {
                $refused = true;
                $this->assertStringContainsString('reserved', $e->getMessage());
            }

            $this->assertTrue($refused, "a demo identity must not accept a new {$attribute}");
        }
    }

    /**
     * Cosmetic columns stay writable on purpose: freezing EVERY column would
     * make any future save() on a demo row a 500 rather than a no-op.
     */
    public function test_cosmetic_edits_to_a_demo_row_still_work(): void
    {
        $this->makeOwner();
        app(DemoDataService::class)->load();

        $demoUser = User::demoIdentities()->firstOrFail();
        $demoUser->update(['designation' => 'Something Else']);

        $this->assertSame('Something Else', $demoUser->fresh()->designation);
    }

    /** The Edit button is not offered at all: demo rows are not staff. */
    public function test_the_user_directory_hides_sample_teammates_unless_asked_for(): void
    {
        $owner = $this->makeOwner();
        app(DemoDataService::class)->load();

        $listed = fn (array $query) => collect(
            $this->actingAs($owner)->get(route('users.index', $query))
                ->viewData('page')['props']['users']['data']
        )->pluck('email');

        $this->assertCount(1, $listed([]), 'the directory shows real staff only by default');
        $this->assertCount(4, $listed(['sample' => 'include']));
        $this->assertCount(3, $listed(['sample' => 'only']));

        // An unknown value must fall back to hiding them, never to showing.
        $this->assertCount(1, $listed(['sample' => 'whatever-i-like']));

        // And the edit screen itself refuses.
        $demoUser = User::demoIdentities()->firstOrFail();
        $this->actingAs($owner)->get(route('users.edit', $demoUser))->assertForbidden();
    }

    /**
     * The reserved namespace must be refused by every CUSTOMER-SUPPLIED e-mail
     * field, with a readable field error — the model guard is the floor, not
     * the first line of defence, and a 500 is not an answer.
     */
    public function test_every_self_service_email_field_refuses_the_reserved_namespace(): void
    {
        $owner = $this->makeOwner();
        $forged = 'demo-1-1@'.User::DEMO_EMAIL_DOMAIN;

        // Self-service profile e-mail change.
        $this->actingAs($owner)
            ->patch(route('profile.update'), ['name' => 'Owner', 'email' => $forged])
            ->assertSessionHasErrors('email');

        // Admin "create user".
        $this->actingAs($owner)->post(route('users.store'), [
            'name' => 'Fake', 'email' => $forged, 'password' => 'a-very-long-password',
        ])->assertSessionHasErrors('email');

        // Onboarding invite.
        $this->actingAs($owner)->post(route('onboarding.team'), [
            'name' => 'Fake', 'email' => $forged,
        ])->assertSessionHasErrors('email');

        // Admin edit of an existing real person.
        $real = User::create([
            'name' => 'Real', 'email' => 'real@acme.test',
            'password' => 'a-very-long-password', 'role' => 'member',
        ]);
        $this->actingAs($owner)->put(route('users.update', $real), [
            'name' => 'Real', 'email' => $forged,
        ])->assertSessionHasErrors('email');

        $this->assertSame(
            0,
            User::withoutGlobalScopes()->where('email', 'like', '%@'.User::DEMO_EMAIL_DOMAIN)->count(),
            'not one forged demo identity may exist anywhere in the install',
        );
    }

    /** Bulk edit must skip demo rows rather than throw halfway through. */
    public function test_bulk_edit_skips_sample_teammates_without_breaking_the_batch(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)->post(route('users.store'), [
            'name' => 'Real Hire', 'email' => 'real@acme.test', 'password' => 'a-very-long-password',
        ]);
        app(DemoDataService::class)->load();

        $real = User::where('email', 'real@acme.test')->firstOrFail();
        $demoIds = User::demoIdentities()->pluck('id')->all();

        $this->actingAs($owner)->post(route('users.bulk-update'), [
            'user_ids' => [$real->id, ...$demoIds],
            'set_designation' => true,
            'designation' => 'Bulk Applied',
        ])->assertOk();

        $this->assertSame('Bulk Applied', $real->fresh()->designation);

        foreach (User::demoIdentities()->get() as $demoUser) {
            $this->assertNotSame('Bulk Applied', $demoUser->designation);
        }
    }
}
