import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import SiteHeader from '@/Components/Site/SiteHeader';
import SiteFooter from '@/Components/Site/SiteFooter';
import BlockRenderer from '@/Components/Site/BlockRenderer';

const STORAGE_KEY = 'tracksuite-site-theme';

// Light fallbacks — keep the page sane if config/brand.php omits a token.
const FALLBACK = {
    accent: '#4F46E5', accent_deep: '#4338CA', grad_from: '#8B5CF6', grad_to: '#4F46E5',
    highlight: '#A78BFA', brand: '#1E1B4B', deep: '#1E1B4B', ink: '#15132E',
    mint: '#EDE9FE', paper: '#F5F3FF', canvas: '#FFFFFF', surface: '#FFFFFF',
    surface_2: '#F5F3FF', border: '#E9E7F5', text: '#1E1B2E', muted: '#56536E',
    subtle: '#9794AE', pink: '#FCE7F3', lavender: '#EDE9FE',
};

// One palette object (config `colors` or `colors_dark`) → the CSS custom
// properties every marketing block reads from. `--deep` is a band BACKGROUND
// (stays dark in both modes); `--brand` is heading TEXT (light in dark mode).
function paletteVars(c = {}) {
    const f = { ...FALLBACK, ...c };
    return {
        '--a': f.accent, '--a-deep': f.accent_deep, '--g-from': f.grad_from, '--g-to': f.grad_to,
        '--hl': f.highlight, '--brand': f.brand, '--deep': f.deep, '--ink': f.ink,
        '--mint': f.mint, '--paper': f.paper, '--pink': f.pink, '--lavender': f.lavender,
        '--canvas': f.canvas, '--surface': f.surface, '--surface-2': f.surface_2,
        '--line': f.border, '--text': f.text, '--muted': f.muted, '--subtle': f.subtle,
    };
}

/* --- tiny hex helpers so the builder's single accent picker can drive the
   whole accent family (deep / gradient / highlight) without the owner needing
   to choose five colours. --- */
const isHex = (h) => /^#[0-9a-fA-F]{6}$/.test(h || '');
function hexToRgb(hex) {
    const n = parseInt(hex.slice(1), 16);
    return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
}
function rgbToHex(r, g, b) {
    const h = (x) => Math.min(255, Math.max(0, Math.round(x))).toString(16).padStart(2, '0');
    return '#' + h(r) + h(g) + h(b);
}
function mix(hex, target, amt) {
    const a = hexToRgb(hex), b = hexToRgb(target);
    return rgbToHex(a.r + (b.r - a.r) * amt, a.g + (b.g - a.g) * amt, a.b + (b.b - a.b) * amt);
}
const lighten = (hex, amt) => mix(hex, '#ffffff', amt);
const darken = (hex, amt) => mix(hex, '#000000', amt);

// If the owner picked a custom accent in the builder (one that differs from the
// config default), recolour the accent family from it. Equal-to-default = leave
// config's hand-tuned violet→indigo gradient untouched.
function applyAccentOverride(vars, accent, highlight, configAccent) {
    if (!isHex(accent) || accent.toLowerCase() === (configAccent || '').toLowerCase()) return vars;
    return {
        ...vars,
        '--a': accent,
        '--a-deep': darken(accent, 0.14),
        '--g-to': accent,
        '--g-from': lighten(accent, 0.18),
        '--hl': isHex(highlight) ? highlight : lighten(accent, 0.30),
    };
}

// First-paint mode: a saved choice wins, otherwise the visitor's OS preference.
// Runs in the useState initialiser, so the right palette is applied before paint.
function initialMode() {
    if (typeof window === 'undefined') return 'light';
    try {
        const saved = window.localStorage.getItem(STORAGE_KEY);
        if (saved === 'light' || saved === 'dark') return saved;
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) return 'dark';
    } catch (e) { /* no storage — default light */ }
    return 'light';
}

/**
 * Public marketing page. Carries BOTH a light and dark palette (from
 * config/brand.php) and a day/night switcher: the header sun/moon toggle flips
 * `mode`, which swaps the CSS custom properties on `.site-root`. The choice is
 * persisted and the first paint honours the visitor's OS preference.
 */
export default function Show({ page, theme, brand, nav, plans, isAuthed }) {
    const [mode, setMode] = useState(initialMode);
    const [ready, setReady] = useState(false);
    const meta = page?.meta || {};

    // Enable colour transitions only after the first paint, so the page doesn't
    // animate from the default palette into the real one on load.
    useEffect(() => { setReady(true); }, []);

    const palette = mode === 'dark' ? (brand?.colors_dark || {}) : (brand?.colors || {});
    const vars = applyAccentOverride(
        paletteVars(palette),
        theme?.accent_color,
        theme?.highlight_color,
        brand?.colors?.accent,
    );

    // Builder-editable chrome overrides config: nav, footer columns, contact
    // details and socials all prefer the values saved in the Website settings.
    const headerNav = (theme?.nav_links?.length ? theme.nav_links : nav?.header) || [];
    const footerNav = (theme?.footer_columns?.length ? theme.footer_columns : nav?.footer) || [];
    const effectiveBrand = {
        ...brand,
        name: theme?.site_name || brand?.name,
        tagline: theme?.tagline || brand?.tagline,
        support_email: theme?.contact_email || brand?.support_email,
        phone: theme?.contact_phone || brand?.phone,
        address: theme?.contact_address || brand?.address,
        socials: { ...(brand?.socials || {}), ...(theme?.social_links || {}) },
    };

    const toggleTheme = useCallback(() => {
        setMode((m) => {
            const next = m === 'dark' ? 'light' : 'dark';
            try { window.localStorage.setItem(STORAGE_KEY, next); } catch (e) { /* ignore */ }
            return next;
        });
    }, []);

    return (
        <div
            className="site-root min-h-screen"
            data-site-theme={mode}
            data-ready={ready ? '1' : undefined}
            style={{ ...vars, background: 'var(--canvas)', color: 'var(--text)' }}
        >
            <Head>
                <title>{meta.title || effectiveBrand?.name || 'Tracksuite'}</title>
                {meta.description && <meta name="description" content={meta.description} />}
                {meta.canonical && <link rel="canonical" href={meta.canonical} />}
                {meta.robots && <meta name="robots" content={meta.robots} />}
                <meta property="og:title" content={meta.title || effectiveBrand?.name || 'Tracksuite'} />
                {meta.description && <meta property="og:description" content={meta.description} />}
                <meta property="og:type" content="website" />
                <meta name="twitter:card" content="summary_large_image" />
            </Head>

            <SiteHeader nav={headerNav} brand={effectiveBrand} theme={theme} isAuthed={isAuthed} mode={mode} onToggleTheme={toggleTheme} />
            <main>
                <BlockRenderer blocks={page?.blocks || []} theme={theme} brand={effectiveBrand} plans={plans} />
            </main>
            <SiteFooter nav={footerNav} brand={effectiveBrand} theme={theme} />
        </div>
    );
}
