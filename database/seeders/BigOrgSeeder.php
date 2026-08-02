<?php

namespace Database\Seeders;

use App\Models\MachineBlocklistRule;
use App\Models\MonitoringSetting;
use App\Models\Organization;
use App\Models\PayPeriod;
use App\Models\Plan;
use App\Models\User;
use App\Models\WorkCategory;
use App\Services\TenantProvisioner;
use App\Support\BusinessTime;
use App\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * THE BIG-ORG SEEDER — the dataset every Phase 6 measurement is taken against.
 *
 * It builds TWO tenants, because the plan's M6 acceptance test has two halves:
 *
 *   1. A BIG workspace: 100+ people, months of attendance, work entries,
 *      tracker sessions, screenshots, activity samples, machine-compliance
 *      inventory + findings, timesheets, feedback. This is what has to stay
 *      instant when a 500-seat customer signs up.
 *   2. A BRAND-NEW EMPTY workspace, provisioned exactly the way a self-serve
 *      signup provisions one (via TenantProvisioner, so it can never drift).
 *      This is what has to look INTENTIONAL rather than broken.
 *
 * ── WHY IT IS WRITTEN THE WAY IT IS ──────────────────────────────────────────
 *
 * REALISM OVER UNIFORMITY. A uniform dataset hides exactly the bugs this phase
 * is hunting: every query looks cheap when every user has identical rows on
 * identical days. So the generator varies shifts (including an overnight one
 * that crosses midnight), gives some people a personal `shift_start_time`
 * instead of a named shift, archives a slice of the team mid-history, takes
 * weekends/holidays/leave off, adds breaks, forgets a few clock-outs, mixes
 * manual and tracked work across several work categories with a billable split,
 * and skews activity percentages and screenshot density per person.
 *
 * SPEED. Nothing here goes through Eloquent on the hot path. Rows are built as
 * plain arrays and written with chunked `DB::table()->insert()` inside a
 * transaction per phase. Two consequences, both handled explicitly:
 *
 *   - `insert()` BYPASSES the BelongsToOrganization `creating` hook, so every
 *     row here sets `organization_id` by hand. There is one helper (`stamp()`)
 *     and every builder routes through it.
 *   - `insert()` returns no ids, and children need their parents' ids
 *     (screenshots → session, work_hours → session + timesheet, inventory →
 *     machine). So parent ids are PRE-ALLOCATED from `max(id) + 1` and written
 *     explicitly. Safe here because seeding is a single-writer dev operation.
 *
 * DETERMINISM. `mt_srand($seed)` up front, so two runs with the same options
 * produce the same dataset and a benchmark delta is a code delta.
 */
class BigOrgSeeder extends Seeder
{
    /** Password every seeded account gets, so a human can actually log in and look. */
    public const PASSWORD = 'Staffvora@123';

    // ── Options ──────────────────────────────────────────────────────────────
    private int $userCount = 120;

    private int $months = 6;

    /** Business days back from today that get screenshot rows (0 = none). */
    private int $screenshotDays = 60;

    /** Business days back from today that get activity-sample rows (0 = none). */
    private int $sampleDays = 14;

    private int $randomSeed = 20260803;

    private string $bigName = 'Northwind Global';

    private string $bigSlug = 'big-org';

    private string $emptyName = 'Fresh Start Co';

    private string $emptySlug = 'empty-org';

    private bool $withEmpty = true;

    private string $timezone = 'Asia/Karachi';

    /** @var callable(string):void */
    private $logger;

    // ── Results ──────────────────────────────────────────────────────────────
    /** @var array<string,int> table => rows written */
    public array $counts = [];

    public ?int $bigOrgId = null;

    public ?int $emptyOrgId = null;

    public ?string $bigOwnerEmail = null;

    public ?string $emptyOwnerEmail = null;

    public float $seconds = 0.0;

    // ── Internal state ───────────────────────────────────────────────────────
    /** @var array<int,array<string,mixed>> */
    private array $people = [];

    /** @var array<int,int> */
    private array $clientIds = [];

    /** @var array<int,int> */
    private array $profileIds = [];

    /** @var array<int,array{id:int,billable:bool,slug:string}> */
    private array $categories = [];

    /** @var array<string,array{id:int,status:string}> keyed "userId|periodKey" */
    private array $timesheetIndex = [];

    private int $orgId = 0;

    private string $tz = 'Asia/Karachi';

    /** Placeholder bind ceiling per INSERT statement, per driver. */
    private int $maxBinds = 900;

    public function configure(array $options): static
    {
        $this->userCount = max(1, (int) ($options['users'] ?? $this->userCount));
        $this->months = max(1, (int) ($options['months'] ?? $this->months));
        $this->screenshotDays = max(0, (int) ($options['screenshot_days'] ?? $this->screenshotDays));
        $this->sampleDays = max(0, (int) ($options['sample_days'] ?? $this->sampleDays));
        $this->randomSeed = (int) ($options['seed'] ?? $this->randomSeed);
        $this->bigName = (string) ($options['name'] ?? $this->bigName);
        $this->bigSlug = (string) ($options['slug'] ?? $this->bigSlug);
        $this->emptyName = (string) ($options['empty_name'] ?? $this->emptyName);
        $this->emptySlug = (string) ($options['empty_slug'] ?? $this->emptySlug);
        $this->withEmpty = (bool) ($options['with_empty'] ?? $this->withEmpty);
        $this->timezone = (string) ($options['timezone'] ?? $this->timezone);

        return $this;
    }

    public function onProgress(callable $logger): static
    {
        $this->logger = $logger;

        return $this;
    }

    public function run(): void
    {
        $this->seed();
    }

