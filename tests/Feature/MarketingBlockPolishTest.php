<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * MARKETING POLISH — the accessibility / token leftovers from the public-site
 * audit that lived in blocks outside the round-1 marketing agent's set.
 *
 * These are markup / CSS fixes, so the guard is the same one the sister defence
 * tests use: read the component source and assert the attribute / token is
 * present (or the banned one is gone). Every assertion here failed against the
 * source as it stood before this round — the FAQ toggles carried no
 * `aria-expanded`, CtaBand shipped a literal `bg-white`, TeamGrid used `alt=""`,
 * Hero carried `ring-black/5`, and app.css defined no `:focus-visible` ring.
 */
class MarketingBlockPolishTest extends TestCase
{
    private function block(string $name): string
    {
        $path = resource_path('js/Components/Site/blocks/'.$name);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    // --- W10: FAQ accordion exposes expanded state and hides collapsed answers -

    public function test_faq_toggle_button_exposes_expanded_state_and_controls_its_panel(): void
    {
        $faq = $this->block('Faq.jsx');

        $this->assertStringContainsString('aria-expanded={open}', $faq,
            'the FAQ toggle no longer announces open/closed to assistive tech');
        $this->assertStringContainsString('aria-controls={panelId}', $faq,
            'the FAQ toggle is not wired to the panel it controls');
    }

    public function test_faq_panel_is_a_labelled_region_removed_from_the_a11y_tree_when_closed(): void
    {
        $faq = $this->block('Faq.jsx');

        $this->assertStringContainsString('id={panelId}', $faq);
        $this->assertStringContainsString('role="region"', $faq);
        $this->assertStringContainsString('aria-labelledby={btnId}', $faq);
        // `invisible` (visibility:hidden) is what pulls the collapsed answer out of
        // the accessibility tree — `grid-rows-[0fr]` + opacity alone do not.
        $this->assertStringContainsString('grid-rows-[0fr] opacity-0 invisible', $faq,
            'the collapsed FAQ answer is still exposed to screen readers');
    }

    // --- U12: CtaBand pill is token-driven, not a literal white ---------------

    public function test_cta_band_pill_uses_tokens_that_work_in_both_themes(): void
    {
        $band = $this->block('CtaBand.jsx');

        $this->assertStringNotContainsString('bg-white', $band,
            'CtaBand still ships a literal bg-white, which the design contract disallows');
        $this->assertStringContainsString('bg-[var(--surface)] px-8 py-4 text-base font-semibold text-[var(--brand)]', $band,
            'the reversed CTA pill is not on the surface/brand token pair (surface + brand both flip per theme)');
    }

    // --- U7: TeamGrid avatar alt text is the member name ----------------------

    public function test_team_grid_avatar_uses_the_member_name_for_alt_text(): void
    {
        $grid = $this->block('TeamGrid.jsx');

        $this->assertStringContainsString('alt={m.name || \'\'}', $grid,
            'the team avatar still has empty/generic alt text');
        $this->assertStringNotContainsString('src={m.avatar_url} alt=""', $grid);
    }

    // --- U13: Hero hairlines are token-driven, not fixed black/5 --------------

    public function test_hero_hairline_rings_use_the_line_token(): void
    {
        $hero = $this->block('Hero.jsx');

        $this->assertStringNotContainsString('ring-black/5', $hero,
            'Hero still uses ring-black/5, which is near-invisible on the night canvas');
        $this->assertStringContainsString('ring-1 ring-[var(--line)]', $hero);
    }

    // --- U8: over-narrow / over-long centred prose is widened -----------------

    public function test_hero_centred_subhead_is_widened_for_long_copy(): void
    {
        $hero = $this->block('Hero.jsx');

        $this->assertStringContainsString("center ? 'mx-auto max-w-3xl'", $hero,
            'the centred hero subhead is still capped at max-w-2xl, so long copy reads ragged');
    }

    public function test_steps_body_copy_is_widened_from_max_w_xs(): void
    {
        $steps = $this->block('Steps.jsx');

        $this->assertStringContainsString('mx-auto mt-2 max-w-sm', $steps,
            'the Steps body is still squeezed into max-w-xs (320px), producing a ragged narrow block');
    }

    // --- U10: one global, token-driven focus-visible ring in app.css ----------

    public function test_app_css_defines_a_global_branded_focus_visible_ring(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString(':focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }', $css,
            'no global :focus-visible ring — keyboard focus contrast is left to the UA default');
        $this->assertStringContainsString('.site-root :focus-visible { outline-color: var(--a); }', $css,
            'the marketing site does not swap the focus ring to its own accent token');
    }
}
