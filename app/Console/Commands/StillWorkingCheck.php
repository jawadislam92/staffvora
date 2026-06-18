<?php

namespace App\Console\Commands;

use App\Models\AttendanceClockCheck;
use App\Models\TrackingSession;
use App\Models\User;
use App\Services\AttendanceCloser;
use App\Services\SlackBotService;
use Carbon\Carbon;
use Illuminate\Console\Command;

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
    protected $signature = 'attendance:still-working-check
        {--reprompt-minutes=10 : Minimum gap between nudges to the same person}
        {--max-prompts= : Clock the person out after this many unanswered nudges (default from config, 4)}
        {--snooze-hours=2 : How long a "yes, still working" keeps them clocked in before we ask again}
        {--user= : Limit to a single user id (for testing)}
        {--force : Ignore the hours threshold (testing — prompt even a fresh clock-in)}
        {--dry-run : Report without DMing or closing}';

    protected $description = 'DM long-running clock-ins a "still working?" check; clock out non-responders.';

    public function handle(SlackBotService $slack, AttendanceCloser $closer): int
    {
        $thresholdHours = (float) config('services.attendance.prompt_after_hours', 8);
        $reprompt = (int) $this->option('reprompt-minutes');
        $maxPrompts = (int) ($this->option('max-prompts') ?? config('services.attendance.max_unanswered_prompts', 4));
        $snoozeHours = (float) $this->option('snooze-hours');
        $force = (bool) $this->option('force');
        $dry = (bool) $this->option('dry-run');
        $now = Carbon::now('Asia/Karachi');

        // Anyone running the screenshot monitor right now is clearly active —
        // we never nudge (or auto-close) them. One query, O(1) lookup.
        $activeTrackerIds = array_flip(
            TrackingSession::where('status', TrackingSession::STATUS_ACTIVE)
                ->distinct()->pluck('user_id')->all()
        );

        $users = User::query()
            ->when($this->option('user'), fn ($q) => $q->where('id', $this->option('user')))
            ->get();

        $pinged = 0;
        $closed = 0;

        foreach ($users as $user) {
            $clockIn = $closer->openClockIn($user->id);
            if (! $clockIn) {
                continue;
            }

            $clockInTs = Carbon::parse($clockIn->action_timestamp)->setTimezone('Asia/Karachi');
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

            $check = AttendanceClockCheck::firstOrCreate(
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

            $sent = $slack->dmBlocksByEmail(
                $user->email,
                'Are you still working? (SA Track attendance check)',
                $this->blocks($check->id, $user->name, $clockInTs, $ageHours)
            );

            if ($sent) {
                $check->update(['prompts_sent' => $check->prompts_sent + 1, 'last_prompted_at' => $now]);
                $pinged++;
            } else {
                $this->warn("    could not DM {$user->name} ({$user->email}) — no Slack match; 12h cap will handle it.");
            }
        }

        $this->info(sprintf('%s %d, clocked out %d non-responder(s).', $dry ? 'Would nudge' : 'Nudged', $pinged, $closed));

        return self::SUCCESS;
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
