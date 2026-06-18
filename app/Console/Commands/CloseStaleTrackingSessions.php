<?php

namespace App\Console\Commands;

use App\Models\TrackingSession;
use App\Services\TrackingSessionService;
use Illuminate\Console\Command;

class CloseStaleTrackingSessions extends Command
{
    protected $signature = 'monitoring:close-stale-sessions
        {--minutes=15 : Close active sessions with no heartbeat for this many minutes}
        {--dry-run : Report what would be closed without closing}';

    protected $description = 'Stop tracking sessions whose desktop app died without sending a stop, and sync their work hours.';

    public function handle(TrackingSessionService $sessions): int
    {
        $threshold = now()->subMinutes((int) $this->option('minutes'));

        $stale = TrackingSession::query()
            ->where('status', TrackingSession::STATUS_ACTIVE)
            ->where(function ($query) use ($threshold) {
                $query->where('last_heartbeat_at', '<', $threshold)
                    ->orWhere(function ($q) use ($threshold) {
                        $q->whereNull('last_heartbeat_at')->where('started_at', '<', $threshold);
                    });
            })
            ->orderBy('started_at')
            ->get();

        if ($stale->isEmpty()) {
            $this->info('No stale sessions.');

            return self::SUCCESS;
        }

        foreach ($stale as $session) {
            $endedAt = $session->last_heartbeat_at ?? $session->started_at;
            $this->line(sprintf(
                'Session #%d (user %d) started %s, last heartbeat %s -> %s',
                $session->id,
                $session->user_id,
                $session->started_at?->format('Y-m-d H:i'),
                $session->last_heartbeat_at?->format('Y-m-d H:i') ?? 'never',
                $this->option('dry-run') ? 'would close' : 'closing'
            ));

            if (! $this->option('dry-run')) {
                $sessions->finalize($session, $endedAt);
            }
        }

        $this->info(($this->option('dry-run') ? 'Would close ' : 'Closed ').$stale->count().' stale session(s).');

        return self::SUCCESS;
    }
}
