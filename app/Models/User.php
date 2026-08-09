<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use App\Services\BillingPricing;
use App\Services\DemoDataService;
use App\Support\BusinessTime;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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
        // NOT fillable — see the DEMO IDENTITIES block below. The cast is here
        // so `is_demo` reads as a real boolean everywhere; the VALUE is stamped
        // by the model itself and can never arrive from a request.
        'is_demo' => 'boolean',
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
    // customer could mint a user the exclusion hides, they would mint
    // themselves unlimited free seats.
    //
    // 🔴 THE MARKER IS THE `is_demo` COLUMN — NEVER THE E-MAIL ADDRESS.
    //
    // It used to be the address, and that cost us production. The refusal was
    // byte-exact PHP (`str_ends_with(strtolower($email), '@demo…invalid')`)
    // while the seat EXCLUSION was SQL `LIKE`, evaluated in the column's
    // collation. MySQL's `utf8mb4_unicode_ci` folds case AND accents, so
    // `demo-9-1@demo.staffvora.İnvalid` (U+0130) was REFUSED BY NEITHER: PHP
    // saw a different byte string and let it through, SQL folded it onto the
    // reserved domain and hid it from the seat count. Eight ordinary
    // `POST /users` requests produced nine active, login-capable users on a
    // two-seat plan while seatUsage() and billableSeats() both reported 1.
    // SQLite (this suite) does not fold, so the tests could not see it.
    //
    // A security decision must never depend on how the database compares
    // strings. So demo identity is now an explicit boolean FACT:
    //   • it is stamped by this model, in the creating event, from
    //     {@see asDemoIdentityWriter()} — server-side code with no
    //     request-reachable entry point;
    //   • EVERY other create is forced to `false`, whatever the request body,
    //     the factory or a mass assignment says;
    //   • it can never be changed on an existing row from outside the writer;
    //   • the guard and the exclusion both read THAT column, so they cannot
    //     disagree on any database, in any collation, for any encoding.
    //
    // The address shape `demo-<orgId>-<n>@demo.staffvora.invalid` survives only
    // as a COSMETIC LABEL (and the namespace stays refused so nobody can squat
    // on it or confuse a support conversation). `.invalid` is reserved by
    // RFC 2606 and can never be delivered to, so refusing it costs nothing —
    // but no seat, billing or access decision is taken from it any more.

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
     * Does this address LOOK like sample data? Matched on the exact shape the
     * service mints.
     *
     * ⚠️ COSMETIC ONLY. No seat, billing or access decision may be taken from
     * this or from {@see isReservedDemoEmail()} — that is what the `is_demo`
     * column is for. An address predicate is a string comparison, and a string
     * comparison is exactly what the database and PHP were caught disagreeing
     * about (see the block above).
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
     * The attributes of a sample-data row that a customer may NEVER rewrite.
     *
     * Deliberately not "every column": cosmetic fields (name, designation,
     * shift, team…) stay writable so a bulk edit or some future feature that
     * happens to save a demo row does not explode. What is frozen is exactly
     * the set that decides IDENTITY, ACCESS and TENANCY — i.e. everything that
     * could turn a free, sign-in-incapable placeholder into a real account.
     *
     * @var array<int, string>
     */
    private const DEMO_IMMUTABLE_ATTRIBUTES = [
        'email',
        'password',
        'remember_token',
        'role',
        'permissions',
        'is_platform_admin',
        'organization_id',
        'is_demo',
    ];

    /**
     * Stamp the demo marker on create, and freeze it thereafter.
     *
     * The stamp is the whole point: `is_demo` is not read from the attributes
     * the caller supplied, it is OVERWRITTEN from the writer flag. So a request
     * body, a `User::create([... 'is_demo' => true])`, a factory state or a
     * `forceFill` cannot mint a free seat — only code running inside
     * {@see asDemoIdentityWriter()} can, and nothing reachable from HTTP is.
     */
    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            $user->stampDemoIdentity();
            $user->guardDemoIdentity();
        });

        static::updating(function (self $user): void {
            // Direction 0 — the MARKER itself. Flipping it on mints a free seat;
            // flipping it off launders a free row into a real one. Neither is
            // anyone's business but DemoDataService's.
            $user->guardDemoMarkerWrite();

            // Direction 1 — INTO the reserved namespace. No longer a seat
            // decision (that is `is_demo`), but the label stays reserved so
            // nobody can squat on it or make support unreadable.
            if ($user->isDirty('email')) {
                $user->guardDemoIdentity();
            }

            // Direction 2 — OUT of it: launders a free row into a real one.
            $user->guardDemoIdentityImmutable();
        });
    }

    /**
     * A new row is sample data if and only if DemoDataService is writing it.
     * Unconditional assignment, so whatever the caller passed is discarded.
     */
    private function stampDemoIdentity(): void
    {
        $this->is_demo = self::$demoIdentityWrites;
    }

    /** The marker may only ever change inside {@see asDemoIdentityWriter()}. */
    private function guardDemoMarkerWrite(): void
    {
        if (self::$demoIdentityWrites || ! $this->isDirty('is_demo')) {
            return;
        }

        throw new \RuntimeException(
            'The sample-data marker is owned by demo mode and cannot be set or cleared.'
        );
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

    /**
     * Sample-data rows belong to DemoDataService, not to the customer.
     *
     * Guarding only the "into the namespace" direction was not enough: a demo
     * teammate is a real `users` row that is merely EXCLUDED from
     * {@see seatUsage()} by its address, so rewriting that address (and its
     * password) the other way converted a free row into a real, counted,
     * LOGIN-CAPABLE account. Worse, {@see DemoDataService::isLoaded()}
     * keys on the same address, so once renamed the workspace read as "no demo
     * loaded" and could mint another free cast — repeatedly. That was proven to
     * eight real users on a two-seat plan.
     *
     * The guard lives HERE rather than in the one controller that did it,
     * because every write path (admin edit, bulk edit, console command, a
     * feature written next year) goes through Eloquent's updating event, and
     * only {@see asDemoIdentityWriter()} — which no request can reach — is
     * allowed through.
     */
    private function guardDemoIdentityImmutable(): void
    {
        // Keyed on the stored MARKER, not on the stored address: the address is
        // a label and a label is forgeable, a column written only by
        // asDemoIdentityWriter() is not.
        if (self::$demoIdentityWrites || ! $this->wasStoredAsDemoIdentity()) {
            return;
        }

        $frozen = array_values(array_filter(
            self::DEMO_IMMUTABLE_ATTRIBUTES,
            fn (string $attribute) => $this->isDirty($attribute)
        ));

        if ($frozen === []) {
            return;
        }

        throw new \RuntimeException(
            'Sample-data teammates are reserved for demo mode and their identity cannot be changed ('
            .implode(', ', $frozen).'). Remove the sample data instead.'
        );
    }

    /**
     * Is THIS row one of DemoDataService's sample-data teammates?
     *
     * Reads the marker in BOTH the pending and the stored state, so an
     * in-flight edit cannot answer "no" for a row that is demo on disk.
     */
    public function isDemoIdentity(): bool
    {
        return (bool) ($this->is_demo ?? false) || $this->wasStoredAsDemoIdentity();
    }

    /** The marker AS STORED, untouched by whatever the current request set. */
    private function wasStoredAsDemoIdentity(): bool
    {
        return $this->exists && (bool) $this->getRawOriginal('is_demo');
    }

    /**
     * Sample-data teammates in the current organization.
     *
     * A column comparison, deliberately: the old `LIKE '%@demo…invalid'` was
     * evaluated in the connection's collation, and `utf8mb4_unicode_ci` folded
     * accented forgeries onto the reserved domain — hiding rows the PHP guard
     * had happily accepted. Booleans compare the same on every engine.
     */
    public function scopeDemoIdentities($query)
    {
        return $query->where('is_demo', true);
    }

    /**
     * Everyone except sample-data teammates — THE seat exclusion. It reads the
     * same column the guard writes, so the set it hides and the set the guard
     * refuses can never drift apart on a database that compares strings
     * differently from PHP. {@see SeatMarkerCannotBeForgedTest}
     */
    public function scopeExcludingDemoIdentities($query)
    {
        return $query->where('is_demo', false);
    }

    /**
     * THE SEAT DEFINITION. One predicate, used by BOTH counters.
     *
     * A seat is an ACTIVE, REAL person:
     *   • sample-data teammates are excluded — the product tells the customer
     *     in two places that they are free, and the plan-limit counter agreed,
     *     but the BILLING counter used to count them, so clicking "Load sample
     *     data" quietly added three seats to the invoice and to every per-seat
     *     add-on line. Charging for something we called free is not a rounding
     *     error, it is a refund.
     *   • deactivated people (who left the company) keep their history but can
     *     neither sign in nor track, so they are neither billed nor counted
     *     against the plan.
     *
     * Both {@see seatUsage()} (the plan limit) and
     * {@see BillingPricing::billableSeats()} (the invoice) MUST
     * stay built from this scope. When they disagree the customer is either
     * over-charged or shown a ceiling that has nothing to do with their bill —
     * SeatCountersAgreeTest exists to make a future divergence fail loudly.
     */
    public function scopeSeatConsuming($query)
    {
        return $query->excludingDemoIdentities()->where('is_active', true);
    }

    /**
     * Seats consumed in the current organization — the ONE number every plan
     * seat check must use. See {@see scopeSeatConsuming()} for what counts and
     * why; see the note above for why the demo exclusion cannot be forged.
     *
     * Org-scoped by OrganizationScope, so the `not like` runs over one tenant's
     * roster (~100 rows), never the whole users table.
     */
    public static function seatUsage(): int
    {
        return static::query()->seatConsuming()->count();
    }

    /**
     * The same number for an EXPLICIT organization, for code that runs outside
     * a tenant request (the billing hub prices a workspace an operator is
     * looking at). Deliberately the same scope — a second, subtly different
     * COUNT(*) is exactly how the billing and limit counters drifted apart.
     */
    public static function seatUsageFor(int $organizationId): int
    {
        return static::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId)
            ->seatConsuming()
            ->count();
    }

    /**
     * Run $callback with a seat RESERVED — the seat check and the write that
     * consumes it happen inside one transaction, behind one lock.
     *
     * The old shape was a textbook check-then-act race: fifty concurrent
     * `POST /users` all ran `seatUsage() >= $limit` against the same stale
     * count, all fifty passed, and a two-seat plan ended up with fifty-one
     * users. Every writer now queues on the SAME `organizations` row, so the
     * second request only reads the count AFTER the first request's user row
     * is in it.
     *
     * Engine parity: `lockForUpdate()` compiles to `FOR UPDATE` on MySQL
     * (prod) and to an empty string on SQLite (tests) — SQLite serialises
     * writers at the database level, so the invariant holds on both without
     * two code paths. DO NOT "simplify" the lock away because the test suite
     * cannot observe it; SQLite is why it looks redundant.
     *
     * Unlimited plans take no lock at all: there is nothing to serialise, and
     * an unlimited workspace must not funnel every signup through one row.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws ValidationException when the plan is full
     */
    public static function withSeatGuard(
        ?Organization $organization,
        \Closure $callback,
        string $message = 'Your plan includes up to :limit users. Upgrade your plan to add more.',
        string $field = 'email',
    ) {
        if ($organization === null || $organization->seatLimit() === null) {
            return $callback();
        }

        return DB::transaction(function () use ($organization, $callback, $message, $field) {
            // The serialisation point. Reading the row for update is what makes
            // the count below authoritative rather than a snapshot.
            Organization::query()
                ->withoutGlobalScopes()
                ->whereKey($organization->getKey())
                ->lockForUpdate()
                ->first();

            $limit = $organization->seatLimit();

            if ($limit !== null && static::seatUsage() >= $limit) {
                throw ValidationException::withMessages([
                    $field => str_replace(':limit', (string) $limit, $message),
                ]);
            }

            return $callback();
        });
    }

    /**
     * How many more seats this workspace may light up right now; null =
     * unlimited. Callers that reactivate people in BULK need the headroom
     * rather than a yes/no, so they can fill it and report the remainder
     * instead of failing the whole batch.
     */
    public static function seatHeadroom(?Organization $organization): ?int
    {
        $limit = $organization?->seatLimit();

        return $limit === null ? null : max(0, $limit - static::seatUsage());
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
        $localTimestamp = $timestamp->copy()->setTimezone(BusinessTime::tz());
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

    /**
     * Send the branded password-reset mail instead of the framework's plain
     * default. The token/broker machinery — the part the security audit
     * hardened — is completely untouched: this only swaps which Notification
     * class renders the mail. See {@see \App\Notifications\ResetPasswordNotification}
     * for why that class stays synchronous (never queued).
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\ResetPasswordNotification($token));
    }
}
