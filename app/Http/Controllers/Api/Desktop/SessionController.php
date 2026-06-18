<?php

namespace App\Http\Controllers\Api\Desktop;

use App\Http\Controllers\Controller;
use App\Http\Requests\Desktop\HeartbeatRequest;
use App\Http\Requests\Desktop\StartSessionRequest;
use App\Http\Requests\Desktop\StopSessionRequest;
use App\Models\Client;
use App\Models\TimeEntry;
use App\Models\TrackingSession;
use App\Models\User;
use App\Services\TrackingSessionService;
use App\Support\BusinessTime;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function __construct(private TrackingSessionService $sessions) {}

    /**
     * Start a new tracking session. Idempotent on (user_id, client_uuid)
     * so the desktop app can safely retry after a flaky upload.
     */
    public function start(StartSessionRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $client = isset($data['client_id'])
            ? Client::query()->with('upworkProfiles:id')->find($data['client_id'])
            : null;

        // The client may have its profile linked via the legacy column OR the
        // newer client_upwork_profile pivot. Fall through both.
        $derivedProfileId = $client?->upwork_profile_id
            ?? $client?->upworkProfiles->first()?->id;

        $session = TrackingSession::firstOrCreate(
            [
                'user_id' => $user->id,
                'client_uuid' => $data['client_uuid'],
            ],
            [
                'client_id' => $data['client_id'] ?? null,
                'upwork_profile_id' => $derivedProfileId ?? ($data['upwork_profile_id'] ?? null),
                // Prefer the per-entry work type the user picked in the desktop
                // app (tracker/manual/fixed/...). Fall back to deriving from the
                // client, and never store the client-level "tracker_manual"
                // engagement type as a per-entry category.
                'work_type' => $this->sessions->normaliseWorkType($data['work_type'] ?? $client?->work_type),
                'task_note' => $data['task_note'] ?? null,
                // Desktop sends UTC ISO; convert to app timezone for storage so
                // it lines up with the rest of the data (see BusinessTime).
                'started_at' => BusinessTime::fromClient($data['started_at']) ?? now(),
                'last_heartbeat_at' => BusinessTime::fromClient($data['started_at']) ?? now(),
                'status' => TrackingSession::STATUS_ACTIVE,
                'source' => 'desktop',
                'device_name' => $data['device_name'] ?? null,
                'platform' => $data['platform'] ?? null,
                'app_version' => $data['app_version'] ?? null,
            ],
        );

        // Single-device tracking (last device wins): unless this user is allowed
        // to track on multiple devices, starting here stops any session still
        // running on another device, so the same wall-clock time is never
        // double-counted. The response reports what was stopped so the desktop
        // can tell the user.
        $stoppedDevices = [];
        if ($session->wasRecentlyCreated && ! $user->allow_multiple_devices) {
            $others = TrackingSession::where('user_id', $user->id)
                ->where('status', TrackingSession::STATUS_ACTIVE)
                ->where('id', '!=', $session->id)
                ->get();
            foreach ($others as $other) {
                $this->sessions->finalize($other);
                $stoppedDevices[] = $other->device_name ?: 'another device';
            }
        }

        // Starting the tracker means they're working — if they forgot to clock
        // in (or didn't know they had to), clock them in automatically at the
        // session's real start time. Only on a genuine new session, never on an
        // idempotent retry, and never if they're already clocked in.
        if ($session->wasRecentlyCreated) {
            $this->ensureClockedIn($user, $session->started_at ?? now());
        }

        return response()->json([
            'id' => $session->id,
            'status' => $session->status,
            'started_at' => $session->started_at?->toIso8601String(),
            'client_uuid' => $session->client_uuid,
            'stopped_other_devices' => $stoppedDevices,
        ], 201);
    }

    /**
     * Auto clock-in when a tracking session starts and the user isn't already
     * clocked in. Uses the session start time (which the desktop sends from its
     * local clock, so an offline-queued start still records the correct time).
     */
    private function ensureClockedIn(User $user, Carbon $startedAt): void
    {
        $last = TimeEntry::where('user_id', $user->id)
            ->orderByDesc('action_timestamp')->orderByDesc('id')
            ->first();

        // clock_in / break_start / break_end all mean "still clocked in".
        if ($last && in_array($last->action_type, ['clock_in', 'break_start', 'break_end'], true)) {
            return;
        }

        $ts = $startedAt->copy()->setTimezone('Asia/Karachi');

        TimeEntry::create([
            'user_id' => $user->id,
            'action_type' => 'clock_in',
            'action_timestamp' => $ts,
            'action_date' => $user->attendanceDateFor($ts),
            'action_time' => $ts->toTimeString(),
            'notes' => 'Auto clock-in (started tracker)',
        ]);
    }

    public function heartbeat(HeartbeatRequest $request, TrackingSession $session): JsonResponse
    {
        abort_unless($session->user_id === $request->user()->id, 403);

        // If this session was already stopped (e.g. another device took over
        // under the single-device rule), don't let stale heartbeats re-inflate
        // its time. Signal the desktop so it can stop tracking locally.
        if ($session->status !== TrackingSession::STATUS_ACTIVE) {
            return response()->json([
                'status' => $session->status,
                'stopped_elsewhere' => true,
            ], 409);
        }

        $data = $request->validated();

        $session->update([
            'total_seconds' => $data['total_seconds'],
            'activity_percent' => $data['activity_percent'] ?? $session->activity_percent,
            'last_heartbeat_at' => BusinessTime::fromClient($data['heartbeat_at'] ?? null) ?? now(),
        ]);

        return response()->json(['status' => 'ok']);
    }

    public function stop(StopSessionRequest $request, TrackingSession $session): JsonResponse
    {
        abort_unless($session->user_id === $request->user()->id, 403);

        $data = $request->validated();

        $session->update([
            'stopped_at' => BusinessTime::fromClient($data['stopped_at']) ?? now(),
            'total_seconds' => $data['total_seconds'],
            'activity_percent' => $data['activity_percent'] ?? $session->activity_percent,
            'task_note' => $data['task_note'] ?? $session->task_note,
            'status' => TrackingSession::STATUS_STOPPED,
            'last_heartbeat_at' => now(),
        ]);

        $this->sessions->syncWorkHour($session->fresh());

        return response()->json([
            'id' => $session->id,
            'status' => $session->status,
            'stopped_at' => $session->stopped_at?->toIso8601String(),
        ]);
    }

    /**
     * Per-day totals for the trailing 7 days (including today), for the
     * desktop week chart.
     */
    public function week(Request $request): JsonResponse
    {
        // Bucket by the business timezone, not server UTC, so late-evening
        // sessions land on the user's calendar day.
        $today = BusinessTime::today();
        $end = $today->copy()->endOfDay();
        $start = $today->copy()->subDays(6)->startOfDay();

        $perDay = TrackingSession::forUser($request->user()->id)
            ->whereBetween('started_at', BusinessTime::utcRange($start, $end))
            ->get(['started_at', 'total_seconds'])
            ->groupBy(fn (TrackingSession $s) => BusinessTime::dateKey($s->started_at))
            ->map(fn ($group) => (int) $group->sum('total_seconds'));

        $days = [];
        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $iso = $cursor->toDateString();
            $days[] = [
                'date' => $iso,
                'weekday' => $cursor->format('D'),
                'total_seconds' => (int) ($perDay[$iso] ?? 0),
                'is_today' => $cursor->isSameDay($today),
            ];
        }

        return response()->json(['days' => $days]);
    }

    /**
     * The user's most recent distinct clients (last 14 days), newest first.
     * Powers the desktop quick-start chips so switching between regular
     * clients is one click.
     */
    public function recentClients(Request $request): JsonResponse
    {
        $sessions = TrackingSession::forUser($request->user()->id)
            ->with('client:id,name')
            ->whereNotNull('client_id')
            ->where('started_at', '>=', now()->subDays(14))
            ->orderByDesc('started_at')
            ->get(['id', 'client_id', 'work_type', 'task_note', 'started_at']);

        $clients = $sessions
            ->unique('client_id')
            ->take(6)
            ->filter(fn (TrackingSession $session) => $session->client !== null)
            ->map(fn (TrackingSession $session) => [
                'client_id' => $session->client_id,
                'client_name' => $session->client->name,
                'last_work_type' => $session->work_type,
                'last_task_note' => $session->task_note,
                'last_started_at' => $session->started_at?->toIso8601String(),
            ])
            ->values();

        return response()->json(['clients' => $clients]);
    }

    public function today(Request $request): JsonResponse
    {
        $today = BusinessTime::today();

        $sessions = TrackingSession::forUser($request->user()->id)
            ->with(['client:id,name', 'upworkProfile:id,name'])
            ->whereBetween('started_at', BusinessTime::utcRange($today->copy()->startOfDay(), $today->copy()->endOfDay()))
            ->orderBy('started_at', 'desc')
            ->get(['id', 'client_uuid', 'client_id', 'upwork_profile_id', 'work_type', 'task_note', 'started_at', 'stopped_at', 'total_seconds', 'status'])
            ->map(fn (TrackingSession $session) => [
                'id' => $session->id,
                'client_uuid' => $session->client_uuid,
                'client_id' => $session->client_id,
                'client_name' => $session->client?->name,
                'upwork_profile_id' => $session->upwork_profile_id,
                'upwork_profile_name' => $session->upworkProfile?->name,
                'work_type' => $session->work_type,
                'task_note' => $session->task_note,
                'started_at' => $session->started_at?->toIso8601String(),
                'stopped_at' => $session->stopped_at?->toIso8601String(),
                'total_seconds' => $session->total_seconds,
                'status' => $session->status,
            ]);

        return response()->json(['sessions' => $sessions]);
    }
}
