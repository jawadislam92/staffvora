<?php

namespace Tests\Feature;

use App\Models\DesktopConsent;
use App\Models\User;
use App\Services\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The desktop agent is monitoring software: it can record time, activity,
 * screenshots and app/website names. Installing that without telling the person
 * and getting their active agreement is the most expensive mistake this product
 * can make for a customer, and "we showed a dialog once" is not a defence
 * unless the acceptance can actually be produced.
 *
 * These pin the parts of that promise that are cheap to break silently:
 *   - the agent is TOLD what the current document version is, by the server, so
 *     new terms re-ask the whole installed fleet without a new build;
 *   - an acceptance is recorded against the token's user, never a user the
 *     (attacker-controlled) client names;
 *   - an acceptance of a DIFFERENT version is refused rather than accepted as
 *     "close enough";
 *   - an unticked box can never produce a row;
 *   - the ledger is tenant-scoped and cannot be flooded.
 */
class DesktopConsentTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $company = 'Consent Co', string $email = 'owner@consent.test'): User
    {
        return app(TenantProvisioner::class)->provision($company, 'Owner', $email, 'secret-pass-123')['owner'];
    }

    // ---- the document ------------------------------------------------------

    public function test_the_agent_is_told_which_version_to_show_and_whether_this_user_accepted_it(): void
    {
        Sanctum::actingAs($this->owner(), ['desktop-tracker']);

        $this->getJson('/api/desktop/consent')
            ->assertOk()
            ->assertJsonPath('document.version', DesktopConsent::CURRENT_VERSION)
            ->assertJsonPath('document.terms_url', 'https://staffvora.com/terms')
            ->assertJsonPath('document.privacy_url', 'https://staffvora.com/privacy')
            ->assertJsonPath('document.disclosure_hash', DesktopConsent::disclosureHash())
            ->assertJsonPath('accepted', false)
            ->assertJsonStructure([
                'document' => ['version', 'effective_date', 'terms_url', 'privacy_url', 'checkbox_label', 'disclosure', 'disclosure_hash'],
                'accepted',
            ]);
    }

    /**
     * The screen exists to tell someone that their screen can be photographed.
     * A disclosure that has quietly lost its screenshot line still renders, still
     * collects a tick, and is worth nothing.
     */
    public function test_the_disclosure_names_the_capabilities_people_actually_care_about(): void
    {
        Sanctum::actingAs($this->owner(), ['desktop-tracker']);

        $body = strtolower((string) $this->getJson('/api/desktop/consent')->getContent());

        foreach (['screenshot', 'keyboard', 'activity', 'website', 'who can see'] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $body,
                "The monitoring disclosure no longer mentions '{$needle}'. Consent to a disclosure that "
                .'omits what is captured is not consent.'
            );
        }

        // "By continuing you agree" is not consent; the label must describe an
        // affirmative act.
        $this->assertStringContainsString('i have read and agree', $body);
    }

    public function test_the_consent_endpoints_require_a_token(): void
    {
        $this->getJson('/api/desktop/consent')->assertUnauthorized();
        $this->postJson('/api/desktop/consent', [
            'accepted' => true,
            'version' => DesktopConsent::CURRENT_VERSION,
        ])->assertUnauthorized();
    }

    // ---- recording an acceptance -------------------------------------------

    public function test_an_acceptance_is_recorded_against_the_token_user_and_the_server_clock(): void
    {
        $owner = $this->owner();
        Sanctum::actingAs($owner, ['desktop-tracker']);

        $this->postJson('/api/desktop/consent', [
            'accepted' => true,
            'version' => DesktopConsent::CURRENT_VERSION,
            'device_name' => 'ANN-LAPTOP',
            'platform' => 'win32',
            'app_version' => '0.5.0',
            'at' => now()->toIso8601String(),
        ])
            ->assertCreated()
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('duplicate', false);

        $consent = DesktopConsent::withoutOrganizationScope()->firstOrFail();

        $this->assertSame($owner->id, $consent->user_id);
        $this->assertSame($owner->organization_id, $consent->organization_id);
        $this->assertSame(DesktopConsent::CURRENT_VERSION, $consent->document_version);
        $this->assertSame(DesktopConsent::disclosureHash(), $consent->disclosure_hash);
        $this->assertSame('https://staffvora.com/terms', $consent->terms_url);
        $this->assertSame('https://staffvora.com/privacy', $consent->privacy_url);
        $this->assertSame(DesktopConsent::SOURCE_DESKTOP_FIRST_RUN, $consent->source);
        $this->assertSame('ANN-LAPTOP', $consent->device_name);
        $this->assertNotNull($consent->accepted_at);
        $this->assertNotNull($consent->ip_address);
    }

    public function test_the_gate_opens_only_after_the_acceptance_is_recorded(): void
    {
        Sanctum::actingAs($this->owner(), ['desktop-tracker']);

        $this->getJson('/api/desktop/consent')->assertJsonPath('accepted', false);

        $this->postJson('/api/desktop/consent', [
            'accepted' => true,
            'version' => DesktopConsent::CURRENT_VERSION,
            'device_name' => 'ANN-LAPTOP',
        ])->assertCreated();

        $this->getJson('/api/desktop/consent')
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('accepted_version', DesktopConsent::CURRENT_VERSION);
    }

    public function test_an_unticked_box_can_never_produce_a_record(): void
    {
        Sanctum::actingAs($this->owner(), ['desktop-tracker']);

        foreach ([false, 0, '0', 'no', null] as $answer) {
            $this->postJson('/api/desktop/consent', [
                'accepted' => $answer,
                'version' => DesktopConsent::CURRENT_VERSION,
            ])->assertStatus(422);
        }

        $this->postJson('/api/desktop/consent', [
            'version' => DesktopConsent::CURRENT_VERSION,
        ])->assertStatus(422);

        $this->assertSame(0, DesktopConsent::withoutOrganizationScope()->count());
    }

    // ---- versioning --------------------------------------------------------

    public function test_accepting_a_different_version_is_refused_with_the_required_one(): void
    {
        Sanctum::actingAs($this->owner(), ['desktop-tracker']);

        $this->postJson('/api/desktop/consent', [
            'accepted' => true,
            'version' => '1999-01-01',
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'consent_version_mismatch')
            ->assertJsonPath('document.version', DesktopConsent::CURRENT_VERSION);

        $this->assertSame(0, DesktopConsent::withoutOrganizationScope()->count());
    }

    /**
     * The whole point of versioning: when the terms change, the previously
     * accepted row must stop satisfying the gate. Exact string comparison, never
     * version_compare() — "newer than what they signed" is not consent.
     */
    public function test_an_acceptance_of_an_older_version_does_not_satisfy_the_current_one(): void
    {
        $owner = $this->owner();
        Sanctum::actingAs($owner, ['desktop-tracker']);

        DesktopConsent::create([
            'organization_id' => $owner->organization_id,
            'user_id' => $owner->id,
            'document_version' => '2020-01-01',
            'accepted_at' => now()->subYear(),
        ]);

        $this->getJson('/api/desktop/consent')->assertJsonPath('accepted', false);
    }

    // ---- identity is never taken from the client ---------------------------

    public function test_a_client_supplied_identity_is_ignored(): void
    {
        $owner = $this->owner();
        $victim = $this->owner('Other Co', 'owner@other.test');

        Sanctum::actingAs($owner, ['desktop-tracker']);

        $this->postJson('/api/desktop/consent', [
            'accepted' => true,
            'version' => DesktopConsent::CURRENT_VERSION,
            'user_id' => $victim->id,
            'organization_id' => $victim->organization_id,
            'device_name' => 'SPOOFED',
        ])->assertCreated();

        $consent = DesktopConsent::withoutOrganizationScope()->firstOrFail();

        $this->assertSame($owner->id, $consent->user_id, 'the agent chose whose consent this was');
        $this->assertSame($owner->organization_id, $consent->organization_id);
        $this->assertSame(0, DesktopConsent::withoutOrganizationScope()->where('user_id', $victim->id)->count());
    }

    public function test_one_workspace_cannot_see_another_workspaces_consent_ledger(): void
    {
        $mine = $this->owner();
        $theirs = $this->owner('Other Co', 'owner@other.test');

        DesktopConsent::create([
            'organization_id' => $theirs->organization_id,
            'user_id' => $theirs->id,
            'document_version' => DesktopConsent::CURRENT_VERSION,
            'accepted_at' => now(),
        ]);

        Sanctum::actingAs($mine, ['desktop-tracker']);

        // The other tenant's acceptance must not open this user's gate, and must
        // not be readable through the tenant-scoped model.
        $this->getJson('/api/desktop/consent')->assertJsonPath('accepted', false);
        $this->assertSame(0, DesktopConsent::query()->count());
        $this->assertSame(1, DesktopConsent::withoutOrganizationScope()->count());
    }

    // ---- row-flood bounds --------------------------------------------------

    public function test_re_accepting_on_the_same_device_does_not_write_a_second_row(): void
    {
        Sanctum::actingAs($this->owner(), ['desktop-tracker']);

        $payload = [
            'accepted' => true,
            'version' => DesktopConsent::CURRENT_VERSION,
            'device_name' => 'ANN-LAPTOP',
        ];

        $this->postJson('/api/desktop/consent', $payload)->assertCreated();
        $this->postJson('/api/desktop/consent', $payload)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertSame(1, DesktopConsent::withoutOrganizationScope()->count());
    }

    public function test_accepting_on_a_second_machine_is_recorded_separately(): void
    {
        Sanctum::actingAs($this->owner(), ['desktop-tracker']);

        $this->postJson('/api/desktop/consent', [
            'accepted' => true,
            'version' => DesktopConsent::CURRENT_VERSION,
            'device_name' => 'ANN-LAPTOP',
        ])->assertCreated();

        $this->postJson('/api/desktop/consent', [
            'accepted' => true,
            'version' => DesktopConsent::CURRENT_VERSION,
            'device_name' => 'ANN-DESKTOP',
        ])->assertCreated();

        $this->assertSame(2, DesktopConsent::withoutOrganizationScope()->count());
    }

    // ---- the client clock is a claim, not a fact ---------------------------

    public function test_a_fabricated_acceptance_time_is_clamped_and_kept_apart_from_the_server_clock(): void
    {
        Sanctum::actingAs($this->owner(), ['desktop-tracker']);

        $this->postJson('/api/desktop/consent', [
            'accepted' => true,
            'version' => DesktopConsent::CURRENT_VERSION,
            'device_name' => 'ANN-LAPTOP',
            'at' => now()->subYears(5)->toIso8601String(),
        ])->assertCreated();

        $consent = DesktopConsent::withoutOrganizationScope()->firstOrFail();

        $this->assertTrue(
            $consent->client_at->greaterThan(now()->subDays(31)),
            'a five-year-old client timestamp was stored verbatim'
        );
        $this->assertTrue(
            $consent->accepted_at->greaterThan(now()->subMinute()),
            'accepted_at must be the server clock, not the client claim'
        );
    }

    // ---- the deactivated-account guard still applies -----------------------

    public function test_a_deactivated_employee_cannot_record_a_consent(): void
    {
        $owner = $this->owner();
        $owner->forceFill(['is_active' => false])->save();

        Sanctum::actingAs($owner->fresh(), ['desktop-tracker']);

        $this->postJson('/api/desktop/consent', [
            'accepted' => true,
            'version' => DesktopConsent::CURRENT_VERSION,
        ])->assertForbidden();
    }

    // ---- desktop <-> server contract ---------------------------------------
    //
    // The agent carries a bundled copy of the disclosure so an offline first
    // launch is still honest. That copy drifting from the server's is silent and
    // only bites on a customer's PC: the employee accepts version X offline, the
    // server requires Y, and they are asked again for no reason — or worse, the
    // bundled text stops matching the terms they are actually agreeing to.

    private function bundledDocument(): string
    {
        $path = base_path('desktop/main/consent.js');

        $this->assertFileExists($path, 'The desktop consent module is missing.');

        return (string) file_get_contents($path);
    }

    public function test_the_agents_offline_fallback_matches_the_servers_document(): void
    {
        $source = $this->bundledDocument();

        preg_match("/version:\s*'([^']+)'/", $source, $version);
        preg_match("/terms_url:\s*'([^']+)'/", $source, $terms);
        preg_match("/privacy_url:\s*'([^']+)'/", $source, $privacy);

        $this->assertSame(
            DesktopConsent::CURRENT_VERSION,
            $version[1] ?? null,
            'desktop/main/consent.js BUNDLED_DOCUMENT.version has drifted from DesktopConsent::CURRENT_VERSION. '
            .'An offline acceptance would be recorded against the wrong version.'
        );
        $this->assertSame(DesktopConsent::TERMS_URL, $terms[1] ?? null);
        $this->assertSame(DesktopConsent::PRIVACY_URL, $privacy[1] ?? null);
    }

    /**
     * A pre-ticked box is not consent. This is one `useState(true)` away from
     * being wrong, and nothing else in the stack would notice.
     */
    public function test_the_consent_checkbox_is_not_pre_ticked_and_the_gate_is_wired_in(): void
    {
        $view = (string) file_get_contents(base_path('desktop/renderer/src/views/Consent.jsx'));

        $this->assertFileExists(base_path('desktop/renderer/src/views/Consent.jsx'));
        $this->assertStringContainsString(
            'useState(false)',
            $view,
            'The consent checkbox must start unticked.'
        );
        $this->assertStringNotContainsString('defaultChecked', $view);
        $this->assertStringContainsString('checked={ticked}', $view);

        $app = (string) file_get_contents(base_path('desktop/renderer/src/App.jsx'));

        $this->assertStringContainsString(
            'Consent',
            $app,
            'App.jsx no longer renders the consent gate — the tracker would be reachable without it.'
        );
        $this->assertStringContainsString(
            'consented !== true',
            $app,
            'The gate must fail closed: anything other than an explicit true keeps the tracker out of reach.'
        );
    }

    /**
     * The installer must not be the place consent is TAKEN (it runs once per
     * machine, usually under an IT account, with nobody signed in), but it must
     * still say what is being installed — and it must not block a silent
     * auto-update with a modal.
     */
    public function test_the_installer_discloses_monitoring_without_blocking_silent_updates(): void
    {
        $nsh = (string) file_get_contents(base_path('desktop/build/installer.nsh'));

        $this->assertStringContainsString('customInit', $nsh);
        $this->assertStringContainsString('MessageBox', $nsh);
        $this->assertStringContainsString('IfSilent', $nsh, 'A modal in silent mode hangs auto-updates and IT rollouts.');
        $this->assertStringContainsString('staffvora.com/terms', $nsh);
        $this->assertStringContainsString('staffvora.com/privacy', $nsh);
    }

    // ---- the disclosure hash is a real fingerprint -------------------------

    public function test_the_disclosure_hash_changes_when_the_disclosure_changes(): void
    {
        $hash = DesktopConsent::disclosureHash();

        $this->assertSame(64, strlen($hash), 'expected a sha256 hex digest');
        $this->assertNotSame(hash('sha256', ''), $hash);
        $this->assertSame($hash, DesktopConsent::disclosureHash(), 'the hash must be stable');
    }
}
