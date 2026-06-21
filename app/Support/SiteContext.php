<?php

namespace App\Support;

use App\Models\SiteTheme;

/**
 * Shared context for the public marketing site (brand identity + the resolved
 * palette + font). Used by every public controller so block-pages and the blog
 * render identically. The palette is resolved no-code from the builder:
 * config/brand.php defaults → a chosen preset → the owner's custom colour
 * overrides. The font is the owner's choice or the config default.
 */
class SiteContext
{
    public static function brand(): array
    {
        $b = config('brand');
        $theme = SiteTheme::current();
        [$light, $dark] = self::resolvePalette($b, $theme);

        return [
            'name' => $b['name'] ?? 'Tracksuite',
            'tagline' => $b['tagline'] ?? null,
            'legal_name' => $b['legal_name'] ?? null,
            'colors' => $light,
            'colors_dark' => $dark,
            'font' => $theme->font ?: ($b['font'] ?? 'Hanken Grotesk'),
            'socials' => array_filter($b['socials'] ?? []),
            'support_email' => $b['support_email'] ?? null,
            'whatsapp' => $b['whatsapp'] ?? null,
            'phone' => $b['phone'] ?? null,
            'address' => $b['address'] ?? null,
            'demo_url' => $b['demo_url'] ?? null,
            'pkr_rate' => $b['pkr_rate'] ?? 280,
        ];
    }

    /** config defaults → preset (if chosen) → custom colour overrides (if any). */
    private static function resolvePalette(array $b, SiteTheme $theme): array
    {
        $light = $b['colors'] ?? [];
        $dark = $b['colors_dark'] ?? [];

        $preset = SitePalettes::resolve($theme->palette_preset);
        if ($preset) {
            $light = $preset['light'];
            $dark = $preset['dark'];
        }

        $custom = $theme->palette ?? [];
        $valid = fn ($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v);
        $lo = array_filter($custom['light'] ?? [], $valid);
        $do = array_filter($custom['dark'] ?? [], $valid);
        if ($lo) {
            $light = array_merge($light, $lo);
        }
        if ($do) {
            $dark = array_merge($dark, $do);
        }

        return [$light, $dark];
    }
}
