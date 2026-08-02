<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs another artisan command once per active organization, each bound to its
 * tenant via CurrentOrganization. Scheduled jobs run with no authenticated user,
 * so without this they would query across every tenant at once (wrong channel,
 * mixed data). Wrapping them here keeps each run scoped to one org.
 *
 * SCALE (500 tenants, big companies):
 *  - organizations stream in bounded chunks instead of being hydrated in one
 *    ->get(); nothing here holds the whole tenant list in memory;
 *  - one tenant's failure is caught, logged and skipped — it can never abort the
 *    remaining 499. Both Throwable and the "command not found" case are handled;
 *  - a wall-clock budget stops the pass cleanly and reports where it stopped,
 *    instead of being hard-killed mid-flight by an execution-time limit. That
 *    matters because a process killed inside withoutOverlapping() never releases
 *    its mutex, and the default expiry is 24 hours — i.e. one over-running pass
 *    silently disables the job for a full day;
 *  - per-org wall time is measured, and anything over --slow-org-seconds is
 *    logged as a warning, so one tenant starving the schedule surfaces loudly
 *    instead of being invisible.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE STARVATION BUG THE CURSOR FIXES (Phase 6 finding)
 * ─────────────────────────────────────────────────────────────────────────────
 * The time budget above stopped the pass cleanly and claimed the remainder was
 * "deferred to the next run". It was not. Every pass restarted at `orderBy('id')`
 * from the lowest id, so with 500 tenants and a budget that runs out at, say,
 * organization #180, organizations #181-500 were NEVER processed — not late,
 * never. Their sessions were never closed, their long clock-ins never nudged,
 * their screenshots never pruned, and nothing in the output said so, because
 * from the command's point of view the pass "succeeded".
 *
 * `--resume` (ON by default whenever a time budget is set) persists the id of
 * the last organization served under a per-command cache key and starts the next
 * pass AFTER it, wrapping to the beginning when the list is exhausted. That turns
 * the budget into genuine round-robin fairness: every tenant is served within
 * ceil(total / per-pass) passes, and the cursor is written even when the pass
 * fails, so a crashing tenant cannot pin the cursor in front of everybody else.
 *
 * The cursor is advisory only: it is a cache entry, and losing it simply restarts
 * the rotation from the top. It is never used as a correctness boundary.
 */
class TenantRun extends Command
{
    protected $signature = 'tenant:run
        {target : The artisan command name to run for each active organization}
        {--only= : Comma-separated organization ids to limit to}
        {--chunk=100 : Organizations hydrated per batch}
        {--time-budget=0 : Stop cleanly after this many seconds (0 = no limit)}
        {--slow-org-seconds=60 : Log a warning when one organization takes longer than this}
        {--resume=auto : Round-robin across passes when the budget cuts a pass short (auto|yes|no; auto = yes whenever --time-budget is set)}
        {--reset-cursor : Forget the saved position and start this pass at the lowest organization id}';

    protected $description = 'Run an artisan command once per active organization, bound to that tenant.';

    /** How long a rotation cursor survives without a pass touching it. */
    private const CURSOR_TTL_DAYS = 30;

