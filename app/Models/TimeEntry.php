<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimeEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'action_type',
        'action_timestamp',
        'action_date',
        'action_time',
        'notes',
    ];

    protected $casts = [
        'action_timestamp' => 'datetime',
        'action_date' => 'date',
        'action_time' => 'datetime:H:i:s',
    ];

    /**
     * Announce clock-ins and clock-outs to the attendance Slack channel, when
     * those posts are switched on in Settings. Fires for web, desktop, and any
     * other path that creates the entry. The post runs as a terminating
     * callback so it never adds latency to the clock action itself.
     *
     * System-generated clock-outs (the auto clock-out cap and the "still
     * working?" auto-close) are skipped here — those paths announce their own
     * worded lockout message, so re-posting would double up.
     */
    protected static function booted(): void
    {
        static::created(function (self $entry) {
            if (! in_array($entry->action_type, ['clock_in', 'clock_out'], true)) {
                return;
            }

            $settings = \App\Models\MonitoringSetting::current();
            $enabled = $entry->action_type === 'clock_in'
                ? $settings->slack_clockin_enabled
                : $settings->slack_clockout_enabled;
            if (! $enabled) {
                return;
            }

            $channel = $settings->attendanceChannel();
            if (! $channel) {
                return;
            }

            // Don't echo the system's own auto clock-outs.
            if ($entry->action_type === 'clock_out'
                && str_starts_with((string) $entry->notes, 'Auto clock-out')) {
                return;
            }

            $at = $entry->action_timestamp->copy()->setTimezone('Asia/Karachi');
            $name = $entry->user->name ?? 'Someone';
            $message = $entry->action_type === 'clock_in'
                ? sprintf(':office: *%s* clocked in at %s.', $name, $at->format('g:i A'))
                : sprintf(':waving_black_flag: *%s* clocked out at %s.%s', $name, $at->format('g:i A'), self::workedSuffix($entry));

            app()->terminating(function () use ($channel, $message) {
                try {
                    app(\App\Services\SlackBotService::class)->postToChannel($channel, $message);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Attendance Slack post failed', ['message' => $e->getMessage()]);
                }
            });
        });
    }

    /**
     * " (worked 8h 12m)" for a clock-out, computed from the matching clock-in
     * on the same attendance day, or '' when it can't be determined cheaply.
     */
    private static function workedSuffix(self $clockOut): string
    {
        $clockIn = static::where('user_id', $clockOut->user_id)
            ->where('action_type', 'clock_in')
            ->where('action_timestamp', '<=', $clockOut->action_timestamp)
            ->orderByDesc('action_timestamp')->orderByDesc('id')
            ->first();

        if (! $clockIn) {
            return '';
        }

        $mins = $clockIn->action_timestamp->diffInMinutes($clockOut->action_timestamp);
        if ($mins <= 0) {
            return '';
        }

        return $mins >= 60
            ? sprintf(' (worked %dh %dm)', intdiv($mins, 60), $mins % 60)
            : sprintf(' (worked %dm)', $mins);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForDate($query, $date)
    {
        return $query->whereDate('action_date', $date);
    }

    public function scopeRecent($query, $limit = 50)
    {
        return $query->orderBy('action_timestamp', 'desc')->limit($limit);
    }

    public function getFormattedActionTimeAttribute()
    {
        return $this->action_timestamp->setTimezone('Asia/Karachi')->format('g:i A');
    }

    public function getFormattedActionDateAttribute()
    {
        return $this->action_timestamp->setTimezone('Asia/Karachi')->format('M j, Y');
    }

    public function getFormattedActionTimestampAttribute()
    {
        return $this->action_timestamp->setTimezone('Asia/Karachi')->format('M j, Y g:i A');
    }
}
