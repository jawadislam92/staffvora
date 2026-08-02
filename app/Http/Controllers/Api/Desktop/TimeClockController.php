<?php

namespace App\Http\Controllers\Api\Desktop;

use App\Models\TimeEntry;
use App\Models\User;
use App\Support\TimeClockRules;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Attendance clock for the desktop tracker: clock in/out and breaks.
 * Mirrors the web dashboard's Time Tracking card using the same
 * TimeClockRules transitions and overnight attendance-date logic.
 */
class TimeClockController extends DesktopController
{
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $now = Carbon::now(\App\Support\BusinessTime::tz());
        $attendanceDate = $user->attendanceDateFor($now);

        // Global current state (not day-scoped) so the desktop app shows
        // "Clock Out" — not "Clock In" — for someone still clocked in from
        // last night, and never offers a duplicate clock-in after midnight.
        $lastAction = TimeEntry::currentClockState($user->id);

        return response()->json([
            'last_action' => $lastAction,
            'available' => TimeClockRules::available($lastAction),
            'attendance_date' => $attendanceDate instanceof Carbon ? $attendanceDate->toDateString() : (string) $attendanceDate,
        ]);
    }

    public function act(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action_type' => ['required', Rule::in(TimeClockRules::ACTIONS)],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $now = Carbon::now(\App\Support\BusinessTime::tz());
        $attendanceDate = $user->attendanceDateFor($now);
        $actionType = $validated['action_type'];

        // Same double-start race guard as the web store(): lock the user row,
        // then read the GLOBAL current clock state and validate the transition
        // before inserting, all in one transaction. Two concurrent clock actions
        // from the same person (shared-PC double-tap) serialize on the lock, so a
        // duplicate clock-in — including one that crosses midnight while a prior
        // clock-in is still open — is rejected instead of orphaning a session.
        $lastAction = null;
        $created = DB::transaction(function () use ($user, $now, $actionType, $attendanceDate, $validated, &$lastAction) {
            User::whereKey($user->id)->lockForUpdate()->first();

            $lastAction = TimeEntry::currentClockState($user->id);

            if (! TimeClockRules::isAllowed($lastAction, $actionType)) {
                return false;
            }

            TimeEntry::create([
                'user_id' => $user->id,
                'action_type' => $actionType,
                'action_timestamp' => $now,
                'action_date' => $attendanceDate,
                'action_time' => $now->toTimeString(),
                'notes' => $validated['notes'] ?? 'Desktop app',
            ]);

            return true;
        });

        if (! $created) {
            return response()->json([
                'message' => TimeClockRules::blockedMessage($lastAction, $actionType),
            ], 422);
        }

        return response()->json([
            'last_action' => $actionType,
            'available' => TimeClockRules::available($actionType),
        ], 201);
    }
}
