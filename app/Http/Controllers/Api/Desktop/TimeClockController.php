<?php

namespace App\Http\Controllers\Api\Desktop;

use App\Http\Controllers\Controller;
use App\Models\TimeEntry;
use App\Support\TimeClockRules;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Attendance clock for the desktop tracker: clock in/out and breaks.
 * Mirrors the web dashboard's Time Tracking card using the same
 * TimeClockRules transitions and overnight attendance-date logic.
 */
class TimeClockController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $now = Carbon::now('Asia/Karachi');
        $attendanceDate = $user->attendanceDateFor($now);

        $lastAction = TimeEntry::forUser($user->id)
            ->forDate($attendanceDate)
            ->orderByDesc('action_timestamp')->orderByDesc('id')
            ->value('action_type');

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
        $now = Carbon::now('Asia/Karachi');
        $attendanceDate = $user->attendanceDateFor($now);
        $actionType = $validated['action_type'];

        $lastAction = TimeEntry::forUser($user->id)
            ->forDate($attendanceDate)
            ->orderByDesc('action_timestamp')->orderByDesc('id')
            ->value('action_type');

        if (! TimeClockRules::isAllowed($lastAction, $actionType)) {
            return response()->json([
                'message' => TimeClockRules::blockedMessage($lastAction, $actionType),
            ], 422);
        }

        TimeEntry::create([
            'user_id' => $user->id,
            'action_type' => $actionType,
            'action_timestamp' => $now,
            'action_date' => $attendanceDate,
            'action_time' => $now->toTimeString(),
            'notes' => $validated['notes'] ?? 'Desktop app',
        ]);

        return response()->json([
            'last_action' => $actionType,
            'available' => TimeClockRules::available($actionType),
        ], 201);
    }
}
