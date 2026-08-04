<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts as Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Which hostnames this installation will answer on.
 *
 * Anything not matched here is refused before it can influence an absolute URL,
 * which is what stops a poisoned Host header from ending up inside an emailed
 * password-reset link.
 *
 * Three properties this class has to hold at once, and the first round only had
 * the first one:
 *
 *  1. PUBLIC hosts are an allow-list. APP_URL (+ subdomains) and the operator
 *     console host. Nothing else may appear in a link we send.
 *
 *  2. INFRASTRUCTURE traffic must still be served. Uptime probes hit the origin
 *     by IP, nginx upstream checks send `Host: localhost`, local cron `curl`
 *     sends `127.0.0.1`, and HTTP/1.0 probes send no Host header at all. The
 *     first round refused all of those with a 400. Production happens to have
 *     zero such traffic today, which is the only reason nobody noticed.
 *
 *  3. A link built on an infrastructure host must NEVER be emailed. Serving
 *     `Host: localhost` is harmless; mailing `http://localhost/reset-password/…`
 *     to a customer is not. So whenever the request is being served on one of
 *     the infrastructure hosts, every absolute URL generated during that request
 *     is pinned to `config('app.url')` instead of to the request. That single
 *     rule is what makes property 2 safe, and it also covers any *other* mail
 *     the app happens to send from a cron/probe-shaped request.
 *
 * FAIL CLOSED. `Illuminate\Http\Middleware\TrustHosts::handle()` calls
 * `Request::setTrustedHosts(array_filter($this->hosts()))`, and Symfony treats
 * an EMPTY list as "no host validation at all" — i.e. an unparseable APP_URL
 * plus a blank PLATFORM_HOST silently turned the whole protection OFF. The
 * public list can therefore never be empty here: when it resolves to nothing we
 * substitute a pattern that can never match and log a critical, so the failure
 * mode is a refused request and an alarm rather than a silent hole.
 * `deploy:preflight` checks the same thing before a deploy.
 */
class TrustHosts extends Middleware
{
    /**
     * A pattern that matches no hostname whatsoever.
     *
     * `(?!)` is a negative lookahead on the empty string, which always fails, so
     * `preg_match('{^(?!)$}i', $anything)` is 0 — including for the empty host.
     * Used as the fail-closed stand-in for an empty public host list.
     */
    public const NEVER_MATCHES = '^(?!)$';

    /**
     * Extra hostnames to trust, comma separated (e.g. the origin IP a monitor
     * hits directly, or an internal load-balancer name).
     *
     * Read from the process environment rather than through env(), for the same
     * reason TrustProxies does: `config:cache` stops Laravel loading .env, so a
     * real fastcgi_param/SetEnv variable must still be honoured.
     */
    private const EXTRA_HOSTS_VAR = 'TRUSTED_HOSTS_EXTRA';

    /**
     * Get the host patterns that should be trusted.
     *
     * The parent calls this from handle() and hands the result straight to
     * Symfony, so it has to contain BOTH the public and the infrastructure
     * patterns. Use publicHostPatterns() when you need only the hosts that are
     * allowed to appear in a link.
     *
     * @return array<int, string>
     */
    public function hosts(): array
    {
        return array_merge($this->publicHostPatterns(), $this->infrastructureHostPatterns());
    }

    /**
     * The hosts this installation is publicly known by — the only ones that may
     * ever end up inside an absolute URL we hand to a customer.
     *
     * Never empty: an empty list means "trust everything" to Symfony.
     *
     * @return array<int, string>
     */
    public function publicHostPatterns(): array
    {
        $patterns = array_values(array_filter([
            $this->allSubdomainsOfApplicationUrl(),
            // The operator/landlord console is bound to its own host through
            // Route::domain(config('platform.host')). That host is USUALLY a
            // subdomain of APP_URL (already covered above) but it is env
            // configurable and may be a completely different domain, so trust
            // it explicitly — otherwise turning this middleware on would 400
            // the platform portal.
            $this->platformHostPattern(),
        ]));

        if ($patterns !== []) {
            return $patterns;
        }

        $this->reportEmptyHostList();

        return [self::NEVER_MATCHES];
    }

    /**
     * Hosts that only ever carry infrastructure traffic: loopback, the origin's
     * own address, operator-configured extras, and the empty host a request with
     * no Host header resolves to.
     *
     * These are deliberately NOT public: see pinUrlRootForInfrastructureHosts(),
     * which stops anything generated on one of them leaving the box.
     *
     * @return array<int, string>
     */
    public function infrastructureHostPatterns(): array
    {
        $patterns = [
            // A request that carries no Host header at all. Symfony resolves it
            // to SERVER_NAME, then SERVER_ADDR, then ''. HTTP/1.0 health checks
            // and some container probes look like this.
            '^$',
            '^localhost$',
            '^127\.0\.0\.1$',
            '^::1$',
            '^\[::1\]$',
        ];

        // The origin's own address, so an external uptime monitor can hit the
        // box by IP. SERVER_ADDR is set by the web server, never by the client,
        // so this cannot be steered from outside.
        $serverAddress = $_SERVER['SERVER_ADDR'] ?? null;

        if (is_string($serverAddress) && filter_var($serverAddress, FILTER_VALIDATE_IP)) {
            $patterns[] = '^'.preg_quote($serverAddress, null).'$';
            $patterns[] = '^\['.preg_quote($serverAddress, null).'\]$';
        }

        foreach ($this->extraHostsFromEnvironment() as $host) {
            $patterns[] = '^'.preg_quote($host, null).'$';
        }

        return $patterns;
    }

