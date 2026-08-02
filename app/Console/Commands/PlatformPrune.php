<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * RETENTION FOR THE PLATFORM-WIDE TABLES — the ones `tenant:run` must never touch.
 *
 * `monitoring:prune-screenshots` runs once per organization and prunes that
 * organization's rows. Everything in THIS command is landlord-level: it has no
 * `organization_id` to scope by, or it deliberately spans tenants. Running these
 * sweeps inside the per-tenant pass would repeat the same global DELETE 500 times
 * a night — 500× the work for exactly one table's worth of result — which is why
 * they live in their own command on their own schedule entry.
 *
 * Every table here grows without an upper bound and, before this command, had no
 * retention path anywhere in the codebase:
 *
 *   failed_jobs             one row per permanently failed queued job, kept
 *                           forever, each carrying a full serialised payload and
 *                           stack trace. A single misbehaving integration writes
 *                           these faster than anyone reads them.
 *   contact_submissions     the PUBLIC marketing contact form. Unauthenticated,
 *                           so its growth rate is set by whoever is pointing a
 *                           script at it, not by our customers.
 *   password_reset_tokens   Laravel expires these logically (see
 *                           auth.passwords.*.expire) but never deletes the rows.
 *   personal_access_tokens  desktop-agent tokens. Expired and long-unused ones
 *                           are dead credentials sitting in a table — a security
 *                           liability as much as a size one.
 *   platform_audit_logs     the landlord action trail. Kept much longer than
 *                           anything else on purpose (it is the record of what
 *                           WE did to a customer's account), but still bounded.
 *
 * SAFETY RULES THIS COMMAND FOLLOWS
 *  - Every table is guarded by Schema::hasTable and each sweep is wrapped, so a
 *    schema that predates one of them (or one statement that fails) can never
 *    stop the remaining sweeps.
 *  - Every DELETE is batched, so no statement can lock a large range.
 *  - A wall-clock budget stops the pass cleanly; the next night picks up the
 *    rest, because every sweep is "delete the oldest N matching rows".
 *  - --dry-run reports without deleting, and is the only thing CI ever runs.
 */
class PlatformPrune extends Command
{
    protected $signature = 'platform:prune
        {--dry-run : Report what would be deleted without deleting}
        {--time-budget=0 : Stop cleanly after this many seconds (0 = no limit)}
        {--failed-jobs-days=30 : Retention for failed queue jobs}
        {--contact-days=365 : Retention for public contact-form submissions}
        {--token-days=90 : How long an expired or unused access token is kept}
        {--audit-days=1095 : Retention for the landlord audit trail}';

    protected $description = 'Enforce retention on the platform-wide tables that have no organization_id (failed jobs, contact submissions, expired tokens, landlord audit trail).';

    /** Rows deleted per statement. */
    private const BATCH = 2000;

    /**
     * Hard ceiling on contact-form rows, independent of age. The form is public,
     * so a time window alone is not a bound — a script can write a year's worth
     * of "recent" rows in an hour.
     */
    private const CONTACT_MAX_ROWS = 50000;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $budget = max(0, (int) $this->option('time-budget'));
        $startedAt = microtime(true);

        $sweeps = [
            'failed queue jobs' => fn () => $this->pruneFailedJobs((int) $this->option('failed-jobs-days'), $dryRun),
            'contact submissions' => fn () => $this->pruneContactSubmissions((int) $this->option('contact-days'), $dryRun),
            'password reset tokens' => fn () => $this->prunePasswordResetTokens($dryRun),
            'access tokens' => fn () => $this->pruneAccessTokens((int) $this->option('token-days'), $dryRun),
            'landlord audit trail' => fn () => $this->pruneAuditTrail((int) $this->option('audit-days'), $dryRun),
        ];

        $label = $dryRun ? '[dry-run] Would prune' : 'Pruned';
        $stopped = null;

        foreach ($sweeps as $name => $sweep) {
            if ($budget > 0 && (microtime(true) - $startedAt) >= $budget) {
                $stopped = $name;

                break;
            }

            try {
                $deleted = $sweep();
                $this->info("{$label} {$deleted} row(s) — {$name}.");
            } catch (\Throwable $e) {
                // One failing sweep must not cost the others their retention.
                $this->error("Sweep '{$name}' failed: ".$e->getMessage());
                Log::error('platform:prune sweep failed', [
                    'sweep' => $name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($stopped !== null) {
            $this->warn("platform:prune stopped on its {$budget}s budget before '{$stopped}'; the next run continues.");
            Log::warning('platform:prune hit its time budget', ['stopped_before' => $stopped]);
        }

        return self::SUCCESS;
    }

    private function pruneFailedJobs(int $days, bool $dryRun): int
    {
        if (! Schema::hasTable('failed_jobs') || $days <= 0) {
            return 0;
        }

        return $this->sweep(
            fn () => DB::table('failed_jobs')->where('failed_at', '<', now()->subDays($days)),
            $dryRun,
        );
    }

    /**
     * Public form, so BOTH an age window and a volume ceiling apply — either one
     * alone is trivially defeated by a submission script.
     */
    private function pruneContactSubmissions(int $days, bool $dryRun): int
    {
        if (! Schema::hasTable('contact_submissions') || $days <= 0) {
            return 0;
        }

        $deleted = $this->sweep(
            fn () => DB::table('contact_submissions')->where('created_at', '<', now()->subDays($days)),
            $dryRun,
        );

        // Volume ceiling: one query finds the id at the ceiling, one bounded loop
        // deletes below it — no OFFSET scan over the whole table.
        $thresholdId = DB::table('contact_submissions')
            ->orderByDesc('id')
            ->skip(self::CONTACT_MAX_ROWS - 1)
            ->take(1)
            ->value('id');

        if ($thresholdId !== null) {
            $deleted += $this->sweep(
                fn () => DB::table('contact_submissions')->where('id', '<', $thresholdId),
                $dryRun,
            );
        }

        return $deleted;
    }

    /**
     * Laravel treats these as expired via `auth.passwords.*.expire` (minutes) but
     * never removes the rows. Deleting anything past twice the configured window
     * cannot invalidate a token that was still usable.
     */
    private function prunePasswordResetTokens(bool $dryRun): int
    {
        if (! Schema::hasTable('password_reset_tokens')) {
            return 0;
        }

        $expireMinutes = (int) config('auth.passwords.users.expire', 60);
        $cutoff = now()->subMinutes(max(60, $expireMinutes * 2));

        return $this->sweep(
            fn () => DB::table('password_reset_tokens')->where('created_at', '<', $cutoff),
            $dryRun,
            'email',
        );
    }

    /**
     * Dead desktop-agent credentials: past their own `expires_at`, or never used
     * and old enough that they never will be. A token that is currently in use is
     * never touched — `last_used_at` is refreshed on every agent call.
     */
    private function pruneAccessTokens(int $days, bool $dryRun): int
    {
        if (! Schema::hasTable('personal_access_tokens') || $days <= 0) {
            return 0;
        }

        $cutoff = now()->subDays($days);

        return $this->sweep(
            fn () => DB::table('personal_access_tokens')
                ->where(fn ($q) => $q
                    ->where('expires_at', '<', now())
                    ->orWhere(fn ($w) => $w
                        ->whereNull('last_used_at')
                        ->where('created_at', '<', $cutoff))
                    ->orWhere('last_used_at', '<', $cutoff)),
            $dryRun,
        );
    }

    private function pruneAuditTrail(int $days, bool $dryRun): int
    {
        if (! Schema::hasTable('platform_audit_logs') || $days <= 0) {
            return 0;
        }

        return $this->sweep(
            fn () => DB::table('platform_audit_logs')->where('created_at', '<', now()->subDays($days)),
            $dryRun,
        );
    }

    /**
     * Delete in bounded batches. SQLite is not built with the optional
     * SQLITE_ENABLE_UPDATE_DELETE_LIMIT flag, so `DELETE ... LIMIT` is a syntax
     * error there; the portable form is to select a page of keys and delete by
     * key. `$key` is always a hardcoded column name from this file.
     *
     * @param  callable():\Illuminate\Database\Query\Builder  $builder
     */
    private function sweep(callable $builder, bool $dryRun, string $key = 'id'): int
    {
        if ($dryRun) {
            return (int) $builder()->count();
        }

        $deleted = 0;

        do {
            $keys = $builder()->orderBy($key)->limit(self::BATCH)->pluck($key)->all();

            if ($keys === []) {
                break;
            }

            $table = $builder()->from;
            $deleted += (int) DB::table($table)->whereIn($key, $keys)->delete();
        } while (count($keys) >= self::BATCH);

        return $deleted;
    }
}
