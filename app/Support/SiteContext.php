<?php

namespace App\Support;

/**
 * Shared context for the public marketing site (brand identity + palette from
 * config/brand.php). Used by every public controller so the block-pages and the
 * blog render with the same brand payload.
 */
class SiteContext
{
    public static function brand(): array
    {
        $b = config('brand');

        return [
            'name' => $b['name'] ?? 'Tracksuite',
            'tagline' => $b['tagline'] ?? null,
            'legal_name' => $b['legal_name'] ?? null,
            'colors' => $b['colors'] ?? [],
            'colors_dark' => $b['colors_dark'] ?? [],
            'socials' => array_filter($b['socials'] ?? []),
            'support_email' => $b['support_email'] ?? null,
            'whatsapp' => $b['whatsapp'] ?? null,
            'phone' => $b['phone'] ?? null,
            'address' => $b['address'] ?? null,
            'demo_url' => $b['demo_url'] ?? null,
            'pkr_rate' => $b['pkr_rate'] ?? 280,
        ];
    }
}
