<?php

namespace App\Console\Commands;

use App\Models\TrackingSession;
use App\Services\TrackingSessionService;
use Illuminate\Console\Command;

/**
 * One-off backfill: re-mirror finished tracking sessions into work_hours using
 * the calendar-day split. Existing tracker rows were dumped entirely on the
 * session's start date, so overnight shifts were mis-attributed (the next day's
 * Report/Diary/Dashboard missed the post-midnight hours). Re-running syncWorkHour
 * deletes each session's old mirror row(s) and recreates one row per day.
 * Manual work_hours (no tracking_session_id) are never touched.
 */
class ResyncWorkHours extends Command
{
    protected $signature = 'tracker:resync-work-hours
        {--since= : Only sessions started on/after this date (Y-m-d)}
        {--user= : Limit to one user id}
        {--dry-run : Count without writing}';

    protected $description = 'Re-mirror finished tracking sessions into work_hours, splitting overnight sessions by calendar day.';

    public function handle(TrackingSessionService $service): int
    {
        $dry = (bool) $this->option('dry-run');

        $query = TrackingSession::query()
            ->where('total_seconds', '>=', 60)
            ->whereNotNull('stopped_at')
            ->when($this->option('since'), fn ($q) => $q->whereDate('started_at', '>=', $this->option('since')))
            ->when($this->option('user'), fn ($q) => $q->where('user_id', $this->option('user')));

        $count = (clone $query)->count();
        $this->info(($dry ? '[dry-run] ' : '')."Re-syncing {$count} session(s)...");

        if ($dry) {
            return self::SUCCESS;
        }

        $done = 0;
        $query->orderBy('id')->chunkById(200, function ($sessions) use ($service, &$done) {
            foreach ($sessions as $s) {
                $service->syncWorkHour($s);
                $done++;
            }
            $this->line("  {$done} done...");
        });

        $this->info("Re-synced {$done} session(s).");

        return self::SUCCESS;
    }
}
