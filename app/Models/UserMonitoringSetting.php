<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserMonitoringSetting extends Model
{
    protected $fillable = [
        'user_id',
        'override_screenshots',
        'override_activity',
        'override_app_url',
        'override_weekly_limit',
        'override_auto_pause',
        'override_offline_time',
        'override_notify_screenshot',
        'override_desktop_app',
        'override_display',
        'screenshots_per_hour',
        'blur_screenshots',
        'capture_enabled',
        'activity_tracking_enabled',
        'app_url_tracking_enabled',
        'weekly_time_limit_hours',
        'auto_pause_minutes',
        'allow_offline_time',
        'notify_on_screenshot',
        'desktop_auto_start',
        'desktop_force_quit_on_idle',
        'display_timezone',
        'time_format',
    ];

    protected $casts = [
        'override_screenshots' => 'boolean',
        'override_activity' => 'boolean',
        'override_app_url' => 'boolean',
        'override_weekly_limit' => 'boolean',
        'override_auto_pause' => 'boolean',
        'override_offline_time' => 'boolean',
        'override_notify_screenshot' => 'boolean',
        'override_desktop_app' => 'boolean',
        'override_display' => 'boolean',
        'screenshots_per_hour' => 'integer',
        'blur_screenshots' => 'boolean',
        'capture_enabled' => 'boolean',
        'activity_tracking_enabled' => 'boolean',
        'app_url_tracking_enabled' => 'boolean',
        'weekly_time_limit_hours' => 'integer',
        'auto_pause_minutes' => 'integer',
        'allow_offline_time' => 'boolean',
        'notify_on_screenshot' => 'boolean',
        'desktop_auto_start' => 'boolean',
        'desktop_force_quit_on_idle' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
