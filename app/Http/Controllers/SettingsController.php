<?php

namespace App\Http\Controllers;

use App\Models\MonitoringSetting;
use App\Models\User;
use App\Models\UserMonitoringSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    private const CATEGORIES = [
        'screenshots',
        'activity',
        'app_url',
        'weekly_limit',
        'auto_pause',
        'offline_time',
        'notify_screenshot',
        'week_starts_on',
        'currency',
        'desktop_app',
        'display',
    ];

    public function index(): Response
    {
        $team = MonitoringSetting::current();

        // Everyone is listed, including Super Admins, so the owner sees the full
        // roster. Super Admins are exempt from monitoring overrides (the tracker
        // doesn't apply them), so their rows render read-only/"Exempt" and
        // updateUser() refuses to write an override for them.
        $users = User::orderBy('name')
            ->get(['id', 'name', 'email', 'role']);

        $overrides = UserMonitoringSetting::whereIn('user_id', $users->pluck('id'))
            ->get()
            ->keyBy('user_id');

        $perUser = $users->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->role,
            'overrides' => $this->serializeOverride($overrides->get($u->id)),
        ]);

        return Inertia::render('Settings/Index', [
            'team' => $team->teamPayload(),
            'users' => $perUser,
        ]);
    }

    public function updateTeam(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'screenshots_per_hour' => ['required', 'integer', 'min:0', 'max:60'],
            'blur_screenshots' => ['required', 'boolean'],
            'capture_enabled' => ['required', 'boolean'],
            'activity_tracking_enabled' => ['required', 'boolean'],
            'app_url_tracking_enabled' => ['required', 'boolean'],
            'weekly_time_limit_hours' => ['nullable', 'integer', 'min:0', 'max:168'],
            'auto_pause_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'allow_offline_time' => ['required', 'boolean'],
            'notify_on_screenshot' => ['required', 'boolean'],
            'week_starts_on' => ['required', Rule::in(['monday', 'sunday'])],
            'currency_symbol' => ['required', 'string', 'max:8'],
            'desktop_auto_start' => ['required', 'boolean'],
            'desktop_force_quit_on_idle' => ['required', 'boolean'],
            'display_timezone' => ['required', 'timezone'],
            'time_format' => ['required', Rule::in(['12', '24'])],
            'slack_clockin_enabled' => ['required', 'boolean'],
            'slack_clockout_enabled' => ['required', 'boolean'],
            'slack_attendance_channel' => ['nullable', 'string', 'max:100'],
        ]);

        // Default-deny gating: a tenant can never save a setting beyond what its
        // plan (or a platform per-tenant override) grants. Enforced here on the
        // server, not just hidden in the UI, so the system can't be abused.
        if ($org = $request->user()->organization) {
            $this->enforceEntitlements($org, $data);
        }

        $team = MonitoringSetting::current();

        // Keep the desktop's min/max seconds in sync with screenshots_per_hour
        // so the existing tracker code keeps working unchanged.
        $perHour = (int) $data['screenshots_per_hour'];
        if ($perHour > 0) {
            $avg = (int) round(3600 / $perHour);
            $data['screenshot_interval_min_seconds'] = max(30, (int) round($avg * 0.5));
            $data['screenshot_interval_max_seconds'] = max(60, (int) round($avg * 1.5));
            $data['capture_enabled'] = $data['capture_enabled'] && true;
        } else {
            $data['capture_enabled'] = false;
        }

        $team->update($data);
        \App\Models\MonitoringSetting::forgetSharedCache();

        // Toggles save in the background (axios) — a tiny JSON ack keeps the
        // page from re-rendering all settings props on every flip.
        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Team settings updated.');
    }

    /**
     * Save the per-org attendance policy (Phase 5F): when tracking started,
     * which weekdays are off, holiday dates, and whether a missing working day
     * is auto-marked Absent. Keeps a fresh workspace from branding everyone
     * Absent for days it wasn't even tracking.
     */
    public function updateAttendance(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'attendance_tracking_start_date' => ['nullable', 'date'],
            'attendance_week_off_days' => ['nullable', 'array'],
            'attendance_week_off_days.*' => ['integer', 'between:0,6'],
            'attendance_holidays' => ['nullable', 'array'],
            'attendance_holidays.*' => ['date_format:Y-m-d'],
            'attendance_auto_absent' => ['required', 'boolean'],
        ]);

        $team = MonitoringSetting::current();
        $team->update([
            'attendance_tracking_start_date' => $data['attendance_tracking_start_date'] ?: null,
            'attendance_week_off_days' => array_values(array_unique(array_map('intval', $data['attendance_week_off_days'] ?? []))),
            'attendance_holidays' => array_values(array_unique($data['attendance_holidays'] ?? [])),
            'attendance_auto_absent' => $data['attendance_auto_absent'],
        ]);
        MonitoringSetting::forgetSharedCache();

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Attendance policy updated.');
    }

    /**
     * Connect or disconnect this organization's own Slack bot token. The token
     * is write-only (never returned to the client); the UI shows only connected
     * status. Sending `disconnect` clears it; a blank token keeps the current one.
     */
    public function updateSlack(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'slack_bot_token' => ['nullable', 'string', 'max:255'],
            'disconnect' => ['nullable', 'boolean'],
        ]);

        // Connecting your own Slack bot is an integrations feature — gate it.
        if (filled($data['slack_bot_token'] ?? null) && empty($data['disconnect'])) {
            $org = $request->user()->organization;
            if ($org && ! $org->can('integrations')) {
                throw ValidationException::withMessages([
                    'slack_bot_token' => 'Connecting your own Slack bot is not included in your plan.',
                ]);
            }
        }

        $team = MonitoringSetting::current();

        if (! empty($data['disconnect'])) {
            $team->slack_bot_token = null;
        } elseif (filled($data['slack_bot_token'] ?? null)) {
            $team->slack_bot_token = trim($data['slack_bot_token']);
        }

        $team->save();
        MonitoringSetting::forgetSharedCache();

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'connected' => filled($team->slack_bot_token)]);
        }

        return back()->with('success', 'Slack connection updated.');
    }

    public function updateUser(Request $request, User $user): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'enabled' => ['required', 'boolean'],
            'values' => ['nullable', 'array'],
            'values.screenshots_per_hour' => ['nullable', 'integer', 'min:0', 'max:60'],
            'values.blur_screenshots' => ['nullable', 'boolean'],
            'values.capture_enabled' => ['nullable', 'boolean'],
            'values.activity_tracking_enabled' => ['nullable', 'boolean'],
            'values.app_url_tracking_enabled' => ['nullable', 'boolean'],
            'values.weekly_time_limit_hours' => ['nullable', 'integer', 'min:0', 'max:168'],
            'values.auto_pause_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'values.allow_offline_time' => ['nullable', 'boolean'],
            'values.notify_on_screenshot' => ['nullable', 'boolean'],
            'values.desktop_auto_start' => ['nullable', 'boolean'],
            'values.desktop_force_quit_on_idle' => ['nullable', 'boolean'],
            'values.display_timezone' => ['nullable', 'timezone'],
            'values.time_format' => ['nullable', Rule::in(['12', '24'])],
        ]);

        abort_if($user->isSuperAdmin(), 422, 'Super Admins are not subject to overrides.');

        $override = UserMonitoringSetting::firstOrNew(['user_id' => $user->id]);

        $flagField = $this->flagFieldFor($data['category']);
        $valueFields = $this->valueFieldsFor($data['category']);

        $override->{$flagField} = (bool) $data['enabled'];

        if ($data['enabled']) {
            $team = MonitoringSetting::current()->teamPayload();
            foreach ($valueFields as $field) {
                $incoming = $data['values'][$field] ?? null;
                $override->{$field} = $incoming ?? $team[$field] ?? null;
            }
        } else {
            foreach ($valueFields as $field) {
                $override->{$field} = null;
            }
        }

        $override->user_id = $user->id;
        $override->save();

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Individual setting updated.');
    }

    /**
     * Reject any team setting that exceeds the org's effective entitlements
     * (plan defaults merged with platform per-tenant overrides). Throws a
     * ValidationException keyed by field so the form shows an inline
     * "upgrade to unlock" reason.
     */
    private function enforceEntitlements(\App\Models\Organization $org, array $data): void
    {
        $errors = [];

        // Screenshot monitoring: the feature itself, then the per-hour ceiling.
        if (! $org->can('monitoring')) {
            if (($data['capture_enabled'] ?? false) || (int) ($data['screenshots_per_hour'] ?? 0) > 0) {
                $errors['screenshots_per_hour'] = 'Screenshot monitoring is not included in your plan.';
            }
        } elseif (! $org->entitlementAllows('max_screenshots_per_hour', (int) ($data['screenshots_per_hour'] ?? 0))) {
            $max = $org->entitlement('max_screenshots_per_hour');
            $errors['screenshots_per_hour'] = "Your plan allows up to {$max} screenshots per hour. Upgrade to increase the rate.";
        }

        // Auto-pause floor: lower minutes = more aggressive; plans set a floor.
        $pause = (int) ($data['auto_pause_minutes'] ?? 0);
        if ($pause > 0 && ! $org->entitlementAllows('min_auto_pause_minutes', $pause)) {
            $min = $org->entitlement('min_auto_pause_minutes');
            $errors['auto_pause_minutes'] = "Your plan's minimum auto-pause is {$min} minutes. Upgrade for a tighter idle limit.";
        }

        // Feature flags.
        if (($data['activity_tracking_enabled'] ?? false) && ! $org->can('activity_tracking')) {
            $errors['activity_tracking_enabled'] = 'Activity tracking is not included in your plan.';
        }
        if (($data['app_url_tracking_enabled'] ?? false) && ! $org->can('app_url_tracking')) {
            $errors['app_url_tracking_enabled'] = 'App & URL tracking is not included in your plan.';
        }
        if (! empty($data['weekly_time_limit_hours']) && ! $org->can('weekly_limit')) {
            $errors['weekly_time_limit_hours'] = 'Weekly time limits are not included in your plan.';
        }
        if ((($data['slack_clockin_enabled'] ?? false) || ($data['slack_clockout_enabled'] ?? false)) && ! $org->can('attendance_alerts')) {
            $errors['slack_clockin_enabled'] = 'Attendance Slack alerts are not included in your plan.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function flagFieldFor(string $category): string
    {
        return [
            'screenshots' => 'override_screenshots',
            'activity' => 'override_activity',
            'app_url' => 'override_app_url',
            'weekly_limit' => 'override_weekly_limit',
            'auto_pause' => 'override_auto_pause',
            'offline_time' => 'override_offline_time',
            'notify_screenshot' => 'override_notify_screenshot',
            'desktop_app' => 'override_desktop_app',
            'display' => 'override_display',
            'week_starts_on' => 'override_screenshots', // no per-user override
            'currency' => 'override_screenshots',        // no per-user override
        ][$category];
    }

    /** @return string[] */
    private function valueFieldsFor(string $category): array
    {
        return [
            'screenshots' => ['screenshots_per_hour', 'blur_screenshots', 'capture_enabled'],
            'activity' => ['activity_tracking_enabled'],
            'app_url' => ['app_url_tracking_enabled'],
            'weekly_limit' => ['weekly_time_limit_hours'],
            'auto_pause' => ['auto_pause_minutes'],
            'offline_time' => ['allow_offline_time'],
            'notify_screenshot' => ['notify_on_screenshot'],
            'desktop_app' => ['desktop_auto_start', 'desktop_force_quit_on_idle'],
            'display' => ['display_timezone', 'time_format'],
            'week_starts_on' => [],
            'currency' => [],
        ][$category];
    }

    /** @return array<string, mixed> */
    private function serializeOverride(?UserMonitoringSetting $row): array
    {
        if (! $row) {
            return [
                'override_screenshots' => false,
                'override_activity' => false,
                'override_app_url' => false,
                'override_weekly_limit' => false,
                'override_auto_pause' => false,
                'override_offline_time' => false,
                'override_notify_screenshot' => false,
                'override_desktop_app' => false,
                'override_display' => false,
            ];
        }

        return [
            'override_screenshots' => (bool) $row->override_screenshots,
            'override_activity' => (bool) $row->override_activity,
            'override_app_url' => (bool) $row->override_app_url,
            'override_weekly_limit' => (bool) $row->override_weekly_limit,
            'override_auto_pause' => (bool) $row->override_auto_pause,
            'override_offline_time' => (bool) $row->override_offline_time,
            'override_notify_screenshot' => (bool) $row->override_notify_screenshot,
            'override_desktop_app' => (bool) $row->override_desktop_app,
            'override_display' => (bool) $row->override_display,
            'display_timezone' => $row->display_timezone,
            'time_format' => $row->time_format,
            'screenshots_per_hour' => $row->screenshots_per_hour,
            'blur_screenshots' => $row->blur_screenshots,
            'capture_enabled' => $row->capture_enabled,
            'activity_tracking_enabled' => $row->activity_tracking_enabled,
            'app_url_tracking_enabled' => $row->app_url_tracking_enabled,
            'weekly_time_limit_hours' => $row->weekly_time_limit_hours,
            'auto_pause_minutes' => $row->auto_pause_minutes,
            'allow_offline_time' => $row->allow_offline_time,
            'notify_on_screenshot' => $row->notify_on_screenshot,
            'desktop_auto_start' => $row->desktop_auto_start,
            'desktop_force_quit_on_idle' => $row->desktop_force_quit_on_idle,
        ];
    }
}
