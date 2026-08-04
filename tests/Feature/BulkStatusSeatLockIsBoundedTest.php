<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * REGRESSION — the tenant's serialisation lock is held for BOUNDED work.
 *
 * Bulk REACTIVATION lights up seats, so it runs inside the same transaction and
 * the same `organizations` row lock a new hire takes: that is what stops a bulk
 * reactivation and a `POST /users` both spending the last seat. Correct — but
 * the previous shape then STREAMED the whole matching set through `chunkById`
 * while holding it. On a "select all N matching" over a 100k-row tenant that
 * meant one request held the lock across an unbounded scan, queueing every
 * other signup, hire and reactivation in that workspace behind it until
 * something timed out. At 500 orgs × 100+ users that is an outage, not a
 * slowdown.
 *
 * A reactivation can never touch more rows than the plan has FREE SEATS, so the
 * bound is free: read `headroom + 1` ids (the extra one only tells the customer
 * that the rest did not fit) and update them. This test asserts the bound
 * directly off the SQL that is issued, so re-introducing an unbounded scan
 * inside the lock fails here rather than in production.
 */
class BulkStatusSeatLockIsBoundedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array{0: User, 1: int} owner and the number of archived people */
    private function tenantWithArchivedCrowd(int $seats, int $archived, string $slug = 'acme'): array
    {
        $result = app(TenantProvisioner::class)
            ->provision('Acme '.$slug, 'Owner', "owner@{$slug}.test", 'super-secret-pw', null, 14, 'general');
        app(CurrentOrganization::class)->set($result['organization']);
        $result['organization']->update(['entitlement_overrides' => ['max_users' => $seats]]);
        $result['organization']->forgetResolvedEntitlements();

        User::factory()->count($archived)->create([
            'organization_id' => $result['organization']->id,
            'role' => 'member',
            'is_active' => false,
        ]);

        return [$result['owner']->fresh(), $archived];
    }

    /**
     * The number of rows any single SELECT against `users` is allowed to read
     * while the lock is held. Aggregates (the seat count) are exempt — they read
     * an index, not a row set.
     *
     * @return array<int, array{sql:string, limit:int|null}>
     */
    private function userSelects(array $queries): array
    {
        $out = [];

        foreach ($queries as $sql) {
            $normalised = strtolower($sql);

            if (! str_starts_with($normalised, 'select')) {
                continue;
            }
            if (! str_contains($normalised, 'from "users"') && ! str_contains($normalised, 'from `users`')) {
                continue;
            }
            if (str_contains($normalised, 'count(') || str_contains($normalised, 'sum(')) {
                continue;
            }

            preg_match('/ limit (\d+)/', $normalised, $m);
            $out[] = ['sql' => $sql, 'limit' => isset($m[1]) ? (int) $m[1] : null];
        }

        return $out;
    }

    public function test_bulk_reactivation_reads_no_more_rows_than_the_plan_has_free_seats(): void
    {
        // 3 seats, owner already holding one → headroom 2, against 40 archived
        // people. The lock may see 3 rows (headroom + the "there is more" probe),
        // never 40 and never a 500-row page.
        [$owner] = $this->tenantWithArchivedCrowd(seats: 3, archived: 40);

        $headroom = User::seatHeadroom($owner->organization);
        $this->assertSame(2, $headroom);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $response = $this->actingAs($owner)->post(route('users.bulk-status'), [
            'is_active' => true,
            'all_matching' => true,
        ]);

        $response->assertRedirect();

        $unbounded = [];
        foreach ($this->userSelects($queries) as $select) {
            if ($select['limit'] === null || $select['limit'] > $headroom + 1) {
                $unbounded[] = ($select['limit'] ?? 'no limit').' :: '.$select['sql'];
            }
        }

        $this->assertSame(
            [],
            $unbounded,
            "The tenant's serialisation lock is being held across an unbounded scan. "
            ."A reactivation can touch at most {$headroom} rows, so it must never read more than "
            .($headroom + 1).":\n".implode("\n", $unbounded),
        );

        // …and the behaviour the owner reviewed is unchanged: it fills the
        // headroom, no more, and says so.
        $this->assertSame(3, User::query()->where('is_active', true)->count());
        $this->assertStringContainsString(
            'no free seats left',
            (string) session('success'),
            'the customer must be told the rest did not fit',
        );
        $this->assertStringContainsString('Reactivated 2 user(s).', (string) session('success'));
    }

    /**
     * The work done inside the lock must not grow with the size of the
     * directory. Same plan, same headroom, ten times the archived people — the
     * query count may not move.
     */
    public function test_the_locked_work_does_not_grow_with_the_directory(): void
    {
        // Two independent tenants rather than a database reset — the suite runs
        // each test inside one transaction, and re-migrating mid-test breaks it.
        $count = function (int $archived, string $slug): int {
            [$owner] = $this->tenantWithArchivedCrowd(seats: 3, archived: $archived, slug: $slug);

            $queries = [];
            DB::listen(function ($query) use (&$queries) {
                $queries[] = $query->sql;
            });

            $this->actingAs($owner)->post(route('users.bulk-status'), [
                'is_active' => true,
                'all_matching' => true,
            ]);

            return count($this->userSelects($queries));
        };

        $small = $count(5, 'small');
        $large = $count(120, 'large');

        $this->assertSame(
            $small,
            $large,
            'a bigger directory made the locked section do more work — the scan is unbounded again',
        );
    }

    /** Deactivation consumes no seat, so it must not take the lock at all. */
    public function test_bulk_deactivation_still_deactivates_everyone_selected(): void
    {
        [$owner] = $this->tenantWithArchivedCrowd(seats: 3, archived: 0);

        $ids = User::factory()->count(12)->create([
            'organization_id' => $owner->organization_id,
            'role' => 'member',
            'is_active' => true,
        ])->pluck('id')->all();

        $this->actingAs($owner)->post(route('users.bulk-status'), [
            'is_active' => false,
            'ids' => $ids,
        ])->assertRedirect();

        $this->assertSame(0, User::query()->whereIn('id', $ids)->where('is_active', true)->count());
        // The actor is never deactivated by their own bulk action.
        $this->assertTrue($owner->fresh()->isActive());
    }
}
