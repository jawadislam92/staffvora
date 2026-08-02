<?php

namespace Tests\Feature;

use Cron\CronExpression;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Tests\TestCase;

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * THE SCHEDULE IS A CONTRACT, AND BOTH WAYS OF BREAKING IT ARE SILENT
 * ─────────────────────────────────────────────────────────────────────────────
 * At 500 tenants every `tenant:run` entry is 500 sequential sub-commands in one
 * process. Two failure modes follow, and neither one errors:
 *
 *  1. NO WALL-CLOCK BOUND. A pass that outruns its own interval is skipped on the
 *     next tick by `withoutOverlapping()` and never catches up. Nothing is
 *     logged, because from the scheduler's point of view the job is "already
 *     running".
 *
 *  2. NO EXPLICIT OVERLAP EXPIRY. `withoutOverlapping()` defaults to **1440
 *     minutes** — 24 hours. A process hard-killed mid-pass (OOM, deploy,
 *     max_execution_time) never releases its mutex, so one kill disables that
 *     job for a full day with no error anywhere.
 *
 * This file is the drift guard that stops the next person re-introducing either.
 * It is deliberately cheap: no database, no HTTP, no sleeping — it reads the
 * schedule definition and asserts arithmetic about it.
 *
 * ⚠️ SCOPED TO **COMMAND** EVENTS ON PURPOSE. The two `$schedule->call()`
 * closures (`config-cache-self-heal`, `schedule-heartbeat`) are sub-millisecond
 * in-process closures with no mutex and no budget — a naive "every event" rule
 * would fail on day one for no safety gain. They are enumerated explicitly in
 * {@see test_the_only_unbounded_events_are_the_two_known_in_process_closures} so
 * a THIRD closure cannot join them unnoticed.
 */
class SchedulePolicyTest extends TestCase
{
    /**
     * Build the schedule with every feature flag ON, so the policy covers the
     * whole file rather than only the entries the testing env happens to enable.
     * Half of these entries are behind config flags; without this the test would
     * pass while asserting nothing about them.
     *
     * @return array<int, Event>
     */
    private function scheduledEvents(): array
    {
        config([
            'services.slack_reports.weekly_enabled' => true,
            'services.slack_reports.daily_digest_enabled' => true,
            'services.attendance.auto_clockout_enabled' => true,
            'services.attendance.still_working_slack_enabled' => true,
            'services.attendance.tracker_health_slack_enabled' => true,
            // The queue drain is only scheduled off the sync driver.
            'queue.default' => 'database',
        ]);

        $schedule = new Schedule;

        $method = new \ReflectionMethod(app(ConsoleKernelContract::class), 'schedule');
        $method->setAccessible(true);
        $method->invoke(app(ConsoleKernelContract::class), $schedule);

        return $schedule->events();
    }

    /** @return array<int, Event> */
    private function commandEvents(): array
    {
        return array_values(array_filter(
            $this->scheduledEvents(),
            fn (Event $event) => ! $event instanceof CallbackEvent
        ));
    }

    /**
     * Shortest gap between two consecutive fires of a cron expression, in
     * seconds. Sampled over several fires because expressions like
     * `0,30 * * * *` are not evenly spaced in general.
     */
    private function intervalSeconds(string $expression): int
    {
        $cron = new CronExpression($expression);
        $cursor = $cron->getNextRunDate(new \DateTime('2026-01-01 00:00:00', new \DateTimeZone('UTC')));
        $shortest = PHP_INT_MAX;

        for ($i = 0; $i < 8; $i++) {
            $next = $cron->getNextRunDate($cursor);
            $shortest = min($shortest, $next->getTimestamp() - $cursor->getTimestamp());
            $cursor = $next;
        }

        return $shortest;
    }

