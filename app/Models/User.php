<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

/**
 * NOT MustVerifyEmail — deliberately, and the `verified` middleware has been
 * removed from routes/web.php to stop the codebase advertising a control it
 * does not enforce. Laravel's EnsureEmailIsVerified is a silent no-op unless
 * the authenticatable implements MustVerifyEmail, so every route decorated
 * `verified` was unprotected while reading as protected — the worst of both
 * worlds, because it stops anyone looking closer.
 *
 * Implementing the interface would NOT have closed the abuse the audit cites.
 * Tenant creation and every expensive grant (organization + trialing
 * subscription + AI/monitoring/integrations entitlements + S3 storage) happen
 * in RegisteredUserController -> TenantProvisioner, inside the `guest` route
 * group, and the onboarding wizard is `auth`-only. All of that sits OUTSIDE
 * the `verified` gate, so an unverified attacker still gets a fully
 * provisioned, billable tenant; verification would only have blocked them from
 * opening the dashboard afterwards.
 *
 * Turning verification on for real is a sequenced piece of work that must land
 * together, and three of its four steps are outside this file:
 *   1. backfill email_verified_at for existing rows (nothing here stamps it);
 *   2. gate the grants in TenantProvisioner::provision so an unverified owner's
 *      workspace is inert rather than merely unusable — this is the actual fix;
 *   3. decide ProfileController::update's re-verify-on-email-change behaviour
 *      (it nulls email_verified_at today and sends nothing);
 *   4. confirm production SMTP genuinely delivers before flipping — the
 *      production env template still points MAIL_HOST at a sandbox host.
 * Step 1 for admin-created accounts is already done (UserController::store
 * stamps them verified, since the admin vouches for the address).
 */
