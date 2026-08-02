<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * Trusted-proxy configuration for the public SaaS deployment.
 *
 * WHY THIS MATTERS: every IP-keyed throttle in the app (login, password reset,
 * signup, contact, the desktop API) keys on `$request->ip()`. Behind a reverse
 * proxy or CDN — the normal shape for a public SaaS, and the shape of the
 * Hostinger VPS this ships to — the peer address is the *proxy's*, identical for
 * every visitor on earth. With no trusted proxies configured, all of those
 * limiters silently collapse into ONE global bucket: a single attacker can burn
 * the whole internet's signup / contact / password-reset budget. The same
 * setting also governs whether `X-Forwarded-Proto` is honoured — without it
 * Laravel generates `http://` URLs when TLS is terminated at the edge, which
 * breaks signed URLs, redirects and secure-cookie behaviour.
 *
 * WHY NOT `'*'`: the wildcard trusts the *immediate peer*, whoever it is, and
 * honours whatever `X-Forwarded-For` that peer sends. If PHP-FPM/nginx is ever
 * reachable directly on the origin — plausible on a shared VPS that also fronts
 * other sites — `'*'` hands every attacker a one-header bypass of every rate
 * limit in the product. That is strictly worse than trusting nothing. So the
 * default here is NULL (trust nothing, key on the real peer), and the edge must
 * be pinned explicitly.
 *
 * HOW TO CONFIGURE (in order of precedence):
 *   1. `TRUSTED_PROXIES` environment variable — comma-separated IPs/CIDRs, e.g.
 *      `TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12`. The literal token
 *      `REMOTE_ADDR` is supported (Laravel resolves it to the calling peer).
 *      The token `cloudflare` expands to Cloudflare's published edge ranges.
 *   2. `config('trustedproxy.proxies')` — read by the parent middleware. Use
 *      this on hosts that run `php artisan config:cache`, because a cached
 *      configuration means Laravel never loads `.env`, so `env()` reads nothing
 *      unless the variable is a REAL process/server environment variable
 *      (fastcgi_param / SetEnv / FPM pool env) — which this class also checks.
 *   3. Nothing set -> no proxies trusted (the previous, safe behaviour).
 *
 * Pinning the edge here is only half the job: the origin must also refuse
 * traffic that does not arrive from the edge (firewall / nginx `allow` rules),
 * otherwise an attacker can talk to the origin directly and simply not be
 * proxied at all.
 */
class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * Resolved from the environment rather than hardcoded — see the class
     * docblock. Null means "trust nothing", which is the safe default.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies;

    /**
     * The headers that should be used to detect proxies.
     *
     * X_FORWARDED_PREFIX is included (the framework default has it; the previous
     * override silently dropped it) so an edge that serves the app under a path
     * prefix still generates correct URLs.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_PREFIX |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    /**
     * Cloudflare's published edge ranges, usable via `TRUSTED_PROXIES=cloudflare`.
     *
     * Source: https://www.cloudflare.com/ips/ — re-check on deploy. A stale entry
     * fails SAFE: a real edge IP that is missing is simply not trusted, so the
     * limiter keys on the edge address exactly as it does today. It can never
     * grant trust to an address Cloudflare does not own.
     *
     * @var array<int, string>
     */
    private const CLOUDFLARE_RANGES = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /**
     * Resolve the trusted proxy list.
     *
     * Falls through to the parent — which honours `TrustProxies::at()` and
     * `config('trustedproxy.proxies')` — when nothing is set in the environment.
     *
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        return $this->proxiesFromEnvironment() ?? parent::proxies();
    }

    /**
     * Read TRUSTED_PROXIES from the process environment.
     *
     * Checked directly against $_SERVER/$_ENV/getenv() rather than through
     * env(), because Laravel skips loading `.env` entirely when the
     * configuration is cached (this app self-heals its config cache in
     * App\Console\Kernel), and a server-provided variable must still work there.
     *
     * @return array<int, string>|null
     */
    private function proxiesFromEnvironment(): ?array
    {
        $raw = $_SERVER['TRUSTED_PROXIES'] ?? $_ENV['TRUSTED_PROXIES'] ?? getenv('TRUSTED_PROXIES');

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $entries = [];

        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            if (strcasecmp($entry, 'cloudflare') === 0) {
                array_push($entries, ...self::CLOUDFLARE_RANGES);

                continue;
            }

            $entries[] = $entry;
        }

        return $entries === [] ? null : array_values(array_unique($entries));
    }
}
