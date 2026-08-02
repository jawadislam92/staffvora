<?php

namespace App\Console\Commands;

use App\Models\AttendanceClockCheck;
use App\Models\DesktopDiagnostic;
use App\Models\Machine;
use App\Models\MachineFlag;
use App\Models\MachineInventoryItem;
use App\Models\MonitoringSetting;
use App\Models\Organization;
use App\Models\TrackingActivitySample;
use App\Models\TrackingAuditLog;
use App\Models\TrackingScreenshot;
use App\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Enforces the monitoring retention period for BOTH evidence tables.
 *
 * Screenshots were already pruned here. Activity samples were not pruned
 * anywhere — and they are the far higher-cardinality table (one row per user per
 * minute: ~480/day/person, ~48,000/day for a 100-person tenant, tens of millions
 * per day across a few hundred such tenants), each carrying window titles and URL
 * domains. Left alone the table grows forever, which is a guaranteed failure at
 * the 100-500-large-customers target and a compliance problem in its own right,
 * because it retains PII well past the period the customer was sold.
 *
 * Two deliberate differences from the screenshot path:
 *  - Samples own no files, so they are deleted in bounded batches on the raw
 *    builder (one DELETE per 5,000 rows) rather than row-at-a-time via
 *    chunkById + per-model delete. At this table's volume the row-at-a-time
 *    pattern would issue millions of single-row statements.
 *  - Retention "disabled" (retention_days = 0) still gets a hard PLATFORM
 *    ceiling for samples. Otherwise the unbounded-growth hole simply reopens
 *    per tenant, one checkbox at a time.
 *
 * Retention is additionally clamped to the plan's `max_retention_days`
 * entitlement, so a tenant cannot retain window titles and URLs for longer than
 * its plan actually sold.
 *
 * NOTE ON THE CUTOFF: rows are matched when `captured_at` OR `created_at` is past
 * the cutoff — the portable equivalent of LEAST(captured_at, created_at) < cutoff
 * (MySQL has LEAST, SQLite does not). `captured_at` is client-supplied by the
 * desktop agent, so a far-future value would otherwise make a row immortal;
 * retention must not be defeatable by the thing being retained. `created_at` is
 * server-set and cannot be forged.
 */
class PruneTrackingScreenshots extends Command
{
    protected $signature = 'monitoring:prune-screenshots
        {--days= : Override the configured retention period}
        {--dry-run : Report what would be deleted without deleting}
        {--orphans : Also purge activity samples that carry no organization_id (pre-tenancy rows no scoped query can ever reach)}';

    protected $description = 'Permanently delete screenshots (files and rows), activity samples, desktop diagnostics and stale machine-compliance inventory older than the configured retention period';

    /**
     * Hard platform ceiling for activity samples, in days. Applies even when a
     * tenant sets retention_days = 0 ("keep forever"): retaining keystroke
     * counts, window titles and URL domains indefinitely is neither a product
     * promise nor something the schema can survive.
     */
    private const SAMPLE_MAX_RETENTION_DAYS = 180;

    /** Rows deleted per statement when pruning samples. */
    private const SAMPLE_DELETE_BATCH = 5000;

    /**
     * Hard platform ceiling for desktop diagnostics, in days. Diagnostics are
     * an operational breadcrumb trail, not evidence: a month is generous for
     * "why did this machine stop capturing", and beyond that they are pure cost.
     * Applies even when the tenant disables retention.
     */
    private const DIAGNOSTIC_MAX_RETENTION_DAYS = 30;

    /**
     * VOLUME ceiling, per organization. The desktop agent controls how many
     * events it uploads, so a time window alone is not a bound — a looping or
     * tampered agent can produce a year's worth of rows in an afternoon. Anything
     * beyond the newest N rows for a tenant is dropped regardless of age.
     */
    private const DIAGNOSTIC_MAX_ROWS = 20000;

    /** Rows deleted per statement when pruning diagnostics. */
    private const DIAGNOSTIC_DELETE_BATCH = 5000;

