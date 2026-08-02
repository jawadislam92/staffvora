<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TIMESHEET APPROVALS + PAY PERIODS.
 *
 * THE SHAPE, AND WHY
 * ------------------
 * A pay PERIOD is never a table. A workspace picks a cadence
 * (weekly / bi-weekly / semi-monthly / monthly) plus an anchor, and every
 * period is DERIVED from that pair — see App\Models\PayPeriod, which is a plain
 * immutable value object, deliberately not an Eloquent model. Pre-creating rows
 * for every period of every workspace forever would be a table that grows with
 * the calendar and answers no question the arithmetic can't.
 *
 * What IS persisted:
 *
 *   `timesheets`            ONE row per (person, period) — and only once that
 *                           person's period actually enters the state machine
 *                           (someone submitted it, or an approver acted on it).
 *                           A period nobody has touched has no row: it reads as
 *                           "open" from the absence of one. The row carries a
 *                           SNAPSHOT of the period (cadence + start + end) so a
 *                           workspace that changes cadence next quarter does not
 *                           retroactively re-shape what was already approved.
 *
 *   `timesheet_approvals`   The append-only audit trail: every transition, who
 *                           made it, when, from which status to which, with the
 *                           note and the totals as they stood at that moment.
 *                           Nothing here is ever updated or deleted.
 *
 * THE LOCK (`work_hours.timesheet_id`)
 * ------------------------------------
 * Approving a timesheet FREEZES the number payroll is about to pay against, so
 * every work_hours row inside the period is stamped with the approving
 * timesheet's id in ONE indexed UPDATE statement. Reopening clears the stamp in
 * one more (indexed by timesheet_id). Reads then answer "is this row locked?"
 * from a column that is already loaded — no per-row query, no per-page query,
 * nothing that degrades as a workspace grows. The alternative (resolving the
 * covering approved period at render time) costs a query per distinct user on
 * every list that serialises work entries.
 *
 * CADENCE SETTING (`monitoring_settings`)
 * ---------------------------------------
 * `pay_period_cadence` + `pay_period_anchor_date` live on the existing per-org
 * settings row rather than in a new table — it is one workspace-level policy
 * choice, exactly like week_starts_on next to it.
 *
 * CONVENTIONS: no DB-level foreign keys (identical schema on SQLite and MySQL),
 * every step guarded by hasTable/hasColumn/hasIndex so re-running is a no-op,
 * and every index name is explicit and well under 64 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('timesheets')) {
            Schema::create('timesheets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->unsignedBigInteger('user_id');

                // The derived period, snapshotted. `period_key` is
                // "<cadence>:<start Y-m-d>" — stable, sortable within a cadence,
                // and re-derivable, so a client can name a period without ever
                // being able to invent an arbitrary date range.
                $table->string('period_key', 40);
                $table->string('cadence', 20);
                $table->date('period_start');
                $table->date('period_end');

                // open | submitted | approved | rejected
                $table->string('status', 20)->default('open');

                $table->timestamp('submitted_at')->nullable();
                $table->unsignedBigInteger('submitted_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('locked_at')->nullable();

                // The reviewer's / submitter's most recent note. The full
                // history lives in timesheet_approvals.
                $table->string('note', 1000)->nullable();

                // Totals frozen at approval time, so the payroll number does not
                // move if history is later corrected after a reopen.
                $table->decimal('total_hours', 10, 2)->nullable();
                $table->decimal('billable_hours', 10, 2)->nullable();
                $table->decimal('tracked_hours', 10, 2)->nullable();
                $table->decimal('manual_hours', 10, 2)->nullable();
                $table->unsignedInteger('entries_count')->nullable();

                $table->timestamps();

                // One timesheet per person per period. User ids are globally
                // unique, so this is safe across tenants (same shape as
                // shift_overrides_user_date_unique).
                $table->unique(['user_id', 'period_key'], 'timesheets_user_period_unique');

                // The requested (organization_id, period, user_id, status)
                // composite: serves the per-period roster read and the
                // per-period status rollup, tenant-partitioned first.
                $table->index(['organization_id', 'period_key', 'user_id', 'status'], 'timesheets_org_period_user_idx');

                // The APPROVER QUEUE: "what is waiting on me", newest period
                // first, across periods.
                $table->index(['organization_id', 'status', 'period_start'], 'timesheets_org_status_start_idx');

                // "My timesheets", newest first.
                $table->index(['organization_id', 'user_id', 'period_start'], 'timesheets_org_user_start_idx');
            });
        }

        if (! Schema::hasTable('timesheet_approvals')) {
            Schema::create('timesheet_approvals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->unsignedBigInteger('timesheet_id');
                $table->unsignedBigInteger('subject_user_id');
                $table->unsignedBigInteger('actor_user_id')->nullable();

                // submitted | approved | rejected | reopened
                $table->string('action', 20);
                $table->string('from_status', 20)->nullable();
                $table->string('to_status', 20);
                $table->string('note', 1000)->nullable();

                // Totals as they stood when the transition happened.
                $table->decimal('total_hours', 10, 2)->nullable();
                $table->decimal('billable_hours', 10, 2)->nullable();

                $table->timestamps();

                $table->index(['timesheet_id', 'id'], 'timesheet_approvals_sheet_idx');
                $table->index(['organization_id', 'created_at'], 'timesheet_approvals_org_time_idx');
            });
        }

        // The lock stamp. Nullable: null = not inside an approved timesheet.
        if (Schema::hasTable('work_hours') && ! Schema::hasColumn('work_hours', 'timesheet_id')) {
            Schema::table('work_hours', function (Blueprint $table) {
                $table->unsignedBigInteger('timesheet_id')->nullable();
            });
        }

        // Reopening clears the stamp with one indexed UPDATE.
        if (Schema::hasTable('work_hours')
            && Schema::hasColumn('work_hours', 'timesheet_id')
            && ! Schema::hasIndex('work_hours', 'work_hours_timesheet_idx')) {
            Schema::table('work_hours', function (Blueprint $table) {
                $table->index('timesheet_id', 'work_hours_timesheet_idx');
            });
        }

        // The workspace's pay-period policy.
        if (Schema::hasTable('monitoring_settings')) {
            Schema::table('monitoring_settings', function (Blueprint $table) {
                if (! Schema::hasColumn('monitoring_settings', 'pay_period_cadence')) {
                    // Weekly is the cadence most hourly teams actually run, and
                    // it is the least surprising default to see pre-selected.
                    $table->string('pay_period_cadence', 20)->default('weekly');
                }

                if (! Schema::hasColumn('monitoring_settings', 'pay_period_anchor_date')) {
                    // Null = derive the anchor from week_starts_on. Only weekly
                    // and bi-weekly use it; semi-monthly and monthly are pinned
                    // to the calendar.
                    $table->date('pay_period_anchor_date')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('monitoring_settings')) {
            Schema::table('monitoring_settings', function (Blueprint $table) {
                foreach (['pay_period_cadence', 'pay_period_anchor_date'] as $column) {
                    if (Schema::hasColumn('monitoring_settings', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('work_hours') && Schema::hasColumn('work_hours', 'timesheet_id')) {
            Schema::table('work_hours', function (Blueprint $table) {
                if (Schema::hasIndex('work_hours', 'work_hours_timesheet_idx')) {
                    $table->dropIndex('work_hours_timesheet_idx');
                }
                $table->dropColumn('timesheet_id');
            });
        }

        Schema::dropIfExists('timesheet_approvals');
        Schema::dropIfExists('timesheets');
    }
};
