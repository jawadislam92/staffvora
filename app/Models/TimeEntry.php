<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimeEntry extends Model
{
    use BelongsToOrganization;

    use HasFactory;

    protected $fillable = [
        'user_id',
        'action_type',
        'action_timestamp',
        'action_date',
        'action_time',
        'notes',
        // Slack thread anchor for the day's attendance thread (see AttendanceClockNotifier).
        'slack_thread_ts',
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
            // One Slack THREAD per person per day: the day's first clock-in is
            // the parent, breaks and the clock-out reply underneath it, instead
            // of four separate posts cluttering the channel. All the decisions
            // (enabled? channel? system-generated punch?) live in the notifier.
            app()->terminating(function () use ($entry) {
                try {
                    app(\App\Services\AttendanceClockNotifier::class)->notify($entry);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Attendance Slack post failed', ['message' => $e->getMessage()]);
                }
            });
        });
    }


    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * The user's CURRENT clock state: the action_type of their globally most
     * recent entry, regardless of attendance day. The clock sequence must be
     * enforced against this (not against today's entries only) so a clock-in
     * left open last night still blocks a new clock-in after midnight — you
     * can't clock in while already clocked in. Returns null if they've never
     * clocked anything (then a clock-in is the only allowed action).
     *
     * Org-scoped automatically via the BelongsToOrganization global scope, so
     * this only ever sees the active tenant's rows for this user.
     */
    public static function currentClockState(int $userId): ?string
    {
        return static::where('user_id', $userId)
            ->orderByDesc('action_timestamp')->orderByDesc('id')
            ->value('action_type');
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
        return $this->action_timestamp->setTimezone(\App\Support\BusinessTime::tz())->format('g:i A');
    }

    public function getFormattedActionDateAttribute()
    {
        return $this->action_timestamp->setTimezone(\App\Support\BusinessTime::tz())->format('M j, Y');
    }

    public function getFormattedActionTimestampAttribute()
    {
        return $this->action_timestamp->setTimezone(\App\Support\BusinessTime::tz())->format('M j, Y g:i A');
    }
}
