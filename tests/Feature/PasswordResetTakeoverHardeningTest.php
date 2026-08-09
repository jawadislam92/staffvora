<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetCodeController;
use App\Http\Middleware\TrustHosts;
use App\Http\Middleware\TrustProxies;
use App\Models\MonitoringSetting;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
// Staffvora sends its own branded subclass (App\Models\User::
// sendPasswordResetNotification()) instead of the framework default —
// assert against that concrete class. Same ->token, same toMail() shape.
use App\Notifications\ResetPasswordNotification as ResetPassword;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request as LaravelRequest;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Tests\TestCase;

/**
 * ROUND-2 REGRESSION COVER — account takeover through the password-reset chain.
 *
 * Every test here is the INVERTED form of a hole an independent red team walked
 * through in the FIXED code, plus the collateral that round-1 hardening caused.
 * Each one was confirmed failing against the pre-fix tree before the fix landed.
 *
 * Themes, because the same two mistakes produced most of the findings:
 *
 *  • THE SIBLING. /reset-password and /reset-password/code unlock the same row.
 *    A guard on one is not a guard. Several tests below deliberately spend a
 *    budget on one endpoint and then assert the other is out of budget too.
 *
 *  • THE DATABASE MUST NOT BE THE JUDGE OF STRING EQUALITY. The suite runs
 *    SQLite (BINARY), production runs MySQL utf8mb4_unicode_ci, and a guard
 *    written in PHP against a lookup written in SQL cannot be tested here at
 *    all. So the guard is keyed on the account the database RESOLVED, and the
 *    tests assert that property rather than a list of unicode spellings.
 */
class PasswordResetTakeoverHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        SymfonyRequest::setTrustedHosts([]);
        SymfonyRequest::setTrustedProxies([], SymfonyRequest::HEADER_X_FORWARDED_FOR);
        TrustProxies::flushState();
        unset($_SERVER['TRUSTED_PROXIES'], $_SERVER['TRUSTED_HOSTS_EXTRA'], $_SERVER['SERVER_ADDR']);
        parent::tearDown();
    }

    private function owner(string $company, string $email): User
    {
        $owner = app(TenantProvisioner::class)
            ->provision($company, 'Owner', $email, 'secret-pass-1234')['owner'];

        app(CurrentOrganization::class)->forget();

        return $owner->fresh();
    }

    /** Arm exactly what TrustHosts arms in production. */
    private function armTrustedHosts(): void
    {
        SymfonyRequest::setTrustedHosts(
            array_values(array_filter((new TrustHosts($this->app))->hosts()))
        );
    }

    private function appHost(): string
    {
        return (string) parse_url(config('app.url'), PHP_URL_HOST);
    }

    /** Store a known 6-digit code for an account, exactly as the Slack flow does. */
    private function issueCode(User $user, string $code = '424242'): void
    {
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($code), 'created_at' => now()]
        );
    }

    // =====================================================================
    // (1) X-Forwarded-Prefix / emailed link roots
    // =====================================================================

    /**
     * The header that hijacked the reset link is not trusted at all any more.
     *
     * Symfony's getBaseUrl() prepends X-Forwarded-Prefix verbatim into the root
     * URL — no validation whatsoever — the moment this bit is set and the peer
     * is trusted. Round 1 ADDED the bit while closing the Host-header hole.
     */
    public function test_trust_proxies_does_not_trust_the_forwarded_prefix_header(): void
    {
        $headers = (new \ReflectionClass(TrustProxies::class))
            ->getDefaultProperties()['headers'];

        $this->assertSame(
            0,
            $headers & SymfonyRequest::HEADER_X_FORWARDED_PREFIX,
            'HEADER_X_FORWARDED_PREFIX is trusted again — this is the header that turned the real domain into URL userinfo'
        );

        // Still honouring the headers a real edge genuinely needs.
        foreach ([
            'HEADER_X_FORWARDED_FOR' => SymfonyRequest::HEADER_X_FORWARDED_FOR,
            'HEADER_X_FORWARDED_HOST' => SymfonyRequest::HEADER_X_FORWARDED_HOST,
            'HEADER_X_FORWARDED_PROTO' => SymfonyRequest::HEADER_X_FORWARDED_PROTO,
        ] as $label => $bit) {
            $this->assertSame($bit, $headers & $bit, "{$label} is no longer trusted — rate limiting and https URLs break behind the edge");
        }
    }

    /** The header is stripped from the request outright, belt as well as braces. */
    public function test_the_forwarded_prefix_header_is_stripped_from_the_request(): void
    {
        $_SERVER['TRUSTED_PROXIES'] = '127.0.0.1';

        $seen = 'not-run';

        $request = LaravelRequest::create('http://'.$this->appHost().'/', 'GET');
        $request->headers->set('X-Forwarded-Prefix', '@evil.test');
        $request->server->set('HTTP_X_FORWARDED_PREFIX', '@evil.test');

        (new TrustProxies)->handle($request, function (LaravelRequest $r) use (&$seen) {
            $seen = $r->headers->get('X-Forwarded-Prefix');

            return response('ok');
        });

        $this->assertNull($seen, 'X-Forwarded-Prefix survived TrustProxies');
    }

    /**
     * Collect the reset links out of mail that was actually RENDERED DURING the
     * request.
     *
     * Deliberately not Notification::fake(): a faked notification is only turned
     * into a MailMessage when the assertion calls toMail(), i.e. long after the
     * poisoned request finished and with whatever URL state the LAST request
     * left behind. That measures nothing. The array mailer renders the body
     * inside the request, which is the only moment at which "what host did we
     * put in the customer's inbox?" has an answer.
     *
     * @return array<int, string>
     */
    private function resetLinksMailedDuring(callable $work): array
    {
        $links = [];

        Event::listen(MessageSent::class, function (MessageSent $event) use (&$links) {
            $body = quoted_printable_decode($event->message->toString());

            if (preg_match_all('~https?://[^\s"\'<>\]]+/reset-password/[A-Za-z0-9]+~', $body, $matches)) {
                foreach ($matches[0] as $link) {
                    $links[] = $link;
                }
            }
        });

        $work();

        return $links;
    }

    /**
     * End to end: a trusted proxy sending the weaponised prefix must not be able
     * to steer the host of the emailed link.
     *
     * A fresh account per prefix, because the broker throttles repeat sends to
     * the same address and would otherwise leave three of the four untested.
     */
    public function test_a_forwarded_prefix_cannot_hijack_the_emailed_reset_link(): void
    {
        $_SERVER['TRUSTED_PROXIES'] = '127.0.0.1';
        $this->armTrustedHosts();

        $prefixes = ['@evil.test', 'https://evil.test/x', '//evil.test', '@evil.test:8443'];

        $owners = [];

        foreach ($prefixes as $i => $prefix) {
            $owners[$i] = $this->owner('Prefix Corp '.$i, 'prefix-fix-'.$i.'@corp.test');
        }

        $links = $this->resetLinksMailedDuring(function () use ($prefixes, $owners) {
            foreach ($prefixes as $i => $prefix) {
                $this->withHeaders(['X-Forwarded-Prefix' => $prefix])
                    ->post('/forgot-password', ['email' => $owners[$i]->email]);
            }
        });

        // One link per account (it appears several times inside each message).
        $this->assertGreaterThanOrEqual(count($prefixes), count(array_unique($links)),
            'not every poisoned request produced a reset mail to inspect');

        foreach ($links as $link) {
            $this->assertSame($this->appHost(), parse_url($link, PHP_URL_HOST),
                "the emitted reset URL does not resolve to the application host: {$link}");
            $this->assertStringNotContainsString('evil.test', $link);
        }
    }

    /**
     * The link root for reset mail comes from configuration, not from the
     * request — even when the request is served on an infrastructure host.
     *
     * This is the guard that makes serving loopback traffic safe: a local cron
     * `curl http://127.0.0.1/...` that triggers a reset must not mail a customer
     * a link to 127.0.0.1.
     */
    public function test_a_reset_triggered_on_a_loopback_host_still_mails_the_public_link(): void
    {
        $this->armTrustedHosts();

        $hosts = ['localhost', '127.0.0.1'];
        $owners = [];

        foreach ($hosts as $i => $host) {
            $owners[$i] = $this->owner('Loopback Corp '.$i, 'loopback-'.$i.'@corp.test');
        }

        $links = $this->resetLinksMailedDuring(function () use ($hosts, $owners) {
            foreach ($hosts as $i => $host) {
                $this->post('http://'.$host.'/forgot-password', ['email' => $owners[$i]->email])
                    ->assertSessionHasNoErrors();
            }
        });

        $this->assertGreaterThanOrEqual(count($hosts), count(array_unique($links)),
            'the loopback request was refused, so no mail was produced');

        foreach ($links as $link) {
            $this->assertSame($this->appHost(), parse_url($link, PHP_URL_HOST),
                "a link built on a loopback host was emailed: {$link}");
        }
    }

    // =====================================================================
    // (4a) Collateral — infrastructure traffic must still be served
    // =====================================================================

    public function test_loopback_and_no_host_probes_are_served_rather_than_refused(): void
    {
        $this->armTrustedHosts();

        $codes = [];

        foreach (['localhost', '127.0.0.1'] as $host) {
            $codes[$host] = $this->get('http://'.$host.'/login')->getStatusCode();
        }

        foreach ($codes as $host => $code) {
            $this->assertNotContains($code, [400, 404],
                "an uptime/nginx probe on {$host} was refused with {$code}");
        }

        // ...and a request that carries no Host header at all (HTTP/1.0 probes).
        $server = $this->transformHeadersToServerVars([]);
        unset($server['HTTP_HOST']);
        $server['SERVER_NAME'] = 'localhost';

        $request = SymfonyRequest::create('http://localhost/login', 'GET', [], [], [], $server);
        $request->headers->remove('HOST');

        $response = $this->app->make(HttpKernel::class)
            ->handle(LaravelRequest::createFromBase($request));

        $this->assertNotContains($response->getStatusCode(), [400],
            'a request with no Host header was refused');
    }

    /** The origin's own IP (what an external uptime monitor hits) is trusted. */
    public function test_the_origin_ip_and_configured_extra_hosts_are_trusted(): void
    {
        $_SERVER['SERVER_ADDR'] = '203.0.113.10';
        $_SERVER['TRUSTED_HOSTS_EXTRA'] = 'health.internal , 198.51.100.7';

        $this->armTrustedHosts();

        foreach (['203.0.113.10', 'health.internal', '198.51.100.7'] as $host) {
            $this->assertNotContains(
                $this->get('http://'.$host.'/login')->getStatusCode(),
                [400, 404],
                "{$host} was refused even though it is an operator-declared infrastructure host"
            );
        }

        // Still an allow-list: an address we never declared stays refused.
        $this->assertContains(
            $this->get('http://198.51.100.99/login')->getStatusCode(),
            [400, 404],
            'an undeclared host was served — the allow-list has been widened too far'
        );
    }

    // =====================================================================
    // (4b) Fail closed, not fail open, when the host list resolves to nothing
    // =====================================================================

    public function test_an_unresolvable_host_configuration_fails_closed_and_loudly(): void
    {
        $realHost = $this->appHost();

        config(['app.url' => '', 'platform.host' => '']);

        Log::spy();

        $patterns = (new TrustHosts($this->app))->publicHostPatterns();

        // NOT an empty array: Symfony reads an empty trusted-host list as
        // "trust every host on earth", which is the opposite of the intent.
        $this->assertNotSame([], $patterns, 'the public host list is empty — Symfony would trust every host');
        $this->assertSame([TrustHosts::NEVER_MATCHES], $patterns);

        Log::shouldHaveReceived('critical')
            ->withArgs(fn ($message, $context = []) => is_string($message)
                && str_contains($message, 'Trusted host list resolved to nothing'))
            ->atLeast()->once();

        // And armed, that pattern refuses everything rather than allowing it.
        $this->armTrustedHosts();

        $this->assertContains(
            $this->get('http://'.$realHost.'/login')->getStatusCode(),
            [400, 404],
            'traffic was served while the trusted-host configuration was broken'
        );
    }

    // =====================================================================
    // (4c) deploy:preflight catches the environments where the guard is off
    // =====================================================================

    public function test_preflight_fails_when_a_live_looking_box_still_says_app_env_local(): void
    {
        config(['app.env' => 'local', 'app.url' => 'https://staffvora.com']);

        // One substring only: both phrases live on the same printed line, and
        // the console mock hands each line to the first matching expectation.
        $this->artisan('deploy:preflight')
            ->expectsOutputToContain('Host header validation OFF')
            ->assertExitCode(1);
    }

    public function test_preflight_fails_when_the_trusted_host_list_resolves_to_nothing(): void
    {
        config(['app.env' => 'production', 'app.url' => 'not a url', 'platform.host' => '']);

        $this->artisan('deploy:preflight')
            ->expectsOutputToContain('trusted host list resolves to NOTHING')
            ->assertExitCode(1);
    }

    public function test_preflight_only_cautions_on_a_genuine_development_machine(): void
    {
        // APP_ENV=local with a .test address is a laptop, not a live box.
        config(['app.env' => 'local', 'app.url' => 'http://staffvora.test']);

        $this->artisan('deploy:preflight')
            ->expectsOutputToContain('never on the live server');
    }

    // =====================================================================
    // (2)/(3) The account-wide guessing budget
    // =====================================================================

    /**
     * THE SIBLING CASE. Both endpoints verify the same password_reset_tokens
     * row, so they must draw on ONE budget — otherwise an attacker who exhausts
     * the budget on /reset-password simply carries on at /reset-password/code.
     */
    public function test_both_reset_endpoints_share_one_account_budget(): void
    {
        $victim = $this->owner('Sibling Corp', 'sibling@corp.test');
        $this->issueCode($victim);

        // Spend the budget entirely on the token endpoint, rotating IPs so the
        // per-IP route throttle is never the thing that refuses us.
        for ($i = 0; $i <= NewPasswordController::FREE_FAILURES; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i])
                ->from('/reset-password/x')
                ->post('/reset-password', [
                    'token' => 'wrong-'.$i,
                    'email' => $victim->email,
                    'password' => 'attacker-password-12345',
                    'password_confirmation' => 'attacker-password-12345',
                ]);
        }

        // ...then present the CORRECT code to the sibling endpoint.
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.240'])
            ->from('/forgot-password')
            ->post('/reset-password/code', [
                'email' => $victim->email,
                'code' => '424242',
                'password' => 'attacker-password-12345',
                'password_confirmation' => 'attacker-password-12345',
            ])->assertStatus(429);

        $this->assertFalse(
            Hash::check('attacker-password-12345', $victim->fresh()->password),
            'HOLE OPEN: the budget spent on one reset endpoint did not apply to its sibling'
        );
    }

    /**
     * 200 wrong codes from 40 addresses, then the right one. The number of
     * guesses an attacker gets must be a property of the ACCOUNT, not of how
     * many source addresses they can rent.
     */
    public function test_the_slack_code_cannot_be_brute_forced_by_rotating_ip(): void
    {
        $victim = $this->owner('Brute Corp', 'brute-fix@corp.test');
        $this->issueCode($victim);

        $processed = 0;

        for ($ip = 0; $ip < 40; $ip++) {
            for ($n = 0; $n < 5; $n++) {
                $status = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$ip])
                    ->from('/forgot-password')
                    ->post('/reset-password/code', [
                        'email' => $victim->email,
                        'code' => str_pad((string) ($ip * 5 + $n), 6, '0', STR_PAD_LEFT),
                        'password' => 'attacker-password-12345',
                        'password_confirmation' => 'attacker-password-12345',
                    ])->getStatusCode();

                if ($status !== 429) {
                    $processed++;
                }
            }
        }

        // The free budget, and not one guess more.
        $this->assertLessThanOrEqual(
            NewPasswordController::FREE_FAILURES + 1,
            $processed,
            "{$processed} wrong guesses were processed against one account by rotating IP"
        );

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.250'])
            ->from('/forgot-password')
            ->post('/reset-password/code', [
                'email' => $victim->email,
                'code' => '424242',
                'password' => 'attacker-password-12345',
                'password_confirmation' => 'attacker-password-12345',
            ]);

        $this->assertFalse(
            Hash::check('attacker-password-12345', $victim->fresh()->password),
            'HOLE OPEN: account taken over via brute-forced Slack code'
        );
    }

    /**
     * The key is a function of the RESOLVED ACCOUNT and of nothing else about
     * the submitted string.
     *
     * This is the collation defence stated as a property. `sha1(strtolower())`
     * over the submitted address disagreed with MySQL's utf8mb4_unicode_ci
     * (which folds case AND accents AND width) so four spellings of one account
     * meant four separate budgets — and SQLite could never show it.
     */
    public function test_the_budget_key_is_derived_from_the_resolved_account(): void
    {
        $a = $this->owner('Key A Corp', 'key-a@corp.test');
        $b = $this->owner('Key B Corp', 'key-b@corp.test');

        $spellings = [
            $a->email,
            strtoupper($a->email),
            'kéy-a@corp.test',
            "\u{FF4B}".'ey-a@corp.test',
            "\u{00A0}".' key-a@corp.test ',
            'something-else-entirely@corp.test',
        ];

        foreach ($spellings as $spelling) {
            $this->assertSame(
                'acct:'.$a->getKey(),
                NewPasswordController::accountScope($a, $spelling),
                "the submitted spelling '{$spelling}' changed the budget bucket for a resolved account"
            );
        }

        // Different accounts still get different budgets.
        $this->assertNotSame(
            NewPasswordController::accountScope($a, $a->email),
            NewPasswordController::accountScope($b, $b->email)
        );

        // And an unresolvable address is keyed on the address ALONE — never on
        // the IP — so it throttles exactly like a real account and cannot be
        // used to ask "does this person have an account here?".
        $this->assertSame(
            NewPasswordController::accountScope(null, 'Nobody@Corp.Test'),
            NewPasswordController::accountScope(null, ' nobody@corp.test ')
        );
    }

    // =====================================================================
    // (4d) The lockout must not be a weapon against the real owner
    // =====================================================================

    /**
     * An attacker burning the free budget must not stop the genuine owner using
     * the genuine link from their own inbox. Only FAILURES cost budget, so a
     * request carrying a valid token is never refused on account of the
     * attempts that came before it.
     */
    public function test_an_attackers_failed_guesses_do_not_lock_the_owner_out(): void
    {
        Notification::fake();
        $victim = $this->owner('Denial Corp', 'denial-fix@corp.test');

        $this->post('/forgot-password', ['email' => $victim->email]);

        $realToken = null;
        Notification::assertSentTo($victim, ResetPassword::class, function (ResetPassword $n) use (&$realToken) {
            $realToken = $n->token;

            return true;
        });

        for ($i = 0; $i < NewPasswordController::FREE_FAILURES; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$i])
                ->from('/reset-password/x')
                ->post('/reset-password', [
                    'token' => 'garbage-'.$i,
                    'email' => $victim->email,
                    'password' => 'attacker-password-12345',
                    'password_confirmation' => 'attacker-password-12345',
                ]);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.18.0.9'])
            ->from('/reset-password/'.$realToken)
            ->post('/reset-password', [
                'token' => $realToken,
                'email' => $victim->email,
                'password' => 'my-own-new-password-1',
                'password_confirmation' => 'my-own-new-password-1',
            ]);

        $this->assertTrue(
            Hash::check('my-own-new-password-1', $victim->fresh()->password),
            'an anonymous attacker denied the real owner their own password reset'
        );
    }

    /**
     * Past the free budget the account is SLOWED, never shut. The cooldown is
     * capped, so however long an attacker keeps guessing the owner is never
     * more than a minute away from resetting their own password.
     */
    public function test_the_cooldown_escalates_but_is_capped_so_the_owner_always_gets_back_in(): void
    {
        $victim = $this->owner('Escalate Corp', 'escalate@corp.test');
        $this->issueCode($victim, '135790');

        // Far past the free budget — the attacker keeps going for a long time.
        for ($i = 0; $i < 40; $i++) {
            NewPasswordController::penaliseFailedReset($victim, $victim->email);
        }

        $remaining = RateLimiter::availableIn('pwreset-cool:acct:'.$victim->getKey());

        $this->assertGreaterThan(0, $remaining, 'no cooldown was armed at all');
        $this->assertLessThanOrEqual(
            NewPasswordController::COOLDOWN_MAX_SECONDS,
            $remaining,
            'the cooldown grew past its cap — that is a denial-of-service primitive, not a rate limit'
        );

        // Blocked right now...
        $this->from('/forgot-password')->post('/reset-password/code', [
            'email' => $victim->email,
            'code' => '135790',
            'password' => 'owner-chosen-password-9',
            'password_confirmation' => 'owner-chosen-password-9',
        ])->assertStatus(429);

        // ...and back in a minute later, with the code from their own Slack.
        $this->travel(NewPasswordController::COOLDOWN_MAX_SECONDS + 1)->seconds();

        $this->post('/reset-password/code', [
            'email' => $victim->email,
            'code' => '135790',
            'password' => 'owner-chosen-password-9',
            'password_confirmation' => 'owner-chosen-password-9',
        ])->assertRedirect(route('login'));

        $this->assertTrue(
            Hash::check('owner-chosen-password-9', $victim->fresh()->password),
            'the owner could not reset their password even after the cooldown expired'
        );
    }

    /** A successful reset wipes the slate rather than leaving the owner marked. */
    public function test_a_successful_reset_clears_the_accounts_failure_record(): void
    {
        $victim = $this->owner('Clear Corp', 'clear@corp.test');
        $this->issueCode($victim, '246800');

        for ($i = 0; $i < 3; $i++) {
            $this->from('/forgot-password')->post('/reset-password/code', [
                'email' => $victim->email,
                'code' => '111111',
                'password' => 'owner-chosen-password-9',
                'password_confirmation' => 'owner-chosen-password-9',
            ]);
        }

        $this->assertGreaterThan(0, RateLimiter::attempts('pwreset-fail:acct:'.$victim->getKey()));

        $this->post('/reset-password/code', [
            'email' => $victim->email,
            'code' => '246800',
            'password' => 'owner-chosen-password-9',
            'password_confirmation' => 'owner-chosen-password-9',
        ])->assertRedirect(route('login'));

        $this->assertSame(0, RateLimiter::attempts('pwreset-fail:acct:'.$victim->getKey()),
            'failed guesses survived a genuine reset and would follow the owner into the next one');
    }

    // =====================================================================
    // (2) The request side — code sends are capped per account too
    // =====================================================================

    public function test_reset_code_sends_are_capped_per_account_across_rotating_ips(): void
    {
        Http::fake(fn () => Http::response(['ok' => true], 200));

        $victim = $this->owner('Spam Corp', 'spam-fix@corp.test');

        app(CurrentOrganization::class)->runFor(
            $victim->organization_id,
            fn () => MonitoringSetting::current()->update(['slack_bot_token' => 'xoxb-TENANT'])
        );
        app(CurrentOrganization::class)->forget();

        $accepted = 0;

        for ($ip = 0; $ip < 12; $ip++) {
            for ($n = 0; $n < 5; $n++) {
                $status = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$ip])
                    ->post('/forgot-password/code', ['email' => $victim->email])
                    ->getStatusCode();

                if ($status !== 429) {
                    $accepted++;
                }
            }
        }

        $this->assertSame(
            PasswordResetCodeController::MAX_CODES_PER_WINDOW,
            $accepted,
            "{$accepted} reset-code DMs could be triggered at one victim by rotating IP"
        );
    }

    /**
     * The send cap must behave IDENTICALLY for an address that has no account,
     * or it becomes an account-existence oracle for any address on earth.
     */
    public function test_the_send_cap_looks_the_same_for_an_unknown_address(): void
    {
        Http::fake(fn () => Http::response(['ok' => true], 200));

        $victim = $this->owner('Oracle Corp', 'oracle@corp.test');

        app(CurrentOrganization::class)->runFor(
            $victim->organization_id,
            fn () => MonitoringSetting::current()->update(['slack_bot_token' => 'xoxb-TENANT'])
        );
        app(CurrentOrganization::class)->forget();

        $probe = function (string $email): array {
            $codes = [];

            for ($ip = 0; $ip < 8; $ip++) {
                $codes[] = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$ip])
                    ->post('/forgot-password/code', ['email' => $email])
                    ->getStatusCode();
            }

            return $codes;
        };

        $known = $probe($victim->email);
        $unknown = $probe('definitely-nobody@oracle.test');

        $this->assertSame($known, $unknown,
            'a real address is throttled differently from an unknown one — that answers "does this person use Staffvora?"');
    }
}
