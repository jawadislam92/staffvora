<?php

namespace App\Console\Commands;

use App\Models\TrackingActivitySample;
use App\Models\TrackingScreenshot;
use App\Models\TrackingSession;
use App\Models\User;
use App\Services\SlackBotService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Capture-health watchdog. A tracker can keep sending heartbeats (so it shows
 * "live" with time + activity %) while its screenshot/sample helpers are blocked
 * by antivirus — so the person looks tracked but the day has no screenshots or
 * apps. This finds those silent sessions in real time and posts a Slack alert,
 * so a broken machine surfaces immediately instead of being discovered days
 * later in an empty report. Alerts at most once per session.
 */
class SilentTrackerCheck extends Command
{
    protected $signature = 'monitoring:silent-tracker-check
        {--minutes=20 : Flag a live session with no screenshot/sample for this long}
        {--user= : Limit to one user id (testing)}
        {--force : Ignore the already-alerted guard (re-alert; testing)}
        {--dry-run : Report without posting or marking}';

    protected $description = 'Alert when a live tracker sends heartbeats but no screenshots/samples (likely antivirus block).';

    public function handle(SlackBotService $slack): int
    {
        $minutes = (int) $this->option('minutes');
        $dry = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $now = Carbon::now('Asia/Karachi');
        $cutoff = $now->copy()->subMinutes($minutes);
        $liveCutoff = $now->copy()->subMinutes(5);

        $sessions = TrackingSession::query()
            ->where('status', TrackingSession::STATUS_ACTIVE)
            ->where('started_at', '<=', $cutoff)            // running long enough to have captured
            ->where('last_heartbeat_at', '>=', $liveCutoff) // genuinely live (heartbeats arriving)
            ->when(! $force, fn ($q) => $q->whereNull('health_alerted_at'))
            ->when($this->option('user'), fn ($q) => $q->where('user_id', $this->option('user')))
            ->get();

        $flagged = 0;

        foreach ($sessions as $s) {
            $lastShot = TrackingScreenshot::where('tracking_session_id', $s->id)->max('captured_at');
            $lastSample = TrackingActivitySample::where('tracking_session_id', $s->id)->max('captured_at');
            $last = collect([$lastShot, $lastSample])->filter()->map(fn ($d) => Carbon::parse($d))->max();

            $silent = ! $last || $last->lt($cutoff);
            if (! $silent) {
                continue;
            }

            $user = User::find($s->user_id);
            $ageMin = (int) round($s->started_at->diffInMinutes($now));
            $this->line(sprintf('%-22s live %dm, no capture %s', $user?->name ?? ('#'.$s->user_id), $ageMin, $dry ? '[dry-run]' : ''));
            $flagged++;

            if ($dry) {
                continue;
            }

            $this->announce($slack, $user, $s, $last);
            $s->update(['health_alerted_at' => $now]);
        }

        $this->info(sprintf('%s %d silent tracker(s).', $dry ? 'Would flag' : 'Flagged', $flagged));

        return self::SUCCESS;
    }

    private function announce(SlackBotService $slack, ?User $user, TrackingSession $s, ?Carbon $last): void
    {
        $channel = config('services.attendance.tracker_health_channel')
            ?: config('services.attendance.clockin_channel');

        if (! $channel) {
            return;
        }

        $lastTxt = $last ? $last->format('g:i A') : 'never';
        $slack->postToChannel($channel, sprintf(
            ":warning: *%s*'s tracker is live (sending time) but has captured *no screenshots/activity* since %s — likely an antivirus block on that machine. Device: %s. Session started %s.",
            $user?->name ?? 'Someone',
            $lastTxt,
            $s->device_name ?: 'unknown',
            $s->started_at->format('g:i A'),
        ));
    }
}
