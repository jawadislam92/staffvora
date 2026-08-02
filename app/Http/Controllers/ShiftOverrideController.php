<?php

namespace App\Http\Controllers;

use App\Models\ShiftOverride;
use App\Models\TrackingAuditLog;
use App\Models\User;
use App\Support\BusinessTime;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * One-day shift changes. A member with `shift.edit_own` manages their OWN
 * upcoming days (today/future only); an admin with `shift.manage_all` may set or
 * remove a change for anyone, including backdated days.
 *
 * An override only moves that day's schedule (start time / length) — it never
 * creates clock-ins or tracked time, so it cannot inflate hours.
 * {@see User::effectiveShiftFor()} applies it everywhere (day bucketing,
 * auto-close, nudges, late detection) automatically.
 *
 * TENANCY: every read/write goes through tenant-scoped models, so a person can
 * only ever see or touch overrides inside their own workspace — the `user_id`
 * lookup uses User::findOrFail(), which the OrganizationScope narrows, so an id
 * from another workspace 404s rather than resolving.
 *
 * TIME: "today" and the override date are judged in the WORKSPACE's business
 * timezone (BusinessTime::tz()), the same zone attendance days bucket in — never
 * a naive now().
 */
class ShiftOverrideController extends Controller
{
    /** The member-facing "My Schedule" page: standing shift + upcoming changes. */
    public function index(Request $request)
    {
        $user = $request->user();
        $today = BusinessTime::today()->toDateString();

        $overrides = $user->shiftOverrides()
            ->whereDate('date', '>=', $today)
            ->orderBy('date')
            ->get()
            ->map(fn (ShiftOverride $o) => $this->present($o))
            ->values();

        // The standing schedule as the app actually resolves it: the assigned
        // named shift's timing when it has any, otherwise the person's own
        // columns. Presented for TODAY so an override on a past day can never
        // masquerade as the standing shift.
        $standing = $user->effectiveShiftFor($today);
        $hasOverrideToday = $user->shiftOverrides
            ->contains(fn ($o) => optional($o->date)->toDateString() === $today);

        return Inertia::render('MySchedule', [
            'standingShift' => [
                'name' => $user->shift?->name,
                // When today already carries an override, show the underlying
                // standing values rather than the overridden ones.
                'start_time' => $hasOverrideToday
                    ? $this->standingStartTime($user)
                    : $standing['start_time']?->format('H:i'),
                'hours' => $hasOverrideToday
                    ? $this->standingHours($user)
                    : $standing['hours'],
                'grace_minutes' => (int) ($standing['grace_minutes'] ?? 0),
            ],
            'overrides' => $overrides,
            'today' => $today,
            'canEdit' => $user->hasPermission('shift.edit_own') || $user->hasPermission('shift.manage_all'),
            'canManageAll' => $user->hasPermission('shift.manage_all'),
        ]);
    }