    /**
     * The declared wall-clock bound on one scheduled command, in seconds.
     *
     * Two spellings are legitimate and both are accepted: our own
     * `--time-budget=N` (tenant:run, platform:prune) and Laravel's
     * `--max-time=N` (queue:work). What is NOT legitimate is neither.
     */
    private function declaredBudgetSeconds(string $command): ?int
    {
        if (preg_match('/--(?:time-budget|max-time)=(\d+)/', $command, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    private function label(Event $event): string
    {
        // The stored command is the full `'php' 'artisan' <cmd>` string; the
        // artisan part is all a failure message needs.
        return trim((string) preg_replace('/^.*artisan[\'"]?\s*/', '', (string) $event->command));
    }

    public function test_the_schedule_still_contains_every_command_it_is_supposed_to(): void
    {
        $labels = array_map(fn (Event $e) => $this->label($e), $this->commandEvents());

        foreach ([
            'tenant:run reports:send-weekly-slack',
            'tenant:run activity:digest-slack',
            'tenant:run monitoring:prune-screenshots',
            'platform:prune',
            'tenant:run monitoring:close-stale-sessions',
            'tenant:run attendance:auto-clock-out',
            'tenant:run attendance:still-working-check',
            'tenant:run monitoring:silent-tracker-check',
            'queue:work',
        ] as $expected) {
            $this->assertTrue(
                collect($labels)->contains(fn ($l) => str_starts_with($l, $expected)),
                "The scheduled entry '{$expected}' has disappeared from App\\Console\\Kernel. ".
                'If that was deliberate, delete it from this list too — otherwise a whole class of '.
                'per-tenant maintenance has silently stopped running.'
            );
        }

        // Guard against the guard becoming vacuous.
        $this->assertGreaterThanOrEqual(9, count($labels));
    }

    /**
     * Every command event must declare an explicit overlap expiry, and it must be
     * shorter than the framework's 24-hour default. This is failure mode #2.
     */
    public function test_every_scheduled_command_has_an_explicit_non_default_overlap_expiry(): void
    {
        foreach ($this->commandEvents() as $event) {
            $label = $this->label($event);

            $this->assertTrue(
                $event->withoutOverlapping,
                "Scheduled command '{$label}' has no withoutOverlapping(). At 500 tenants a pass that ".
                'outruns its interval would stack passes on top of each other.'
            );

            $this->assertLessThan(
                1440,
                $event->expiresAt,
                "Scheduled command '{$label}' uses withoutOverlapping()'s DEFAULT 1440-minute expiry. ".
                'A process hard-killed mid-pass would then disable this job for 24 hours, silently. '.
                'Pass an explicit expiry just above the job\'s own time budget.'
            );

            $this->assertGreaterThan(0, $event->expiresAt);
        }
    }

    /**
     * Every command event must declare a wall-clock bound, and that bound must be
     * smaller than its own interval. This is failure mode #1: the whole point of
     * the budget is that the pass ends on OUR terms before the next tick is due.
     */
    public function test_every_scheduled_command_has_a_time_budget_smaller_than_its_own_interval(): void
    {
        foreach ($this->commandEvents() as $event) {
            $label = $this->label($event);
            $budget = $this->declaredBudgetSeconds((string) $event->command);

            $this->assertNotNull(
                $budget,
                "Scheduled command '{$label}' declares no wall-clock bound (--time-budget= or --max-time=). ".
                'Without one, a pass that outruns its interval is skipped by withoutOverlapping() on every '.
                'subsequent tick and never catches up — silently.'
            );

            $interval = $this->intervalSeconds($event->getExpression());

            $this->assertLessThan(
                $interval,
                $budget,
                "Scheduled command '{$label}' has a {$budget}s budget but runs every {$interval}s. ".
                'A budget at or above the interval cannot stop passes from piling up.'
            );
        }
    }

    /**
     * The expiry has to OUTLIVE the budget, or a pass that legitimately uses its
     * full budget releases its own mutex mid-flight and a second copy starts on
     * top of it — the exact thing withoutOverlapping exists to prevent.
     */
    public function test_every_overlap_expiry_outlives_the_budget_it_guards(): void
    {
        foreach ($this->commandEvents() as $event) {
            $label = $this->label($event);
            $budget = $this->declaredBudgetSeconds((string) $event->command);
            $expirySeconds = $event->expiresAt * 60;

            $this->assertGreaterThan(
                $budget,
                $expirySeconds,
                "Scheduled command '{$label}' may run for {$budget}s but its overlap mutex expires after ".
                "{$expirySeconds}s. The mutex would lapse while the pass is still running."
            );
        }
    }

    /**
     * `tenant:run` specifically must always carry `--time-budget`: `--max-time`
     * means nothing to it, and an unbounded tenant sweep is the single most
     * expensive thing on this schedule.
     */
    public function test_every_tenant_run_entry_carries_an_explicit_time_budget(): void
    {
        $tenantRuns = array_filter(
            $this->commandEvents(),
            fn (Event $e) => str_contains((string) $e->command, 'tenant:run')
        );

        $this->assertNotEmpty($tenantRuns, 'No tenant:run entries found — the guard would be vacuous.');

        foreach ($tenantRuns as $event) {
            $this->assertMatchesRegularExpression(
                '/--time-budget=\d+/',
                (string) $event->command,
                "tenant:run entry '{$this->label($event)}' has no --time-budget."
            );
        }
    }

    /**
     * A long tenant sweep must not hold up the other events due in the same
     * `schedule:run` tick — without runInBackground() the nightly prune blocks
     * the per-minute heartbeat and queue drain for as long as it runs.
     */
    public function test_every_scheduled_command_runs_in_the_background(): void
    {
        foreach ($this->commandEvents() as $event) {
            $this->assertTrue(
                $event->runInBackground,
                "Scheduled command '{$this->label($event)}' runs in the foreground and will block every ".
                'other event due in the same scheduler tick.'
            );
        }
    }

    /**
     * The explicit exemption list. These two are in-process closures measured in
     * microseconds, so a mutex and a budget would be pure ceremony — but the
     * exemption has to be NAMED, or the next `$schedule->call()` (which could be
     * anything) inherits it by accident.
     */
    public function test_the_only_unbounded_events_are_the_two_known_in_process_closures(): void
    {
        $callbacks = array_values(array_filter(
            $this->scheduledEvents(),
            fn (Event $e) => $e instanceof CallbackEvent
        ));

        $names = array_map(fn (Event $e) => (string) $e->description, $callbacks);
        sort($names);

        $this->assertSame(
            ['config-cache-self-heal', 'schedule-heartbeat'],
            $names,
            'A new $schedule->call() closure has appeared. Closures are exempt from the budget/mutex policy '.
            'only because these two are trivially short. If the new one does real work, make it a command '.
            'so the policy above applies to it; if it is genuinely trivial, add its name here on purpose.'
        );
    }
}
