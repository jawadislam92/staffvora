<?php

namespace App\Support;

/**
 * Curated colour themes the owner can pick from in the builder (no code). Each
 * preset defines its BRAND colours for light + dark; shared neutral scaffolding
 * (canvas/surface/text/…) is merged in so every preset stays balanced. The chosen
 * preset's maps become the site's `colors` / `colors_dark` (see SiteContext).
 */
class SitePalettes
{
    private const NEUTRAL_LIGHT = [
        'canvas' => '#FFFFFF', 'surface' => '#FFFFFF', 'surface_2' => '#F6F4FB',
        'border' => '#EAE7F2', 'text' => '#262234', 'muted' => '#585369',
        'subtle' => '#928DA6', 'ink' => '#160F2E',
    ];

    private const NEUTRAL_DARK = [
        'canvas' => '#0E0B1A', 'surface' => '#181428', 'surface_2' => '#221C38',
        'border' => '#2E2746', 'text' => '#ECE8F6', 'muted' => '#AAA2C2',
        'subtle' => '#7B7498', 'ink' => '#08060F',
    ];

    /** preset => [label, light brand colours, dark brand colours]. */
    private const PRESETS = [
        'aurora' => ['label' => 'Aurora',
            'light' => ['accent' => '#7C3AED', 'accent_deep' => '#6D28D9', 'grad_from' => '#22D3EE', 'grad_to' => '#A855F7', 'highlight' => '#C4B5FD', 'brand' => '#2A1257', 'deep' => '#2A1257', 'mint' => '#F3E8FF', 'paper' => '#FAF5FF', 'pink' => '#FCE7F3', 'lavender' => '#CFFAFE'],
            'dark' => ['accent' => '#A78BFA', 'accent_deep' => '#8B5CF6', 'grad_from' => '#22D3EE', 'grad_to' => '#C084FC', 'highlight' => '#DDD6FE', 'brand' => '#F3EEFF', 'deep' => '#241046', 'mint' => '#281C44', 'paper' => '#181030', 'pink' => '#2E1F3C', 'lavender' => '#103A4A']],
        'electric' => ['label' => 'Electric blue',
            'light' => ['accent' => '#2563EB', 'accent_deep' => '#1D4ED8', 'grad_from' => '#06B6D4', 'grad_to' => '#6366F1', 'highlight' => '#93C5FD', 'brand' => '#0B1E4B', 'deep' => '#0B1E4B', 'mint' => '#DBEAFE', 'paper' => '#EFF6FF', 'pink' => '#E0F2FE', 'lavender' => '#CFFAFE'],
            'dark' => ['accent' => '#60A5FA', 'accent_deep' => '#3B82F6', 'grad_from' => '#22D3EE', 'grad_to' => '#818CF8', 'highlight' => '#BFDBFE', 'brand' => '#EAF2FF', 'deep' => '#0E1B3A', 'mint' => '#16233F', 'paper' => '#101A30', 'pink' => '#122A3A', 'lavender' => '#0E2E3E']],
        'sunset' => ['label' => 'Sunset',
            'light' => ['accent' => '#E11D48', 'accent_deep' => '#BE123C', 'grad_from' => '#FB923C', 'grad_to' => '#D946EF', 'highlight' => '#FDA4AF', 'brand' => '#4A1124', 'deep' => '#4A1124', 'mint' => '#FFE4E6', 'paper' => '#FFF1F2', 'pink' => '#FCE7F3', 'lavender' => '#FFEDD5'],
            'dark' => ['accent' => '#FB7185', 'accent_deep' => '#F43F5E', 'grad_from' => '#FB923C', 'grad_to' => '#E879F9', 'highlight' => '#FECDD3', 'brand' => '#FFE9EE', 'deep' => '#3A0F1E', 'mint' => '#3A1A24', 'paper' => '#2A1118', 'pink' => '#3A1228', 'lavender' => '#3A2410']],
        'emerald' => ['label' => 'Emerald',
            'light' => ['accent' => '#059669', 'accent_deep' => '#047857', 'grad_from' => '#34D399', 'grad_to' => '#0EA5E9', 'highlight' => '#6EE7B7', 'brand' => '#06281E', 'deep' => '#06281E', 'mint' => '#D1FAE5', 'paper' => '#ECFDF5', 'pink' => '#DCFCE7', 'lavender' => '#CFFAFE'],
            'dark' => ['accent' => '#34D399', 'accent_deep' => '#10B981', 'grad_from' => '#34D399', 'grad_to' => '#22D3EE', 'highlight' => '#A7F3D0', 'brand' => '#E7FFF6', 'deep' => '#0A2A20', 'mint' => '#14302A', 'paper' => '#0F2620', 'pink' => '#123026', 'lavender' => '#0E2E3A']],
        'crimson' => ['label' => 'Crimson',
            'light' => ['accent' => '#DC2626', 'accent_deep' => '#B91C1C', 'grad_from' => '#F97316', 'grad_to' => '#E11D48', 'highlight' => '#FCA5A5', 'brand' => '#450A0A', 'deep' => '#450A0A', 'mint' => '#FEE2E2', 'paper' => '#FEF2F2', 'pink' => '#FFE4E6', 'lavender' => '#FFEDD5'],
            'dark' => ['accent' => '#F87171', 'accent_deep' => '#EF4444', 'grad_from' => '#FB923C', 'grad_to' => '#FB7185', 'highlight' => '#FECACA', 'brand' => '#FFEAEA', 'deep' => '#3A0F0F', 'mint' => '#3A1A1A', 'paper' => '#2A1212', 'pink' => '#3A1820', 'lavender' => '#3A2410']],
        'slate' => ['label' => 'Indigo slate',
            'light' => ['accent' => '#4F46E5', 'accent_deep' => '#4338CA', 'grad_from' => '#6366F1', 'grad_to' => '#4F46E5', 'highlight' => '#A5B4FC', 'brand' => '#1E1B3A', 'deep' => '#1E1B3A', 'mint' => '#EEF2FF', 'paper' => '#F8FAFC', 'pink' => '#EEF2FF', 'lavender' => '#E0E7FF'],
            'dark' => ['accent' => '#818CF8', 'accent_deep' => '#6366F1', 'grad_from' => '#818CF8', 'grad_to' => '#6366F1', 'highlight' => '#C7D2FE', 'brand' => '#EEF1FF', 'deep' => '#1A1B30', 'mint' => '#1E2138', 'paper' => '#14162A', 'pink' => '#1E2138', 'lavender' => '#181B33']],
    ];

