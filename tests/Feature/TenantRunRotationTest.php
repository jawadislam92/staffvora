<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * THE 500-TENANT STARVATION BUG THIS PINS
 * ─────────────────────────────────────────────────────────────────────────────
 * `tenant:run` fans a scheduled command out over every active organization in one
 * process, and a `--time-budget` stops the pass cleanly when it runs long. That
 * part was right. What was wrong is what happened NEXT: every pass restarted at
 * `orderBy('id')` from the lowest id.
 *
 * So with 500 tenants and a budget that runs out around organization #180,
 * organizations #181-500 were not served LATE — they were never served AT ALL.
 * Their stale sessions were never closed, their long clock-ins never nudged,
 * their screenshots never pruned. And the command exited 0, printed a success
 * line, and said nothing about it, because from its own point of view the pass
 * had "succeeded".
 *
 * The fix is a per-target rotation cursor: an interrupted pass remembers the last
 * organization it served and the next pass starts AFTER it, wrapping to the top
 * when the list is exhausted. Every tenant is then served within
 * ceil(total / served-per-pass) passes.
 *
 * These are the four properties that make that true, and each one is a way the
 * fix could be silently undone:
 *   1. a budget-limited pass ADVANCES the cursor;
 *   2. the next pass starts AFTER it and the rotation reaches EVERY org;
 *   3. the cursor advances even when a tenant THROWS (otherwise one permanently
 *      broken workspace pins the rotation and re-creates the original bug for
 *      everybody behind it);
 *   4. `--reset-cursor` genuinely forgets the position.
 *
 * NOTE ON TIMING. Two tests below sleep. `--time-budget` is an integer number of
 * seconds, so the only way to make a pass exceed its budget is real wall time.
 * The probe sleeps just over one second per organization, which makes "exactly
 * one organization per pass" deterministic rather than a race: the budget is
 * checked BEFORE each organization, so the first always runs and the second
 * always finds the budget spent.
 */
class TenantRunRotationTest extends TestCase
{
    use RefreshDatabase;

    /** Comfortably over the 1s budget, with margin for Windows timer granularity. */
    private const OVER_BUDGET_MICROSECONDS = 1_100_000;

