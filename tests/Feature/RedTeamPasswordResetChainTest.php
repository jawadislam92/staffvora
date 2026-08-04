<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Middleware\TrustHosts;
use App\Http\Middleware\TrustProxies;
use App\Models\MonitoringSetting;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * THE PASSWORD-RESET CHAIN, FROM THE ATTACKER'S SIDE — NOW ASSERTING DEFENCE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Every request sequence in this file was written by a red team as an ATTACK:
 * a PASS originally meant the bypass landed. The bypasses were closed, so each
 * test now demands the SECURE outcome instead, with the payload and the request
 * order left exactly as they were. Keep them that way — the value here is that
 * these are attacks somebody actually got to work, not attacks somebody
 * imagined.
 *
 * What is covered, and what each one caught:
 *
 *  (a) HOST-HEADER INJECTION into the emailed reset link. Prefix/suffix/
 *      lookalike hosts, X-Forwarded-Host, and the one that actually worked:
 *      X-Forwarded-Prefix. TrustHosts only ever validated getHost(), while
 *      Laravel's root URL is getSchemeAndHttpHost() . getBaseUrl() — and
 *      getBaseUrl() prepended X-Forwarded-Prefix with no validation at all, so
 *      a prefix of "@evil.test" turned the real host into URL *userinfo* and
 *      the emailed link resolved to the attacker.
 *
 *  (b) BRUTE FORCE. The 6-digit Slack reset code had its lockout keyed on
 *      email|IP, so rotating the source address reset both the route throttle
 *      and the lockout. 200 guesses against a 6-digit secret, then the real one.
 *
 *  (c) LOCKOUT-KEY DERIVATION, and the systemic trap underneath it: the suite
 *      runs SQLite (BINARY) and production runs MySQL utf8mb4_unicode_ci, which
 *      folds case AND accents AND width. Any guard that derives its key by
 *      normalising the submitted string in PHP is holding a second opinion about
 *      string equality that this suite can never referee. The fix does not
 *      normalise at all — it keys on the account the DATABASE resolved. See
 *      test_no_email_spelling_can_buy_a_fresh_lockout_budget.
 *
 *  (d) COLLATERAL. TrustHosts must not refuse infrastructure traffic, and an
 *      anonymous attacker must not be able to lock a real owner out of their own
 *      reset. Both of those were live regressions of earlier hardening rounds.
 *
 * Companion file: tests/Feature/PasswordResetTakeoverHardeningTest.php holds the
 * fixer's defence tests for the same ground. Overlap is deliberate.
 */
class RedTeamPasswordResetChainTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        SymfonyRequest::setTrustedHosts([]);
        SymfonyRequest::setTrustedProxies([], SymfonyRequest::HEADER_X_FORWARDED_FOR);
        TrustProxies::flushState();
        unset($_SERVER['TRUSTED_PROXIES'], $_SERVER['SERVER_ADDR'], $_SERVER['TRUSTED_HOSTS_EXTRA']);
        parent::tearDown();
    }

    /** Arm exactly what TrustHosts arms in production. */
    private function armTrustedHosts(): void
    {
        SymfonyRequest::setTrustedHosts(
            array_filter((new TrustHosts($this->app))->hosts())
        );
    }

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

    /** The actionUrl of the reset mail sent to $user, or null. */
    private function emailedResetUrl(User $user): ?string
    {
        $captured = null;

        foreach (Notification::sent($user, ResetPassword::class) as $notification) {
            $captured = (string) $notification->toMail($user)->actionUrl;
        }

        return $captured;
    }

    // =====================================================================
    // (a) HOST-HEADER INJECTION
    // =====================================================================

    /**
     * ORIGINAL HOLE: no host validation at all, so any Host header the attacker
     * chose became the host of the emailed reset link.
     *
     * THE FIX: TrustHosts is an allow-list of APP_URL (+ subdomains) and the
     * operator console host, and it FAILS CLOSED — Symfony treats an empty
     * trusted-host list as "no validation", so an unparseable APP_URL used to
     * turn the whole protection off silently. It now substitutes a pattern that
     * can never match and logs a critical instead.
     *
     * Every spelling below is a real bypass shape: append the app host to your
     * own domain, prepend to it, dash-prefix it, or just ask for an unrelated
     * one. `subdomain` is in the list on purpose — it is legitimately trusted,
     * and its presence is what proves the refusals are selective rather than
     * "everything 400s".
     */
    public function test_untrusted_host_variants_are_refused_by_trusted_hosts(): void
    {
        $this->armTrustedHosts();
        $app = $this->appHost();               // staffvora.test

        $candidates = [
            'suffix' => $app.'.evil.test',
            'prefix' => 'evil'.$app,
            'prefix-dash' => 'evil-'.$app,
            'unrelated' => 'attacker.example',
            'userinfo' => 'evil.test',
            'trailing-dot' => $app.'.',
            'upper' => strtoupper($app),
            'subdomain' => 'anything.'.$app,   // legitimately trusted
        ];

        $served = [];
        foreach ($candidates as $label => $host) {
            $served[$label] = $this->post('http://'.$host.'/forgot-password', ['email' => 'nobody@corp.test'])
                ->getStatusCode();
        }

        foreach (['suffix', 'prefix', 'prefix-dash', 'unrelated', 'userinfo'] as $bad) {
            $this->assertContains($served[$bad], [400, 404],
                "host variant '{$bad}' ({$candidates[$bad]}) was SERVED (status {$served[$bad]}) — trusted hosts bypassed");
        }

        // ...and the allow-list is an allow-list, not a blanket refusal. If this
        // ever starts failing, the five assertions above have stopped meaning
        // anything: they would be passing because nothing is served at all.
        $this->assertNotContains($served['subdomain'], [400],
            'a legitimately trusted subdomain is being refused — the host list is over-tight');
    }

    /**
     * ORIGINAL HOLE: forwarded-host headers steering the emailed link from
     * behind a trusted proxy.
     *
     * THE FIX: a forwarded host is still run through the trusted-host patterns,
     * so it buys nothing the Host header would not have bought. Both halves are
     * asserted — the request is refused AND, separately, no reset link carrying
     * the attacker host was ever produced. The second assertion is the one that
     * matters: a 400 that still queued a poisoned mail would be no fix at all.
     */
    public function test_forwarded_host_headers_cannot_poison_the_reset_link(): void
    {
        $_SERVER['TRUSTED_PROXIES'] = '127.0.0.1';
        $this->armTrustedHosts();

        Notification::fake();
        $owner = $this->owner('XFH Corp', 'xfh@corp.test');

        $codes = [];
        foreach (['X-Forwarded-Host', 'X-Original-Host', 'X-Forwarded-Server', 'X-Host'] as $header) {
            $codes[$header] = $this->withHeaders([$header => 'evil.test'])
                ->post('/forgot-password', ['email' => $owner->email])
                ->getStatusCode();
        }

        $leaked = null;
        foreach (Notification::sent($owner, ResetPassword::class) as $n) {
            $url = (string) $n->toMail($owner)->actionUrl;
            if (str_contains($url, 'evil.test')) {
                $leaked = $url;
            }
        }

        $this->assertNull($leaked, "a forwarded-host header poisoned the reset link: {$leaked}");

        foreach ($codes as $header => $code) {
            $this->assertContains($code, [400, 404], "{$header} was served instead of refused");
        }
    }

    /**
     * ⭐ THE ONE THAT WORKED. ORIGINAL HOLE, LIVE:
     *
     * TrustHosts validated getHost(). Laravel's root URL is
     * getSchemeAndHttpHost() . getBaseUrl(), and getBaseUrl() blindly prepends
     * the X-Forwarded-Prefix header when the peer is a trusted proxy — no
     * validation whatsoever. A prefix of "@evil.test" turns the real host into
     * URL *userinfo*, so the emailed link's EFFECTIVE host is the attacker's:
     *
     *     http://staffvora.test@evil.test/reset-password/<token>?email=…
     *
     * The victim clicks a link that visibly contains the right domain, and hands
     * their reset token to evil.test. TrustProxies had just started honouring
     * HEADER_X_FORWARDED_PREFIX, which is what made this reachable.
     *
     * THE FIX: TrustProxies no longer trusts that header, and it is stripped
     * from the request, so nothing can concatenate it into a root URL. See
     * PasswordResetTakeoverHardeningTest for the middleware-level assertions.
     */
    public function test_x_forwarded_prefix_userinfo_cannot_hijack_the_reset_link(): void
    {
        $_SERVER['TRUSTED_PROXIES'] = '127.0.0.1';
        $this->armTrustedHosts();

        Notification::fake();
        $owner = $this->owner('Prefix Corp', 'prefix@corp.test');

        $this->withHeaders(['X-Forwarded-Prefix' => '@evil.test'])
            ->post('/forgot-password', ['email' => $owner->email]);

        $captured = $this->emailedResetUrl($owner);

        $this->assertNotNull($captured, 'no reset mail was produced — this test would prove nothing');

        // The effective host of the emitted URL, which is what the browser
        // resolves — userinfo and all.
        $this->assertSame($this->appHost(), parse_url($captured, PHP_URL_HOST),
            "HOLE OPEN: emitted reset URL resolves to an attacker host: {$captured}");
        $this->assertStringNotContainsString('evil.test', $captured);
        $this->assertStringNotContainsString('@', (string) parse_url($captured, PHP_URL_PATH));
    }

    /**
     * The same defect, protocol-relative form. This spelling never handed over
     * the host on its own (it yields the nonsense host "staffvora.testhttps"),
     * but it is the clearest proof of the underlying property: the prefix was
     * concatenated RAW into the root URL. That property is what the test pins,
     * because a fix that only special-cases "@" would leave the next spelling
     * of the same defect open.
     */
    public function test_x_forwarded_prefix_is_never_concatenated_into_the_root_url(): void
    {
        $_SERVER['TRUSTED_PROXIES'] = '127.0.0.1';
        $this->armTrustedHosts();

        Notification::fake();
        $owner = $this->owner('Prefix2 Corp', 'prefix2@corp.test');

        $this->withHeaders(['X-Forwarded-Prefix' => 'https://evil.test/x'])
            ->post('/forgot-password', ['email' => $owner->email]);

        $captured = $this->emailedResetUrl($owner);

        $this->assertNotNull($captured, 'no reset mail was produced — this test would prove nothing');
        $this->assertStringNotContainsString('evil.test', $captured,
            "X-Forwarded-Prefix is concatenated unvalidated into the emailed root URL: {$captured}");
        $this->assertSame($this->appHost(), parse_url($captured, PHP_URL_HOST));
    }

    /**
     * Host with an attacker-chosen PORT: `Host: staffvora.test:1337`.
     *
     * Symfony's host validation compares the hostname, not the port, so this
     * request is served and the port does ride into the emitted URL. That is
     * acceptable — the link still points at OUR hostname, and an attacker
     * cannot receive traffic on a port of a domain they do not control — but
     * "acceptable" has to be asserted rather than assumed, because the previous
     * version of this test asserted nothing at all (`assertTrue(true)`), which
     * is indistinguishable from no test.
     *
     * What must hold: whatever the port, the HOST of the emailed link is always
     * the application's own host.
     */
    public function test_a_port_in_the_host_header_cannot_change_the_emailed_host(): void
    {
        $this->armTrustedHosts();

        Notification::fake();
        $owner = $this->owner('Port Corp', 'port@corp.test');

        $this->post('http://'.$this->appHost().':1337/forgot-password', ['email' => $owner->email]);

        $captured = $this->emailedResetUrl($owner);

        $this->assertNotNull($captured, 'no reset mail was produced — this test would prove nothing');
        $this->assertSame($this->appHost(), parse_url($captured, PHP_URL_HOST),
            "a port in the Host header changed the host of the emailed link: {$captured}");
        $this->assertStringStartsWith('/reset-password/', (string) parse_url($captured, PHP_URL_PATH));
    }

    // =====================================================================
    // (d) COLLATERAL — what did TrustHosts break?
    // =====================================================================

    /**
     * COLLATERAL, and a live regression of the first hardening round: TrustHosts
     * started refusing every infrastructure host with a 400 — uptime probes that
     * hit the origin by IP, nginx upstream checks sending `Host: localhost`,
     * local cron curl on 127.0.0.1. Production happens to have zero such traffic
     * today, which is the only reason nobody noticed. A monitoring product whose
     * own health checks 400 is not a small bug.
     *
     * THE FIX: infrastructure hosts are served, AND every absolute URL generated
     * during such a request is pinned to config('app.url') so a link built on
     * `localhost` can never be emailed.
     */
    public function test_operator_console_and_infrastructure_hosts_are_still_served(): void
    {
        $_SERVER['SERVER_ADDR'] = '203.0.113.10';
        $this->armTrustedHosts();

        $probes = [
            'platform console' => (string) config('platform.host'),
            'www' => 'www.'.$this->appHost(),
            'apex' => $this->appHost(),
            'origin by IPv4' => '203.0.113.10',
            'localhost' => 'localhost',
            'loopback ip' => '127.0.0.1',
        ];

        foreach ($probes as $label => $host) {
            $this->assertNotContains(
                $this->get('http://'.$host.'/login')->getStatusCode(),
                [400, 404],
                "{$label} ({$host}) is refused by TrustHosts — infrastructure traffic is broken",
            );
        }

        // Still an allow-list: an address nobody declared stays refused.
        $this->assertContains(
            $this->get('http://198.51.100.99/login')->getStatusCode(),
            [400, 404],
            'an undeclared host was served — the allow-list has been widened too far',
        );
    }

    /**
     * COLLATERAL: a request that carries NO Host header at all (HTTP/1.0 probes,
     * some load-balancer health checks). The first hardening round refused these.
     *
     * The previous version of this test ended in `assertTrue(true)` and printed
     * the result to stderr — a test that cannot fail is worse than no test,
     * because it looks like coverage. It now asserts the outcome.
     */
    public function test_a_request_with_no_host_header_is_served_not_refused(): void
    {
        $this->armTrustedHosts();

        $server = $this->transformHeadersToServerVars([]);
        unset($server['HTTP_HOST']);
        $server['SERVER_NAME'] = 'localhost';
        $server['REQUEST_METHOD'] = 'GET';
        $server['REQUEST_URI'] = '/login';

        $request = SymfonyRequest::create('http://localhost/login', 'GET', [], [], [], $server);
        $request->headers->remove('HOST');

        $response = $this->app->make(Kernel::class)
            ->handle(Request::createFromBase($request));

        $this->assertNotSame(400, $response->getStatusCode(),
            'a request with no Host header is refused — HTTP/1.0 health probes are broken');
        $this->assertSame(200, $response->getStatusCode(),
            'the login page did not render for a Host-less probe');
    }

    /**
     * COLLATERAL: the desktop agent's API must keep working on the hosts the
     * operator declared, and must NOT be reachable on a host nobody declared —
     * an unauthenticated 401 means the route was reached, a 400 means the host
     * was refused before routing.
     *
     * Also previously an `assertTrue(true)`.
     */
    public function test_the_desktop_api_is_reachable_on_declared_hosts_only(): void
    {
        $_SERVER['SERVER_ADDR'] = '203.0.113.10';
        $this->armTrustedHosts();

        foreach ([$this->appHost(), 'localhost', '203.0.113.10'] as $host) {
            $this->assertSame(401, $this->getJson('http://'.$host.'/api/desktop/me')->getStatusCode(),
                "the desktop API is not reachable on {$host} — the agent would stop reporting");
        }

        $this->assertSame(400, $this->getJson('http://198.51.100.99/api/desktop/me')->getStatusCode(),
            'the desktop API answered on a host nobody declared');
    }

    // =====================================================================
    // (b)/(c) THROTTLE + BRUTE FORCE
    // =====================================================================

    /**
     * ORIGINAL HOLE (live): the Slack-code verify endpoint keyed its lockout on
     * email|IP, and the route throttle keyed on IP too. Rotate the source
     * address and BOTH caps reset — so a 6-digit secret had no account-wide
     * budget at all. 200 wrong guesses from 40 addresses, then the real code
     * from a fresh one, and the account was taken over.
     *
     * THE FIX: the budget is keyed on the RESOLVED ACCOUNT
     * (NewPasswordController::accountScope), not on the address, so every
     * spelling and every source IP shares one budget.
     */
    public function test_the_slack_reset_code_cannot_be_brute_forced_by_rotating_ip(): void
    {
        $victim = $this->owner('Brute Corp', 'brute-code@corp.test');

        $code = '424242';
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $victim->email],
            ['token' => Hash::make($code), 'created_at' => now()]
        );

        $wrongAccepted = 0;
        for ($ip = 0; $ip < 40; $ip++) {
            for ($n = 0; $n < 5; $n++) {
                $r = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$ip])
                    ->from('/forgot-password')
                    ->post('/reset-password/code', [
                        'email' => $victim->email,
                        'code' => str_pad((string) ($ip * 5 + $n), 6, '0', STR_PAD_LEFT),
                        'password' => 'attacker-password-12345',
                        'password_confirmation' => 'attacker-password-12345',
                    ]);

                if ($r->getStatusCode() !== 429) {
                    $wrongAccepted++;
                }
            }
        }

        // The real guess, from a fresh address, after the budget is spent.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.250'])
            ->from('/forgot-password')
            ->post('/reset-password/code', [
                'email' => $victim->email,
                'code' => $code,
                'password' => 'attacker-password-12345',
                'password_confirmation' => 'attacker-password-12345',
            ]);

        $this->assertLessThan(50, $wrongAccepted,
            "{$wrongAccepted} wrong 6-digit guesses were processed against one account by rotating IP");
        $this->assertFalse(Hash::check('attacker-password-12345', $victim->fresh()->password),
            'account taken over via brute-forced Slack code');
    }

    /**
     * The same rotation against the token endpoint. Both endpoints have to share
     * one account budget — the original finding was that they did not, so the
     * weaker sibling could be used to spend guesses the other one was counting.
     */
    public function test_the_token_endpoint_cannot_be_brute_forced_by_rotating_ip(): void
    {
        $victim = $this->owner('Brute2 Corp', 'brute-token@corp.test');

        $code = '424242';
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $victim->email],
            ['token' => Hash::make($code), 'created_at' => now()]
        );

        $processed = 0;
        for ($ip = 0; $ip < 10; $ip++) {
            for ($n = 0; $n < 5; $n++) {
                $r = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$ip])
                    ->from('/reset-password/x')
                    ->post('/reset-password', [
                        'token' => str_pad((string) ($ip * 5 + $n), 6, '0', STR_PAD_LEFT),
                        'email' => $victim->email,
                        'password' => 'attacker-password-12345',
                        'password_confirmation' => 'attacker-password-12345',
                    ]);
                if ($r->getStatusCode() !== 429) {
                    $processed++;
                }
            }
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.250'])
            ->from('/reset-password/x')
            ->post('/reset-password', [
                'token' => $code,
                'email' => $victim->email,
                'password' => 'attacker-password-12345',
                'password_confirmation' => 'attacker-password-12345',
            ]);

        $this->assertLessThan(50, $processed,
            "{$processed} wrong tokens were processed against one account by rotating IP");
        $this->assertFalse(Hash::check('attacker-password-12345', $victim->fresh()->password),
            'token endpoint brute-forced by rotating IP');
    }

    /**
     * ═══ THE SYSTEMIC ONE. READ THIS BEFORE CHANGING THE LOCKOUT KEY. ═══
     *
     * ORIGINAL HOLE: the per-email lockout key was sha1(strtolower(trim($email)))
     * but the USER LOOKUP is a plain `where email = ?`, i.e. it is decided by
     * the DATABASE COLLATION, not by that PHP normalisation. Any string the DB
     * treats as equal but PHP does not lands in a DIFFERENT bucket — and each
     * variant is a fresh budget of guesses against the same account.
     *
     * THE SUITE CANNOT REFEREE THAT DISAGREEMENT. Tests run on SQLite (BINARY:
     * nothing folds); production runs the `mysql` connection, pinned to
     * utf8mb4_unicode_ci, a UCA collation that is case-insensitive AND
     * accent-insensitive AND width-insensitive. Verified directly on MySQL 8.4:
     *
     *     utf8mb4_unicode_ci -> 'user1@x' = 'usér1@x' = 'úser1@x' = 'ｕser1@x'
     *
     * So on production every one of those resolves to the SAME user, the token
     * row is looked up by the CANONICAL address
     * (DatabaseTokenRepository::exists uses $user->getEmailForPasswordReset()),
     * and yet the guard had bucketed them apart. A test written against SQLite
     * would have looked airtight and proved nothing.
     *
     * THE FIX IS NOT A CLEVERER NORMALISER. Any normaliser is a second opinion
     * about string equality that can disagree with the collation, and no test on
     * this connection can catch the disagreement. The key is instead derived
     * from the account the DATABASE RESOLVED — NewPasswordController::
     * accountScope() returns 'acct:<id>' — so the guard and the lookup are the
     * same comparison by construction. Whatever the collation folds together
     * shares one budget, on MySQL, SQLite or anything else.
     *
     * WHAT IS ASSERTED HERE is therefore the collation-INDEPENDENT property: the
     * key is a function of the resolved account and of nothing else about the
     * submitted string. That holds regardless of which strings a given collation
     * folds, which is exactly why it is safe to assert on SQLite.
     */
    public function test_no_email_spelling_can_buy_a_fresh_lockout_budget(): void
    {
        $this->assertSame(
            'utf8mb4_unicode_ci',
            config('database.connections.mysql.collation'),
            'production collation changed — re-check this finding against the new one'
        );

        $victim = $this->owner('Key Corp', 'user1@big-org.test');
        $canonical = $victim->email;

        // Every spelling MySQL folds onto this account under utf8mb4_unicode_ci
        // (measured on MySQL 8.4), plus the ASCII case variants SQLite folds,
        // plus the shapes a PHP normaliser would have bucketed apart.
        $variants = [
            'canonical' => $canonical,
            'upper' => strtoupper($canonical),
            'mixed' => 'User1@Big-Org.Test',
            'nbsp-lead' => "\u{00A0}".$canonical,
            'accent-e' => 'us'."\u{00E9}".'r1@big-org.test',   // usér1@
            'accent-u' => "\u{00FA}".'ser1@big-org.test',      // úser1@
            'fullwidth-u' => "\u{FF55}".'ser1@big-org.test',   // ｕser1@
            'turkish-i' => 'user1@b'."\u{0131}".'g-org.test',
        ];

        $canonicalScope = NewPasswordController::accountScope($victim, $canonical);

        $this->assertSame('acct:'.$victim->getKey(), $canonicalScope,
            'the reset budget is not keyed on the resolved account id — it is back to normalising a string');

        foreach ($variants as $label => $variant) {
            // Whatever string got us here, if the DB resolved it to this account
            // it lands in this account's bucket. No spelling buys a fresh budget.
            $this->assertSame(
                $canonicalScope,
                NewPasswordController::accountScope($victim, $variant),
                "variant '{$label}' resolves to the same account but a different lockout bucket"
            );
        }

        // The PHP normalisation that USED to derive the key would have split
        // these apart. Asserting that explicitly is what stops someone
        // reintroducing it as a "simplification".
        $phpBuckets = array_unique(array_map(
            fn (string $e): string => sha1(strtolower(trim($e))),
            $variants,
        ));

        $this->assertGreaterThan(1, count($phpBuckets),
            'these variants no longer differ under PHP normalisation, so this test has stopped '
            .'demonstrating why the key may not be derived from the submitted string');

        // And an address that resolves to NOTHING is keyed on the address alone
        // — never on the IP — so an unknown address is throttled exactly like a
        // known one and cannot be used as an existence oracle.
        $unknown = NewPasswordController::accountScope(null, 'nobody@big-org.test');

        $this->assertStringStartsWith('addr:', $unknown);
        $this->assertNotSame($canonicalScope, $unknown);
    }

    /**
     * COLLATERAL: five bogus posts used to consume the whole per-address budget
     * for ten minutes, so an anonymous attacker could deny a real user their own
     * password reset — renewably, from rotating IPs. Locking the owner out is
     * the attack, not the defence.
     *
     * THE FIX: a failed-guess budget that does not refuse the holder of a
     * genuine token. The sequence below is the original one, unchanged: the real
     * user requests a link, the attacker burns the budget from five different
     * addresses, and then the real user tries their real token.
     */
    public function test_an_attacker_cannot_lock_the_owner_out_of_their_own_reset(): void
    {
        Notification::fake();
        $victim = $this->owner('Denial Corp', 'denial@corp.test');

        $this->post('/forgot-password', ['email' => $victim->email]);

        $realToken = null;
        Notification::assertSentTo($victim, ResetPassword::class, function (ResetPassword $n) use (&$realToken) {
            $realToken = $n->token;

            return true;
        });

        $this->assertNotNull($realToken, 'no reset token was issued — this test would prove nothing');

        for ($i = 0; $i < 5; $i++) {
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

        $this->assertTrue(Hash::check('my-own-new-password-1', $victim->fresh()->password),
            'an anonymous attacker locked the real owner out of their own password reset');
    }

    /**
     * COLLATERAL/ABUSE: /forgot-password/code kept its request budget on
     * email|IP, so a rotating attacker could spam a victim's Slack DMs
     * indefinitely AND keep overwriting the stored code — which also denies the
     * victim the code they are looking at.
     *
     * THE FIX: the send budget is per ACCOUNT across all addresses. The
     * assertion is a bound rather than an exact figure because the figure is a
     * tuning knob; what must hold is that 60 attempts from 12 addresses cannot
     * become 60 DMs.
     */
    public function test_reset_code_dms_cannot_be_spammed_by_rotating_ip(): void
    {
        Http::fake(fn () => Http::response(['ok' => true], 200));

        $victim = $this->owner('Spam Corp', 'spam-code@corp.test');
        app(CurrentOrganization::class)->runFor($victim->organization_id, function () {
            MonitoringSetting::current()->update(['slack_bot_token' => 'xoxb-TENANT']);
        });
        app(CurrentOrganization::class)->forget();

        $accepted = 0;
        for ($ip = 0; $ip < 12; $ip++) {
            for ($n = 0; $n < 5; $n++) {
                $r = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$ip])
                    ->post('/forgot-password/code', ['email' => $victim->email]);
                if ($r->getStatusCode() !== 429) {
                    $accepted++;
                }
            }
        }

        $this->assertLessThan(20, $accepted,
            "{$accepted} of 60 reset-code DMs could be triggered at one victim by rotating IP");
    }
}
