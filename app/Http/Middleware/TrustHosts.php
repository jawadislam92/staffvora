<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts as Middleware;

class TrustHosts extends Middleware
{
    /**
     * Get the host patterns that should be trusted.
     *
     * Anything not matched here is refused before it can influence an absolute
     * URL, which is what stops a poisoned Host header from ending up inside an
     * emailed password-reset link.
     *
     * @return array<int, string|null>
     */
    public function hosts(): array
    {
        return [
            $this->allSubdomainsOfApplicationUrl(),
            // The operator/landlord console is bound to its own host through
            // Route::domain(config('platform.host')). That host is USUALLY a
            // subdomain of APP_URL (already covered above) but it is env
            // configurable and may be a completely different domain, so trust
            // it explicitly — otherwise turning this middleware on would 400
            // the platform portal.
            $this->platformHostPattern(),
        ];
    }

    /**
     * Exact-match pattern for the operator console host, or null when it is
     * unset/unparseable (array_filter in the parent drops nulls).
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

        return $host ? '^'.preg_quote($host).'$' : null;
    }
}
