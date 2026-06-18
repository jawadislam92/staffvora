<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'permissions',
        'include_in_slack_reports',
        'avatar',
        'designation',
        'joining_date',
        'shift_start_time',
        'shift_grace_minutes',
        'shift_hours',
        'clockout_reminder_hours',
        'allow_multiple_devices',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'permissions',
    ];

    /**
     * Serialized users always carry a resolvable avatar URL; the raw path is
     * only meaningful server-side (the avatars disk may be local or S3).
     *
     * @var array<int, string>
     */
    protected $appends = [
        'avatar_url',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'permissions' => 'array',
        'include_in_slack_reports' => 'boolean',
        'joining_date' => 'date',
        'shift_start_time' => 'datetime:H:i',
        'shift_grace_minutes' => 'integer',
        'shift_hours' => 'decimal:2',
        'clockout_reminder_hours' => 'decimal:2',
        'allow_multiple_devices' => 'boolean',
    ];

    const ROLES = [
        'super_admin' => 'Super Admin',
        'admin' => 'Admin',
        'member' => 'Member',
    ];

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return in_array($permission, $this->expandedPermissions(), true);
    }

    public function hasAnyPermission(array $permissions): bool
    {
        return $this->isSuperAdmin()
            || collect($permissions)->contains(fn (string $permission) => $this->hasPermission($permission));
    }

    public function effectivePermissions(): array
    {
        if ($this->isSuperAdmin()) {
            return collect(config('access.permissions'))
                ->flatMap(fn (array $group) => array_keys($group))
                ->values()
                ->all();
        }

        return $this->expandedPermissions();
    }

    public function getRoleLabelAttribute(): string
    {
        return self::ROLES[$this->role] ?? ucfirst(str_replace('_', ' ', $this->role));
    }

    private function expandedPermissions(): array
    {
        $validPermissions = collect(config('access.permissions'))
            ->flatMap(fn (array $group) => array_keys($group))
            ->all();
        $assigned = array_values(array_intersect($this->permissions ?? [], $validPermissions));

        $implications = [
            'users.manage' => ['users.view'],
            'users.delete' => ['users.view'],
            'clients.manage' => ['clients.view'],
            'clients.import_export' => ['clients.view'],
            'profiles.manage' => ['profiles.view'],
            'attendance.manual_mark' => ['attendance.view'],
            'attendance.export' => ['attendance.view'],
            'reports.export' => ['reports.view'],
            'reports.send_slack' => ['reports.view'],
            'monitoring.manage' => ['monitoring.view'],
            'monitoring.view_screenshots' => ['monitoring.view'],
            'monitoring.delete_screenshots' => ['monitoring.view', 'monitoring.view_screenshots'],
            'monitoring.settings' => ['monitoring.view'],
            'timeline.view_others' => [],
        ];

        foreach ($assigned as $permission) {
            $assigned = [...$assigned, ...($implications[$permission] ?? [])];
        }

        return array_values(array_unique($assigned));
    }

    /**
     * Get the avatar URL attribute.
     *
     * On S3 this is a short-lived signed URL (objects are private); on the
     * local disk it is the public /storage path.
     */
    public function getAvatarUrlAttribute()
    {
        if (! $this->avatar) {
            return null;
        }

        // Stable, cacheable proxy route (see AvatarController). The ?v hash
        // changes only when the avatar file changes, so the browser caches
        // each image for a week instead of re-fetching a fresh presigned S3
        // URL on every page render.
        return route('avatar.show', ['user' => $this->id, 'v' => substr(md5($this->avatar), 0, 8)]);
    }

    /**
     * Get the work hours for the user.
     */
    public function workHours()
    {
        return $this->hasMany(WorkHour::class);
    }

    /**
     * Get the time entries for the user.
     */
    public function timeEntries()
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * Clocking in up to this many minutes before a shift starts counts toward
     * that shift's attendance day. Without it, an early arrival for a shift
     * starting at/after midnight (e.g. 23:50 for a 00:00 shift) lands on the
     * previous calendar day and "disappears" when the date rolls over.
     */
    public const EARLY_CLOCK_IN_GRACE_MINUTES = 240;

    public function attendanceDateFor(Carbon $timestamp): string
    {
        $localTimestamp = $timestamp->copy()->setTimezone('Asia/Karachi');

        if (! $this->shift_start_time) {
            return $localTimestamp->toDateString();
        }

        $shiftTime = $this->shift_start_time->format('H:i:s');
        $todayShiftStart = $localTimestamp->copy()->startOfDay()->setTimeFromTimeString($shiftTime);

        // Early arrival for the NEXT day's shift. Only reachable late in the
        // evening for shifts that start around midnight; day shifts never get
        // within the grace window of tomorrow's start.
        $tomorrowShiftStart = $todayShiftStart->copy()->addDay();
        $minutesUntilTomorrowStart = $localTimestamp->diffInMinutes($tomorrowShiftStart, false);

        if ($minutesUntilTomorrowStart >= 0 && $minutesUntilTomorrowStart <= self::EARLY_CLOCK_IN_GRACE_MINUTES) {
            return $tomorrowShiftStart->toDateString();
        }

        if ($localTimestamp->greaterThanOrEqualTo($todayShiftStart)) {
            return $localTimestamp->toDateString();
        }

        $previousShiftStart = $todayShiftStart->copy()->subDay();
        $minutesSincePreviousStart = $previousShiftStart->diffInMinutes($localTimestamp, false);

        return $minutesSincePreviousStart >= 0 && $minutesSincePreviousStart <= 12 * 60
            ? $localTimestamp->copy()->subDay()->toDateString()
            : $localTimestamp->toDateString();
    }
}
