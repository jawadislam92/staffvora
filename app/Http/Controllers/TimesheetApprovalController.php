<?php

namespace App\Http\Controllers;

use App\Models\PayPeriod;
use App\Models\Timesheet;
use App\Models\TimesheetApproval;
use App\Models\User;
use App\Models\WorkHour;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TIMESHEET APPROVALS — the surface that makes tracked time payable.
 *
 * THE MACHINE
 *   open ──submit──▶ submitted ──approve──▶ approved ──reopen──▶ open
 *                          └────reject────▶ rejected ──submit──▶ submitted
 *
 *   · Only the OWNER submits. There is no "submit on behalf": the submission is
 *     the person's attestation that the period is right, and an approver
 *     signing it for them would make the approval meaningless.
 *   · approve/reject are only legal from `submitted` — an approver cannot close
 *     a period nobody has attested to.
 *   · APPROVED FREEZES THE TIME. Every work_hours row in the period is stamped
 *     with the timesheet id, and WorkHourController then refuses every edit,
 *     delete and new entry inside it (see WorkHour::isApprovalLocked()).
 *   · Reopening is the only way back, is separately permissioned, and is
 *     audited like everything else.
 *
 * WHO MAY APPROVE (the self-approval rule, stated plainly)
 *   `timesheets.approve`      review anyone ELSE's timesheet. It explicitly
 *                             does NOT allow approving your own — the person
 *                             who logged the hours must not be the person who
 *                             signs them off.
 *   `timesheets.approve_own`  the strictly higher grant that ALSO allows
 *                             approving your own. Meant for the workspace owner
 *                             / a solo operator, who would otherwise never be
 *                             able to close a period. Super Admins hold every
 *                             permission, so a one-person workspace works out of
 *                             the box.
 *   `timesheets.reopen`       unlock an approved timesheet. Separate because it
 *                             un-freezes a number payroll may already have paid.
 *
 * SCALE. Nothing on these screens loops over work_hours in PHP. Every figure is
 * a SQL aggregate (SUM/COUNT/GROUP BY) over org-leading indexes, the roster is
 * paginated, the period picker is capped at PayPeriod::MAX_HISTORY, and bulk
 * review is capped and chunked.
 *
 * SECURITY. The period NEVER comes from the client as a date range — only as a
 * key that PayPeriod::fromKey() re-derives and rejects unless it is a genuine
 * boundary. Every user id is validated against the CURRENT organization before
 * it reaches a query, and no mutation trusts a client-supplied hour count.
 */
class TimesheetApprovalController extends Controller
{
    /** Roster page size on the approver queue. */
    private const PER_PAGE = 25;

    /** Hard ceiling on one bulk review, so a single POST stays bounded. */
    private const MAX_BULK = 200;

    /** How many periods the picker offers. */
    private const PERIOD_CHOICES = 8;

    // ─────────────────────────────────────────────────────────────────────────
    // Permission helpers. Checked explicitly (never inferred from an implication
    // table) so the rules hold regardless of how config/access.php is wired.
    // ─────────────────────────────────────────────────────────────────────────

    private function canReview(?User $user): bool
    {
        return (bool) $user?->hasAnyPermission(['timesheets.approve', 'timesheets.approve_own']);
    }

    private function canReviewSelf(?User $user): bool
    {
        return (bool) $user?->hasPermission('timesheets.approve_own');
    }

    private function canReopen(?User $user): bool
    {
        return (bool) $user?->hasPermission('timesheets.reopen');
    }

    /**
     * The period a request is talking about. An absent/foreign/invented key
     * falls back to the current period rather than erroring — but it can never
     * become an arbitrary range.
     */
    private function resolvePeriod(Request $request): PayPeriod
    {
        return PayPeriod::fromKey($request->input('period')) ?? PayPeriod::current();
    }

