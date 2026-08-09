<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\CalculatesTimeStats;
use App\Models\ManualAttendanceAudit;
use App\Models\ManualAttendanceMark;
use App\Models\MonitoringSetting;
use App\Models\TimeEntry;
use App\Models\TrackingAuditLog;
use App\Models\User;
use App\Services\AttendanceSlackReportService;
use App\Support\BusinessTime;
use App\Support\CsvExport;
use App\Support\TimeClockRules;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;

class EmployeeAttendanceController extends Controller
{
    // The state machine that derives work/break hours from a chronological
    // punch sequence used to be duplicated verbatim in this controller. It now
    // comes from the shared trait the dashboard endpoints already use, so the
    // two surfaces cannot drift and both get the pre-parsed-timestamp fast
    // path (see entryLocalTime()).
    use CalculatesTimeStats;

    private const ATTENDANCE_STATUSES = [
        'P' => 'Present',
        'A' => 'Absent',
        'H' => 'Holiday',
        'L' => 'Leave',
        'HD' => 'Half day',
        'WFH' => 'Work from home',
        'LC' => 'Late coming',
        'LI' => 'Late joining',
        'PH' => 'Public holiday',
    ];

    /**
     * Bounds for the bulk calendar writer. The endpoint used to be unbounded on
     * the people dimension (only the 366-day span was capped), which made a
     * single admin click able to issue six figures of queries inside one
     * transaction. These three constants keep any one request to a handful of
     * bulk statements over at most CALENDAR_MAX_PERSON_DAYS rows.
     */
    private const CALENDAR_MAX_SELECTED_USERS = 200;

    private const CALENDAR_MAX_PERSON_DAYS = 5000;

    private const CALENDAR_WRITE_CHUNK = 500;

    /**
     * Display the employee attendance page
     */
    public function index()
    {
        return Inertia::render('EmployeeAttendance', [
            'serverDate' => Carbon::today(\App\Support\BusinessTime::tz())->toDateString(),
            'canManuallyMarkAttendance' => $this->canManuallyMarkAttendance(request()->user()),
            'canEditClockTimes' => $this->canEditClockTimes(request()->user()),
            'canSendAttendanceSlack' => request()->user()?->hasPermission('reports.send_slack') ?? false,
            'slackConfigured' => app(AttendanceSlackReportService::class)->configured(),
            // FIRST-RUN vs FILTERED-OUT. The tabs below load by AJAX and can
            // only see one day/month at a time, so without this they cannot tell
            // "this workspace has never clocked in" from "nobody worked last
            // Sunday" — and both were being reported to the customer as a filter
            // mistake. Same shape and same cost as the report screen's
            // `hasAnyEntries`: ONE EXISTS, served by the leading organization_id
            // of the composite index, never a COUNT over the punch table.
            'hasAnyAttendance' => TimeEntry::query()->exists(),
        ]);
    }

    /**
     * The day's punches for one employee, plus the H:i values that prefill the
     * "Edit clock times" dialog. Times are rendered in the ORGANIZATION's
     * business timezone (BusinessTime::tz()), which is the same clock every
     * other attendance surface uses, so an admin always edits the wall-clock the
     * team actually reads. Gated by attendance.edit_times.
     */
    public function getDayEntries(Request $request)
    {
        abort_unless($this->canEditClockTimes($request->user()), 403);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        // findOrFail (not find) so a user id from another tenant — invisible
        // through the OrganizationScope — 404s instead of leaking a blank day.
        $subject = User::findOrFail($validated['user_id']);

        $entries = TimeEntry::forUser($subject->id)
            ->forDate($validated['date'])
            ->orderBy('action_timestamp')->orderBy('id')
            ->get();

        $tz = BusinessTime::tz();
        $fmt = fn (?TimeEntry $entry) => $entry
            ? Carbon::parse($entry->action_timestamp)->setTimezone($tz)->format('H:i')
            : null;

        // The GLOBAL clock state (not just this day's) decides whether the
        // "clock them out" action is offered — someone who clocked in last night
        // and never clocked out is still running today.
        $currentState = TimeEntry::currentClockState($subject->id);

        return response()->json([
            'user_id' => $subject->id,
            'user_name' => $subject->name,
            'date' => $validated['date'],
            'clock_in' => $fmt($entries->firstWhere('action_type', 'clock_in')),
            'clock_out' => $fmt($entries->where('action_type', 'clock_out')->last()),
            'break_start' => $fmt($entries->firstWhere('action_type', 'break_start')),
            'break_end' => $fmt($entries->where('action_type', 'break_end')->last()),
            'timezone' => $tz,
            // Only labelled when the workspace runs on a non-default zone, so
            // the dialog stays quiet for the common case.
            'timezone_label' => $tz !== config('app.timezone', 'UTC')
                ? str_replace('_', ' ', $tz)
                : '',
            // Every punch on the day, for the drill-in list.
            'entries' => $entries->map(fn (TimeEntry $entry) => [
                'id' => $entry->id,
                'action_type' => $entry->action_type,
                'time' => Carbon::parse($entry->action_timestamp)->setTimezone($tz)->format('g:i A'),
                'notes' => $entry->notes,
            ])->values(),
            'current_state' => $currentState,
            // Still on the clock: clock_in, an open break, or working again
            // after a break. Mirrors the open set adminClockOut() accepts.
            'is_clocked_in' => in_array($currentState, ['clock_in', 'break_start', 'break_end'], true),
        ]);
    }

