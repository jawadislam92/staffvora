<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\Organization;
use App\Models\User;
use App\Services\BillingPricing;
use App\Services\DemoDataService;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * REGRESSION — THE SEAT MARKER IS A COLUMN, NOT A STRING COMPARISON.
 *
 * ─── WHAT BROKE, AND WHY THE SUITE COULD NOT SEE IT ─────────────────────────
 * Sample-data teammates do not consume plan seats, so "is this row sample
 * data?" is a billing and plan-limit decision. It used to be answered twice,
 * from the e-mail address, by two mechanisms that compare strings DIFFERENTLY:
 *
 *   • the REFUSAL  — byte-exact PHP: `str_ends_with(strtolower(trim($email)), …)`
 *   • the EXCLUSION — SQL `LIKE`, evaluated in the column's collation
 *
 * `config/database.php` puts MySQL on `utf8mb4_unicode_ci`, which folds case
 * AND accents. So in production `'demo-9-1@demo.staffvora.İnvalid' LIKE
 * '%@demo.staffvora.invalid'` was TRUE while PHP's comparison was FALSE: the
 * guard let the address through and the seat count hid the row. Eight ordinary
 * `POST /users` requests produced nine active, login-capable users on a
 * two-seat plan with `seatUsage()` and `billableSeats()` both reporting 1.
 *
 * SQLite — this suite — does not fold, so an outcome-only test is green on
 * SQLite and red on MySQL. That is the trap, and every test in this file is
 * written to avoid it: NONE of them depends on how the database compares two
 * strings. They assert the shape of the decision instead —
 *
 *   the set of rows the seat exclusion hides == the set of rows DEMO MODE
 *   CREATED, and nothing else, whatever any address says.
 *
 * ─── HOW TO BREAK THEM ON PURPOSE ───────────────────────────────────────────
 * Put an `email LIKE` back into `User::scopeExcludingDemoIdentities()` and
 * every test below fails on SQLite too, because they hand the exclusion rows
 * whose address is the reserved domain BYTE FOR BYTE — no folding required.
 */
class SeatMarkerCannotBeForgedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Addresses an attacker can put in front of the guard. Deliberately mixed:
     * the exact marker, ASCII case games, whitespace, and the non-ASCII
     * confusables that a *_ci collation folds onto the reserved domain
     * (U+0130 İ, U+017F ſ, U+00DF ß, accented vowels, fullwidth forms).
     */
    private const ADVERSARIAL = [
        // Numbered from 71 so they never collide with the cast demo mode really
        // mints (demo-<org>-1 … demo-<org>-3).
        'exact marker' => 'demo-1-71@demo.staffvora.invalid',
        'exact marker, other org' => 'demo-99-72@demo.staffvora.invalid',
        'uppercase domain' => 'demo-1-73@DEMO.STAFFVORA.INVALID',
        'mixed case' => 'Demo-1-74@Demo.Staffvora.Invalid',
        'trailing space' => 'demo-1-75@demo.staffvora.invalid ',
        'leading space' => ' demo-1-76@demo.staffvora.invalid',
        'dotted capital I (U+0130)' => "demo-1-77@demo.staffvora.\xC4\xB0nvalid",
        'long s (U+017F)' => "demo-1-78@demo.\xC5\xBFtaffvora.invalid",
        'sharp s (U+00DF)' => "demo-1-79@demo.\xC3\x9Ftaffvora.invalid",
        'a-acute' => "demo-1-80@demo.staffvor\xC3\xA1.invalid",
        'e-acute' => "demo-1-81@d\xC3\xA9mo.staffvora.invalid",
        'n-tilde' => "demo-1-82@demo.staffvora.i\xC3\xB1valid",
        'i-diaeresis' => "demo-1-83@demo.staffvora.\xC3\xAFnvalid",
        'a-ring' => "demo-1-84@demo.st\xC3\xA5ffvora.invalid",
        'fullwidth d' => "demo-1-85@\xEF\xBD\x84emo.staffvora.invalid",
        'plus tag' => 'demo-1-86+x@demo.staffvora.invalid',
        'subdomain' => 'demo-1-87@a.demo.staffvora.invalid',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function makeOwner(int $seats = 2): User
    {
        $result = app(TenantProvisioner::class)
            ->provision('Acme Co', 'Owner', 'owner@acme.test', 'super-secret-pw', null, 14, 'general');
        app(CurrentOrganization::class)->set($result['organization']);
        $result['organization']->update(['entitlement_overrides' => ['max_users' => $seats]]);
        $result['organization']->forgetResolvedEntitlements();

        return $result['owner']->fresh();
    }

    /**
     * Put a row in the `users` table with NO model, NO validation and NO guard —
     * the strongest attacker there is, and also exactly what a stray console
     * script, a raw import or a future bulk-insert path looks like. Whatever
     * this row's address says, it was not created by demo mode, so it MUST cost
     * a seat.
     */
    private function smuggleRow(int $orgId, string $email, string $name = 'Smuggled'): int
    {
        return DB::table('users')->insertGetId([
            'organization_id' => $orgId,
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('a-very-long-password-1'),
            'role' => 'member',
            'permissions' => json_encode([]),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. THE CLASS-CATCHER: hidden ⟺ created by demo mode
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * THE INVARIANT. The set the seat exclusion HIDES and the set demo mode
     * CREATED must be the same set — for every address, on every engine.
     *
     * Collation-independent by construction: several of these addresses are the
     * reserved domain byte for byte, so SQLite and MySQL agree that a `LIKE`
     * matches them. If the exclusion is a string comparison, this fails on both.
     */
    public function test_the_seat_exclusion_hides_exactly_the_rows_demo_mode_created(): void
    {
        $owner = $this->makeOwner(seats: 50);
        $orgId = $owner->organization_id;

        // The genuine article — the ONLY rows that may ever be free.
        app(DemoDataService::class)->load();
        $genuine = User::demoIdentities()->pluck('id')->sort()->values()->all();
        $this->assertNotEmpty($genuine, 'demo mode created nothing to compare against');

        // Every forgery an attacker could get into the table, by any route.
        $forged = [];
        foreach (self::ADVERSARIAL as $label => $email) {
            $forged[$label] = $this->smuggleRow($orgId, $email, 'Forged '.$label);
        }

        $hidden = User::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->demoIdentities()
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $stolen = array_intersect($hidden, array_values($forged));

        $this->assertSame(
            [],
            array_values($stolen),
            'FREE SEATS: rows nobody in demo mode created are hidden from the seat count. '
            .'The exclusion is reading the e-mail address again — it must read `is_demo`, '
            .'a column only User::asDemoIdentityWriter() can write. Forged: '
            .implode(', ', array_keys(array_filter($forged, fn ($id) => in_array($id, $stolen, true)))),
        );

        $this->assertSame($genuine, $hidden, 'the hidden set must be EXACTLY demo mode\'s own rows');

        // …and the mirror image: every forgery is billed and counted.
        $counted = User::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->seatConsuming()
            ->pluck('id')
            ->all();

        foreach ($forged as $label => $id) {
            $this->assertContains($id, $counted, "[{$label}] is an unbilled, uncounted, free seat");
        }
    }

    /**
     * The structural version of the same statement, and the cheapest possible
     * guard against the whole bug class: the seat exclusion's SQL must not
     * mention the e-mail column at all. A predicate that never looks at a string
     * cannot be decided by a collation.
     */
    public function test_the_seat_exclusion_does_not_compare_strings_at_all(): void
    {
        $sql = strtolower(User::query()->withoutGlobalScopes()->excludingDemoIdentities()->toSql());

        $this->assertStringNotContainsString('email', $sql, 'the seat exclusion is back to matching addresses');
        $this->assertStringNotContainsString('like', $sql, 'the seat exclusion is back to a collation-dependent LIKE');
        $this->assertStringContainsString('is_demo', $sql, 'the seat exclusion must read the explicit marker');
    }

    /**
     * The address is a LABEL. Rewriting it underneath the application — in
     * either direction, with no model involved — must not move a single seat.
     */
    public function test_rewriting_an_address_in_the_database_moves_no_seats(): void
    {
        $owner = $this->makeOwner(seats: 50);
        $orgId = $owner->organization_id;

        app(DemoDataService::class)->load();
        $realHire = $this->smuggleRow($orgId, 'real@acme.test', 'Real Hire');
        $before = User::seatUsage();

        // Direction 1 — a real person's address is made to look like sample data.
        DB::table('users')->where('id', $realHire)->update(['email' => 'demo-'.$orgId.'-99@demo.staffvora.invalid']);

        $this->assertSame($before, User::seatUsage(), 'A REAL SEAT VANISHED because its address was rewritten');

        // Direction 2 — a sample row's address is made to look real.
        $demoId = User::demoIdentities()->value('id');
        DB::table('users')->where('id', $demoId)->update(['email' => 'laundered@acme.test']);

        $this->assertSame($before, User::seatUsage(), 'A FREE ROW BECAME A SEAT because its address was rewritten');
        $this->assertTrue(User::whereKey($demoId)->first()->isDemoIdentity(), 'the marker followed the label');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. THE CUSTOMER-REACHABLE HALF: nothing a request can create is free
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Drive every adversarial address through the real `POST /users` form. Each
     * one is either REFUSED, or it is created AND it costs a seat. There is no
     * third outcome — "accepted and free" is the bug.
     */
    public function test_every_address_a_customer_can_submit_is_refused_or_costs_a_seat(): void
    {
        $owner = $this->makeOwner(seats: 50);
        $free = [];

        foreach (self::ADVERSARIAL as $label => $email) {
            $before = User::seatUsage();

            $response = $this->actingAs($owner)->post(route('users.store'), [
                'name' => 'Hire '.$label,
                'email' => $email,
                'password' => 'a-very-long-password-1',
            ]);

            $created = User::query()->where('name', 'Hire '.$label)->exists();
            $after = User::seatUsage();

            if ($created && $after !== $before + 1) {
                $free[] = $label;
            }

            // A refusal must be a refusal, not a 500 and not a silent success.
            if (! $created) {
                $response->assertSessionHasErrors('email');
            }
        }

        $this->assertSame(
            [],
            $free,
            'FREE SEATS via POST /users — these addresses were accepted and cost nothing: '.implode(', ', $free),
        );
    }

    /**
     * The end-to-end version of the live exploit: eight ordinary hire requests
     * on a two-seat plan. The plan holds, and the two counters still agree.
     */
    public function test_eight_forged_hires_cannot_grow_a_two_seat_plan(): void
    {
        $owner = $this->makeOwner(seats: 2);

        $i = 0;
        foreach (self::ADVERSARIAL as $email) {
            $this->actingAs($owner)->post(route('users.store'), [
                'name' => 'Attacker '.(++$i),
                'email' => $email,
                'password' => 'a-very-long-password-1',
            ]);
        }

        $active = User::query()->where('is_active', true)->count();

        $this->assertLessThanOrEqual(2, $active, "PLAN BYPASSED: {$active} active users on a two-seat plan");
        $this->assertSame(
            max(1, User::seatUsage()),
            app()->makeWith(BillingPricing::class, [])->billableSeats($owner->organization->fresh()),
            'the plan-limit counter and the invoice disagree — one of them is reading addresses',
        );
    }

    /** The marker itself is not writable from anywhere but demo mode. */
    public function test_the_marker_cannot_be_set_by_mass_assignment_or_by_hand(): void
    {
        $owner = $this->makeOwner(seats: 50);

        $viaMassAssignment = User::create([
            'name' => 'Sneaky',
            'email' => 'sneaky@acme.test',
            'password' => 'a-very-long-password-1',
            'role' => 'member',
            'is_demo' => true,
        ]);

        $this->assertFalse((bool) $viaMassAssignment->fresh()->is_demo, 'mass assignment minted a free seat');

        $byHand = new User([
            'name' => 'Sneakier',
            'email' => 'sneakier@acme.test',
            'password' => 'a-very-long-password-1',
            'role' => 'member',
        ]);
        $byHand->is_demo = true;
        $byHand->organization_id = $owner->organization_id;
        $byHand->save();

        $this->assertFalse((bool) $byHand->fresh()->is_demo, 'a direct property write minted a free seat');

        // And an existing row's marker is frozen in both directions.
        $this->expectException(\RuntimeException::class);
        $byHand->fresh()->forceFill(['is_demo' => true])->save();
    }

    /** Demo mode's own rows still keep the marker — and clearing it is refused. */
    public function test_demo_mode_still_owns_its_own_marker(): void
    {
        $owner = $this->makeOwner(seats: 50);
        app(DemoDataService::class)->load();

        $demo = User::demoIdentities()->firstOrFail();
        $this->assertTrue((bool) $demo->is_demo);
        $this->assertTrue($demo->isDemoIdentity());

        $this->expectException(\RuntimeException::class);
        $demo->forceFill(['is_demo' => false])->save();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. A LIMIT THAT VANISHES MUST NOT BECOME A LOCK-OUT
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Retiring the `max_users` feature from the operator panel used to resolve
     * to `false`, which `Organization::seatLimit()` cast to `0` — collapsing
     * EVERY tenant's ceiling to zero at once: no hire, no reactivation, no
     * invite, platform-wide, from one toggle. A ceiling that is switched off is
     * an absent ceiling, never a ceiling of nothing.
     */
    public function test_retiring_the_seat_limit_feature_does_not_lock_every_tenant_out(): void
    {
        $owner = $this->makeOwner(seats: 2);
        $org = $owner->organization->fresh();

        $this->assertSame(2, $org->seatLimit());

        Feature::where('key', 'max_users')->firstOrFail()->update(['is_active' => false]);
        Entitlements::bump();
        Entitlements::flushMemo();

        $after = $org->fresh()->seatLimit();

        $this->assertNotSame(0, $after, 'RETIRING A LIMIT LOCKED EVERY TENANT OUT OF HIRING');
        $this->assertTrue($after === null || $after >= 2, 'a retired ceiling must never shrink below what the plan sold');

        // And the hire that this used to block still goes through.
        $this->actingAs($owner)->post(route('users.store'), [
            'name' => 'Still Hireable',
            'email' => 'hire@acme.test',
            'password' => 'a-very-long-password-1',
        ]);

        $this->assertTrue(
            User::query()->where('email', 'hire@acme.test')->exists(),
            'an operator toggle stopped a paying customer hiring anyone at all',
        );
    }

    /** A workspace with NO plan at all must not be locked out either. */
    public function test_a_retired_limit_never_resolves_to_zero_for_anyone(): void
    {
        $bare = Organization::factory()->create(['status' => Organization::STATUS_ACTIVE]);

        Feature::where('key', 'max_users')->firstOrFail()->update(['is_active' => false]);
        Entitlements::bump();
        Entitlements::flushMemo();

        $this->assertNull($bare->fresh()->seatLimit(), 'a retired ceiling must be absent, not zero');
    }

    /** Retiring a CAPABILITY still revokes it — the fail-safe is for limits only. */
    public function test_retiring_a_capability_still_revokes_it(): void
    {
        $owner = $this->makeOwner(seats: 2);
        $org = $owner->organization->fresh();

        Feature::where('key', 'ai')->firstOrFail()->update(['is_active' => false]);
        Entitlements::bump();
        Entitlements::flushMemo();

        $this->assertFalse($org->fresh()->can('ai'), 'retiring a feature must still revoke it');
        $this->assertFalse($org->fresh()->can('never_heard_of_this'), 'an unknown capability must deny');
    }
}
