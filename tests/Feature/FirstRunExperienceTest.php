<?php

namespace Tests\Feature;

use App\Http\Controllers\WorkHourController;
use App\Models\Client;
use App\Models\Machine;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkCategory;
use App\Models\WorkHour;
use App\Services\DemoDataService;
use App\Services\GettingStartedChecklist;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * PHASE 7 — FIRST-RUN EXPERIENCE.
 *
 * Four things are proved here, and each one is a promise made to a customer who
 * has just signed up:
 *
 *  1. A BRAND-NEW EMPTY WORKSPACE IS NOT BROKEN. Every surface a new customer
 *     can reach answers, rather than erroring — which is the difference between
 *     "this is a beginning" and "this is broken".
 *  2. THE GET-STARTED CHECKLIST IS DERIVED, NEVER STORED. Each step's tick is
 *     re-earned from real rows, and — the part that matters — is TAKEN BACK when
 *     the thing it claims stops being true.
 *  3. DEMO DATA REMOVES CLEANLY AND CANNOT BE MISTAKEN FOR PAYROLL. Removal is
 *     asserted table-by-table against the raw tables, and a real employee's
 *     hours are asserted byte-identical either side of loading it.
 *  4. `/report` OPENS ON A BOUNDED WINDOW, and all time is still reachable.
 */
class FirstRunExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function makeOwner(string $type = 'general'): User
    {
        $result = app(TenantProvisioner::class)
            ->provision('Acme Co', 'Owner', 'owner@acme.test', 'super-secret-pw', null, 14, $type);
        app(CurrentOrganization::class)->set($result['organization']);

        return $result['owner'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1 — the empty workspace
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The real first-run walk. A workspace with no people, no punches, no
     * entries and no history must ANSWER on every surface the nav offers.
     * A 500 anywhere in here is the "this product is broken" moment.
     */
    public function test_every_first_run_surface_answers_on_a_completely_empty_workspace(): void
    {
        $owner = $this->makeOwner();

        $surfaces = [
            'dashboard',
            'onboarding.show',
            'time-entries.today',
            'time-entries.today-summary',
            'time-entries.dashboard-trend',
            'time-entries.index',
            'employee-attendance.index',
            'employee-attendance.monthly',
            'employee-attendance.summary',
            'employee-attendance.detailed',
            'employee-attendance.timeline',
            'work-hours.index',
            'work-hours.report',
            'work-hours.export',
            'timesheets.index',
            'insights.index',
            'timeline.index',
            'timeline.data',
            'shift-overrides.index',
            'clients.index',
            'users.index',
            'teams.index',
            'team.index',
            'settings.index',
            'settings.work-categories',
            'billing.index',
            'help.index',
            'desktop-downloads.index',
        ];

        foreach ($surfaces as $name) {
            $this->actingAs($owner)
                ->get(route($name))
                ->assertOk("first-run surface [{$name}] must render on an empty workspace");
        }
    }

    /**
     * The empty report must say "nothing has been logged yet", not "adjust your
     * filters" — the page can only tell those apart from the server, so the
     * signal has to be there.
     */
    public function test_the_report_distinguishes_a_new_workspace_from_a_filtered_out_one(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)
            ->get(route('work-hours.report'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('WorkHoursReport')
                ->where('hasAnyEntries', false));

        WorkHour::create([
            'user_id' => $owner->id,
            'date' => now()->toDateString(),
            'hours' => 3,
            'description' => 'Real work',
            'work_type' => 'manual',
            'source' => 'manual',
        ]);

        $this->actingAs($owner)
            ->get(route('work-hours.report'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('hasAnyEntries', true));
    }

    /**
     * Attendance is loaded a day (or a month) at a time by AJAX, so the page
     * cannot work out for itself whether an empty tab means "this workspace has
     * never clocked in" or "nobody worked last Sunday". Without the server
     * signal it blamed the customer's filters for both.
     */
    public function test_the_attendance_page_distinguishes_a_new_workspace_from_a_quiet_day(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)
            ->get(route('employee-attendance.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('EmployeeAttendance')
                ->where('hasAnyAttendance', false));

        TimeEntry::create([
            'user_id' => $owner->id,
            'action_type' => 'clock_in',
            'action_timestamp' => now()->subDays(30),
            'action_date' => now()->subDays(30)->toDateString(),
            'action_time' => '09:00:00',
        ]);

        // A punch a month ago still means the workspace HAS attendance — every
        // empty day from here on is a quiet day, not a broken product.
        $this->actingAs($owner)
            ->get(route('employee-attendance.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('hasAnyAttendance', true));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1b — Top projects: the card that could never fill
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The dashboard's Top projects card had NO data source: it rendered a
     * permanent empty state for every real user. It now ranks live client hours,
     * aggregated in SQL over a bounded window.
     */
    public function test_top_projects_ranks_live_client_hours_over_a_bounded_window(): void
    {
        $owner = $this->makeOwner();

        $atlas = Client::create(['name' => 'Atlas', 'work_type' => 'tracker_manual']);
        $orbit = Client::create(['name' => 'Orbit', 'work_type' => 'tracker_manual']);
        $stale = Client::create(['name' => 'Ancient', 'work_type' => 'tracker_manual']);

        $log = function (Client $client, float $hours, int $daysAgo) use ($owner) {
            WorkHour::create([
                'user_id' => $owner->id,
                'client_id' => $client->id,
                'date' => now()->subDays($daysAgo)->toDateString(),
                'hours' => $hours,
                'description' => 'Work',
                'work_type' => 'manual',
                'source' => 'manual',
            ]);
        };

        $log($atlas, 6, 1);
        $log($atlas, 2, 2);
        $log($orbit, 4, 3);
        // Outside the seven-day window: it must not appear, or the card would be
        // quietly summing the whole table.
        $log($stale, 99, 40);
        // No client at all — counts towards the denominator, never as a row.
        WorkHour::create([
            'user_id' => $owner->id,
            'date' => now()->toDateString(),
            'hours' => 2,
            'description' => 'Admin',
            'work_type' => 'manual',
            'source' => 'manual',
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $p) {
                $top = $p->toArray()['props']['topProjects'];

                $this->assertSame('team', $top['scope']);
                $this->assertSame(7, $top['days']);
                $this->assertCount(2, $top['items'], 'the 40-day-old project must be outside the window');

                $this->assertSame('Atlas', $top['items'][0]['label']);
                $this->assertEqualsWithDelta(8.0, $top['items'][0]['hours'], 0.001);
                $this->assertSame(100, $top['items'][0]['pct']);
                // 8 of the 14 hours logged in the window.
                $this->assertSame(57, $top['items'][0]['share']);

                $this->assertSame('Orbit', $top['items'][1]['label']);
                $this->assertSame(50, $top['items'][1]['pct']);
            });
    }

    /**
     * SCOPE FOLLOWS PERMISSION. A member with no reporting rights must never be
     * shown a colleague's hours through a dashboard card — they get their own.
     */
    public function test_top_projects_shows_a_member_only_their_own_hours(): void
    {
        $owner = $this->makeOwner();
        $member = User::create([
            'name' => 'Member', 'email' => 'member@acme.test',
            'password' => bcrypt('super-secret-pw'), 'role' => 'member',
        ]);

        $secret = Client::create(['name' => 'Owner Only', 'work_type' => 'tracker_manual']);
        $mine = Client::create(['name' => 'Mine', 'work_type' => 'tracker_manual']);

        foreach ([[$owner, $secret, 9.0], [$member, $mine, 1.0]] as [$user, $client, $hours]) {
            WorkHour::create([
                'user_id' => $user->id,
                'client_id' => $client->id,
                'date' => now()->toDateString(),
                'hours' => $hours,
                'description' => 'Work',
                'work_type' => 'manual',
                'source' => 'manual',
            ]);
        }

        $this->actingAs($member)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $p) {
                $top = $p->toArray()['props']['topProjects'];

                $this->assertSame('self', $top['scope']);
                $this->assertSame(['Mine'], array_column($top['items'], 'label'));
            });
    }

    /** A brand-new workspace gets an honest empty card, not a broken one. */
    public function test_top_projects_is_empty_on_a_new_workspace(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Dashboard')
                ->where('topProjects.items', []));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2 — the checklist
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_brand_new_workspace_has_nothing_ticked(): void
    {
        $owner = $this->makeOwner();

        $checklist = app(GettingStartedChecklist::class)->build($owner);

        $this->assertSame(4, $checklist['total']);
        $this->assertSame(0, $checklist['done']);
        $this->assertFalse($checklist['complete']);

        foreach ($checklist['steps'] as $step) {
            $this->assertFalse($step['done'], "step [{$step['key']}] must start untouched");
            $this->assertArrayHasKey('can', $step, 'a step must say whether its CTA is reachable');
            $this->assertArrayNotHasKey('permission', $step, 'permission keys are internal, never serialised');
        }
    }

    /**
     * A checklist that hands somebody a 403 is worse than no checklist: the
     * button is only offered when the destination's own permission would let
     * them in.
     */
    public function test_a_step_button_is_withheld_when_its_destination_would_refuse(): void
    {
        $this->makeOwner();

        // An admin who may manage users but has NOT been given settings or
        // attendance access.
        $admin = User::create([
            'name' => 'Limited Admin',
            'email' => 'limited@acme.test',
            'password' => bcrypt('super-secret-pw'),
            'role' => 'admin',
            'permissions' => ['users.manage'],
        ]);

        $steps = collect(app(GettingStartedChecklist::class)->build($admin)['steps'])->keyBy('key');

        $this->assertTrue($steps['invite_team']['can']);
        $this->assertTrue($steps['install_desktop']['can'], 'the downloads page is open to everyone');
        $this->assertFalse($steps['configure_work']['can']);
        $this->assertFalse($steps['first_clock_in']['can']);

        // And the claim is true: the routes really do refuse them.
        $this->actingAs($admin)->get(route('settings.work-categories'))->assertForbidden();
        $this->actingAs($admin)->get(route('employee-attendance.index'))->assertForbidden();
    }

    /**
     * The whole argument for deriving rather than storing: each tick has to be
     * TAKEN BACK when its evidence disappears. A stored flag would keep it.
     */
    public function test_each_step_ticks_from_real_data_and_unticks_when_that_data_goes(): void
    {
        $owner = $this->makeOwner();
        $service = app(GettingStartedChecklist::class);

        $done = fn (string $key) => collect($service->build($owner)['steps'])
            ->firstWhere('key', $key)['done'];

        // 1 — invite your team.
        $mate = User::create([
            'name' => 'Jane', 'email' => 'jane@acme.test',
            'password' => bcrypt('super-secret-pw'), 'role' => 'member',
        ]);
        $this->assertTrue($done('invite_team'));
        $mate->delete();
        $this->assertFalse($done('invite_team'), 'the tick must be withdrawn when the teammate goes');

        // 2 — install the desktop app: proved by what the app leaves behind.
        $mate = User::create([
            'name' => 'Jane', 'email' => 'jane2@acme.test',
            'password' => bcrypt('super-secret-pw'), 'role' => 'member',
        ]);
        $machine = Machine::create([
            'user_id' => $mate->id,
            'device_name' => 'JANE-PC',
            'platform' => 'win32',
            'app_version' => '0.3.4',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        $this->assertTrue($done('install_desktop'));
        $machine->delete();
        $this->assertFalse($done('install_desktop'));

        // 3 — shifts / work categories. The STARTER categories are seeded
        //     automatically, so their existence must NOT tick the step.
        WorkCategory::ensureSeeded();
        $this->assertFalse($done('configure_work'), 'the seeded starter set is not a customisation');

        $renamed = WorkCategory::query()->where('slug', 'meetings')->firstOrFail();
        $renamed->update(['name' => 'Stand-ups']);
        WorkCategory::flushResolverCache();
        $this->assertTrue($done('configure_work'));
        $renamed->update(['name' => 'Meetings']);
        WorkCategory::flushResolverCache();
        $this->assertFalse($done('configure_work'));

        // 4 — the first clock-in.
        $punch = TimeEntry::create([
            'user_id' => $owner->id,
            'action_type' => 'clock_in',
            'action_timestamp' => now(),
            'action_date' => now()->toDateString(),
            'action_time' => now()->format('H:i:s'),
        ]);
        $this->assertTrue($done('first_clock_in'));
        $punch->delete();
        $this->assertFalse($done('first_clock_in'));
    }

    public function test_the_dashboard_serves_the_checklist_to_an_owner_and_not_to_a_member(): void
    {
        $owner = $this->makeOwner();
        $member = User::create([
            'name' => 'Member', 'email' => 'member@acme.test',
            'password' => bcrypt('super-secret-pw'), 'role' => 'member',
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Dashboard')
                ->has('gettingStarted.steps', 4)
                ->has('demoData'));

        // A member cannot invite people or install software fleet-wide, so the
        // checklist would be noise — and four wasted queries — for them.
        $this->actingAs($member)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->missing('gettingStarted'));
    }

    /**
     * SCALE. The checklist runs on every dashboard load, so its cost has to be
     * a handful of index probes that do not grow with the workspace — not a
     * count that walks a five-thousand-person tenant. Asserted as a hard
     * ceiling on the whole dashboard response, and as "no extra cost at all"
     * for the members who are never shown it.
     */
    public function test_the_first_run_props_cost_a_bounded_number_of_queries(): void
    {
        $owner = $this->makeOwner();
        $member = User::create([
            'name' => 'Member', 'email' => 'member@acme.test',
            'password' => bcrypt('super-secret-pw'), 'role' => 'member',
        ]);

        $count = function (callable $work): int {
            $queries = 0;
            $listener = function () use (&$queries) {
                $queries++;
            };
            DB::listen($listener);

            $work();

            return $queries;
        };

        // The checklist itself, measured in isolation: one probe per question
        // it asks, and nothing that scans.
        $checklistQueries = $count(fn () => app(GettingStartedChecklist::class)->build($owner));
        $this->assertLessThanOrEqual(
            8,
            $checklistQueries,
            "the checklist issued {$checklistQueries} queries; it must stay a handful of index probes"
        );

        // "Is demo data loaded" is asked on every dashboard load, so it is a
        // single left-anchored probe against the unique e-mail index — never a
        // scan of the workspace's people or of its work entries.
        $loadedQueries = $count(fn () => app(DemoDataService::class)->isLoaded());
        $this->assertSame(1, $loadedQueries);

        // And the cost must not move with the amount of data in the workspace.
        app(DemoDataService::class)->load();
        $after = $count(fn () => app(GettingStartedChecklist::class)->build($owner));
        $this->assertLessThanOrEqual($checklistQueries, $after);

        // A member is never shown the checklist, so they must not pay for it.
        $ownerLoad = $count(fn () => $this->actingAs($owner)->get(route('dashboard'))->assertOk());
        $memberLoad = $count(fn () => $this->actingAs($member)->get(route('dashboard'))->assertOk());
        $this->assertLessThan($ownerLoad, $memberLoad, 'a member must not pay for props they are not sent');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3 — demo data
    // ─────────────────────────────────────────────────────────────────────────

    public function test_demo_data_loads_labelled_and_reports_itself_as_loaded(): void
    {
        $owner = $this->makeOwner();
        $demo = app(DemoDataService::class);

        $this->assertFalse($demo->isLoaded());

        $result = $demo->load();

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertTrue($demo->isLoaded());
        $this->assertGreaterThan(0, $result['entries']);

        // Every demo person and the demo project is named so a human reading
        // ANY screen — including screens written after this file — can tell.
        $demoUsers = User::where('email', 'like', '%@'.DemoDataService::EMAIL_DOMAIN)->get();
        $this->assertNotEmpty($demoUsers);

        foreach ($demoUsers as $user) {
            $this->assertStringStartsWith(DemoDataService::NAME_PREFIX, $user->name);
        }

        $client = Client::where('name', 'like', DemoDataService::NAME_PREFIX.'%')->firstOrFail();
        $this->assertContains(DemoDataService::CLIENT_TAG, (array) $client->tags);

        // Second, independent marker on the hours themselves.
        $this->assertSame(
            0,
            WorkHour::whereIn('user_id', $demoUsers->pluck('id'))
                ->where('source', '!=', DemoDataService::WORK_HOUR_SOURCE)
                ->count(),
            'every demo work entry must carry source=demo'
        );

        // And the punches, so the dashboard and attendance grid have something.
        $this->assertGreaterThan(0, TimeEntry::whereIn('user_id', $demoUsers->pluck('id'))->count());
    }

    /**
     * Demo mode has to actually DEMONSTRATE something: the surfaces that were
     * empty a moment ago must now be populated, and everything in them must
     * still read as demo.
     */
    public function test_demo_data_populates_the_surfaces_it_is_loaded_for(): void
    {
        $owner = $this->makeOwner();
        app(DemoDataService::class)->load();

        // The dashboard team table — which only lists people with punches today.
        $summary = $this->actingAs($owner)->getJson(route('time-entries.today-summary'))->assertOk();
        $names = collect($summary->json('employees'))->pluck('user_name');

        $this->assertGreaterThan(0, $names->count(), 'demo people must show on the dashboard');
        foreach ($names as $name) {
            $this->assertStringStartsWith(DemoDataService::NAME_PREFIX, $name);
        }

        // The report, on its DEFAULT window — the demo history has to be inside
        // it or the sample would demonstrate an empty report.
        $props = $this->reportProps();
        $this->assertGreaterThan(0, $props['workHours']['total']);
        foreach ($props['workHours']['data'] as $row) {
            $this->assertStringStartsWith(DemoDataService::NAME_PREFIX, $row['user']['name']);
        }

        // No demo punch falls on a PAST weekend — a sample that shows a team
        // working seven days a week misrepresents the product. Today is the
        // deliberate exception (it is what makes the live table say anything).
        $today = Carbon::now(\App\Support\BusinessTime::tz())->toDateString();

        foreach (TimeEntry::all() as $entry) {
            $date = Carbon::parse($entry->action_date);

            if ($date->toDateString() === $today) {
                continue;
            }

            $this->assertFalse($date->isWeekend(), 'demo attendance must not land on a past weekend');
        }
    }

    /**
     * THE CENTRAL PROMISE. Removal leaves NOTHING — asserted against the raw
     * tables with the organization scope off, so a row that merely became
     * invisible would still fail this.
     */
    public function test_removing_demo_data_leaves_nothing_behind(): void
    {
        $owner = $this->makeOwner();
        $demo = app(DemoDataService::class);

        $before = [
            'users' => DB::table('users')->count(),
            'work_hours' => DB::table('work_hours')->count(),
            'time_entries' => DB::table('time_entries')->count(),
            'clients' => DB::table('clients')->count(),
        ];

        $demo->load();

        foreach ($before as $table => $count) {
            $this->assertGreaterThan($count, DB::table($table)->count(), "{$table} must have grown");
        }

        $demo->remove();

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "{$table} must be back to exactly where it was");
        }

        $this->assertFalse($demo->isLoaded());
        $this->assertSame(0, DB::table('work_hours')->where('source', DemoDataService::WORK_HOUR_SOURCE)->count());
        $this->assertSame(0, DB::table('users')->where('email', 'like', '%@'.DemoDataService::EMAIL_DOMAIN)->count());
    }

    /**
     * Demo hours can never be confused with payroll hours, because no demo hour
     * is ever attributed to a real person. The real employee's totals are the
     * same number before, during and after demo mode.
     */
    public function test_demo_data_never_touches_a_real_persons_hours(): void
    {
        $owner = $this->makeOwner();
        $demo = app(DemoDataService::class);

        $real = User::create([
            'name' => 'Real Person', 'email' => 'real@acme.test',
            'password' => bcrypt('super-secret-pw'), 'role' => 'member',
        ]);

        foreach ([1, 2, 3] as $i) {
            WorkHour::create([
                'user_id' => $real->id,
                'date' => now()->subDays($i)->toDateString(),
                'hours' => 7.5,
                'description' => 'Real payroll work',
                'work_type' => 'manual',
                'source' => 'manual',
            ]);
        }

        $payroll = fn () => (float) WorkHour::where('user_id', $real->id)->sum('hours');
        $baseline = $payroll();
        $this->assertEqualsWithDelta(22.5, $baseline, 0.0001);

        $demo->load();
        $this->assertEqualsWithDelta($baseline, $payroll(), 0.0001, 'loading demo data must not move a real total');
        $this->assertSame(
            0,
            WorkHour::where('user_id', $real->id)->where('source', DemoDataService::WORK_HOUR_SOURCE)->count()
        );

        $demo->remove();
        $this->assertEqualsWithDelta($baseline, $payroll(), 0.0001, 'removing demo data must not take real hours with it');
        $this->assertSame(3, WorkHour::where('user_id', $real->id)->count());
    }

    /**
     * Demo teammates DO NOT consume plan seats. On Free (2 seats, the owner
     * using one) the sample used to be trimmed to a single person — a demo so
     * thin it taught the customer nothing. The whole cast now loads even on a
     * one-seat plan, because seat checks count real people only.
     */
    public function test_demo_data_loads_in_full_even_when_every_plan_seat_is_taken(): void
    {
        $owner = $this->makeOwner();
        $owner->organization->update(['entitlement_overrides' => ['max_users' => 1]]);
        $owner->organization->forgetResolvedEntitlements();

        $demo = app(DemoDataService::class);
        $result = $demo->load();

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(3, $result['people']);
        $this->assertTrue($demo->isLoaded());

        // Four rows exist, but only the owner counts against the plan.
        $this->assertSame(4, User::count());
        $this->assertSame(1, User::seatUsage());
    }

    /**
     * The point of the exclusion: a loaded demo must never stand between a
     * customer and a real hire — and must never BUY them one either.
     */
    public function test_a_loaded_demo_neither_blocks_nor_grants_a_real_hire(): void
    {
        $owner = $this->makeOwner();
        $owner->organization->update(['entitlement_overrides' => ['max_users' => 2]]);
        $owner->organization->forgetResolvedEntitlements();

        app(DemoDataService::class)->load();

        // Seat 2 of 2 — allowed, despite three demo people also sitting there.
        $this->actingAs($owner)->post(route('users.store'), [
            'name' => 'Real Hire',
            'email' => 'real@acme.test',
            'password' => 'a-very-long-password',
        ])->assertRedirect(route('users.index'));

        $this->assertSame(2, User::seatUsage());

        // Seat 3 of 2 — refused. The demo did not buy an extra seat.
        $this->actingAs($owner)->post(route('users.store'), [
            'name' => 'One Too Many',
            'email' => 'nope@acme.test',
            'password' => 'a-very-long-password',
        ])->assertSessionHasErrors('email');

        $this->assertSame(2, User::seatUsage());
    }

    /**
     * THE ABUSE SURFACE. Excluding demo people from the seat count is a
     * plan-limit bypass, so the marker it keys on must be unforgeable. Every
     * request-reachable path that can set an e-mail address is tried here: if
     * any of them let a customer mint a `@demo.staffvora.invalid` address, they
     * would mint themselves unlimited free seats.
     */
    public function test_a_customer_cannot_mint_free_seats_by_imitating_the_demo_marker(): void
    {
        $owner = $this->makeOwner();
        $owner->organization->update(['entitlement_overrides' => ['max_users' => 2]]);
        $owner->organization->forgetResolvedEntitlements();

        $orgId = app(CurrentOrganization::class)->id();
        $forged = "demo-{$orgId}-9@".DemoDataService::EMAIL_DOMAIN;

        // 1. The admin "create user" form.
        $this->actingAs($owner)->post(route('users.store'), [
            'name' => 'Fake Demo',
            'email' => $forged,
            'password' => 'a-very-long-password',
        ])->assertSessionHasErrors('email');

        // 2. The onboarding invite step.
        $this->actingAs($owner)->post(route('onboarding.team'), [
            'name' => 'Fake Demo',
            'email' => "demo-{$orgId}-8@".DemoDataService::EMAIL_DOMAIN,
        ])->assertSessionHasErrors('email');

        // 3. Public self-registration (a whole new tenant). This one is stopped
        // by the model guard rather than a form rule — registration is outside
        // this change — so it may surface as a thrown refusal. What matters is
        // that it cannot SUCCEED; nothing legitimate ever registers on an
        // RFC 2606 `.invalid` domain.
        try {
            $this->post(route('register'), [
                'name' => 'Fake Demo',
                'company_name' => 'Forge Co',
                'email' => 'demo-1-1@'.DemoDataService::EMAIL_DOMAIN,
                'password' => 'a-very-long-password',
                'password_confirmation' => 'a-very-long-password',
            ]);
        } catch (\Throwable $e) {
            $this->assertStringContainsString('reserved', $e->getMessage());
        }

        // 4. Renaming an EXISTING real user into the namespace after the fact.
        $this->actingAs($owner);
        $real = User::create([
            'name' => 'Real Person',
            'email' => 'real@acme.test',
            'password' => 'a-very-long-password',
            'role' => 'member',
        ]);

        try {
            $real->update(['email' => $forged]);
            $this->fail('renaming a real user into the demo namespace must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('reserved', $e->getMessage());
        }

        // Not one forged demo identity exists anywhere in the install, so the
        // seat exclusion has nothing a customer can hide behind.
        $this->assertSame(
            0,
            DB::table('users')->where('email', 'like', '%@'.DemoDataService::EMAIL_DOMAIN)->count()
        );

        // And the owner + the one real hire still fill the 2-seat plan.
        $this->assertSame(2, User::seatUsage());
    }

    /** The seat count excludes demo people and nobody else. */
    public function test_seat_usage_counts_real_people_only(): void
    {
        $this->makeOwner();

        $this->assertSame(1, User::seatUsage());

        app(DemoDataService::class)->load();
        $this->assertSame(4, User::count());
        $this->assertSame(1, User::seatUsage());

        app(DemoDataService::class)->remove();
        $this->assertSame(1, User::seatUsage());
    }

    public function test_demo_data_refuses_to_load_twice(): void
    {
        $this->makeOwner();
        $demo = app(DemoDataService::class);

        $this->assertTrue($demo->load()['ok']);

        $second = $demo->load();
        $this->assertFalse($second['ok']);
        $this->assertSame(3, User::where('email', 'like', '%@'.DemoDataService::EMAIL_DOMAIN)->count());
    }

    /** One workspace's demo data is invisible to — and unremovable by — another. */
    public function test_demo_data_is_confined_to_the_workspace_that_loaded_it(): void
    {
        $this->makeOwner();
        $mine = app(CurrentOrganization::class)->id();

        app(DemoDataService::class)->load();
        $mineCount = User::where('email', 'like', '%@'.DemoDataService::EMAIL_DOMAIN)->count();
        $this->assertGreaterThan(0, $mineCount);

        $other = app(TenantProvisioner::class)
            ->provision('Other Co', 'Other', 'other@other.test', 'super-secret-pw');

        app(CurrentOrganization::class)->set($other['organization']);
        $demo = app(DemoDataService::class);

        $this->assertFalse($demo->isLoaded(), "the neighbour's sample must not read as mine");
        $demo->remove();

        app(CurrentOrganization::class)->set($mine);
        $this->assertTrue(app(DemoDataService::class)->isLoaded());
        $this->assertSame($mineCount, User::where('email', 'like', '%@'.DemoDataService::EMAIL_DOMAIN)->count());
    }

    /** Demo people cannot sign in — demo mode is not a way into a workspace. */
    public function test_demo_people_cannot_authenticate(): void
    {
        $this->makeOwner();
        app(DemoDataService::class)->load();

        $demoUser = User::where('email', 'like', '%@'.DemoDataService::EMAIL_DOMAIN)->firstOrFail();

        foreach (['password', 'demo', 'staffvora', ''] as $guess) {
            $this->post(route('login'), ['email' => $demoUser->email, 'password' => $guess])
                ->assertSessionHasErrors();
            $this->assertGuest();
        }
    }

    public function test_the_console_command_can_load_and_remove_for_a_named_tenant(): void
    {
        $owner = $this->makeOwner();
        $slug = $owner->organization->slug;

        $this->artisan('staffvora:demo-data', ['action' => 'load', '--tenant' => $slug])
            ->assertExitCode(0);

        app(CurrentOrganization::class)->set($owner->organization);
        $this->assertTrue(app(DemoDataService::class)->isLoaded());

        $this->artisan('staffvora:demo-data', ['action' => 'remove', '--tenant' => $slug])
            ->assertExitCode(0);

        app(CurrentOrganization::class)->set($owner->organization);
        $this->assertFalse(app(DemoDataService::class)->isLoaded());
    }

    public function test_the_console_command_refuses_without_a_tenant(): void
    {
        $this->artisan('staffvora:demo-data', ['action' => 'load'])->assertExitCode(1);
    }

    /**
     * The HTTP endpoints. The route lines belong to routes/web.php, which this
     * change does not own — so if they are not registered yet the test binds
     * them itself, exactly as specified, rather than skipping. The AUTHORIZATION
     * is the thing being proved, and it must be proved either way.
     */
    public function test_the_demo_endpoints_are_owner_only(): void
    {
        if (! app('router')->has('onboarding.demo.load')) {
            \Illuminate\Support\Facades\Route::middleware(['web', 'auth'])->group(function () {
                \Illuminate\Support\Facades\Route::post('/onboarding/demo-data', [\App\Http\Controllers\OnboardingController::class, 'loadDemoData'])
                    ->name('onboarding.demo.load');
                \Illuminate\Support\Facades\Route::delete('/onboarding/demo-data', [\App\Http\Controllers\OnboardingController::class, 'removeDemoData'])
                    ->name('onboarding.demo.remove');
            });

            app('router')->getRoutes()->refreshNameLookups();
        }

        $owner = $this->makeOwner();
        $member = User::create([
            'name' => 'Member', 'email' => 'member@acme.test',
            'password' => bcrypt('super-secret-pw'), 'role' => 'member',
        ]);

        $this->actingAs($member)->post(route('onboarding.demo.load'))->assertForbidden();
        $this->assertFalse(app(DemoDataService::class)->isLoaded());

        $this->actingAs($owner)->post(route('onboarding.demo.load'))->assertRedirect();
        $this->assertTrue(app(DemoDataService::class)->isLoaded());

        $this->actingAs($member)->delete(route('onboarding.demo.remove'))->assertForbidden();
        $this->actingAs($owner)->delete(route('onboarding.demo.remove'))->assertRedirect();
        $this->assertFalse(app(DemoDataService::class)->isLoaded());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4 — the /report default window
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A bare `/report` opens on the last 90 days, so its GROUP BY cannot keep
     * widening for the life of the workspace.
     */
    public function test_the_report_defaults_to_the_last_ninety_days(): void
    {
        $owner = $this->makeOwner();

        // One entry inside the window, one comfortably outside it.
        WorkHour::create([
            'user_id' => $owner->id, 'date' => now()->subDays(5)->toDateString(),
            'hours' => 4, 'description' => 'Recent', 'work_type' => 'manual', 'source' => 'manual',
        ]);
        WorkHour::create([
            'user_id' => $owner->id, 'date' => now()->subDays(200)->toDateString(),
            'hours' => 9, 'description' => 'Ancient', 'work_type' => 'manual', 'source' => 'manual',
        ]);

        $props = $this->reportProps();

        $this->assertSame(WorkHourController::DEFAULT_REPORT_FILTER, $props['filter']);
        $this->assertSame(
            Carbon::now(\App\Support\BusinessTime::tz())->subDays(WorkHourController::DEFAULT_REPORT_DAYS - 1)->toDateString(),
            $props['startDate']
        );
        $this->assertSame(1, $props['workHours']['total'], 'the 200-day-old entry must be outside the default window');
        $this->assertSame('Recent', $props['workHours']['data'][0]['description']);
    }

    /** All time is still reachable — it is a choice, not a thing we removed. */
    public function test_all_time_is_still_reachable_and_returns_everything(): void
    {
        $owner = $this->makeOwner();

        WorkHour::create([
            'user_id' => $owner->id, 'date' => now()->subDays(5)->toDateString(),
            'hours' => 4, 'description' => 'Recent', 'work_type' => 'manual', 'source' => 'manual',
        ]);
        WorkHour::create([
            'user_id' => $owner->id, 'date' => now()->subDays(400)->toDateString(),
            'hours' => 9, 'description' => 'Ancient', 'work_type' => 'manual', 'source' => 'manual',
        ]);

        $props = $this->reportProps(['filter' => 'all']);

        $this->assertSame('all', $props['filter']);
        $this->assertNull($props['startDate']);
        $this->assertSame(2, $props['workHours']['total']);
    }

    /** An explicit range wins over the default, so saved links keep working. */
    public function test_an_explicit_range_is_honoured_without_the_default_window(): void
    {
        $owner = $this->makeOwner();

        WorkHour::create([
            'user_id' => $owner->id, 'date' => '2024-01-15',
            'hours' => 6, 'description' => 'Old bookmark', 'work_type' => 'manual', 'source' => 'manual',
        ]);

        $props = $this->reportProps(['startDate' => '2024-01-01', 'endDate' => '2024-01-31']);

        $this->assertSame('2024-01-01', $props['startDate']);
        $this->assertSame('2024-01-31', $props['endDate']);
        $this->assertSame(1, $props['workHours']['total']);
    }

    /**
     * The default window must not have disturbed the shape the page reads:
     * the paginator contract, the filter option lists and the CSV export.
     */
    public function test_the_default_window_preserves_pagination_filters_and_the_csv_export(): void
    {
        $owner = $this->makeOwner();
        $client = Client::create(['name' => 'Acme Ltd', 'is_active' => true]);

        foreach (range(1, 20) as $i) {
            WorkHour::create([
                'user_id' => $owner->id,
                'date' => now()->subDays($i)->toDateString(),
                'hours' => 2,
                'description' => "Entry {$i}",
                'work_type' => 'manual',
                'source' => 'manual',
                'client_id' => $client->id,
            ]);
        }

        $props = $this->reportProps(['perPage' => 15]);
        $paginator = $props['workHours'];

        foreach (['data', 'total', 'per_page', 'current_page', 'last_page', 'from', 'to', 'links'] as $key) {
            $this->assertArrayHasKey($key, $paginator, "paginator key [{$key}] must survive");
        }

        $this->assertSame(20, $paginator['total']);
        $this->assertCount(15, $paginator['data']);
        $this->assertSame(2, $paginator['last_page']);

        // Filter option lists still populated.
        $this->assertContains('Acme Ltd', array_values($props['availableClients']));
        $this->assertArrayHasKey('workTypes', $props['filterOptions']);

        // The CSV feed — the export the page fetches then serialises — is
        // unchanged and honours the same window when the page passes it.
        $export = $this->actingAs($owner)->getJson(route('work-hours.export', [
            'startDate' => now()->subDays(90 - 1)->toDateString(),
            'endDate' => now()->toDateString(),
        ]));

        $export->assertOk();
        $this->assertCount(20, $export->json('data'));
    }

    /** @return array<string,mixed> */
    private function reportProps(array $query = []): array
    {
        $props = null;

        $this->actingAs(User::query()->where('role', 'super_admin')->firstOrFail())
            ->get(route('work-hours.report', $query))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return $props;
    }
}