    /**
     * MACHINE COMPLIANCE retention. The inventory tables are current-state (see
     * the create migration), so they do not grow per report — but they still
     * need a floor, because uninstalled tools, decommissioned PCs and resolved
     * findings otherwise accumulate forever, and because a tampered agent can
     * churn item names to inflate the "removed" pile on purpose.
     *
     * Four independent ceilings, all applied even when the tenant disables
     * retention (retention_days = 0):
     *   1. REMOVED items      — a tool that is gone is history, not evidence.
     *   2. STALE PRESENT items — an item still marked present but not confirmed
     *      by any report for this long is flipped to removed. This is what
     *      closes the "empty inventory is UNKNOWN, so we do not sweep" hole in
     *      the ingest: a machine that keeps reporting an empty extension list
     *      still ages its old rows out, it just does not do it in one step.
     *   3. RESOLVED findings  — kept long enough to answer "was this ever on
     *      that PC", then dropped. Open/acknowledged/ignored are never pruned.
     *   4. DEAD machines      — a PC that stopped reporting entirely, together
     *      with all of its inventory and findings.
     */
    private const MACHINE_REMOVED_ITEM_DAYS = 30;

    private const MACHINE_STALE_ITEM_DAYS = 45;

    private const MACHINE_RESOLVED_FLAG_DAYS = 90;

    private const MACHINE_DEAD_DAYS = 120;

    /**
     * VOLUME ceiling on inventory rows per organization. Item names come from
     * the employee's machine, so a tampered agent can invent a new "extension"
     * on every report; a time window alone is therefore not a bound. Anything
     * beyond the newest N rows for a tenant is dropped regardless of age.
     * Sized well above a real 100-PC fleet (~45,000 rows).
     */
    private const MACHINE_INVENTORY_MAX_ROWS = 250000;

    /** Rows deleted per statement when pruning compliance tables. */
    private const MACHINE_DELETE_BATCH = 5000;

    /** Screenshot rows (and their files) handled per pass. */
    private const SCREENSHOT_CHUNK = 200;

    /**
     * MONITORING AUDIT TRAIL retention, in days.
     *
     * `tracking_audit_logs` is one row per privileged monitoring action (a
     * screenshot deleted, an attendance day overridden, a session edited) and had
     * NO retention path anywhere — not here, not in the per-session purge. It is
     * genuinely useful evidence, so the window is deliberately long (a full year,
     * comfortably past any dispute window and past the screenshots the rows point
     * at, which are gone after the tenant's own much shorter retention). It is
     * still a window, because "forever" is not a retention policy.
     *
     * NOTE the asymmetry with the tenant's `retention_days`: this is NOT clamped
     * down to it. A workspace that keeps screenshots for 7 days must still be
     * able to answer "who deleted last month's evidence" — shortening the audit
     * trail to the retention of the thing it audits would make the trail useless
     * exactly when it matters.
     */
    private const AUDIT_LOG_RETENTION_DAYS = 365;

    /**
     * ATTENDANCE CLOCK-CHECK retention, in days. One row per "still working?"
     * conversation — written every ten minutes by the scheduled check for every
     * person clocked in past their threshold. Resolved rows are transient
     * bookkeeping: the outcome is already recorded on the time entry itself, so
     * keeping the nudge ledger past a couple of months buys nothing.
     *
     * OPEN checks (no `resolved_at`) are NEVER pruned by age alone — an unresolved
     * check is live state the next pass reads. They are only dropped when their
     * clock-in is long gone, which the cutoff below already guarantees.
     */
    private const CLOCK_CHECK_RETENTION_DAYS = 60;

    /** Rows deleted per statement for the ledger tables. */
    private const LEDGER_DELETE_BATCH = 5000;

