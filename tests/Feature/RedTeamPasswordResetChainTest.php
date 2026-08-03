<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustHosts;
use App\Http\Middleware\TrustProxies;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Tests\TestCase;

/**
 * RED TEAM — independent re-attack of the password-reset chain.
 * Nothing here is a fix; every method is an attempt to get around the guards
 * that were just installed.
 */
class RedTeamPasswordResetChainTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        SymfonyRequest::setTrustedHosts([]);
        SymfonyRequest::setTrustedProxies([], SymfonyRequest::HEADER_X_FORWARDED_FOR);
        TrustProxies::flushState();
        unset($_SERVER['TRUSTED_PROXIES']);
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

    // =====================================================================
    // (a) HOST-HEADER INJECTION — can I still steer an absolute URL?
    // =====================================================================

    /**
     * Prefix / suffix / lookalike / unrelated-subdomain host variants.
     * All of these SHOULD be refused. Any 2xx/3xx here is a hole.
     */
    public function test_redteam_host_variants_against_trusted_hosts(): void
    {
        $this->armTrustedHosts();
        $app = $this->appHost();               // staffvora.test

        $candidates = [
            'suffix'            => $app.'.evil.test',
            'prefix'            => 'evil'.$app,
            'prefix-dash'       => 'evil-'.$app,
            'unrelated'         => 'attacker.example',
            'userinfo'          => 'evil.test',
            'trailing-dot'      => $app.'.',
            'upper'             => strtoupper($app),
            'subdomain'         => 'anything.'.$app,   // legitimately trusted
        ];

        $served = [];
        foreach ($candidates as $label => $host) {
            $r = $this->post('http://'.$host.'/forgot-password', ['email' => 'nobody@corp.test']);
            $served[$label] = $r->getStatusCode();
        }

        fwrite(STDERR, "\n[HOST VARIANTS] ".json_encode($served)."\n");

        foreach (['suffix', 'prefix', 'prefix-dash', 'unrelated', 'userinfo'] as $bad) {
            $this->assertContains($served[$bad], [400, 404],
                "host variant '{$bad}' ({$candidates[$bad]}) was SERVED (status {$served[$bad]}) — trusted hosts bypassed");
        }
    }

    /**
     * X-Forwarded-Host behind a trusted proxy — does it get past trusted hosts?
     */
    public function test_redteam_x_forwarded_host_behind_trusted_proxy(): void
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

        fwrite(STDERR, "\n[FORWARDED HOST HEADERS] ".json_encode($codes)."\n");

        $leaked = null;
        foreach (Notification::sent($owner, ResetPassword::class) as $n) {
            $url = (string) $n->toMail($owner)->actionUrl;
            if (str_contains($url, 'evil.test')) {
                $leaked = $url;
            }
        }

        $this->assertNull($leaked, "a forwarded-host header poisoned the reset link: {$leaked}");

        // Every one of these was refused outright — the forwarded host is still
        // run through the trusted-host patterns, so this vector is CLOSED.
        foreach ($codes as $header => $code) {
            $this->assertContains($code, [400, 404], "{$header} was served instead of refused");
        }
    }

    /**
     * THE REAL ATTACK: X-Forwarded-Prefix.
     *
     * TrustHosts only validates getHost(). Laravel's root URL is
     * getSchemeAndHttpHost() . getBaseUrl(), and getBaseUrl() blindly prepends
     * the X-Forwarded-Prefix header when the peer is a trusted proxy — with no
     * validation whatsoever. A prefix of "@evil.test" turns the real host into
     * URL *userinfo*, so the emailed link's effective host is the attacker's.
     *
     * TrustProxies was ALSO just changed to start honouring
     * HEADER_X_FORWARDED_PREFIX, which is what makes this reachable.
     */
    public function test_redteam_x_forwarded_prefix_userinfo_hijacks_the_reset_link(): void
    {
        $_SERVER['TRUSTED_PROXIES'] = '127.0.0.1';
        $this->armTrustedHosts();

        Notification::fake();
        $owner = $this->owner('Prefix Corp', 'prefix@corp.test');

        $status = $this->withHeaders(['X-Forwarded-Prefix' => '@evil.test'])
            ->post('/forgot-password', ['email' => $owner->email])
            ->getStatusCode();

        $captured = null;
        Notification::assertSentTo($owner, ResetPassword::class, function (ResetPassword $n) use ($owner, &$captured) {
            $captured = (string) $n->toMail($owner)->actionUrl;

            return true;
        });

        fwrite(STDERR, "\n[X-FORWARDED-PREFIX] status={$status} url={$captured}\n");
        fwrite(STDERR, '[X-FORWARDED-PREFIX] parsed host = '.var_export(parse_url((string) $captured, PHP_URL_HOST), true)."\n");

        $this->assertNotSame('evil.test', parse_url((string) $captured, PHP_URL_HOST),
            "HOLE OPEN: emitted reset URL resolves to an attacker host: {$captured}");
    }

    /** Same trick, protocol-relative form. */
    public function test_redteam_x_forwarded_prefix_protocol_relative(): void
    {
        $_SERVER['TRUSTED_PROXIES'] = '127.0.0.1';
        $this->armTrustedHosts();

        Notification::fake();
        $owner = $this->owner('Prefix2 Corp', 'prefix2@corp.test');

        $this->withHeaders(['X-Forwarded-Prefix' => 'https://evil.test/x'])
            ->post('/forgot-password', ['email' => $owner->email]);

        $captured = null;
        Notification::assertSentTo($owner, ResetPassword::class, function (ResetPassword $n) use ($owner, &$captured) {
            $captured = (string) $n->toMail($owner)->actionUrl;

            return true;
        });

        $host = parse_url((string) $captured, PHP_URL_HOST);

        fwrite(STDERR, "\n[X-FORWARDED-PREFIX ABS] url={$captured} host=".var_export($host, true)."\n");

        // This form does NOT hand over the host (it yields the nonsense host
        // "staffvora.testhttps"), but it proves the prefix is concatenated raw
        // into the root URL with zero validation — the "@" form above is the
        // weaponised version of the same defect.
        $this->assertStringNotContainsString('evil.test', (string) $captured,
            "X-Forwarded-Prefix is concatenated unvalidated into the emailed root URL: {$captured}");
    }

    /** Port trick — Host: staffvora.test:1337 */
    public function test_redteam_port_in_host_header(): void
    {
        $this->armTrustedHosts();

        Notification::fake();
        $owner = $this->owner('Port Corp', 'port@corp.test');

        $status = $this->post('http://'.$this->appHost().':1337/forgot-password', ['email' => $owner->email])
            ->getStatusCode();

        $captured = null;
        Notification::assertSentTo($owner, ResetPassword::class, function (ResetPassword $n) use ($owner, &$captured) {
            $captured = (string) $n->toMail($owner)->actionUrl;

            return true;
        });

        fwrite(STDERR, "\n[PORT TRICK] status={$status} url={$captured}\n");
        $this->assertTrue(true);
    }

    // =====================================================================
    // (d) COLLATERAL — what did TrustHosts break?
    // =====================================================================

    public function test_collateral_operator_console_and_infra_hosts_under_trusted_hosts(): void
    {
        $this->armTrustedHosts();

        $probes = [
            'platform console'  => (string) config('platform.host'),
            'www'               => 'www.'.$this->appHost(),
            'apex'              => $this->appHost(),
            'origin by IPv4'    => '203.0.113.10',
            'localhost'         => 'localhost',
            'loopback ip'       => '127.0.0.1',
        ];

        $out = [];
        foreach ($probes as $label => $host) {
            $out[$label] = $this->get('http://'.$host.'/login')->getStatusCode();
        }

        fwrite(STDERR, "\n[COLLATERAL HOSTS] ".json_encode($out)."\n");

        $this->assertNotContains($out['platform console'], [400, 404],
            'the operator console host is refused by TrustHosts');
        $this->assertNotContains($out['apex'], [400, 404], 'the apex domain is refused');
        $this->assertNotContains($out['www'], [400, 404], 'www is refused');
    }

    /** A request that carries NO Host header at all (HTTP/1.0 probes, some health checks). */
    public function test_collateral_request_with_no_host_header(): void
    {
        $this->armTrustedHosts();

        $server = $this->transformHeadersToServerVars([]);
        unset($server['HTTP_HOST']);
        $server['SERVER_NAME'] = 'localhost';
        $server['REQUEST_METHOD'] = 'GET';
        $server['REQUEST_URI'] = '/up';

        $request = SymfonyRequest::create('http://localhost/up', 'GET', [], [], [], $server);
        $request->headers->remove('HOST');

        try {
            $response = $this->app->make(\Illuminate\Contracts\Http\Kernel::class)
                ->handle(\Illuminate\Http\Request::createFromBase($request));
            $code = $response->getStatusCode();
        } catch (\Throwable $e) {
            $code = get_class($e);
        }

        fwrite(STDERR, "\n[NO HOST HEADER] result=".(is_int($code) ? $code : $code)."\n");
        $this->assertTrue(true);
    }

    /** Does the desktop/Sanctum API survive an unexpected host? */
    public function test_collateral_desktop_api_by_ip(): void
    {
        $this->armTrustedHosts();

        $code = $this->getJson('http://203.0.113.10/api/desktop/ping')->getStatusCode();
        fwrite(STDERR, "\n[DESKTOP API BY IP] {$code}\n");
        $this->assertTrue(true);
    }

    // =====================================================================
    // (b)/(c) THROTTLE + BRUTE FORCE
    // =====================================================================

    /**
     * The Slack-code verify endpoint keys its lockout on email|IP, and the
     * route throttle keys on IP too. Rotate the IP and BOTH caps reset.
     * If the correct code still lands after hundreds of wrong guesses, the
     * 6-digit secret has no account-wide budget at all.
     */
    public function test_redteam_slack_code_bruteforce_by_rotating_ip(): void
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

        // Now the real guess, from a fresh IP.
        $final = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.250'])
            ->from('/forgot-password')
            ->post('/reset-password/code', [
                'email' => $victim->email,
                'code' => $code,
                'password' => 'attacker-password-12345',
                'password_confirmation' => 'attacker-password-12345',
            ]);

        $pwned = Hash::check('attacker-password-12345', $victim->fresh()->password);

        fwrite(STDERR, "\n[SLACK CODE BRUTE] wrong guesses processed={$wrongAccepted} final_status=".$final->getStatusCode()." pwned=".var_export($pwned, true)."\n");

        $this->assertLessThan(50, $wrongAccepted,
            "HOLE OPEN: {$wrongAccepted} wrong 6-digit guesses were processed against one account by rotating IP");
        $this->assertFalse($pwned, 'HOLE OPEN: account taken over via brute-forced Slack code');
    }

    /**
     * Same rotation against the token endpoint, which has the per-EMAIL lockout.
     * This one should hold — proving the sibling endpoint is the weak one.
     */
    public function test_redteam_token_endpoint_bruteforce_by_rotating_ip(): void
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

        $final = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.250'])
            ->from('/reset-password/x')
            ->post('/reset-password', [
                'token' => $code,
                'email' => $victim->email,
                'password' => 'attacker-password-12345',
                'password_confirmation' => 'attacker-password-12345',
            ]);

        $pwned = Hash::check('attacker-password-12345', $victim->fresh()->password);
        fwrite(STDERR, "\n[TOKEN BRUTE ROTATE IP] processed={$processed} final=".$final->getStatusCode()." pwned=".var_export($pwned, true)."\n");

        $this->assertFalse($pwned, 'HOLE OPEN: token endpoint brute-forced by rotating IP');
    }

    /**
     * Per-email lockout evasion. The key is sha1(strtolower(trim($email))) but
     * the USER LOOKUP is a plain `where email = ?`, i.e. it is decided by the
     * DATABASE COLLATION, not by that PHP normalisation. Any string the DB
     * treats as equal but PHP does not lowercases into a *different* bucket.
     *
     * This test enumerates the variants and records, for each, (1) whether the
     * validator accepts it and (2) whether it maps to a distinct throttle key.
     */
    public function test_redteam_per_email_lockout_key_derivation(): void
    {
        $victim = $this->owner('Key Corp', 'user1@big-org.test');
        $canonical = $victim->email;

        $variants = [
            'canonical'     => $canonical,
            'upper'         => strtoupper($canonical),
            'mixed'         => 'User1@Big-Org.Test',
            'plus'          => 'user1+x@big-org.test',
            'nbsp-lead'     => "\u{00A0}user1@big-org.test",
            'accent-e'      => 'us'."\u{00E9}".'r1@big-org.test',   // usér1@
            'accent-u'      => "\u{00FA}".'ser1@big-org.test',      // úser1@
            'fullwidth-u'   => "\u{FF55}".'ser1@big-org.test',      // ｕser1@
            'turkish-i'     => 'user1@b'."\u{0131}".'g-org.test',
        ];

        $rows = [];
        foreach ($variants as $label => $email) {
            $normalised = strtolower(trim((string) $email));
            $rows[$label] = [
                'key' => substr(sha1($normalised), 0, 10),
                'same_key_as_canonical' => sha1($normalised) === sha1(strtolower(trim($canonical))),
                'passes_email_rule' => validator(['email' => $email], ['email' => ['required', 'not_regex:/[\r\n]/', 'email']])->passes(),
                'found_by_db' => (bool) app(CurrentOrganization::class)->runUnscoped(
                    fn () => User::where('email', $email)->first()
                ),
            ];
        }

        fwrite(STDERR, "\n[EMAIL KEY DERIVATION] ".json_encode($rows, JSON_PRETTY_PRINT)."\n");

        // The dangerous combination: accepted by the validator, resolves to the
        // same user in the DB, but lands in a DIFFERENT throttle bucket.
        foreach ($rows as $label => $row) {
            if ($label === 'canonical') {
                continue;
            }
            $this->assertFalse(
                $row['passes_email_rule'] && $row['found_by_db'] && ! $row['same_key_as_canonical'],
                "HOLE OPEN: variant '{$label}' reaches the same account but a different lockout bucket"
            );
        }
    }

    /**
     * The same lockout, evaluated against the PRODUCTION database instead of
     * the SQLite test database.
     *
     * The suite runs on SQLite (BINARY collation), where every unicode variant
     * simply fails to resolve — which is exactly why the fixer's lockout test
     * looks airtight. Production runs the `mysql` connection, which
     * config/database.php pins to utf8mb4_unicode_ci: a UCA collation that is
     * case-insensitive AND accent-insensitive AND width-insensitive.
     *
     * Verified directly against MySQL 8.4:
     *   utf8mb4_unicode_ci -> 'user1@x' = 'usér1@x' = 'úser1@x' = 'ｕser1@x' = TRUE
     *
     * So on prod those variants all resolve to the SAME user
     * (EloquentUserProvider::retrieveByCredentials -> where email = ?), the
     * token row is then looked up by the CANONICAL address
     * (DatabaseTokenRepository::exists uses $user->getEmailForPasswordReset()),
     * yet the lockout bucket is sha1(strtolower(trim($submitted))) — a
     * different bucket for every variant. Each variant = 5 more guesses.
     */
    public function test_redteam_lockout_key_is_not_collation_equivalent(): void
    {
        $this->assertSame(
            'utf8mb4_unicode_ci',
            config('database.connections.mysql.collation'),
            'production collation changed — re-check this finding'
        );

        $canonical = 'user1@big-org.test';
        $normalise = fn (string $e) => sha1(strtolower(trim($e)));

        // Folded to the same value by utf8mb4_unicode_ci (measured on MySQL 8.4).
        $collationEquivalent = [
            'usér1@big-org.test',
            'úser1@big-org.test',
            "\u{FF55}".'ser1@big-org.test',
        ];

        foreach ($collationEquivalent as $variant) {
            $this->assertTrue(
                validator(['email' => $variant], ['email' => ['required', 'not_regex:/[\r\n]/', 'email']])->passes(),
                "validator rejects {$variant} (would close the bypass)"
            );

            $this->assertSame(
                $normalise($canonical),
                $normalise($variant),
                "HOLE OPEN on MySQL: '{$variant}' is the SAME account under utf8mb4_unicode_ci but a DIFFERENT lockout bucket"
            );
        }
    }

    /**
     * COLLATERAL: can an attacker deny a real user their password reset?
     * Five bogus posts consume the whole per-address budget for 10 minutes,
     * and the genuine owner — clicking a genuine link from their inbox — is
     * refused. Renewable indefinitely from rotating IPs.
     */
    public function test_collateral_attacker_can_lock_a_victim_out_of_their_own_reset(): void
    {
        Notification::fake();
        $victim = $this->owner('Denial Corp', 'denial@corp.test');

        // Real user asks for a link.
        $this->post('/forgot-password', ['email' => $victim->email]);

        $realToken = null;
        Notification::assertSentTo($victim, ResetPassword::class, function (ResetPassword $n) use (&$realToken) {
            $realToken = $n->token;

            return true;
        });

        // Attacker burns the budget from five DIFFERENT IPs (route throttle
        // never fires; only the per-address lockout counts).
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

        // The genuine owner, with the genuine token from their own inbox.
        $r = $this->withServerVariables(['REMOTE_ADDR' => '198.18.0.9'])
            ->from('/reset-password/'.$realToken)
            ->post('/reset-password', [
                'token' => $realToken,
                'email' => $victim->email,
                'password' => 'my-own-new-password-1',
                'password_confirmation' => 'my-own-new-password-1',
            ]);

        $ownerLockedOut = ! Hash::check('my-own-new-password-1', $victim->fresh()->password);

        fwrite(STDERR, "\n[DoS LOCKOUT] owner_locked_out=".var_export($ownerLockedOut, true)." status=".$r->getStatusCode()."\n");

        $this->assertFalse($ownerLockedOut,
            'COLLATERAL: an anonymous attacker locked the real owner out of their own password reset for 10 minutes');
    }

    /**
     * COLLATERAL: /forgot-password/code request budget is keyed email|IP, so a
     * rotating attacker can spam the victim's Slack DMs indefinitely AND keep
     * overwriting the stored code.
     */
    public function test_collateral_slack_code_request_spam_by_rotating_ip(): void
    {
        \Illuminate\Support\Facades\Http::fake(fn () => \Illuminate\Support\Facades\Http::response(['ok' => true], 200));

        $victim = $this->owner('Spam Corp', 'spam-code@corp.test');
        app(CurrentOrganization::class)->runFor($victim->organization_id, function () {
            \App\Models\MonitoringSetting::current()->update(['slack_bot_token' => 'xoxb-TENANT']);
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

        fwrite(STDERR, "\n[SLACK DM SPAM] accepted={$accepted} of 60\n");
        $this->assertLessThan(20, $accepted,
            "COLLATERAL/ABUSE: {$accepted} reset-code DMs could be triggered at one victim by rotating IP");
    }
}
