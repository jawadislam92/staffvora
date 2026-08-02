<?php

namespace Tests\Feature;

use App\Http\Controllers\ComplianceController;
use App\Models\Machine;
use App\Models\MachineFlag;
use App\Models\MachineInventoryItem;
use App\Models\Organization;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Regression cover for the Machine Compliance DASHBOARD.
 *
 * The ingest's tests (MachineComplianceIngestTest) pin the write path. These
 * pin the read/act path, and specifically the four ways this page could betray
 * the people relying on it:
 *
 *  1. GATING — access is decided ONLY by the `machine_compliance` entitlement
 *     key. Never by plan name, never by workspace_type. If a second gate ever
 *     creeps back in, the "operator can grant this to any company" test fails.
 *  2. TENANCY — one customer's fleet must be invisible to another, and a
 *     mutation aimed at a foreign machine or finding must 404, not act.
 *  3. FORGED BULK ACTIONS — the Acknowledge button sends an id list. A crafted
 *     list must never reach a finding on another machine.
 *  4. "UNKNOWN IS NOT CLEAN" — a machine whose agent cannot enumerate browser
 *     extensions must never be presented as clean. That mistake is the one that
 *     gets an Upwork profile banned while the dashboard shows all green.
 */
class ComplianceDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The root Blade template resolves the page component through the Vite
        // manifest, which only lists pages that have been built. A brand-new page
        // would 500 here for a reason that has nothing to do with what is under
        // test — the manifest, not the code.
        $this->withoutVite();

        $this->registerComplianceRoutes();
        $this->registerCompliancePermissions();
    }

    /**
     * The web routes for this feature, registered here ONLY while they are
     * absent from routes/web.php (that file belongs to another agent in this
     * port). The definitions are byte-for-byte the ones returned for
     * registration, so these tests exercise the real middleware stack —
     * `entitlement:machine_compliance` plus the permission gate — and keep
     * passing unchanged the moment the real lines land.
     */
    private function registerComplianceRoutes(): void
    {
        if (Route::has('monitoring.compliance')) {
            return;
        }

        $existing = Route::getRoutes()->getRoutes();
        $before = count($existing);

        Route::middleware(['web', 'auth', 'verified'])->group(function () {
            Route::prefix('compliance')->middleware('entitlement:machine_compliance')->group(function () {
                Route::get('/', [ComplianceController::class, 'index'])
                    ->middleware('permission:compliance.view')
                    ->name('monitoring.compliance');

                Route::patch('/flags/{flag}', [ComplianceController::class, 'updateFlag'])
                    ->middleware('permission:compliance.manage')
                    ->whereNumber('flag')
                    ->name('monitoring.compliance.flag');

                Route::delete('/machines/{machine}', [ComplianceController::class, 'destroyMachine'])
                    ->middleware('permission:compliance.manage')
                    ->whereNumber('machine')
                    ->name('monitoring.compliance.machine.destroy');
            });
        });

        // The router appends, so everything past the original count is ours.
        $added = array_slice(Route::getRoutes()->getRoutes(), $before);

        // ⚠ ORDER MATTERS, AND NOT ONLY IN THIS TEST.
        // routes/web.php ends with the marketing catch-all `Route::get('/{slug}')`,
        // which swallows ANY GET registered after it — the compliance page 404s
        // with no other symptom. The real lines therefore have to live inside the
        // existing `Route::middleware(['auth', 'verified'])` group, well above the
        // catch-all. Appending them at runtime here puts them below it, so the
        // collection is rebuilt with them in front to reproduce the real ordering.
        $collection = new RouteCollection;

        foreach ($added as $route) {
            $collection->add($route);
        }

        foreach ($existing as $route) {
            $collection->add($route);
        }

        // A route's ->name() is applied AFTER the collection has indexed it, so
        // the name lookup has to be rebuilt by hand. In a normal boot the
        // framework's RouteServiceProvider does this from an `app.booted`
        // callback that has already fired by the time a test runs.
        $collection->refreshNameLookups();
        $collection->refreshActionLookups();

        Route::setRoutes($collection);
    }

    /**
     * The permission catalogue entries this feature needs. config/access.php
     * belongs to another agent too, and PermissionMiddleware silently drops any
     * key that is not in the catalogue — so a member granted `compliance.view`
     * would 403 forever if these were missing.
     */
    private function registerCompliancePermissions(): void
    {
        $permissions = config('access.permissions');

        if (isset($permissions['Machine Compliance'])) {
            return;
        }

        $permissions['Machine Compliance'] = [
            'compliance.view' => [
                'label' => 'View machine compliance',
                'description' => 'Opens the Machine Compliance dashboard.',
            ],
            'compliance.manage' => [
                'label' => 'Acknowledge findings & remove machines',
                'description' => 'Can acknowledge or ignore a finding and delete a decommissioned machine.',
            ],
        ];

        config()->set('access.permissions', $permissions);
    }

    /** Provision a real tenant and (optionally) grant the compliance key. */
    private function tenant(string $name, string $email, bool $entitled = true): User
    {
        $owner = app(TenantProvisioner::class)->provision($name, 'Owner', $email, 'secret-pass-123')['owner'];

        Organization::withoutGlobalScopes()->whereKey($owner->organization_id)->update([
            'entitlement_overrides' => ['machine_compliance' => $entitled],
        ]);

        // Web routes sit behind `verified`; the provisioner does not verify.
        User::withoutGlobalScopes()->whereKey($owner->id)->update(['email_verified_at' => now()]);

        app(CurrentOrganization::class)->set($owner->organization_id);

        return $owner->fresh();
    }

    /** Bind the tenant and act as the user, with a clean auth guard. */
    private function asTenant(User $user): self
    {
        $this->app['auth']->forgetGuards();
        app(CurrentOrganization::class)->set($user->organization_id);
        $this->actingAs($user);

        return $this;
    }

    /**
     * Build one machine with an optional finding and inventory item, stamping
     * organization_id explicitly (the fixtures must not depend on whichever
     * tenant happens to be bound at the time).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function machineFor(User $owner, array $attributes = []): Machine
    {
        return Machine::create(array_merge([
            'organization_id' => $owner->organization_id,
            'user_id' => $owner->id,
            'device_name' => 'PC-1',
            'platform' => 'win32',
            'app_version' => '0.5.0',
            'first_seen_at' => now()->subMonth(),
            'last_seen_at' => now(),
            'inventory_counts' => ['extensions' => 12, 'programs' => 40],
            'inventory_collected_at' => ['extensions' => now()->toDateTimeString()],
        ], $attributes));
    }

    /** @param  array<string, mixed>  $attributes */
    private function flagOn(Machine $machine, array $attributes = []): MachineFlag
    {
        return MachineFlag::create(array_merge([
            'organization_id' => $machine->organization_id,
            'machine_id' => $machine->id,
            'user_id' => $machine->user_id,
            'device_name' => $machine->device_name,
            'kind' => 'extensions',
            'rule' => 'scraper',
            'severity' => 'critical',
            'alert' => true,
            'label' => 'Instant Data Scraper',
            'browser_profile' => 'Default',
            'signature' => md5(uniqid('', true)),
            'status' => MachineFlag::STATUS_OPEN,
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
            'app_version' => '0.5.0',
        ], $attributes));
    }

    /** @param  array<string, mixed>  $attributes */
    private function itemOn(Machine $machine, array $attributes = []): MachineInventoryItem
    {
        return MachineInventoryItem::create(array_merge([
            'organization_id' => $machine->organization_id,
            'machine_id' => $machine->id,
            'user_id' => $machine->user_id,
            'kind' => 'extensions',
            'signature' => md5(uniqid('', true)),
            'name' => 'Grammarly',
            'browser' => 'Chrome',
            'browser_profile' => 'Default',
            'present' => true,
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
        ], $attributes));
    }

    // ------------------------------------------------------------------ gating

    public function test_the_dashboard_is_gated_only_by_the_machine_compliance_entitlement(): void
    {
        $denied = $this->tenant('No Compliance Co', 'no@compliance.test', entitled: false);

        $this->asTenant($denied)
            ->get(route('monitoring.compliance'))
            // EnsureEntitlement refuses and answers with the Upgrade/Locked page.
            ->assertForbidden();

        // The SAME company, same plan, same workspace_type — only the entitlement
        // override changes. If access ever keys off anything else, this fails.
        Organization::withoutGlobalScopes()->whereKey($denied->organization_id)->update([
            'entitlement_overrides' => ['machine_compliance' => true],
        ]);

        $this->asTenant($denied->fresh())
            ->get(route('monitoring.compliance'))
            ->assertOk();
    }

    public function test_a_member_without_the_view_permission_is_refused(): void
    {
        $owner = $this->tenant('Perm Co', 'perm@compliance.test');

        $member = User::factory()->create([
            'organization_id' => $owner->organization_id,
            'role' => 'member',
            'permissions' => [],
        ]);

        $this->asTenant($member)
            ->get(route('monitoring.compliance'))
            ->assertForbidden();

        $member->forceFill(['permissions' => ['compliance.view']])->save();

        $this->asTenant($member->fresh())
            ->get(route('monitoring.compliance'))
            ->assertOk();
    }

    public function test_a_viewer_without_manage_cannot_acknowledge_or_remove(): void
    {
        $owner = $this->tenant('ReadOnly Co', 'readonly@compliance.test');
        $machine = $this->machineFor($owner);
        $flag = $this->flagOn($machine);

        $viewer = User::factory()->create([
            'organization_id' => $owner->organization_id,
            'role' => 'member',
            'permissions' => ['compliance.view'],
        ]);

        $this->asTenant($viewer)
            ->patch(route('monitoring.compliance.flag', $flag), ['status' => 'acknowledged'])
            ->assertForbidden();

        $this->asTenant($viewer)
            ->delete(route('monitoring.compliance.machine.destroy', $machine))
            ->assertForbidden();

        $this->assertSame(MachineFlag::STATUS_OPEN, $flag->fresh()->status);
        $this->assertNotNull(Machine::withoutGlobalScopes()->find($machine->id));
    }

    // ----------------------------------------------------------------- tenancy

    public function test_one_company_never_sees_another_companys_fleet(): void
    {
        $a = $this->tenant('Alpha Agency', 'alpha@compliance.test');
        $machineA = $this->machineFor($a, ['device_name' => 'ALPHA-PC']);
        $this->flagOn($machineA, ['label' => 'Alpha Only Scraper']);

        $b = $this->tenant('Beta Agency', 'beta@compliance.test');
        $machineB = $this->machineFor($b, ['device_name' => 'BETA-PC']);
        $this->flagOn($machineB, ['label' => 'Beta Only Scraper']);

        $response = $this->asTenant($b)->get(route('monitoring.compliance', ['view' => 'machines']));
        $response->assertOk();

        $devices = collect($response->viewData('page')['props']['rows']['data'])->pluck('device');
        $this->assertTrue($devices->contains('BETA-PC'));
        $this->assertFalse($devices->contains('ALPHA-PC'));

        $summary = $response->viewData('page')['props']['summary'];
        $this->assertSame(1, $summary['machines']);
        $this->assertSame(1, $summary['open_alerts']);
    }

    public function test_a_foreign_finding_or_machine_cannot_be_touched(): void
    {
        $a = $this->tenant('Victim Co', 'victim@compliance.test');
        $machineA = $this->machineFor($a);
        $flagA = $this->flagOn($machineA);

        $b = $this->tenant('Attacker Co', 'attacker@compliance.test');

        // The route binding is tenant-scoped, so a foreign id is simply not found.
        $this->asTenant($b)
            ->patch(route('monitoring.compliance.flag', $flagA), ['status' => 'ignored'])
            ->assertNotFound();

        $this->asTenant($b)
            ->delete(route('monitoring.compliance.machine.destroy', $machineA))
            ->assertNotFound();

        $this->assertSame(MachineFlag::STATUS_OPEN, MachineFlag::withoutGlobalScopes()->find($flagA->id)->status);
        $this->assertNotNull(Machine::withoutGlobalScopes()->find($machineA->id));
    }

    // ------------------------------------------------------- acknowledge / bulk

    public function test_acknowledging_records_the_actor_and_keeps_the_finding_visible(): void
    {
        $owner = $this->tenant('Ack Co', 'ack@compliance.test');
        $flag = $this->flagOn($this->machineFor($owner));

        $this->asTenant($owner)
            ->patch(route('monitoring.compliance.flag', $flag), ['status' => 'acknowledged'])
            ->assertRedirect();

        $flag->refresh();
        $this->assertSame(MachineFlag::STATUS_ACKNOWLEDGED, $flag->status);
        $this->assertSame($owner->id, $flag->acknowledged_by);
        $this->assertNotNull($flag->acknowledged_at);

        // Reopening clears the human decision as well as the resolution, so the
        // row can never read "acknowledged by X" while sitting open.
        $this->asTenant($owner)
            ->patch(route('monitoring.compliance.flag', $flag), ['status' => 'open'])
            ->assertRedirect();

        $flag->refresh();
        $this->assertSame(MachineFlag::STATUS_OPEN, $flag->status);
        $this->assertNull($flag->acknowledged_by);
        $this->assertNull($flag->acknowledged_at);
    }

    public function test_bulk_acknowledge_clears_every_browser_profile_of_the_same_tool(): void
    {
        $owner = $this->tenant('Bulk Co', 'bulk@compliance.test');
        $machine = $this->machineFor($owner);

        $one = $this->flagOn($machine, ['browser_profile' => 'Upwork — Faryal']);
        $two = $this->flagOn($machine, ['browser_profile' => 'Default']);

        $this->asTenant($owner)
            ->patch(route('monitoring.compliance.flag', $one), [
                'status' => 'acknowledged',
                'ids' => [$one->id, $two->id],
            ])
            ->assertRedirect();

        $this->assertSame(MachineFlag::STATUS_ACKNOWLEDGED, $one->fresh()->status);
        $this->assertSame(MachineFlag::STATUS_ACKNOWLEDGED, $two->fresh()->status);
    }

    /**
     * THE SECURITY-CRITICAL ONE. `ids` is attacker-supplied: the page sends it so
     * one click can clear a tool across every Chrome profile it sits in. It must
     * be re-scoped server-side to the SAME machine and the SAME rule as the flag
     * named in the URL, or a manager (or a crafted request) could silence
     * findings on a machine they never opened.
     */
    public function test_a_crafted_bulk_id_list_cannot_reach_another_machine_or_another_tool(): void
    {
        $owner = $this->tenant('Scope Co', 'scope@compliance.test');

        $mine = $this->machineFor($owner, ['device_name' => 'MY-PC']);
        $target = $this->flagOn($mine, ['browser_profile' => 'Default']);

        // Same tenant, DIFFERENT machine — must not be touched.
        $other = $this->machineFor($owner, ['device_name' => 'OTHER-PC']);
        $otherMachineFlag = $this->flagOn($other);

        // Same machine, DIFFERENT tool — must not be touched either.
        $otherTool = $this->flagOn($mine, [
            'rule' => 'vpn_proxy',
            'label' => 'Windscribe',
            'alert' => false,
            'severity' => 'high',
        ]);

        $this->asTenant($owner)
            ->patch(route('monitoring.compliance.flag', $target), [
                'status' => 'ignored',
                'ids' => [$target->id, $otherMachineFlag->id, $otherTool->id],
            ])
            ->assertRedirect();

        $this->assertSame(MachineFlag::STATUS_IGNORED, $target->fresh()->status);
        $this->assertSame(MachineFlag::STATUS_OPEN, $otherMachineFlag->fresh()->status);
        $this->assertSame(MachineFlag::STATUS_OPEN, $otherTool->fresh()->status);
    }

    public function test_the_status_value_is_whitelisted(): void
    {
        $owner = $this->tenant('Status Co', 'status@compliance.test');
        $flag = $this->flagOn($this->machineFor($owner));

        // `resolved` is set by the ingest when a tool disappears; a human must
        // not be able to mark a finding resolved while the tool is still there.
        $this->asTenant($owner)
            ->patch(route('monitoring.compliance.flag', $flag), ['status' => 'resolved'])
            ->assertSessionHasErrors('status');

        $this->assertSame(MachineFlag::STATUS_OPEN, $flag->fresh()->status);
    }

    // --------------------------------------------------------- remove a machine

    public function test_removing_a_machine_deletes_its_evidence_and_nothing_elses(): void
    {
        $owner = $this->tenant('Decom Co', 'decom@compliance.test');

        $doomed = $this->machineFor($owner, ['device_name' => 'OLD-PC']);
        $this->flagOn($doomed);
        $this->itemOn($doomed);

        $keeper = $this->machineFor($owner, ['device_name' => 'LIVE-PC']);
        $keeperFlag = $this->flagOn($keeper);
        $keeperItem = $this->itemOn($keeper);

        $this->asTenant($owner)
            ->delete(route('monitoring.compliance.machine.destroy', $doomed))
            ->assertRedirect();

        $this->assertNull(Machine::withoutGlobalScopes()->find($doomed->id));
        $this->assertSame(0, MachineFlag::withoutGlobalScopes()->where('machine_id', $doomed->id)->count());
        $this->assertSame(0, MachineInventoryItem::withoutGlobalScopes()->where('machine_id', $doomed->id)->count());

        $this->assertNotNull(Machine::withoutGlobalScopes()->find($keeper->id));
        $this->assertNotNull(MachineFlag::withoutGlobalScopes()->find($keeperFlag->id));
        $this->assertNotNull(MachineInventoryItem::withoutGlobalScopes()->find($keeperItem->id));
    }

    // ------------------------------------------------ unknown is never "clean"

    public function test_a_machine_on_a_too_old_agent_reads_unknown_not_clean(): void
    {
        $owner = $this->tenant('Stale Co', 'stale@compliance.test');

        // No findings at all — the naive read is "clean". But this agent predates
        // extension reporting, so its empty inventory means "we cannot see".
        $this->machineFor($owner, [
            'device_name' => 'ANCIENT-PC',
            'app_version' => '0.4.0',
            'inventory_counts' => ['programs' => 40],
            'inventory_collected_at' => ['programs' => now()->toDateTimeString()],
        ]);

        $response = $this->asTenant($owner)->get(route('monitoring.compliance', ['view' => 'machines']));
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $row = collect($props['rows']['data'])->firstWhere('device', 'ANCIENT-PC');

        $this->assertSame('unknown', $row['state']);
        $this->assertNotSame('clean', $row['state']);
        $this->assertTrue($row['stale_agent']);
        $this->assertTrue($row['never_reported_extensions']);

        // And it is counted where a manager will see it, not hidden.
        $this->assertSame(1, $props['summary']['stale_agents']);
    }

    public function test_a_current_agent_with_no_findings_reads_clean(): void
    {
        $owner = $this->tenant('Clean Co', 'clean@compliance.test');
        $this->machineFor($owner, ['device_name' => 'GOOD-PC']);

        $response = $this->asTenant($owner)->get(route('monitoring.compliance', ['view' => 'machines']));

        $row = collect($response->viewData('page')['props']['rows']['data'])->firstWhere('device', 'GOOD-PC');
        $this->assertSame('clean', $row['state']);
        $this->assertSame(0, $response->viewData('page')['props']['summary']['stale_agents']);
    }

    public function test_a_watch_only_finding_never_reads_as_an_alert(): void
    {
        $owner = $this->tenant('Watch Co', 'watch@compliance.test');
        $machine = $this->machineFor($owner, ['device_name' => 'VPN-PC']);

        // VPN findings are observational by owner policy: recorded and shown,
        // never announced and never counted as an alert.
        $this->flagOn($machine, [
            'rule' => 'vpn_proxy',
            'label' => 'Windscribe',
            'severity' => 'high',
            'alert' => false,
            'kind' => 'network',
            'browser_profile' => null,
        ]);

        $machines = $this->asTenant($owner)->get(route('monitoring.compliance', ['view' => 'machines']));
        $row = collect($machines->viewData('page')['props']['rows']['data'])->firstWhere('device', 'VPN-PC');

        $this->assertSame('watch', $row['state']);
        $this->assertSame(0, $row['open_alerts']);
        $this->assertSame(1, $row['watch']);
        $this->assertSame(0, $machines->viewData('page')['props']['summary']['open_alerts']);
        $this->assertSame(1, $machines->viewData('page')['props']['summary']['watch']);

        $findings = $this->asTenant($owner)->get(route('monitoring.compliance'));
        $tool = collect($findings->viewData('page')['props']['rows']['data'])->firstWhere('label', 'Windscribe');

        $this->assertSame('watch', $tool['category']);
        $this->assertFalse($tool['alerting']);
    }

    // ---------------------------------------------------- aggregation + bounds

    public function test_findings_are_grouped_by_tool_with_spread_counted_in_sql(): void
    {
        $owner = $this->tenant('Spread Co', 'spread@compliance.test');

        $colleague = User::factory()->create([
            'organization_id' => $owner->organization_id,
            'role' => 'member',
        ]);

        $one = $this->machineFor($owner, ['device_name' => 'PC-A']);
        $two = $this->machineFor($owner, ['device_name' => 'PC-B', 'user_id' => $colleague->id]);

        // The same tool, on two machines, in three browser profiles.
        $this->flagOn($one, ['browser_profile' => 'Default']);
        $this->flagOn($one, ['browser_profile' => 'Upwork — Faryal']);
        $this->flagOn($two, ['browser_profile' => 'Default', 'user_id' => $colleague->id]);

        $response = $this->asTenant($owner)->get(route('monitoring.compliance'));
        $rows = $response->viewData('page')['props']['rows']['data'];

        $this->assertCount(1, $rows, 'One tool must collapse to ONE row, however many profiles it sits in.');

        $tool = $rows[0];
        $this->assertSame('Instant Data Scraper', $tool['label']);
        $this->assertSame(3, $tool['findings']);
        $this->assertSame(2, $tool['machines']);
        $this->assertSame(2, $tool['people']);
        $this->assertSame('ban', $tool['category']);

        // Occurrences collapse to ONE row per machine, with every affected
        // profile listed on it — the fix for the unreadable per-profile list.
        $this->assertCount(2, $tool['occurrences']);
        $pcA = collect($tool['occurrences'])->firstWhere('device', 'PC-A');
        $this->assertEqualsCanonicalizing(['Default', 'Upwork — Faryal'], $pcA['profiles']);
        $this->assertCount(2, $pcA['ids'], 'Both flags must be behind one Acknowledge click.');
    }

    public function test_the_by_employee_view_counts_programs_as_well_as_extensions(): void
    {
        $owner = $this->tenant('Employee Co', 'employee@compliance.test');
        $machine = $this->machineFor($owner);

        $this->itemOn($machine, ['kind' => 'extensions', 'name' => 'Grammarly']);
        $this->itemOn($machine, ['kind' => 'extensions', 'name' => 'uBlock Origin']);
        // A mouse jiggler is an installed PROGRAM. An extensions-only view hid
        // exactly this class of tool, which is why the source shipped a fix
        // titled "By-employee now covers programs".
        $this->itemOn($machine, [
            'kind' => 'programs',
            'name' => 'TinyTask',
            'browser' => null,
            'browser_profile' => null,
            'flagged' => true,
            'alert' => true,
            'rule' => 'jiggler_autoclicker',
            'severity' => 'critical',
        ]);

        $response = $this->asTenant($owner)->get(route('monitoring.compliance', ['view' => 'employees']));
        $row = collect($response->viewData('page')['props']['rows']['data'])->firstWhere('user_id', $owner->id);

        $this->assertSame(3, $row['items']);
        $this->assertSame(2, $row['extensions']);
        $this->assertSame(1, $row['programs']);
        $this->assertSame(1, $row['flagged']);
        $this->assertSame(1, $row['alerting']);

        // Drilling in returns both kinds, still bounded and paginated.
        $drill = $this->asTenant($owner)->get(route('monitoring.compliance', [
            'view' => 'employees',
            'employee' => $owner->id,
        ]));

        $props = $drill->viewData('page')['props'];
        $this->assertSame('items', $props['rows']['mode']);
        $this->assertEqualsCanonicalizing(
            ['Grammarly', 'uBlock Origin', 'TinyTask'],
            collect($props['rows']['data'])->pluck('name')->all(),
        );
    }

    public function test_the_by_extension_view_groups_by_name_and_drills_into_holders(): void
    {
        $owner = $this->tenant('Ext Co', 'ext@compliance.test');

        $colleague = User::factory()->create([
            'organization_id' => $owner->organization_id,
            'role' => 'member',
        ]);

        $one = $this->machineFor($owner, ['device_name' => 'PC-A']);
        $two = $this->machineFor($owner, ['device_name' => 'PC-B', 'user_id' => $colleague->id]);

        // Same extension, two people, two machines, three profiles — and the
        // casing differs, which must not split it into separate rows.
        $this->itemOn($one, ['name' => 'Instant Data Scraper', 'browser_profile' => 'Default', 'flagged' => true, 'alert' => true, 'rule' => 'scraper', 'severity' => 'critical']);
        $this->itemOn($one, ['name' => 'instant data scraper', 'browser_profile' => 'Upwork — Faryal', 'flagged' => true, 'alert' => true, 'rule' => 'scraper', 'severity' => 'critical']);
        $this->itemOn($two, ['name' => 'Instant Data Scraper', 'browser_profile' => 'Work', 'user_id' => $colleague->id, 'flagged' => true, 'alert' => true, 'rule' => 'scraper', 'severity' => 'critical']);

        $response = $this->asTenant($owner)->get(route('monitoring.compliance', ['view' => 'extensions']));
        $rows = $response->viewData('page')['props']['rows']['data'];

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['people']);
        $this->assertSame(2, $rows[0]['machines']);
        $this->assertSame(3, $rows[0]['profiles']);
        $this->assertTrue($rows[0]['flagged']);

        $drill = $this->asTenant($owner)->get(route('monitoring.compliance', [
            'view' => 'extensions',
            'extension' => $rows[0]['key'],
        ]));

        $props = $drill->viewData('page')['props'];
        $this->assertSame('holders', $props['rows']['mode']);
        $this->assertCount(3, $props['rows']['data']);
    }

    /**
     * Every list is paginated. The dashboard must never send a tenant's whole
     * inventory down the wire, whatever the fleet size.
     */
    public function test_every_list_is_bounded_by_pagination(): void
    {
        $owner = $this->tenant('Big Co', 'big@compliance.test');

        for ($i = 0; $i < 30; $i++) {
            $this->machineFor($owner, ['device_name' => 'PC-'.$i]);
        }

        $response = $this->asTenant($owner)->get(route('monitoring.compliance', ['view' => 'machines']));
        $rows = $response->viewData('page')['props']['rows'];

        $this->assertSame(30, $rows['meta']['total']);
        $this->assertLessThanOrEqual($rows['meta']['per_page'], count($rows['data']));
        $this->assertGreaterThan(1, $rows['meta']['last_page']);

        $second = $this->asTenant($owner)->get(route('monitoring.compliance', ['view' => 'machines', 'page' => 2]));
        $this->assertSame(2, $second->viewData('page')['props']['rows']['meta']['current_page']);
    }

    /**
     * Search terms are attacker-influenced. A wildcard-only term must be treated
     * as literal text (not "match everything"), and a hostile string must move
     * through the query and back out as plain data — never as markup, and never
     * as SQL.
     */
    public function test_hostile_search_terms_and_item_names_are_handled_as_data(): void
    {
        $owner = $this->tenant('Hostile Co', 'hostile@compliance.test');
        $machine = $this->machineFor($owner, ['device_name' => 'PC-A']);

        $this->itemOn($machine, ['name' => 'Grammarly']);
        $this->itemOn($machine, ['name' => "100% Free' OR 1=1 -- tool"]);

        // A LIKE wildcard is escaped, so "%" matches only a literal percent sign.
        $wild = $this->asTenant($owner)->get(route('monitoring.compliance', [
            'view' => 'extensions',
            'q' => '%',
        ]));
        $wild->assertOk();
        $names = collect($wild->viewData('page')['props']['rows']['data'])->pluck('name');
        $this->assertFalse($names->contains('Grammarly'), 'A bare % must not behave as "match everything".');
        $this->assertCount(1, $names);

        // The hostile name itself survives as text and is never escaped into
        // markup on the server — React escapes it on render.
        $hit = $this->asTenant($owner)->get(route('monitoring.compliance', [
            'view' => 'extensions',
            'q' => "OR 1=1",
        ]));
        $hit->assertOk();
        $this->assertSame(
            "100% Free' OR 1=1 -- tool",
            collect($hit->viewData('page')['props']['rows']['data'])->pluck('name')->first(),
        );

        // An unknown view or filter falls back to the whitelist rather than
        // reaching a column comparison.
        $bogus = $this->asTenant($owner)->get(route('monitoring.compliance', [
            'view' => 'not-a-view',
            'status' => 'not-a-status',
            'severity' => 'not-a-severity',
        ]));
        $bogus->assertOk();

        $props = $bogus->viewData('page')['props'];
        $this->assertSame('findings', $props['view']);
        $this->assertSame('live', $props['filters']['status']);
        $this->assertSame('all', $props['filters']['severity']);
    }

    public function test_the_empty_fleet_renders_the_compliance_page_with_zeroed_summary(): void
    {
        $owner = $this->tenant('Fresh Co', 'fresh@compliance.test');

        $response = $this->asTenant($owner)->get(route('monitoring.compliance', ['view' => 'machines']));
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertSame('Compliance/Index', $response->viewData('page')['component']);
        $this->assertSame([], $props['rows']['data']);
        $this->assertSame(0, $props['summary']['machines']);
        $this->assertSame(0, $props['summary']['open_alerts']);
        $this->assertNull($props['summary']['last_report']);
        // The page tells the operator which agent version it needs, so an empty
        // dashboard is never mistaken for a clean one.
        $this->assertSame(Machine::minAgentVersion(), $props['options']['min_agent']);
    }
}
