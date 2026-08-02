<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ONE person's time for ONE pay period, as it moves through approval.
 *
 *      open ──submit──▶ submitted ──approve──▶ approved ──reopen──▶ open
 *                            │
 *                            └───reject───▶ rejected ──submit──▶ submitted
 *
 * A row only exists once the period has entered that machine. "Open" is
 * therefore the ABSENCE of a row as much as it is a stored status, and both
 * read identically to callers (see `statusFor()`), so a workspace with 500
 * people and three years of history stores rows for what was actually reviewed
 * and nothing else.
 *
 * The row snapshots its own period (cadence + start + end) because the cadence
 * is a live setting: switching a workspace from weekly to monthly must not
 * retroactively re-shape a timesheet that was already approved and paid.
 *
 * THE LOCK. `approved` is the whole point of the feature — payroll needs a
 * frozen number. Approving stamps `work_hours.timesheet_id` for every entry in
 * the period (one indexed UPDATE), and WorkHour::isApprovalLocked() reads that
 * column, so no list view pays a query to find out whether a row is editable.
 * Reopening clears the stamp and is audited like every other transition.
 */
class Timesheet extends Model
{
    use BelongsToOrganization;

    public const STATUS_OPEN = 'open';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_SUBMITTED, self::STATUS_APPROVED, self::STATUS_REJECTED];

    protected $fillable = [
        'user_id',
        'period_key',
        'cadence',
        'period_start',
        'period_end',
        'status',
        'submitted_at',
        'submitted_by',
        'reviewed_at',
        'reviewed_by',
        'locked_at',
        'note',
        'total_hours',
        'billable_hours',
        'tracked_hours',
        'manual_hours',
        'entries_count',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'locked_at' => 'datetime',
        'total_hours' => 'float',
        'billable_hours' => 'float',
        'tracked_hours' => 'float',
        'manual_hours' => 'float',
        'entries_count' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approvals()
    {
        return $this->hasMany(TimesheetApproval::class);
    }

    // ── State ─────────────────────────────────────────────────────────────────

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    /** Approved = frozen. Nothing inside the period may be edited or deleted. */
    public function locksTime(): bool
    {
        return $this->isApproved();
    }

    /** The status a (person, period) pair reads as when it has no row yet. */
    public static function statusFor(?self $timesheet): string
    {
        return $timesheet?->status ?? self::STATUS_OPEN;
    }

    /** The period this row was created for, rebuilt as the value object. */
    public function period(): PayPeriod
    {
        return new PayPeriod(
            $this->cadence,
            \Carbon\Carbon::parse((string) $this->period_start, \App\Support\BusinessTime::tz())->startOfDay(),
            \Carbon\Carbon::parse((string) $this->period_end, \App\Support\BusinessTime::tz())->startOfDay(),
        );
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForPeriod(Builder $query, PayPeriod|string $period): Builder
    {
        return $query->where('period_key', $period instanceof PayPeriod ? $period->key() : $period);
    }

    /** Waiting on an approver. */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUBMITTED);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    // ── The lock lookup ───────────────────────────────────────────────────────

    /**
     * The APPROVED timesheet covering a person's day, or null. ONE indexed,
     * org-scoped query — used to refuse NEW time landing inside a frozen
     * period. Existing rows never need this: they carry `timesheet_id`.
     */
    public static function approvedCovering(int $userId, string $date): ?self
    {
        // Plain comparisons, never whereDate(): both columns are DATE columns,
        // and wrapping them in DATE() would make the predicate non-sargable and
        // throw away timesheets_org_user_start_idx.
        return static::query()
            ->where('user_id', $userId)
            ->where('status', self::STATUS_APPROVED)
            ->where('period_start', '<=', $date)
            ->where('period_end', '>=', $date)
            ->first();
    }

    // ── Aggregation (SQL only — never a PHP loop over work_hours) ─────────────

    /**
     * Per-person totals for a date range, as ONE grouped aggregate.
     *
     * Rides work_hours_org_user_date_idx (organization_id, user_id, date) when
     * user ids are given, and work_hours_org_date_idx otherwise. The result has
     * at most one row per user, so it is bounded by the roster page, never by
     * how many time entries the workspace holds. Callers MUST pass a bounded
     * user list (a paginated page) on any surface that could see 100+ people.
     *
     * `tracked` mirrors WorkHour::scopeTracked() exactly — a row is tracked when
     * it mirrors a tracking session or was written by the desktop mirror — so
     * the split shown here can't drift from the split the diary shows.
     *
     * @param  array<int, int>|null  $userIds  null = the whole (scoped) workspace
     * @return array<int, array{total: float, billable: float, tracked: float, manual: float, entries: int}>
     */
    public static function totalsByUser(string $start, string $end, ?array $userIds = null): array
    {
        if ($userIds !== null && $userIds === []) {
            return [];
        }

        $rows = WorkHour::query()
            ->whereBetween('date', [$start, $end])
            ->when($userIds !== null, fn ($q) => $q->whereIn('user_id', $userIds))
            ->groupBy('user_id')
            ->selectRaw(
                'user_id,'
                .' COUNT(*) as entries_count,'
                .' SUM(hours) as total_hours,'
                .' SUM(CASE WHEN is_billable = 1 THEN hours ELSE 0 END) as billable_hours,'
                .' SUM(CASE WHEN (tracking_session_id IS NOT NULL OR source = ?) THEN hours ELSE 0 END) as tracked_hours',
                [WorkHour::SOURCE_TRACKER]
            )
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $total = round((float) $row->total_hours, 2);
            $tracked = round((float) $row->tracked_hours, 2);

            $out[(int) $row->user_id] = [
                'total' => $total,
                'billable' => round((float) $row->billable_hours, 2),
                'tracked' => $tracked,
                'manual' => round(max(0, $total - $tracked), 2),
                'entries' => (int) $row->entries_count,
            ];
        }

        return $out;
    }

    /** The zero row, so every caller renders the same shape for "no time". */
    public static function emptyTotals(): array
    {
        return ['total' => 0.0, 'billable' => 0.0, 'tracked' => 0.0, 'manual' => 0.0, 'entries' => 0];
    }

    /**
     * One person's period broken down by work category — ONE grouped aggregate,
     * at most one row per category (the catalogue is capped at 200).
     *
     * @return array<int, array{id: ?int, name: string, hours: float, billable: float, entries: int}>
     */
    public static function categoryBreakdown(int $userId, string $start, string $end): array
    {
        $rows = WorkHour::query()
            ->leftJoin('work_categories', 'work_hours.work_category_id', '=', 'work_categories.id')
            ->where('work_hours.user_id', $userId)
            ->whereBetween('work_hours.date', [$start, $end])
            ->groupBy('work_hours.work_category_id', 'work_categories.name')
            ->selectRaw(
                'work_hours.work_category_id as category_id,'
                .' work_categories.name as category_name,'
                .' COUNT(*) as entries_count,'
                .' SUM(work_hours.hours) as total_hours,'
                .' SUM(CASE WHEN work_hours.is_billable = 1 THEN work_hours.hours ELSE 0 END) as billable_hours'
            )
            ->orderByRaw('SUM(work_hours.hours) DESC')
            ->get();

        return $rows->map(fn ($row) => [
            'id' => $row->category_id === null ? null : (int) $row->category_id,
            'name' => $row->category_name ?: 'Uncategorised',
            'hours' => round((float) $row->total_hours, 2),
            'billable' => round((float) $row->billable_hours, 2),
            'entries' => (int) $row->entries_count,
        ])->all();
    }

    /**
     * One person's period day by day — ONE grouped aggregate returning at most
     * `days()` rows (31 at the very worst), so the detail page never loads
     * individual entries to draw its bars.
     *
     * @return array<int, array{date: string, hours: float, billable: float, tracked: float, entries: int}>
     */
    public static function dailyBreakdown(int $userId, string $start, string $end): array
    {
        $rows = WorkHour::query()
            ->where('user_id', $userId)
            ->whereBetween('date', [$start, $end])
            ->groupBy('date')
            ->orderBy('date')
            ->selectRaw(
                'date,'
                .' COUNT(*) as entries_count,'
                .' SUM(hours) as total_hours,'
                .' SUM(CASE WHEN is_billable = 1 THEN hours ELSE 0 END) as billable_hours,'
                .' SUM(CASE WHEN (tracking_session_id IS NOT NULL OR source = ?) THEN hours ELSE 0 END) as tracked_hours',
                [WorkHour::SOURCE_TRACKER]
            )
            ->get();

        return $rows->map(fn ($row) => [
            'date' => substr((string) $row->date, 0, 10),
            'hours' => round((float) $row->total_hours, 2),
            'billable' => round((float) $row->billable_hours, 2),
            'tracked' => round((float) $row->tracked_hours, 2),
            'entries' => (int) $row->entries_count,
        ])->all();
    }

    /**
     * How many timesheets sit in each status for a period — ONE grouped COUNT
     * over timesheets_org_period_user_idx. Never a row fetch.
     *
     * @return array<string, int>
     */
    public static function statusCounts(PayPeriod|string $period): array
    {
        $rows = static::query()
            ->forPeriod($period)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as sheets')
            ->get();

        $out = array_fill_keys(self::STATUSES, 0);

        foreach ($rows as $row) {
            $out[(string) $row->status] = (int) $row->sheets;
        }

        return $out;
    }

    /** @return array<string, mixed> The shape every page prop uses. */
    public function toPayload(): array
    {
        return [
            'id' => (int) $this->id,
            'user_id' => (int) $this->user_id,
            'period_key' => $this->period_key,
            'cadence' => $this->cadence,
            'period_start' => substr((string) $this->period_start, 0, 10),
            'period_end' => substr((string) $this->period_end, 0, 10),
            'status' => $this->status,
            'note' => $this->note,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'locked_at' => $this->locked_at?->toIso8601String(),
            'approved_totals' => $this->isApproved() ? [
                'total' => (float) $this->total_hours,
                'billable' => (float) $this->billable_hours,
                'tracked' => (float) $this->tracked_hours,
                'manual' => (float) $this->manual_hours,
                'entries' => (int) $this->entries_count,
            ] : null,
        ];
    }
}