    /** Resolve a preset id → ['light' => full token map, 'dark' => full token map], or null. */
    public static function resolve(?string $preset): ?array
    {
        if (! $preset || ! isset(self::PRESETS[$preset])) {
            return null;
        }
        $p = self::PRESETS[$preset];

        return [
            'light' => array_merge(self::NEUTRAL_LIGHT, $p['light']),
            'dark' => array_merge(self::NEUTRAL_DARK, $p['dark']),
        ];
    }

    /** For the builder UI: id, label, and a few swatch colours to preview each preset. */
    public static function options(): array
    {
        return collect(self::PRESETS)->map(fn ($p, $id) => [
            'id' => $id,
            'label' => $p['label'],
            'from' => $p['light']['grad_from'],
            'to' => $p['light']['grad_to'],
            'accent' => $p['light']['accent'],
            'brand' => $p['light']['brand'],
        ])->values()->all();
    }

    /** The set of brand-colour keys the custom editor lets the owner fine-tune. */
    public static function editableKeys(): array
    {
        return [
            'accent' => 'Accent (links, buttons)',
            'grad_from' => 'Gradient start',
            'grad_to' => 'Gradient end',
            'brand' => 'Headings',
            'deep' => 'Dark band background',
        ];
    }

    /** Curated, readable font choices (Google Fonts). */
    public static function fonts(): array
    {
        return ['Hanken Grotesk', 'Inter', 'Plus Jakarta Sans', 'Poppins', 'Manrope', 'DM Sans', 'Sora', 'Space Grotesk', 'Outfit', 'Figtree'];
    }
}