    /** @var array<int, int> ordered ids of the organizations created for a test */
    private array $orgIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        for ($i = 1; $i <= 4; $i++) {
            $this->orgIds[] = Organization::create([
                'name' => 'Rotation Org '.$i,
                'slug' => 'rotation-'.$i.'-'.Str::random(6),
                'status' => Organization::STATUS_ACTIVE,
            ])->id;
        }
    }

    /** The cache key TenantRun stores its per-target position under. */
    private function cursorKey(string $target): string
    {
        return 'tenant_run.cursor.'.sha1($target);
    }

    /**
     * Register a probe command that records which tenant it was bound to.
     *
     * @param  array<int, int>  $throwFor  organization ids whose run should blow up
     * @param  array<int, int>  $seen      filled with the ids served, in order
     */
    private function probe(string $name, array &$seen, int $sleepMicroseconds = 0, array $throwFor = []): void
    {
        $current = app(CurrentOrganization::class);

        Artisan::command($name, function () use (&$seen, $current, $sleepMicroseconds, $throwFor) {
            $id = $current->id();
            $seen[] = $id;

            if ($sleepMicroseconds > 0) {
                usleep($sleepMicroseconds);
            }

            if (in_array($id, $throwFor, true)) {
                throw new \RuntimeException('this tenant is broken');
            }
        })->purpose('rotation probe');
    }

    /**
     * Property 2, without any sleeping: seed the cursor at organization #2 and
     * assert one full pass serves #3, #4, then WRAPS to #1, #2 — every tenant,
     * exactly once, starting after the saved position.
     *
     * This is the assertion that fails on the old code, which would have served
     * #1, #2, #3, #4 (i.e. restarted at the lowest id and ignored the cursor).
     */
    public function test_a_pass_starts_after_the_saved_cursor_and_wraps_to_cover_every_tenant(): void
    {
        $seen = [];
        $this->probe('test:rotation-wrap', $seen);

        Cache::put($this->cursorKey('test:rotation-wrap'), $this->orgIds[1], now()->addDay());

        $this->artisan('tenant:run', [
            'target' => 'test:rotation-wrap',
            '--time-budget' => 3600,
        ])->assertSuccessful();

        $this->assertSame(
            [$this->orgIds[2], $this->orgIds[3], $this->orgIds[0], $this->orgIds[1]],
            $seen,
            'The pass must resume AFTER the saved cursor and wrap back to the top, not restart at the lowest id.'
        );

        // A rotation that completed has nothing to resume from, so the cursor is
        // cleared and the next pass starts at the top again.
        $this->assertSame(0, (int) Cache::get($this->cursorKey('test:rotation-wrap')));
    }

    /**
     * Property 1 + 2 for real: a pass really cut short by its budget advances the
     * cursor, and the NEXT pass picks up where it stopped instead of re-serving
     * the same first tenant forever.
     */
    public function test_a_budget_limited_pass_advances_the_cursor_and_the_next_pass_continues_from_it(): void
    {
        // Registered ONCE: Artisan::command only takes effect when the console
        // Application is next constructed, so a second registration of the same
        // name would be ignored and quietly keep writing into the first array.
        $seen = [];
        $this->probe('test:rotation-slow', $seen, self::OVER_BUDGET_MICROSECONDS);

        $this->artisan('tenant:run', [
            'target' => 'test:rotation-slow',
            '--time-budget' => 1,
            '--slow-org-seconds' => 60,
        ])->assertSuccessful();

        $this->assertSame([$this->orgIds[0]], $seen, 'The budget must stop the pass after the first tenant.');
        $this->assertSame(
            $this->orgIds[0],
            (int) Cache::get($this->cursorKey('test:rotation-slow')),
            'An interrupted pass must record where it stopped, or the next pass restarts at the lowest id '.
            'and every tenant past the cut-off is never served.'
        );

        $this->artisan('tenant:run', [
            'target' => 'test:rotation-slow',
            '--time-budget' => 1,
            '--slow-org-seconds' => 60,
        ])->assertSuccessful();

        $this->assertSame(
            [$this->orgIds[0], $this->orgIds[1]],
            $seen,
            'The second pass must serve the NEXT tenant. Serving org #1 again is the starvation bug.'
        );
        $this->assertSame($this->orgIds[1], (int) Cache::get($this->cursorKey('test:rotation-slow')));
    }

    /**
     * Property 3. A workspace with corrupt data throws on every single pass. If
     * the cursor only advanced on success, that one workspace would sit at the
     * front of the rotation forever and starve all 499 behind it — the original
     * bug, re-created by a single tenant.
     */
    public function test_the_cursor_advances_even_when_a_tenant_throws(): void
    {
        $seen = [];
        $this->probe('test:rotation-throws', $seen, self::OVER_BUDGET_MICROSECONDS, [$this->orgIds[0]]);

        // A failing tenant makes the command exit 1 — the pass still completed.
        $this->artisan('tenant:run', [
            'target' => 'test:rotation-throws',
            '--time-budget' => 1,
            '--slow-org-seconds' => 60,
        ])->assertExitCode(1);

        $this->assertSame([$this->orgIds[0]], $seen);
        $this->assertSame(
            $this->orgIds[0],
            (int) Cache::get($this->cursorKey('test:rotation-throws')),
            'A crashing tenant must not pin the rotation cursor.'
        );

        $this->artisan('tenant:run', [
            'target' => 'test:rotation-throws',
            '--time-budget' => 1,
            '--slow-org-seconds' => 60,
        ])->assertSuccessful();

        $this->assertSame(
            [$this->orgIds[0], $this->orgIds[1]],
            $seen,
            'The pass after a failing tenant must move on to the next one, not retry the broken one forever.'
        );
    }

    /** Property 4: the escape hatch actually forgets the position. */
    public function test_reset_cursor_forgets_the_saved_position_and_starts_at_the_lowest_id(): void
    {
        $seen = [];
        $this->probe('test:rotation-reset', $seen);

        Cache::put($this->cursorKey('test:rotation-reset'), $this->orgIds[2], now()->addDay());

        $this->artisan('tenant:run', [
            'target' => 'test:rotation-reset',
            '--time-budget' => 3600,
            '--reset-cursor' => true,
        ])->assertSuccessful();

        $this->assertSame(
            $this->orgIds,
            $seen,
            '--reset-cursor must start the pass at the lowest organization id.'
        );
    }

    /**
     * Rotation is meaningless when the operator has named a fixed set, and
     * silently mutating the shared cursor from a one-off `--only` run would
     * corrupt the position the scheduled pass depends on.
     */
    public function test_only_disables_rotation_and_never_touches_the_shared_cursor(): void
    {
        $seen = [];
        $this->probe('test:rotation-only', $seen);

        Cache::put($this->cursorKey('test:rotation-only'), $this->orgIds[2], now()->addDay());

        $this->artisan('tenant:run', [
            'target' => 'test:rotation-only',
            '--only' => (string) $this->orgIds[0],
            '--time-budget' => 3600,
        ])->assertSuccessful();

        $this->assertSame([$this->orgIds[0]], $seen);
        $this->assertSame(
            $this->orgIds[2],
            (int) Cache::get($this->cursorKey('test:rotation-only')),
            'An --only run must leave the scheduled rotation position exactly where it was.'
        );
    }

    /**
     * Rotation is opt-outable, but opting out with a budget means the remainder
     * really is skipped — so the command has to SAY so instead of printing a
     * clean success line. That silence was the whole reason the bug survived.
     */
    public function test_disabling_rotation_under_a_budget_warns_that_the_remainder_is_skipped(): void
    {
        $seen = [];
        $this->probe('test:rotation-off', $seen, self::OVER_BUDGET_MICROSECONDS);

        $this->artisan('tenant:run', [
            'target' => 'test:rotation-off',
            '--time-budget' => 1,
            '--slow-org-seconds' => 60,
            '--resume' => 'no',
        ])
            ->expectsOutputToContain('the remainder is skipped, not deferred')
            ->assertSuccessful();

        $this->assertNull(Cache::get($this->cursorKey('test:rotation-off')));
    }

    /**
     * Suspended / cancelled workspaces are not in the rotation at all — a
     * cancelled tenant must not consume budget that active tenants need.
     */
    public function test_only_trial_and_active_organizations_are_in_the_rotation(): void
    {
        Organization::whereKey($this->orgIds[1])->update(['status' => Organization::STATUS_SUSPENDED]);
        Organization::whereKey($this->orgIds[2])->update(['status' => Organization::STATUS_TRIAL]);

        $seen = [];
        $this->probe('test:rotation-status', $seen);

        $this->artisan('tenant:run', ['target' => 'test:rotation-status'])->assertSuccessful();

        $this->assertSame([$this->orgIds[0], $this->orgIds[2], $this->orgIds[3]], $seen);
    }
}