    /**
     * Validation for a submitted user id: it MUST belong to the CURRENT
     * organization. An id from another tenant fails validation outright — it is
     * never merely filtered out later.
     */
    private function orgUserRule(?int $organizationId): array
    {
        return [
            'required',
            'integer',
            Rule::exists('users', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // READ
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The Timesheets screen: your own period on top, and — for an approver —
     * the queue of everyone else's underneath.
     *
     * Query budget for the approver view: 1 paginated roster read + 1 grouped
     * SUM over work_hours for that page's users + 1 read of those users'
     * timesheets + 1 grouped COUNT for the period rollup + 1 COUNT for the
     * global pending badge. It does not change with the size of work_hours.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $period = $this->resolvePeriod($request);
        $review = $this->canReview($user);

        $ownTimesheet = Timesheet::query()
            ->where('user_id', $user->id)
            ->forPeriod($period)
            ->first();

        $ownTotals = Timesheet::totalsByUser($period->startDate(), $period->endDate(), [$user->id])[$user->id]
            ?? Timesheet::emptyTotals();

        $payload = [
            'period' => $period->toArray(),
            'periods' => array_map(fn (PayPeriod $p) => $p->toArray(), PayPeriod::recent(self::PERIOD_CHOICES)),
            'cadence' => [
                'key' => PayPeriod::cadence(),
                'label' => PayPeriod::CADENCE_LABELS[PayPeriod::cadence()] ?? PayPeriod::cadence(),
            ],
            'mine' => [
                'user' => ['id' => (int) $user->id, 'name' => $user->name],
                'status' => Timesheet::statusFor($ownTimesheet),
                'timesheet' => $ownTimesheet?->toPayload(),
                'totals' => $ownTotals,
                // A period that has not begun has nothing to attest to.
                'can_submit' => ! $period->isFuture()
                    && in_array(Timesheet::statusFor($ownTimesheet), [Timesheet::STATUS_OPEN, Timesheet::STATUS_REJECTED], true),
            ],
            'can' => [
                'review' => $review,
                'review_self' => $this->canReviewSelf($user),
                'reopen' => $this->canReopen($user),
            ],
            'filters' => ['status' => $this->resolveStatusFilter($request)],
            'queue' => null,
            'summary' => null,
        ];

        if ($review) {
            $payload['queue'] = $this->buildQueue($request, $period, $user);
            $payload['summary'] = $this->buildSummary($period);
        }

        return Inertia::render('Timesheets/Index', $payload);
    }

    /** all | open | submitted | approved | rejected */
    private function resolveStatusFilter(Request $request): string
    {
        $value = (string) $request->input('status', 'all');

        return in_array($value, Timesheet::STATUSES, true) ? $value : 'all';
    }

    /**
     * The approver queue for one period, paginated over the ACTIVE roster.
     *
     * The status filter is applied as an EXISTS subquery against
     * timesheets_org_period_user_idx rather than by loading ids into PHP, so it
     * costs one index lookup and stays flat as the roster grows.
     */
    private function buildQueue(Request $request, PayPeriod $period, User $viewer): array
    {
        $status = $this->resolveStatusFilter($request);

        $roster = User::query()
            ->active()
            ->when($status !== 'all', function ($query) use ($status, $period) {
                $matching = Timesheet::query()
                    ->select('user_id')
                    ->forPeriod($period)
                    ->where('status', $status);

                // "open" is the ABSENCE of a row as much as a stored status, so
                // it has to mean both.
                return $status === Timesheet::STATUS_OPEN
                    ? $query->where(function ($q) use ($matching, $period) {
                        $q->whereIn('id', $matching)
                            ->orWhereNotIn('id', Timesheet::query()->select('user_id')->forPeriod($period));
                    })
                    : $query->whereIn('id', $matching);
            })
            ->orderBy('name')
            ->paginate(self::PER_PAGE, ['id', 'name', 'email', 'designation'])
            ->withQueryString();

        $userIds = collect($roster->items())->pluck('id')->map(fn ($id) => (int) $id)->all();

        $totals = Timesheet::totalsByUser($period->startDate(), $period->endDate(), $userIds);

        $sheets = Timesheet::query()
            ->forPeriod($period)
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy('user_id');

        $rows = collect($roster->items())->map(function ($person) use ($totals, $sheets, $viewer) {
            $sheet = $sheets->get($person->id);
            $sheetStatus = Timesheet::statusFor($sheet);
            $isSelf = (int) $person->id === (int) $viewer->id;

            return [
                'user' => [
                    'id' => (int) $person->id,
                    'name' => $person->name,
                    'email' => $person->email,
                    'designation' => $person->designation,
                ],
                'status' => $sheetStatus,
                'timesheet' => $sheet?->toPayload(),
                'totals' => $totals[(int) $person->id] ?? Timesheet::emptyTotals(),
                'is_self' => $isSelf,
                // Exactly the rule the server enforces, mirrored so the UI can
                // disable rather than surprise.
                'can_review' => $sheetStatus === Timesheet::STATUS_SUBMITTED
                    && (! $isSelf || $this->canReviewSelf($viewer)),
                'can_reopen' => $sheetStatus === Timesheet::STATUS_APPROVED && $this->canReopen($viewer),
            ];
        })->all();

        return [
            'rows' => $rows,
            'links' => $roster->linkCollection()->toArray(),
            'meta' => [
                'current_page' => $roster->currentPage(),
                'last_page' => $roster->lastPage(),
                'per_page' => $roster->perPage(),
                'total' => $roster->total(),
                'from' => $roster->firstItem(),
                'to' => $roster->lastItem(),
            ],
        ];
    }

    /** Period rollup + the "waiting on me" badge. Both pure SQL counts. */
    private function buildSummary(PayPeriod $period): array
    {
        $counts = Timesheet::statusCounts($period);
        $people = User::query()->active()->count();

        // Everyone without a row is open, by definition.
        $counts[Timesheet::STATUS_OPEN] = max(
            0,
            $people - $counts[Timesheet::STATUS_SUBMITTED] - $counts[Timesheet::STATUS_APPROVED] - $counts[Timesheet::STATUS_REJECTED]
        );

        return [
            'people' => $people,
            'counts' => $counts,
            // Across every period, not just this one — the number the approver
            // actually needs to see. Rides timesheets_org_status_start_idx.
            'pending_all_periods' => Timesheet::query()->pending()->count(),
        ];
    }

    /**
     * One person's period in detail: the totals, the category breakdown, the
     * manual-vs-tracked split, a day-by-day bar, and the full audit trail.
     *
     * Everything is aggregated in SQL; individual work_hours rows are never
     * loaded here. Visible to the person themselves, or to an approver.
     */
    public function show(Request $request, User $user): Response
    {
        $viewer = $request->user();
        $isSelf = (int) $user->id === (int) $viewer->id;

        abort_unless($isSelf || $this->canReview($viewer), 403, 'You can only open your own timesheet.');

        $period = $this->resolvePeriod($request);

        $timesheet = Timesheet::query()
            ->where('user_id', $user->id)
            ->forPeriod($period)
            ->first();

        $status = Timesheet::statusFor($timesheet);

        $history = $timesheet
            ? $timesheet->approvals()->with('actor:id,name')->orderByDesc('id')->limit(50)->get()
                ->map(fn (TimesheetApproval $row) => $row->toPayload())->all()
            : [];

        return Inertia::render('Timesheets/Show', [
            'period' => $period->toArray(),
            'periods' => array_map(fn (PayPeriod $p) => $p->toArray(), PayPeriod::recent(self::PERIOD_CHOICES)),
            'person' => [
                'id' => (int) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'designation' => $user->designation,
            ],
            'status' => $status,
            'timesheet' => $timesheet?->toPayload(),
            'totals' => Timesheet::totalsByUser($period->startDate(), $period->endDate(), [$user->id])[$user->id]
                ?? Timesheet::emptyTotals(),
            'categories' => Timesheet::categoryBreakdown($user->id, $period->startDate(), $period->endDate()),
            'daily' => Timesheet::dailyBreakdown($user->id, $period->startDate(), $period->endDate()),
            'history' => $history,
            'is_self' => $isSelf,
            'can' => [
                'submit' => $isSelf
                    && ! $period->isFuture()
                    && in_array($status, [Timesheet::STATUS_OPEN, Timesheet::STATUS_REJECTED], true),
                'review' => $status === Timesheet::STATUS_SUBMITTED
                    && $this->canReview($viewer)
                    && (! $isSelf || $this->canReviewSelf($viewer)),
                'reopen' => $status === Timesheet::STATUS_APPROVED && $this->canReopen($viewer),
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WRITE — the state machine
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * SUBMIT YOUR OWN timesheet. There is no user id in the payload by design:
     * the subject is always the authenticated person.
     */
    public function submit(Request $request): RedirectResponse
    {
        $request->validate([
            'period' => ['required', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        $period = PayPeriod::fromKey($request->input('period'));

        if (! $period) {
            throw ValidationException::withMessages(['period' => 'That pay period could not be recognised.']);
        }

        if ($period->isFuture()) {
            throw ValidationException::withMessages(['period' => 'That pay period hasn\'t started yet.']);
        }

        $note = $this->cleanNote($request->input('note'));

        DB::transaction(function () use ($user, $period, $note) {
            $timesheet = $this->lockedTimesheet($user->id, $period);
            $from = $timesheet->status;

            if ($from === Timesheet::STATUS_SUBMITTED) {
                throw ValidationException::withMessages(['period' => 'That timesheet is already submitted and waiting for review.']);
            }

            if ($from === Timesheet::STATUS_APPROVED) {
                throw ValidationException::withMessages(['period' => 'That timesheet is approved. It has to be reopened before it can change.']);
            }

            $totals = $this->totalsFor($user->id, $period);

            $timesheet->fill([
                'status' => Timesheet::STATUS_SUBMITTED,
                'submitted_at' => now(),
                'submitted_by' => $user->id,
                'reviewed_at' => null,
                'reviewed_by' => null,
                'note' => $note,
            ])->save();

            TimesheetApproval::record(
                $timesheet, TimesheetApproval::ACTION_SUBMITTED, $from, Timesheet::STATUS_SUBMITTED, $user->id, $note, $totals
            );
        });

        return back()->with('success', 'Timesheet submitted for '.$period->label().'.');
    }

    /**
     * APPROVE or REJECT one person's period.
     *
     * Route-gated by `permission:timesheets.approve`; the self-approval rule is
     * enforced here because the middleware cannot see who the subject is.
     */
    public function review(Request $request): RedirectResponse
    {
        $actor = $request->user();

        $data = $request->validate([
            'user_id' => $this->orgUserRule($actor?->organization_id),
            'period' => ['required', 'string', 'max:40'],
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $period = PayPeriod::fromKey($data['period']);

        if (! $period) {
            throw ValidationException::withMessages(['period' => 'That pay period could not be recognised.']);
        }

        $subjectId = (int) $data['user_id'];

        if ($subjectId === (int) $actor->id && ! $this->canReviewSelf($actor)) {
            throw ValidationException::withMessages([
                'user_id' => 'You can\'t approve your own timesheet. Someone with the "approve own timesheet" permission has to review it.',
            ]);
        }

        $note = $this->cleanNote($request->input('note'));

        if ($data['decision'] === 'reject' && $note === null) {
            throw ValidationException::withMessages([
                'note' => 'Say why it\'s being sent back — the person needs to know what to fix.',
            ]);
        }

        $applied = DB::transaction(fn () => $this->applyDecision($subjectId, $period, $data['decision'], $note, $actor));

        return back()->with(
            'success',
            $applied
                ? ($data['decision'] === 'approve'
                    ? 'Timesheet approved for '.$period->label().'. Its time entries are now locked.'
                    : 'Timesheet sent back for '.$period->label().'.')
                : 'Nothing to review — that timesheet is not awaiting approval.'
        );
    }

    /**
     * BULK REVIEW — close a whole period in one action.
     *
     * With no `user_ids` it takes everyone whose timesheet is currently
     * SUBMITTED for that period (capped), which is the "approve the queue"
     * button. Each person's lock is its own indexed UPDATE, so the work is
     * bounded by MAX_BULK and never by the size of work_hours.
     */
    public function bulkReview(Request $request): RedirectResponse
    {
        $actor = $request->user();

        $data = $request->validate([
            'period' => ['required', 'string', 'max:40'],
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'string', 'max:1000'],
            // Capped, and validated WITHOUT a per-element exists: rule — the
            // org-scoped read below is what proves ownership, in one query
            // instead of one per id (and without leaking whether a foreign id
            // exists).
            'user_ids' => ['nullable', 'array', 'max:'.self::MAX_BULK],
            'user_ids.*' => ['integer'],
        ]);

        $period = PayPeriod::fromKey($data['period']);

        if (! $period) {
            throw ValidationException::withMessages(['period' => 'That pay period could not be recognised.']);
        }

        $note = $this->cleanNote($request->input('note'));

        if ($data['decision'] === 'reject' && $note === null) {
            throw ValidationException::withMessages(['note' => 'Say why these are being sent back.']);
        }

        // One org-scoped read decides the whole batch. Ids from another tenant
        // simply do not come back.
        $candidates = Timesheet::query()
            ->forPeriod($period)
            ->pending()
            ->when(! empty($data['user_ids']), fn ($q) => $q->whereIn('user_id', array_map('intval', $data['user_ids'])))
            ->orderBy('user_id')
            ->limit(self::MAX_BULK)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $selfBlocked = 0;
        $done = 0;

        foreach (array_chunk($candidates, 25) as $chunk) {
            DB::transaction(function () use ($chunk, $period, $data, $note, $actor, &$done, &$selfBlocked) {
                foreach ($chunk as $subjectId) {
                    if ($subjectId === (int) $actor->id && ! $this->canReviewSelf($actor)) {
                        $selfBlocked++;

                        continue;
                    }

                    if ($this->applyDecision($subjectId, $period, $data['decision'], $note, $actor)) {
                        $done++;
                    }
                }
            });
        }

        $verb = $data['decision'] === 'approve' ? 'Approved' : 'Sent back';

        return back()->with('success', $verb.' '.$done.' '.($done === 1 ? 'timesheet' : 'timesheets').' for '.$period->label().'.'
            .($selfBlocked > 0 ? ' Your own timesheet was skipped — it needs a different approver.' : ''));
    }

    /**
     * REOPEN an approved timesheet: unfreeze the period so the time can be
     * corrected. Separately permissioned because it un-locks a number payroll
     * may already have paid against.
     */
    public function reopen(Request $request): RedirectResponse
    {
        $actor = $request->user();

        $data = $request->validate([
            'user_id' => $this->orgUserRule($actor?->organization_id),
            'period' => ['required', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $period = PayPeriod::fromKey($data['period']);

        if (! $period) {
            throw ValidationException::withMessages(['period' => 'That pay period could not be recognised.']);
        }

        $note = $this->cleanNote($request->input('note'));

        DB::transaction(function () use ($data, $period, $note, $actor) {
            $timesheet = Timesheet::query()
                ->where('user_id', (int) $data['user_id'])
                ->forPeriod($period)
                ->lockForUpdate()
                ->first();

            if (! $timesheet || ! $timesheet->isApproved()) {
                throw ValidationException::withMessages(['user_id' => 'That timesheet is not approved, so there is nothing to reopen.']);
            }

            // Unfreeze the time: one indexed UPDATE over work_hours_timesheet_idx.
            WorkHour::query()->where('timesheet_id', $timesheet->id)->update(['timesheet_id' => null]);

            $timesheet->fill([
                'status' => Timesheet::STATUS_OPEN,
                'locked_at' => null,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
                'note' => $note,
            ])->save();

            TimesheetApproval::record(
                $timesheet, TimesheetApproval::ACTION_REOPENED, Timesheet::STATUS_APPROVED, Timesheet::STATUS_OPEN, $actor->id, $note
            );
        });

        return back()->with('success', 'Timesheet reopened for '.$period->label().'. Its time entries can be edited again.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Apply one decision to one person's period inside an open transaction.
     * Returns false when the timesheet is not (or is no longer) awaiting
     * review, which is what makes a double-submitted button harmless.
     */
    private function applyDecision(int $subjectId, PayPeriod $period, string $decision, ?string $note, User $actor): bool
    {
        $timesheet = Timesheet::query()
            ->where('user_id', $subjectId)
            ->forPeriod($period)
            ->lockForUpdate()
            ->first();

        // Only a SUBMITTED timesheet can be approved or rejected: an approver
        // must never be able to close a period the person never attested to.
        if (! $timesheet || ! $timesheet->isSubmitted()) {
            return false;
        }

        $totals = $this->totalsFor($subjectId, $period);

        if ($decision === 'approve') {
            $timesheet->fill([
                'status' => Timesheet::STATUS_APPROVED,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
                'locked_at' => now(),
                'note' => $note,
                // Frozen at this instant: if the period is later reopened and
                // corrected, what was approved stays legible.
                'total_hours' => $totals['total'],
                'billable_hours' => $totals['billable'],
                'tracked_hours' => $totals['tracked'],
                'manual_hours' => $totals['manual'],
                'entries_count' => $totals['entries'],
            ])->save();

            // THE LOCK. One indexed UPDATE over
            // work_hours_org_user_date_idx — never a per-row loop.
            WorkHour::query()
                ->where('user_id', $subjectId)
                ->whereBetween('date', [$period->startDate(), $period->endDate()])
                ->update(['timesheet_id' => $timesheet->id]);

            TimesheetApproval::record(
                $timesheet, TimesheetApproval::ACTION_APPROVED, Timesheet::STATUS_SUBMITTED, Timesheet::STATUS_APPROVED, $actor->id, $note, $totals
            );

            return true;
        }

        $timesheet->fill([
            'status' => Timesheet::STATUS_REJECTED,
            'reviewed_at' => now(),
            'reviewed_by' => $actor->id,
            'locked_at' => null,
            'note' => $note,
        ])->save();

        TimesheetApproval::record(
            $timesheet, TimesheetApproval::ACTION_REJECTED, Timesheet::STATUS_SUBMITTED, Timesheet::STATUS_REJECTED, $actor->id, $note, $totals
        );

        return true;
    }

    /**
     * The person's row for that period, created on first touch and locked for
     * the rest of the transaction.
     *
     * A row that does not exist yet cannot be row-locked, so two simultaneous
     * submits (a double-click, a retried request) can both reach the insert.
     * timesheets_user_period_unique is what actually prevents the duplicate;
     * catching its violation and re-reading turns that race into the correct
     * answer instead of a 500.
     */
    private function lockedTimesheet(int $userId, PayPeriod $period): Timesheet
    {
        $find = fn (bool $lock) => Timesheet::query()
            ->where('user_id', $userId)
            ->forPeriod($period)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->first();

        if ($existing = $find(true)) {
            return $existing;
        }

        try {
            // organization_id is stamped by BelongsToOrganization — never hardcoded.
            return Timesheet::create([
                'user_id' => $userId,
                'period_key' => $period->key(),
                'cadence' => $period->cadence,
                'period_start' => $period->startDate(),
                'period_end' => $period->endDate(),
                'status' => Timesheet::STATUS_OPEN,
            ]);
        } catch (QueryException $e) {
            $winner = $find(true);

            if (! $winner) {
                throw $e;
            }

            return $winner;
        }
    }

    /** Server-derived totals. A client-supplied hour count is never trusted. */
    private function totalsFor(int $userId, PayPeriod $period): array
    {
        return Timesheet::totalsByUser($period->startDate(), $period->endDate(), [$userId])[$userId]
            ?? Timesheet::emptyTotals();
    }

    private function cleanNote(mixed $raw): ?string
    {
        $note = trim((string) ($raw ?? ''));

        return $note === '' ? null : mb_substr($note, 0, 1000);
    }
}
