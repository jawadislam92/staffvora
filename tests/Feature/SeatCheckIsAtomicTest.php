<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * REGRESSION: the seat check is not a check-then-act race.
 *
 * The audit's finding: `POST /users` read `seatUsage() >= $limit` and inserted
 * the row afterwards, as two separate statements outside any transaction. Fire
 * fifty concurrently and all fifty read the same pre-insert count, all fifty
 * pass, and a two-seat plan ends up with fifty-one users.
 *
 * PHPUnit cannot fire fifty genuinely concurrent requests, so this asserts the two
 * properties that make concurrency safe instead of trying to observe it:
 *
 *   1. the check and the INSERT happen inside ONE transaction, and the
 *      `organizations` row is read for update inside it (the serialisation
 *      point every writer queues on);
 *   2. the count is re-derived INSIDE that transaction, not passed in from a
 *      read that happened earlier.
 *
 * Plus a source-level guard on `lockForUpdate()` — SQLite compiles the lock to
 * an empty string, so no runtime assertion in this suite can see it, and
 * without this a refactor could silently drop the only thing protecting
 * production (MySQL) while the suite stayed green.
 */
class SeatCheckIsAtomicTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function makeOwner(int $seats): User
    {
        $result = app(TenantProvisioner::class)
            ->provision('Acme Co', 'Owner', 'owner@acme.test', 'super-secret-pw', null, 14, 'general');
        app(CurrentOrganization::class)->set($result['organization']);

        $result['organization']->update(['entitlement_overrides' => ['max_users' => $seats]]);
        $result['organization']->forgetResolvedEntitlements();

        return $result['owner'];
    }

    public function test_the_seat_check_and_the_insert_share_one_transaction(): void
    {
        $owner = $this->makeOwner(5);

        $sawOrganizationLockRead = false;
        $insertTransactionLevel = null;

        DB::listen(function ($query) use (&$sawOrganizationLockRead, &$insertTransactionLevel) {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'organizations') && str_starts_with($sql, 'select')) {
                $sawOrganizationLockRead = true;
            }

            if (str_starts_with($sql, 'insert into "users"') || str_starts_with($sql, 'insert into `users`')) {
                $insertTransactionLevel = DB::transactionLevel();
            }
        });

        $this->actingAs($owner)->post(route('users.store'), [
            'name' => 'Hire', 'email' => 'hire@acme.test', 'password' => 'a-very-long-password',
        ])->assertRedirect(route('users.index'));

        $this->assertNotNull($insertTransactionLevel, 'no users INSERT was observed');
        $this->assertGreaterThanOrEqual(
            1,
            $insertTransactionLevel,
            'The seat check and the INSERT must run inside one transaction, or two concurrent '.
            'requests both read the same pre-insert seat count.',
        );
        $this->assertTrue(
            $sawOrganizationLockRead,
            'The organizations row must be read (FOR UPDATE) so concurrent writers serialise on it.',
        );
    }

    /**
     * The count must be read under the lock, not before it. A callback that
     * itself fills the last seat has to be visible to the very next guard.
     */
    public function test_the_seat_count_is_re_derived_inside_the_lock(): void
    {
        $owner = $this->makeOwner(2);
        $organization = $owner->organization;

        // Seat 2 of 2, taken inside the guard.
        User::withSeatGuard($organization, fn () => User::create([
            'name' => 'Hire One',
            'email' => 'one@acme.test',
            'password' => 'a-very-long-password',
            'role' => 'member',
        ]));

        $this->assertSame(2, User::seatUsage());

        // The next guard must see it — this is the assertion the racing version
        // failed, because it counted before anyone had inserted.
        try {
            User::withSeatGuard($organization, fn () => User::create([
                'name' => 'Hire Two',
                'email' => 'two@acme.test',
                'password' => 'a-very-long-password',
                'role' => 'member',
            ]));
            $this->fail('the seat guard must refuse the seat that was just taken');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }

        $this->assertSame(2, User::seatUsage());
        $this->assertSame(0, User::where('email', 'two@acme.test')->count());
    }

    /** A refused seat must not leave a half-written row behind. */
    public function test_a_refused_seat_rolls_back_cleanly(): void
    {
        $owner = $this->makeOwner(1);
        $baseline = DB::transactionLevel();

        $this->actingAs($owner)->post(route('users.store'), [
            'name' => 'Too Many', 'email' => 'toomany@acme.test', 'password' => 'a-very-long-password',
        ])->assertSessionHasErrors('email');

        $this->assertSame(0, User::where('email', 'toomany@acme.test')->count());
        $this->assertSame(1, User::seatUsage());
        // RefreshDatabase already holds one transaction open around the test,
        // so "nothing left open" means back to that baseline, not zero.
        $this->assertSame($baseline, DB::transactionLevel(), 'the refused transaction must not be left open');
    }

    /** Unlimited plans take no lock at all — nothing to serialise. */
    public function test_an_unlimited_plan_does_not_serialise_signups(): void
    {
        $owner = $this->makeOwner(5);
        $owner->organization->update([
            'entitlement_overrides' => Entitlements::sanitizeOverrides(['max_users' => 'unlimited']),
        ]);
        $owner->organization->refresh();
        $owner->organization->forgetResolvedEntitlements();

        $this->assertNull($owner->organization->seatLimit());

        $baseline = DB::transactionLevel();
        $ran = false;
        User::withSeatGuard($owner->organization, function () use (&$ran, $baseline) {
            $ran = true;
            $this->assertSame($baseline, DB::transactionLevel(), 'an unlimited plan must not open a transaction');
        });

        $this->assertTrue($ran);
    }

    /**
     * SQLite compiles `FOR UPDATE` to nothing, so the lock is invisible to this
     * suite at runtime. Assert it exists in the source, because dropping it
     * would break production (MySQL) while every test stayed green.
     */
    public function test_the_seat_guard_still_takes_a_row_lock(): void
    {
        $this->assertStringContainsString(
            'lockForUpdate()',
            file_get_contents(app_path('Models/User.php')),
            'User::withSeatGuard must lock the organizations row; SQLite makes this invisible at runtime.',
        );
    }

    /** Bulk reactivation is seat-increasing too, and is capped, not overshot. */
    public function test_bulk_reactivation_cannot_exceed_the_plan(): void
    {
        $owner = $this->makeOwner(2);

        $leavers = collect(['a', 'b', 'c'])->map(fn (string $letter) => User::create([
            'name' => 'Leaver '.$letter,
            'email' => $letter.'@acme.test',
            'password' => 'a-very-long-password',
            'role' => 'member',
            'is_active' => false,
        ]));

        // Only the owner is active, so exactly one seat is free on a 2-seat plan.
        $this->assertSame(1, User::seatUsage());

        $this->actingAs($owner)->post(route('users.bulk-status'), [
            'is_active' => true,
            'ids' => $leavers->pluck('id')->all(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, User::seatUsage(), 'bulk reactivation must stop at the plan limit');
    }

    /** Single reactivation on a full plan is refused with a readable error. */
    public function test_reactivating_one_person_on_a_full_plan_is_refused(): void
    {
        $owner = $this->makeOwner(1);

        $leaver = User::create([
            'name' => 'Leaver',
            'email' => 'leaver@acme.test',
            'password' => 'a-very-long-password',
            'role' => 'member',
            'is_active' => false,
        ]);

        $this->actingAs($owner)
            ->patch(route('users.set-status', $leaver), ['is_active' => true])
            ->assertSessionHasErrors('is_active');

        $this->assertFalse((bool) $leaver->fresh()->is_active);

        // Deactivation is never blocked — it can only free a seat.
        $this->actingAs($owner)
            ->patch(route('users.set-status', $leaver), ['is_active' => false])
            ->assertSessionHasNoErrors();
    }
}
