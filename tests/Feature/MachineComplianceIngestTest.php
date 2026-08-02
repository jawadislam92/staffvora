<?php

namespace Tests\Feature;

use App\Models\Machine;
use App\Models\MachineBlocklistRule;
use App\Models\MachineFlag;
use App\Models\MachineInventoryItem;
use App\Models\Organization;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Regression cover for the Machine Compliance ingest.
 *
 * This endpoint is the most attacker-exposed surface in the product: the desktop
 * agent runs on the employee's own machine, and the people being monitored are
 * exactly the people with a motive to tamper with it. These tests pin the
 * server-side controls that stop a tampered agent forging another user's
 * machine, wiping evidence, poisoning the dashboard, or flooding the tables —
 * plus the hard-won behaviours carried over from the single-tenant source
 * (the `profile` field that a firewall 403'd, the extension-dropping filter,
 * watch-only VPNs, and "empty inventory is unknown, not clean").
 */
class MachineComplianceIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // No outbound Slack from an ingest test, whatever the config says.
        Http::fake();
        RateLimiter::clear('');
    }

    /** Provision a real tenant, grant the compliance entitlement, bind it. */
    private function tenantOwner(string $org, string $email, bool $entitled = true): User
    {
        $owner = app(TenantProvisioner::class)->provision($org, 'Owner', $email, 'secret-pass-123')['owner'];

        Organization::withoutGlobalScopes()->whereKey($owner->organization_id)->update([
            'entitlement_overrides' => ['machine_compliance' => $entitled],
        ]);

        app(CurrentOrganization::class)->set($owner->organization_id);

        return $owner->fresh();
    }

    /**
     * Headers for one agent request.
     *
     * forgetGuards() is essential here, not cosmetic: Laravel's auth manager is
     * a container singleton and RequestGuard memoises the resolved user, so a
     * second HTTP call inside one test would otherwise reuse the FIRST call's
     * User model — stale relations and, when the test switches tenants, the
     * wrong user entirely. Production gets a fresh container per request.
     */
    private function tokenHeaders(User $user): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$user->createToken('PC-1', ['desktop-tracker'])->plainTextToken];
    }

    /** @param  array<int, array<string, mixed>>  $extensions */
    private function payload(array $extensions, array $extra = []): array
    {
        return array_merge([
            'device_name' => 'PC-VA-7',
            'app_version' => '0.5.0',
            'platform' => 'win32',
            'reports' => [
                ['kind' => 'extensions', 'collected_at' => now()->toIso8601String(), 'items' => $extensions],
            ],
        ], $extra);
    }

    // ------------------------------------------------------------- gating

    public function test_access_is_decided_only_by_the_machine_compliance_entitlement(): void
    {
        $blocked = $this->tenantOwner('No Compliance Co', 'no@compliance.test', entitled: false);

        $this->withHeaders($this->tokenHeaders($blocked))
            ->postJson('/api/desktop/machine-report', $this->payload([['name' => 'uBlock Origin']]))
            ->assertStatus(403)
            ->assertJsonPath('code', 'feature_not_in_plan')
            ->assertJsonPath('feature', 'machine_compliance');

        $this->assertSame(0, Machine::withoutGlobalScopes()->count());

        // The SAME plan, with the operator granting only this key, now passes —
        // proving nothing branches on plan name or workspace_type.
        Organization::withoutGlobalScopes()->whereKey($blocked->organization_id)
            ->update(['entitlement_overrides' => ['machine_compliance' => true]]);

        $this->withHeaders($this->tokenHeaders($blocked->fresh()))
            ->postJson('/api/desktop/machine-report', $this->payload([['name' => 'uBlock Origin']]))
            ->assertOk();
    }

    public function test_an_unauthenticated_agent_is_rejected(): void
    {
        $this->postJson('/api/desktop/machine-report', $this->payload([['name' => 'x']]))
            ->assertStatus(401);
    }

    // -------------------------------------------------------- identity

    public function test_tenant_and_user_come_from_the_token_and_never_from_the_body(): void
    {
        $victim = $this->tenantOwner('Victim Co', 'owner@victim.test');
        $attacker = $this->tenantOwner('Attacker Co', 'owner@attacker.test');

        $this->withHeaders($this->tokenHeaders($attacker))
            ->postJson('/api/desktop/machine-report', $this->payload(
                [['name' => 'Instant Data Scraper']],
                [
                    // Every one of these is ignored.
                    'organization_id' => $victim->organization_id,
                    'user_id' => $victim->id,
                    'reports' => [[
                        'kind' => 'extensions',
                        'items' => [['name' => 'Instant Data Scraper']],
                        'organization_id' => $victim->organization_id,
                        'user_id' => $victim->id,
                    ]],
                ],
            ))
            ->assertOk();

        $machine = Machine::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($attacker->organization_id, $machine->organization_id);
        $this->assertSame($attacker->id, $machine->user_id);

        // Nothing at all was written under the victim's tenant.
        $this->assertSame(0, Machine::withoutGlobalScopes()->where('organization_id', $victim->organization_id)->count());
        $this->assertSame(0, MachineFlag::withoutGlobalScopes()->where('organization_id', $victim->organization_id)->count());
        $this->assertSame(0, MachineInventoryItem::withoutGlobalScopes()->where('organization_id', $victim->organization_id)->count());
    }

    public function test_bulk_upserted_rows_carry_the_organization_stamp(): void
    {
        // upsert() bypasses the BelongsToOrganization creating hook; an unstamped
        // row would be invisible to its own tenant AND to the retention sweep.
        $owner = $this->tenantOwner('Stamp Co', 'owner@stamp.test');

        $this->withHeaders($this->tokenHeaders($owner))
            ->postJson('/api/desktop/machine-report', $this->payload([
                ['name' => 'uBlock Origin'], ['name' => 'Grammarly'], ['name' => 'Instant Data Scraper'],
            ]))
            ->assertOk();

        $this->assertSame(0, MachineInventoryItem::withoutGlobalScopes()->whereNull('organization_id')->count());
        $this->assertSame(0, MachineFlag::withoutGlobalScopes()->whereNull('organization_id')->count());
        $this->assertSame(3, MachineInventoryItem::count()); // visible through the scope
    }

    // ---------------------------------------------------------- hardening

    public function test_an_oversized_body_is_refused_before_validation(): void
    {
        $owner = $this->tenantOwner('Big Co', 'owner@big.test');

        $this->withHeaders($this->tokenHeaders($owner))
            ->postJson('/api/desktop/machine-report', $this->payload(
                [['name' => 'x']],
                ['filler' => str_repeat('a', 1100000)],
            ))
            ->assertStatus(413);

        $this->assertSame(0, Machine::withoutGlobalScopes()->count());
    }

    public function test_over_cap_items_are_truncated_rather_than_losing_the_batch(): void
    {
        $owner = $this->tenantOwner('Cap Co', 'owner@cap.test');

        $extensions = [];
        for ($i = 0; $i < 900; $i++) {
            $extensions[] = ['name' => 'Extension '.$i];
        }

        $response = $this->withHeaders($this->tokenHeaders($owner))
            ->postJson('/api/desktop/machine-report', $this->payload($extensions, [
                'reports' => [
                    ['kind' => 'extensions', 'items' => $extensions],
                    ['kind' => 'programs', 'items' => [['name' => 'Notepad++', 'publisher' => 'Don Ho']]],
                ],
            ]))
            ->assertOk();

        $this->assertSame(800, $response->json('stored.extensions'));
        $this->assertSame(100, $response->json('truncated.extensions'));
        // The oversized category never cost us the rest of the batch.
        $this->assertSame(1, $response->json('stored.programs'));
        $this->assertSame(1, MachineInventoryItem::where('kind', 'programs')->count());
    }

    public function test_item_strings_are_normalised_before_they_reach_a_dashboard(): void
    {
        $owner = $this->tenantOwner('XSS Co', 'owner@xss.test');

        $this->withHeaders($this->tokenHeaders($owner))
            ->postJson('/api/desktop/machine-report', $this->payload([[
                'name' => "<script>alert(1)</script>Data\nScraper",
                'browser_profile' => "Upwork\r\n*fake alert line*",
                'version' => str_repeat('9', 200),
            ]]))
            ->assertOk();

        $item = MachineInventoryItem::firstOrFail();

        $this->assertStringNotContainsString('<', $item->name);
        $this->assertStringNotContainsString('>', $item->name);
        $this->assertStringNotContainsString("\n", $item->name);
        // Control characters that could forge extra lines in a Slack alert are gone.
        $this->assertStringNotContainsString("\r", (string) $item->browser_profile);
        $this->assertStringNotContainsString("\n", (string) $item->browser_profile);
        $this->assertLessThanOrEqual(MachineInventoryItem::MAX_VERSION, mb_strlen((string) $item->version));
    }

    public function test_device_registrations_are_capped_per_user(): void
    {
        $owner = $this->tenantOwner('Flood Co', 'owner@flood.test');

        // Fill the quota directly — the point under test is the cap, and driving
        // it over HTTP would hit the endpoint's (also deliberate) throttle first.
        for ($i = 0; $i < Machine::MAX_DEVICES_PER_USER - 1; $i++) {
            Machine::create([
                'organization_id' => $owner->organization_id,
                'user_id' => $owner->id,
                'device_name' => 'PC-'.$i,
                'last_seen_at' => now(),
            ]);
        }

        // The last slot is still available.
        $this->withHeaders($this->tokenHeaders($owner))->postJson('/api/desktop/machine-report', $this->payload(
            [['name' => 'uBlock Origin']],
            ['device_name' => 'PC-last'],
        ))->assertOk();

        $this->withHeaders($this->tokenHeaders($owner))->postJson('/api/desktop/machine-report', $this->payload(
            [['name' => 'uBlock Origin']],
            ['device_name' => 'PC-overflow'],
        ))->assertStatus(422)->assertJsonPath('code', 'device_limit_reached');

        $this->assertSame(Machine::MAX_DEVICES_PER_USER, Machine::count());

        // An already-registered device keeps working — the cap blocks new rows,
        // not the fleet.
        $this->withHeaders($this->tokenHeaders($owner))->postJson('/api/desktop/machine-report', $this->payload(
            [['name' => 'uBlock Origin']],
            ['device_name' => 'PC-last'],
        ))->assertOk();
    }

    public function test_an_agent_that_sends_no_device_name_reuses_one_machine_row(): void
    {
        // device_name is nullable, and `where(col, null)` matches nothing in SQL —
        // so a naive lookup would mint a new "PC" on every report until the
        // device cap slammed shut.
        $owner = $this->tenantOwner('Nameless Co', 'owner@nameless.test');

        foreach (range(1, 3) as $ignored) {
            $this->withHeaders($this->tokenHeaders($owner))
                ->postJson('/api/desktop/machine-report', $this->payload(
                    [['name' => 'uBlock Origin']],
                    ['device_name' => null],
                ))
                ->assertOk();
        }

        $this->assertSame(1, Machine::count());
        $this->assertSame(1, MachineInventoryItem::count());
    }

    // ------------------------------------------------- the `profile` fix

    public function test_the_wire_field_is_browser_profile_and_a_profile_key_is_never_read(): void
    {
        // A repeated `profile` key is a ModSecurity rule target: in the source it
        // made the host firewall 403 the ENTIRE upload before it reached PHP,
        // silently losing all four inventories for over half the fleet. The
        // contract therefore uses `browser_profile`, and `profile` must have no
        // effect whatsoever if some future agent sends it.
        $owner = $this->tenantOwner('Profile Co', 'owner@profile.test');

        $this->withHeaders($this->tokenHeaders($owner))
            ->postJson('/api/desktop/machine-report', $this->payload([
                ['name' => 'Instant Data Scraper', 'browser' => 'chrome', 'browser_profile' => 'Upwork Faryal'],
                ['name' => 'Grammarly', 'browser' => 'chrome', 'profile' => 'Should Be Ignored'],
            ]))
            ->assertOk();

        $this->assertSame('Upwork Faryal', MachineInventoryItem::where('name', 'Instant Data Scraper')->value('browser_profile'));
        $this->assertNull(MachineInventoryItem::where('name', 'Grammarly')->value('browser_profile'));

        // The finding carries the profile — "which Upwork account is at risk".
        $this->assertSame('Upwork Faryal', MachineFlag::where('rule', 'scraper')->value('browser_profile'));
    }

    public function test_the_same_extension_in_two_profiles_is_two_findings(): void
    {
        $owner = $this->tenantOwner('Multi Profile Co', 'owner@multi.test');

        $this->withHeaders($this->tokenHeaders($owner))
            ->postJson('/api/desktop/machine-report', $this->payload([
                ['name' => 'Instant Data Scraper', 'id' => 'abc', 'browser' => 'chrome', 'browser_profile' => 'Upwork A'],
                ['name' => 'Instant Data Scraper', 'id' => 'abc', 'browser' => 'chrome', 'browser_profile' => 'Personal'],
            ]))
            ->assertOk();

        $this->assertSame(2, MachineInventoryItem::count());
        $this->assertSame(2, MachineFlag::where('rule', 'scraper')->count());
    }

    // ------------------------------------------------------ the blocklist

    public function test_the_seeded_blocklist_alerts_on_bans_and_watches_vpns(): void
    {
        $owner = $this->tenantOwner('Rules Co', 'owner@rules.test');

        $response = $this->withHeaders($this->tokenHeaders($owner))
            ->postJson('/api/desktop/machine-report', $this->payload([
                ['name' => 'Instant Data Scraper'],
                ['name' => 'GoLogin'],
                ['name' => 'Easy Auto Refresh'],
                ['name' => 'Windscribe VPN'],
                ['name' => 'Selenium IDE'],
                // Allowlisted despite containing a blocklist trigger word.
                ['name' => 'Proxy SwitchyOmega'],
                ['name' => 'uBlock Origin'],
            ]))
            ->assertOk();

        $this->assertSame(5, $response->json('flagged'));

        // Anti-detect browsers, scrapers and refresh/bid tools ALERT.
        foreach (['scraper', 'antidetect_browser', 'upwork_refresh_bid'] as $rule) {
            $this->assertTrue((bool) MachineFlag::where('rule', $rule)->value('alert'), $rule.' must alert');
        }

        // VPN + RPA frameworks are recorded but WATCH-ONLY (owner policy).
        $this->assertFalse((bool) MachineFlag::where('rule', 'vpn_proxy')->value('alert'));
        $this->assertFalse((bool) MachineFlag::where('rule', 'automation_framework')->value('alert'));
        $this->assertSame(3, $response->json('alerted'));

        // The allowlist suppressed the false positives entirely.
        $this->assertFalse((bool) MachineInventoryItem::where('name', 'Proxy SwitchyOmega')->value('flagged'));
        $this->assertFalse((bool) MachineInventoryItem::where('name', 'uBlock Origin')->value('flagged'));
    }

    public function test_a_tenant_can_extend_the_blocklist_and_its_rules_do_not_leak(): void
    {
        // The blocklist is DATA, not a PHP array: a customer adds a rule and it
        // takes effect with no deploy — and only for that customer.
        $a = $this->tenantOwner('Rule Author Co', 'owner@author.test');

        MachineBlocklistRule::create([
            'organization_id' => $a->organization_id,
            'rule_key' => 'custom_tool',
            'label' => 'Banned in-house tool',
            'severity' => 'critical',
            'alert' => true,
            'match_type' => 'contains',
            'pattern' => 'acme helper',
        ]);

        $this->withHeaders($this->tokenHeaders($a))
            ->postJson('/api/desktop/machine-report', $this->payload([['name' => 'ACME Helper']]))
            ->assertOk();

        $this->assertSame('custom_tool', MachineFlag::where('organization_id', $a->organization_id)->value('rule'));

        $b = $this->tenantOwner('Other Co', 'owner@other.test');

        $this->withHeaders($this->tokenHeaders($b))
            ->postJson('/api/desktop/machine-report', $this->payload([['name' => 'ACME Helper']]))
            ->assertOk();

        $this->assertSame(0, MachineFlag::where('organization_id', $b->organization_id)->count());
        // …but the platform baseline still applies to everybody.
        $this->assertFalse((bool) MachineInventoryItem::where('name', 'ACME Helper')->value('flagged'));
    }

    public function test_a_broken_tenant_regex_cannot_take_the_ingest_down(): void
    {
        $owner = $this->tenantOwner('Bad Regex Co', 'owner@regex.test');

        MachineBlocklistRule::create([
            'organization_id' => $owner->organization_id,
            'rule_key' => 'broken',
            'label' => 'Broken pattern',
            'severity' => 'high',
            'alert' => true,
            'match_type' => 'regex',
            'pattern' => '([unclosed',
        ]);

        $this->withHeaders($this->tokenHeaders($owner))
            ->postJson('/api/desktop/machine-report', $this->payload([['name' => 'Instant Data Scraper']]))
            ->assertOk();

        // The valid baseline rule still fired.
        $this->assertSame('scraper', MachineFlag::where('rule', 'scraper')->value('rule'));
    }

    // ------------------------------------------------- current-state model

    public function test_inventory_is_upserted_not_appended(): void
    {
        // The scale decision: repeated reports must not grow the table.
        $owner = $this->tenantOwner('Upsert Co', 'owner@upsert.test');
        $headers = $this->tokenHeaders($owner);
        $items = [['name' => 'uBlock Origin'], ['name' => 'Grammarly']];

        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders($headers)->postJson('/api/desktop/machine-report', $this->payload($items))->assertOk();
        }

        $this->assertSame(1, Machine::count());
        $this->assertSame(2, MachineInventoryItem::count());
    }

    public function test_processes_and_network_store_only_findings(): void
    {
        $owner = $this->tenantOwner('Process Co', 'owner@process.test');

        $response = $this->withHeaders($this->tokenHeaders($owner))
            ->postJson('/api/desktop/machine-report', $this->payload([], [
                'reports' => [
                    ['kind' => 'processes', 'items' => [
                        ['process' => 'chrome.exe'], ['process' => 'explorer.exe'], ['process' => 'MouseJiggler.exe'],
                    ]],
                    ['kind' => 'network', 'items' => [
                        ['adapter' => 'Ethernet', 'description' => 'Realtek PCIe GbE'],
                        ['adapter' => 'NordVPN Tap', 'description' => 'TAP-NordVPN Windows Adapter'],
                    ]],
                ],
            ]))
            ->assertOk();

        $this->assertSame(1, $response->json('stored.processes'));
        $this->assertSame(1, $response->json('stored.network'));
        $this->assertSame(0, MachineInventoryItem::where('name', 'chrome.exe')->count());
        $this->assertSame(1, MachineInventoryItem::where('kind', 'processes')->where('flagged', true)->count());
    }

    public function test_a_finding_resolves_when_the_tool_disappears_and_reopens_when_it_returns(): void
    {
        $owner = $this->tenantOwner('Lifecycle Co', 'owner@lifecycle.test');
        $headers = $this->tokenHeaders($owner);

        $this->withHeaders($headers)->postJson('/api/desktop/machine-report',
            $this->payload([['name' => 'Instant Data Scraper'], ['name' => 'uBlock Origin']]))->assertOk();

        $flag = MachineFlag::firstOrFail();
        $this->assertSame(MachineFlag::STATUS_OPEN, $flag->status);

        // Uninstalled.
        $this->travel(1)->minutes();
        $this->withHeaders($headers)->postJson('/api/desktop/machine-report',
            $this->payload([['name' => 'uBlock Origin']]))->assertOk();

        $this->assertSame(MachineFlag::STATUS_RESOLVED, $flag->fresh()->status);
        $this->assertFalse((bool) MachineInventoryItem::where('name', 'Instant Data Scraper')->value('present'));

        // Reinstalled — this is a genuine new event and must announce again.
        $this->travel(1)->minutes();
        $response = $this->withHeaders($headers)->postJson('/api/desktop/machine-report',
            $this->payload([['name' => 'Instant Data Scraper'], ['name' => 'uBlock Origin']]))->assertOk();

        $this->assertSame(MachineFlag::STATUS_OPEN, $flag->fresh()->status);
        $this->assertSame(1, $response->json('alerted'));
        // Still ONE ledger row — the history is on it, not in a new row.
        $this->assertSame(1, MachineFlag::count());
    }

    public function test_an_acknowledged_or_ignored_finding_does_not_re_announce(): void
    {
        $owner = $this->tenantOwner('Quiet Co', 'owner@quiet.test');
        $headers = $this->tokenHeaders($owner);
        $items = [['name' => 'Instant Data Scraper']];

        $this->withHeaders($headers)->postJson('/api/desktop/machine-report', $this->payload($items))->assertOk();

        MachineFlag::query()->update(['status' => MachineFlag::STATUS_IGNORED]);

        $this->travel(1)->minutes();
        $response = $this->withHeaders($headers)->postJson('/api/desktop/machine-report', $this->payload($items))->assertOk();

        $this->assertSame(0, $response->json('alerted'));
        $this->assertSame(MachineFlag::STATUS_IGNORED, MachineFlag::firstOrFail()->status);
    }

    // ------------------------------------------------- silence ≠ clean

    public function test_an_empty_inventory_does_not_wipe_a_machine_clean(): void
    {
        // "The collector could not see" must never render as "nothing installed".
        $owner = $this->tenantOwner('Blind Co', 'owner@blind.test');
        $headers = $this->tokenHeaders($owner);

        $this->withHeaders($headers)->postJson('/api/desktop/machine-report',
            $this->payload([['name' => 'Instant Data Scraper']]))->assertOk();

        $this->travel(1)->minutes();
        $this->withHeaders($headers)->postJson('/api/desktop/machine-report', $this->payload([]))->assertOk();

        $this->assertTrue((bool) MachineInventoryItem::where('name', 'Instant Data Scraper')->value('present'));
        $this->assertSame(MachineFlag::STATUS_OPEN, MachineFlag::firstOrFail()->status);
    }

    public function test_a_machine_on_an_old_agent_reads_as_unknown_not_clean(): void
    {
        $owner = $this->tenantOwner('Old Agent Co', 'owner@old.test');

        $this->withHeaders($this->tokenHeaders($owner))
            ->postJson('/api/desktop/machine-report', $this->payload([], [
                'app_version' => '0.4.2',
                'reports' => [['kind' => 'programs', 'items' => [['name' => 'Notepad++']]]],
            ]))
            ->assertOk();

        $this->assertTrue(Machine::firstOrFail()->isAgentStale());

        // A current agent is not stale.
        Machine::query()->update(['app_version' => '0.5.0']);
        $this->assertFalse(Machine::firstOrFail()->isAgentStale());

        // Neither is a machine that never reported a version — that is unknown too.
        Machine::query()->update(['app_version' => null]);
        $this->assertTrue(Machine::firstOrFail()->isAgentStale());
    }

    // ---------------------------------------------------------- retention

    public function test_retention_prunes_removed_items_dead_machines_and_resolved_findings(): void
    {
        $owner = $this->tenantOwner('Retention Co', 'owner@retention.test');

        $this->withHeaders($this->tokenHeaders($owner))
            ->postJson('/api/desktop/machine-report', $this->payload([
                ['name' => 'Instant Data Scraper'], ['name' => 'uBlock Origin'],
            ]))
            ->assertOk();

        // One item uninstalled long ago, its finding resolved long ago.
        MachineInventoryItem::where('name', 'Instant Data Scraper')->update([
            'present' => false,
            'removed_at' => now()->subDays(200),
            'last_seen_at' => now()->subDays(200),
        ]);
        MachineFlag::query()->update([
            'status' => MachineFlag::STATUS_RESOLVED,
            'resolved_at' => now()->subDays(200),
        ]);

        $this->artisan('monitoring:prune-screenshots')->assertSuccessful();

        $this->assertSame(0, MachineInventoryItem::where('name', 'Instant Data Scraper')->count());
        $this->assertSame(0, MachineFlag::count());
        // The live item and its machine survive.
        $this->assertSame(1, MachineInventoryItem::count());
        $this->assertSame(1, Machine::count());

        // A machine that stopped reporting takes its inventory with it.
        Machine::query()->update(['last_seen_at' => now()->subDays(400)]);
        $this->artisan('monitoring:prune-screenshots')->assertSuccessful();

        $this->assertSame(0, Machine::count());
        $this->assertSame(0, MachineInventoryItem::count());
    }

    public function test_retention_ages_out_items_no_report_has_confirmed(): void
    {
        // The other half of "empty inventory is unknown": rows still marked
        // present but unconfirmed for weeks must not stay present forever.
        $owner = $this->tenantOwner('Stale Item Co', 'owner@staleitem.test');

        $this->withHeaders($this->tokenHeaders($owner))
            ->postJson('/api/desktop/machine-report', $this->payload([['name' => 'uBlock Origin']]))
            ->assertOk();

        MachineInventoryItem::query()->update(['last_seen_at' => now()->subDays(60)]);
        Machine::query()->update(['last_seen_at' => now()]);

        $this->artisan('monitoring:prune-screenshots')->assertSuccessful();

        $this->assertFalse((bool) MachineInventoryItem::firstOrFail()->present);
    }
}