    /**
     * Set/correct an employee's clock-in, clock-out and break times for a day
     * (e.g. they forgot to clock in). Replaces that day's TimeEntry rows with a
     * single legal clock_in → [break_start → break_end] → clock_out sequence,
     * audits the change, and marks the rows 'Admin clock edit' so the attendance
     * Slack notifier skips them. Gated by attendance.edit_times.
     *
     * Every in-office hour in the app derives live from these rows, so all the
     * dependent numbers update automatically.
     */
    public function updateClockTimes(Request $request)
    {
        $actor = $request->user();
        abort_unless($this->canEditClockTimes($actor), 403);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'clock_in' => ['nullable', 'date_format:H:i'],
            'clock_out' => ['nullable', 'date_format:H:i'],
            'break_start' => ['nullable', 'date_format:H:i'],
            'break_end' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $target = User::findOrFail($validated['user_id']);

        // Parse the entered wall-clock in the org's business timezone (the same
        // zone the dialog displays), so action_date then buckets to the org's
        // attendance day. Carbon::parse (not createFromFormat) is used so the
        // timezone is applied to the wall-clock rather than ignored.
        $tz = BusinessTime::tz();
        $date = $validated['date'];
        $mk = fn (string $hi) => Carbon::parse("{$date} {$hi}", $tz);

        // Provided actions in canonical clock order. Break/clock-out values that
        // land chronologically at or before the clock-in are pushed to the next
        // day so an overnight shift stays monotonic.
        $order = ['clock_in', 'break_start', 'break_end', 'clock_out'];
        $provided = array_values(array_filter($order, fn ($action) => ! empty($validated[$action])));

        if (empty($provided)) {
            throw ValidationException::withMessages(['clock_in' => 'Enter at least a clock-in time.']);
        }

        $timestamps = [];
        $previous = null;
        foreach ($provided as $action) {
            $ts = $mk($validated[$action]);
            if ($previous) {
                while ($ts->lessThanOrEqualTo($previous)) {
                    $ts->addDay();
                }
            }
            $timestamps[$action] = $ts;
            $previous = $ts;
        }

        // The sequence must be legal (must start with clock_in, breaks pair up…).
        $last = null;
        foreach ($provided as $action) {
            if (! TimeClockRules::isAllowed($last, $action)) {
                throw ValidationException::withMessages([
                    $action => TimeClockRules::blockedMessage($last, $action),
                ]);
            }
            $last = $action;
        }

        // Every timestamp must bucket to the edited day for THIS user's shift —
        // honouring the shift precedence (one-day override > named shift >
        // shift_start_time) through attendanceDateFor(). Without this an edit can
        // silently file a punch on a neighbouring attendance day, the exact
        // mis-filing bug this app has already been bitten by.
        foreach ($timestamps as $action => $ts) {
            if ($target->attendanceDateFor($ts) !== $date) {
                throw ValidationException::withMessages([
                    $action => "That time falls on a different attendance day for this employee's shift. Please check the date and time.",
                ]);
            }
        }

        // Capture the old times for the audit before replacing the day.
        $oldEntries = TimeEntry::forUser($target->id)->forDate($date)
            ->orderBy('action_timestamp')->orderBy('id')->get();
        $fmtOld = fn (?TimeEntry $entry) => $entry
            ? Carbon::parse($entry->action_timestamp)->setTimezone($tz)->format('H:i')
            : null;
        $oldValue = [
            'clock_in' => $fmtOld($oldEntries->firstWhere('action_type', 'clock_in')),
            'clock_out' => $fmtOld($oldEntries->where('action_type', 'clock_out')->last()),
            'break_start' => $fmtOld($oldEntries->firstWhere('action_type', 'break_start')),
            'break_end' => $fmtOld($oldEntries->where('action_type', 'break_end')->last()),
        ];

        $firstTs = $timestamps[$provided[0]];
        $lastTs = $timestamps[$provided[count($provided) - 1]];
        $leavesOpen = ! in_array('clock_out', $provided, true);

        // Same exclusive user-row lock the live clock takes (TimeEntryController
        // @store), so an admin edit can never interleave with a worker clocking
        // in and produce two overlapping open sessions.
        DB::transaction(function () use ($target, $date, $timestamps, $provided, $firstTs, $lastTs, $leavesOpen) {
            User::whereKey($target->id)->lockForUpdate()->first();

            // Double-clock guard, part 1: whatever the user's last punch OUTSIDE
            // this day before the edit window is, it must leave them clocked out —
            // otherwise this day's clock_in would be a second open session.
            $before = TimeEntry::forUser($target->id)
                ->whereDate('action_date', '!=', $date)
                ->where('action_timestamp', '<', $firstTs)
                ->orderByDesc('action_timestamp')->orderByDesc('id')
                ->first();

            if ($before && $before->action_type !== 'clock_out') {
                throw ValidationException::withMessages([
                    'clock_in' => 'This employee has an earlier session that was never clocked out. Close that day first, then edit this one.',
                ]);
            }

            // Double-clock guard, part 2: an edit that leaves the day OPEN (no
            // clock-out) may not be followed by any later punch — that would
            // strand an open session behind newer activity.
            if ($leavesOpen) {
                $after = TimeEntry::forUser($target->id)
                    ->whereDate('action_date', '!=', $date)
                    ->where('action_timestamp', '>', $lastTs)
                    ->exists();

                if ($after) {
                    throw ValidationException::withMessages([
                        'clock_out' => 'Add a clock-out time — leaving this day open would overlap later activity for this employee.',
                    ]);
                }
            }

            // Replace-the-day: simpler than patching individual rows and it
            // guarantees a legal sequence. The 'Admin clock edit' note is what
            // keeps these back-dated writes out of the attendance Slack thread.
            TimeEntry::forUser($target->id)->forDate($date)->delete();

            foreach ($provided as $action) {
                // Store the canonical instant, with action_time in the business
                // timezone so it round-trips and action_time == TIME(action_timestamp).
                $ts = $timestamps[$action]->copy()->setTimezone(BusinessTime::tz());
                TimeEntry::create([
                    'user_id' => $target->id,
                    'action_type' => $action,
                    'action_timestamp' => $ts,
                    'action_date' => $date,
                    'action_time' => $ts->toTimeString(),
                    'notes' => 'Admin clock edit',
                ]);
            }
        });

        TrackingAuditLog::record([
            'subject_user_id' => $target->id,
            'actor_user_id' => $actor->id,
            'action' => 'attendance.clock_edit',
            'event_date' => $date,
            'old_value' => $oldValue,
            'new_value' => [
                'clock_in' => $validated['clock_in'] ?? null,
                'clock_out' => $validated['clock_out'] ?? null,
                'break_start' => $validated['break_start'] ?? null,
                'break_end' => $validated['break_end'] ?? null,
            ],
            'reason' => $validated['reason'],
        ]);

        return response()->json(['message' => 'Clock times updated.']);
    }