    public function store(Request $request)
    {
        $actor = $request->user();

        $data = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'shift_start_time' => ['nullable', 'date_format:H:i'],
            'shift_hours' => ['nullable', 'numeric', 'min:0', 'max:16'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        // Nothing to change unless a start time and/or a length is given.
        if (empty($data['shift_start_time']) && ($data['shift_hours'] ?? null) === null) {
            throw ValidationException::withMessages([
                'shift_start_time' => 'Set a start time, a length, or both.',
            ]);
        }

        $targetId = $data['user_id'] ?? $actor->id;
        $isSelf = (int) $targetId === (int) $actor->id;

        // Self-service needs shift.edit_own; touching anyone else needs manage_all.
        abort_unless(
            $isSelf ? $actor->hasPermission('shift.edit_own') : $actor->hasPermission('shift.manage_all'),
            403
        );

        // findOrFail runs through the OrganizationScope, so an id belonging to
        // another workspace is not found instead of being silently edited.
        $target = $isSelf ? $actor : User::findOrFail($targetId);

        // The override date and the today/future guard are judged in the
        // workspace's business timezone — the zone attendance days bucket in.
        $date = BusinessTime::parseDate($data['date']);

        // Without manage_all you can only change today or a future day — never
        // retro-edit a day whose attendance is already settled.
        if (! $actor->hasPermission('shift.manage_all') && $date->lt(BusinessTime::today())) {
            throw ValidationException::withMessages([
                'date' => 'You can only change your shift for today or a future day.',
            ]);
        }

        $existing = $target->shiftOverrides()->whereDate('date', $date->toDateString())->first();
        $before = $existing
            ? [
                'shift_start_time' => $existing->shift_start_time?->format('H:i'),
                'shift_hours' => $existing->shift_hours !== null ? (float) $existing->shift_hours : null,
            ]
            : null;

        $payload = [
            'shift_start_time' => $data['shift_start_time'] ?? null,
            'shift_hours' => $data['shift_hours'] ?? null,
            'reason' => $data['reason'] ?? null,
            'created_by' => $actor->id,
        ];

        // Matched with whereDate() rather than updateOrCreate()'s plain equality:
        // a `date` cast is persisted as "Y-m-d 00:00:00" on SQLite but "Y-m-d" on
        // MySQL, so an equality match would silently miss (and then trip the
        // unique index) on one of the two. whereDate() is correct on both.
        if ($existing) {
            $existing->fill($payload)->save();
        } else {
            ShiftOverride::create([
                'user_id' => $target->id,
                'date' => $date->toDateString(),
                ...$payload,
            ]);
        }

        TrackingAuditLog::record([
            'subject_user_id' => $target->id,
            'actor_user_id' => $actor->id,
            'action' => 'shift.override',
            'event_date' => $date->toDateString(),
            'old_value' => $before,
            'new_value' => [
                'shift_start_time' => $data['shift_start_time'] ?? null,
                'shift_hours' => $data['shift_hours'] ?? null,
            ],
            'reason' => $data['reason'] ?? null,
        ]);

        return back()->with('success', 'Shift updated for '.$date->format('M j, Y').'.');
    }

    public function destroy(Request $request, ShiftOverride $shiftOverride)
    {
        $actor = $request->user();
        $isSelf = (int) $shiftOverride->user_id === (int) $actor->id;

        abort_unless(
            $isSelf ? $actor->hasPermission('shift.edit_own') : $actor->hasPermission('shift.manage_all'),
            403
        );

        // Without manage_all you may only cancel a day that hasn't started yet.
        if (! $actor->hasPermission('shift.manage_all')
            && Carbon::parse($shiftOverride->date)->startOfDay()->lt(BusinessTime::today()->startOfDay())) {
            abort(403);
        }

        TrackingAuditLog::record([
            'subject_user_id' => $shiftOverride->user_id,
            'actor_user_id' => $actor->id,
            'action' => 'shift.override_removed',
            'event_date' => Carbon::parse($shiftOverride->date)->toDateString(),
            'old_value' => [
                'shift_start_time' => $shiftOverride->shift_start_time?->format('H:i'),
                'shift_hours' => $shiftOverride->shift_hours !== null ? (float) $shiftOverride->shift_hours : null,
            ],
            'new_value' => null,
            'reason' => null,
        ]);

        $shiftOverride->delete();

        return back()->with('success', 'Shift change removed.');
    }

    /** The standing start time, ignoring any one-day override. */
    private function standingStartTime(User $user): ?string
    {
        $shift = $user->shift_id ? $user->shift : null;
        $start = ($shift && $shift->start_time) ? $shift->start_time : $user->shift_start_time;

        return $start?->format('H:i');
    }

    /** The standing shift length, ignoring any one-day override. */
    private function standingHours(User $user): ?float
    {
        $shift = $user->shift_id ? $user->shift : null;

        if ($shift && $shift->hours !== null) {
            return (float) $shift->hours;
        }

        return $user->shift_hours !== null ? (float) $user->shift_hours : null;
    }

    private function present(ShiftOverride $o): array
    {
        $date = Carbon::parse($o->date);

        return [
            'id' => $o->id,
            'date' => $date->toDateString(),
            'date_label' => $date->format('l, M j'),
            'shift_start_time' => $o->shift_start_time?->format('H:i'),
            'shift_hours' => $o->shift_hours !== null ? (float) $o->shift_hours : null,
            'reason' => $o->reason,
        ];
    }
}