class User extends Authenticatable
{
    use BelongsToOrganization;

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
        'shift_id',
        'clockout_reminder_hours',
        'allow_multiple_devices',
        'team_id',
        'is_platform_admin',
        'theme',
        'is_active',
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
        'is_platform_admin' => 'boolean',
        'is_active' => 'boolean',
    ];

    const ROLES = [
        'super_admin' => 'Super Admin',
        'admin' => 'Admin',
        'member' => 'Member',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // DEMO IDENTITIES — the sample-data marker, and why it is UNFORGEABLE
    // ─────────────────────────────────────────────────────────────────────────
    //
    // Sample-data teammates ({@see \App\Services\DemoDataService}) are real
    // `users` rows, so without help they occupy plan seats and a loaded demo
    // could block a real hire. Seat checks therefore EXCLUDE them
    // ({@see seatUsage()}).
    //
    // That exclusion is a plan-limit bypass, i.e. an abuse surface: if a
    // customer could mint a user whose address matches the marker, they would
    // mint themselves unlimited free seats. So the marker is closed at the
    // MODEL layer, not at any one controller — every write path (admin create,
    // onboarding invite, self-registration, profile e-mail change, console
    // command, factory, mass assignment) goes through Eloquent's creating /
    // updating events, and all of them are refused here. The ONLY way to write
    // one is {@see asDemoIdentityWriter()}, which is server-side code with no
    // request-reachable entry point.
    //
    // The address shape is `demo-<orgId>-<n>@demo.staffvora.invalid`. `.invalid`
    // is reserved by RFC 2606 and can never be delivered to or registered, so
    // refusing it costs no real customer anything.

    /** RFC 2606 reserved domain. Canonical home of the marker. */
    public const DEMO_EMAIL_DOMAIN = 'demo.staffvora.invalid';

    /** Left-anchored prefix, so the marker query can use the e-mail index. */
    public const DEMO_EMAIL_PREFIX = 'demo-';

    /**
     * True only while DemoDataService is writing its own rows. Never set from a
     * request path; always restored in a finally block.
     */
    private static bool $demoIdentityWrites = false;

    /**
     * Run $callback with permission to write demo-identity e-mails. The ONLY
     * legitimate caller is DemoDataService. Restores the guard even if the
     * callback throws, so one failed load cannot leave the door open for the
     * rest of the process (queue workers are long-lived).
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function asDemoIdentityWriter(callable $callback)
    {
        $previous = self::$demoIdentityWrites;
        self::$demoIdentityWrites = true;

        try {
            return $callback();
        } finally {
            self::$demoIdentityWrites = $previous;
        }
    }

    /**
     * Does this address claim to be sample data? Matched on the exact shape the
     * service mints, because this is the predicate the SEAT EXCLUSION keys on —
     * it must describe precisely the rows that are excluded, no more.
     */
    public static function isDemoIdentityEmail(?string $email): bool
    {
        if (! is_string($email) || $email === '') {
            return false;
        }

        return (bool) preg_match(
            '/^'.preg_quote(self::DEMO_EMAIL_PREFIX, '/').'\d+-\d+@'.preg_quote(self::DEMO_EMAIL_DOMAIN, '/').'$/i',
            trim($email)
        );
    }

    /**
     * Is this anywhere in the reserved domain? Deliberately BROADER than
     * {@see isDemoIdentityEmail}: what gets refused is the whole namespace, so
     * there is no near-miss address a customer can probe with, and no way to
     * squat on one the service might mint later. RFC 2606 guarantees no real
     * person can be reached at `.invalid`, so nothing legitimate is lost.
     */
    public static function isReservedDemoEmail(?string $email): bool
    {
        if (! is_string($email) || $email === '') {
            return false;
        }

        return str_ends_with(strtolower(trim($email)), '@'.self::DEMO_EMAIL_DOMAIN);
    }

    /**
     * Validation rule for any customer-supplied e-mail address. The model guard
     * below is the floor that cannot be bypassed; this exists so the common
     * paths answer with a readable form error instead of an exception.
     */
    public static function reservedEmailRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (self::isReservedDemoEmail(is_string($value) ? $value : null)) {
                $fail('That domain is reserved for Staffvora sample data. Please use a real email address.');
            }
        };
    }

    /**
     * Refuse any attempt to create or rename a user into the reserved demo
     * namespace. This is what makes the seat exclusion safe to rely on.
     */
    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            $user->guardDemoIdentity();
        });

        static::updating(function (self $user): void {
            if ($user->isDirty('email')) {
                $user->guardDemoIdentity();
            }
        });
    }

    private function guardDemoIdentity(): void
    {
        if (self::$demoIdentityWrites || ! self::isReservedDemoEmail($this->email)) {
            return;
        }

        throw new \RuntimeException(
            'The '.self::DEMO_EMAIL_DOMAIN.' address space is reserved for sample data and cannot be assigned.'
        );
    }

    /** Sample-data teammates in the current organization. */
    public function scopeDemoIdentities($query)
    {
        return $query
            ->where('email', 'like', self::DEMO_EMAIL_PREFIX.'%')
            ->where('email', 'like', '%@'.self::DEMO_EMAIL_DOMAIN);
    }

    /** Everyone except sample-data teammates. */
    public function scopeExcludingDemoIdentities($query)
    {
        return $query->where(function ($q) {
            $q->where('email', 'not like', self::DEMO_EMAIL_PREFIX.'%')
                ->orWhere('email', 'not like', '%@'.self::DEMO_EMAIL_DOMAIN);
        });
    }

    /**
     * Seats consumed in the current organization — the ONE number every plan
     * seat check must use. Sample-data teammates are excluded so loading a demo
     * can never block a real hire; see the note above for why that exclusion
     * cannot be forged.
     *
     * Org-scoped by OrganizationScope, so the `not like` runs over one tenant's
     * roster (~100 rows), never the whole users table.
     */
    public static function seatUsage(): int
    {
        return static::query()->excludingDemoIdentities()->count();
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    /** The SaaS operator who manages every tenant from the platform console. */
    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_platform_admin;
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

    /**
     * Active (not deactivated) users. Deactivated users — people who left the
     * company — are hidden from active directory / assignment / performance
     * views and blocked from logging in and tracking, but keep all their
     * history. Tenant scoping is handled by the OrganizationScope, so this
     * only ever narrows within the current organization.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Deactivated (archived) users only — the "Archived" directory tab. */
    public function scopeArchived($query)
    {
        return $query->where('is_active', false);
    }

    /**
     * Single source of truth for "may this user use the app?". Null-safe so
     * rows created before the is_active column (and the default true) read as
     * active — and so a stray direct ->is_active read can't bypass the gate.
     */
    public function isActive(): bool
    {
        return (bool) ($this->is_active ?? true);
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
            'compliance.manage' => ['compliance.view'],
            'timesheets.approve_own' => ['timesheets.approve'],
            'timesheets.reopen' => ['timesheets.approve'],
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
     * The team this user belongs to (within their organization), if any.
     */
    public function team()
    {
        return $this->belongsTo(Team::class);
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
     * The curated shift (Morning / Noon / Evening / Night / …) this person is
     * assigned to — primarily a filter/grouping LABEL, but it may also carry the
     * timing that drives their schedule. Nullable. {@see Shift}
     */
    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    /** One-day shift changes for this user. {@see ShiftOverride} */
    public function shiftOverrides()
    {
        return $this->hasMany(ShiftOverride::class);
    }

    /** Convenience for payloads/tables: the assigned shift's name, or null. */
    public function getShiftNameAttribute(): ?string
    {
        return $this->shift?->name;
    }

    /**
     * The effective shift for an attendance date. Single source of truth for
     * every shift consumer — day bucketing, auto-close, "still working?" nudges
     * and late detection all read this, so a per-day change applies everywhere
     * at once.
     *
     * Resolution order, most specific first:
     *   1. a one-day ShiftOverride for $date (per column — a row that only sets
     *      a start time leaves the length alone, and vice versa);
     *   2. the assigned named Shift's timing, when that shift defines any;
     *   3. the user's own shift_start_time / shift_hours columns — the original
     *      behaviour, and still the fallback for everyone with no named shift.
     *
     * @return array{start_time: ?Carbon, hours: ?float, grace_minutes: ?int}
     */
    public function effectiveShiftFor(string|Carbon $date): array
    {
        $dateStr = $date instanceof Carbon ? $date->toDateString() : (string) $date;

        // Only touch the shift relation when one is actually assigned, so users
        // without a named shift cost no extra query.
        $shift = $this->shift_id ? $this->shift : null;

        $startTime = ($shift && $shift->start_time) ? $shift->start_time : $this->shift_start_time;
        $hours = ($shift && $shift->hours !== null)
            ? (float) $shift->hours
            : ($this->shift_hours !== null ? (float) $this->shift_hours : null);
        $graceMinutes = ($shift && $shift->grace_minutes !== null)
            ? (int) $shift->grace_minutes
            : ($this->shift_grace_minutes !== null ? (int) $this->shift_grace_minutes : null);

        // Property access lazy-loads the overrides once and caches them on the
        // instance, so the repeated calls in attendanceDateFor() stay in-memory.
        $override = $this->shiftOverrides->first(
            fn ($o) => optional($o->date)->toDateString() === $dateStr
        );

        if ($override) {
            if ($override->shift_start_time) {
                $startTime = $override->shift_start_time;
            }
            if ($override->shift_hours !== null) {
                $hours = (float) $override->shift_hours;
            }
        }

        return ['start_time' => $startTime, 'hours' => $hours, 'grace_minutes' => $graceMinutes];
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
        $localTimestamp = $timestamp->copy()->setTimezone(\App\Support\BusinessTime::tz());
        $calendarDate = $localTimestamp->toDateString();

        // Effective shift start for the day this timestamp falls on: a one-day
        // override wins, then the assigned named shift, then the user's own
        // shift_start_time column. With no shift set at all, the attendance day
        // is just the calendar day (unchanged behaviour).
        $startTime = $this->effectiveShiftFor($calendarDate)['start_time'];

        if (! $startTime) {
            return $calendarDate;
        }

        $todayShiftStart = $localTimestamp->copy()->startOfDay()
            ->setTimeFromTimeString($startTime->format('H:i:s'));

        // Early arrival for the NEXT day's shift. Only reachable late in the
        // evening for shifts that start around midnight; day shifts never get
        // within the grace window of tomorrow's start. Measured against
        // tomorrow's OWN effective start in case that day has its own override.
        $tomorrowStart = $this->effectiveShiftFor($localTimestamp->copy()->addDay()->toDateString())['start_time'] ?? $startTime;
        $tomorrowShiftStart = $localTimestamp->copy()->addDay()->startOfDay()
            ->setTimeFromTimeString($tomorrowStart->format('H:i:s'));
        $minutesUntilTomorrowStart = $localTimestamp->diffInMinutes($tomorrowShiftStart, false);

        if ($minutesUntilTomorrowStart >= 0 && $minutesUntilTomorrowStart <= self::EARLY_CLOCK_IN_GRACE_MINUTES) {
            return $tomorrowShiftStart->toDateString();
        }

        if ($localTimestamp->greaterThanOrEqualTo($todayShiftStart)) {
            return $calendarDate;
        }

        // Late-night spillover belonging to the previous day's shift.
        $previousStart = $this->effectiveShiftFor($localTimestamp->copy()->subDay()->toDateString())['start_time'] ?? $startTime;
        $previousShiftStart = $localTimestamp->copy()->subDay()->startOfDay()
            ->setTimeFromTimeString($previousStart->format('H:i:s'));
        $minutesSincePreviousStart = $previousShiftStart->diffInMinutes($localTimestamp, false);

        return $minutesSincePreviousStart >= 0 && $minutesSincePreviousStart <= 12 * 60
            ? $localTimestamp->copy()->subDay()->toDateString()
            : $calendarDate;
    }
}