    public function handle(): int
    {
        $override = $this->option('days') !== null && $this->option('days') !== ''
            ? (int) $this->option('days')
            : null;

        $days = $this->clampToEntitlement($override ?? (int) MonitoringSetting::current()->retention_days);
        $dryRun = (bool) $this->option('dry-run');

        // Every step is isolated: a schema that is missing one of these tables,
        // or one statement that blows up on a single tenant, must not stop the
        // rest of that tenant's retention from being enforced — and must not
        // abort the other 499 tenants in this tenant:run pass either.
        foreach ([
            'screenshots' => fn () => $this->pruneScreenshots($days, $dryRun),
            'activity samples' => fn () => $this->pruneActivitySamples($days, $dryRun),
            'desktop diagnostics' => fn () => $this->pruneDiagnostics($days, $dryRun),
            'machine compliance' => fn () => $this->pruneMachineCompliance($dryRun),
            'monitoring audit trail' => fn () => $this->pruneAuditLogs($dryRun),
            'attendance clock checks' => fn () => $this->pruneClockChecks($dryRun),
        ] as $label => $step) {
            try {
                $step();
            } catch (\Throwable $e) {
                $this->error("Retention step '{$label}' failed: ".$e->getMessage());
                \Illuminate\Support\Facades\Log::error('monitoring:prune-screenshots step failed', [
                    'step' => $label,
                    'organization_id' => app(CurrentOrganization::class)->id(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }

    /**
     * The monitoring audit trail. Organization-scoped by the model, matched on
     * `created_at` (server-set — there is no client-supplied timestamp on this
     * table to forge) and deleted in bounded batches.
     */
    private function pruneAuditLogs(bool $dryRun): void
    {
        if (! Schema::hasTable('tracking_audit_logs')) {
            return;
        }

        $cutoff = now()->subDays(self::AUDIT_LOG_RETENTION_DAYS);

        $expired = fn () => TrackingAuditLog::query()->where('created_at', '<', $cutoff);

        $deleted = $this->deleteInBatches($expired, $dryRun, self::LEDGER_DELETE_BATCH);

        $label = $dryRun ? '[dry-run] Would prune' : 'Pruned';
        $this->info("{$label} {$deleted} monitoring audit row(s) older than ".self::AUDIT_LOG_RETENTION_DAYS.' days.');
    }

    /**
     * The "still working?" nudge ledger. Only rows whose conversation is over
     * AND whose clock-in is long past are dropped, so a check the next pass is
     * still driving can never be deleted out from under it.
     */
    private function pruneClockChecks(bool $dryRun): void
    {
        if (! Schema::hasTable('attendance_clock_checks')) {
            return;
        }

        $cutoff = now()->subDays(self::CLOCK_CHECK_RETENTION_DAYS);

        $expired = fn () => AttendanceClockCheck::query()
            ->where('created_at', '<', $cutoff)
            ->where(fn ($q) => $q->whereNotNull('resolved_at')
                // An unresolved check older than the window belongs to a
                // clock-in the 12h auto-close cap dealt with long ago; it is
                // abandoned state, not live state.
                ->orWhere('created_at', '<', now()->subDays(self::CLOCK_CHECK_RETENTION_DAYS * 2)));

        $deleted = $this->deleteInBatches($expired, $dryRun, self::LEDGER_DELETE_BATCH);

        $label = $dryRun ? '[dry-run] Would prune' : 'Pruned';
        $this->info("{$label} {$deleted} attendance clock-check row(s) older than ".self::CLOCK_CHECK_RETENTION_DAYS.' days.');
    }

    /**
     * Machine Compliance retention. Runs unconditionally — this data is an
     * operational inventory, not the evidence the customer's retention setting
     * governs, and leaving it unbounded was the exact shape of a prior audit
     * finding. Every statement is batched and organization-scoped by the model.
     */
    private function pruneMachineCompliance(bool $dryRun): void
    {
        if (! Schema::hasTable('machine_inventory_items')) {
            return;
        }

        $now = now();

        // 1 + 2. Age out inventory: delete what is already gone, then flip
        // long-unconfirmed rows to gone so the next pass can delete them.
        $removedItems = $this->deleteInBatches(
            fn () => MachineInventoryItem::query()
                ->where('present', false)
                ->where(fn ($q) => $q->where('last_seen_at', '<', $now->copy()->subDays(self::MACHINE_REMOVED_ITEM_DAYS))
                    ->orWhereNull('last_seen_at')),
            $dryRun,
        );

        $staleCutoff = $now->copy()->subDays(self::MACHINE_STALE_ITEM_DAYS);
        $staleQuery = fn () => MachineInventoryItem::query()
            ->where('present', true)
            ->where('last_seen_at', '<', $staleCutoff);

        $staleItems = $dryRun
            ? (int) $staleQuery()->count()
            : (int) $staleQuery()->update(['present' => false, 'removed_at' => $now, 'updated_at' => $now]);

        // 3. Resolved findings.
        $resolvedFlags = Schema::hasTable('machine_flags')
            ? $this->deleteInBatches(
                fn () => MachineFlag::query()
                    ->where('status', MachineFlag::STATUS_RESOLVED)
                    ->where('resolved_at', '<', $now->copy()->subDays(self::MACHINE_RESOLVED_FLAG_DAYS)),
                $dryRun,
            )
            : 0;

        // 4. Machines that stopped reporting, with their children (there are no
        // db-level foreign keys here, so the cascade is explicit).
        $deadMachines = $this->pruneDeadMachines($now->copy()->subDays(self::MACHINE_DEAD_DAYS), $dryRun);

        // Volume ceiling, independent of every date above.
        $overflow = $this->pruneInventoryOverflow($dryRun);

        $label = $dryRun ? '[dry-run] Would prune' : 'Pruned';
        $this->info("{$label} {$removedItems} removed inventory item(s), marked {$staleItems} unconfirmed item(s) as gone, dropped {$resolvedFlags} resolved finding(s), {$deadMachines} dead machine(s), and {$overflow} item(s) over the ".self::MACHINE_INVENTORY_MAX_ROWS.'-row per-workspace ceiling.');
    }

    /**
     * Delete machines that have not reported for MACHINE_DEAD_DAYS, together
     * with their inventory and findings. Machine ids are taken in bounded pages
     * so a workspace with thousands of retired PCs never builds one huge IN().
     */
    private function pruneDeadMachines(Carbon $cutoff, bool $dryRun): int
    {
        if (! Schema::hasTable('machines')) {
            return 0;
        }

        $dead = fn () => Machine::query()
            ->where(fn ($q) => $q->where('last_seen_at', '<', $cutoff)->orWhereNull('last_seen_at'));

        if ($dryRun) {
            return (int) $dead()->count();
        }

        $deleted = 0;

        while (true) {
            $ids = $dead()->orderBy('id')->limit(200)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            MachineInventoryItem::query()->whereIn('machine_id', $ids)->delete();

            if (Schema::hasTable('machine_flags')) {
                MachineFlag::query()->whereIn('machine_id', $ids)->delete();
            }

            $deleted += (int) Machine::query()->whereIn('id', $ids)->delete();
        }

        return $deleted;
    }

    /**
     * Drop everything below the newest MACHINE_INVENTORY_MAX_ROWS inventory rows
     * for the active workspace. One query finds the id at the ceiling, one
     * bounded loop deletes below it — no offset scan over the whole table.
     */
    private function pruneInventoryOverflow(bool $dryRun): int
    {
        $thresholdId = MachineInventoryItem::query()
            ->orderByDesc('id')
            ->skip(self::MACHINE_INVENTORY_MAX_ROWS - 1)
            ->take(1)
            ->value('id');

        if ($thresholdId === null) {
            return 0;
        }

        return $this->deleteInBatches(
            fn () => MachineInventoryItem::query()->where('id', '<', $thresholdId),
            $dryRun,
        );
    }

    /**
     * Run a delete in bounded batches so one statement can never lock a huge
     * range. Returns rows removed (or matched, on a dry run).
     *
     * @param  callable():Builder  $builder
     */
    private function deleteInBatches(callable $builder, bool $dryRun, ?int $batchSize = null): int
    {
        $batchSize = max(1, $batchSize ?? self::MACHINE_DELETE_BATCH);

        if ($dryRun) {
            return (int) $builder()->count();
        }

        $deleted = 0;

        do {
            $batch = (int) $builder()->limit($batchSize)->delete();
            $deleted += $batch;
        } while ($batch >= $batchSize);

        return $deleted;
    }

    /**
     * Retention for the desktop diagnostics table (agent telemetry + watchdog
     * decisions). Two independent ceilings, because either one alone is
     * defeatable:
     *   - TIME:   the tenant's retention window, hard-capped at
     *             DIAGNOSTIC_MAX_RETENTION_DAYS, and applied even when the tenant
     *             turns retention off.
     *   - VOLUME: at most DIAGNOSTIC_MAX_ROWS newest rows per organization, so an
     *             agent that loops cannot flood the table inside the window.
     */
    private function pruneDiagnostics(int $days, bool $dryRun): void
    {
        if (! Schema::hasTable('desktop_diagnostics')) {
            return;
        }

        $effective = $days > 0
            ? min($days, self::DIAGNOSTIC_MAX_RETENTION_DAYS)
            : self::DIAGNOSTIC_MAX_RETENTION_DAYS;

        $cutoff = now()->subDays($effective);

        // captured_at is server-set, but created_at is matched too for the same
        // reason as samples: retention must not be defeatable by the thing being
        // retained.
        $expired = fn () => DesktopDiagnostic::query()
            ->where(fn ($q) => $q->where('captured_at', '<', $cutoff)->orWhere('created_at', '<', $cutoff));

        $deleted = 0;

        if ($dryRun) {
            $deleted = (int) $expired()->count();
        } else {
            do {
                $batch = (int) $expired()->limit(self::DIAGNOSTIC_DELETE_BATCH)->delete();
                $deleted += $batch;
            } while ($batch >= self::DIAGNOSTIC_DELETE_BATCH);
        }

        $overflow = $this->pruneDiagnosticOverflow($dryRun);

        $label = $dryRun ? '[dry-run] Would prune' : 'Pruned';
        $this->info("{$label} {$deleted} desktop diagnostic(s) captured before {$cutoff->toDateTimeString()} (retention {$effective} days), plus {$overflow} over the ".self::DIAGNOSTIC_MAX_ROWS.'-row per-workspace ceiling.');
    }

    /**
     * Drop everything below the newest DIAGNOSTIC_MAX_ROWS rows for the active
     * workspace. One query finds the id at the ceiling, one bounded loop deletes
     * below it — no offset scan over the whole table.
     */
    private function pruneDiagnosticOverflow(bool $dryRun): int
    {
        $thresholdId = DesktopDiagnostic::query()
            ->orderByDesc('id')
            ->skip(self::DIAGNOSTIC_MAX_ROWS - 1)
            ->take(1)
            ->value('id');

        if ($thresholdId === null) {
            return 0;
        }

        $overflowing = fn () => DesktopDiagnostic::query()->where('id', '<', $thresholdId);

        if ($dryRun) {
            return (int) $overflowing()->count();
        }

        $deleted = 0;

        do {
            $batch = (int) $overflowing()->limit(self::DIAGNOSTIC_DELETE_BATCH)->delete();
            $deleted += $batch;
        } while ($batch >= self::DIAGNOSTIC_DELETE_BATCH);

        return $deleted;
    }

    private function pruneScreenshots(int $days, bool $dryRun): void
    {
        if ($days <= 0) {
            $this->warn('Retention is disabled (retention_days is 0); no screenshots pruned.');

            return;
        }

        $cutoff = now()->subDays($days);

        $query = fn () => TrackingScreenshot::withTrashed()->where(
            fn ($q) => $q->where('captured_at', '<', $cutoff)->orWhere('created_at', '<', $cutoff)
        );

        $total = (int) $query()->count();

        if ($total === 0) {
            $this->info("No screenshots older than {$days} days.");

            return;
        }

        if ($dryRun) {
            $this->info("[dry-run] Would delete {$total} screenshots captured before {$cutoff->toDateTimeString()}.");

            return;
        }

        $disk = Storage::disk('screenshots');
        $deletedRows = 0;
        $deletedFiles = 0;

        // chunkById is kept here (unlike samples) because each row owns files
        // that must be unlinked before the row disappears. The redundant
        // exists() probe is gone: on S3 it was a wasted HEAD request per file,
        // and deleting a missing object is a no-op on both drivers.
        $query()->orderBy('id')->chunkById(self::SCREENSHOT_CHUNK, function ($screenshots) use ($disk, &$deletedRows, &$deletedFiles) {
            foreach ($screenshots as $screenshot) {
                foreach ([$screenshot->image_path, $screenshot->thumbnail_path] as $path) {
                    if ($path) {
                        $disk->delete($path);
                        $deletedFiles++;
                    }
                }
            }

            // One mass delete per chunk instead of one statement per row. The
            // OrganizationScope still applies to it, which is what we want.
            $ids = $screenshots->modelKeys();
            TrackingScreenshot::withTrashed()->whereKey($ids)->forceDelete();
            $deletedRows += count($ids);
        });

        $this->info("Pruned {$deletedRows} screenshots ({$deletedFiles} files) captured before {$cutoff->toDateTimeString()}.");
    }

    private function pruneActivitySamples(int $days, bool $dryRun): void
    {
        $effective = $days > 0
            ? min($days, self::SAMPLE_MAX_RETENTION_DAYS)
            : self::SAMPLE_MAX_RETENTION_DAYS;

        $cutoff = now()->subDays($effective);

        $deleted = $this->deleteSamplesOlderThan($cutoff, $dryRun, false);

        if ($this->option('orphans')) {
            // Rows written before the tenancy stamp was fixed: invisible to every
            // scoped read, to the per-session purge and to tenant deletion, so
            // nothing else will ever remove them. Opt-in, because purging them
            // crosses the tenant boundary by definition.
            $deleted += $this->deleteSamplesOlderThan($cutoff, $dryRun, true);
        }

        $label = $dryRun ? '[dry-run] Would prune' : 'Pruned';
        $this->info("{$label} {$deleted} activity sample(s) captured before {$cutoff->toDateTimeString()} (retention {$effective} days).");
    }

    /**
     * Delete in bounded batches so one statement can never lock a multi-million
     * row range. Returns rows removed (or matched, on a dry run).
     */
    private function deleteSamplesOlderThan(Carbon $cutoff, bool $dryRun, bool $orphansOnly): int
    {
        $builder = fn () => TrackingActivitySample::query()
            ->when($orphansOnly, fn ($q) => $q->withoutOrganizationScope()->whereNull('organization_id'))
            ->where(fn ($q) => $q->where('captured_at', '<', $cutoff)->orWhere('created_at', '<', $cutoff));

        if ($dryRun) {
            return (int) $builder()->count();
        }

        $deleted = 0;

        do {
            $batch = (int) $builder()->limit(self::SAMPLE_DELETE_BATCH)->delete();
            $deleted += $batch;
        } while ($batch >= self::SAMPLE_DELETE_BATCH);

        return $deleted;
    }

    /**
     * A tenant may configure a SHORTER retention than its plan allows, never a
     * longer one. Zero (disabled) passes through untouched — the screenshot path
     * treats it as "off", the sample path applies the platform ceiling.
     */
    private function clampToEntitlement(int $days): int
    {
        if ($days <= 0) {
            return $days;
        }

        $organizationId = app(CurrentOrganization::class)->id();

        if ($organizationId === null) {
            return $days;
        }

        $max = Organization::find($organizationId)?->entitlement('max_retention_days');

        return is_int($max) && $max > 0 ? min($days, $max) : $days;
    }
}
