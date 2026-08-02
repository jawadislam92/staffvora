<?php

namespace App\Services;

use App\Models\Feedback;
use App\Models\MonitoringSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Slack alerts for the feedback inbox.
 *
 * A request that nobody sees is a request that never gets answered, so a new
 * submission is pushed to the people who can act on it instead of waiting for
 * someone to happen to open the inbox.
 *
 * Delivery mirrors the pattern already used for password-reset codes: DM the
 * person directly (their Slack is matched by email), and fall back to the
 * workspace channel when they aren't reachable that way. Slack is best-effort —
 * every failure is swallowed, because a Slack outage must never stop somebody
 * filing a request or a manager replying to one.
 */
class FeedbackSlackNotifier
{
    /**
     * Hard ceiling on the DM fan-out. Every manager costs up to 3 blocking
     * Slack round trips, so an uncapped fan-out turned one POST /feedback into
     * hundreds of outbound HTTPS calls inside a single web request. Nobody
     * triages an inbox with more than a couple of dozen people on it anyway.
     */
    private const MAX_MANAGERS = 25;

    /**
     * A burst of submissions collapses into ONE fan-out per workspace per
     * window; everything else in the window gets a single channel post. The
     * cap alone does not stop the abuse — 5 submissions/minute x 25 managers is
     * still ~375 Slack calls/minute from one member, enough to flood every
     * manager's DMs and get the workspace's bot token rate-limited.
     */
    private const BURST_WINDOW_MINUTES = 2;

    public function __construct(private SlackBotService $slack)
    {
    }

    /** Tell the people who triage the inbox that a new request landed. */
    public function newRequest(Feedback $item): void
    {
        $this->safely(function () use ($item) {
            $author = $item->user?->name ?? 'Someone';
            $text = sprintf(
                "*New %s from %s*\n>%s\n%s",
                $this->typeLabel($item->type),
                $author,
                str_replace("\n", ' ', $item->subject),
                route('feedback.index')
            );

            // Second and later submissions inside the window still get
            // surfaced — just once, in the channel, instead of re-DMing
            // everyone. The information still lands; the amplifier does not.
            if (! $this->claimFanOut($item)) {
                $this->postToChannel($text);

                return;
            }

            $reached = 0;
            foreach ($this->managers() as $manager) {
                if ($manager->email && $this->slack->dmByEmail($manager->email, $text)) {
                    $reached++;
                }
            }

            // Nobody was reachable by DM — fall back to the workspace channel so
            // the request is still surfaced somewhere a human looks.
            if ($reached === 0) {
                $this->postToChannel($text);
            }
        });
    }

    /**
     * True for the first submission in this workspace's window, false for the
     * rest. Cache::add is atomic, so concurrent submissions cannot both win.
     * Per-organization, so one noisy tenant never silences another.
     */
    private function claimFanOut(Feedback $item): bool
    {
        $orgId = $item->organization_id ?? 'unbound';

        return Cache::add(
            'feedback-fanout:'.$orgId,
            true,
            now()->addMinutes(self::BURST_WINDOW_MINUTES)
        );
    }

    /** Tell the person who filed a request that someone answered it. */
    public function replied(Feedback $item, string $body, User $responder): void
    {
        $this->safely(function () use ($item, $body, $responder) {
            $submitter = $item->user;
            if (! $submitter?->email || $submitter->id === $responder->id) {
                return;
            }

            $this->slack->dmByEmail($submitter->email, sprintf(
                "*%s replied to your request*\n_%s_\n>%s\n%s",
                $responder->name,
                str_replace("\n", ' ', $item->subject),
                str_replace("\n", ' ', mb_strimwidth($body, 0, 280, '…')),
                route('feedback.index')
            ));
        });
    }

    /**
     * Everyone who can triage the inbox: super admins plus anyone granted
     * feedback.manage. Archived people are skipped — they can't log in, so
     * messaging them would just be noise.
     *
     * This used to hydrate EVERY user in the workspace (permissions JSON and
     * all) and filter in PHP, behind a per-request Schema::hasColumn() that
     * costs an information_schema scan on MySQL. For a 500-person tenant that
     * was 500 models + a metadata query on every single feedback submission.
     *
     * Now the database does the narrowing:
     *  - the candidate set is filtered to super admins plus rows whose
     *    permissions JSON mentions feedback.manage. The LIKE is deliberate
     *    rather than whereJsonContains — SQLite is the local driver and JSON1
     *    is not guaranteed, whereas a LIKE over the JSON text column works
     *    identically on both engines;
     *  - only the five columns actually used are selected;
     *  - the result is capped, so the fan-out has a known upper bound.
     * The PHP pass afterwards is the authority (LIKE can over-match on a
     * longer permission key that merely contains this one), so the SQL only
     * ever narrows — it can never admit someone hasPermission() would reject.
     *
     * is_active is matched null-safely to mirror User::isActive(): rows created
     * before that column existed read as active, and dropping them would
     * silently stop notifying legitimate managers.
     */
    private function managers()
    {
        return User::query()
            ->where(fn ($q) => $q->where('is_active', true)->orWhereNull('is_active'))
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->where(fn ($q) => $q->where('role', 'super_admin')
                ->orWhere('permissions', 'like', '%"feedback.manage"%'))
            ->orderBy('id')
            ->limit(self::MAX_MANAGERS)
            ->get(['id', 'name', 'email', 'role', 'permissions'])
            ->filter(fn (User $u) => $u->isSuperAdmin() || $u->hasPermission('feedback.manage'))
            ->values();
    }

    private function postToChannel(string $text): void
    {
        $channel = MonitoringSetting::current()->slack_attendance_channel;

        if (filled($channel)) {
            $this->slack->postToChannel($channel, $text);
        }
    }

    private function typeLabel(?string $type): string
    {
        return match ($type) {
            'bug' => 'bug report',
            'idea', 'feature' => 'feature request',
            'question' => 'question',
            default => 'request',
        };
    }

    /** Slack is never allowed to break the request it is reporting on. */
    private function safely(callable $fn): void
    {
        try {
            if (! $this->slack->configured()) {
                return;
            }

            $fn();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
