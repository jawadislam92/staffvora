<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    /**
     * ─────────────────────────────────────────────────────────────────────────
     * WHY EVERY tenant:run ENTRY CARRIES A TIME BUDGET AND AN OVERLAP EXPIRY
     * ─────────────────────────────────────────────────────────────────────────
     * At 500 tenants each of these is 500 sequential sub-commands in ONE process.
     * Two failure modes follow, and both are silent:
     *
     *  1. OVERRUN. A pass that takes longer than its own interval is skipped by
     *     `withoutOverlapping()` on the next tick — forever, once it stops
     *     catching up. `--time-budget` makes each pass end on OUR terms, and
     *     `tenant:run` now rotates its starting position so the tenants it did
     *     not reach are genuinely served next pass instead of being skipped for
     *     good (see TenantRun's docblock).
     *
     *  2. A STUCK MUTEX. `withoutOverlapping()` defaults to a 24-hour expiry, so
     *     a process hard-killed mid-pass (OOM, deploy, `max_execution_time`)
     *     disables its own job for a full day with no error anywhere. Every entry
     *     now passes an explicit expiry just above its own budget, so the worst a
     *     kill can cost is one interval.
     *
     * Budgets are set below each job's interval so the scheduler tick is never
     * the thing that overruns. `runInBackground()` keeps a long tenant sweep from
     * blocking the other due events in the same `schedule:run` tick — without it
     * the nightly prune would hold up the heartbeat and the config self-heal for
     * as long as it runs.
     */
    protected function schedule(Schedule $schedule): void
    {
        $timezone = config('services.slack_reports.timezone', 'Asia/Karachi');

        if (config('services.slack_reports.weekly_enabled')) {
            $schedule->command('tenant:run reports:send-weekly-slack --time-budget=3000')
                ->sundays()
                ->at('10:00')
                ->timezone($timezone)
                ->withoutOverlapping(60)
                ->runInBackground();
        }

        if (config('services.slack_reports.daily_digest_enabled')) {
            $schedule->command('tenant:run activity:digest-slack --time-budget=3000')
                ->dailyAt(config('services.slack_reports.daily_digest_time', '09:00'))
                ->timezone($timezone)
                ->withoutOverlapping(60)
                ->runInBackground();
        }

        // Enforce the screenshot/sample/diagnostic/compliance retention periods.
        // The widest window of the lot (nothing else runs at 02:30), so the
        // budget is generous — but it is still a budget, and the rotation means
        // an overrunning night resumes where it stopped rather than re-pruning
        // the same first tenants every night while the tail never gets pruned.
        $schedule->command('tenant:run monitoring:prune-screenshots --time-budget=7200 --slow-org-seconds=120')
            ->dailyAt('02:30')
            ->timezone($timezone)
            ->withoutOverlapping(150)
            ->runInBackground();

        // Platform-wide tables (failed jobs, expired tokens, contact form spam,
        // landlord audit trail). These are NOT per-tenant, so they run ONCE —
        // running them inside tenant:run would repeat the same global sweep 500
        // times a night.
        $schedule->command('platform:prune --time-budget=900')
            ->dailyAt('03:30')
            ->timezone($timezone)
            ->withoutOverlapping(30)
            ->runInBackground();

        // Close sessions whose desktop app died without sending a stop, so
        // they don't sit "active" forever and their hours still sync.
        $schedule->command('tenant:run monitoring:close-stale-sessions --time-budget=420')
            ->everyTenMinutes()
            ->withoutOverlapping(10)
            ->runInBackground();

        // Safety net for forgotten clock-outs: the clock is fully manual, so a
        // clock-in with no clock-out hangs open forever. Close dangling
        // clock-ins (at last tracker activity, or capped at 12h when never
        // tracked) and record a clock_out with a plain-language reason.
        // Gated by a flag so it can be dry-run/reviewed before going live.
        if (config('services.attendance.auto_clockout_enabled')) {
            $schedule->command('tenant:run attendance:auto-clock-out --time-budget=1500')
                ->everyThirtyMinutes()
                ->withoutOverlapping(30)
                ->runInBackground();
        }

        // Interactive "still working?" Slack check: DM people clocked in past
        // the threshold with Yes/No buttons; clock out non-responders. The cap
        // above stays as the final backstop. Gated by its own flag.
        //
        // This is the most budget-sensitive job on the schedule: each nudge is a
        // BLOCKING Slack HTTP call, and the command's own --max-dms cap is
        // per-tenant, so 500 tenants can queue up to 500 × 200 blocking calls in
        // one ten-minute window. The wall-clock budget is the only thing that
        // actually bounds that, and the rotation is what stops the same first
        // tenants consuming it every single pass.
        if (config('services.attendance.still_working_slack_enabled')) {
            $schedule->command('tenant:run attendance:still-working-check --time-budget=420')
                ->everyTenMinutes()
                ->withoutOverlapping(10)
                ->runInBackground();
        }

        // Capture-health watchdog: a tracker can send heartbeats (looks "live")
        // while its screenshot/sample helpers are blocked by antivirus, so the
        // person looks tracked but the report is empty. Alert in real time.
        if (config('services.attendance.tracker_health_slack_enabled')) {
            $schedule->command('tenant:run monitoring:silent-tracker-check --time-budget=660')
                ->everyFifteenMinutes()
                ->withoutOverlapping(15)
                ->runInBackground();
        }

        // QUEUE DRAIN. The heavy background work (screenshot thumbnails) is
        // queued rather than run inside the desktop agent's upload request. On a
        // box with a supervised `queue:work` this is redundant and harmless — it
        // simply finds an empty queue. On a box WITHOUT one (shared hosting, or
        // a worker that died after a deploy) it is the difference between
        // thumbnails appearing within a minute and never appearing at all.
        //
        // --stop-when-empty ends the process instead of idling, --max-time caps
        // it below the tick so passes can never pile up, and --tries/--backoff
        // match the job's own retry policy.
        if (config('queue.default') !== 'sync') {
            $schedule->command('queue:work --stop-when-empty --max-time=50 --max-jobs=200 --tries=3 --sleep=1')
                ->everyMinute()
                ->withoutOverlapping(2)
                ->runInBackground();
        }

        // Hostinger's git auto-deploy re-clones the tree and wipes
        // bootstrap/cache, dropping the config cache (a large chunk of TTFB
        // on shared hosting). Rebuild it whenever it's found missing.
        // NOTE: route:cache must never be added here — closure routes.
        $schedule->call(function () {
            if (! file_exists(base_path('bootstrap/cache/config.php'))) {
                Artisan::call('config:cache');
            }
        })->name('config-cache-self-heal')->everyFiveMinutes();

        // Liveness marker: this file's mtime shows when the scheduler last
        // ran, regardless of what drives it (host cron or the HTTP trigger).
        $schedule->call(function () {
            touch(storage_path('framework/schedule-heartbeat'));
        })->name('schedule-heartbeat')->everyMinute();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
