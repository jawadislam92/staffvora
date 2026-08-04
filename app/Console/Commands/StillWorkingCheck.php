<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RequiresBoundTenant;
use App\Models\AttendanceClockCheck;
use App\Models\TimeEntry;
use App\Models\TrackingSession;
use App\Models\User;
use App\Services\AttendanceCloser;
use App\Services\SlackBotService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Friendly companion to the auto clock-out cap. When someone has been clocked
 * in past a threshold (default 8h), DM them on Slack with two buttons —
 * "Yes, still working" (snooze) or "No, clock me out" (close now). After a few
 * unanswered nudges we clock them out and say why. The 12h cap in
 * attendance:auto-clock-out remains the final backstop for anyone we can't
 * reach on Slack.
 */
class StillWorkingCheck extends Command
{
    use RequiresBoundTenant;

    protected $signature = 'attendance:still-working-check
        {--reprompt-minutes=10 : Minimum gap between nudges to the same person}
        {--max-prompts= : Clock the person out after this many unanswered nudges (default from config, 4)}
        {--snooze-hours=2 : How long a "yes, still working" keeps them clocked in before we ask again}
        {--user= : Limit to a single user id (for testing)}
        {--max-dms=200 : Maximum blocking Slack DMs to send in one pass (the rest wait for the next run)}
        {--force : Ignore the hours threshold (testing — prompt even a fresh clock-in)}
        {--dry-run : Report without DMing or closing}';

    protected $description = 'DM long-running clock-ins a "still working?" check; clock out non-responders.';

    /** Users resolved per pass. Keeps the set-based lookups bounded. */
    private const USER_CHUNK = 500;

