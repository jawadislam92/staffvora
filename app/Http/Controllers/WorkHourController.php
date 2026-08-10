<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\MonitoringSetting;
use App\Models\TimeEntry;
use App\Models\TrackingAuditLog;
use App\Models\Timesheet;
use App\Models\TrackingSession;
use App\Models\UpworkProfile;
use App\Models\User;
use App\Models\WorkCategory;
use App\Models\WorkHour;
use App\Services\SlackReportService;
use App\Services\TrackingSessionService;
use App\Support\BusinessTime;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class WorkHourController extends Controller
{
    /**
     * LEGACY vocabulary. The one flat list that conflated capture method, work
     * category and billing. It is accepted on input during the transition (the
     * desktop app, the API and old bookmarks still speak it) and still written
     * to `work_hours.work_type` so historical readers keep working — but the
     * source of truth is now work_category_id + is_billable + capture method.
     * {@see App\Models\WorkCategory}
     */
    private const WORK_TYPES = [
        'tracker',
        'manual',
        'fixed',
        'outside_of_upwork',
        'office_work',
        'test_task',
        'upwork_bidding',
    ];

    /**
     * THE REPORT'S DEFAULT WINDOW.
     *
     * `/report` used to open on ALL TIME. That is a query whose GROUP BY widens
     * for the entire life of the workspace: every day a customer uses the
     * product, the first page they see gets slower, and a three-year-old tenant
     * pays for three years of grouping to look at last week. The first load now
     * opens on the last ninety days instead — long enough to contain any pay
     * period, any monthly review and any invoice run — and "All dates" is still
     * one click away in the date picker, so nothing has become unreachable.
     *
     * The narrowing applies ONLY when the request says nothing about dates. An
     * explicit `filter=all`, or an explicit start/end pair (a saved link, a
     * Slack digest link, a bookmark), is always honoured exactly as given.
     */
    public const DEFAULT_REPORT_FILTER = 'last90';

    public const DEFAULT_REPORT_DAYS = 90;

    /**
     * A still-open clock-in older than this is treated as forgotten, so the
     * in-office span it opens is capped instead of running to "now" forever.
     * Mirrors the attendance-hours state machine.
     */
    private const OPEN_PUNCH_CAP_HOURS = 18;

    /** Gap slivers shorter than this aren't worth offering as a quick-fill chip. */
    private const MIN_GAP_SECONDS = 60;

    private function authorizeWorkHourAccess(WorkHour $workHour): void
    {
        $user = auth()->user();

        if (! $user->hasPermission('work_hours.manage_all') && $workHour->user_id !== $user->id) {
            abort(403, 'You can only manage your own work hour entries.');
        }
    }

    /**
     * Manual work-hour entry is always available to Admin/Super Admin (anyone
     * who can manage all entries). For regular Members it depends on the team
     * `allow_offline_time` setting from the new Settings page.
     */
    private function ensureCanCreateManual(): void
    {
        $user = auth()->user();

        if ($user->isSuperAdmin() || $user->hasPermission('work_hours.manage_all')) {
            return;
        }

        if (! MonitoringSetting::current()->allow_offline_time) {
            abort(403, 'Manual work-hour entry is disabled by your administrator.');
        }
    }

    // -----------------------------------------------------------------------
    // Manual-time window — WHEN a person may add or edit time by hand.
    //
    // The workspace has no "backfill N days" setting (see the port notes), so
    // the window is derived from settings that DO exist:
    //   · latest   = the organization's business "today" — you can never log
    //                work you haven't done yet.
    //   · earliest = attendance_tracking_start_date when the workspace has set
    //                one — nothing existed before the workspace started
    //                tracking. Managers (work_hours.manage_all) may reach back
    //                past it to fix historical records.
    // The UI restricts the date picker to this window; the server rejects
    // anything outside it regardless of what the UI showed.
    // -----------------------------------------------------------------------

    /** @return array{earliest: ?string, latest: string} */
    private function manualWindow(?User $user = null): array
    {
        $user = $user ?: auth()->user();

        $earliest = null;
        $trackingStart = MonitoringSetting::current()->attendance_tracking_start_date;
        if ($trackingStart && ! ($user?->hasPermission('work_hours.manage_all'))) {
            $earliest = $trackingStart->copy()->startOfDay()->toDateString();
        }

        return [
            'earliest' => $earliest,
            'latest' => BusinessTime::today()->toDateString(),
        ];
    }

    /**
     * Server-side enforcement of the manual-time window. Never trust the date
     * picker's min/max — this is the authority.
     */
    private function assertDateWithinManualWindow(string $rawDate, ?User $user = null): void
    {
        $window = $this->manualWindow($user);
        $date = BusinessTime::parseDate($rawDate);

        if ($date->greaterThan(BusinessTime::parseDate($window['latest']))) {
            throw ValidationException::withMessages([
                'date' => 'You can\'t log time for a future date.',
            ]);
        }

        if ($window['earliest'] && $date->lessThan(BusinessTime::parseDate($window['earliest']))) {
            throw ValidationException::withMessages([
                'date' => 'Manual time can only be added from '.$window['earliest'].' onwards.',
            ]);
        }
    }

    /**
     * Tracker-recorded entries are LOCKED. Their hours are what the desktop
     * measured, so the time portion (hours / start–end / date) can never be
     * rewritten by hand — only the labels (description, client, work type,
     * profile). Deleting one means deleting the underlying session, which is a
     * manager action; ordinary people adjust tracked time from the Timeline.
     *
     * The PAYROLL lock is absolute and comes first: an entry inside an approved
     * timesheet cannot be deleted by ANYONE, at any permission level, because
     * the period's total has already been signed off. The only way through it
     * is to reopen that timesheet — which is itself permissioned and audited.
     */
    private function canDeleteEntry(WorkHour $workHour, ?User $user): bool
    {
        if ($workHour->isApprovalLocked()) {
            return false;
        }

        return ! $workHour->isLocked() || (bool) $user?->hasPermission('work_hours.manage_all');
    }

    /**
     * Refuse any write that would land inside a pay period this person has
     * already had APPROVED. ONE indexed, org-scoped lookup — new time may not
     * appear behind a number payroll has signed off.
     */
    private function assertPeriodNotApproved(int $userId, string $rawDate): void
    {
        $date = BusinessTime::parseDate($rawDate)->toDateString();

        $approved = Timesheet::approvedCovering($userId, $date);

        if ($approved) {
            throw ValidationException::withMessages([
                'date' => 'That day is inside an approved timesheet ('
                    .substr((string) $approved->period_start, 0, 10).' – '.substr((string) $approved->period_end, 0, 10)
                    .'), so no more time can be added to it. Ask an approver to reopen that pay period first.',
            ]);
        }
    }

    // -----------------------------------------------------------------------
    // GAP-FILL — the day's untracked "open gaps".
    //
    // A gap = the in-office span (clock-in→clock-out) MINUS breaks MINUS
    // tracked sessions MINUS the person's existing ranged manual entries. The
    // form offers these as one-click quick-fills so hand-logged time lands in
    // real untracked time instead of on top of time that is already counted.
    // Ported from the source's DayGaps helper, reading this fork's models.
    // -----------------------------------------------------------------------

    /**
     * @return array{date: string, in_office: array, busy: array, gaps: array, has_attendance: bool}
     */
    private function dayGaps(?User $user, Carbon $date, ?int $ignoreWorkHourId = null): array
    {
        $tz = BusinessTime::tz();
        $dayStart = $date->copy()->setTimezone($tz)->startOfDay();
        $dayEnd = $dayStart->copy()->addDay();

        if (! $user) {
            return ['date' => $dayStart->toDateString(), 'in_office' => [], 'busy' => [], 'gaps' => [], 'has_attendance' => false];
        }

        $now = BusinessTime::now();

        [$office, $breaks] = $this->officeAndBreaks($user, $dayStart, $now);
        $tracked = $this->trackedSpans($user, $dayStart, $dayEnd, $now, $tz);
        $manual = $this->manualSpans($user, $dayStart, $dayEnd, $ignoreWorkHourId, $tz);

        $inOffice = $this->mergeSpans($this->clampSpans($office, $dayStart, $dayEnd));
        $busy = $this->mergeSpans(array_merge(
            $this->clampSpans($breaks, $dayStart, $dayEnd),
            $this->clampSpans($tracked, $dayStart, $dayEnd),
            $this->clampSpans($manual, $dayStart, $dayEnd),
        ));

        $gaps = [];
        foreach ($inOffice as $segment) {
            foreach ($this->subtractSpans($segment, $busy) as $gap) {
                if ($gap[0]->diffInSeconds($gap[1]) >= self::MIN_GAP_SECONDS) {
                    $gaps[] = $gap;
                }
            }
        }

        return [
            'date' => $dayStart->toDateString(),
            'in_office' => $this->formatSpans($inOffice),
            'busy' => $this->formatSpans($busy),
            'gaps' => $this->formatSpans($gaps),
            'has_attendance' => count($inOffice) > 0,
        ];
    }

    /**
     * In-office segments (clock_in→clock_out) and break intervals for the day,
     * read from the attendance punches. An ongoing punch ends at min(now,
     * start + cap) so a forgotten clock-in can't swallow the whole day.
     *
     * @return array{0: array<array{0: Carbon, 1: Carbon}>, 1: array<array{0: Carbon, 1: Carbon}>}
     */
    private function officeAndBreaks(User $user, Carbon $dayStart, Carbon $now): array
    {
        $tz = $dayStart->timezone;

        $entries = TimeEntry::forUser($user->id)
            ->forDate($dayStart->toDateString())
            ->orderBy('action_timestamp')->orderBy('id')
            ->get(['action_type', 'action_timestamp']);

        $office = [];
        $breaks = [];
        $sessionStart = null;
        $breakStart = null;

        foreach ($entries as $entry) {
            $at = Carbon::parse($entry->action_timestamp)->setTimezone($tz);

            switch ($entry->action_type) {
                case 'clock_in':
                    $sessionStart = $at;
                    break;
                case 'clock_out':
                    if ($sessionStart) {
                        $office[] = [$sessionStart, $at];
                        $sessionStart = null;
                    }
                    break;
                case 'break_start':
                    $breakStart = $at;
                    break;
                case 'break_end':
                    if ($breakStart) {
                        $breaks[] = [$breakStart, $at];
                        $breakStart = null;
                    }
                    break;
            }
        }

        if ($sessionStart) {
            $end = $this->cappedOngoingEnd($sessionStart, $now);
            if ($end->greaterThan($sessionStart)) {
                $office[] = [$sessionStart, $end];
            }
        }
        if ($breakStart) {
            $end = $this->cappedOngoingEnd($breakStart, $now);
            if ($end->greaterThan($breakStart)) {
                $breaks[] = [$breakStart, $end];
            }
        }

        return [$office, $breaks];
    }

    private function cappedOngoingEnd(Carbon $start, Carbon $now): Carbon
    {
        $cap = $start->copy()->addHours(self::OPEN_PUNCH_CAP_HOURS);

        return $now->lessThan($cap) ? $now->copy() : $cap;
    }

    /**
     * Desktop tracking sessions overlapping the day. An active session runs to
     * now. Session timestamps are stored instants, so they convert into the
     * business timezone before the wall-clock arithmetic.
     *
     * @return array<array{0: Carbon, 1: Carbon}>
     */
    private function trackedSpans(User $user, Carbon $dayStart, Carbon $dayEnd, Carbon $now, string $tz): array
    {
        [$fromUtc, $toUtc] = BusinessTime::utcRange($dayStart, $dayEnd);

        $sessions = TrackingSession::forUser($user->id)
            ->where('started_at', '<', $toUtc)
            ->where(fn ($q) => $q->whereNull('stopped_at')->orWhere('stopped_at', '>', $fromUtc))
            ->get(['started_at', 'stopped_at']);

        $out = [];
        foreach ($sessions as $session) {
            if (! $session->started_at) {
                continue;
            }
            $start = $session->started_at->copy()->setTimezone($tz);
            $end = $session->stopped_at ? $session->stopped_at->copy()->setTimezone($tz) : $now->copy();
            if ($end->greaterThan($start)) {
                $out[] = [$start, $end];
            }
        }

        return $out;
    }

    /**
     * The person's existing ranged manual entries for the day, optionally
     * excluding the one being edited so its own range doesn't read as "busy".
     * started_at/ended_at are stored as wall-clock (like `date`), so they are
     * re-read as business-timezone wall-clock rather than converted.
     *
     * @return array<array{0: Carbon, 1: Carbon}>
     */
    private function manualSpans(User $user, Carbon $dayStart, Carbon $dayEnd, ?int $ignoreWorkHourId, string $tz): array
    {
        $rows = WorkHour::query()
            ->where('user_id', $user->id)
            ->manual()
            ->overlapping($dayStart->format('Y-m-d H:i:s'), $dayEnd->format('Y-m-d H:i:s'))
            ->when($ignoreWorkHourId, fn ($q) => $q->where('id', '!=', $ignoreWorkHourId))
            ->get(['id', 'started_at', 'ended_at']);

        $out = [];
        foreach ($rows as $row) {
            $start = Carbon::parse($row->started_at->format('Y-m-d H:i:s'), $tz);
            $end = Carbon::parse($row->ended_at->format('Y-m-d H:i:s'), $tz);
            if ($end->greaterThan($start)) {
                $out[] = [$start, $end];
            }
        }

        return $out;
    }

    /**
     * Clamp every interval to [dayStart, dayEnd], dropping empties.
     *
     * @param  array<array{0: Carbon, 1: Carbon}>  $intervals
     * @return array<array{0: Carbon, 1: Carbon}>
     */
    private function clampSpans(array $intervals, Carbon $dayStart, Carbon $dayEnd): array
    {
        $out = [];
        foreach ($intervals as [$s, $e]) {
            $start = $s->greaterThan($dayStart) ? $s->copy() : $dayStart->copy();
            $end = $e->lessThan($dayEnd) ? $e->copy() : $dayEnd->copy();
            if ($end->greaterThan($start)) {
                $out[] = [$start, $end];
            }
        }

        return $out;
    }

    /**
     * Merge overlapping/adjacent intervals into a sorted, disjoint set.
     *
     * @param  array<array{0: Carbon, 1: Carbon}>  $intervals
     * @return array<array{0: Carbon, 1: Carbon}>
     */
    private function mergeSpans(array $intervals): array
    {
        if (empty($intervals)) {
            return [];
        }

        usort($intervals, fn ($a, $b) => $a[0]->getTimestamp() <=> $b[0]->getTimestamp());

        $out = [];
        [$curStart, $curEnd] = $intervals[0];
        $curStart = $curStart->copy();
        $curEnd = $curEnd->copy();

        foreach (array_slice($intervals, 1) as [$s, $e]) {
            if ($s->lessThanOrEqualTo($curEnd)) {
                if ($e->greaterThan($curEnd)) {
                    $curEnd = $e->copy();
                }
            } else {
                $out[] = [$curStart, $curEnd];
                $curStart = $s->copy();
                $curEnd = $e->copy();
            }
        }
        $out[] = [$curStart, $curEnd];

        return $out;
    }

    /**
     * Subtract a sorted, disjoint busy set from one segment → the open gaps.
     *
     * @param  array{0: Carbon, 1: Carbon}  $segment
     * @param  array<array{0: Carbon, 1: Carbon}>  $busy
     * @return array<array{0: Carbon, 1: Carbon}>
     */
    private function subtractSpans(array $segment, array $busy): array
    {
        [$segStart, $segEnd] = $segment;
        $cursor = $segStart->copy();
        $gaps = [];

        foreach ($busy as [$bs, $be]) {
            if ($be->lessThanOrEqualTo($cursor)) {
                continue; // entirely before the cursor
            }
            if ($bs->greaterThanOrEqualTo($segEnd)) {
                break; // busy is sorted; nothing else can overlap
            }
            if ($bs->greaterThan($cursor)) {
                $gaps[] = [$cursor->copy(), $bs->copy()];
            }
            if ($be->greaterThan($cursor)) {
                $cursor = $be->copy();
            }
            if ($cursor->greaterThanOrEqualTo($segEnd)) {
                break;
            }
        }

        if ($cursor->lessThan($segEnd)) {
            $gaps[] = [$cursor->copy(), $segEnd->copy()];
        }

        return $gaps;
    }

    /**
     * @param  array<array{0: Carbon, 1: Carbon}>  $intervals
     */
    private function formatSpans(array $intervals): array
    {
        $out = [];
        foreach ($intervals as [$s, $e]) {
            $out[] = [
                'start' => $s->format('H:i'),
                'end' => $e->format('H:i'),
                'label' => $s->format('g:i A').' – '.$e->format('g:i A'),
                'minutes' => (int) round($s->diffInSeconds($e) / 60),
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // WORK CATEGORY + BILLABLE — the workspace-owned replacement for the flat
    // `work_type` enum. One mechanism for every workspace: an Agency and a
    // General workspace run this identical code and differ only in which rows
    // their catalogue was seeded with, so there is no edition branch here.
    // -----------------------------------------------------------------------

    /**
     * The picker list for the create/edit forms: this workspace's ACTIVE
     * categories in its own order. Seeds the starter set the first time a
     * workspace opens the form (mirrors Shift::ensureSeeded()).
     */
    private function activeWorkCategories()
    {
        WorkCategory::ensureSeeded();

        return WorkCategory::active()->ordered()->get(['id', 'name', 'slug', 'is_billable_default', 'sort_order']);
    }

    /**
     * The full catalogue for filter dropdowns — archived rows included, because
     * historical entries still point at them and must stay filterable.
     */
    private function allWorkCategories()
    {
        WorkCategory::ensureSeeded();

        return WorkCategory::ordered()->get(['id', 'name', 'slug', 'is_billable_default', 'is_active']);
    }

    /**
     * Just the ids of this workspace's categories — one indexed, org-scoped
     * query used to sanitise incoming filter ids on the export endpoints, which
     * don't need the full catalogue.
     *
     * @return array<int, int>
     */
    private function ownCategoryIds(): array
    {
        return WorkCategory::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Validation for a submitted category id. The id MUST belong to the current
     * workspace — an id from another tenant is rejected, never merely filtered.
     * Archived categories can't take NEW time, but an entry that already sits on
     * one keeps saving (otherwise archiving would freeze old rows).
     */
    private function workCategoryRule(?int $organizationId, ?int $keepId = null): array
    {
        return [
            'nullable',
            'integer',
            Rule::exists('work_categories', 'id')->where(function ($query) use ($organizationId, $keepId) {
                $query->where('organization_id', $organizationId)
                    ->where(function ($sub) use ($keepId) {
                        $sub->where('is_active', true);
                        if ($keepId) {
                            $sub->orWhere('id', $keepId);
                        }
                    });
            }),
        ];
    }

    /**
     * The category an entry should land on: the explicit id when given
     * (re-read through the tenant-scoped query, so it can only ever be one of
     * this workspace's own), otherwise derived from the legacy `work_type` so
     * old clients keep filing time correctly.
     */
    private function resolveWorkCategory(?int $categoryId, ?string $legacyWorkType): ?WorkCategory
    {
        if ($categoryId) {
            return WorkCategory::query()->find($categoryId);
        }

        return WorkCategory::resolveForLegacyWorkType($legacyWorkType);
    }

    /**
     * The legacy value to keep writing into `work_hours.work_type` so every
     * not-yet-migrated reader (Slack digest, exports, the desktop app) still
     * renders these rows. Manual entries collapse to 'manual' when a category
     * has no legacy twin — the category is the real answer from here on.
     */
    private function legacyWorkTypeFor(?WorkCategory $category, ?string $submitted, bool $tracked = false): string
    {
        if ($submitted !== null && $submitted !== '') {
            return $submitted;
        }

        $legacy = $category?->slug ? (WorkCategory::LEGACY_WORK_TYPE_BY_SLUG[$category->slug] ?? null) : null;

        if (in_array($legacy, self::WORK_TYPES, true)) {
            return $legacy;
        }

        // Client Work and workspace-invented categories have no legacy twin, so
        // the legacy column falls back to the CAPTURE METHOD — which is the only
        // thing the old 'tracker' / 'manual' values ever really meant.
        return $tracked ? 'tracker' : 'manual';
    }

    /**
     * Billable resolution: an explicitly submitted value always wins; otherwise
     * the chosen category's default; otherwise (no category at all) fall back to
     * the value already on the row, or true.
     */
    private function resolveBillable(Request $request, ?WorkCategory $category, ?bool $current = null): bool
    {
        if ($request->has('is_billable')) {
            return $request->boolean('is_billable');
        }

        if ($category) {
            return (bool) $category->is_billable_default;
        }

        return $current ?? true;
    }

    /**
     * Requested category-id filters, hard-intersected with the ids this
     * workspace actually owns — a crafted id from another tenant can neither
     * filter nor probe.
     *
     * @param  array<int, int>  $ownIds
     * @return array<int, int>
     */
    private function selectedCategoryIds(Request $request, array $ownIds): array
    {
        $requested = array_map('intval', $this->filterValues($request, 'workCategories', 'workCategory'));

        return array_values(array_intersect($requested, $ownIds));
    }

    /** 'all' | 'yes' | 'no' — the billable filter, defaulting to everything. */
    private function selectedBillable(Request $request): string
    {
        $value = (string) $request->input('billable', 'all');

        return in_array($value, ['yes', 'no'], true) ? $value : 'all';
    }

    private function filterValues(Request $request, string $key, ?string $legacyKey = null): array
    {
        $value = $request->input($key);

        if ($value === null && $legacyKey) {
            $value = $request->input($legacyKey);
        }

        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            $value = [$value];
        }

        return collect($value)
            ->flatten()
            ->map(fn ($item) => trim((string) $item))
            ->filter(fn ($item) => $item !== '' && $item !== 'all')
            ->unique()
            ->values()
            ->all();
    }

    private function applyCommonFilters($query, array $filters): void
    {
        if (! empty($filters['workTypes'])) {
            $query->whereIn('work_type', $filters['workTypes']);
        }

        // Ids are pre-intersected with this workspace's own categories
        // (selectedCategoryIds), so this can never reach across tenants.
        if (! empty($filters['workCategoryIds'])) {
            $query->whereIn('work_hours.work_category_id', $filters['workCategoryIds']);
        }

        if (($filters['billable'] ?? 'all') !== 'all') {
            $query->where('work_hours.is_billable', ($filters['billable'] === 'yes'));
        }

        if (! empty($filters['trackers'])) {
            $query->whereIn('tracker', $filters['trackers']);
        }

        if (! empty($filters['clients'])) {
            $query->whereHas('client', function ($q) use ($filters) {
                $q->whereIn('name', $filters['clients']);
            });
        }

        if (! empty($filters['userIds'])) {
            $query->whereIn('user_id', $filters['userIds']);
        }

        if (! empty($filters['designations'])) {
            $query->whereHas('user', function ($q) use ($filters) {
                $q->whereIn('designation', $filters['designations']);
            });
        }
    }

    private function firstLegacyValue(array $values): string
    {
        return $values[0] ?? 'all';
    }

    private function redirectToReturnPath(Request $request, string $fallbackRoute, array $flash = [])
    {
        $returnTo = $request->input('return_to');
        $redirect = is_string($returnTo)
            && str_starts_with($returnTo, '/')
            && ! str_starts_with($returnTo, '//')
                ? redirect($returnTo)
                : redirect()->route($fallbackRoute);

        foreach ($flash as $key => $value) {
            $redirect->with($key, $value);
        }

        return $redirect;
    }

    public function index(Request $request)
    {
        $user = auth()->user();
        // workCategory is eager-loaded so the category column (and the legacy
        // work_type accessor) costs one query for the page, not one per row.
        $query = WorkHour::with('user', 'client', 'workCategory');

        // WorkHoursList is for personal work diary - everyone sees only their own entries
        $query->where('user_id', $user->id);

        $filter = $request->input('filter', 'all');
        $startDate = $request->input('startDate');
        $endDate = $request->input('endDate');
        $selectedWorkTypes = $this->filterValues($request, 'workTypes', 'workType');
        $selectedTrackers = $this->filterValues($request, 'trackers', 'tracker');
        $selectedClients = $this->filterValues($request, 'clients', 'client');
        $workCategories = $this->allWorkCategories();
        $selectedWorkCategories = $this->selectedCategoryIds($request, $workCategories->pluck('id')->all());
        $selectedBillable = $this->selectedBillable($request);
        $perPage = $request->input('perPage', 15);

        // Validate perPage to prevent abuse
        $allowedPerPage = [15, 25, 50, 100];
        if (! in_array($perPage, $allowedPerPage)) {
            $perPage = 15;
        }

        // Apply date filter
        if ($filter !== 'all' && $startDate && $endDate) {
            $query->whereBetween('date', [$startDate, $endDate]);
        }

        $this->applyCommonFilters($query, [
            'workTypes' => $selectedWorkTypes,
            'workCategoryIds' => $selectedWorkCategories,
            'billable' => $selectedBillable,
            'trackers' => $selectedTrackers,
            'clients' => $selectedClients,
        ]);

        // Implement pagination with dynamic per page
        $workHours = $query->orderByDesc('date')->orderByDesc('id')->paginate($perPage);

        // Preserve query parameters in pagination links
        $workHours->appends($request->query());

        $availableClients = WorkHour::with('client')
            ->where('user_id', $user->id)
            ->whereHas('client')
            ->get()
            ->pluck('client.name')
            ->unique()
            ->filter()
            ->sort()
            ->values();

        $availableTrackers = WorkHour::where('user_id', $user->id)
            ->whereNotNull('tracker')
            ->distinct()
            ->pluck('tracker')
            ->filter()
            ->sort()
            ->values();

        return Inertia::render('WorkHoursList', [
            'workHours' => $workHours,
            'filter' => $filter,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'workType' => $this->firstLegacyValue($selectedWorkTypes),
            'tracker' => $this->firstLegacyValue($selectedTrackers),
            'client' => $this->firstLegacyValue($selectedClients),
            'perPage' => $perPage,
            'billable' => $selectedBillable,
            'workCategories' => $workCategories,
            'selectedFilters' => [
                'workTypes' => $selectedWorkTypes,
                'workCategories' => $selectedWorkCategories,
                'billable' => $selectedBillable,
                'trackers' => $selectedTrackers,
                'clients' => $selectedClients,
            ],
            'filterOptions' => [
                'workTypes' => self::WORK_TYPES,
                'workCategories' => $workCategories,
                'captureMethods' => [WorkHour::CAPTURE_TRACKED, WorkHour::CAPTURE_MANUAL],
                'trackers' => $availableTrackers,
                'clients' => $availableClients,
            ],
            'flash' => [
                'success' => $request->session()->get('success') ?? '',
                'error' => $request->session()->get('error') ?? '',
            ],
        ]);
    }

    public function create(Request $request)
    {
        $this->ensureCanCreateManual();
        $user = $request->user();
        $organization = $user?->organization;

        $clients = Client::with(['upworkProfile', 'upworkProfiles'])
            ->select('id', 'name', 'work_type', 'upwork_profile_id')
            ->orderBy('name')
            ->get();

        $trackers = $organization?->usesProfiles()
            ? UpworkProfile::active()->orderBy('name')->pluck('name')->toArray()
            : [];

        // `?date=` lets the form re-ask for another day's gaps with a partial
        // Inertia reload (only: ['gaps','gapDay']) — no extra route needed.
        $day = $this->dayGaps($user, BusinessTime::parseDate($request->input('date')));

        return Inertia::render('WorkHourCreate', [
            'trackers' => $trackers,
            'clients' => $clients,
            'workCategories' => $this->activeWorkCategories(),
            'manualWindow' => $this->manualWindow($user),
            'gapDay' => [
                'date' => $day['date'],
                'has_attendance' => $day['has_attendance'],
                'in_office' => $day['in_office'],
            ],
            'gaps' => $day['gaps'],
        ]);
    }

    public function store(Request $request)
    {
        $this->ensureCanCreateManual();
        $organization = $request->user()?->organization;
        $usesProfiles = (bool) $organization?->usesProfiles();

        $validated = $request->validate([
            'date' => 'required|date',
            'description' => 'required|string|max:5000',
            // Either vocabulary is accepted while the UI migrates, but one of
            // them must be present — an entry always says what the work was.
            'work_category_id' => array_merge(
                ['required_without:work_type'],
                $this->workCategoryRule($organization?->id)
            ),
            'work_type' => ['required_without:work_category_id', 'nullable', Rule::in(self::WORK_TYPES)],
            'is_billable' => ['nullable', 'boolean'],
            'client_id' => [
                'nullable',
                'integer',
                Rule::exists('clients', 'id')->where(fn ($query) => $query->where('organization_id', $organization?->id)),
            ],
            'tracker' => $usesProfiles
                ? ['nullable', 'string', 'max:255', Rule::exists('upwork_profiles', 'name')->where(fn ($query) => $query->where('organization_id', $organization?->id))]
                : ['nullable', 'string', 'max:255'],
            'entry_mode' => ['nullable', Rule::in(['range', 'duration'])],
        ]);

        // The manual-time window is enforced here, not in the date picker.
        $this->assertDateWithinManualWindow($validated['date'], $request->user());

        // …and neither window nor picker may put new time inside a period that
        // has already been approved.
        $this->assertPeriodNotApproved($request->user()->id, $validated['date']);

        $time = $this->resolveTimeFields($request); // throws ValidationException on bad/overlapping input

        $category = $this->resolveWorkCategory(
            isset($validated['work_category_id']) ? (int) $validated['work_category_id'] : null,
            $validated['work_type'] ?? null
        );

        WorkHour::create([
            'user_id' => $request->user()->id,
            'date' => $validated['date'],
            'description' => $validated['description'],
            'work_category_id' => $category?->id,
            'is_billable' => $this->resolveBillable($request, $category),
            // Legacy column stays populated so nothing that still reads it breaks.
            'work_type' => $this->legacyWorkTypeFor($category, $validated['work_type'] ?? null),
            'client_id' => $validated['client_id'] ?? null,
            'tracker' => $usesProfiles ? ($validated['tracker'] ?? null) : null,
            'hours' => $time['hours'],
            'started_at' => $time['started_at'],
            'ended_at' => $time['ended_at'],
            'source' => 'manual',
        ]);

        return redirect()->route('work-hours.index')
            ->with('success', 'Work hour entry created successfully.'.($time['warning'] ? ' '.$time['warning'] : ''));
    }

    /**
     * Resolve the time portion of a manual entry from either a start–end range
     * or a plain duration. Range mode computes `hours` from the wall-clock
     * start/end in the org timezone, splits overnight ranges (end <= start rolls
     * to the next day), and applies the org's overlap policy. Returns
     * ['hours', 'started_at', 'ended_at', 'warning'] or throws on bad input.
     *
     * @return array{hours: float, started_at: ?Carbon, ended_at: ?Carbon, warning: ?string}
     */
    private function resolveTimeFields(Request $request, ?int $ignoreId = null): array
    {
        if ($request->input('entry_mode') === 'range') {
            $data = $request->validate([
                'start_time' => 'required|date_format:H:i',
                'end_time' => 'required|date_format:H:i',
            ]);

            // Wall-clock datetimes (no timezone conversion) — like the `date`
            // column, started_at/ended_at hold exactly what the employee typed,
            // so storage, overlap comparison and display all stay consistent.
            $start = Carbon::parse($request->input('date').' '.$data['start_time']);
            $end = Carbon::parse($request->input('date').' '.$data['end_time']);
            if ($end->lessThanOrEqualTo($start)) {
                $end->addDay(); // overnight: e.g. 11:30pm–1:00am
            }

            $minutes = $start->diffInMinutes($end);
            if ($minutes <= 0) {
                throw ValidationException::withMessages(['end_time' => 'End time must be after start time.']);
            }
            if ($minutes > 24 * 60) {
                throw ValidationException::withMessages(['end_time' => 'A single entry cannot span more than 24 hours.']);
            }

            return [
                'hours' => round($minutes / 60, 2),
                'started_at' => $start,
                'ended_at' => $end,
                'warning' => $this->overlapWarning($request->user()->id, $start, $end, $ignoreId),
            ];
        }

        // Duration mode (hours + minutes) — unchanged behaviour, no endpoints.
        $data = $request->validate([
            'hours' => 'required|integer|min:0|max:24',
            'minutes' => 'required|integer|min:0|max:59',
        ]);
        if ((int) $data['hours'] === 0 && (int) $data['minutes'] === 0) {
            throw ValidationException::withMessages(['hours' => 'Please enter at least some time.']);
        }

        return [
            'hours' => $data['hours'] + ($data['minutes'] / 60),
            'started_at' => null,
            'ended_at' => null,
            'warning' => null,
        ];
    }

    /**
     * Apply the org's manual-time overlap policy to a new/edited range. `allow`
     * never blocks; `block` rejects an overlap; `warn` allows it but returns a
     * message to surface. Compares against the user's other ranged entries.
     */
    private function overlapWarning(int $userId, Carbon $start, Carbon $end, ?int $ignoreId): ?string
    {
        $policy = MonitoringSetting::current()->manual_time_overlap_policy ?: 'warn';
        if ($policy === 'allow') {
            return null;
        }

        $overlaps = WorkHour::where('user_id', $userId)
            ->whereNotNull('started_at')->whereNotNull('ended_at')
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('started_at', '<', $end)
            ->where('ended_at', '>', $start)
            ->exists();

        if (! $overlaps) {
            return null;
        }

        if ($policy === 'block') {
            throw ValidationException::withMessages(['start_time' => 'This time range overlaps another entry on your diary.']);
        }

        return 'Heads up — this overlaps another time entry on your diary.';
    }

    public function edit(Request $request, WorkHour $workHour)
    {
        $this->authorizeWorkHourAccess($workHour);

        // Load the client + category relationships
        $workHour->load('client', 'workCategory');

        $clients = Client::select('id', 'name')
            ->orderBy('name')
            ->get();

        $organization = auth()->user()?->organization;
        $trackers = $organization?->usesProfiles()
            ? UpworkProfile::active()->orderBy('name')->pluck('name')->toArray()
            : [];

        // Gaps belong to the entry's OWNER (a manager may be editing someone
        // else's diary), and exclude this entry so its own range isn't "busy".
        $owner = $workHour->user ?: User::find($workHour->user_id);
        $day = $workHour->isLocked()
            ? ['date' => (string) $workHour->date, 'has_attendance' => false, 'in_office' => [], 'gaps' => []]
            : $this->dayGaps($owner, BusinessTime::parseDate($request->input('date', (string) $workHour->date)), $workHour->id);

        // The picker shows the ACTIVE catalogue plus, when this entry sits on an
        // archived category, that one row — so editing an old entry never
        // silently re-files it.
        WorkCategory::ensureSeeded();
        $workCategories = WorkCategory::query()
            ->where(function ($q) use ($workHour) {
                $q->where('is_active', true);
                if ($workHour->work_category_id) {
                    $q->orWhere('id', $workHour->work_category_id);
                }
            })
            ->ordered()
            ->get(['id', 'name', 'slug', 'is_billable_default', 'sort_order', 'is_active']);

        return Inertia::render('WorkHourEdit', [
            'workHour' => $workHour,
            'trackers' => $trackers,
            'clients' => $clients,
            'workCategories' => $workCategories,
            'captureMethod' => $workHour->captureMethod(),
            'locked' => $workHour->isLocked(),
            // The stricter payroll lock: when true even the LABELS are frozen,
            // and update() refuses the whole request.
            'approvalLocked' => $workHour->isApprovalLocked(),
            'lockReason' => $workHour->lockReason(),
            'manualWindow' => $this->manualWindow($request->user()),
            'gapDay' => [
                'date' => $day['date'],
                'has_attendance' => $day['has_attendance'],
                'in_office' => $day['in_office'],
            ],
            'gaps' => $day['gaps'],
        ]);
    }

    public function update(Request $request, WorkHour $workHour)
    {
        $this->authorizeWorkHourAccess($workHour);
        $organization = $request->user()?->organization;
        $usesProfiles = (bool) $organization?->usesProfiles();

        $validated = $request->validate([
            'date' => 'required|date',
            'description' => 'required|string|max:5000',
            // The entry's CURRENT category stays selectable even if it has since
            // been archived, so an old entry can still be corrected.
            'work_category_id' => array_merge(
                ['required_without:work_type'],
                $this->workCategoryRule($organization?->id, $workHour->work_category_id)
            ),
            'work_type' => ['required_without:work_category_id', 'nullable', Rule::in(self::WORK_TYPES)],
            'is_billable' => ['nullable', 'boolean'],
            'client_id' => [
                'nullable',
                'integer',
                Rule::exists('clients', 'id')->where(fn ($query) => $query->where('organization_id', $organization?->id)),
            ],
            'tracker' => $usesProfiles
                ? ['nullable', 'string', 'max:255', Rule::exists('upwork_profiles', 'name')->where(fn ($query) => $query->where('organization_id', $organization?->id))]
                : ['nullable', 'string', 'max:255'],
            'entry_mode' => ['nullable', Rule::in(['range', 'duration'])],
        ]);

        // PAYROLL LOCK — checked before anything else, because it is absolute.
        // An entry inside an approved timesheet accepts NO change at all: not
        // the time, and not the labels either, because the category and the
        // billable flag are part of the split that was signed off. Reopening
        // the timesheet is the only way through, and it is audited.
        if ($workHour->isApprovalLocked()) {
            return $this->redirectToReturnPath($request, 'work-hours.index', [
                'error' => $workHour->lockReason(),
            ]);
        }

        $category = $this->resolveWorkCategory(
            isset($validated['work_category_id']) ? (int) $validated['work_category_id'] : null,
            $validated['work_type'] ?? null
        );

        // Tracker-recorded time is LOCKED: the hours always reflect what the
        // desktop tracker measured, so an entry can't be edited to show more
        // (or different) time than was actually tracked — and its date can't be
        // moved off the day it was measured on. The LABELS (description, client,
        // work category, billable, profile) can still be corrected; any
        // submitted time is ignored. The capture method is never editable — it
        // is derived from the session, not stored as a choice.
        // The UI disables those fields, but this is what actually enforces it.
        if ($workHour->isLocked()) {
            $workHour->update([
                'description' => $validated['description'],
                'work_category_id' => $category?->id ?? $workHour->work_category_id,
                'is_billable' => $this->resolveBillable($request, $category, $workHour->is_billable),
                'work_type' => $this->legacyWorkTypeFor($category, $validated['work_type'] ?? null, $workHour->isTracked()),
                'client_id' => $validated['client_id'] ?? null,
                'tracker' => $usesProfiles ? ($validated['tracker'] ?? null) : null,
            ]);

            return redirect()->route('work-hours.index')
                ->with('success', 'Entry updated. The tracked time itself stays as the desktop tracker measured it.');
        }

        $this->assertDateWithinManualWindow($validated['date'], $request->user());

        // Moving an editable entry INTO an approved period would grow a signed-
        // off total from the outside — same refusal as creating one there.
        $this->assertPeriodNotApproved((int) $workHour->user_id, $validated['date']);

        $time = $this->resolveTimeFields($request, $workHour->id);

        $workHour->update([
            'date' => $validated['date'],
            'description' => $validated['description'],
            'work_category_id' => $category?->id ?? $workHour->work_category_id,
            'is_billable' => $this->resolveBillable($request, $category, $workHour->is_billable),
            'work_type' => $this->legacyWorkTypeFor($category, $validated['work_type'] ?? null, $workHour->isTracked()),
            'client_id' => $validated['client_id'] ?? null,
            'tracker' => $usesProfiles ? ($validated['tracker'] ?? null) : null,
            'hours' => $time['hours'],
            'started_at' => $time['started_at'],
            'ended_at' => $time['ended_at'],
        ]);

        return redirect()->route('work-hours.index')
            ->with('success', 'Work hour entry updated successfully.'.($time['warning'] ? ' '.$time['warning'] : ''));
    }

    public function destroy(Request $request, WorkHour $workHour)
    {
        $this->authorizeWorkHourAccess($workHour);

        // Deleting a tracked entry tears down the whole session it mirrors, so
        // it's a manager action. Employees adjust tracked time on the Timeline.
        // An approval-locked entry refuses everyone, at every permission level.
        if (! $this->canDeleteEntry($workHour, $request->user())) {
            return $this->redirectToReturnPath($request, 'work-hours.index', [
                'error' => $workHour->isApprovalLocked()
                    ? $workHour->lockReason()
                    : 'Tracker-recorded time can\'t be deleted here. Open that day in the Timeline and remove the relevant screenshots instead.',
            ]);
        }

        $cascaded = $this->deleteWorkHourAndSource($workHour, $request->user());

        return $this->redirectToReturnPath($request, 'work-hours.index', [
            'success' => $cascaded
                ? 'Work entry deleted — its tracking session, screenshots, and activity data were removed too.'
                : 'Work hour entry deleted.',
        ]);
    }

    /**
     * Delete a work entry and, when it mirrors a tracker session, tear down
     * that whole session (screenshots, activity samples) so it also vanishes
     * from the Timeline and Team Performance. Manual entries have no source
     * session and are simply removed. Returns true when a session cascaded.
     */
    private function deleteWorkHourAndSource(WorkHour $workHour, ?User $actor): bool
    {
        $session = $workHour->tracking_session_id
            ? TrackingSession::find($workHour->tracking_session_id)
            : null;

        if (! $session) {
            $workHour->delete();

            return false;
        }

        TrackingAuditLog::record([
            'tracking_session_id' => $session->id,
            'subject_user_id' => $session->user_id,
            'actor_user_id' => $actor?->id,
            'action' => 'session.delete',
            'event_date' => optional($session->started_at)->toDateString(),
            'old_value' => [
                'via' => 'work_diary',
                'total_seconds' => (int) $session->total_seconds,
                'screenshots' => $session->screenshots()->count(),
            ],
            'new_value' => null,
            'reason' => 'Work-diary entry deleted',
        ]);

        // purge() removes the mirrored work-hours row as well.
        app(TrackingSessionService::class)->purge($session);

        return true;
    }

    public function bulkDelete(Request $request)
    {
        if ($request->has('entry_ids') && ! $request->has('ids')) {
            $request->merge(['ids' => $request->input('entry_ids')]);
        }

        $validated = $request->validate([
            // Capped, and validated WITHOUT a per-element exists: rule.
            //
            // `exists:work_hours,id` issues one SELECT per array element, so an
            // uncapped list turned a single POST into tens of thousands of
            // sequential queries before the controller body even ran — and this
            // route carries no permission middleware, so the lowest-privilege
            // member of any tenant could fire it. The rule bought nothing
            // either: the whereIn below already runs through OrganizationScope,
            // so ids from another workspace resolve to nothing. It actively
            // leaked, in fact — exists: is not tenant-scoped, so a 422-vs-no-op
            // difference disclosed whether a row id existed in someone else's
            // organization.
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        $user = auth()->user();
        $ids = $validated['ids'];

        // ONE tenant-scoped read for both the ownership check and the delete
        // loop. This used to run the same whereIn twice (a count() and then a
        // get()) against a table with hundreds of thousands of rows per tenant.
        $query = WorkHour::whereIn('id', $ids);
        if (! $user->hasPermission('work_hours.manage_all')) {
            $query->where('user_id', $user->id);
        }

        // Cascade each entry so tracker-synced ones also remove their session,
        // screenshots, and activity data (not just the work_hours row).
        // Locked (tracker-recorded) entries are skipped for anyone who can't
        // manage all entries — the same rule destroy() applies, so a bulk
        // selection can't be used to get around the single-row lock.
        $entries = $query->get();

        // Anything the caller asked for that this scoped read did not return is
        // either someone else's entry or another tenant's id — same refusal
        // either way, derived from the query we already ran rather than from N
        // extra ones.
        if (! $user->hasPermission('work_hours.manage_all') && $entries->count() !== count(array_unique($ids))) {
            return back()->withErrors([
                'ids' => 'Some entries could not be deleted. You can only delete your own entries.',
            ]);
        }
        $skipped = 0;
        $lockedByApproval = 0;
        $deleted = 0;
        foreach ($entries as $entry) {
            if (! $this->canDeleteEntry($entry, $user)) {
                $skipped++;
                if ($entry->isApprovalLocked()) {
                    $lockedByApproval++;
                }

                continue;
            }
            $this->deleteWorkHourAndSource($entry, $user);
            $deleted++;
        }

        if ($deleted === 0 && $skipped > 0) {
            return back()->withErrors([
                'ids' => $lockedByApproval === $skipped
                    ? 'Those entries sit inside an approved timesheet and are frozen for payroll. Ask an approver to reopen that pay period first.'
                    : 'Tracker-recorded entries can\'t be deleted here. Adjust that day from the Timeline instead.',
            ]);
        }

        return back()->with('success', 'Successfully deleted '.$deleted.' entries.'
            .($skipped > 0
                ? ' '.$skipped.' '.($skipped === 1 ? 'entry was' : 'entries were').' skipped — '
                    .($lockedByApproval > 0 ? 'approved timesheets are frozen' : 'tracked time is locked').'.'
                : ''));
    }

    public function exportPersonal(Request $request)
    {
        $user = auth()->user();
        $query = WorkHour::with('user', 'client', 'workCategory');

        // Only show current user's entries
        $query->where('user_id', $user->id);

        $filter = $request->input('filter', 'all');
        $startDate = $request->input('startDate');
        $endDate = $request->input('endDate');
        $selectedWorkTypes = $this->filterValues($request, 'workTypes', 'workType');
        $selectedTrackers = $this->filterValues($request, 'trackers', 'tracker');
        $selectedClients = $this->filterValues($request, 'clients', 'client');
        $selectedWorkCategories = $this->selectedCategoryIds($request, $this->ownCategoryIds());
        $selectedBillable = $this->selectedBillable($request);
        $idsOnly = $request->input('idsOnly', false);

        // Apply date filter
        if ($filter !== 'all' && $startDate && $endDate) {
            $query->whereBetween('date', [$startDate, $endDate]);
        }

        $this->applyCommonFilters($query, [
            'workTypes' => $selectedWorkTypes,
            'workCategoryIds' => $selectedWorkCategories,
            'billable' => $selectedBillable,
            'trackers' => $selectedTrackers,
            'clients' => $selectedClients,
        ]);

        // If only IDs are requested, return just ID and minimal data for bulk operations
        if ($idsOnly) {
            $workHours = $query->orderByDesc('date')->orderByDesc('id')->get(['id']);
        } else {
            // Get all data without pagination
            $workHours = $query->orderByDesc('date')->orderByDesc('id')->get();
        }

        return response()->json([
            'data' => $workHours,
        ]);
    }

    public function export(Request $request)
    {
        $query = WorkHour::with('user', 'client', 'workCategory');
        $filter = $request->input('filter', 'all');
        $startDate = $request->input('startDate');
        $endDate = $request->input('endDate');
        $selectedWorkTypes = $this->filterValues($request, 'workTypes', 'workType');
        $selectedUserIds = $this->filterValues($request, 'userIds', 'userId');
        $selectedDesignations = $this->filterValues($request, 'designations', 'designation');
        $selectedTrackers = $this->filterValues($request, 'trackers', 'tracker');
        $selectedClients = $this->filterValues($request, 'clients', 'client');
        $selectedWorkCategories = $this->selectedCategoryIds($request, $this->ownCategoryIds());
        $selectedBillable = $this->selectedBillable($request);

        // Apply date filter - only apply if both dates are provided
        if ($startDate && $endDate) {
            $query->whereBetween('date', [$startDate, $endDate]);
        }

        $this->applyCommonFilters($query, [
            'workTypes' => $selectedWorkTypes,
            'workCategoryIds' => $selectedWorkCategories,
            'billable' => $selectedBillable,
            'userIds' => $selectedUserIds,
            'designations' => $selectedDesignations,
            'trackers' => $selectedTrackers,
            'clients' => $selectedClients,
        ]);

        // Get all data without pagination
        $workHours = $query->orderByDesc('date')->orderByDesc('id')->get();

        return response()->json([
            'data' => $workHours,
        ]);
    }

    public function report(Request $request, SlackReportService $slack)
    {
        $query = WorkHour::query();
        $filter = $request->input('filter', 'all');
        $startDate = $request->input('startDate');
        $endDate = $request->input('endDate');

        // DEFAULT WINDOW — see self::DEFAULT_REPORT_FILTER. Applied only when
        // the caller has expressed no opinion at all about dates: no `filter`
        // key and no start/end pair. Everything else is left untouched, so
        // `?filter=all`, a custom range and every existing deep link behave
        // exactly as they did.
        $statedFilter = trim((string) $request->input('filter', ''));
        $statedRange = $startDate && $endDate;

        if ($statedFilter === '' && ! $statedRange) {
            $today = Carbon::now(BusinessTime::tz());
            $filter = self::DEFAULT_REPORT_FILTER;
            $startDate = $today->copy()->subDays(self::DEFAULT_REPORT_DAYS - 1)->toDateString();
            $endDate = $today->toDateString();
        }

        $selectedWorkTypes = $this->filterValues($request, 'workTypes', 'workType');
        $selectedUserIds = $this->filterValues($request, 'userIds', 'userId');
        $selectedDesignations = $this->filterValues($request, 'designations', 'designation');
        $selectedTrackers = $this->filterValues($request, 'trackers', 'tracker');
        $selectedClients = $this->filterValues($request, 'clients', 'client');
        $workCategories = $this->allWorkCategories();
        $selectedWorkCategories = $this->selectedCategoryIds($request, $workCategories->pluck('id')->all());
        $selectedBillable = $this->selectedBillable($request);
        $perPage = $request->input('perPage', 15);

        // Fetch all available designation filter options.
        $availableDesignations = User::whereNotNull('designation')
            ->distinct()
            ->pluck('designation')
            ->filter()
            ->sort()
            ->values();

        $availableTrackers = UpworkProfile::active()
            ->orderBy('name')
            ->pluck('name')
            ->toArray();

        // Which clients have ever been logged against, for the dropdown.
        //
        // A correlated EXISTS rather than `whereIn(... distinct client_id ...)`:
        // the IN form materialises the DISTINCT over EVERY work_hours row the
        // tenant owns (a full scan that grows forever — 11.6ms at 27k rows on
        // the synthetic 120-person org, and this runs on every page load of the
        // report). EXISTS stops at the first matching row per client, so the
        // work is bounded by the CLIENT count, not the work-entry count: 0.2ms
        // on the same data. Correlating on clients.id keeps it tenant-safe —
        // the outer Client query is org-scoped and a work_hours row can only
        // reference a client in its own organization — and the organization_id
        // predicate is stated explicitly anyway so the composite index
        // (organization_id, client_id) serves it directly.
        $availableClients = Client::query()
            ->whereExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('work_hours')
                    ->whereColumn('work_hours.client_id', 'clients.id')
                    ->whereColumn('work_hours.organization_id', 'clients.organization_id');
            })
            ->orderBy('name')
            ->pluck('name')
            ->values();

        // Validate perPage to prevent abuse
        $allowedPerPage = [15, 25, 50, 100];
        if (! in_array($perPage, $allowedPerPage)) {
            $perPage = 15;
        }

        // Apply date filter - only apply if both dates are provided
        if ($startDate && $endDate) {
            $query->whereBetween('date', [$startDate, $endDate]);
        }

        $this->applyCommonFilters($query, [
            'workTypes' => $selectedWorkTypes,
            'workCategoryIds' => $selectedWorkCategories,
            'billable' => $selectedBillable,
            'userIds' => $selectedUserIds,
            'designations' => $selectedDesignations,
            'trackers' => $selectedTrackers,
            'clients' => $selectedClients,
        ]);

        // Server-side search across all pages — the search box previously
        // only filtered the rows already on screen, silently hiding matches
        // that lived on other pages.
        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('tracker', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        // HEADLINE KPIs + TREND — computed over the ENTIRE filtered set, not the
        // page of rows the table happens to show. The strip used to be summed on
        // the client from the current page, so "Total hours / People / Clients"
        // changed as you paged and never matched the report they titled (a
        // 90-day report could headline a single day's 57h next to an 811-page
        // pager). Filters and the server-side search above are already applied to
        // $query, so these inherit them.
        //
        // One bounded aggregate row (SUM + two COUNT DISTINCTs) over work_hours
        // ALONE — no join, no model hydration — so it stays cheap on a tenant
        // with hundreds of thousands of entries. Cloned BEFORE the grouping
        // branch below mutates $query with its LEFT JOINs.
        $summaryRow = (clone $query)
            ->toBase()
            ->selectRaw('COALESCE(SUM(work_hours.hours), 0) as total_hours, COUNT(DISTINCT work_hours.user_id) as people, COUNT(DISTINCT work_hours.client_id) as clients')
            ->first();

        $summary = [
            'total_hours' => round((float) ($summaryRow->total_hours ?? 0), 2),
            'people' => (int) ($summaryRow->people ?? 0),
            'clients' => (int) ($summaryRow->clients ?? 0),
        ];

        // Daily trend across the whole filtered window — one row per calendar day
        // (bounded by the window length), so the chart shows the real shape of
        // the period instead of the few days that landed on the current page.
        // substr(...,1,10) buckets a driver that persists "Y-m-d 00:00:00"
        // (SQLite) and one that stores a bare DATE (MySQL prod) to the same key,
        // so the grouping never depends on how the column is stored.
        $trend = (clone $query)
            ->toBase()
            ->selectRaw('substr(work_hours.date, 1, 10) as day, SUM(work_hours.hours) as hours')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($r) => [
                'day' => (string) $r->day,
                'hours' => round((float) $r->hours, 2),
            ])
            ->all();

        // Merge identical entries (same person · date · client · work category ·
        // billable · work type · tracker · source) into one totalled row, so a
        // client logged three times in a day shows once with the summed hours
        // and an "×3" badge. `?detailed=1` falls back to one row per raw entry.
        //
        // Category and billable join the grouping key because they are what the
        // row now MEANS — two entries that differ on either are genuinely
        // different work and must not be silently added together. In practice
        // both are derived from work_type (which was already in the key), so the
        // merge granularity is unchanged for existing data.
        if ($request->boolean('detailed')) {
            $workHours = $query->with('user', 'client', 'workCategory')
                ->orderByDesc('date')->orderByDesc('id')
                ->paginate($perPage);
        } else {
            // GROUP_CONCAT takes its separator differently per driver: MySQL
            // (prod) wants `SEPARATOR '…'`, SQLite (local dev) takes it as the
            // second argument. Writing only the MySQL form made this whole page
            // a 500 on a local SQLite database.
            $descriptions = (new WorkHour)->getConnection()->getDriverName() === 'sqlite'
                ? "GROUP_CONCAT(NULLIF(work_hours.description, ''), ' · ')"
                : "GROUP_CONCAT(NULLIF(work_hours.description, '') SEPARATOR ' · ')";

            // The grouping key is the eight WORK_HOURS columns only. The three
            // joined display names used to sit in the GROUP BY as well, which
            // was redundant (each is functionally dependent on an id that is
            // already in the key) and expensive: it forced the grouping to run
            // over the joined row set instead of over a covering index on
            // work_hours alone. They are now selected as MIN(...) — the value
            // is constant within a group, so the output is byte-identical
            // (verified row-for-row across several pages and page sizes), and
            // an aggregate is always legal under MySQL's ONLY_FULL_GROUP_BY,
            // which a bare joined column would not reliably be through a LEFT
            // JOIN. ORDER BY uses the select aliases for the same reason.
            //
            // Measured on the synthetic 120-person org (27,514 work entries,
            // SQLite): page query 68.0ms -> 37.3ms.
            $groupColumns = [
                'work_hours.date',
                'work_hours.user_id',
                'work_hours.client_id',
                'work_hours.work_category_id',
                'work_hours.is_billable',
                'work_hours.work_type',
                'work_hours.tracker',
                'work_hours.source',
            ];

            // Laravel's paginate() would build the row COUNT by wrapping this
            // whole statement — three LEFT JOINs and all — in a derived table,
            // i.e. paying for the joins twice for a number the joins cannot
            // change. Counting the groups over work_hours alone is the same
            // number (the joins are on ids already in the key and cannot add or
            // remove groups) and lets the covering index answer it outright:
            // 62.4ms -> 8.4ms on the same data, same 27,514 result.
            $page = Paginator::resolveCurrentPage();
            $total = (clone $query)
                ->select($groupColumns)
                ->groupBy($groupColumns)
                ->toBase()
                ->getCountForPagination();

            $rows = $query
                ->leftJoin('users', 'work_hours.user_id', '=', 'users.id')
                ->leftJoin('clients', 'work_hours.client_id', '=', 'clients.id')
                ->leftJoin('work_categories', 'work_hours.work_category_id', '=', 'work_categories.id')
                ->selectRaw("MIN(work_hours.id) as id, work_hours.date, work_hours.user_id, MIN(users.name) as user_name, work_hours.client_id, MIN(clients.name) as client_name, work_hours.work_category_id, MIN(work_categories.name) as work_category_name, work_hours.is_billable, work_hours.work_type, work_hours.tracker, work_hours.source, SUM(work_hours.hours) as hours, COUNT(*) as entry_count, {$descriptions} as description")
                ->groupBy($groupColumns)
                ->orderByDesc('work_hours.date')->orderBy('user_name')->orderBy('client_name')
                ->forPage($page, $perPage)
                ->get();

            // Same class paginate() returns, so the JSON the React page reads
            // (data / links / meta) is unchanged.
            $workHours = new LengthAwarePaginator($rows, $total, $perPage, $page, [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
            ]);

            $workHours->through(fn ($r) => [
                'id' => (int) $r->id,
                'date' => substr((string) $r->date, 0, 10),
                'user' => ['name' => $r->user_name],
                'client' => $r->client_id ? ['name' => $r->client_name] : null,
                'work_category' => $r->work_category_id
                    ? ['id' => (int) $r->work_category_id, 'name' => $r->work_category_name]
                    : null,
                'is_billable' => (bool) $r->is_billable,
                'capture_method' => $r->source === WorkHour::SOURCE_TRACKER
                    ? WorkHour::CAPTURE_TRACKED
                    : WorkHour::CAPTURE_MANUAL,
                'work_type' => $r->work_type,
                'tracker' => $r->tracker,
                'source' => $r->source,
                'hours' => (float) $r->hours,
                'entry_count' => (int) $r->entry_count,
                'description' => $r->description,
            ]);
        }

        // Preserve query parameters in pagination links
        $workHours->appends($request->query());

        $users = User::orderBy('name')->get(['id', 'name', 'include_in_slack_reports']);

        return Inertia::render('WorkHoursReport', [
            'search' => $search,
            'workHours' => $workHours,
            // Whole-filtered-set headline figures + daily trend, so the KPI strip
            // and chart are stable across pages (see the block above). Entries is
            // read on the client from the paginator's own `total` (the grouped
            // row count), which is already whole-set.
            'summary' => $summary,
            'trend' => $trend,
            'users' => $users,
            'filter' => $filter,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'workType' => $this->firstLegacyValue($selectedWorkTypes),
            'userId' => $this->firstLegacyValue($selectedUserIds),
            'designation' => $this->firstLegacyValue($selectedDesignations),
            'tracker' => $this->firstLegacyValue($selectedTrackers),
            'client' => $this->firstLegacyValue($selectedClients),
            'perPage' => $perPage,
            'billable' => $selectedBillable,
            'workCategories' => $workCategories,
            'availableDesignations' => $availableDesignations,
            'availableTrackers' => $availableTrackers,
            'availableClients' => $availableClients,
            'selectedFilters' => [
                'workTypes' => $selectedWorkTypes,
                'workCategories' => $selectedWorkCategories,
                'billable' => $selectedBillable,
                'userIds' => $selectedUserIds,
                'designations' => $selectedDesignations,
                'trackers' => $selectedTrackers,
                'clients' => $selectedClients,
            ],
            'filterOptions' => [
                'workTypes' => self::WORK_TYPES,
                'workCategories' => $workCategories,
                'captureMethods' => [WorkHour::CAPTURE_TRACKED, WorkHour::CAPTURE_MANUAL],
                'users' => $users,
                'designations' => $availableDesignations,
                'trackers' => $availableTrackers,
                'clients' => $availableClients,
            ],
            'defaultFilter' => self::DEFAULT_REPORT_FILTER,
            // FIRST-RUN vs FILTERED-OUT. Without this the page can only say
            // "no data found — try adjusting your filters", which tells a brand
            // new workspace it did something wrong when in fact it has simply
            // not tracked anything yet. One EXISTS on the tenant index.
            'hasAnyEntries' => WorkHour::query()->exists(),
            'slackConfigured' => $slack->configured(),
            'slackWeeklyEnabled' => (bool) config('services.slack_reports.weekly_enabled'),
            'flash' => [
                'success' => $request->session()->get('success') ?? '',
                'error' => $request->session()->get('error') ?? '',
            ],
        ]);
    }
}
