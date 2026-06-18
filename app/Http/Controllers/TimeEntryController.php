<?php

namespace App\Http\Controllers;

use App\Models\MonitoringSetting;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\TimeClockRules;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class TimeEntryController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $query = TimeEntry::forUser($user->id)->with('user');

        // Filter by date if provided
        if ($request->has('date')) {
            $query->forDate($request->date);
        }

        $entries = $query->recent(100)->get();

        return response()->json([
            'entries' => $entries->map(function ($entry) {
                return [
                    'id' => $entry->id,
                    'action_type' => $entry->action_type,
                    'action_timestamp' => $entry->action_timestamp->toISOString(),
                    'formatted_date' => $entry->formatted_action_date,
                    'formatted_time' => $entry->formatted_action_time,
                    'notes' => $entry->notes,
                    'user_name' => $entry->user->name,
                ];
            }),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'action_type' => 'required|in:clock_in,clock_out,break_start,break_end',
            'notes' => 'nullable|string|max:255',
        ]);

        $now = Carbon::now('Asia/Karachi');
        $user = Auth::user();
        $actionType = $validated['action_type'];
        $attendanceDate = $user->attendanceDateFor($now);
        $lastAction = TimeEntry::forUser($user->id)
            ->forDate($attendanceDate)
            ->orderBy('action_timestamp', 'desc')
            ->orderBy('id', 'desc')
            ->value('action_type');

        if (! $this->isActionAllowed($lastAction, $actionType)) {
            return response()->json([
                'message' => $this->blockedActionMessage($lastAction, $actionType),
            ], 422);
        }

        $entry = TimeEntry::create([
            'user_id' => $user->id,
            'action_type' => $actionType,
            'action_timestamp' => $now,
            'action_date' => $attendanceDate,
            'action_time' => $now->toTimeString(),
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'entry' => [
                'id' => $entry->id,
                'action_type' => $entry->action_type,
                'action_timestamp' => $entry->action_timestamp->toISOString(),
                'formatted_date' => $entry->formatted_action_date,
                'formatted_time' => $entry->formatted_action_time,
                'notes' => $entry->notes,
                'user_name' => $user->name,
            ],
            'message' => 'Time entry recorded successfully',
        ]);
    }

    private function isActionAllowed(?string $lastAction, string $nextAction): bool
    {
        return TimeClockRules::isAllowed($lastAction, $nextAction);
    }

    private function blockedActionMessage(?string $lastAction, string $nextAction): string
    {
        return TimeClockRules::blockedMessage($lastAction, $nextAction);
    }

    public function export(Request $request)
    {
        $user = Auth::user();
        $query = TimeEntry::forUser($user->id)->with('user');

        // Filter by date range if provided
        if ($request->has('start_date')) {
            $query->whereDate('action_date', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->whereDate('action_date', '<=', $request->end_date);
        }

        $entries = $query->orderBy('action_timestamp', 'desc')->get();

        $csv = "Date,Time,Employee,Action,Notes\n";
        foreach ($entries as $entry) {
            $csv .= implode(',', [
                $entry->formatted_action_date,
                $entry->formatted_action_time,
                $entry->user->name,
                str_replace('_', ' ', ucwords($entry->action_type)),
                '"'.($entry->notes ?? '').'"',
            ])."\n";
        }

        return response($csv)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'attachment; filename="time-entries-'.now()->format('Y-m-d').'.csv"');
    }

    public function getTodaysEntries()
    {
        $user = Auth::user();
        $today = $user->attendanceDateFor(Carbon::now('Asia/Karachi'));

        $entries = TimeEntry::forUser($user->id)
            ->forDate($today)
            ->orderBy('action_timestamp', 'desc')
            ->get();

        return response()->json([
            'entries' => $entries->map(function ($entry) {
                return [
                    'id' => $entry->id,
                    'action_type' => $entry->action_type,
                    'action_timestamp' => $entry->action_timestamp->toISOString(),
                    'formatted_date' => $entry->formatted_action_date,
                    'formatted_time' => $entry->formatted_action_time,
                    'notes' => $entry->notes,
                    'user_name' => $entry->user->name,
                ];
            }),
        ]);
    }

    public function getTodaysSummary()
    {
        $user = Auth::user();
        $now = Carbon::now('Asia/Karachi');
        $weekStart = Carbon::now('Asia/Karachi')->startOfWeek(MonitoringSetting::weekStartDay());
        $monthStart = Carbon::now('Asia/Karachi')->startOfMonth();

        if ($user->hasAnyPermission(['dashboard.view_team', 'attendance.view'])) {
            $employees = User::all();
            $ids = $employees->pluck('id')->all();
            $entriesByUser = $this->loadSummaryEntries($ids, $now, $weekStart, $monthStart);
            $trackedByUserDate = $this->loadTrackedHours($ids, $now);
            $liveByUser = $this->loadLiveSessions($ids);
            $activityByUser = $this->loadDayActivity($ids, $now);

            $employeesData = $employees->map(function ($employee) use ($now, $weekStart, $monthStart, $entriesByUser, $trackedByUserDate, $liveByUser, $activityByUser) {
                return $this->summaryStatsFor($employee, $entriesByUser->get($employee->id, collect()), $now, $weekStart, $monthStart, $trackedByUserDate, $liveByUser, $activityByUser);
            })->filter(function ($employee) {
                // Show anyone with attendance entries today OR a live tracker
                // session (so someone tracking without clocking in still shows).
                return $employee['total_entries'] > 0 || $employee['is_live'];
            });

            return response()->json([
                'employees' => $employeesData->values(),
            ]);
        } else {
            // Regular users see only their own data
            $entriesByUser = $this->loadSummaryEntries([$user->id], $now, $weekStart, $monthStart);
            $trackedByUserDate = $this->loadTrackedHours([$user->id], $now);
            $liveByUser = $this->loadLiveSessions([$user->id]);
            $activityByUser = $this->loadDayActivity([$user->id], $now);

            return response()->json([
                'employees' => [$this->summaryStatsFor($user, $entriesByUser->get($user->id, collect()), $now, $weekStart, $monthStart, $trackedByUserDate, $liveByUser, $activityByUser)],
            ]);
        }
    }

    /**
     * Day activity % per user, from the tracker's own per-session activity
     * score weighted by tracked time. We use the session score (the same number
     * shown on each screenshot) rather than raw samples, because it's delivered
     * reliably via heartbeats even when the (large) samples table is sparse.
     */
    private function loadDayActivity(array $userIds, Carbon $now): Collection
    {
        return \App\Models\TrackingSession::query()
            ->whereIn('user_id', $userIds)
            ->where('total_seconds', '>', 0)
            ->where('started_at', '<=', $now)
            // Sessions that ran at some point today, including a night shift's
            // session that started yesterday evening and finished after midnight
            // (or is still running).
            ->where(function ($q) use ($now) {
                $q->whereNull('stopped_at')
                    ->orWhere('stopped_at', '>=', $now->copy()->startOfDay());
            })
            ->get(['user_id', 'total_seconds', 'activity_percent'])
            ->groupBy('user_id')
            ->map(function ($sessions) {
                $tot = (int) $sessions->sum('total_seconds');
                if ($tot <= 0) {
                    return 0;
                }
                $weighted = $sessions->sum(fn ($s) => (int) $s->activity_percent * (int) $s->total_seconds);

                return (int) round($weighted / $tot);
            });
    }

    /**
     * Currently-running tracker sessions keyed by user_id, so the dashboard's
     * "Tracked" column counts in-progress work instead of reading 0 until the
     * session stops. One query for the whole team.
     */
    private function loadLiveSessions(array $userIds): Collection
    {
        return \App\Models\TrackingSession::query()
            ->whereIn('user_id', $userIds)
            ->where('status', \App\Models\TrackingSession::STATUS_ACTIVE)
            ->get(['user_id', 'started_at', 'total_seconds'])
            ->keyBy('user_id');
    }

    /**
     * Work-diary hours (tracker-synced + manual) per user per date for the
     * dashboard's "in office vs tracked work" comparison. One grouped query;
     * yesterday is included so an overnight shift's attendance day resolves.
     *
     * @param  array<int>  $userIds
     */
    private function loadTrackedHours(array $userIds, Carbon $now): Collection
    {
        return \App\Models\WorkHour::query()
            ->whereIn('user_id', $userIds)
            ->whereDate('date', '>=', $now->copy()->subDay()->toDateString())
            ->get(['user_id', 'date', 'hours'])
            ->groupBy(fn ($r) => $r->user_id.'|'.substr((string) $r->date, 0, 10));
    }

    /**
     * One grouped fetch instead of three queries per employee — the previous
     * shape ran ~165 queries for a full team and made every clock action feel
     * 5+ seconds slow (the dashboard refetches this summary after each one).
     *
     * @param  array<int>  $userIds
     * @return Collection keyed by user_id, entries ordered chronologically
     */
    private function loadSummaryEntries(array $userIds, Carbon $now, Carbon $weekStart, Carbon $monthStart): Collection
    {
        // An overnight shift's "today" can resolve to yesterday's date, which
        // on the 1st of a month falls before monthStart — widen the range so
        // the today-bucket never loses entries.
        $rangeStart = min($monthStart->toDateString(), $weekStart->toDateString(), $now->copy()->subDay()->toDateString());

        return TimeEntry::query()
            ->whereIn('user_id', $userIds)
            ->whereDate('action_date', '>=', $rangeStart)
            ->orderBy('action_timestamp', 'asc')
            ->get()
            ->groupBy('user_id');
    }

    /**
     * @param  Collection  $entries  this user's entries, chronological
     * @return array<string, mixed>
     */
    private function summaryStatsFor(User $employee, $entries, Carbon $now, Carbon $weekStart, Carbon $monthStart, ?Collection $trackedByUserDate = null, ?Collection $liveByUser = null, ?Collection $activityByUser = null): array
    {
        $today = $employee->attendanceDateFor($now);
        $weekStartDate = $weekStart->toDateString();
        $monthStartDate = $monthStart->toDateString();

        $todayEntries = $entries->filter(fn ($e) => $e->action_date->toDateString() === $today)->values();
        $weeklyEntries = $entries->filter(fn ($e) => $e->action_date->toDateString() >= $weekStartDate)->values();
        $monthlyEntries = $entries->filter(fn ($e) => $e->action_date->toDateString() >= $monthStartDate)->values();

        $stats = $this->calculateEmployeeStats($employee, $todayEntries, $weeklyEntries, $monthlyEntries);

        // Actual work product for the same attendance day (tracker + manual
        // work-diary entries) — shown beside the clock-based presence hours.
        $stats['tracked_hours'] = round(
            (float) ($trackedByUserDate?->get($employee->id.'|'.$today) ?? collect())->sum('hours'),
            2
        );

        // Add the live session's running time so a currently-tracking person
        // shows real progress instead of 0 until their session syncs — but only
        // the part that falls on today's calendar date, so a night shift's
        // pre-midnight hours don't inflate today. Capped at 16h.
        $live = $liveByUser?->get($employee->id);
        if ($live) {
            $sinceMidnight = (int) $now->copy()->startOfDay()->diffInSeconds($now);
            $liveWall = (int) $live->started_at->diffInSeconds($now);
            $liveSeconds = min(16 * 3600, $liveWall, max(0, $sinceMidnight));
            $stats['tracked_hours'] = round($stats['tracked_hours'] + $liveSeconds / 3600, 2);
        }
        $stats['is_live'] = (bool) $live;

        // Day-level activity: how active they actually were while tracked
        // (replaces the confusing tracked-vs-clocked "coverage" ratio).
        $stats['activity_percent'] = (int) round((float) ($activityByUser[$employee->id] ?? 0));

        return $stats;
    }

    private function calculateEmployeeStats($employee, $todayEntries, $weeklyEntries, $monthlyEntries)
    {
        // Calculate today's stats
        $todayStats = $this->calculateTimeStats($todayEntries);

        // Calculate weekly stats
        $weeklyStats = $this->calculateTimeStats($weeklyEntries);

        // Calculate monthly stats
        $monthlyStats = $this->calculateTimeStats($monthlyEntries);

        return [
            'user_id' => $employee->id,
            'user_name' => $employee->name,
            'avatar' => $employee->avatar_url ?? null,
            'designation' => $employee->designation ?? 'Employee',
            'total_work_hours' => $todayStats['workHours'],
            'total_break_hours' => $todayStats['breakHours'],
            'weekly_work_hours' => $weeklyStats['workHours'],
            'weekly_break_hours' => $weeklyStats['breakHours'],
            'monthly_work_hours' => $monthlyStats['workHours'],
            'monthly_break_hours' => $monthlyStats['breakHours'],
            'total_entries' => $todayEntries->count(),
            'last_action' => $todayStats['lastAction'],
            'current_status' => $todayStats['status'],
            'last_action_time' => $todayEntries->last()?->formatted_action_time,
            'shift_start_time' => $employee->shift_start_time?->format('H:i'),
        ];
    }

    private function calculateTimeStats($entries)
    {
        $totalWorkMinutes = 0;
        $totalBreakMinutes = 0;
        $currentSessionStart = null;
        $currentBreakStart = null;
        $lastAction = null;
        $status = 'Not Started';

        foreach ($entries as $entry) {
            $entryTime = Carbon::parse($entry->action_timestamp)->setTimezone('Asia/Karachi');
            $lastAction = $entry->action_type;

            switch ($entry->action_type) {
                case 'clock_in':
                    $currentSessionStart = $entryTime;
                    $status = 'Working';
                    break;
                case 'clock_out':
                    if ($currentSessionStart) {
                        $totalWorkMinutes += $this->positiveMinutesBetween($currentSessionStart, $entryTime);
                        $currentSessionStart = null;
                    }
                    $status = 'Clocked Out';
                    break;
                case 'break_start':
                    $currentBreakStart = $entryTime;
                    $status = 'On Break';
                    break;
                case 'break_end':
                    if ($currentBreakStart) {
                        $totalBreakMinutes += $this->positiveMinutesBetween($currentBreakStart, $entryTime);
                        $currentBreakStart = null;
                    }
                    $status = 'Working';
                    break;
            }
        }

        $now = Carbon::now('Asia/Karachi');

        // Include a reasonable overnight session without counting stale clock-ins.
        if ($currentSessionStart) {
            $ongoingWorkMinutes = $this->positiveMinutesBetween($currentSessionStart, $now);

            if ($ongoingWorkMinutes <= 18 * 60) {
                $totalWorkMinutes += $ongoingWorkMinutes;
            }
        }

        if ($currentBreakStart) {
            $ongoingBreakMinutes = $this->positiveMinutesBetween($currentBreakStart, $now);

            if ($ongoingBreakMinutes <= 18 * 60) {
                $totalBreakMinutes += $ongoingBreakMinutes;
            }
        }

        // Calculate effective work time (excluding breaks)
        $effectiveWorkMinutes = max(0, $totalWorkMinutes - $totalBreakMinutes);

        return [
            'workHours' => round($effectiveWorkMinutes / 60, 2),
            'breakHours' => round($totalBreakMinutes / 60, 2),
            'lastAction' => $lastAction,
            'status' => $status,
        ];
    }

    private function positiveMinutesBetween(Carbon $start, Carbon $end): int
    {
        return max(0, (int) floor($start->diffInMinutes($end, false)));
    }
}