    public function handle(SlackBotService $slack, AttendanceCloser $closer): int
    {
        $thresholdHours = (float) config('services.attendance.prompt_after_hours', 8);
        $reprompt = (int) $this->option('reprompt-minutes');
        $maxPrompts = (int) ($this->option('max-prompts') ?? config('services.attendance.max_unanswered_prompts', 4));
        $snoozeHours = (float) $this->option('snooze-hours');
        $force = (bool) $this->option('force');
        $dry = (bool) $this->option('dry-run');
        $now = Carbon::now(\App\Support\BusinessTime::tz());

        // Anyone running the screenshot monitor right now is clearly active —
        // we never nudge (or auto-close) them. One query, O(1) lookup.
        $activeTrackerIds = array_flip(
            TrackingSession::where('status', TrackingSession::STATUS_ACTIVE)
                ->distinct()->pluck('user_id')->all()
        );

        $pinged = 0;
        $closed = 0;
        $failed = 0;
        $dmBudget = max(1, (int) $this->option('max-dms'));

        // SCALE: this used to hydrate every user in the tenant and call
        // AttendanceCloser::openClockIn() per person (two ordered queries each),
        // then make a BLOCKING Slack HTTP call (10s timeout) inline for every
        // prompted user — on a ten-minute schedule, sequentially across every
        // organization. The pass could not finish inside its own interval, and
        // once withoutOverlapping() started skipping runs the "still working?"
        // safety net silently stopped firing altogether.
        //
        // Now: users stream in bounded chunks, each chunk resolves its open
        // clock-ins in two set queries, Slack failures are isolated per person,
        // and the number of blocking DMs per pass is capped so wall-clock stays
        // inside the interval no matter how large the tenant is.
        User::query()
            ->active()
            ->when($this->option('user'), fn ($q) => $q->where('id', $this->option('user')))
            ->orderBy('id')
            ->chunkById(self::USER_CHUNK, function ($users) use (
                $slack, $closer, $now, $force, $dry, $reprompt, $maxPrompts,
                $snoozeHours, $thresholdHours, $activeTrackerIds,
                &$pinged, &$closed, &$failed, &$dmBudget
            ) {
                $clockIns = $this->openClockInsPerUser($users->modelKeys());

                if ($clockIns->isEmpty()) {
                    return;
                }

                // One query for the whole chunk instead of a firstOrCreate per user.
                $checks = AttendanceClockCheck::query()
                    ->whereIn('clock_in_id', $clockIns->pluck('id')->all())
                    ->get()
                    ->keyBy('clock_in_id');

                foreach ($users as $user) {
                    $clockIn = $clockIns->get($user->id);
                    if (! $clockIn) {
                        continue;
                    }

                    $clockInTs = Carbon::parse($clockIn->action_timestamp)->setTimezone(\App\Support\BusinessTime::tz());
                    $ageHours = $clockInTs->diffInMinutes($now, false) / 60;

                    // Per-person reminder threshold overrides the global default, so a
                    // 10h-shift person can be nudged at, say, 9h instead of the team's 8h.
                    $userThreshold = $user->clockout_reminder_hours !== null
                        ? (float) $user->clockout_reminder_hours
                        : $thresholdHours;
                    if (! $force && $ageHours < $userThreshold) {
                        continue;
                    }

                    // Running the desktop tracker = a live "still working" signal. Skip
                    // the nudge and snooze any open check so they're never auto-closed
                    // while actively tracking.
                    if (! $force && isset($activeTrackerIds[$user->id])) {
                        AttendanceClockCheck::where('clock_in_id', $clockIn->id)->update([
                            'confirmed_until' => $now->copy()->addHours((int) ceil($snoozeHours)),
                            'last_response_at' => $now,
                        ]);

                        continue;
                    }

                    $check = $checks->get($clockIn->id) ?? AttendanceClockCheck::firstOrCreate(
                        ['clock_in_id' => $clockIn->id],
                        ['user_id' => $user->id]
                    );

                    if ($check->resolved_at) {
                        continue;
                    }
                    if ($check->confirmed_until && $check->confirmed_until->isFuture()) {
                        continue; // they said "still working" recently
                    }
                    if ($check->last_prompted_at && $check->last_prompted_at->gt($now->copy()->subMinutes($reprompt))) {
                        continue; // nudged too recently
                    }

                    try {
                        // Out of nudges with no answer -> clock them out now and say why.
                        if ($check->prompts_sent >= $maxPrompts) {
                            $reason = sprintf(
                                'Auto clock-out: no response to %d "still working?" checks on Slack; system closed your day.',
                                $check->prompts_sent
                            );
                            $this->line(sprintf('%-22s no response after %d nudges -> clock out %s', $user->name, $check->prompts_sent, $dry ? '[dry-run]' : ''));
                            if (! $dry) {
                                $closer->close($user->id, $now, $reason);
                                $check->update(['resolved_at' => $now, 'resolution' => 'no_response']);
                                $this->announceLockout($slack, $user, $now, $reason);
                            }
                            $closed++;

                            continue;
                        }

                        // Send (or re-send) the prompt.
                        $this->line(sprintf('%-22s clocked in %s ago -> nudge #%d %s', $user->name, $this->humanHours($ageHours), $check->prompts_sent + 1, $dry ? '[dry-run]' : ''));
                        if ($dry) {
                            continue;
                        }

                        if ($dmBudget <= 0) {
                            // Wall-clock guard: each DM is a blocking HTTP call with a
                            // 10s timeout, so an unbounded number of them is what makes
                            // the pass overrun its own schedule and get skipped. The
                            // remainder is picked up next pass; the hard 12h cap in
                            // attendance:auto-clock-out remains the backstop either way.
                            $this->warn('    Slack DM budget for this pass exhausted; remaining nudges deferred to the next run.');

                            return false; // stop chunking entirely
                        }

                        $dmBudget--;

                        $sent = $slack->dmBlocksByEmail(
                            $user->email,
                            'Are you still working? (Staffvora attendance check)',
                            $this->blocks($check->id, $user->name, $clockInTs, $ageHours)
                        );

                        if ($sent) {
                            $check->update(['prompts_sent' => $check->prompts_sent + 1, 'last_prompted_at' => $now]);
                            $pinged++;
                        } else {
                            $this->warn("    could not DM {$user->name} ({$user->email}) — no Slack match; 12h cap will handle it.");
                        }
                    } catch (\Throwable $e) {
                        // A Slack outage (or one malformed record) must not abort
                        // the pass for everyone else in the tenant.
                        $failed++;
                        $this->error(sprintf('  %s: %s', $user->name, $e->getMessage()));
                        Log::error('attendance:still-working-check failed for user', [
                            'user_id' => $user->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $this->info(sprintf(
            '%s %d, clocked out %d non-responder(s)%s.',
            $dry ? 'Would nudge' : 'Nudged',
            $pinged,
            $closed,
            $failed > 0 ? "; {$failed} error(s)" : ''
        ));

        return self::SUCCESS;
    }

    /**
     * Set-based equivalent of AttendanceCloser::openClockIn(), for a whole chunk
     * of users in TWO queries instead of two per person.
     *
     * Semantics are preserved exactly: a session counts as OPEN when the user's
     * newest entry is clock_in, break_start or break_end (someone back from a
     * break is working again and has NOT clocked out); the returned entry is the
     * newest clock_in for those users. Ties on action_timestamp break on id,
     * matching the original `orderByDesc('action_timestamp')->orderByDesc('id')`.
     *
     * Written as an anti-join rather than a window function so it runs
     * identically on MySQL 8 and the local SQLite database, and is served by the
     * (user_id, action_timestamp, id) index.
     *
     * @param  array<int, int>  $userIds
     * @return \Illuminate\Support\Collection<int, TimeEntry>
     */
    private function openClockInsPerUser(array $userIds)
    {
        $latest = $this->latestEntriesPerUser($userIds, null);

        $openUserIds = $latest
            ->filter(fn ($entry) => in_array($entry->action_type, ['clock_in', 'break_start', 'break_end'], true))
            ->keys()
            ->all();

        return $this->latestEntriesPerUser($openUserIds, ['clock_in']);
    }

    /**
     * Greatest-n-per-group: the single newest time entry for each of $userIds,
     * keyed by user_id, in ONE query.
     *
     * @param  array<int, int>  $userIds
     * @param  array<int, string>|null  $actionTypes
     * @return \Illuminate\Support\Collection<int, TimeEntry>
     */
    private function latestEntriesPerUser(array $userIds, ?array $actionTypes)
    {
        if ($userIds === []) {
            return collect();
        }

        return TimeEntry::query()
            ->whereIn('user_id', $userIds)
            ->when($actionTypes, fn ($q) => $q->whereIn('action_type', $actionTypes))
            ->whereNotExists(function ($sub) use ($userIds, $actionTypes) {
                $sub->selectRaw('1')
                    ->from('time_entries as newer')
                    ->whereColumn('newer.user_id', 'time_entries.user_id')
                    ->whereIn('newer.user_id', $userIds)
                    ->when($actionTypes, fn ($q) => $q->whereIn('newer.action_type', $actionTypes))
                    ->where(function ($w) {
                        $w->whereColumn('newer.action_timestamp', '>', 'time_entries.action_timestamp')
                            ->orWhere(function ($tie) {
                                $tie->whereColumn('newer.action_timestamp', '=', 'time_entries.action_timestamp')
                                    ->whereColumn('newer.id', '>', 'time_entries.id');
                            });
                    });
            })
            ->get()
            ->keyBy('user_id');
    }

    /** @return array<int, array<string, mixed>> */
    private function blocks(int $checkId, string $name, Carbon $clockInTs, float $ageHours): array
    {
        return [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => sprintf(
                        ":wave: Hi %s — you've been clocked in for *%s* (since %s). Are you still working?",
                        $name,
                        $this->humanHours($ageHours),
                        $clockInTs->format('g:i A')
                    ),
                ],
            ],
            [
                'type' => 'actions',
                'block_id' => 'attendance_check_'.$checkId,
                'elements' => [
                    [
                        'type' => 'button',
                        'action_id' => 'attendance_still_working',
                        'style' => 'primary',
                        'text' => ['type' => 'plain_text', 'text' => '✅ Yes, still working'],
                        'value' => (string) $checkId,
                    ],
                    [
                        'type' => 'button',
                        'action_id' => 'attendance_clock_out',
                        'style' => 'danger',
                        'text' => ['type' => 'plain_text', 'text' => '⏹ No, clock me out'],
                        'value' => (string) $checkId,
                    ],
                ],
            ],
        ];
    }

    /** Tell the team channel that someone was auto clocked-out, and why. */
    private function announceLockout(SlackBotService $slack, User $user, Carbon $at, string $reason): void
    {
        $channel = config('services.attendance.lockout_channel')
            ?: config('services.attendance.clockin_channel');

        if (! $channel) {
            return;
        }

        $slack->postToChannel($channel, sprintf(
            ":lock: *%s* was automatically clocked out at %s.\n> %s",
            $user->name,
            $at->format('g:i A'),
            $reason,
        ));
    }

    private function humanHours(float $hours): string
    {
        $mins = max(0, (int) round($hours * 60));

        return $mins >= 60 ? sprintf('%dh %dm', intdiv($mins, 60), $mins % 60) : $mins.'m';
    }
}