    /**
     * Handle the incoming request.
     *
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, $next)
    {
        if ($this->shouldSpecifyTrustedHosts()) {
            Request::setTrustedHosts(array_values(array_filter($this->hosts())));
        }

        // Runs in EVERY environment, including local and the test suite, where
        // the trusted-host list itself is deliberately not armed. The pin is not
        // a host check — it decides where generated links point — and a link
        // must not depend on whether host validation happens to be on.
        $this->pinUrlRootForInfrastructureHosts($request);

        return $next($request);
    }

    /**
     * When the request is being served on an infrastructure host, build every
     * absolute URL for this request from config('app.url') instead.
     *
     * This is the guard that makes serving loopback/origin-IP traffic safe: a
     * password-reset mail triggered from a cron `curl http://127.0.0.1/...` still
     * carries the real public link, and a cached response can't be poisoned with
     * a loopback root either.
     *
     * @return bool whether the root URL was pinned
     */
    public function pinUrlRootForInfrastructureHosts(Request $request): bool
    {
        $root = rtrim(trim((string) config('app.url')), '/');

        $pin = $root !== ''
            && $this->matchesAny($this->rawHost($request), $this->infrastructureHostPatterns());

        // Always assign, never "only when pinning": leaving a previous request's
        // forced root in place would make a later public request emit links for
        // the wrong root. Nothing else in the app forces a root URL.
        URL::forceRootUrl($pin ? $root : null);

        // The scheme has to come from app.url too. Without this a plain-http
        // loopback probe would rewrite an https:// root back down to http://,
        // and we would mail an insecure link.
        URL::forceScheme($pin ? (parse_url($root, PHP_URL_SCHEME) ?: null) : null);

        return $pin;
    }

    /**
     * Exact-match pattern for the operator console host, or null when it is
     * unset/unparseable (array_filter drops nulls).
     */
    protected function platformHostPattern(): ?string
    {
        $configured = trim((string) config('platform.host'));

        if ($configured === '') {
            return null;
        }

        // Accept either a bare host ("platform.staffvora.com") or a full URL.
        $host = parse_url(
            str_contains($configured, '//') ? $configured : '//'.$configured,
            PHP_URL_HOST
        );

        return $host ? '^'.preg_quote($host, null).'$' : null;
    }

    /**
     * The requested hostname, read WITHOUT calling Request::getHost().
     *
     * getHost() is one-shot: the first call on an untrusted host throws, sets a
     * private `isHostValid` flag, and every later call on that request returns
     * an empty string instead of throwing again. Calling it speculatively here
     * therefore *consumed* the rejection and let a foreign Host header through
     * with a normal 200/302 — the exact hole this middleware exists to close.
     * So we read the raw header and normalise it the same way Symfony does
     * (trim, strip the port, lowercase), and never touch that state.
     */
    private function rawHost(Request $request): string
    {
        $host = (string) ($request->headers->get('HOST')
            ?: $request->server->get('SERVER_NAME')
            ?: $request->server->get('SERVER_ADDR')
            ?: '');

        return strtolower((string) preg_replace('/:\d+$/', '', trim($host)));
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function matchesAny(string $host, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && preg_match('{'.$pattern.'}i', $host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function extraHostsFromEnvironment(): array
    {
        $raw = $_SERVER[self::EXTRA_HOSTS_VAR] ?? $_ENV[self::EXTRA_HOSTS_VAR] ?? getenv(self::EXTRA_HOSTS_VAR);

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $hosts = [];

        foreach (explode(',', $raw) as $entry) {
            $entry = strtolower(trim($entry));

            if ($entry !== '') {
                $hosts[] = $entry;
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * Loud, every time. This state means the deploy is broken AND that older
     * builds would have been silently trusting every Host header on earth, so it
     * is worth a line per request until someone fixes the configuration.
     */
    private function reportEmptyHostList(): void
    {
        try {
            Log::critical('Trusted host list resolved to nothing — refusing all traffic (fail closed).', [
                'app_url' => (string) config('app.url'),
                'platform_host' => (string) config('platform.host'),
                'fix' => 'Set APP_URL to the full https:// site address (and PLATFORM_HOST if the operator console is on its own domain), then run: php artisan config:cache',
            ]);
        } catch (Throwable) {
            // Logging must never be the reason a request dies.
        }
    }
}
