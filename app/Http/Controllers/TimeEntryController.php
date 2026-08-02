<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\CalculatesTimeStats;
use App\Models\MonitoringSetting;
use App\Models\TimeEntry;
use App\Models\TrackingSession;
use App\Models\User;
use App\Models\WorkHour;
use App\Support\BusinessTime;
use App\Support\TimeClockRules;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TimeEntryController extends Controller
{
    use CalculatesTimeStats;

    /**
     * Hard ceiling (in calendar days, inclusive) on any caller-supplied date
     * range. Without it `?start=2000-01-01&end=2030-12-31` hydrates the whole
     * organization's punch history in one request — a memory bomb reachable by
     * every manager (audit finding high-17). Ranges longer than this are
     * CLAMPED to the most recent window rather than rejected, so a bookmarked
     * link keeps rendering instead of 422-ing.
     */
    private const MAX_RANGE_DAYS = 92;

    /**
     * How many users' punches are hydrated at a time in teamActivity().
     *
     * calculateTimeStats() walks ONE user's ordered in/out/break sequence for
     * ONE attendance day, so the rows for a given user-day must stay together —
     * chunkById() would order by id and split a sequence across chunk
     * boundaries, silently producing wrong in-office hours. Batching by USER
     * keeps every sequence intact while bounding peak memory to a slice of the
     * org instead of the whole org.
     */
    private const ACTIVITY_USER_BATCH = 25;

    /**
     * The only user columns the dashboard summary reads: the four rendered
     * fields plus the shift columns effectiveShiftFor()/attendanceDateFor()
     * resolve against. `shift_id` is required so the shift relation can be
     * eager-loaded onto the projected models.
     */
    private const SUMMARY_USER_COLUMNS = [
        'id',
        'name',
        'avatar',
        'designation',
        'shift_id',
        'shift_start_time',
        'shift_hours',
        'shift_grace_minutes',
    ];

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

        $now = Carbon::now(\App\Support\BusinessTime::tz());
        $user = Auth::user();
        $actionType = $validated['action_type'];
        $attendanceDate = $user->attendanceDateFor($now);

        // Double-start race guard. Read-latest-action → validate → insert runs
        // inside one transaction that first takes an exclusive row lock on the
        // user, so two near-simultaneous clock actions for the same person
        // (shared-PC double-tap) serialize instead of both reading a "closed"
        // state and both inserting an overlapping open session — the root of
        // the impossible/inflated hours. The second request waits on the lock,
        // then re-reads the now-committed state below and is rejected by the
        // sequence rule. Locking the user row (always present) also covers the
        // first-ever clock-in, which a lock on time_entries rows would miss.
        $lastAction = null;
        $entry = DB::transaction(function () use ($user, $now, $actionType, $attendanceDate, $validated, &$lastAction) {
            User::whereKey($user->id)->lockForUpdate()->first();

            // Enforce the sequence against the GLOBAL current clock state, not
            // just today's — otherwise a clock-in left open past midnight looks
            // "closed" to today's empty list and a duplicate clock-in slips
            // through (clocked in 7:26 PM, second clock-in at 12:40 AM).
            $lastAction = TimeEntry::currentClockState($user->id);

            if (! $this->isActionAllowed($lastAction, $actionType)) {
                return null;
            }

            return TimeEntry::create([
                'user_id' => $user->id,
                'action_type' => $actionType,
                'action_timestamp' => $now,
                'action_date' => $attendanceDate,
                'action_time' => $now->toTimeString(),
                'notes' => $validated['notes'] ?? null,
            ]);
        });

        if ($entry === null) {
            return response()->json([
                'message' => $this->blockedActionMessage($lastAction, $actionType),
            ], 422);
        }

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
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ]);

        $user = Auth::user();
        $query = TimeEntry::forUser($user->id)->with('user:id,name');

        // Filter by date range if provided. action_date is a real DATE column,
        // so plain comparisons are used instead of whereDate() — the latter
        // compiles to `date(`action_date`) >= ?`, which is non-sargable and
        // disqualifies every index on the column (audit finding high-16).
        // The upper bound is half-open (`< end + 1 day`) so it still matches
        // rows that SQLite persisted with a " 00:00:00" suffix through the
        // `date` cast, which a plain `<= end` would drop.
        if (! empty($validated['start_date'])) {
            $query->where('action_date', '>=', Carbon::parse($validated['start_date'])->toDateString());
        }
        if (! empty($validated['end_date'])) {
            $query->where('action_date', '<', Carbon::parse($validated['end_date'])->addDay()->toDateString());
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
        $today = $user->attendanceDateFor(Carbon::now(\App\Support\BusinessTime::tz()));

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
        $now = Carbon::now(\App\Support\BusinessTime::tz());
        $weekStart = Carbon::now(\App\Support\BusinessTime::tz())->startOfWeek(MonitoringSetting::weekStartDay());
        $monthStart = Carbon::now(\App\Support\BusinessTime::tz())->startOfMonth();

        if ($user->hasAnyPermission(['dashboard.view_team', 'attendance.view'])) {
            // Deactivated staff were being hydrated (as full models), shift-
            // resolved and walked three times on every poll only to be dropped
            // by the filter at the bottom. Active only, projected columns.
            $employees = User::query()->active()->get(self::SUMMARY_USER_COLUMNS);
            $ids = $employees->pluck('id')->all();
            $entriesByUser = $this->loadSummaryEntries($ids, $now, $weekStart, $monthStart);
            $liveByUser = $this->loadLiveSessions($ids);

            // Filter BEFORE computing, not after. The response only ever keeps
            // people with punches today or a live tracker session, so narrowing
            // here means shift resolution and the three calculateTimeStats()
            // passes run for the ~40 people actually on the clock instead of
            // all 300. (Superset of the final filter: someone with punches
            // earlier in the month but none today is dropped below.)
            $relevantIds = $entriesByUser->keys()->merge($liveByUser->keys())->unique()->flip();
            $employees = $employees->filter(fn (User $employee) => $relevantIds->has($employee->id))->values();
            $ids = $employees->pluck('id')->all();

            $trackedByUserDate = $this->loadTrackedHours($ids, $now);
            $activityByUser = $this->loadDayActivity($ids, $now);

            // One shift + one override query for the whole team instead of two
            // lazy queries per employee inside attendanceDateFor() (600 queries
            // on a 300-person tenant, on a poll that fires after every clock
            // action).
            $this->eagerLoadShiftContext($employees, $now);

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
     * Eager-load the shift context attendanceDateFor() resolves against.
     *
     * The window is load-bearing. effectiveShiftFor() resolves overrides purely
     * IN MEMORY off the cached relation, and attendanceDateFor() asks for THREE
     * dates: the timestamp's own day, the day AFTER (early clock-in for a shift
     * starting near midnight) and the day BEFORE (late-night spillover). A
     * constrained eager load narrower than [now - 1d, now + 1d] would make a
     * real override resolve as "no override" with no error — a night-shift
     * correctness regression, not just a performance change. Bounds are
     * half-open so they also match the "Y-m-d 00:00:00" form SQLite persists
     * through the `date` cast.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, User>  $employees
     */
    private function eagerLoadShiftContext($employees, Carbon $now): void
    {
        if ($employees->isEmpty()) {
            return;
        }

        $employees->load([
            'shift',
            'shiftOverrides' => fn ($query) => $query
                ->where('date', '>=', $now->copy()->subDay()->toDateString())
                ->where('date', '<', $now->copy()->addDays(2)->toDateString()),
        ]);
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
            // (or is still running). The never-stopped branch carries its own
            // lower bound: without it, every session a crashed desktop client
            // ever left open — years of them — is read on every dashboard poll,
            // and a session still "running" from days ago is stale anyway.
            ->where(function ($q) use ($now) {
                $q->where(fn ($open) => $open
                    ->whereNull('stopped_at')
                    ->where('started_at', '>=', $now->copy()->startOfDay()->subDay()))
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
        // Plain `>=` (not whereDate) so work_hours_org_user_date_idx is usable —
        // `date` is a real DATE column, so wrapping it in date() is pure loss.
        return \App\Models\WorkHour::query()
            ->whereIn('user_id', $userIds)
            ->where('date', '>=', $now->copy()->subDay()->toDateString())
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

        // Plain `>=` (not whereDate) keeps the predicate sargable so
        // time_entries_org_user_action_date_idx can serve the range; an
        // explicit column list stops the whole org's punch rows being hydrated
        // as full models with notes/slack_thread_ts payload (finding high-16).
        //
        // toBase() drops the Eloquent hydration entirely — a month of a
        // 300-person tenant is ~72k rows, and as models that is where this
        // endpoint's memory went. The business-tz instant is parsed ONCE here
        // instead of up to three times per punch downstream (the today, week
        // and month slices overlap almost completely).
        $tz = BusinessTime::tz();

        return TimeEntry::query()
            ->whereIn('user_id', $userIds)
            ->where('action_date', '>=', $rangeStart)
            ->orderBy('action_timestamp', 'asc')
            ->orderBy('id', 'asc')
            ->toBase()
            ->get(['id', 'user_id', 'action_type', 'action_timestamp', 'action_date'])
            ->each(function ($entry) use ($tz) {
                $entry->local_time = Carbon::parse($entry->action_timestamp)->setTimezone($tz);
            })
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

        // ONE pass over the punches instead of three filter() passes that each
        // re-derived the day key for every row. The slices overlap heavily (the
        // monthly one is nearly the whole set), so this removes roughly two
        // thirds of the per-request key derivation with no behaviour change —
        // the input is already chronological, so each slice stays ordered.
        $todayEntries = [];
        $weeklyEntries = [];
        $monthlyEntries = [];

        foreach ($entries as $entry) {
            $dayKey = $this->dateKey($entry->action_date);

            if ($dayKey === $today) {
                $todayEntries[] = $entry;
            }
            if ($dayKey >= $weekStartDate) {
                $weeklyEntries[] = $entry;
            }
            if ($dayKey >= $monthStartDate) {
                $monthlyEntries[] = $entry;
            }
        }

        $todayEntries = collect($todayEntries);
        $weeklyEntries = collect($weeklyEntries);
        $monthlyEntries = collect($monthlyEntries);

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
            // Projected rows, so the Eloquent accessor is formatted inline off
            // the timestamp parsed once in loadSummaryEntries() — same output.
            'last_action_time' => $todayEntries->last()?->local_time->format('g:i A'),
            'shift_start_time' => $employee->shift_start_time?->format('H:i'),
        ];
    }

    // calculateTimeStats() + positiveMinutesBetween() now live in the shared
    // App\Http\Controllers\Concerns\CalculatesTimeStats trait (used by this
    // controller's summary, trend and team-activity methods). The trait is
    // tz-correct via BusinessTime::tz(), so every tenant buckets its own day.

    /**
     * Daily worked-hours trend for the dashboard's "Worked hours" TrendChart.
     *
     * Returns a dense series of the last 14 business days (one point per day,
     * zero-filled), each carrying the total NET worked hours (clock-in→out minus
     * breaks) for the whole organization — or just the signed-in user when they
     * can't view the team. It aggregates the existing time_entries only; no new
     * tables. Team viewers get the org total, everyone else gets their own.
     *
     * Multi-tenant: TimeEntry carries the OrganizationScope global scope, so the
     * query is already restricted to the active tenant — no organization_id is
     * hardcoded here. tz-correct: action_date is written as the worker's business
     * attendance day (see store()/attendanceDateFor), so grouping by it yields
     * business-timezone day buckets with no extra conversion.
     *
     * Response shape:
     *   {
     *     "range": "last_14_days",
     *     "timezone": "Asia/Karachi",
     *     "series": [ { "date": "2026-07-10", "label": "Jul 10", "hours": 6.5 }, ... ]  // 14 items, oldest→newest
     *   }
     */
    public function dashboardTrend(Request $request)
    {
        $user = Auth::user();
        $canViewTeam = $user->hasAnyPermission(['dashboard.view_team', 'attendance.view']);

        $tz = BusinessTime::tz();
        $today = BusinessTime::today();          // start of today, business tz
        $days = 14;
        $startDate = $today->copy()->subDays($days - 1);

        // Pre-build the dense, ordered series (oldest → newest) with zeroes so a
        // day with no activity still shows as a 0h point in the chart.
        $series = [];
        $index = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $startDate->copy()->addDays($i);
            $key = $d->toDateString();
            $series[] = ['date' => $key, 'label' => $d->format('M j'), 'hours' => 0.0];
            $index[$key] = $i;
        }

        // Half-open range on the raw DATE column instead of whereDate(): the
        // latter compiles to `date(`action_date`) >= ?`, which is non-sargable,
        // so with no user_id filter (team viewers) MySQL had to read every
        // punch the tenant has ever written just to draw a 14-day chart. Paired
        // with the (organization_id, action_date) composite this becomes a
        // 14-day range scan inside one tenant's slice. The exclusive upper
        // bound also matches rows SQLite stored with a " 00:00:00" suffix.
        $query = TimeEntry::query()
            ->where('action_date', '>=', $startDate->toDateString())
            ->where('action_date', '<', $today->copy()->addDay()->toDateString())
            ->orderBy('action_timestamp', 'asc')
            ->orderBy('id', 'asc');

        if (! $canViewTeam) {
            $query->where('user_id', $user->id);
        }

        // Not hydrated as models, and no Carbon per punch: this endpoint walks
        // 14 days of the WHOLE organization on the same 45s dashboard poll, and
        // the ONLY thing it does with a punch's instant is subtract it from
        // another one. An epoch second is enough for that, and strtotime() is
        // roughly a fifth the cost of Carbon::parse()->setTimezone(). Nothing
        // downstream formats these rows, so no zone is involved — see
        // CalculatesTimeStats::entryTimestamp().
        $entries = $query
            ->toBase()
            ->get(['id', 'user_id', 'action_type', 'action_timestamp', 'action_date'])
            ->each(function ($entry) {
                $entry->local_ts = strtotime((string) $entry->action_timestamp);
            });

        // Worked hours are computed per USER per DAY: calculateTimeStats() walks a
        // single person's in/out/break sequence, so users must not be mixed. Group
        // by attendance day, then by user, run the shared trait, and sum each
        // person's net worked hours into that day's bucket.
        $entries
            ->groupBy(fn ($e) => $this->dateKey($e->action_date))
            ->each(function ($dayEntries, $dateKey) use (&$series, $index) {
                if (! array_key_exists($dateKey, $index)) {
                    return;
                }
                $dayTotal = $dayEntries
                    ->groupBy('user_id')
                    ->sum(fn ($userEntries) => $this->calculateTimeStats($userEntries->values())['workHours']);
                $series[$index[$dateKey]]['hours'] = round((float) $dayTotal, 2);
            });

        return response()->json([
            'range' => 'last_14_days',
            'timezone' => $tz,
            'series' => $series,
        ]);
    }

    /**
     * Ranged per-member activity feed for the dashboard's "Team Activity" panel,
     * so a manager can flip the list to Today / Yesterday / This week / This month
     * / a custom range. Tracked time, in-office time, activity % and days worked
     * per member over the window. Team-only (managers/attendance viewers).
     *
     * Adapted for the target data model (documented divergences from the mature
     * source, whose exact response shape this otherwise keeps):
     *  - tracked_seconds comes from the work_hours table (which already holds BOTH
     *    tracker-mirrored rows — split per calendar day by
     *    TrackingSessionService::syncWorkHour — AND manual entries), so it is the
     *    tracker + manual total the source derived from sessions + manual rows.
     *  - in_office_seconds is the clock-based NET worked hours (via the shared
     *    CalculatesTimeStats trait) per attendance day. The target has no
     *    gross-presence helper (AttendanceHours), so this reuses the same
     *    calculator the dashboard's "Today" column uses.
     *  - activity_percent weights each overlapping session's activity_percent by
     *    its whole total_seconds (the target's TrackingSessionService has no
     *    inDaySeconds proportional split — same approach as loadDayActivity()).
     *  - shift_name is null (the target User has no named-shift relationship);
     *    the key is kept for response-shape parity.
     *
     * Multi-tenant: User / WorkHour / TimeEntry / TrackingSession are all
     * org-scoped via OrganizationScope, so every query is restricted to the
     * active tenant with no explicit organization_id. tz-correct via BusinessTime.
     *
     * Response shape:
     *   {
     *     "range": "today",
     *     "start": "2026-07-22",
     *     "end": "2026-07-22",
     *     "employees": [ {
     *       "user_id", "user_name", "designation", "shift_name", "avatar",
     *       "tracked_seconds", "in_office_seconds", "activity_percent",
     *       "days_worked", "is_live"
     *     }, ... ]
     *   }
     */
    public function teamActivity(Request $request)
    {
        $user = Auth::user();
        abort_unless($user->hasAnyPermission(['dashboard.view_team', 'attendance.view']), 403);

        // Reject garbage before it reaches Carbon (the old code parsed anything
        // strtotime accepted and never noticed an inverted range, which produced
        // an empty punch set but a nonsense session-overlap predicate).
        // `date_format:Y-m-d` rather than `date`: the latter accepts anything
        // strtotime does, including relative strings like "+5 years", which is
        // an unnecessary parsing surface on a caller-supplied range. The span
        // itself is clamped (not rejected) in clampRange(), so a bookmarked
        // link keeps rendering.
        $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d'],
            'range' => ['nullable', Rule::in(['today', 'yesterday', 'week', 'month', 'custom'])],
        ]);

        [$rangeStart, $rangeEnd] = $this->resolveActivityRange($request);
        [$dayStart, $dayEnd] = BusinessTime::utcRange($rangeStart, $rangeEnd);

        // Half-open bounds on the raw DATE columns: sargable (so the new
        // organization-leading composites are usable) AND correct on SQLite,
        // where a `date` cast can persist "Y-m-d 00:00:00" that an inclusive
        // `<= end` string comparison would drop.
        $startDate = $rangeStart->toDateString();
        $endExclusive = $rangeEnd->copy()->startOfDay()->addDay()->toDateString();

        // Org-scoped automatically (OrganizationScope on User).
        $users = User::query()->orderBy('name')->get(['id', 'name', 'designation', 'avatar']);
        $ids = $users->pluck('id')->all();

        // Tracked / work-diary hours per user over the range (tracker + manual).
        // Summed in SQL — one row per user instead of every work_hours row in
        // the window hydrated as a model (finding high-17).
        $trackedHoursByUser = WorkHour::query()
            ->whereIn('user_id', $ids)
            ->where('date', '>=', $startDate)
            ->where('date', '<', $endExclusive)
            ->selectRaw('user_id, SUM(hours) as total_hours')
            ->groupBy('user_id')
            ->pluck('total_hours', 'user_id');

        // Sessions overlapping the range: the activity weighting at the bottom
        // of this method is SUM(activity_percent * total_seconds) / SUM(
        // total_seconds), which is expressible in SQL exactly (both columns are
        // integers), so this collapses to one row per user too.
        // The never-stopped branch gets an explicit lower bound too: without it
        // a tenant's entire history of sessions abandoned by crashed desktop
        // clients is read on every call, however short the requested range.
        $sessionAggByUser = TrackingSession::query()
            ->whereIn('user_id', $ids)
            ->where('total_seconds', '>', 0)
            ->where('started_at', '<', $dayEnd)
            ->where(fn ($q) => $q
                ->where(fn ($open) => $open
                    ->whereNull('stopped_at')
                    ->where('started_at', '>=', $dayStart->copy()->subDay()))
                ->orWhere('stopped_at', '>', $dayStart))
            ->selectRaw('user_id, SUM(total_seconds) as total_seconds, SUM(activity_percent * total_seconds) as weighted_activity')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        // Clock punches still have to be hydrated — calculateTimeStats() is a
        // per-user-per-day state machine over the ordered in/out/break sequence
        // and cannot be expressed as a GROUP BY. What IS bounded is how many
        // users' sequences are in memory at once: batching by user keeps each
        // user-day sequence intact (which chunkById would break) while capping
        // peak memory at a slice of the org instead of the whole org.
        [$inOfficeByUser, $daysWorkedByUser] = $this->inOfficeSecondsByUser($ids, $startDate, $endExclusive);

        $liveIds = TrackingSession::query()->active()->pluck('user_id')->flip();

        $rows = $users->map(function (User $u) use ($trackedHoursByUser, $inOfficeByUser, $daysWorkedByUser, $sessionAggByUser, $liveIds) {
            $trackedSeconds = (int) round((float) ($trackedHoursByUser[$u->id] ?? 0) * 3600);

            $agg = $sessionAggByUser->get($u->id);
            $tot = (int) ($agg->total_seconds ?? 0);
            $activity = $tot > 0
                ? (int) round((float) $agg->weighted_activity / $tot)
                : 0;

            return [
                'user_id' => $u->id,
                'user_name' => $u->name,
                'designation' => $u->designation ?? 'Employee',
                'shift_name' => null,
                'avatar' => $u->avatar_url ?? null,
                'tracked_seconds' => $trackedSeconds,
                'in_office_seconds' => (int) ($inOfficeByUser[$u->id] ?? 0),
                'activity_percent' => $activity,
                'days_worked' => (int) ($daysWorkedByUser[$u->id] ?? 0),
                'is_live' => $liveIds->has($u->id),
            ];
        })
            ->filter(fn ($r) => $r['tracked_seconds'] > 0 || $r['in_office_seconds'] > 0 || $r['is_live'])
            ->sortBy([['is_live', 'desc'], ['tracked_seconds', 'desc'], ['user_name', 'asc']])
            ->values();

        return response()->json([
            'range' => $request->input('range', 'today'),
            'start' => $rangeStart->toDateString(),
            'end' => $rangeEnd->toDateString(),
            'employees' => $rows,
        ]);
    }

    /**
     * Net in-office seconds and days-worked per user over a business-day range.
     *
     * Returns [inOfficeSecondsByUserId, daysWorkedByUserId].
     *
     * Users are processed in batches so the punch rows for at most
     * self::ACTIVITY_USER_BATCH people are resident at once. Batching by USER
     * (rather than chunkById, which orders by id) is load-bearing: a single
     * user-day's in/out/break sequence must never be split, or the pairing in
     * calculateTimeStats() silently produces wrong hours instead of an error.
     *
     * @param  array<int>  $userIds
     * @param  string  $startDate  inclusive Y-m-d lower bound
     * @param  string  $endExclusive  exclusive Y-m-d upper bound (end + 1 day)
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private function inOfficeSecondsByUser(array $userIds, string $startDate, string $endExclusive): array
    {
        $inOffice = [];
        $daysWorked = [];

        foreach (array_chunk($userIds, self::ACTIVITY_USER_BATCH) as $batch) {
            TimeEntry::query()
                ->whereIn('user_id', $batch)
                ->where('action_date', '>=', $startDate)
                ->where('action_date', '<', $endExclusive)
                ->orderBy('action_timestamp')->orderBy('id')
                ->toBase()
                ->get(['id', 'user_id', 'action_type', 'action_timestamp', 'action_date'])
                // Epoch seconds, not Carbon: this roll-up only ever subtracts
                // punches from one another and never formats one.
                ->each(function ($entry) {
                    $entry->local_ts = strtotime((string) $entry->action_timestamp);
                })
                ->groupBy('user_id')
                ->each(function ($userEntries, $userId) use (&$inOffice, &$daysWorked) {
                    $byDay = $userEntries->groupBy(fn ($e) => $this->dateKey($e->action_date));

                    $inOffice[$userId] = (int) round(
                        $byDay->sum(fn ($dayEntries) => $this->calculateTimeStats($dayEntries->values())['workHours']) * 3600
                    );
                    $daysWorked[$userId] = $byDay->filter(fn ($d) => $d->isNotEmpty())->count();
                });
        }

        return [$inOffice, $daysWorked];
    }

    /**
     * Resolve the team-activity range: a custom start+end, else a named preset
     * (today / yesterday / week / month), all in the business timezone. Week start
     * follows the same MonitoringSetting::weekStartDay() the summary uses.
     *
     * The custom branch is CLAMPED to self::MAX_RANGE_DAYS and self-corrects an
     * inverted range, so no caller can ask this endpoint to walk the tenant's
     * whole history in one request (audit finding high-17).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveActivityRange(Request $request): array
    {
        $tz = BusinessTime::tz();

        if ($request->filled('start') && $request->filled('end')) {
            try {
                return $this->clampRange(
                    Carbon::parse($request->input('start'), $tz),
                    Carbon::parse($request->input('end'), $tz)
                );
            } catch (\Throwable $e) {
                // fall through to named ranges
            }
        }

        $today = BusinessTime::today();

        return match ($request->input('range', 'today')) {
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()->endOfDay()],
            'week' => [$today->copy()->startOfWeek(MonitoringSetting::weekStartDay()), $today->copy()->endOfDay()],
            'month' => [$today->copy()->startOfMonth(), $today->copy()->endOfDay()],
            default => [$today->copy(), $today->copy()->endOfDay()],
        };
    }

    /**
     * Normalise a caller-supplied range: swap an inverted pair, snap to whole
     * business days, and clamp the span to self::MAX_RANGE_DAYS (keeping the
     * most recent window) so an unbounded `?start=2000-01-01&end=2030-12-31`
     * cannot be used to hydrate the tenant's entire history.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function clampRange(Carbon $start, Carbon $end): array
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->startOfDay();

        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }

        $spanDays = (int) $start->diffInDays($end) + 1;

        if ($spanDays > self::MAX_RANGE_DAYS) {
            $start = $end->copy()->subDays(self::MAX_RANGE_DAYS - 1);
        }

        return [$start, $end->endOfDay()];
    }

    /** Business-day bucket key (Y-m-d) for a TimeEntry's action_date (date cast or raw string). */
    private function dateKey($actionDate): string
    {
        return $actionDate instanceof Carbon
            ? $actionDate->toDateString()
            : substr((string) $actionDate, 0, 10);
    }
}
