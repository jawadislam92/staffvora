<?php

namespace App\Support;

class WebDomain
{
    /**
     * Canonical form for captured browser domains so "www.youtube.com" and
     * "YouTube.com" roll up with "youtube.com" instead of splitting the
     * totals across variants.
     */
    public static function normalize(?string $domain): ?string
    {
        if ($domain === null || trim($domain) === '') {
            return null;
        }

        $domain = strtolower(trim($domain));
        $domain = preg_replace('/^www\./', '', $domain);

        return $domain === '' ? null : $domain;
    }
}
