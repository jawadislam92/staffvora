<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class MonitoringSetting extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'ai_suggested_questions',
        'screenshots_per_hour',
        'screenshot_interval_min_seconds',
        'screenshot_interval_max_seconds',
        'idle_threshold_seconds',
        'activity_sample_interval_seconds',
        'retention_days',
        'blur_screenshots',
        'capture_enabled',
        'require_active_window_metadata',
        'activity_tracking_enabled',
        'app_url_tracking_enabled',
        'weekly_time_limit_hours',
        'auto_pause_minutes',
        'allow_offline_time',
        'manual_time_overlap_policy',
        'notify_on_screenshot',
        'week_starts_on',
        'currency_symbol',
        'desktop_auto_start',
        'desktop_force_quit_on_idle',
        'display_timezone',
        'time_format',
        'slack_clockin_enabled',
        'slack_clockout_enabled',
        'slack_attendance_channel',
        'slack_bot_token',
        'branding_logo_path',
        'attendance_tracking_start_date',
        'attendance_week_off_days',
        'attendance_holidays',
        'attendance_auto_absent',
    ];

    protected $casts = [
        'ai_suggested_questions' => 'array',
        'screenshots_per_hour' => 'integer',
        'screenshot_interval_min_seconds' => 'integer',
        'screenshot_interval_max_seconds' => 'integer',
        'idle_threshold_seconds' => 'integer',
        'activity_sample_interval_seconds' => 'integer',
        'retention_days' => 'integer',
        'blur_screenshots' => 'boolean',
        'capture_enabled' => 'boolean',
        'require_active_window_metadata' => 'boolean',
        'activity_tracking_enabled' => 'boolean',
        'app_url_tracking_enabled' => 'boolean',
        'weekly_time_limit_hours' => 'integer',
        'auto_pause_minutes' => 'integer',
        'allow_offline_time' => 'boolean',
        'notify_on_screenshot' => 'boolean',
        'desktop_auto_start' => 'boolean',
        'desktop_force_quit_on_idle' => 'boolean',
        'slack_clockin_enabled' => 'boolean',
        'slack_clockout_enabled' => 'boolean',
        'slack_bot_token' => 'encrypted',
        'attendance_tracking_start_date' => 'date',
        'attendance_week_off_days' => 'array',
        'attendance_holidays' => 'array',
        'attendance_auto_absent' => 'boolean',
    ];

    /**
     * Invalidate the current organization's cached shared settings + branding
     * payloads (see HandleInertiaRequests). Call after any settings/branding
     * change. Keys are org-scoped so one tenant's flush never affects another.
     */
    public static function forgetSharedCache(): void
    {
        $id = app(CurrentOrganization::class)->id() ?? 0;
        \Illuminate\Support\Facades\Cache::forget('monitoring_settings.shared.'.$id);
        \Illuminate\Support\Facades\Cache::forget('branding.shared.'.$id);
    }

    /**
     * Carbon day-of-week constant for the configured week start, for use with
     * Carbon::startOfWeek()/endOfWeek().
     */
    public static function weekStartDay(): int
    {
        return static::current()->week_starts_on === 'sunday'
            ? Carbon::SUNDAY
            : Carbon::MONDAY;
    }

    /**
     * Matching end-of-week day for Carbon::endOfWeek().
     */
    public static function weekEndDay(): int
    {
        return static::current()->week_starts_on === 'sunday'
            ? Carbon::SATURDAY
            : Carbon::SUNDAY;
    }

    /**
     * The shipped defaults for a brand-new workspace settings row. Also the
     * shape of the throw-away instance returned when NO tenant is bound.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'screenshots_per_hour' => 6,
            'screenshot_interval_min_seconds' => 300,
            'screenshot_interval_max_seconds' => 600,
            'idle_threshold_seconds' => 300,
            'activity_sample_interval_seconds' => 60,
            'retention_days' => 60,
            'blur_screenshots' => false,
            'capture_enabled' => true,
            'require_active_window_metadata' => true,
            'activity_tracking_enabled' => true,
            'app_url_tracking_enabled' => false,
            'weekly_time_limit_hours' => null,
            'auto_pause_minutes' => 5,
            'allow_offline_time' => false,
            'notify_on_screenshot' => false,
            'week_starts_on' => 'monday',
            'currency_symbol' => '$',
            'desktop_auto_start' => false,
            'desktop_force_quit_on_idle' => false,
            'display_timezone' => 'Asia/Karachi',
            'time_format' => '12',
            'slack_clockin_enabled' => false,
            'slack_clockout_enabled' => false,
            'slack_attendance_channel' => null,
        ];
    }

    /**
     * The ACTIVE tenant's settings row.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * WHY UNBOUND NEVER TOUCHES THE DATABASE
     * ─────────────────────────────────────────────────────────────────────────
     * This used to firstOrCreate() on `organization_id = NULL` whenever nothing
     * was bound — a console command run without `tenant:run`, a guest request, a
     * terminating callback. That stray "landlord" row is not harmless: it is a
     * settings row nobody owns, nobody can see in any UI, and every unbound code
     * path then reads its toggles and its `slack_attendance_channel`. That is
     * exactly how a tenant's clock-in ended up announced in the OPERATOR's own
     * Slack workspace — the only thing standing in the way was the DEFAULT value
     * of a boolean column, and a prod install that predates tenancy already has
     * such a row with the toggles ON.
     *
     * So: unbound returns a THROW-AWAY, unsaved defaults instance. Reads keep
     * working everywhere they did before (week start, timezone, retention…),
     * nothing is created, and a pre-existing NULL-org row can no longer be read
     * back either. Writes unbound are a no-op by construction — Eloquent will
     * not update a model that does not exist — which is the correct outcome:
     * there is no workspace to write to.
     *
     * The tenant id is the BOUND one (boundId), not the query-scoping one, so a
     * lookup inside a runUnscoped() block still resolves the bound workspace's
     * own settings instead of silently degrading to the landlord row.
     */
    public static function current(): self
    {
        // One settings row per organization, memoized per org in the container
        // (NOT a function `static`, which would leak across requests/tests and
        // across orgs in a long-lived worker). The container is fresh per
        // request/test, and persists across the tenant:run loop where each org
        // has its own key — so this stays correct everywhere.
        $orgId = app(CurrentOrganization::class)->boundId();
        $key = 'monitoring_settings.current.'.($orgId ?? 0);

        if (app()->bound($key)) {
            return app($key);
        }

        if ($orgId === null) {
            // Unsaved, unpersisted, un-shared: read-only defaults for a context
            // that has no workspace. Deliberately NOT memoized in the container —
            // a later bound lookup must never be served this instance, and
            // nothing should be able to mutate a shared "nobody's settings".
            return static::make(static::defaults());
        }

        $row = static::firstOrCreate(
            ['organization_id' => $orgId],
            static::defaults()
        );

        app()->instance($key, $row);

        return $row;
    }

    /**
     * Resolve the merged setting payload for a specific user, applying any
     * per-user overrides that are switched on.
     *
     * @return array<string, mixed>
     */
    public function effectiveForUser(?User $user): array
    {
        $base = $this->teamPayload();

        if (! $user) {
            return $base;
        }

        $override = UserMonitoringSetting::firstWhere('user_id', $user->id);

        if (! $override) {
            return $base;
        }

        $groups = [
            'override_screenshots' => ['screenshots_per_hour', 'blur_screenshots', 'capture_enabled'],
            'override_activity' => ['activity_tracking_enabled'],
            'override_app_url' => ['app_url_tracking_enabled'],
            'override_weekly_limit' => ['weekly_time_limit_hours'],
            'override_auto_pause' => ['auto_pause_minutes'],
            'override_offline_time' => ['allow_offline_time'],
            'override_notify_screenshot' => ['notify_on_screenshot'],
            'override_desktop_app' => ['desktop_auto_start', 'desktop_force_quit_on_idle'],
            'override_display' => ['display_timezone', 'time_format'],
        ];

        foreach ($groups as $flag => $fields) {
            if (! $override->{$flag}) {
                continue;
            }

            foreach ($fields as $field) {
                if ($override->{$field} !== null) {
                    $base[$field] = $override->{$field};
                }
            }
        }

        return $base;
    }

    /** @return array<string, mixed> */
    public function teamPayload(): array
    {
        return [
            'screenshots_per_hour' => $this->screenshots_per_hour,
            'screenshot_interval_min_seconds' => $this->screenshot_interval_min_seconds,
            'screenshot_interval_max_seconds' => $this->screenshot_interval_max_seconds,
            'idle_threshold_seconds' => $this->idle_threshold_seconds,
            'activity_sample_interval_seconds' => $this->activity_sample_interval_seconds,
            'retention_days' => $this->retention_days,
            'blur_screenshots' => $this->blur_screenshots,
            'capture_enabled' => $this->capture_enabled,
            'require_active_window_metadata' => $this->require_active_window_metadata,
            'activity_tracking_enabled' => $this->activity_tracking_enabled,
            'app_url_tracking_enabled' => $this->app_url_tracking_enabled,
            'weekly_time_limit_hours' => $this->weekly_time_limit_hours,
            'auto_pause_minutes' => $this->auto_pause_minutes,
            'allow_offline_time' => $this->allow_offline_time,
            'manual_time_overlap_policy' => $this->manual_time_overlap_policy ?: 'warn',
            'notify_on_screenshot' => $this->notify_on_screenshot,
            'week_starts_on' => $this->week_starts_on,
            'currency_symbol' => $this->currency_symbol,
            'desktop_auto_start' => $this->desktop_auto_start,
            'desktop_force_quit_on_idle' => $this->desktop_force_quit_on_idle,
            'display_timezone' => $this->display_timezone ?: 'Asia/Karachi',
            'time_format' => $this->time_format ?: '12',
            'slack_clockin_enabled' => (bool) $this->slack_clockin_enabled,
            'slack_clockout_enabled' => (bool) $this->slack_clockout_enabled,
            'slack_attendance_channel' => $this->slack_attendance_channel,
            'slack_connected' => filled($this->slack_bot_token),
            'attendance_tracking_start_date' => $this->attendance_tracking_start_date?->toDateString(),
            'attendance_week_off_days' => $this->attendance_week_off_days ?? [0],
            'attendance_holidays' => $this->attendance_holidays ?? [],
            'attendance_auto_absent' => $this->attendance_auto_absent ?? true,
        ];
    }

    /**
     * The Slack channel attendance posts (clock-in/out) should go to: the
     * UI-managed channel if set, otherwise the env fallback. Null = nowhere.
     */
    public function attendanceChannel(): ?string
    {
        return $this->slack_attendance_channel
            ?: config('services.attendance.clockin_channel');
    }
}
