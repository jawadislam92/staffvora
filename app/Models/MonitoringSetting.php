<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class MonitoringSetting extends Model
{
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
        'branding_logo_path',
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
    ];

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

    public static function current(): self
    {
        // Memoized per request: weekStartDay()/weekEndDay() are called inside
        // loops all over the dashboard/report code, and each current() call
        // was a separate firstOrCreate query.
        return once(fn () => static::firstOrCreate(
            ['id' => 1],
            [
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
            ]
        ));
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
