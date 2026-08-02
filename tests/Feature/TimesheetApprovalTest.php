<?php

namespace Tests\Feature;

use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TimesheetApprovalController;
use App\Models\MonitoringSetting;
use App\Models\PayPeriod;
use App\Models\Timesheet;
use App\Models\TimesheetApproval;
use App\Models\User;
use App\Models\WorkCategory;
use App\Models\WorkHour;
use App\Services\TenantProvisioner;
use App\Support\BusinessTime;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TIMESHEET APPROVALS + PAY PERIODS.
 *
 * What these tests defend, in the order it matters:
 *
 *  1. THE STATE MACHINE. open → submitted → approved | rejected → reopened.
 *     Every illegal edge is a refusal, not a silent no-op that looks like it
 *     worked: you cannot approve what was never submitted, cannot submit
 *     someone else's period, and cannot approve your own without the higher
 *     grant that exists precisely for that case.
 *  2. THE LOCK. An approved timesheet FREEZES its time. If an entry inside one
 *     can still be edited, deleted, or added to — by anyone, at any permission
 *     level — the whole feature is theatre and payroll pays the wrong number.
 *  3. AGGREGATION CORRECTNESS. The totals are SQL sums, and the billable /
 *     tracked / manual split has to match what the diary actually holds.
 *  4. TENANCY. A user id from another workspace must fail validation and write
 *     nothing — never merely be filtered out afterwards.
 *  5. PERIOD DERIVATION. Periods are derived, not stored, and a client-supplied
 *     key can never become an arbitrary date range.
 */
class TimesheetApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The root Blade template resolves page components through the Vite
        // manifest, which only lists pages that have been BUILT. A brand-new
        // page would 500 for a reason unrelated to what is under test.
        $this->withoutVite();

        $this->registerTimesheetRoutes();
        $this->registerTimesheetPermissions();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Harness
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The web routes for this feature, registered here ONLY while they are
     * absent from routes/web.php (that file belongs to another agent). The
     * definitions are byte-for-byte the ones returned for registration, so
     * these tests exercise the real middleware stack and keep passing unchanged
     * the moment the real lines land.
     */
    private function registerTimesheetRoutes(): void
    {
        if (Route::has('timesheets.index')) {
            return;
        }

        $existing = Route::getRoutes()->getRoutes();
        $before = count($existing);

        Route::middleware(['web', 'auth'])->group(function () {
            Route::prefix('timesheets')->group(function () {
                Route::get('/', [TimesheetApprovalController::class, 'index'])->name('timesheets.index');
                Route::post('/submit', [TimesheetApprovalController::class, 'submit'])->name('timesheets.submit');
                Route::post('/review', [TimesheetApprovalController::class, 'review'])
                    ->middleware('permission:timesheets.approve,timesheets.approve_own')
                    ->name('timesheets.review');
                Route::post('/bulk-review', [TimesheetApprovalController::class, 'bulkReview'])
                    ->middleware('permission:timesheets.approve,timesheets.approve_own')
                    ->name('timesheets.bulk-review');
                Route::post('/reopen', [TimesheetApprovalController::class, 'reopen'])
                    ->middleware('permission:timesheets.reopen')
                    ->name('timesheets.reopen');
                Route::get('/{user}', [TimesheetApprovalController::class, 'show'])
                    ->whereNumber('user')->name('timesheets.show');
            });

            // The cadence setting belongs inside the existing settings group.
            Route::put('/settings/pay-period', [SettingsController::class, 'updatePayPeriod'])
                ->middleware('permission:monitoring.settings')
                ->name('settings.pay-period.update');
        });

        // ⚠ ORDER MATTERS, AND NOT ONLY IN THIS TEST. routes/web.php ends with
        // the marketing catch-all `Route::get('/{slug}')`, which swallows ANY
        // GET registered after it. The real lines must live inside the existing
        // `Route::middleware(['auth'])` group, well above that catch-all — so
        // the collection is rebuilt with ours in front to reproduce it here.
        $added = array_slice(Route::getRoutes()->getRoutes(), $before);

        $collection = new RouteCollection;

        foreach ($added as $route) {
            $collection->add($route);
        }

        foreach ($existing as $route) {
            $collection->add($route);
        }

        $collection->refreshNameLookups();
        $collection->refreshActionLookups();

        Route::setRoutes($collection);
    }

    /**
     * PermissionMiddleware silently drops any key that is not in the catalogue,
     * so a person granted `timesheets.approve` would 403 forever if these were
     * missing. config/access.php belongs to another agent.
     */
    private function registerTimesheetPermissions(): void
    {
        $permissions = config('access.permissions');

        if (isset($permissions['Timesheets'])) {
            return;
        }

        $permissions['Timesheets'] = [
            'timesheets.approve' => [
                'label' => 'Approve timesheets',
                'description' => 'Review, approve and send back other people\'s timesheets.',
            ],
            'timesheets.approve_own' => [
                'label' => 'Approve own timesheet',
                'description' => 'Also allowed to approve their own timesheet.',
            ],
            'timesheets.reopen' => [
                'label' => 'Reopen approved timesheets',
                'description' => 'Unlock an approved pay period so its time can be corrected.',
            ],
        ];

        config()->set('access.permissions', $permissions);
    }

    /** A fresh tenant, bound, with the owner returned. */
    private function tenant(string $name, string $email): User
    {
        $owner = app(TenantProvisioner::class)->provision($name, 'Owner', $email, 'secret-pass-123')['owner'];

        User::withoutGlobalScopes()->whereKey($owner->id)->update(['email_verified_at' => now()]);

        app(CurrentOrganization::class)->set($owner->organization_id);
        BusinessTime::flushTzCache();

        return $owner->fresh();
    }

    /** A colleague inside the bound tenant, with the given permissions. */
    private function member(User $owner, string $name, string $email, array $permissions = []): User
    {
        app(CurrentOrganization::class)->set($owner->organization_id);

        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('secret-pass-123'),
            'role' => 'member',
            'permissions' => $permissions,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function asTenant(User $user): self
    {
        $this->app['auth']->forgetGuards();
        app(CurrentOrganization::class)->set($user->organization_id);
        BusinessTime::flushTzCache();
        $this->actingAs($user);

        return $this;
    }

    /** Force a known cadence so every assertion below is date-stable. */
    private function useWeeklyPeriods(User $owner, string $anchor = '2026-01-05'): PayPeriod
    {
        app(CurrentOrganization::class)->set($owner->organization_id);

        $settings = MonitoringSetting::current();
        $settings->pay_period_cadence = PayPeriod::WEEKLY;
        $settings->pay_period_anchor_date = $anchor;
        $settings->save();
        MonitoringSetting::forgetSharedCache();

        return PayPeriod::current();
    }

    /** A work entry on a given day for a given person. */
    private function entry(User $user, string $date, float $hours, array $overrides = []): WorkHour
    {
        app(CurrentOrganization::class)->set($user->organization_id);

        return WorkHour::create(array_merge([
            'user_id' => $user->id,
            'date' => $date,
            'hours' => $hours,
            'description' => 'Work on '.$date,
            'work_type' => 'office_work',
            'is_billable' => true,
            'source' => 'manual',
        ], $overrides));
    }

    /** Submit the acting user's own timesheet for a period. */
    private function submit(User $user, PayPeriod $period, ?string $note = null)
    {
        return $this->asTenant($user)->post(route('timesheets.submit'), array_filter([
            'period' => $period->key(),
            'note' => $note,
        ]));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. PERIOD DERIVATION — derived, never stored, never client-controlled
    // ─────────────────────────────────────────────────────────────────────────

    public function test_weekly_periods_tile_the_calendar_from_the_anchor(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');
        $this->useWeeklyPeriods($owner, '2026-01-05'); // a Monday

        $period = PayPeriod::containing('2026-07-23');

        $this->assertSame('2026-07-20', $period->startDate());
        $this->assertSame('2026-07-26', $period->endDate());
        $this->assertSame(7, $period->days());
        $this->assertSame('weekly:2026-07-20', $period->key());

        // Days BEFORE the anchor must tile too — floor division, not truncation.
        $earlier = PayPeriod::containing('2025-12-30');
        $this->assertSame('2025-12-29', $earlier->startDate());
        $this->assertSame('2026-01-04', $earlier->endDate());
    }

    public function test_every_cadence_produces_the_window_it_promises(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');
        app(CurrentOrganization::class)->set($owner->organization_id);

        $fortnight = PayPeriod::containing('2026-07-23', PayPeriod::BIWEEKLY);
        $this->assertSame(14, $fortnight->days());

        $firstHalf = PayPeriod::containing('2026-07-09', PayPeriod::SEMIMONTHLY);
        $this->assertSame('2026-07-01', $firstHalf->startDate());
        $this->assertSame('2026-07-15', $firstHalf->endDate());

        $secondHalf = PayPeriod::containing('2026-07-16', PayPeriod::SEMIMONTHLY);
        $this->assertSame('2026-07-16', $secondHalf->startDate());
        $this->assertSame('2026-07-31', $secondHalf->endDate());

        $month = PayPeriod::containing('2026-02-17', PayPeriod::MONTHLY);
        $this->assertSame('2026-02-01', $month->startDate());
        $this->assertSame('2026-02-28', $month->endDate());
    }

    public function test_a_forged_period_key_is_refused_rather_than_widened(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');
        $this->useWeeklyPeriods($owner, '2026-01-05');

        // A real boundary round-trips.
        $this->assertNotNull(PayPeriod::fromKey('weekly:2026-07-20'));

        // A mid-period date is NOT a boundary: accepting it would let a caller
        // slide the window and change what gets summed (and locked).
        $this->assertNull(PayPeriod::fromKey('weekly:2026-07-22'));

        // Unknown cadence, junk, and a missing separator are all refused.
        $this->assertNull(PayPeriod::fromKey('yearly:2026-01-01'));
        $this->assertNull(PayPeriod::fromKey('weekly:not-a-date'));
        $this->assertNull(PayPeriod::fromKey('2026-07-20'));
        $this->assertNull(PayPeriod::fromKey(null));
    }

    public function test_the_period_picker_is_bounded(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');
        $this->useWeeklyPeriods($owner);

        $this->assertCount(8, PayPeriod::recent(8));
        // A caller asking for a thousand periods gets the cap, not a thousand.
        $this->assertCount(PayPeriod::MAX_HISTORY, PayPeriod::recent(1000));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. AGGREGATION CORRECTNESS
    // ─────────────────────────────────────────────────────────────────────────

    public function test_totals_are_sql_sums_with_the_right_billable_and_capture_split(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');
        $period = $this->useWeeklyPeriods($owner);
        $start = $period->startDate();

        $this->entry($owner, $start, 4.0, ['is_billable' => true]);
        $this->entry($owner, $start, 2.5, ['is_billable' => false]);
        // Tracker-mirrored time: measured, not typed.
        $this->entry($owner, $start, 3.25, ['is_billable' => true, 'source' => WorkHour::SOURCE_TRACKER]);
        // Outside the period — must NOT be counted.
        $this->entry($owner, $period->previous()->endDate(), 9.0);

        $totals = Timesheet::totalsByUser($period->startDate(), $period->endDate(), [$owner->id])[$owner->id];

        $this->assertEqualsWithDelta(9.75, $totals['total'], 0.001);
        $this->assertEqualsWithDelta(7.25, $totals['billable'], 0.001);
        $this->assertEqualsWithDelta(3.25, $totals['tracked'], 0.001);
        $this->assertEqualsWithDelta(6.5, $totals['manual'], 0.001);
        $this->assertSame(3, $totals['entries']);
    }

    public function test_a_person_with_no_time_reads_as_zero_rather_than_missing(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');
        $period = $this->useWeeklyPeriods($owner);

        $this->assertSame([], Timesheet::totalsByUser($period->startDate(), $period->endDate(), [$owner->id]));
        $this->assertSame(0.0, Timesheet::emptyTotals()['total']);
    }

    public function test_the_approver_queue_does_not_scale_with_the_number_of_time_entries(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');
        $period = $this->useWeeklyPeriods($owner);
        $this->member($owner, 'Aqsa', 'aqsa@acme.test');

        for ($i = 0; $i < 40; $i++) {
            $this->entry($owner, $period->startDate(), 0.25);
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->asTenant($owner)->get(route('timesheets.index', ['period' => $period->key()]))->assertOk();

        // A page that summed rows in PHP would still "pass" — but a page that
        // fetched them would not stay in this budget as entries grow. The point
        // of the number is that it is FLAT: the same page with 40 entries costs
        // what it costs with 4.
        $this->assertLessThan(30, $queries, 'The timesheets page should aggregate in SQL, not fetch entries.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. THE STATE MACHINE
    // ─────────────────────────────────────────────────────────────────────────

    public function test_submitting_creates_the_timesheet_and_audits_the_transition(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');
        $period = $this->useWeeklyPeriods($owner);
        $this->entry($owner, $period->startDate(), 6.0);

        $this->submit($owner, $period, 'All done.')->assertRedirect();

        app(CurrentOrganization::class)->set($owner->organization_id);
        $sheet = Timesheet::where('user_id', $owner->id)->first();

        $this->assertNotNull($sheet);
        $this->assertSame(Timesheet::STATUS_SUBMITTED, $sheet->status);
        $this->assertSame($period->key(), $sheet->period_key);
        $this->assertSame((int) $owner->organization_id, (int) $sheet->organization_id);
        $this->assertSame('All done.', $sheet->note);

        $audit = TimesheetApproval::where('timesheet_id', $sheet->id)->first();
        $this->assertSame(TimesheetApproval::ACTION_SUBMITTED, $audit->action);
        $this->assertSame(Timesheet::STATUS_OPEN, $audit->from_status);
        $this->assertSame((int) $owner->id, (int) $audit->actor_user_id);
        $this->assertEqualsWithDelta(6.0, $audit->total_hours, 0.001);
    }

    public function test_submitting_only_ever_touches_your_own_timesheet(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');
        $period = $this->useWeeklyPeriods($owner);
        $mate = $this->member($owner, 'Aqsa', 'aqsa@acme.test');

        // The endpoint takes NO user id by design — a crafted one is ignored,
        // and the submission lands on the authenticated person.
        $this->asTenant($mate)->post(route('timesheets.submit'), [
            'period' => $period->key(),
            'user_id' => $owner->id,
        ])->assertRedirect();

        app(CurrentOrganization::class)->set($owner->organization_id);

        $this->assertSame(Timesheet::STATUS_SUBMITTED, Timesheet::where('user_id', $mate->id)->first()?->status);
        $this->assertNull(Timesheet::where('user_id', $owner->id)->first());
    }

    public function test_a_submitted_timesheet_cannot_be_submitted_twice(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');
        $period = $this->useWeeklyPeriods($owner);

        $this->submit($owner, $period)->assertRedirect();
        $this->submit($owner, $period)->assertSessionHasErrors('period');

        app(CurrentOrganization::class)->set($owner->organization_id);
        $this->assertSame(1, TimesheetApproval::count());
    }

    public function test_a_future_period_cannot_be_submitted(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');
        $period = $this->useWeeklyPeriods($owner);

        $this->submit($owner, $period->next())->assertSessionHasErrors('period');

        app(CurrentOrganization::class)->set($owner->organization_id);
        $this->assertSame(0, Timesheet::count());
    }

    public function test_an_approver_cannot_approve_what_was_never_submitted(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');
        $period = $this->useWeeklyPeriods($owner);
        $mate = $this->member($owner, 'Aqsa', 'aqsa@acme.test');
        $this->entry($mate, $period->startDate(), 5.0);

        $this->asTenant($owner)->post(route('timesheets.review'), [
            'user_id' => $mate->id,
            'period' => $period->key(),
            'decision' => 'approve',
        ])->assertRedirect();

        app(CurrentOrganization::class)->set($owner->organization_id);

        // No row, no lock, no audit — the approval simply did not happen.
        $this->assertNull(Timesheet::where('user_id', $mate->id)->first());
        $this->assertSame(0, WorkHour::whereNotNull('timesheet_id')->count());
    }

    public function test_rejecting_requires_a_reason_and_lets_the_person_resubmit(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');
        $period = $this->useWeeklyPeriods($owner);
        $mate = $this->member($owner, 'Aqsa', 'aqsa@acme.test');
        $this->entry($mate, $period->startDate(), 5.0);

        $this->submit($mate, $period);

        // No note = no rejection. "Sent back" with no reason is a dead end.
        $this->asTenant($owner)->post(route('timesheets.review'), [
            'user_id' => $mate->id, 'period' => $period->key(), 'decision' => 'reject',
        ])->assertSessionHasErrors('note');

        $this->asTenant($owner)->post(route('timesheets.review'), [
            'user_id' => $mate->id, 'period' => $period->key(), 'decision' => 'reject',
            'note' => 'Thursday looks like a duplicate.',
        ])->assertRedirect();

        app(CurrentOrganization::class)->set($owner->organization_id);
        $sheet = Timesheet::where('user_id', $mate->id)->first();
        $this->assertSame(Timesheet::STATUS_REJECTED, $sheet->status);
        $this->assertNull($sheet->locked_at);
        // Rejection never locks: the whole point is that it can be corrected.
        $this->assertSame(0, WorkHour::whereNotNull('timesheet_id')->count());

        $this->submit($mate, $period, 'Fixed it.')->assertRedirect();
        $this->assertSame(Timesheet::STATUS_SUBMITTED, $sheet->fresh()->status);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The self-approval rule
    // ─────────────────────────────────────────────────────────────────────────

    public function test_an_approver_cannot_approve_their_own_timesheet(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');
        $period = $this->useWeeklyPeriods($owner);

        // Plain `timesheets.approve` — may review anyone ELSE.
        $approver = $this->member($owner, 'Sana', 'sana@acme.test', ['timesheets.approve']);
        $this->entry($approver, $period->startDate(), 8.0);

        $this->submit($approver, $period);

        $this->asTenant($approver)->post(route('timesheets.review'), [
            'user_id' => $approver->id, 'period' => $period->key(), 'decision' => 'approve',
        ])->assertSessionHasErrors('user_id');

        app(CurrentOrganization::class)->set($owner->organization_id);
        $this->assertSame(Timesheet::STATUS_SUBMITTED, Timesheet::where('user_id', $approver->id)->first()->status);
        $this->assertSame(0, WorkHour::whereNotNull('timesheet_id')->count());
    }

    public function test_the_higher_grant_allows_self_approval(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');
        $period = $this->useWeeklyPeriods($owner);

        $lead = $this->member($owner, 'Lead', 'lead@acme.test', ['timesheets.approve', 'timesheets.approve_own']);
        $this->entry($lead, $period->startDate(), 8.0);

        $this->submit($lead, $period);

        $this->asTenant($lead)->post(route('timesheets.review'), [
            'user_id' => $lead->id, 'period' => $period->key(), 'decision' => 'approve',
        ])->assertRedirect()->assertSessionHasNoErrors();

        app(CurrentOrganization::class)->set($owner->organization_id);
        $this->assertSame(Timesheet::STATUS_APPROVED, Timesheet::where('user_id', $lead->id)->first()->status);
    }

    public function test_a_member_without_the_permission_cannot_review_anyone(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');
        $period = $this->useWeeklyPeriods($owner);
        $mate = $this->member($owner, 'Aqsa', 'aqsa@acme.test');
        $nosy = $this->member($owner, 'Nosy', 'nosy@acme.test');

        $this->submit($mate, $period);

        $this->asTenant($nosy)->post(route('timesheets.review'), [
            'user_id' => $mate->id, 'period' => $period->key(), 'decision' => 'approve',
        ])->assertForbidden();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. THE LOCK
    // ─────────────────────────────────────────────────────────────────────────

    /** Submit + approve one person's period, returning their entry. */
    private function approvedEntry(User $owner, User $subject, PayPeriod $period): WorkHour
    {
        $entry = $this->entry($subject, $period->startDate(), 5.0);

        $this->submit($subject, $period);
        $this->asTenant($owner)->post(route('timesheets.review'), [
            'user_id' => $subject->id, 'period' => $period->key(), 'decision' => 'approve',
        ])->assertRedirect();

        app(CurrentOrganization::class)->set($owner->organization_id);

        return $entry->fresh();
    }

    public function test_approving_stamps_every_entry_in_the_period_and_freezes_the_totals(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');
        $period = $this->useWeeklyPeriods($owner);
        $mate = $this->member($owner, 'Aqsa', 'aqsa@acme.test');

        $inside = $this->entry($mate, $period->startDate(), 5.0);
        $alsoInside = $this->entry($mate, $period->endDate(), 3.0, ['is_billable' => false]);
        $outside = $this->entry($mate, $period->next()->startDate(), 4.0);
        $someoneElse = $this->entry($owner, $period->startDate(), 7.0);

        $this->submit($mate, $period);
        $this->asTenant($owner)->post(route('timesheets.review'), [
            'user_id' => $mate->id, 'period' => $period->key(), 'decision' => 'approve', 'note' => 'Signed off.',
        ])->assertRedirect();

        app(CurrentOrganization::class)->set($owner->organization_id);
        $sheet = Timesheet::where('user_id', $mate->id)->first();

        $this->assertSame(Timesheet::STATUS_APPROVED, $sheet->status);
        $this->assertNotNull($sheet->locked_at);
        $this->assertEqualsWithDelta(8.0, $sheet->total_hours, 0.001);
        $this->assertEqualsWithDelta(5.0, $sheet->billable_hours, 0.001);
        $this->assertSame(2, $sheet->entries_count);

        // Inside the period, and only inside it, and only for that person.
        $this->assertSame((int) $sheet->id, (int) $inside->fresh()->timesheet_id);
        $this->assertSame((int) $sheet->id, (int) $alsoInside->fresh()->timesheet_id);
        $this->assertNull($outside->fresh()->timesheet_id);
        $this->assertNull($someoneElse->fresh()->timesheet_id);

        $this->assertTrue($inside->fresh()->isApprovalLocked());
        $this->assertTrue($inside->fresh()->isLocked());
        $this->assertStringContainsString('approved timesheet', (string) $inside->fresh()->lockReason());
    }

    public function test_an_approved_entry_refuses_every_edit_including_its_labels(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');
        $period = $this->useWeeklyPeriods($owner);
        $mate = $this->member($owner, 'Aqsa', 'aqsa@acme.test');

        $entry = $this->approvedEntry($owner, $mate, $period);

        $this->asTenant($mate)->put(route('work-hours.update', $entry->id), [
            'date' => $period->startDate(),
            'description' => 'Rewritten after approval',
            'work_type' => 'office_work',
            'entry_mode' => 'duration',
            'hours' => 12,
            'minutes' => 0,
            'is_billable' => false,
        ])->assertRedirect();

        app(CurrentOrganization::class)->set($owner->organization_id);
        $fresh = $entry->fresh();

        // Not the hours, and — unlike a merely tracked row — not the labels
        // either: the billable split is part of what was signed off.
        $this->assertEqualsWithDelta(5.0, (float) $fresh->hours, 0.001);
        $this->assertSame('Work on '.$period->startDate(), $fresh->description);
        $this->assertTrue((bool) $fresh->is_billable);
    }

    public function test_an_approved_entry_cannot_be_deleted_even_by_a_manager(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');
        $period = $this->useWeeklyPeriods($owner);
        $mate = $this->member($owner, 'Aqsa', 'aqsa@acme.test');

        $entry = $this->approvedEntry($owner, $mate, $period);

        // The owner is a Super Admin — every permission there is, including
        // work_hours.manage_all. The payroll lock still refuses.
        $this->asTenant($owner)->delete(route('work-hours.destroy', $entry->id))->assertRedirect();

        app(CurrentOrganization::class)->set($owner->organization_id);
        $this->assertNotNull(WorkHour::find($entry->id));

        // And the bulk path is not a way around the single-row rule.
        $this->asTenant($owner)->post(route('work-hours.bulk-delete'), ['ids' => [$entry->id]])
            ->assertSessionHasErrors('ids');
        $this->assertNotNull(WorkHour::find($entry->id));
    }

    public function test_new_time_cannot_be_added_inside_an_approved_period(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');
        $period = $this->useWeeklyPeriods($owner);
        $mate = $this->member($owner, 'Aqsa', 'aqsa@acme.test');

        // Members need offline time switched on to log manual entries at all,
        // and the manual-time window must not be what refuses the second POST —
        // otherwise this test would pass for the wrong reason.
        app(CurrentOrganization::class)->set($owner->organization_id);
        MonitoringSetting::current()->update([
            'allow_offline_time' => true,
            'attendance_tracking_start_date' => null,
        ]);
        MonitoringSetting::forgetSharedCache();
        WorkCategory::ensureSeeded();

        $payload = [
            'date' => $period->startDate(),
            'description' => 'Offline work',
            'work_type' => 'office_work',
            'entry_mode' => 'duration',
            'hours' => 3,
            'minutes' => 0,
        ];

        // CONTROL: the very same POST works while the period is open.
        $this->asTenant($mate)->post(route('work-hours.store'), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->approvedEntry($owner, $mate, $period);

        app(CurrentOrganization::class)->set($owner->organization_id);
        $before = WorkHour::where('user_id', $mate->id)->count();

        // …and is refused once that period has been approved.
        $this->asTenant($mate)->post(route('work-hours.store'), $payload)
            ->assertSessionHasErrors('date');

        app(CurrentOrganization::class)->set($owner->organization_id);
        $this->assertSame($before, WorkHour::where('user_id', $mate->id)->count());
    }

    public function test_reopening_unlocks_the_period_and_is_audited(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');
        $period = $this->useWeeklyPeriods($owner);
        $mate = $this->member($owner, 'Aqsa', 'aqsa@acme.test');

        // The manual-time window must not be what blocks the re-edit below —
        // otherwise the unlock would look like it worked when it hadn't.
        app(CurrentOrganization::class)->set($owner->organization_id);
        MonitoringSetting::current()->update(['attendance_tracking_start_date' => null]);
        MonitoringSetting::forgetSharedCache();

        $entry = $this->approvedEntry($owner, $mate, $period);

        $this->asTenant($owner)->post(route('timesheets.reopen'), [
            'user_id' => $mate->id, 'period' => $period->key(), 'note' => 'Correction needed.',
        ])->assertRedirect();

        app(CurrentOrganization::class)->set($owner->organization_id);
        $sheet = Timesheet::where('user_id', $mate->id)->first();

        $this->assertSame(Timesheet::STATUS_OPEN, $sheet->status);
        $this->assertNull($sheet->locked_at);
        $this->assertNull($entry->fresh()->timesheet_id);
        $this->assertFalse($entry->fresh()->isApprovalLocked());

        $this->assertSame(
            TimesheetApproval::ACTION_REOPENED,
            TimesheetApproval::where('timesheet_id', $sheet->id)->orderByDesc('id')->first()->action
        );

        // …and the entry is editable again.
        $this->asTenant($mate)->put(route('work-hours.update', $entry->id), [
            'date' => $period->startDate(),
            'description' => 'Corrected',
            'work_type' => 'office_work',
            'entry_mode' => 'duration',
            'hours' => 6,
            'minutes' => 0,
        ])->assertRedirect()->assertSessionHasNoErrors();

        app(CurrentOrganization::class)->set($owner->organization_id);
        $this->assertEqualsWithDelta(6.0, (float) $entry->fresh()->hours, 0.001);
    }

    public function test_reopening_needs_its_own_permission(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');
        $period = $this->useWeeklyPeriods($owner);
        $mate = $this->member($owner, 'Aqsa', 'aqsa@acme.test');
        $approver = $this->member($owner, 'Sana', 'sana@acme.test', ['timesheets.approve']);

        $entry = $this->approvedEntry($owner, $mate, $period);

        // Approving is not the same power as un-approving.
        $this->asTenant($approver)->post(route('timesheets.reopen'), [
            'user_id' => $mate->id, 'period' => $period->key(),
        ])->assertForbidden();

        app(CurrentOrganization::class)->set($owner->organization_id);
        $this->assertNotNull($entry->fresh()->timesheet_id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Bulk review
    // ─────────────────────────────────────────────────────────────────────────

    public function test_bulk_approve_closes_the_submitted_ones_and_leaves_the_rest_alone(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');
        $period = $this->useWeeklyPeriods($owner);

        $a = $this->member($owner, 'Aqsa', 'aqsa@acme.test');
        $b = $this->member($owner, 'Bilal', 'bilal@acme.test');
        $c = $this->member($owner, 'Zara', 'zara@acme.test');

        foreach ([$a, $b, $c] as $person) {
            $this->entry($person, $period->startDate(), 4.0);
        }

        $this->submit($a, $period);
        $this->submit($b, $period);
        // $c never submits.

        $this->asTenant($owner)->post(route('timesheets.bulk-review'), [
            'period' => $period->key(), 'decision' => 'approve', 'note' => 'Payroll run.',
        ])->assertRedirect();

        app(CurrentOrganization::class)->set($owner->organization_id);

        $this->assertSame(Timesheet::STATUS_APPROVED, Timesheet::where('user_id', $a->id)->first()->status);
        $this->assertSame(Timesheet::STATUS_APPROVED, Timesheet::where('user_id', $b->id)->first()->status);
        $this->assertNull(Timesheet::where('user_id', $c->id)->first());

        // Only the approved people's time is frozen.
        $this->assertSame(2, WorkHour::whereNotNull('timesheet_id')->count());
        $this->assertSame(2, TimesheetApproval::where('action', TimesheetApproval::ACTION_APPROVED)->count());
    }

    public function test_bulk_approve_skips_the_approvers_own_timesheet(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');
        $period = $this->useWeeklyPeriods($owner);

        $approver = $this->member($owner, 'Sana', 'sana@acme.test', ['timesheets.approve']);
        $mate = $this->member($owner, 'Aqsa', 'aqsa@acme.test');

        $this->entry($approver, $period->startDate(), 4.0);
        $this->entry($mate, $period->startDate(), 4.0);

        $this->submit($approver, $period);
        $this->submit($mate, $period);

        $this->asTenant($approver)->post(route('timesheets.bulk-review'), [
            'period' => $period->key(), 'decision' => 'approve',
        ])->assertRedirect();

        app(CurrentOrganization::class)->set($owner->organization_id);

        $this->assertSame(Timesheet::STATUS_APPROVED, Timesheet::where('user_id', $mate->id)->first()->status);
        $this->assertSame(Timesheet::STATUS_SUBMITTED, Timesheet::where('user_id', $approver->id)->first()->status);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. TENANCY
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_user_id_from_another_workspace_fails_validation_and_writes_nothing(): void
    {
        $acme = $this->tenant('Acme', 'owner@acme.test');
        $period = $this->useWeeklyPeriods($acme);

        $rival = $this->tenant('Rival', 'owner@rival.test');
        $this->useWeeklyPeriods($rival);
        $rivalMate = $this->member($rival, 'Foreign', 'foreign@rival.test');
        $this->entry($rivalMate, $period->startDate(), 8.0);
        $this->submit($rivalMate, $period);

        // Acme's owner aims at the other tenant's person.
        $this->asTenant($acme)->post(route('timesheets.review'), [
            'user_id' => $rivalMate->id, 'period' => $period->key(), 'decision' => 'approve',
        ])->assertSessionHasErrors('user_id');

        app(CurrentOrganization::class)->set($rival->organization_id);
        $this->assertSame(Timesheet::STATUS_SUBMITTED, Timesheet::where('user_id', $rivalMate->id)->first()->status);
        $this->assertSame(0, WorkHour::whereNotNull('timesheet_id')->count());
    }

    public function test_one_workspaces_timesheets_are_invisible_to_another(): void
    {
        $acme = $this->tenant('Acme', 'owner@acme.test');
        $period = $this->useWeeklyPeriods($acme);
        $this->entry($acme, $period->startDate(), 5.0);
        $this->submit($acme, $period);

        $rival = $this->tenant('Rival', 'owner@rival.test');
        $this->useWeeklyPeriods($rival);

        app(CurrentOrganization::class)->set($rival->organization_id);
        $this->assertSame(0, Timesheet::count());
        $this->assertSame(0, TimesheetApproval::count());

        // And the detail page for a foreign person is not reachable.
        $this->asTenant($rival)->get(route('timesheets.show', ['user' => $acme->id, 'period' => $period->key()]))
            ->assertNotFound();
    }

    public function test_a_member_cannot_open_someone_elses_timesheet_detail(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');
        $period = $this->useWeeklyPeriods($owner);
        $mate = $this->member($owner, 'Aqsa', 'aqsa@acme.test');
        $nosy = $this->member($owner, 'Nosy', 'nosy@acme.test');

        $this->asTenant($nosy)->get(route('timesheets.show', ['user' => $mate->id, 'period' => $period->key()]))
            ->assertForbidden();

        // Their own is fine.
        $this->asTenant($nosy)->get(route('timesheets.show', ['user' => $nosy->id, 'period' => $period->key()]))
            ->assertOk();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The settings surface
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_cadence_setting_reshapes_future_periods_but_not_approved_history(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');
        $period = $this->useWeeklyPeriods($owner);
        $mate = $this->member($owner, 'Aqsa', 'aqsa@acme.test');

        $this->approvedEntry($owner, $mate, $period);

        app(CurrentOrganization::class)->set($owner->organization_id);
        $settings = MonitoringSetting::current();
        $settings->pay_period_cadence = PayPeriod::MONTHLY;
        $settings->save();
        MonitoringSetting::forgetSharedCache();
        app()->forgetInstance('monitoring_settings.current.'.$owner->organization_id);

        // New periods follow the new cadence…
        $this->assertSame(PayPeriod::MONTHLY, PayPeriod::current()->cadence);

        // …and the already-approved one keeps the window it was signed off with.
        $sheet = Timesheet::where('user_id', $mate->id)->first();
        $this->assertSame(PayPeriod::WEEKLY, $sheet->cadence);
        $this->assertSame($period->startDate(), substr((string) $sheet->period_start, 0, 10));
        $this->assertSame($period->endDate(), substr((string) $sheet->period_end, 0, 10));
    }

    public function test_the_anchor_is_cleared_for_calendar_pinned_cadences(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');
        $this->useWeeklyPeriods($owner, '2026-01-05');

        // The settings routes live behind permission:monitoring.settings, which
        // the Super Admin owner holds.
        $this->asTenant($owner)->put('/settings/pay-period', [
            'pay_period_cadence' => PayPeriod::MONTHLY,
            'pay_period_anchor_date' => '2026-03-09',
        ])->assertRedirect();

        app(CurrentOrganization::class)->set($owner->organization_id);
        app()->forgetInstance('monitoring_settings.current.'.$owner->organization_id);

        $settings = MonitoringSetting::current();
        $this->assertSame(PayPeriod::MONTHLY, $settings->pay_period_cadence);
        $this->assertNull($settings->pay_period_anchor_date);
    }

    public function test_an_unknown_cadence_is_rejected(): void
    {
        $owner = $this->tenant('Acme', 'owner@acme.test');

        $this->asTenant($owner)->put('/settings/pay-period', ['pay_period_cadence' => 'fortnightly-ish'])
            ->assertSessionHasErrors('pay_period_cadence');
    }
}