    /**
     * Clock out a worker who forgot to (gated by attendance.edit_times). Writes a
     * real clock_out — defaulting to the worker's shift end so their hours stay
     * accurate instead of inflating to "now" — and closes an open break first.
     * The action is audited; the 'Admin clock-out' note suppresses the Slack
     * clock-out post (an admin did this, the worker didn't clock out live).
     */
    public function adminClockOut(Request $request)
    {
        $actor = $request->user();
        abort_unless($this->canEditClockTimes($actor), 403);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'time' => ['nullable', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $target = User::findOrFail($validated['user_id']);
        $tz = BusinessTime::tz();
        $note = trim((string) ($validated['note'] ?? ''));

        // Everything — the open-session read, the clamping and the writes — runs
        // under the same exclusive user-row lock the live clock uses, so an admin
        // close can never race a worker's own clock action.
        $result = DB::transaction(function () use ($target, $validated, $tz, $note) {
            User::whereKey($target->id)->lockForUpdate()->first();

            // Open = the latest action keeps them on the clock (clock_in,
            // break_start, or break_end — working again after a break). Read from
            // the global clock state so an overnight session still counts.
            $last = TimeEntry::forUser($target->id)
                ->orderByDesc('action_timestamp')->orderByDesc('id')
                ->first();

            if (! $last || ! in_array($last->action_type, ['clock_in', 'break_start', 'break_end'], true)) {
                throw ValidationException::withMessages([
                    'user_id' => $target->name.' is not currently clocked in.',
                ]);
            }

            // Reuse $last when it already IS the clock-in; only re-query when the
            // open state is a break (then the clock-in is an earlier row).
            $openClockIn = $last->action_type === 'clock_in'
                ? $last
                : TimeEntry::forUser($target->id)->where('action_type', 'clock_in')
                    ->orderByDesc('action_timestamp')->orderByDesc('id')->first();

            if (! $openClockIn) {
                throw ValidationException::withMessages([
                    'user_id' => 'Could not find an open clock-in for '.$target->name.'.',
                ]);
            }

            // The open clock-in's attendance day anchors the close, so an
            // overnight session closes on the SAME attendance day it started —
            // never split across two days by the calendar rolling over.
            $openDate = $openClockIn->action_date instanceof Carbon
                ? $openClockIn->action_date->toDateString()
                : (string) $openClockIn->action_date;
            $openTs = Carbon::parse($openClockIn->action_timestamp)->setTimezone($tz);

            // Shift precedence (one-day override > named shift > the user's own
            // shift_start_time) comes from effectiveShiftFor().
            $shift = $target->effectiveShiftFor($openDate);
            $defaultHours = (float) config('services.attendance.prompt_after_hours', 8);
            $shiftHours = $shift['hours'] ?? $defaultHours;
            $defaultClose = $shift['start_time']
                ? Carbon::parse($openDate.' '.$shift['start_time']->format('H:i:s'), $tz)
                    ->addMinutes((int) round($shiftHours * 60))
                : Carbon::now($tz);

            if (! empty($validated['time'])) {
                $closeAt = Carbon::parse($openDate.' '.$validated['time'], $tz);
                // A time at/before the clock-in belongs to the next calendar day
                // (overnight shift).
                if ($closeAt->lessThanOrEqualTo($openTs)) {
                    $closeAt->addDay();
                }
            } else {
                $closeAt = $defaultClose;
            }

            // Never write the clock-out before the worker's latest action (e.g. a
            // break taken after the shift end) — otherwise that action stays the
            // newest row and the session would still read as open.
            $lastTs = Carbon::parse($last->action_timestamp)->setTimezone($tz);
            if ($closeAt->lessThanOrEqualTo($lastTs)) {
                $closeAt = $lastTs->copy()->addMinute();
            }

            $closeAt = $closeAt->copy()->setTimezone($tz);
            $reason = 'Admin clock-out'.($note !== '' ? ': '.$note : ' (forgot to clock out)');

            // An open break has to be ended before the clock-out, or the sequence
            // would be illegal. Filed on the clock-in's attendance day, like the
            // clock-out itself.
            if ($last->action_type === 'break_start') {
                TimeEntry::create([
                    'user_id' => $target->id,
                    'action_type' => 'break_end',
                    'action_timestamp' => $closeAt,
                    'action_date' => $openDate,
                    'action_time' => $closeAt->toTimeString(),
                    'notes' => 'Auto break-end (system close).',
                ]);
            }

            $clockOut = TimeEntry::create([
                'user_id' => $target->id,
                'action_type' => 'clock_out',
                'action_timestamp' => $closeAt,
                'action_date' => $openDate,
                'action_time' => $closeAt->toTimeString(),
                'notes' => $reason,
            ]);

            return ['clock_out' => $clockOut, 'open_date' => $openDate];
        });

        $closedAtLocal = Carbon::parse($result['clock_out']->action_timestamp)->setTimezone($tz);

        TrackingAuditLog::record([
            'subject_user_id' => $target->id,
            'actor_user_id' => $actor->id,
            'action' => 'attendance.admin_clock_out',
            'event_date' => $result['open_date'],
            'old_value' => ['clock_out' => null],
            'new_value' => ['clock_out' => $closedAtLocal->format('H:i')],
            'reason' => $note !== '' ? $note : 'forgot to clock out',
        ]);

        return response()->json([
            'message' => 'Clocked out '.$target->name.' at '.$closedAtLocal->format('g:i A').'.',
        ]);
    }

    public function getMonthlyGrid(Request $request)
    {
        $validated = $request->validate([
            'range' => ['nullable', Rule::in(['week', 'month'])],
            'month' => ['nullable', 'date_format:Y-m'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $tz = BusinessTime::tz();

        // DEFAULT to a WEEKLY window. A week is 7 columns instead of 28-31, so
        // the payload scales with the window the viewer is actually looking at
        // rather than the whole calendar — the biggest single payload in the app
        // (~770 KB on a large tenant) drops ~4x, and the page fits a phone.
        //
        // Backwards-compatible fallback: a caller that passes only `month` (every
        // pre-existing bookmark, the Slack/report internals, and the page's own
        // "Month" toggle) still gets the full month, so nothing that relied on the
        // old contract changes. An explicit `range` always wins.
        $range = $validated['range'] ?? (! empty($validated['month']) ? 'month' : 'week');

        if ($range === 'month') {
            $month = $validated['month'] ?? Carbon::today($tz)->format('Y-m');
            $startDate = Carbon::createFromFormat('Y-m-d', "{$month}-01", $tz)->startOfDay();
            $lastDay = $startDate->copy()->endOfMonth()->startOfDay();
        } else {
            // A Monday..Sunday week around the anchored day (defaults to today).
            // Monday start keeps the Sunday weekly-off at the END of the row, which
            // reads naturally on both the desktop grid and the phone card strip.
            $anchor = ! empty($validated['date'])
                ? Carbon::createFromFormat('Y-m-d', $validated['date'], $tz)->startOfDay()
                : Carbon::today($tz);
            $startDate = $anchor->copy()->startOfWeek(Carbon::MONDAY);
            $lastDay = $startDate->copy()->addDays(6);
        }

        $now = Carbon::now($tz);
        $today = $now->copy()->startOfDay();

        // Half-open bounds on the raw DATE columns. whereBetween with a bare
        // 'Y-m-d' upper bound is both non-sargable-adjacent and WRONG on
        // SQLite, where the `date` cast persists "Y-m-d 00:00:00" — the last
        // day of the window sorts after the bound and its punches/marks were
        // silently dropped from the grid. `>= first` / `< last + 1 day`
        // matches both textual forms and lets the (organization_id,
        // action_date) composite range-scan one tenant's slice.
        $rangeStart = $startDate->toDateString();
        $rangeEndExclusive = $lastDay->copy()->addDay()->toDateString();

        // One row per day in the visible window (7 for a week, 28-31 for a month).
        // Same per-day shape the grid has always rendered.
        $days = collect(CarbonPeriod::create($startDate, $lastDay))->map(function (Carbon $date) {
            return [
                'date' => $date->toDateString(),
                'day' => $date->day,
                'weekday' => $date->format('D'),
                'is_weekend' => $date->isSunday(),
            ];
        })->values();

        // Parsed once per day here rather than once per employee-day cell —
        // with a full team this loop body otherwise runs ~700 times a month.
        $carbonDays = $days->mapWithKeys(fn (array $day) => [
            $day['date'] => Carbon::parse($day['date'], $tz),
        ]);

        // Per-org attendance policy (humane defaults — see resolveMonthlyStatus).
        $attConfig = $this->attendancePolicy();

        // Everything about a DAY that resolveMonthlyStatus() used to re-derive
        // inside every employee-day cell: whether it is in the future, whether
        // it is today, whether it falls before the workspace started tracking,
        // whether it is a public holiday or a weekly off. Those are properties
        // of the date alone — identical for all 120 employees — but were costing
        // a Carbon::now() (isFuture), two isSameDay() and up to three
        // toDateString() calls PER CELL. On a 110-person month that is ~3,400
        // cells doing the same 31 days' worth of work over and over.
        $trackingStart = $attConfig['tracking_start'] ?? null;
        $holidays = $attConfig['holidays'] ?? [];
        $weekOffDays = $attConfig['week_off_days'] ?? [Carbon::SUNDAY];
        $todayString = $today->toDateString();

        $dayFacts = $days->mapWithKeys(function (array $day) use ($carbonDays, $trackingStart, $holidays, $weekOffDays, $todayString) {
            $date = $carbonDays[$day['date']];
            $dateString = $day['date'];
            $isToday = $dateString === $todayString;

            return [$dateString => [
                'date' => $date,
                'date_string' => $dateString,
                'is_today' => $isToday,
                'is_future' => ! $isToday && $date->isFuture(),
                'before_tracking' => $trackingStart !== null && $dateString < $trackingStart,
                'is_public_holiday' => in_array($dateString, $holidays, true),
                'is_week_off' => in_array($date->dayOfWeek, $weekOffDays, true),
            ]];
        })->all();

        // The employee axis. Previously every user the tenant has ever had was
        // hydrated (as a full model) and given 28-31 day cells. Archived staff
        // are dropped UNLESS they have attendance data in this month, so a
        // leaver's worked days never disappear from payroll history while
        // months after they left stop costing anything.
        $historicUserIds = TimeEntry::query()
            ->where('action_date', '>=', $rangeStart)
            ->where('action_date', '<', $rangeEndExclusive)
            ->distinct()
            ->pluck('user_id')
            ->merge(
                ManualAttendanceMark::query()
                    ->where('attendance_date', '>=', $rangeStart)
                    ->where('attendance_date', '<', $rangeEndExclusive)
                    ->distinct()
                    ->pluck('user_id')
            )
            ->unique()
            ->values()
            ->all();

        $employees = User::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $historicUserIds))
            ->orderBy('name')
            ->get($this->attendanceUserColumns());

        $userIds = $employees->pluck('id')->all();

        // Bounded to the users actually rendered, projected to the four columns
        // the state machine and the cells read, and NOT hydrated as Eloquent
        // models — a 300-person month is ~74k rows, which as models is where
        // this endpoint ran out of memory. Each punch's business-tz instant is
        // parsed ONCE here and reused by every cell that reads it.
        $entriesByUserDate = TimeEntry::query()
            ->whereIn('user_id', $userIds)
            ->where('action_date', '>=', $rangeStart)
            ->where('action_date', '<', $rangeEndExclusive)
            ->orderBy('action_timestamp')
            ->orderBy('id')
            ->toBase()
            ->get(['user_id', 'action_type', 'action_timestamp', 'action_date'])
            ->each(function ($entry) use ($tz) {
                $entry->local_time = Carbon::parse($entry->action_timestamp)->setTimezone($tz);
            })
            ->groupBy(fn ($entry) => $entry->user_id.'|'.$this->dateKey($entry->action_date));

        $manualMarks = ManualAttendanceMark::query()
            ->whereIn('user_id', $userIds)
            ->where('attendance_date', '>=', $rangeStart)
            ->where('attendance_date', '<', $rangeEndExclusive)
            ->get(['id', 'user_id', 'attendance_date', 'status_code', 'note'])
            ->keyBy(fn (ManualAttendanceMark $mark) => $mark->user_id.'|'.$mark->attendance_date->toDateString());

        $summaryTemplate = [
            'holidays' => 0,
            'present' => 0,
            'absent' => 0,
            'leave' => 0,
            'half_day' => 0,
            'work_from_home' => 0,
            'late_coming' => 0,
            'late_joining' => 0,
            'public_holiday' => 0,
            'total_work_hours' => 0,
        ];

        // "Shift start + grace" as a real instant depends only on (day, shift
        // time, grace) — never on WHICH employee — and an organization has a
        // handful of distinct shift settings, not one per person. Memoising it
        // turns ~3,400 Carbon copy/setTimeFromTimeString/addMinutes chains into
        // at most (days x distinct shifts) of them. Deriving it by adding
        // seconds to midnight would be cheaper still but would drift by an hour
        // on a DST boundary for tenants whose zone observes it, so the Carbon
        // path is kept and simply reused.
        $shiftDueCache = [];
        $shiftDueAt = function (array $dayFact, ?string $shiftHms, int $grace) use (&$shiftDueCache): ?Carbon {
            if ($shiftHms === null) {
                return null;
            }

            $key = $dayFact['date_string'].'|'.$shiftHms.'|'.$grace;

            return $shiftDueCache[$key] ??= $dayFact['date']->copy()
                ->setTimeFromTimeString($shiftHms)
                ->addMinutes($grace);
        };

        // Allocated once instead of once per empty cell — most cells in a month
        // have no punches at all.
        $noEntries = collect();

        $employees = $employees
            ->map(function (User $employee) use ($days, $dayFacts, $entriesByUserDate, $manualMarks, $now, $summaryTemplate, $attConfig, $shiftDueAt, $noEntries) {
                $summary = $summaryTemplate;

                // Per-EMPLOYEE constants, hoisted out of the day loop: these were
                // being re-derived (including a Carbon format() for the shift
                // time and a toDateString() for the joining date) inside every
                // one of this person's 28-31 cells.
                $shiftHms = $employee->shift_start_time?->format('H:i:s');
                $grace = (int) ($employee->shift_grace_minutes ?? 0);
                $joiningDate = $employee->joining_date?->toDateString();
                $idPrefix = $employee->id.'|';

                $dayCells = $days->map(function (array $day) use ($idPrefix, $dayFacts, $entriesByUserDate, $manualMarks, $now, &$summary, $attConfig, $shiftDueAt, $shiftHms, $grace, $joiningDate, $noEntries) {
                    $key = $idPrefix.$day['date'];
                    $entries = $entriesByUserDate->get($key) ?? $noEntries;
                    $manualMark = $manualMarks->get($key);
                    $stats = $entries->isNotEmpty() ? $this->calculateTimeStats($entries) : null;

                    $firstClockIn = $entries->firstWhere('action_type', 'clock_in');
                    $lastEntry = $entries->last();
                    $status = $this->resolveMonthlyStatus(
                        $dayFacts[$day['date']], $manualMark, $now, $firstClockIn,
                        $attConfig, $shiftDueAt, $shiftHms, $grace, $joiningDate
                    );
                    $this->addStatusToSummary($summary, $status['code'], $stats['workHours'] ?? 0);

                    return [
                        'date' => $day['date'],
                        'status_code' => $status['code'],
                        'status_label' => $status['label'],
                        'status_reason' => $status['reason'] ?? null,
                        'source' => $status['source'],
                        'work_hours' => $stats['workHours'] ?? 0,
                        'break_hours' => $stats['breakHours'] ?? 0,
                        // The rows are projected stdClass, so the Eloquent
                        // formatted_action_time accessor is formatted inline
                        // off the timestamp parsed once above — same output.
                        'first_clock_in' => $firstClockIn?->local_time->format('g:i A'),
                        'last_action_time' => $lastEntry?->local_time->format('g:i A'),
                        'manual_note' => $manualMark?->note,
                    ];
                })->values();

                $summary['total_work_hours'] = round($summary['total_work_hours'], 2);

                return [
                    'user_id' => $employee->id,
                    'user_name' => $employee->name,
                    'avatar' => $employee->avatar_url ?? null,
                    'designation' => $employee->designation ?? 'Employee',
                    'days' => $dayCells,
                    'summary' => $summary,
                ];
            })
            ->values();

        return response()->json([
            // Window metadata: the client uses these to label and page the view.
            // `month` is kept (the month the window opens in) so any caller that
            // still reads it keeps working.
            'range' => $range,
            'start' => $rangeStart,
            'end' => $lastDay->toDateString(),
            'month' => $startDate->format('Y-m'),
            'days' => $days->values(),
            'employees' => $employees,
            'statusOptions' => collect(self::ATTENDANCE_STATUSES)
                ->map(fn (string $label, string $code) => ['code' => $code, 'label' => $label])
                ->values(),
            'canManualMark' => $this->canManuallyMarkAttendance($request->user()),
        ]);
    }

    public function updateManualStatus(Request $request)
    {
        if (! $this->canManuallyMarkAttendance($request->user())) {
            abort(403, 'You do not have permission to manually mark attendance.');
        }

        $validated = $request->validate([
            // TENANT-SCOPED existence. A bare `exists:users,id` queries the
            // whole users table, so an id belonging to ANOTHER organization
            // passed validation and the org-stamped ManualAttendanceMark landed
            // in the ACTOR's tenant pointing at a foreign person — a permanently
            // dangling reference that renders as "Deleted user" and corrupts the
            // tenant's attendance counts. It was also a cross-tenant existence
            // oracle (422 = exists nowhere, 200 = exists somewhere), which let a
            // caller map the platform's global user-id space.
            'user_id' => ['required', 'integer', $this->tenantUserRule()],
            'date' => ['required', 'date_format:Y-m-d'],
            'status_code' => ['nullable', Rule::in(array_keys(self::ATTENDANCE_STATUSES))],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        // Belt and braces: resolve the subject through the SCOPED model before
        // any write, so every id used below provably belongs to this tenant even
        // if the rule above is ever loosened. Matches getDayEntries().
        $subject = User::findOrFail($validated['user_id']);

        $date = Carbon::parse($validated['date'], \App\Support\BusinessTime::tz())->toDateString();
        $statusCode = $validated['status_code'] ?? null;

        DB::transaction(function () use ($date, $request, $statusCode, $validated, $subject) {
            $mark = ManualAttendanceMark::query()
                ->where('user_id', $subject->id)
                ->whereDate('attendance_date', $date)
                ->first();

            $oldStatusCode = $mark?->status_code;
            $oldReason = $mark?->note;
            $newReason = $validated['note'] ?? null;

            if (! $statusCode) {
                if ($mark) {
                    $this->recordManualAttendanceAudit(
                        $subject->id,
                        $date,
                        $oldStatusCode,
                        null,
                        $request->user()->id,
                        $oldReason
                    );

                    $mark->delete();
                }

                return;
            }

            if (! $mark) {
                $mark = new ManualAttendanceMark([
                    'user_id' => $subject->id,
                    'attendance_date' => $date,
                ]);
            }

            if ($oldStatusCode !== $statusCode || $oldReason !== $newReason) {
                $this->recordManualAttendanceAudit(
                    $subject->id,
                    $date,
                    $oldStatusCode,
                    $statusCode,
                    $request->user()->id,
                    $newReason
                );
            }

            $mark->fill([
                'marked_by_user_id' => $request->user()->id,
                'status_code' => $statusCode,
                'note' => $newReason,
            ])->save();
        });

        if (! $statusCode) {
            return response()->json(['message' => 'Manual attendance mark cleared.']);
        }

        return response()->json(['message' => 'Attendance status updated.']);
    }

    public function updateCalendar(Request $request)
    {
        if (! $this->canManuallyMarkAttendance($request->user())) {
            abort(403, 'You do not have permission to schedule attendance.');
        }

        $validated = $request->validate([
            'operation' => ['required', Rule::in(['apply', 'clear'])],
            'scope' => ['required', Rule::in(['company', 'selected'])],
            // Bound the people dimension. Without a cap a tiny tenant could post
            // thousands of ids and (combined with a 366-day range) drive the
            // person-day count into the hundreds of thousands.
            'user_ids' => ['nullable', 'array', 'max:'.self::CALENDAR_MAX_SELECTED_USERS],
            'user_ids.*' => ['integer'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'status_code' => ['nullable', Rule::in(['H', 'PH', 'L', 'HD', 'WFH'])],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validated['operation'] === 'apply' && empty($validated['status_code'])) {
            throw ValidationException::withMessages([
                'status_code' => 'Choose an attendance status to apply.',
            ]);
        }

        $startDate = Carbon::parse($validated['start_date'], \App\Support\BusinessTime::tz())->startOfDay();
        $endDate = Carbon::parse($validated['end_date'], \App\Support\BusinessTime::tz())->startOfDay();

        if ($startDate->diffInDays($endDate) > 365) {
            throw ValidationException::withMessages([
                'end_date' => 'The attendance calendar range cannot exceed 366 days.',
            ]);
        }

        // Always re-derive the id set from the TENANT-SCOPED User model. The old
        // `exists:users,id` rule was global, so a caller could name another
        // organization's user ids and have marks/audits written in their own org
        // referencing foreign people. Going through User::query() makes the
        // OrganizationScope drop anything that is not ours, silently and safely.
        $userIds = $validated['scope'] === 'company'
            ? User::query()->where('is_active', true)->pluck('id')->all()
            : User::query()
                ->whereIn('id', array_values(array_unique($validated['user_ids'] ?? [])))
                ->pluck('id')->all();

        if ($userIds === []) {
            throw ValidationException::withMessages([
                'user_ids' => 'Select at least one user.',
            ]);
        }

        $dates = collect(CarbonPeriod::create($startDate, $endDate))
            ->map(fn (Carbon $date) => $date->toDateString());

        // HARD CAP on the actual unit of work. The 366-day span check alone left
        // the people dimension unbounded, so "mark the year as a holiday,
        // company-wide" on a 500-person tenant was 183,000 person-days in one
        // request. This is the guard that actually prevents the outage.
        $personDays = count($userIds) * $dates->count();
        if ($personDays > self::CALENDAR_MAX_PERSON_DAYS) {
            throw ValidationException::withMessages([
                'end_date' => 'That selection is '.number_format($personDays).' person-days. Narrow the date range or the people selected (max '.number_format(self::CALENDAR_MAX_PERSON_DAYS).').',
            ]);
        }

        // Bulk writes (upsert/insert) go straight to the query builder and fire
        // NO model events, so BelongsToOrganization's `creating` stamp never
        // runs. Every row below therefore carries organization_id (and its
        // timestamps) explicitly, or it would land NULL and fall permanently
        // outside the OrganizationScope.
        $organizationId = app(\App\Tenancy\CurrentOrganization::class)->id();
        $now = Carbon::now(\App\Support\BusinessTime::tz());
        $actorId = $request->user()->id;

        // Bulk writes also skip attribute CASTING, so the date must be written in
        // exactly the form Eloquent would have written it. Eloquent stores a
        // `date` cast through fromDateTime(), i.e. the connection's full
        // 'Y-m-d H:i:s' format. MySQL normalises either form into a DATE column,
        // but SQLite (local + tests) stores the literal text — so writing a bare
        // 'Y-m-d' here would produce a value that does NOT match the rows the
        // single-day path wrote, defeating the (user_id, attendance_date) unique
        // index and duplicating marks.
        $dateFormat = (new ManualAttendanceMark)->getDateFormat();
        $storedDates = $dates->mapWithKeys(fn (string $date) => [
            $date => Carbon::parse($date)->format($dateFormat),
        ])->all();

        // Range bounds that match BOTH textual forms without a non-sargable
        // whereDate() wrapper: a bare 'Y-m-d' sorts before the same day's
        // 'Y-m-d 00:00:00', and both sort before that day's 23:59:59.
        $rangeStart = $dates->first();
        $rangeEnd = Carbon::parse($dates->last())->endOfDay()->format($dateFormat);

        $affected = DB::transaction(function () use ($validated, $userIds, $dates, $storedDates, $organizationId, $now, $actorId, $rangeStart, $rangeEnd) {
            if ($validated['operation'] === 'clear') {
                return $this->clearCalendarMarks($userIds, $rangeStart, $rangeEnd, $organizationId, $now, $actorId);
            }

            // ONE prefetch instead of a SELECT per person-day. Bounded by the
            // person-day cap above, so this is at most CALENDAR_MAX_PERSON_DAYS
            // narrow rows.
            $existing = ManualAttendanceMark::query()
                ->whereIn('user_id', $userIds)
                ->whereBetween('attendance_date', [$rangeStart, $rangeEnd])
                ->get(['id', 'user_id', 'attendance_date', 'status_code', 'note'])
                ->keyBy(fn (ManualAttendanceMark $m) => $m->user_id.'|'.$m->attendance_date->toDateString());

            $newReason = $validated['note'] ?? null;
            $statusCode = $validated['status_code'];

            $markRows = [];
            $auditRows = [];
            $affected = 0;

            foreach ($userIds as $userId) {
                foreach ($dates as $date) {
                    $current = $existing->get($userId.'|'.$date);
                    $oldStatusCode = $current?->status_code;
                    $oldReason = $current?->note;

                    // Same semantics as before: audit only on a real change.
                    if ($oldStatusCode !== $statusCode || $oldReason !== $newReason) {
                        $auditRows[] = [
                            'organization_id' => $organizationId,
                            'user_id' => $userId,
                            'attendance_date' => $storedDates[$date],
                            'old_status_code' => $oldStatusCode,
                            'new_status_code' => $statusCode,
                            'changed_by_user_id' => $actorId,
                            'changed_at' => $now,
                            'reason' => $newReason,
                            'created_at' => $now,
                        ];
                    }

                    $markRows[] = [
                        'organization_id' => $organizationId,
                        'user_id' => $userId,
                        'marked_by_user_id' => $actorId,
                        'attendance_date' => $storedDates[$date],
                        'status_code' => $statusCode,
                        'note' => $newReason,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $affected++;
                }
            }

            // (user_id, attendance_date) is the real unique index on
            // manual_attendance_marks, so upsert works on MySQL and SQLite alike.
            foreach (array_chunk($markRows, self::CALENDAR_WRITE_CHUNK) as $chunk) {
                ManualAttendanceMark::query()->upsert(
                    $chunk,
                    ['user_id', 'attendance_date'],
                    ['marked_by_user_id', 'status_code', 'note', 'updated_at']
                );
            }

            foreach (array_chunk($auditRows, self::CALENDAR_WRITE_CHUNK) as $chunk) {
                ManualAttendanceAudit::query()->insert($chunk);
            }

            return $affected;
        });

        return response()->json([
            'message' => $validated['operation'] === 'clear'
                ? "Removed {$affected} scheduled attendance marks."
                : "Applied attendance to {$affected} person-days.",
            'affected' => $affected,
        ]);
    }

    public function getManualHistory(Request $request)
    {
        if (! $this->canManuallyMarkAttendance($request->user())) {
            abort(403, 'You do not have permission to view manual attendance history.');
        }

        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $month = $validated['month'] ?? Carbon::today(\App\Support\BusinessTime::tz())->format('Y-m');
        $startDate = Carbon::createFromFormat('Y-m-d', "{$month}-01", \App\Support\BusinessTime::tz())->startOfDay();
        $endDate = $startDate->copy()->endOfMonth();

        $history = ManualAttendanceAudit::query()
            ->with(['user:id,name,designation,avatar', 'changedBy:id,name'])
            ->whereBetween('attendance_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->when($validated['user_id'] ?? null, fn ($query, int $userId) => $query->where('user_id', $userId))
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->limit(250)
            ->get()
            ->map(fn (ManualAttendanceAudit $audit) => [
                'id' => $audit->id,
                'attendance_date' => $audit->attendance_date->toDateString(),
                'employee_name' => $audit->user?->name ?? 'Deleted user',
                'employee_designation' => $audit->user?->designation ?? 'Member',
                'old_status_code' => $audit->old_status_code,
                'old_status_label' => $this->statusLabel($audit->old_status_code),
                'new_status_code' => $audit->new_status_code,
                'new_status_label' => $this->statusLabel($audit->new_status_code),
                'changed_by' => $audit->changedBy?->name ?? 'System',
                'changed_at' => $audit->changed_at
                    ? $audit->changed_at->copy()->setTimezone(\App\Support\BusinessTime::tz())->format('Y-m-d g:i A')
                    : null,
                'reason' => $audit->reason,
            ]);

        return response()->json([
            'month' => $month,
            'history' => $history,
        ]);
    }

    public function sendSlack(Request $request, AttendanceSlackReportService $slack)
    {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'user_ids' => ['required', 'array', 'min:1'],
            // Scoped like every other user-id input: the report service already
            // filters to the tenant, but a foreign id should never get as far as
            // the request payload (defence in depth + no existence oracle).
            'user_ids.*' => ['integer', $this->tenantUserRule()],
            'include_fields' => ['required', 'array', 'min:1'],
            'include_fields.*' => [
                'string',
                Rule::in([
                    'present',
                    'absent',
                    'leave',
                    'half_day',
                    'work_from_home',
                    'late_coming',
                    'late_joining',
                    'holidays',
                    'public_holiday',
                    'total_work_hours',
                ]),
            ],
        ]);

        try {
            $summary = $slack->sendMonthly(
                $validated['month'],
                $validated['user_ids'],
                $validated['include_fields']
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'slack' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'message' => "Attendance report sent to Slack for {$summary['month']}.",
        ]);
    }

    /**
     * Get summary view data
     */
    public function getSummary(Request $request)
    {
        $date = Carbon::parse($request->input('date', Carbon::today(\App\Support\BusinessTime::tz())->toDateString()), \App\Support\BusinessTime::tz())->toDateString();

        $entriesByUser = $this->entriesForDateByUser($date);

        $employees = $this->employeesForIds($entriesByUser->keys()->all())->map(function (User $employee) use ($entriesByUser) {
            $entries = $entriesByUser->get($employee->id, collect());

            if ($entries->isEmpty()) {
                return null;
            }

            $stats = $this->calculateTimeStats($entries);

            // Get first clock in time
            $firstClockIn = $entries->firstWhere('action_type', 'clock_in');

            // Get last action
            $lastEntry = $entries->last();

            return [
                'user_id' => $employee->id,
                'user_name' => $employee->name,
                'avatar' => $employee->avatar_url ?? null,
                'designation' => $employee->designation ?? 'Employee',
                'total_work_hours' => $stats['workHours'],
                'total_break_hours' => $stats['breakHours'],
                'current_status' => $stats['status'],
                'first_clock_in' => $firstClockIn?->local_time->format('g:i A'),
                'last_action_time' => $lastEntry?->local_time->format('g:i A'),
                'total_entries' => $entries->count(),
            ];
        })->filter()->values();

        return response()->json([
            'employees' => $employees,
        ]);
    }

    /**
     * Get detailed activity view data
     */
    public function getDetailed(Request $request)
    {
        $date = Carbon::parse($request->input('date', Carbon::today(\App\Support\BusinessTime::tz())->toDateString()), \App\Support\BusinessTime::tz())->toDateString();

        $entriesByUser = $this->entriesForDateByUser($date);

        $activities = $this->employeesForIds($entriesByUser->keys()->all())->map(function (User $employee) use ($entriesByUser) {
            $entries = $entriesByUser->get($employee->id, collect());

            if ($entries->isEmpty()) {
                return null;
            }

            return [
                'user_id' => $employee->id,
                'user_name' => $employee->name,
                'avatar' => $employee->avatar_url ?? null,
                'designation' => $employee->designation ?? 'Employee',
                'entries' => $entries->map(function ($entry) {
                    return [
                        'id' => $entry->id,
                        'action_type' => $entry->action_type,
                        'formatted_time' => $entry->local_time->format('g:i A'),
                        'notes' => $entry->notes,
                    ];
                })->values(),
            ];
        })->filter()->values();

        return response()->json([
            'activities' => $activities,
        ]);
    }

    /**
     * Get timeline view data
     */
    public function getTimeline(Request $request)
    {
        $date = Carbon::parse($request->input('date', Carbon::today(\App\Support\BusinessTime::tz())->toDateString()), \App\Support\BusinessTime::tz())->toDateString();

        $entriesByUser = $this->entriesForDateByUser($date);

        $timelines = $this->employeesForIds($entriesByUser->keys()->all())->map(function (User $employee) use ($entriesByUser) {
            $entries = $entriesByUser->get($employee->id, collect());

            if ($entries->isEmpty()) {
                return null;
            }

            $stats = $this->calculateTimeStats($entries);
            $sessions = $this->buildSessions($entries);

            return [
                'user_id' => $employee->id,
                'user_name' => $employee->name,
                'avatar' => $employee->avatar_url ?? null,
                'designation' => $employee->designation ?? 'Employee',
                'total_work_hours' => $stats['workHours'],
                'total_break_hours' => $stats['breakHours'],
                'sessions' => $sessions,
            ];
        })->filter()->values();

        return response()->json([
            'timelines' => $timelines,
        ]);
    }

    /**
     * Export attendance data as CSV
     */
    public function export(Request $request)
    {
        $date = Carbon::parse($request->input('date', Carbon::today(\App\Support\BusinessTime::tz())->toDateString()), \App\Support\BusinessTime::tz())->toDateString();

        $entriesByUser = $this->entriesForDateByUser($date);
        $employees = $this->employeesForIds($entriesByUser->keys()->all());

        // Built with fputcsv + CsvExport::row, never string concatenation.
        // Display names and clock notes are both employee-supplied and land in
        // this MANAGER-facing file: the old '"'.$v.'"' quoting let a name like
        // Bob","=1+1","x close its cell and add columns, and a leading
        // = / + / - / @ made Excel execute the cell (=cmd|' /C calc'!A1 is a
        // live DDE shell launch on the manager's own PC). Every column, the
        // per-employee "Detailed Activity" sub-block, the row order and the
        // filename are byte-for-byte the same shape as before.
        return CsvExport::stream(
            'employee-attendance-'.$date.'.csv',
            function ($handle) use ($employees, $entriesByUser) {
                // Fixed header, written verbatim: fputcsv would quote every
                // cell containing a space ("Work Hours"), and the header text is
                // what the owner's downstream payroll sheet matches on.
                CsvExport::literal($handle, "Employee,Designation,Status,Work Hours,Break Hours,First Clock In,Last Action,Total Actions\n");

                foreach ($employees as $employee) {
                    $entries = $entriesByUser->get($employee->id, collect());

                    if ($entries->isEmpty()) {
                        continue;
                    }

                    $stats = $this->calculateTimeStats($entries);
                    $firstClockIn = $entries->firstWhere('action_type', 'clock_in');
                    $lastEntry = $entries->last();

                    CsvExport::row($handle, [
                        $employee->name,
                        $employee->designation ?? 'Employee',
                        $stats['status'],
                        number_format($stats['workHours'], 2),
                        number_format($stats['breakHours'], 2),
                        $firstClockIn ? $firstClockIn->local_time->format('g:i A') : '-',
                        $lastEntry ? $lastEntry->local_time->format('g:i A') : '-',
                        $entries->count(),
                    ]);

                    // Fixed section separators — literal app text, never user
                    // data, so they are written verbatim to keep the file's
                    // existing shape (fputcsv would quote the space in the
                    // heading).
                    CsvExport::literal($handle, "\nDetailed Activity:\nTime,Action,Notes\n");

                    foreach ($entries as $entry) {
                        CsvExport::row($handle, [
                            $entry->local_time->format('g:i A'),
                            str_replace('_', ' ', ucwords($entry->action_type)),
                            $entry->notes ?? '',
                        ]);
                    }

                    CsvExport::literal($handle, "\n");
                }
            }
        );
    }

    /**
     * One whereIn fetch for every employee's entries on an attendance day,
     * keyed by user — the per-employee lazy queries were a 55-query N+1 on
     * each tab switch. action_date is stamped by attendanceDateFor() at
     * clock time, so all tabs share the same shift-anchored bucketing.
     *
     * @return \Illuminate\Support\Collection
     */
    private function entriesForDateByUser(string $date)
    {
        $tz = BusinessTime::tz();

        // Half-open range instead of whereDate(): `date(action_date) = ?` is
        // non-sargable, so it disqualified every index on the column while the
        // range form matches the same rows (including the " 00:00:00" suffix
        // SQLite persists through the `date` cast). Columns are projected and
        // the rows are not hydrated as models; each punch's business-tz instant
        // is parsed once here for the state machine and the formatted output.
        return TimeEntry::query()
            ->where('action_date', '>=', $date)
            ->where('action_date', '<', Carbon::parse($date)->addDay()->toDateString())
            ->orderBy('action_timestamp')
            ->orderBy('id')
            ->toBase()
            ->get(['id', 'user_id', 'action_type', 'action_timestamp', 'action_date', 'notes'])
            ->each(function ($entry) use ($tz) {
                $entry->local_time = Carbon::parse($entry->action_timestamp)->setTimezone($tz);
            })
            ->groupBy('user_id');
    }

    /**
     * The employees rendered by the day-view tabs, ordered by name.
     *
     * All four callers drop anyone with no punches on the day, so the list is
     * derived FROM the punches instead of hydrating the whole directory and
     * throwing most of it away — on a 500-person tenant that was 500 full User
     * models per tab switch.
     *
     * @param  array<int>  $userIds
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function employeesForIds(array $userIds)
    {
        return User::query()
            ->whereIn('id', $userIds)
            ->orderBy('name')
            ->get($this->attendanceUserColumns());
    }

    /**
     * The only user columns any attendance surface reads. `avatar` backs the
     * appended avatar_url; the shift/joining columns back the status rules.
     *
     * @return array<int, string>
     */
    private function attendanceUserColumns(): array
    {
        return [
            'id',
            'name',
            'avatar',
            'designation',
            'joining_date',
            'shift_start_time',
            'shift_grace_minutes',
        ];
    }

    /** Business-day bucket key (Y-m-d) for an action_date (date cast or raw string). */
    private function dateKey($actionDate): string
    {
        return $actionDate instanceof Carbon
            ? $actionDate->toDateString()
            : substr((string) $actionDate, 0, 10);
    }

    /**
     * Build work and break sessions from entries
     */
    private function buildSessions($entries)
    {
        $tz = BusinessTime::tz();
        $sessions = [];
        $currentWorkStart = null;
        $currentBreakStart = null;

        foreach ($entries as $entry) {
            $entryTime = $this->entryLocalTime($entry, $tz);

            switch ($entry->action_type) {
                case 'clock_in':
                    $currentWorkStart = $entryTime;
                    break;

                case 'clock_out':
                    if ($currentWorkStart) {
                        $durationMinutes = $this->positiveMinutesBetween($currentWorkStart, $entryTime);
                        $sessions[] = [
                            'type' => 'work',
                            'start_time' => $currentWorkStart->format('g:i A'),
                            'end_time' => $entryTime->format('g:i A'),
                            'duration' => $this->formatDuration($durationMinutes),
                        ];
                        $currentWorkStart = null;
                    }
                    break;

                case 'break_start':
                    $currentBreakStart = $entryTime;
                    break;

                case 'break_end':
                    if ($currentBreakStart) {
                        $durationMinutes = $this->positiveMinutesBetween($currentBreakStart, $entryTime);
                        $sessions[] = [
                            'type' => 'break',
                            'start_time' => $currentBreakStart->format('g:i A'),
                            'end_time' => $entryTime->format('g:i A'),
                            'duration' => $this->formatDuration($durationMinutes),
                        ];
                        $currentBreakStart = null;
                    }
                    break;
            }
        }

        $now = Carbon::now($tz);

        // Show the ongoing session unless it's a stale orphaned clock-in.
        // The old same-calendar-day check made a night shift's running
        // session vanish from this tab at midnight; the 18h cap mirrors
        // calculateTimeStats so the visible session matches the hours.
        if ($currentWorkStart) {
            $durationMinutes = $this->positiveMinutesBetween($currentWorkStart, $now);
            if ($durationMinutes <= 18 * 60) {
                $sessions[] = [
                    'type' => 'work',
                    'start_time' => $currentWorkStart->format('g:i A'),
                    'end_time' => null,
                    'duration' => $this->formatDuration($durationMinutes).' (ongoing)',
                ];
            }
        }

        if ($currentBreakStart) {
            $durationMinutes = $this->positiveMinutesBetween($currentBreakStart, $now);
            if ($durationMinutes <= 18 * 60) {
                $sessions[] = [
                    'type' => 'break',
                    'start_time' => $currentBreakStart->format('g:i A'),
                    'end_time' => null,
                    'duration' => $this->formatDuration($durationMinutes).' (ongoing)',
                ];
            }
        }

        return $sessions;
    }

    /**
     * The per-org attendance policy. Humane defaults so a fresh workspace never
     * brands everyone Absent: Sunday off, auto-Absent on, no tracking-start cap
     * and no holidays until the owner sets them.
     *
     * @return array{tracking_start: ?string, week_off_days: array<int,int>, holidays: array<int,string>, auto_absent: bool}
     */
    private function attendancePolicy(): array
    {
        $s = MonitoringSetting::current();
        $weekOff = $s->attendance_week_off_days;

        return [
            'tracking_start' => $s->attendance_tracking_start_date?->toDateString(),
            'week_off_days' => is_array($weekOff) && $weekOff !== [] ? array_map('intval', $weekOff) : [Carbon::SUNDAY],
            'holidays' => is_array($s->attendance_holidays) ? $s->attendance_holidays : [],
            'auto_absent' => $s->attendance_auto_absent ?? true,
        ];
    }

    /**
     * The status of one employee-day cell.
     *
     * Takes PRE-DERIVED day facts (see the $dayFacts map in getMonthlyGrid) and
     * pre-derived employee constants rather than re-deriving both from a Carbon
     * and a User on every one of a monthly grid's thousands of cells. The
     * decision order and every returned code/label/source/reason are unchanged.
     *
     * @param  array<string,mixed>  $dayFact  date, date_string, is_today, is_future, before_tracking, is_public_holiday, is_week_off
     * @param  mixed  $firstClockIn  a projected punch row (stdClass) or null — the grid does not hydrate TimeEntry models
     * @param  callable  $shiftDueAt  (array $dayFact, ?string $shiftHms, int $grace): ?Carbon — memoised shift start + grace
     * @param  string|null  $shiftHms  the employee's shift start as H:i:s, or null when they have no shift
     * @param  string|null  $joiningDate  the employee's joining date as Y-m-d, or null
     */
    private function resolveMonthlyStatus(
        array $dayFact,
        ?ManualAttendanceMark $manualMark,
        Carbon $now,
        $firstClockIn,
        array $att,
        callable $shiftDueAt,
        ?string $shiftHms,
        int $grace,
        ?string $joiningDate
    ): array {
        if ($manualMark) {
            return [
                'code' => $manualMark->status_code,
                'label' => self::ATTENDANCE_STATUSES[$manualMark->status_code] ?? $manualMark->status_code,
                'source' => 'manual',
                'reason' => 'Marked manually by an admin.',
            ];
        }

        if ($dayFact['is_future']) {
            return ['code' => null, 'label' => 'Not set', 'source' => 'future', 'reason' => "Hasn't happened yet."];
        }

        // Worked that day → present (even on a week-off or holiday).
        if ($firstClockIn) {
            $allowedClockIn = $shiftDueAt($dayFact, $shiftHms, $grace);

            if ($allowedClockIn !== null
                && $this->entryLocalTime($firstClockIn, BusinessTime::tz())->greaterThan($allowedClockIn)) {
                return ['code' => 'LC', 'label' => self::ATTENDANCE_STATUSES['LC'], 'source' => 'automatic', 'reason' => 'Clocked in after the shift start + grace.'];
            }

            return ['code' => 'P', 'label' => self::ATTENDANCE_STATUSES['P'], 'source' => 'automatic', 'reason' => 'Clocked in for the day.'];
        }

        if ($joiningDate !== null && $dayFact['date_string'] < $joiningDate) {
            return ['code' => 'LI', 'label' => self::ATTENDANCE_STATUSES['LI'], 'source' => 'automatic', 'reason' => 'Before this person joined.'];
        }

        // Before the workspace started tracking attendance → neutral, never Absent.
        if ($dayFact['before_tracking']) {
            return ['code' => null, 'label' => 'Not tracked', 'source' => 'pre-tracking', 'reason' => 'Before this workspace started tracking attendance.'];
        }

        // Explicit public holiday.
        if ($dayFact['is_public_holiday']) {
            return ['code' => 'PH', 'label' => self::ATTENDANCE_STATUSES['PH'], 'source' => 'automatic', 'reason' => 'Public holiday.'];
        }

        // Configurable weekly off (defaults to Sunday).
        if ($dayFact['is_week_off']) {
            return ['code' => 'H', 'label' => 'Weekly off', 'source' => 'automatic', 'reason' => 'A scheduled weekly off day.'];
        }

        if ($dayFact['is_today']) {
            // Someone with no configured shift NEVER has an absence "due" today
            // — there is no shift end to have passed — so today stays pending
            // for them rather than being called Absent. Same rule as before;
            // spelled out because the null case is the load-bearing one.
            $absenceDueAt = $shiftDueAt($dayFact, $shiftHms, $grace);
            $absenceIsDue = $absenceDueAt !== null && $now->greaterThan($absenceDueAt);

            if (! $absenceIsDue) {
                return ['code' => null, 'label' => 'Shift not started', 'source' => 'pending', 'reason' => 'The shift window is still open today.'];
            }
        }

        // A real working day with no record. Only call it "Absent" when the org
        // opts in; otherwise it's a neutral "No record" so the system never
        // bluntly accuses someone.
        if ($att['auto_absent'] ?? true) {
            return ['code' => 'A', 'label' => self::ATTENDANCE_STATUSES['A'], 'source' => 'automatic', 'reason' => 'A working day with no clock-in after the shift ended.'];
        }

        return ['code' => null, 'label' => 'No record', 'source' => 'no-record', 'reason' => 'No clock-in. Auto-Absent is off for this workspace.'];
    }

    private function addStatusToSummary(array &$summary, ?string $statusCode, float|int $workHours): void
    {
        $summary['total_work_hours'] += (float) $workHours;

        if ($statusCode === 'LC') {
            $summary['present']++;
            $summary['late_coming']++;

            return;
        }

        if ($statusCode === 'LI') {
            $summary['late_joining']++;

            return;
        }

        match ($statusCode) {
            'P' => $summary['present']++,
            'A' => $summary['absent']++,
            'H' => $summary['holidays']++,
            'L' => $summary['leave']++,
            'HD' => $summary['half_day']++,
            'WFH' => $summary['work_from_home']++,
            'PH' => $summary['public_holiday']++,
            default => null,
        };
    }

    // isBeforeJoiningDate() / isLateClockIn() / isShiftAbsenceDue() are gone:
    // each was a one-line derivation re-run inside every employee-day cell, and
    // all three are now folded into resolveMonthlyStatus() against values
    // hoisted out of the loops (the joining date as a string, the shift due-at
    // instant memoised per day+shift). AttendanceSlackReportService keeps its
    // own copies — it walks one day at a time, where the cost never mattered.

    /**
     * `exists` on users, restricted to the ACTIVE tenant.
     *
     * The plain `exists:users,id` rule queries the whole platform's users table
     * — the OrganizationScope is an Eloquent global scope and does not apply to
     * the validator's query builder. Every user-id input on this controller
     * therefore has to name the organization explicitly. Fails closed (matches
     * nothing) if no tenant is bound, which cannot happen behind
     * `tenant.required` but must not degrade to "any tenant" if it ever does.
     */
    private function tenantUserRule(): \Illuminate\Validation\Rules\Exists
    {
        $organizationId = app(\App\Tenancy\CurrentOrganization::class)->id();

        return Rule::exists('users', 'id')->where(
            fn ($query) => $organizationId
                ? $query->where('organization_id', $organizationId)
                : $query->whereRaw('1 = 0')
        );
    }

    private function canManuallyMarkAttendance(?User $user): bool
    {
        return (bool) $user && (
            $user->isSuperAdmin()
            || $user->hasPermission('attendance.manual_mark')
        );
    }

    /**
     * Editing someone's recorded clock times rewrites payroll-grade history, so
     * it sits behind its own permission key (super admins pass via
     * hasPermission()) and every change is audited to tracking_audit_logs.
     */
    private function canEditClockTimes(?User $user): bool
    {
        return (bool) $user && $user->hasPermission('attendance.edit_times');
    }

    /**
     * Clear branch of the bulk calendar writer.
     *
     * Streams the matching marks with chunkById() (so an unbounded clear never
     * hydrates the whole range into memory), writes one bulk audit INSERT per
     * chunk instead of one INSERT per mark, and deletes by key per chunk. Every
     * audit row carries organization_id explicitly because bulk inserts fire no
     * model events and would otherwise be stamped NULL.
     */
    private function clearCalendarMarks(
        array $userIds,
        string $rangeStart,
        string $rangeEnd,
        ?int $organizationId,
        Carbon $now,
        int $actorId
    ): int {
        $cleared = 0;
        // Bulk inserts skip attribute casting, so dates must be written in the
        // same format Eloquent uses (see updateCalendar) or SQLite ends up with
        // two textual representations of the same day.
        $dateFormat = (new ManualAttendanceAudit)->getDateFormat();

        ManualAttendanceMark::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('attendance_date', [$rangeStart, $rangeEnd])
            ->select(['id', 'user_id', 'attendance_date', 'status_code', 'note'])
            ->chunkById(self::CALENDAR_WRITE_CHUNK, function ($marks) use (&$cleared, $organizationId, $now, $actorId, $dateFormat) {
                $auditRows = [];

                foreach ($marks as $mark) {
                    $auditRows[] = [
                        'organization_id' => $organizationId,
                        'user_id' => $mark->user_id,
                        'attendance_date' => $mark->attendance_date->format($dateFormat),
                        'old_status_code' => $mark->status_code,
                        'new_status_code' => null,
                        'changed_by_user_id' => $actorId,
                        'changed_at' => $now,
                        'reason' => $mark->note,
                        'created_at' => $now,
                    ];
                }

                if ($auditRows !== []) {
                    ManualAttendanceAudit::query()->insert($auditRows);
                }

                ManualAttendanceMark::query()->whereIn('id', $marks->modelKeys())->delete();
                $cleared += $marks->count();
            });

        return $cleared;
    }

    private function recordManualAttendanceAudit(
        int $userId,
        string $attendanceDate,
        ?string $oldStatusCode,
        ?string $newStatusCode,
        ?int $changedByUserId,
        ?string $reason
    ): void {
        ManualAttendanceAudit::create([
            'user_id' => $userId,
            'attendance_date' => $attendanceDate,
            'old_status_code' => $oldStatusCode,
            'new_status_code' => $newStatusCode,
            'changed_by_user_id' => $changedByUserId,
            'changed_at' => Carbon::now(\App\Support\BusinessTime::tz()),
            'reason' => $reason,
        ]);
    }

    private function statusLabel(?string $statusCode): string
    {
        if (! $statusCode) {
            return 'Auto';
        }

        return self::ATTENDANCE_STATUSES[$statusCode] ?? $statusCode;
    }

    private function formatDuration(int $durationMinutes)
    {
        $hours = intdiv($durationMinutes, 60);
        $minutes = $durationMinutes % 60;

        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }

        return "{$minutes}m";
    }
}
