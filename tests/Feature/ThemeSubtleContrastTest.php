<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guards the accessibility fixes made to the theme tokens in resources/css/app.css:
 *  - `--text-subtle` was failing WCAG AA (light 2.56:1, dark 4.03:1, black 3.72:1)
 *    on the surfaces it is actually used on. It now clears 4.5:1 in every theme
 *    against both --surface and --surface-2 while staying less prominent than
 *    --text-muted.
 *  - the dark and black roots declare `color-scheme: dark` so native controls
 *    and scrollbars match.
 *
 * These are static-token assertions (the CSS is compiled by Vite, so there is no
 * runtime PHP surface to hit) — they exist to stop the ratios silently
 * regressing on a future theme edit.
 */
class ThemeSubtleContrastTest extends TestCase
{
    private function css(): string
    {
        return file_get_contents(resource_path('css/app.css'));
    }

    /** Relative luminance + contrast ratio per WCAG 2.1. */
    private function ratio(string $fg, string $bg): float
    {
        $lum = function (string $hex): float {
            $hex = ltrim($hex, '#');
            $chan = function (int $v): float {
                $s = $v / 255;
                return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
            };
            $r = $chan((int) hexdec(substr($hex, 0, 2)));
            $g = $chan((int) hexdec(substr($hex, 2, 2)));
            $b = $chan((int) hexdec(substr($hex, 4, 2)));
            return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        };
        $a = $lum($fg);
        $b = $lum($bg);
        [$hi, $lo] = $a > $b ? [$a, $b] : [$b, $a];
        return ($hi + 0.05) / ($lo + 0.05);
    }

    public function test_text_subtle_clears_aa_on_every_theme_surface(): void
    {
        // theme => [subtle, muted, surface, surface-2]
        $themes = [
            'light' => ['#616f85', '#475569', '#ffffff', '#f1f5f9'],
            'dark' => ['#8790a6', '#aeb9d0', '#0e1628', '#131f38'],
            'black' => ['#8a8a8a', '#a3a3a3', '#0a0a0a', '#161616'],
        ];

        $css = $this->css();

        foreach ($themes as $name => [$subtle, $muted, $surface, $surface2]) {
            $this->assertStringContainsString(
                "--text-subtle: {$subtle};",
                $css,
                "app.css should set --text-subtle to {$subtle} for the {$name} theme"
            );

            $this->assertGreaterThanOrEqual(
                4.5,
                $this->ratio($subtle, $surface),
                "{$name}: --text-subtle {$subtle} must clear AA on --surface {$surface}"
            );
            $this->assertGreaterThanOrEqual(
                4.5,
                $this->ratio($subtle, $surface2),
                "{$name}: --text-subtle {$subtle} must clear AA on --surface-2 {$surface2}"
            );

            // Still visibly "subtle": less contrast than --text-muted on the surface.
            $this->assertLessThan(
                $this->ratio($muted, $surface),
                $this->ratio($subtle, $surface),
                "{$name}: --text-subtle should stay less prominent than --text-muted"
            );
        }
    }

    public function test_dark_and_black_roots_declare_color_scheme_dark(): void
    {
        $css = $this->css();
        // Two dark roots (":root, [data-theme=dark]" and "[data-theme=black]").
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($css, 'color-scheme: dark;'),
            'both the dark and black theme roots should declare color-scheme: dark'
        );
        $this->assertStringContainsString(
            'color-scheme: light;',
            $css,
            'the light theme root should declare color-scheme: light'
        );
    }
}