    public function handle(CurrentOrganization $current): int
    {
        $command = $this->argument('target');
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        $chunk = max(1, (int) $this->option('chunk'));
        $budget = max(0, (int) $this->option('time-budget'));
        $slowAfter = max(1, (int) $this->option('slow-org-seconds'));

        $resume = $this->shouldResume($budget, $only);
        $cursorKey = $this->cursorKey($command);

        if ($this->option('reset-cursor')) {
            Cache::forget($cursorKey);
        }

        $cursor = $resume ? (int) (Cache::get($cursorKey) ?? 0) : 0;

        $startedAt = microtime(true);
        $processed = 0;
        $failures = 0;
        $budgetHit = false;
        $lastId = $cursor;
        $wrapped = false;

        // Two sweeps at most: from the cursor to the end, then (if the budget
        // still allows) from the beginning back up to the cursor. That is what
        // makes the rotation actually cover every tenant rather than only the
        // tail of the list.
        foreach ($resume ? [[$cursor, null], [0, $cursor]] : [[0, null]] as $index => [$after, $upTo]) {
            if ($budgetHit) {
                break;
            }

            if ($index === 1) {
                if ($cursor === 0) {
                    break; // nothing was skipped, so there is nothing to wrap to
                }
                $wrapped = true;
            }

            $this->sweep(
                current: $current,
                command: $command,
                only: $only,
                chunk: $chunk,
                after: $after,
                upTo: $upTo,
                budget: $budget,
                startedAt: $startedAt,
                slowAfter: $slowAfter,
                processed: $processed,
                failures: $failures,
                budgetHit: $budgetHit,
                lastId: $lastId,
            );
        }

        if ($resume) {
            // A completed rotation resets to 0 so the next pass starts at the
            // top; an interrupted one remembers where to pick up.
            Cache::put($cursorKey, $budgetHit ? $lastId : 0, now()->addDays(self::CURSOR_TTL_DAYS));
        }

        if ($budgetHit) {
            $this->warn(sprintf(
                'tenant:run %s stopped after %ds (time budget) at organization #%d; the next pass resumes from there%s.',
                $command,
                (int) (microtime(true) - $startedAt),
                $lastId,
                $resume ? '' : ' — WARNING: --resume=no, so the next pass restarts at the lowest id and the remainder is skipped, not deferred'
            ));
            Log::warning('tenant:run hit its time budget', [
                'command' => $command,
                'processed' => $processed,
                'resume_from' => $lastId,
                'rotating' => $resume,
            ]);
        }

        $this->info(sprintf(
            'tenant:run %s → %d org(s), %d failure(s)%s%s.',
            $command,
            $processed,
            $failures,
            $wrapped ? ', wrapped past the saved cursor' : '',
            $budgetHit ? ', stopped on time budget' : ''
        ));

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * One ordered sweep over `(after, upTo]`, honouring the shared time budget.
     *
     * @param  array<int, string>  $only
     */
    private function sweep(
        CurrentOrganization $current,
        string $command,
        array $only,
        int $chunk,
        int $after,
        ?int $upTo,
        int $budget,
        float $startedAt,
        int $slowAfter,
        int &$processed,
        int &$failures,
        bool &$budgetHit,
        int &$lastId,
    ): void {
        Organization::query()
            ->when($only, fn ($q) => $q->whereIn('id', $only))
            ->whereIn('status', [Organization::STATUS_TRIAL, Organization::STATUS_ACTIVE])
            ->when($after > 0, fn ($q) => $q->where('id', '>', $after))
            ->when($upTo !== null, fn ($q) => $q->where('id', '<=', $upTo))
            ->orderBy('id')
            ->chunkById($chunk, function ($orgs) use (
                $current, $command, $budget, $slowAfter, $startedAt,
                &$processed, &$failures, &$budgetHit, &$lastId
            ) {
                foreach ($orgs as $org) {
                    if ($budget > 0 && (microtime(true) - $startedAt) >= $budget) {
                        $budgetHit = true;

                        break;
                    }

                    $orgStartedAt = microtime(true);

                    try {
                        $current->runFor($org, function () use ($command) {
                            Artisan::call($command, [], $this->getOutput());
                        });
                        $processed++;
                    } catch (\Throwable $e) {
                        // Isolation is the point: a malformed record, a Slack
                        // outage or a missing table in ONE workspace must not
                        // stop the other 499 from being processed.
                        $failures++;
                        $this->error("[org {$org->id}] {$command} failed: ".$e->getMessage());
                        Log::error('tenant:run failed', [
                            'organization_id' => $org->id,
                            'command' => $command,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    // Advanced whether the tenant succeeded or blew up. A tenant
                    // that throws every pass must not pin the cursor and starve
                    // everybody behind it.
                    $lastId = (int) $org->id;

                    $elapsed = microtime(true) - $orgStartedAt;

                    if ($elapsed >= $slowAfter) {
                        $this->warn(sprintf('[org %d] %s took %.1fs', $org->id, $command, $elapsed));
                        Log::warning('tenant:run slow organization', [
                            'organization_id' => $org->id,
                            'command' => $command,
                            'seconds' => round($elapsed, 1),
                        ]);
                    }
                }

                // Stop chunking entirely once the budget is spent — there is no
                // point paging through the remaining organizations.
                return ! $budgetHit;
            });
    }

    /**
     * Rotation is pointless without a budget (a full pass always finishes) and
     * actively wrong when `--only` names a fixed set, so `auto` enables it only
     * for the case it exists for.
     *
     * @param  array<int, string>  $only
     */
    private function shouldResume(int $budget, array $only): bool
    {
        if ($only !== []) {
            return false;
        }

        return match (strtolower((string) $this->option('resume'))) {
            'yes', 'true', '1', 'on' => true,
            'no', 'false', '0', 'off' => false,
            default => $budget > 0,
        };
    }

    /** Per-target key: each scheduled command rotates independently. */
    private function cursorKey(string $command): string
    {
        return 'tenant_run.cursor.'.sha1($command);
    }
}
