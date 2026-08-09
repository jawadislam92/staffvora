<?php

namespace Tests\Feature;

use App\Http\Kernel;
use App\Http\Middleware\TrustHosts;
use App\Models\MonitoringSetting;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
// Staffvora sends its own branded subclass (App\Models\User::
// sendPasswordResetNotification()) instead of the framework default —
// assert against that concrete class. Same ->token, same toMail() shape.
use App\Notifications\ResetPasswordNotification as ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Tests\TestCase;

/**
 * Regression cover for the anonymous (pre-login) attack surface.
 *
 * These are the INVERTED forms of the audit's Area-5 proof tests: each one
 * asserts the DEFENCE, so it fails the moment the corresponding guard is
 * removed. Every hole here was reachable by an unauthenticated caller, and
 * several only became dangerous once real SMTP was switched on.
 */
class PublicAuthSurfaceHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $company, string $email): User
    {
        $owner = app(TenantProvisioner::class)
            ->provision($company, 'Owner', $email, 'secret-pass-1234')['owner'];

        app(CurrentOrganization::class)->forget();

        return $owner->fresh();
    }

    private function appHost(): string
    {
        return (string) parse_url(config('app.url'), PHP_URL_HOST);
    }

    // ---------------------------------------------------------------------
    // 1. Host-header injection -> poisoned reset links (TrustHosts)
    // ---------------------------------------------------------------------

    /**
     * Install exactly what TrustHosts installs in production.
     *
     * Laravel deliberately skips host validation while the test suite runs
     * (TrustHosts::shouldSpecifyTrustedHosts), and that skip is worth keeping —
     * the trusted-host list is a PROCESS-WIDE static, so leaving it armed for
     * the whole suite leaks into every later test that builds a Request on some
     * other hostname. So arm it for this test only, and disarm it in tearDown.
     */
    private function armTrustedHosts(): void
    {
        SymfonyRequest::setTrustedHosts(
            array_filter((new TrustHosts($this->app))->hosts())
        );
    }

    protected function tearDown(): void
    {
        SymfonyRequest::setTrustedHosts([]);
        parent::tearDown();
    }

    public function test_trust_hosts_is_registered_in_the_global_middleware_stack(): void
    {
        // The actual regression: this middleware was commented out of
        // app/Http/Kernel.php, which is what made every emailed absolute URL
        // attacker-controllable. If it is ever removed again, fail here.
        $global = (new \ReflectionClass(Kernel::class))
            ->getDefaultProperties()['middleware'];

        $this->assertContains(TrustHosts::class, $global,
            'TrustHosts is not in the global HTTP middleware stack');
    }

    public function test_a_foreign_host_header_can_never_poison_an_emailed_reset_link(): void
    {
        Notification::fake();
        $this->armTrustedHosts();

        $owner = $this->owner('Victim Corp', 'victim-host@corp.test');

        $response = $this->post('http://attacker.example/forgot-password', [
            'email' => $owner->email,
        ]);

        // Untrusted host is refused outright rather than being echoed back into
        // absolute URLs. Laravel maps Symfony's SuspiciousOperationException to
        // a 404 ("Bad hostname provided"); either rejection code is acceptable.
        $this->assertContains($response->getStatusCode(), [400, 404],
            'a request with a foreign Host header was served normally');

        Notification::assertNotSentTo($owner, ResetPassword::class);
    }

    public function test_reset_links_are_built_from_the_configured_application_host(): void
    {
        Notification::fake();
        $this->armTrustedHosts();

        $owner = $this->owner('Real Corp', 'victim-host2@corp.test');

        $this->post('/forgot-password', ['email' => $owner->email]);

        Notification::assertSentTo($owner, ResetPassword::class, function (ResetPassword $n) use ($owner) {
            $url = (string) $n->toMail($owner)->actionUrl;

            $this->assertStringContainsString($this->appHost(), $url);
            $this->assertStringNotContainsString('attacker.example', $url);

            return true;
        });
    }

    public function test_the_operator_console_host_stays_trusted(): void
    {
        // config('platform.host') is served through Route::domain(); turning
        // TrustHosts on must not lock the landlord portal out of its own host.
        config(['platform.host' => 'console.some-other-brand.test']);

        $patterns = array_filter((new TrustHosts($this->app))->hosts());

        $matched = false;
        foreach ($patterns as $pattern) {
            if (preg_match('{'.$pattern.'}i', 'console.some-other-brand.test')) {
                $matched = true;
            }
        }

        $this->assertTrue($matched, 'the operator console host is not in the trusted host list');
    }

    // ---------------------------------------------------------------------
    // 2. POST /reset-password throttling (CPU burn + 6-digit code brute force)
    // ---------------------------------------------------------------------

    /**
     * The guessing budget belongs to the ACCOUNT and survives IP rotation, and
     * once it is spent even a CORRECT guess is refused.
     *
     * Every request below comes from a different source address, so the per-IP
     * route throttle can never be the thing refusing them — this is purely the
     * account-wide budget in NewPasswordController.
     *
     * The budget used to be a ten-minute HARD block keyed on the submitted
     * address string. That was evadable (see
     * RedTeamPasswordResetChainTest::test_redteam_lockout_key_is_not_collation_equivalent)
     * and doubled as a denial-of-service weapon against the real owner. It now
     * escalates instead of hard-blocking; PasswordResetTakeoverHardeningTest
     * pins the "the owner still gets in" half of that bargain.
     */
    public function test_reset_password_budget_is_account_wide_and_survives_ip_rotation(): void
    {
        $victim = $this->owner('Victim Corp', 'victim-brute@corp.test');

        // Exactly what PasswordResetCodeController::store writes — both flows
        // share this row, so this endpoint is a back door onto the 6-digit code.
        $code = '424242';
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $victim->email],
            ['token' => Hash::make($code), 'created_at' => now()]
        );

        for ($i = 0; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$i])
                ->from('/reset-password/x')
                ->post('/reset-password', [
                    'token' => str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                    'email' => $victim->email,
                    'password' => 'attacker-password-1',
                    'password_confirmation' => 'attacker-password-1',
                ]);
        }

        // Even the CORRECT code is now refused: the budget is spent, and a
        // fresh source address does not refill it.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])
            ->from('/reset-password/x')
            ->post('/reset-password', [
                'token' => $code,
                'email' => $victim->email,
                'password' => 'attacker-password-1',
                'password_confirmation' => 'attacker-password-1',
            ])->assertStatus(429);

        $this->assertFalse(
            Hash::check('attacker-password-1', $victim->fresh()->password),
            'password was changed despite the lockout'
        );
    }

    public function test_reset_password_is_rate_limited_per_ip_even_across_different_addresses(): void
    {
        // Distinct addresses on every request, so ONLY the route throttle can
        // stop this. Each attempt costs a bcrypt comparison on a shared box.
        $status = 200;

        for ($i = 0; $i < 12; $i++) {
            $status = $this->from('/reset-password/x')->post('/reset-password', [
                'token' => 'whatever',
                'email' => "probe{$i}@corp.test",
                'password' => 'attacker-password-1',
                'password_confirmation' => 'attacker-password-1',
            ])->getStatusCode();

            if ($status === 429) {
                break;
            }
        }

        $this->assertSame(429, $status, 'POST /reset-password accepted 12 requests without throttling');
    }

    // ---------------------------------------------------------------------
    // 3. POST /forgot-password: throttle + no enumeration oracle
    // ---------------------------------------------------------------------

    public function test_forgot_password_answers_identically_for_known_and_unknown_addresses(): void
    {
        Notification::fake();

        $owner = $this->owner('Real Corp', 'real-enum@corp.test');

        $known = $this->from('/forgot-password')->post('/forgot-password', ['email' => $owner->email]);
        $unknown = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nobody-enum@corp.test']);

        $known->assertSessionHasNoErrors();
        $unknown->assertSessionHasNoErrors();

        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());

        // The two responses must be indistinguishable — otherwise this endpoint
        // answers "does this address use Staffvora?" for anyone on earth.
        $this->assertSame(
            $known->getSession()->get('status'),
            $unknown->getSession()->get('status'),
            'a real address produced a different answer than an unknown one'
        );

        // ...while the genuine mail is still sent for the account that exists.
        Notification::assertSentTo($owner, ResetPassword::class);
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        Notification::fake();

        $status = 200;

        for ($i = 0; $i < 12; $i++) {
            $status = $this->from('/forgot-password')
                ->post('/forgot-password', ['email' => "probe{$i}@corp.test"])
                ->getStatusCode();

            if ($status === 429) {
                break;
            }
        }

        $this->assertSame(429, $status, 'POST /forgot-password accepted 12 probes without throttling');
    }

    // ---------------------------------------------------------------------
    // 4. Reset codes must never reach the OPERATOR's Slack workspace
    // ---------------------------------------------------------------------

    public function test_reset_codes_are_delivered_through_the_tenants_own_slack(): void
    {
        $victim = $this->owner('Victim Corp', 'victim-slack@corp.test');

        app(CurrentOrganization::class)->runFor($victim->organization_id, function () {
            MonitoringSetting::current()->update(['slack_bot_token' => 'xoxb-TENANT-OWN-TOKEN']);
        });
        app(CurrentOrganization::class)->forget();

        config([
            'services.slack_bot.token' => 'xoxb-PLATFORM-OPERATOR-TOKEN',
            'services.slack_bot.resets_channel' => '#password-resets',
        ]);

        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            $seen[] = $request->header('Authorization')[0] ?? '';

            // Force the DM lookup to fail so the channel fallback also fires —
            // that fallback used to leak into the operator's shared channel.
            return Http::response(['ok' => false, 'error' => 'users_not_found'], 200);
        });

        $this->post('/forgot-password/code', ['email' => $victim->email])->assertRedirect();

        $this->assertNotEmpty($seen, 'no Slack call was made at all');

        foreach ($seen as $auth) {
            $this->assertStringContainsString('xoxb-TENANT-OWN-TOKEN', $auth,
                'a reset code was sent with a token that is not the tenant’s own');
            $this->assertStringNotContainsString('xoxb-PLATFORM-OPERATOR-TOKEN', $auth);
        }
    }

    public function test_a_tenant_without_its_own_slack_gets_nothing_rather_than_the_operators_workspace(): void
    {
        $victim = $this->owner('No Slack Corp', 'victim-noslack@corp.test');

        // The operator's platform bot exists, but this tenant never connected
        // Slack. Failing CLOSED is the point: silence beats delivering this
        // customer's reset code into the operator's own workspace.
        config([
            'services.slack_bot.token' => 'xoxb-PLATFORM-OPERATOR-TOKEN',
            'services.slack_bot.resets_channel' => '#password-resets',
        ]);

        Http::fake();

        $this->post('/forgot-password/code', ['email' => $victim->email])
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertNothingSent();

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $victim->email]);
    }

    // ---------------------------------------------------------------------
    // 5. The public contact form must not be a mail amplifier
    // ---------------------------------------------------------------------

    public function test_contact_form_never_mails_a_visitor_supplied_address(): void
    {
        $recipients = [];
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$recipients) {
            foreach ($event->message->getTo() as $address) {
                $recipients[] = $address->getAddress();
            }
        });

        $this->post('/contact', [
            'name' => 'Your Bank',
            'email' => 'target@victim.test',
            'message' => 'Your account is locked. Click https://evil.test to restore it.',
        ])->assertRedirect();

        $support = config('brand.support_email') ?: 'hello@staffvora.com';

        $this->assertNotContains('target@victim.test', $recipients,
            'the contact form mailed an address chosen by an anonymous caller');
        $this->assertSame([$support], $recipients,
            'the only recipient must be the operator support address from config');

        // The lead itself is still captured — the fix must not lose enquiries.
        $this->assertDatabaseHas('contact_submissions', ['email' => 'target@victim.test']);
    }

    public function test_contact_form_does_not_echo_the_submitted_text_back_out_over_mail(): void
    {
        config(['mail.default' => 'log']);

        $body = str_repeat('Z', 5000);

        $captured = [];
        Log::listen(function ($e) use (&$captured) {
            $captured[] = $e->message;
        });

        $this->post('/contact', [
            'name' => 'Bob',
            'email' => 'victim@target.test',
            'message' => $body,
        ])->assertSessionHasNoErrors();

        $all = implode("\n", $captured);

        // Exactly ONE outbound mail — the operator copy. The auto-acknowledge
        // that quoted the submitter's own 5,000 characters back at an address
        // they never proved they own is gone, and with it half the
        // attacker-controlled log/queue footprint per anonymous request.
        $this->assertStringNotContainsString('Subject: We got your message', $all);
        // Anchored: "Reply-To: Bob <victim@target.test>" on the operator copy is
        // fine and intended — what must never exist is a To: header carrying it.
        $this->assertSame(0, preg_match('/(?:^|\r?\n)To: [^\r\n]*victim@target\.test/', $all),
            'a mail was addressed TO the anonymous submitter');
        $this->assertSame(1, preg_match_all('/Z{5000}/', $all),
            'the submitted body was written to more than one outbound mail');
    }

    public function test_contact_form_stays_rate_limited_per_ip(): void
    {
        config(['mail.default' => 'array']);

        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = $this->post('/contact', [
                'name' => 'Bob',
                'email' => 'v'.$i.'@target.test',
                'message' => str_repeat('Q', 5000),
            ])->getStatusCode();
        }

        $this->assertSame(429, end($codes), 'POST /contact is no longer throttled');
    }

    // ---------------------------------------------------------------------
    // 6. Signup must reject the reserved demo namespace with a form error
    // ---------------------------------------------------------------------

    public function test_signup_rejects_the_reserved_demo_email_namespace(): void
    {
        $reserved = 'someone@'.User::DEMO_EMAIL_DOMAIN;

        $this->from('/register')->post('/register', [
            'company' => 'Laundering Ltd',
            'name' => 'Owner',
            'email' => $reserved,
            'password' => 'secret-pass-1234',
            'password_confirmation' => 'secret-pass-1234',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => $reserved]);
        $this->assertDatabaseMissing('organizations', ['name' => 'Laundering Ltd']);
    }

    public function test_signup_still_works_for_a_normal_address(): void
    {
        $this->post('/register', [
            'company' => 'Normal Ltd',
            'name' => 'Owner',
            'email' => 'owner@normal.test',
            'password' => 'secret-pass-1234',
            'password_confirmation' => 'secret-pass-1234',
        ])->assertRedirect(route('onboarding.show'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'owner@normal.test']);
    }
}