    /** @return array<string,mixed> summary */
    public function seed(): array
    {
        $startedAt = microtime(true);
        mt_srand($this->randomSeed);
        $this->maxBinds = $this->bindCeiling();

        Plan::ensureSeeded();

        // ── The brand-new EMPTY tenant: the other half of the acceptance test.
        if ($this->withEmpty) {
            $empty = $this->provision($this->emptyName, $this->emptySlug, 'general');
            $this->emptyOrgId = $empty->id;
            $this->emptyOwnerEmail = 'owner@'.$this->emptySlug.'.test';
            $this->grantEverything($empty);
            $this->log("Empty tenant #{$empty->id} ({$this->emptySlug}) provisioned — deliberately no data.");
        }

        // ── The BIG tenant.
        $big = $this->provision($this->bigName, $this->bigSlug, 'agency');
        $this->bigOrgId = $big->id;
        $this->bigOwnerEmail = 'owner@'.$this->bigSlug.'.test';
        $this->orgId = $big->id;
        $this->grantEverything($big);

        app(CurrentOrganization::class)->runFor($big, function () use ($big) {
            BusinessTime::flushTzCache();
            $this->tz = $big->businessTimezone() ?? $this->timezone;

            $this->seedSettings();
            $this->seedCatalogues();
            $this->seedPeople();
            $this->seedTimesheetShells();
            $this->seedActivity();
            $this->seedCompliance();
            $this->seedDiagnostics();
            $this->seedFeedback();
            $this->seedBilling();
            $this->finaliseTimesheets();
        });

        $this->seconds = round(microtime(true) - $startedAt, 2);

        return [
            'big_organization_id' => $this->bigOrgId,
            'empty_organization_id' => $this->emptyOrgId,
            'big_owner_email' => $this->bigOwnerEmail,
            'empty_owner_email' => $this->emptyOwnerEmail,
            'password' => self::PASSWORD,
            'seconds' => $this->seconds,
            'counts' => $this->counts,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tenant plumbing
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Provision through the REAL provisioner, so a seeded tenant is shaped
     * exactly like a signed-up one (owner, default team, settings row, trial
     * subscription). Then promote it to ACTIVE so EnsureOrganizationIsUsable
     * never bounces the benchmark on a lapsed trial.
     */
    private function provision(string $name, string $slug, string $workspaceType): Organization
    {
        $email = 'owner@'.$slug.'.test';

        $existing = User::withoutOrganizationScope()->where('email', $email)->first();

        if ($existing) {
            throw new \RuntimeException(
                "A tenant with owner {$email} already exists (organization #{$existing->organization_id}). ".
                'Re-run with --fresh to delete and rebuild it.'
            );
        }

        $result = app(TenantProvisioner::class)->provision(
            orgName: $name,
            ownerName: 'Workspace Owner',
            email: $email,
            plainPassword: self::PASSWORD,
            slug: $slug,
            trialDays: 365,
            workspaceType: $workspaceType,
        );

        /** @var Organization $org */
        $org = $result['organization'];
        $org->forceFill([
            'status' => Organization::STATUS_ACTIVE,
            'timezone' => $this->timezone,
            'onboarding_completed_at' => now(),
        ])->save();

        DB::table('subscriptions')->where('organization_id', $org->id)->update([
            'status' => 'active',
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->addYear(),
            'seats' => $this->userCount,
        ]);

        return $org->refresh();
    }

    /**
     * Turn every catalogued entitlement ON for the seeded tenant via a
     * per-company override. Deliberately NOT done by editing a plan: the
     * benchmark has to reach Insights, Compliance, Timesheets and Monitoring,
     * and the override layer is the documented way to grant without changing
     * what every other workspace gets.
     */
    private function grantEverything(Organization $org): void
    {
        $overrides = [];

        foreach (DB::table('features')->where('is_active', true)->get(['key', 'kind']) as $feature) {
            $overrides[$feature->key] = match ($feature->kind) {
                'max' => 'unlimited',
                'min' => 0,
                default => true,
            };
        }

        // A workspace with no `features` rows yet still needs the gates open.
        $overrides += [
            'monitoring' => true,
            'activity_tracking' => true,
            'app_url_tracking' => true,
            'insights' => true,
            'timesheet_approvals' => true,
            'machine_compliance' => true,
            'upwork_profiles' => true,
            'integrations' => true,
            'max_users' => 'unlimited',
        ];

        $org->forceFill(['entitlement_overrides' => $overrides])->save();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Catalogues
    // ─────────────────────────────────────────────────────────────────────────

    private function seedSettings(): void
    {
        $holidays = [];
        $cursor = $this->today()->copy()->subMonths($this->months)->startOfMonth();

        // One public holiday a month across the history — enough for the
        // attendance grid to have to branch, not enough to distort the totals.
        while ($cursor->lte($this->today())) {
            $holidays[] = $cursor->copy()->day(min(14, $cursor->daysInMonth))->toDateString();
            $cursor->addMonth();
        }

        MonitoringSetting::current()->forceFill([
            'attendance_tracking_start_date' => $this->today()->copy()->subMonths($this->months)->toDateString(),
            'attendance_week_off_days' => ['saturday', 'sunday'],
            'attendance_holidays' => $holidays,
            'attendance_auto_absent' => true,
            'activity_tracking_enabled' => true,
            'app_url_tracking_enabled' => true,
            'capture_enabled' => true,
            'screenshots_per_hour' => 6,
            'retention_days' => 180,
            'pay_period_cadence' => PayPeriod::WEEKLY,
            'pay_period_anchor_date' => $this->today()->copy()->subMonths($this->months)->startOfWeek()->toDateString(),
            'display_timezone' => $this->timezone,
        ])->save();

        MonitoringSetting::forgetSharedCache();
    }

    private function seedCatalogues(): void
    {
        $now = now();

        // Work categories: the workspace-type defaults plus two of its own, so
        // the billable split is not 50/50 and the picker is not the stock list.
        WorkCategory::ensureSeeded();
        foreach ([['Design QA', false], ['Client Onboarding', true]] as [$name, $billable]) {
            DB::table('work_categories')->insert($this->stamp([
                'name' => $name,
                'slug' => strtolower(str_replace(' ', '_', $name)),
                'is_billable_default' => $billable,
                'sort_order' => 90,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }

        $this->categories = DB::table('work_categories')
            ->where('organization_id', $this->orgId)
            ->orderBy('sort_order')
            ->get(['id', 'slug', 'is_billable_default'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'slug' => (string) $row->slug, 'billable' => (bool) $row->is_billable_default])
            ->all();
        $this->count('work_categories', count($this->categories));

        // Shifts, INCLUDING an overnight one. The overnight shift is the whole
        // reason attendanceDateFor() exists, so the dataset must contain it.
        $shiftDefs = [
            ['Morning', '09:00:00', 8, 15],
            ['Noon', '12:00:00', 8, 15],
            ['Evening', '17:00:00', 8, 20],
            ['Night (overnight)', '22:00:00', 8, 20],
            ['Early Support', '06:00:00', 6, 10],
            ['Flexible', null, null, 30],
        ];
        $shiftRows = [];
        foreach ($shiftDefs as $i => [$name, $start, $hours, $grace]) {
            $shiftRows[] = $this->stamp([
                'name' => $name,
                'start_time' => $start,
                'hours' => $hours,
                'grace_minutes' => $grace,
                'sort_order' => $i,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $this->insertChunked('shifts', $shiftRows);
        $shiftIds = DB::table('shifts')->where('organization_id', $this->orgId)->orderBy('sort_order')->pluck('id')->all();

        // Teams and designations.
        $teamRows = [];
        foreach (['Engineering', 'Design', 'QA', 'Support', 'Delivery', 'Bidding', 'Finance', 'Operations'] as $i => $name) {
            $teamRows[] = $this->stamp([
                'name' => $name,
                'color' => ['#2563eb', '#7c3aed', '#059669', '#d97706', '#dc2626', '#0891b2', '#4f46e5', '#be185d'][$i],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $this->insertChunked('teams', $teamRows);
        $teamIds = DB::table('teams')->where('organization_id', $this->orgId)->pluck('id')->all();

        $designationRows = [];
        foreach ([
            'Software Engineer', 'Senior Engineer', 'Tech Lead', 'UI Designer', 'UX Researcher',
            'QA Engineer', 'Support Agent', 'Project Manager', 'Bid Specialist', 'Accountant',
        ] as $name) {
            $designationRows[] = $this->stamp(['name' => $name, 'created_at' => $now, 'updated_at' => $now]);
        }
        $this->insertChunked('designations', $designationRows);

        // Upwork profiles (the Agency toolkit surface).
        $profileRows = [];
        foreach (['Northwind Web', 'Northwind Mobile', 'Northwind Design', 'Northwind Data', 'Northwind Support', 'Northwind Cloud', 'Northwind AI', 'Northwind Legacy'] as $i => $name) {
            $profileRows[] = $this->stamp([
                'name' => $name,
                'email' => 'profile'.$i.'@'.$this->bigSlug.'.test',
                'description' => $name.' engagements.',
                'is_active' => $i < 6,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $this->insertChunked('upwork_profiles', $profileRows);
        $this->profileIds = DB::table('upwork_profiles')->where('organization_id', $this->orgId)->pluck('id')->all();

        // Clients — 48 of them, a fifth archived, three work types.
        $prefixes = ['Acme', 'Vertex', 'Lumen', 'Harbor', 'Cedar', 'Orbit', 'Northstar', 'Brightline', 'Quanta', 'Solstice', 'Ironwood', 'Pinecrest'];
        $suffixes = ['Analytics', 'Health', 'Commerce', 'Logistics'];
        $clientRows = [];
        $n = 0;
        foreach ($prefixes as $p) {
            foreach ($suffixes as $s) {
                $clientRows[] = $this->stamp([
                    'name' => $p.' '.$s,
                    'tags' => json_encode([strtolower($s), $n % 3 === 0 ? 'retainer' : 'project']),
                    'work_type' => ['tracker_manual', 'tracker_manual', 'fixed', 'outside_of_upwork'][$n % 4],
                    'upwork_profile_id' => $this->profileIds[$n % max(1, count($this->profileIds))] ?? null,
                    'is_active' => $n % 5 !== 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $n++;
            }
        }
        $this->insertChunked('clients', $clientRows);
        $this->clientIds = DB::table('clients')->where('organization_id', $this->orgId)->pluck('id')->all();
        $this->count('clients', count($this->clientIds));

        // Client ↔ profile pivot (many-to-many, no organization_id column).
        $pivot = [];
        foreach ($this->clientIds as $i => $clientId) {
            $pivot[] = ['client_id' => $clientId, 'upwork_profile_id' => $this->profileIds[$i % count($this->profileIds)]];
            if ($i % 3 === 0) {
                $pivot[] = ['client_id' => $clientId, 'upwork_profile_id' => $this->profileIds[($i + 1) % count($this->profileIds)]];
            }
        }
        $this->insertChunked('client_upwork_profile', $pivot);

        // A handful of tenant-authored blocklist rules on top of the baseline.
        $ruleRows = [];
        foreach ([
            ['auto_clicker', 'Auto clickers', 'critical', true, 'programs', 'auto.?clicker|clickermann'],
            ['mouse_jiggler', 'Mouse jigglers', 'critical', true, 'programs', 'jiggler|move.?mouse'],
            ['scraper_ext', 'Scraping extensions', 'high', true, 'extensions', 'scraper|web.?harvest'],
            ['refresh_ext', 'Auto-refresh extensions', 'medium', false, 'extensions', 'auto.?refresh|page.?monitor'],
            ['vpn_client', 'VPN clients', 'medium', false, 'programs', 'nordvpn|expressvpn|protonvpn'],
        ] as $i => [$key, $label, $severity, $alert, $kind, $pattern]) {
            $ruleRows[] = $this->stamp([
                'rule_key' => 'tenant_'.$key,
                'label' => $label,
                'severity' => $severity,
                'alert' => $alert,
                'kind' => $kind,
                'match_type' => MachineBlocklistRule::MATCH_REGEX,
                'pattern' => $pattern,
                'is_allow' => false,
                'is_system' => false,
                'enabled' => true,
                'sort' => $i,
                'notes' => 'Seeded by staffvora:seed-big-org.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $this->insertChunked('machine_blocklist_rules', $ruleRows);

        $this->log(sprintf(
            'Catalogues: %d clients, %d profiles, %d categories, %d shifts, %d teams.',
            count($this->clientIds), count($this->profileIds), count($this->categories), count($shiftIds), count($teamIds)
        ));

        $this->shiftIds = $shiftIds;
        $this->teamIds = $teamIds;
        $this->designationNames = DB::table('designations')->where('organization_id', $this->orgId)->pluck('name')->all();
    }

    /** @var array<int,int> */
    private array $shiftIds = [];

    /** @var array<int,int> */
    private array $teamIds = [];

    /** @var array<int,string> */
    private array $designationNames = [];

    // ─────────────────────────────────────────────────────────────────────────
    // People
    // ─────────────────────────────────────────────────────────────────────────

    private function seedPeople(): void
    {
        $now = now();
        $password = Hash::make(self::PASSWORD);

        $first = ['Ayesha', 'Bilal', 'Sara', 'Hamza', 'Zainab', 'Usman', 'Fatima', 'Ali', 'Maryam', 'Omar',
            'Nida', 'Faisal', 'Hira', 'Tariq', 'Amna', 'Kashif', 'Rabia', 'Junaid', 'Sana', 'Imran',
            'Mehwish', 'Adnan', 'Iqra', 'Salman', 'Noor', 'Waqas', 'Areeba', 'Danish', 'Laiba', 'Shahid'];
        $last = ['Khan', 'Ahmed', 'Ali', 'Malik', 'Shah', 'Hussain', 'Iqbal', 'Raza', 'Siddiqui', 'Baig',
            'Chaudhry', 'Farooq', 'Javed', 'Nawaz', 'Qureshi', 'Rehman'];

        $owner = User::query()->where('organization_id', $this->orgId)->orderBy('id')->firstOrFail();

        // The owner is person #0 — a real working super_admin, not a ghost.
        $this->people[] = $this->personProfile($owner->id, 0);
        DB::table('users')->where('id', $owner->id)->update([
            'shift_id' => $this->people[0]['shift_id'],
            'shift_start_time' => $this->people[0]['shift_start'],
            'shift_hours' => $this->people[0]['shift_hours'],
            'team_id' => $this->people[0]['team_id'],
            'designation' => $this->people[0]['designation'],
            'joining_date' => $this->people[0]['joining_date'],
            'is_active' => true,
        ]);

        $userRows = [];
        $nextUserId = $this->nextId('users');

        for ($i = 1; $i < $this->userCount; $i++) {
            $id = $nextUserId + $i - 1;
            $profile = $this->personProfile($id, $i);
            $this->people[] = $profile;

            // Roles: the owner plus ~6% admins, the rest members. Permissions on
            // a slice of members, so effectivePermissions() is not uniform.
            $role = $i % 17 === 0 ? 'admin' : 'member';
            $permissions = $role === 'admin'
                ? ['reports.view', 'attendance.view', 'users.view', 'monitoring.view', 'timeline.view_others', 'insights.view', 'compliance.view']
                : ($i % 5 === 0 ? ['reports.view'] : []);

            $userRows[] = [
                'id' => $id,
                'organization_id' => $this->orgId,
                'name' => $first[$i % count($first)].' '.$last[intdiv($i, count($first)) % count($last)].($i > 100 ? ' '.$i : ''),
                'email' => 'user'.$i.'@'.$this->bigSlug.'.test',
                'email_verified_at' => $now,
                'password' => $password,
                'role' => $role,
                'permissions' => json_encode($permissions),
                'include_in_slack_reports' => true,
                'remember_token' => null,
                'designation' => $profile['designation'],
                'team_id' => $profile['team_id'],
                'shift_id' => $profile['shift_id'],
                'shift_start_time' => $profile['shift_start'],
                'shift_hours' => $profile['shift_hours'],
                'shift_grace_minutes' => 15,
                'joining_date' => $profile['joining_date'],
                'allow_multiple_devices' => $i % 25 === 0,
                'is_active' => $profile['active'],
                'is_platform_admin' => false,
                'theme' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->insertChunked('users', $userRows);
        $this->count('users', count($userRows) + 1);

        // A per-user monitoring override for a slice of the team — the settings
        // resolver has to merge these on every desktop/settings read.
        $overrideRows = [];
        foreach ($this->people as $p) {
            if ($p['index'] % 9 !== 0) {
                continue;
            }
            $overrideRows[] = $this->stamp([
                'user_id' => $p['id'],
                'override_screenshots' => true,
                'screenshots_per_hour' => [3, 6, 10][$p['index'] % 3],
                'override_activity' => true,
                'activity_tracking_enabled' => true,
                'override_app_url' => false,
                'override_weekly_limit' => false,
                'override_auto_pause' => false,
                'override_offline_time' => false,
                'override_notify_screenshot' => false,
                'override_desktop_app' => false,
                'override_display' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $this->insertChunked('user_monitoring_settings', $overrideRows);

        $archived = count(array_filter($this->people, fn ($p) => ! $p['active']));
        $this->log(sprintf('People: %d users (%d archived), %d monitoring overrides.', count($this->people), $archived, count($overrideRows)));
    }

    /** @return array<string,mixed> */
    private function personProfile(int $id, int $index): array
    {
        // Shift assignment: most people get a named shift; every 7th person is
        // deliberately shift_id-less with a personal start time instead, because
        // effectiveShiftFor() has to resolve BOTH paths.
        $usesNamedShift = $index % 7 !== 0;
        $shiftSlot = $index % 6;

        $namedStarts = ['09:00:00', '12:00:00', '17:00:00', '22:00:00', '06:00:00', null];
        $namedHours = [8, 8, 8, 8, 6, null];

        return [
            'id' => $id,
            'index' => $index,
            'shift_id' => $usesNamedShift ? ($this->shiftIds[$shiftSlot] ?? null) : null,
            'shift_start' => $usesNamedShift ? null : ['08:30:00', '10:00:00', '14:00:00'][$index % 3],
            'shift_hours' => $usesNamedShift ? null : 8,
            'effective_start' => $usesNamedShift ? ($namedStarts[$shiftSlot] ?? '09:00:00') : ['08:30:00', '10:00:00', '14:00:00'][$index % 3],
            'effective_hours' => $usesNamedShift ? ($namedHours[$shiftSlot] ?? 8) : 8,
            'overnight' => $usesNamedShift && $shiftSlot === 3,
            'team_id' => $this->teamIds[$index % max(1, count($this->teamIds))] ?? null,
            'designation' => $this->designationNames[$index % max(1, count($this->designationNames))] ?? 'Employee',
            'joining_date' => $this->today()->copy()->subDays(400 + ($index * 3))->toDateString(),
            // ~10% of the team has left. They keep their history (that is the
            // point of archiving) but stop generating new rows part-way through.
            'active' => $index === 0 || $index % 11 !== 0,
            'left_after_days' => ($index !== 0 && $index % 11 === 0)
                ? mt_rand((int) max(1, $this->historyDays() * 0.5), (int) max(2, $this->historyDays() - 3))
                : null,
            // Per-person character: diligence (attendance), activity skew,
            // screenshot density. This is what makes the dataset non-uniform.
            'diligence' => 0.80 + (($index % 20) / 100),
            'activity_base' => 45 + (($index * 7) % 45),
            'tracked_bias' => (($index % 10) < 7),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Attendance + work + tracking
    // ─────────────────────────────────────────────────────────────────────────

    private function seedActivity(): void
    {
        $historyDays = $this->historyDays();
        $today = $this->today();
        $holidays = array_flip((array) MonitoringSetting::current()->attendance_holidays);

        $nextSessionId = $this->nextId('tracking_sessions');

        $timeEntries = [];
        $workHours = [];
        $sessions = [];
        $screenshots = [];
        $samples = [];
        $marks = [];
        $overrides = [];

        $totals = ['time_entries' => 0, 'work_hours' => 0, 'tracking_sessions' => 0,
            'tracking_screenshots' => 0, 'tracking_activity_samples' => 0,
            'manual_attendance_marks' => 0, 'shift_overrides' => 0];

        $apps = ['Code.exe', 'chrome.exe', 'Figma.exe', 'slack.exe', 'Excel.exe', 'Terminal', 'Photoshop.exe', 'Postman.exe'];
        $domains = ['github.com', 'app.staffvora.com', 'docs.google.com', 'figma.com', 'stackoverflow.com', 'jira.company.com', null];
        $notes = [
            'Implemented the dashboard filters and verified responsive behaviour.',
            'Reviewed client feedback and shipped the requested revisions.',
            'Built the API integration and covered the error paths.',
            'Triaged reported defects and released two fixes.',
            'Prepared release notes and deployment checklist.',
            'Pairing session on the reporting query performance.',
            'Client call plus follow-up action items.',
        ];

        // Flush by BUFFERED ROW COUNT, not by day. A high screenshot density
        // (--screenshot-days=180) buffers tens of thousands of rows per business
        // day; a fixed "every 5 days" flush exhausts PHP's memory limit exactly
        // at the scale this seeder exists to reach.
        $buffered = 0;
        $flushEvery = 10_000;

        $flush = function () use (&$timeEntries, &$workHours, &$sessions, &$screenshots, &$samples, &$marks, &$overrides, &$totals, &$buffered) {
            DB::transaction(function () use (&$timeEntries, &$workHours, &$sessions, &$screenshots, &$samples, &$marks, &$overrides, &$totals) {
                foreach ([
                    'tracking_sessions' => &$sessions,
                    'time_entries' => &$timeEntries,
                    'work_hours' => &$workHours,
                    'tracking_screenshots' => &$screenshots,
                    'tracking_activity_samples' => &$samples,
                    'manual_attendance_marks' => &$marks,
                    'shift_overrides' => &$overrides,
                ] as $table => &$rows) {
                    if ($rows === []) {
                        continue;
                    }
                    $this->insertChunked($table, $rows);
                    $totals[$table] += count($rows);
                    $rows = [];
                }
            });

            $buffered = 0;
        };

        for ($d = $historyDays; $d >= 0; $d--) {
            $date = $today->copy()->subDays($d);
            $dateStr = $date->toDateString();
            $isWeekend = $date->isSaturday() || $date->isSunday();
            $isHoliday = isset($holidays[$dateStr]);
            $withinScreenshotWindow = $d < $this->screenshotDays;
            $withinSampleWindow = $d < $this->sampleDays;

            foreach ($this->people as $p) {
                // Somebody who left stops producing rows part-way through.
                if ($p['left_after_days'] !== null && ($historyDays - $d) > $p['left_after_days']) {
                    continue;
                }

                if ($isHoliday) {
                    if ($p['index'] % 4 === 0) {
                        $marks[] = $this->stamp([
                            'user_id' => $p['id'],
                            'marked_by_user_id' => $this->people[0]['id'],
                            'attendance_date' => $dateStr,
                            'status_code' => 'PH',
                            'note' => 'Public holiday',
                            'created_at' => $date, 'updated_at' => $date,
                        ]);
                    }

                    continue;
                }

                // Weekends off, except a rotating on-call slice — real support
                // teams work them, and the grid has to render that correctly.
                if ($isWeekend && ($p['index'] + $d) % 11 !== 0) {
                    continue;
                }

                // Leave / absence.
                $roll = mt_rand(1, 1000) / 1000;
                if ($roll > $p['diligence']) {
                    if (mt_rand(1, 100) <= 55) {
                        $marks[] = $this->stamp([
                            'user_id' => $p['id'],
                            'marked_by_user_id' => $this->people[0]['id'],
                            'attendance_date' => $dateStr,
                            'status_code' => ['L', 'HD', 'WFH'][mt_rand(0, 2)],
                            'note' => 'Seeded leave record',
                            'created_at' => $date, 'updated_at' => $date,
                        ]);
                    }

                    continue;
                }

                // One-day shift change now and then.
                if (mt_rand(1, 400) === 1) {
                    $overrides[] = $this->stamp([
                        'user_id' => $p['id'],
                        'date' => $dateStr,
                        'shift_start_time' => ['07:00:00', '11:00:00', '15:00:00'][mt_rand(0, 2)],
                        'shift_hours' => 8,
                        'reason' => 'Covering a colleague',
                        'created_by' => $this->people[0]['id'],
                        'created_at' => $date, 'updated_at' => $date,
                    ]);
                }

                [$h, $m] = array_map('intval', explode(':', (string) $p['effective_start']));
                $shiftHours = (float) ($p['effective_hours'] ?: 8);

                $clockIn = $date->copy()->setTime($h, $m, 0)->addMinutes(mt_rand(-8, 45));
                $workedMinutes = (int) round($shiftHours * 60) + mt_rand(-45, 75);
                $clockOut = $clockIn->copy()->addMinutes($workedMinutes);

                // ~1.5% of days: the person forgot to clock out. This is a real
                // production shape and it breaks naive pairing logic.
                $forgotClockOut = mt_rand(1, 1000) <= 15;

                $timeEntries[] = $this->punch($p['id'], 'clock_in', $clockIn, $dateStr, 'Shift started');

                // One or two breaks.
                $breaks = mt_rand(1, 100) <= 70 ? 1 : 2;
                $breakMinutesTotal = 0;
                for ($b = 0; $b < $breaks; $b++) {
                    $breakStart = $clockIn->copy()->addMinutes((int) ($workedMinutes * (0.35 + 0.3 * $b)) + mt_rand(-20, 20));
                    $breakLen = mt_rand(15, 55);
                    $breakEnd = $breakStart->copy()->addMinutes($breakLen);
                    if ($breakEnd->gte($clockOut)) {
                        continue;
                    }
                    $breakMinutesTotal += $breakLen;
                    $timeEntries[] = $this->punch($p['id'], 'break_start', $breakStart, $dateStr, $b === 0 ? 'Lunch break' : 'Short break');
                    $timeEntries[] = $this->punch($p['id'], 'break_end', $breakEnd, $dateStr, 'Back to work');
                }

                if (! $forgotClockOut) {
                    $timeEntries[] = $this->punch($p['id'], 'clock_out', $clockOut, $dateStr, 'Shift ended');
                }

                // ── Work entries for the day: 1–3, tracked and/or manual.
                $netMinutes = max(60, $workedMinutes - $breakMinutesTotal);
                $entryCount = mt_rand(1, 3);
                $cursor = $clockIn->copy()->addMinutes(mt_rand(2, 20));
                $remaining = $netMinutes;

                for ($e = 0; $e < $entryCount; $e++) {
                    $slice = $e === $entryCount - 1 ? $remaining : (int) round($remaining / ($entryCount - $e)) + mt_rand(-25, 25);
                    $slice = max(25, min($slice, $remaining));
                    $remaining -= $slice;
                    if ($slice < 20) {
                        break;
                    }

                    $start = $cursor->copy();
                    $end = $start->copy()->addMinutes($slice);
                    $cursor = $end->copy()->addMinutes(mt_rand(3, 25));

                    $category = $this->categories[($p['index'] + $e + $d) % max(1, count($this->categories))]
                        ?? ['id' => null, 'slug' => 'client_work', 'billable' => true];
                    $clientId = $this->clientIds[($p['index'] * 3 + $e + $d) % max(1, count($this->clientIds))] ?? null;
                    $profileId = $this->profileIds[($p['index'] + $e) % max(1, count($this->profileIds))] ?? null;

                    // Billable mostly follows the category default, but not
                    // always — a manager can flip it, and reports must handle it.
                    $billable = mt_rand(1, 100) <= 88 ? $category['billable'] : ! $category['billable'];

                    $tracked = $p['tracked_bias'] ? mt_rand(1, 100) <= 75 : mt_rand(1, 100) <= 35;
                    $sessionId = null;

                    if ($tracked) {
                        $sessionId = $nextSessionId++;
                        $activity = max(5, min(99, $p['activity_base'] + mt_rand(-18, 22)));

                        $sessions[] = [
                            'id' => $sessionId,
                            'organization_id' => $this->orgId,
                            'user_id' => $p['id'],
                            'client_id' => $clientId,
                            'upwork_profile_id' => $profileId,
                            'work_type' => $category['slug'] === 'bidding' ? 'bidding' : 'tracker',
                            'task_note' => $notes[($p['index'] + $e) % count($notes)],
                            'started_at' => $this->store($start),
                            'stopped_at' => $this->store($end),
                            'last_heartbeat_at' => $this->store($end),
                            'total_seconds' => $slice * 60,
                            'activity_percent' => $activity,
                            'status' => 'stopped',
                            'source' => 'desktop',
                            'device_name' => 'PC-'.str_pad((string) $p['index'], 3, '0', STR_PAD_LEFT),
                            'platform' => $p['index'] % 8 === 0 ? 'darwin' : 'win32',
                            'app_version' => ['0.4.4', '0.4.5', '0.4.6'][$p['index'] % 3],
                            'client_uuid' => sprintf('%08x-seed-%04d-%04d', $sessionId, $p['index'], $d),
                            'health_alerted_at' => null,
                            'health_probe_seconds' => null,
                            'created_at' => $this->store($start),
                            'updated_at' => $this->store($end),
                        ];

                        if ($withinScreenshotWindow) {
                            $perHour = [3, 6, 6, 10][$p['index'] % 4];
                            $shots = max(1, (int) round(($slice / 60) * $perHour));
                            for ($s = 0; $s < $shots; $s++) {
                                $at = $start->copy()->addMinutes((int) ($slice * ($s + 0.5) / $shots));
                                $screenshots[] = [
                                    'organization_id' => $this->orgId,
                                    'tracking_session_id' => $sessionId,
                                    'user_id' => $p['id'],
                                    'captured_at' => $this->store($at),
                                    'image_path' => sprintf('org/%d/%s/u%d/s%d-%d.jpg', $this->orgId, $date->format('Y/m/d'), $p['id'], $sessionId, $s),
                                    'thumbnail_path' => sprintf('org/%d/%s/u%d/s%d-%d-thumb.jpg', $this->orgId, $date->format('Y/m/d'), $p['id'], $sessionId, $s),
                                    'file_size' => mt_rand(90_000, 420_000),
                                    'width' => 1920, 'height' => 1080,
                                    'activity_percent' => max(0, min(100, $activity + mt_rand(-25, 25))),
                                    'keyboard_count' => mt_rand(0, 900),
                                    'mouse_count' => mt_rand(0, 1400),
                                    'active_app' => $apps[($p['index'] + $s) % count($apps)],
                                    'active_window_title' => 'Working on '.$notes[($s + $e) % count($notes)],
                                    'url_domain' => $domains[($p['index'] + $s) % count($domains)],
                                    'is_flagged' => mt_rand(1, 200) === 1,
                                    'flag_reason' => null,
                                    'deleted_at' => null,
                                    'created_at' => $this->store($at),
                                    'updated_at' => $this->store($at),
                                ];
                            }
                        }

                        if ($withinSampleWindow) {
                            $sampleCount = max(1, (int) floor($slice / 5));
                            for ($s = 0; $s < $sampleCount; $s++) {
                                $at = $start->copy()->addMinutes($s * 5);
                                $samples[] = [
                                    'organization_id' => $this->orgId,
                                    'tracking_session_id' => $sessionId,
                                    'user_id' => $p['id'],
                                    'captured_at' => $this->store($at),
                                    'keyboard_count' => mt_rand(0, 250),
                                    'mouse_count' => mt_rand(0, 400),
                                    'idle_seconds' => mt_rand(0, 180),
                                    'active_app' => $apps[($p['index'] + $s) % count($apps)],
                                    'active_window_title' => null,
                                    'url_domain' => $domains[($s) % count($domains)],
                                    'created_at' => $this->store($at),
                                    'updated_at' => $this->store($at),
                                ];
                            }
                        }
                    }

                    $timesheet = $this->timesheetIndex[$p['id'].'|'.$this->periodKeyFor($dateStr)] ?? null;

                    $workHours[] = [
                        'organization_id' => $this->orgId,
                        'user_id' => $p['id'],
                        'date' => $dateStr,
                        'started_at' => $this->store($start),
                        'ended_at' => $this->store($end),
                        'hours' => round($slice / 60, 2),
                        'description' => $notes[($p['index'] + $e + $d) % count($notes)],
                        'work_type' => $tracked ? 'tracker' : 'manual',
                        'work_category_id' => $category['id'],
                        'is_billable' => $billable,
                        'client_id' => $clientId,
                        'tracker' => $profileId ? 'Profile #'.$profileId : null,
                        'tracking_session_id' => $sessionId,
                        'source' => $tracked ? 'tracker' : 'manual',
                        'timesheet_id' => ($timesheet && $timesheet['status'] === 'approved') ? $timesheet['id'] : null,
                        'created_at' => $this->store($end),
                        'updated_at' => $this->store($end),
                    ];
                }
            }

            $buffered = count($timeEntries) + count($workHours) + count($sessions)
                + count($screenshots) + count($samples) + count($marks) + count($overrides);

            if ($buffered >= $flushEvery) {
                $flush();
            }
        }

        // A few sessions still running right now, so "who is working" is not empty.
        $liveStart = $this->now()->copy()->subMinutes(mt_rand(20, 200));
        foreach (array_slice(array_values(array_filter($this->people, fn ($p) => $p['active'])), 0, 12) as $p) {
            $sessionId = $nextSessionId++;
            $sessions[] = [
                'id' => $sessionId,
                'organization_id' => $this->orgId,
                'user_id' => $p['id'],
                'client_id' => $this->clientIds[$p['index'] % count($this->clientIds)] ?? null,
                'upwork_profile_id' => $this->profileIds[$p['index'] % count($this->profileIds)] ?? null,
                'work_type' => 'tracker',
                'task_note' => 'In progress right now',
                'started_at' => $this->store($liveStart),
                'stopped_at' => null,
                'last_heartbeat_at' => $this->store($this->now()->copy()->subMinutes(1)),
                'total_seconds' => $this->now()->diffInSeconds($liveStart),
                'activity_percent' => max(5, min(99, $p['activity_base'])),
                'status' => 'active',
                'source' => 'desktop',
                'device_name' => 'PC-'.str_pad((string) $p['index'], 3, '0', STR_PAD_LEFT),
                'platform' => 'win32',
                'app_version' => '0.4.6',
                'client_uuid' => 'live-'.$sessionId,
                'health_alerted_at' => null,
                'health_probe_seconds' => null,
                'created_at' => $this->store($liveStart),
                'updated_at' => now(),
            ];
        }

        $flush();

        foreach ($totals as $table => $rows) {
            $this->count($table, $rows);
        }

        $this->log(sprintf(
            'Activity: %s punches, %s work entries, %s sessions, %s screenshots, %s activity samples, %s manual marks. (peak memory %s MB)',
            number_format($totals['time_entries']), number_format($totals['work_hours']),
            number_format($totals['tracking_sessions']), number_format($totals['tracking_screenshots']),
            number_format($totals['tracking_activity_samples']), number_format($totals['manual_attendance_marks']),
            number_format(memory_get_peak_usage(true) / 1048576, 0)
        ));
    }

    /** @return array<string,mixed> */
    private function punch(int $userId, string $type, Carbon $at, string $attendanceDate, string $note): array
    {
        $stored = $this->store($at);

        return [
            'organization_id' => $this->orgId,
            'user_id' => $userId,
            'action_type' => $type,
            'action_timestamp' => $stored,
            // The ATTENDANCE day, not the calendar day of the instant — an
            // overnight shift's clock_out lands after midnight but still belongs
            // to the day the shift started.
            'action_date' => $attendanceDate,
            'action_time' => $at->format('H:i:s'),
            'notes' => $note,
            'slack_thread_ts' => null,
            'created_at' => $stored,
            'updated_at' => $stored,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Timesheets
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Timesheet ROWS are created before the work entries so approved periods can
     * stamp `work_hours.timesheet_id` inline (that stamp is the approval lock).
     * Totals are filled in afterwards from the entries that actually landed.
     */
    private function seedTimesheetShells(): void
    {
        $rows = [];
        $nextId = $this->nextId('timesheets');
        $period = PayPeriod::current()->previous();
        $now = now();

        // 10 completed periods back, which is comfortably inside MAX_HISTORY.
        for ($i = 0; $i < 10; $i++) {
            foreach ($this->people as $p) {
                if (! $p['active'] && $i < 3) {
                    continue; // people who left stopped submitting
                }

                // Older periods are approved; the two most recent are a live
                // approvals queue: submitted, a couple rejected, some untouched.
                $status = match (true) {
                    $i >= 3 => 'approved',
                    $i === 2 => ($p['index'] % 9 === 0 ? 'rejected' : 'approved'),
                    default => ($p['index'] % 4 === 0 ? null : 'submitted'),
                };

                if ($status === null) {
                    continue; // no row at all == "open" (see the Timesheet docblock)
                }

                $id = $nextId++;
                $this->timesheetIndex[$p['id'].'|'.$period->key()] = ['id' => $id, 'status' => $status];

                $rows[] = [
                    'id' => $id,
                    'organization_id' => $this->orgId,
                    'user_id' => $p['id'],
                    'period_key' => $period->key(),
                    'cadence' => $period->cadence,
                    'period_start' => $period->startDate(),
                    'period_end' => $period->endDate(),
                    'status' => $status,
                    'submitted_at' => $period->end->copy()->addHours(20),
                    'submitted_by' => $p['id'],
                    'reviewed_at' => $status === 'submitted' ? null : $period->end->copy()->addDays(1),
                    'reviewed_by' => $status === 'submitted' ? null : $this->people[0]['id'],
                    'locked_at' => $status === 'approved' ? $period->end->copy()->addDays(1) : null,
                    'note' => $status === 'rejected' ? 'Missing description on two entries.' : null,
                    'total_hours' => 0, 'billable_hours' => 0, 'tracked_hours' => 0,
                    'manual_hours' => 0, 'entries_count' => 0,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }

            $period = $period->previous();
        }

        $this->insertChunked('timesheets', $rows);
        $this->count('timesheets', count($rows));

        // The approval audit trail — one row per transition.
        $approvals = [];
        foreach ($rows as $sheet) {
            $approvals[] = $this->approvalRow($sheet, 'submit', 'open', 'submitted', $sheet['submitted_at'], $sheet['user_id']);
            if ($sheet['status'] !== 'submitted') {
                $approvals[] = $this->approvalRow(
                    $sheet,
                    $sheet['status'] === 'approved' ? 'approve' : 'reject',
                    'submitted',
                    $sheet['status'],
                    $sheet['reviewed_at'],
                    $this->people[0]['id']
                );
            }
        }
        $this->insertChunked('timesheet_approvals', $approvals);
        $this->count('timesheet_approvals', count($approvals));
    }

    /** @return array<string,mixed> */
    private function approvalRow(array $sheet, string $action, string $from, string $to, $at, int $actor): array
    {
        return [
            'organization_id' => $this->orgId,
            'timesheet_id' => $sheet['id'],
            'subject_user_id' => $sheet['user_id'],
            'actor_user_id' => $actor,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'note' => null,
            'total_hours' => null,
            'billable_hours' => null,
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }

    /**
     * Fill the snapshot columns from the entries that actually landed, in ONE
     * grouped statement per column set rather than a query per timesheet.
     */
    private function finaliseTimesheets(): void
    {
        $sheets = DB::table('timesheets')->where('organization_id', $this->orgId)
            ->get(['id', 'user_id', 'period_start', 'period_end']);

        if ($sheets->isEmpty()) {
            return;
        }

        $byUserPeriod = [];
        foreach ($sheets as $sheet) {
            $byUserPeriod[$sheet->user_id][] = $sheet;
        }

        $aggregates = DB::table('work_hours')
            ->where('organization_id', $this->orgId)
            ->selectRaw('user_id, date, sum(hours) as hours, sum(case when is_billable = 1 then hours else 0 end) as billable, sum(case when source = ? then hours else 0 end) as tracked, count(*) as entries', ['tracker'])
            ->groupBy('user_id', 'date')
            ->get();

        $perUserDay = [];
        foreach ($aggregates as $row) {
            $perUserDay[$row->user_id][substr((string) $row->date, 0, 10)] = $row;
        }

        $updates = [];
        foreach ($sheets as $sheet) {
            $total = $billable = $tracked = 0.0;
            $entries = 0;
            $cursor = Carbon::parse(substr((string) $sheet->period_start, 0, 10));
            $end = Carbon::parse(substr((string) $sheet->period_end, 0, 10));

            while ($cursor->lte($end)) {
                $row = $perUserDay[$sheet->user_id][$cursor->toDateString()] ?? null;
                if ($row) {
                    $total += (float) $row->hours;
                    $billable += (float) $row->billable;
                    $tracked += (float) $row->tracked;
                    $entries += (int) $row->entries;
                }
                $cursor->addDay();
            }

            $updates[] = [
                'id' => $sheet->id,
                'total_hours' => round($total, 2),
                'billable_hours' => round($billable, 2),
                'tracked_hours' => round($tracked, 2),
                'manual_hours' => round($total - $tracked, 2),
                'entries_count' => $entries,
            ];
        }

        DB::transaction(function () use ($updates) {
            foreach (array_chunk($updates, 200) as $chunk) {
                foreach ($chunk as $u) {
                    DB::table('timesheets')->where('id', $u['id'])->update([
                        'total_hours' => $u['total_hours'],
                        'billable_hours' => $u['billable_hours'],
                        'tracked_hours' => $u['tracked_hours'],
                        'manual_hours' => $u['manual_hours'],
                        'entries_count' => $u['entries_count'],
                    ]);
                }
            }
        });

        $this->log('Timesheets: '.count($updates).' period snapshots totalled.');
    }

    private function periodKeyFor(string $date): string
    {
        static $cache = [];

        return $cache[$date] ??= PayPeriod::containing($date)->key();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Machine compliance
    // ─────────────────────────────────────────────────────────────────────────

    private function seedCompliance(): void
    {
        $now = now();
        $machines = [];
        $items = [];
        $flags = [];
        $nextMachineId = $this->nextId('machines');

        $extensions = [
            ['uBlock Origin', 'Raymond Hill', false, null],
            ['Grammarly', 'Grammarly Inc.', false, null],
            ['LastPass', 'LastPass', false, null],
            ['Web Scraper', 'webscraper.io', true, 'tenant_scraper_ext'],
            ['Auto Refresh Plus', 'autorefresh.io', true, 'tenant_refresh_ext'],
            ['ColorZilla', 'colorzilla.com', false, null],
            ['JSON Viewer', 'tulios', false, null],
            ['Loom', 'Loom Inc.', false, null],
            ['React DevTools', 'Meta', false, null],
            ['Wappalyzer', 'Wappalyzer', false, null],
        ];
        $programs = [
            ['Visual Studio Code', 'Microsoft', false, null],
            ['Google Chrome', 'Google LLC', false, null],
            ['Slack', 'Slack Technologies', false, null],
            ['Docker Desktop', 'Docker Inc.', false, null],
            ['NordVPN', 'Nord Security', true, 'tenant_vpn_client'],
            ['AutoClicker 3.0', 'Unknown', true, 'tenant_auto_clicker'],
            ['Mouse Jiggler', 'Unknown', true, 'tenant_mouse_jiggler'],
            ['Figma', 'Figma Inc.', false, null],
            ['Postman', 'Postman Inc.', false, null],
            ['7-Zip', 'Igor Pavlov', false, null],
            ['Notion', 'Notion Labs', false, null],
            ['Zoom', 'Zoom Video', false, null],
        ];

        foreach ($this->people as $p) {
            if (! $p['active'] && $p['index'] % 2 === 0) {
                continue;
            }

            // Most people have one PC; a slice has two (laptop + desktop).
            $deviceCount = $p['index'] % 13 === 0 ? 2 : 1;

            for ($dvc = 0; $dvc < $deviceCount; $dvc++) {
                $machineId = $nextMachineId++;
                $deviceName = 'PC-'.str_pad((string) $p['index'], 3, '0', STR_PAD_LEFT).($dvc ? '-ALT' : '');
                $lastSeen = $this->now()->copy()->subMinutes(mt_rand(5, 60 * 24 * 3));
                // A slice of the fleet runs an agent too old to report
                // extensions — those machines must read as "unknown", not clean.
                $version = $p['index'] % 9 === 0 ? '0.4.4' : '0.4.6';

                $extCount = mt_rand(3, count($extensions));
                $progCount = mt_rand(6, count($programs));

                $machines[] = [
                    'id' => $machineId,
                    'organization_id' => $this->orgId,
                    'user_id' => $p['id'],
                    'device_name' => $deviceName,
                    'platform' => $p['index'] % 8 === 0 ? 'darwin' : 'win32',
                    'app_version' => $version,
                    'first_seen_at' => $this->now()->copy()->subDays(mt_rand(30, 300)),
                    'last_seen_at' => $lastSeen,
                    'collected_at' => $lastSeen,
                    'inventory_counts' => json_encode(['extensions' => $extCount, 'programs' => $progCount]),
                    'inventory_collected_at' => json_encode(['extensions' => $lastSeen->toIso8601String(), 'programs' => $lastSeen->toIso8601String()]),
                    'created_at' => $now, 'updated_at' => $now,
                ];

                foreach ([['extensions', $extensions, $extCount], ['programs', $programs, $progCount]] as [$kind, $pool, $take]) {
                    for ($i = 0; $i < $take; $i++) {
                        [$name, $publisher, $flagged, $rule] = $pool[($p['index'] + $i) % count($pool)];
                        $signature = $kind.'|'.strtolower($name).'|'.$dvc;
                        $present = mt_rand(1, 100) > 4;

                        $items[] = [
                            'organization_id' => $this->orgId,
                            'machine_id' => $machineId,
                            'user_id' => $p['id'],
                            'kind' => $kind,
                            'signature' => $signature,
                            'name' => $name,
                            'browser' => $kind === 'extensions' ? 'chrome' : null,
                            'browser_profile' => $kind === 'extensions' ? 'Default' : null,
                            'publisher' => $publisher,
                            'version' => '1.'.mt_rand(0, 30).'.'.mt_rand(0, 9),
                            'detail' => null,
                            'enabled' => $kind === 'extensions' ? (mt_rand(1, 100) > 15) : null,
                            'flagged' => $flagged,
                            'rule' => $rule,
                            'severity' => $flagged ? ($rule === 'tenant_vpn_client' ? 'medium' : 'critical') : null,
                            'alert' => $flagged && $rule !== 'tenant_vpn_client' && $rule !== 'tenant_refresh_ext',
                            'present' => $present,
                            'first_seen_at' => $this->now()->copy()->subDays(mt_rand(5, 200)),
                            'last_seen_at' => $lastSeen,
                            'removed_at' => $present ? null : $lastSeen,
                            'created_at' => $now, 'updated_at' => $now,
                        ];

                        if ($flagged && $present) {
                            $status = ['open', 'open', 'acknowledged', 'ignored', 'resolved'][mt_rand(0, 4)];
                            $flags[] = [
                                'organization_id' => $this->orgId,
                                'machine_id' => $machineId,
                                'user_id' => $p['id'],
                                'device_name' => $deviceName,
                                'kind' => $kind,
                                'rule' => $rule,
                                'severity' => $rule === 'tenant_vpn_client' ? 'medium' : 'critical',
                                'alert' => $rule !== 'tenant_vpn_client',
                                'label' => $name,
                                'browser_profile' => $kind === 'extensions' ? 'Default' : null,
                                'signature' => $signature,
                                'status' => $status,
                                'first_seen_at' => $this->now()->copy()->subDays(mt_rand(1, 120)),
                                'last_seen_at' => $lastSeen,
                                'alerted_at' => $lastSeen,
                                'resolved_at' => $status === 'resolved' ? $lastSeen : null,
                                'acknowledged_by' => in_array($status, ['acknowledged', 'ignored'], true) ? $this->people[0]['id'] : null,
                                'acknowledged_at' => in_array($status, ['acknowledged', 'ignored'], true) ? $lastSeen : null,
                                'note' => null,
                                'app_version' => $version,
                                'platform' => 'win32',
                                'created_at' => $now, 'updated_at' => $now,
                            ];
                        }
                    }
                }
            }
        }

        DB::transaction(function () use ($machines, $items, $flags) {
            $this->insertChunked('machines', $machines);
            $this->insertChunked('machine_inventory_items', $items);
            $this->insertChunked('machine_flags', $flags);
        });

        $this->count('machines', count($machines));
        $this->count('machine_inventory_items', count($items));
        $this->count('machine_flags', count($flags));
        $this->log(sprintf('Compliance: %s machines, %s inventory items, %s findings.',
            number_format(count($machines)), number_format(count($items)), number_format(count($flags))));
    }

    private function seedDiagnostics(): void
    {
        $rows = [];
        $events = [
            ['desktop', 'warn', 'capture.blocked', 'Screenshot capture returned an empty frame'],
            ['desktop', 'error', 'upload.failed', 'Screenshot upload failed after 3 retries'],
            ['desktop', 'info', 'app.resumed', 'Agent resumed after system sleep'],
            ['tracker-health', 'warn', 'health.probe.low', 'Capture probe below threshold during shift'],
            ['tracker-health', 'info', 'health.skipped', 'Alert skipped — user is on break'],
        ];

        foreach ($this->people as $p) {
            $howMany = $p['index'] % 3 === 0 ? mt_rand(1, 12) : mt_rand(0, 3);
            for ($i = 0; $i < $howMany; $i++) {
                [$category, $level, $event, $summary] = $events[mt_rand(0, count($events) - 1)];
                $at = $this->now()->copy()->subMinutes(mt_rand(10, 60 * 24 * 45));
                $rows[] = $this->stamp([
                    'user_id' => $p['id'],
                    'category' => $category,
                    'level' => $level,
                    'event' => $event,
                    'summary' => $summary,
                    'message' => $summary.' on PC-'.str_pad((string) $p['index'], 3, '0', STR_PAD_LEFT),
                    'context' => json_encode(['seeded' => true, 'index' => $p['index']]),
                    'device_name' => 'PC-'.str_pad((string) $p['index'], 3, '0', STR_PAD_LEFT),
                    'platform' => 'win32',
                    'app_version' => '0.4.6',
                    'client_at' => $at,
                    'captured_at' => $at,
                    'created_at' => $at, 'updated_at' => $at,
                ]);
            }
        }

        $this->insertChunked('desktop_diagnostics', $rows);
        $this->count('desktop_diagnostics', count($rows));
    }

    private function seedFeedback(): void
    {
        $items = [];
        $messages = [];
        $nextId = $this->nextId('feedback_items');
        $subjects = [
            ['question', 'How do I correct a missed clock-out?'],
            ['bug', 'Monthly grid shows a blank cell for the 31st'],
            ['feature_request', 'Bulk-approve timesheets for a whole team'],
            ['missing_doc', 'No help article on overnight shifts'],
            ['question', 'Can I export the work diary as CSV?'],
            ['bug', 'Screenshot thumbnails load slowly on the sessions list'],
            ['feature_request', 'Slack digest per team instead of per workspace'],
            ['question', 'Which plan includes machine compliance?'],
        ];

        for ($i = 0; $i < 32; $i++) {
            [$type, $subject] = $subjects[$i % count($subjects)];
            $person = $this->people[($i * 7) % count($this->people)];
            $status = ['new', 'new', 'in_review', 'planned', 'done', 'declined'][$i % 6];
            $at = $this->now()->copy()->subDays(mt_rand(0, 90));
            $id = $nextId++;

            $items[] = [
                'id' => $id,
                'organization_id' => $this->orgId,
                'user_id' => $person['id'],
                'type' => $type,
                'subject' => $subject.($i >= count($subjects) ? ' ('.$i.')' : ''),
                'message' => 'Seeded feedback body for load testing the inbox. '.$subject,
                'context' => json_encode(['url' => '/dashboard', 'seeded' => true]),
                'status' => $status,
                'handled_by' => $status === 'new' ? null : $this->people[0]['id'],
                'handled_at' => $status === 'new' ? null : $at->copy()->addDays(1),
                'response_seen_at' => null,
                'manager_seen_at' => $status === 'new' ? null : $at->copy()->addDays(1),
                'created_at' => $at, 'updated_at' => $at,
            ];

            $replies = $status === 'new' ? 0 : mt_rand(1, 3);
            for ($r = 0; $r < $replies; $r++) {
                $messages[] = [
                    'organization_id' => $this->orgId,
                    'feedback_item_id' => $id,
                    'user_id' => $r % 2 === 0 ? $this->people[0]['id'] : $person['id'],
                    'body' => 'Seeded reply #'.($r + 1).' on this thread.',
                    'created_at' => $at->copy()->addHours($r + 1),
                    'updated_at' => $at->copy()->addHours($r + 1),
                ];
            }
        }

        $this->insertChunked('feedback_items', $items);
        $this->insertChunked('feedback_messages', $messages);
        $this->count('feedback_items', count($items));
        $this->count('feedback_messages', count($messages));
    }

    private function seedBilling(): void
    {
        $subscriptionId = DB::table('subscriptions')->where('organization_id', $this->orgId)->value('id');
        $rows = [];

        for ($i = 12; $i >= 1; $i--) {
            $paidAt = $this->now()->copy()->subMonths($i)->startOfMonth()->addDays(2);
            $rows[] = [
                'organization_id' => $this->orgId,
                'subscription_id' => $subscriptionId,
                'amount_cents' => $this->userCount * 700,
                'currency' => 'USD',
                'method' => $i % 4 === 0 ? 'card' : 'bank_transfer',
                'reference' => 'SEED-INV-'.$paidAt->format('Ym'),
                'status' => $i === 1 ? 'pending' : 'confirmed',
                'paid_at' => $i === 1 ? null : $paidAt,
                'period_start' => $paidAt->copy()->startOfMonth(),
                'period_end' => $paidAt->copy()->endOfMonth(),
                'recorded_by' => null,
                'notes' => null,
                'kind' => 'subscription',
                'addon_id' => null,
                'billing_cycle' => 'monthly',
                'seats' => $this->userCount,
                'unit_price_cents' => 700,
                'description' => 'Monthly subscription',
                'requested_by' => null,
                'created_at' => $paidAt, 'updated_at' => $paidAt,
            ];
        }

        $this->insertChunked('payments', $rows);
        $this->count('payments', count($rows));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Plumbing
    // ─────────────────────────────────────────────────────────────────────────

    private function historyDays(): int
    {
        return (int) $this->today()->copy()->subMonths($this->months)->diffInDays($this->today());
    }

    private function today(): Carbon
    {
        return Carbon::today($this->tz);
    }

    private function now(): Carbon
    {
        return Carbon::now($this->tz);
    }

    /** Business-timezone instant → the app's STORAGE timezone, as a string. */
    private function store(Carbon $at): string
    {
        return $at->copy()->setTimezone(BusinessTime::storageTz())->format('Y-m-d H:i:s');
    }

    /**
     * Stamp `organization_id` explicitly. `insert()` bypasses the
     * BelongsToOrganization `creating` hook, which is exactly how bulk-seeded
     * rows end up invisible to the tenant that owns them.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function stamp(array $row): array
    {
        return ['organization_id' => $this->orgId] + $row;
    }

    /** The next free autoincrement id, so children can reference parents. */
    private function nextId(string $table): int
    {
        return ((int) DB::table($table)->max('id')) + 1;
    }

    /**
     * Chunked bulk insert sized to the driver's placeholder ceiling. SQLite
     * >= 3.32 allows 32766 bound parameters, older builds 999; MySQL allows
     * 65535. Getting this wrong is a hard "too many SQL variables" failure at
     * exactly the scale the seeder exists to reach.
     */
    private function insertChunked(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $columns = max(1, count(reset($rows)));
        $chunkSize = max(1, (int) floor($this->maxBinds / $columns));
        $total = count($rows);

        // array_slice one window at a time, NOT array_chunk: array_chunk
        // materialises a full second copy of the buffer, and at six-figure row
        // counts that copy alone is what exhausts PHP's memory limit.
        for ($offset = 0; $offset < $total; $offset += $chunkSize) {
            $chunk = array_slice($rows, $offset, $chunkSize);
            DB::table($table)->insert($chunk);
            unset($chunk);
        }
    }

    private function bindCeiling(): int
    {
        $driver = DB::connection()->getDriverName();

        if ($driver !== 'sqlite') {
            return 50_000;
        }

        try {
            $version = (string) DB::selectOne('select sqlite_version() as v')->v;

            // 8k rather than SQLite's full 32766 ceiling: a wider statement is
            // not measurably faster here and each one costs proportional memory
            // in the driver, which matters when the buffer is already large.
            return version_compare($version, '3.32.0', '>=') ? 8_000 : 900;
        } catch (\Throwable $e) {
            return 900;
        }
    }

    private function count(string $table, int $rows): void
    {
        $this->counts[$table] = ($this->counts[$table] ?? 0) + $rows;
    }

    private function log(string $message): void
    {
        if ($this->logger) {
            ($this->logger)($message);
        }
    }
}
