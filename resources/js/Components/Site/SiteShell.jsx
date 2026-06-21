import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import SiteHeader from './SiteHeader';
import SiteFooter from './SiteFooter';

const STORAGE_KEY = 'tracksuite-site-theme';

// Light fallbacks — keep the page sane if config/brand.php omits a token.
const FALLBACK = {
    accent: '#4F46E5', accent_deep: '#4338CA', grad_from: '#8B5CF6', grad_to: '#4F46E5',
    highlight: '#A78BFA', brand: '#1E1B4B', deep: '#1E1B4B', ink: '#15132E',
    mint: '#EDE9FE', paper: '#F5F3FF', canvas: '#FFFFFF', surface: '#FFFFFF',
    surface_2: '#F5F3FF', border: '#E9E7F5', text: '#1E1B2E', muted: '#56536E',
    subtle: '#9794AE', pink: '#FCE7F3', lavender: '#EDE9FE',
};

// One palette object (config `colors` or `colors_dark`) → CSS custom properties.
// `--deep` is a band BACKGROUND (dark in both modes); `--brand` is heading TEXT.
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

/* --- hex helpers so the builder's single accent picker can drive the whole
   accent family (deep / gradient / highlight). --- */
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

// Owner picked a custom accent (differs from config default) → recolour the
// accent family from it; otherwise keep config's hand-tuned violet→indigo.
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

function initialMode() {
    if (typeof window === 'undefined') return 'light';
    try {
        const saved = window.localStorage.getItem(STORAGE_KEY);
        if (saved === 'light' || saved === 'dark') return saved;
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) return 'dark';
    } catch (e) { /* no storage */ }
    return 'light';
}

// Builder-editable chrome (name/tagline/contact/socials) overrides config. Pure
// so block pages can build the same effective brand for their blocks.
export function siteEffectiveBrand(brand, theme) {
    return {
        ...brand,
        name: theme?.site_name || brand?.name,
        tagline: theme?.tagline || brand?.tagline,
        support_email: theme?.contact_email || brand?.support_email,
        phone: theme?.contact_phone || brand?.phone,
        address: theme?.contact_address || brand?.address,
        socials: { ...(brand?.socials || {}), ...(theme?.social_links || {}) },
    };
}

/**
 * The shared public-site chrome: the light/dark palette + day/night switcher,
 * header, footer, per-page <head> meta and JSON-LD. Used by every public page
 * (block-pages and the blog) so theming + nav + SEO stay consistent.
 */
export default function SiteShell({ brand, theme, nav, jsonLd, isAuthed, meta = {}, children }) {
    const [mode, setMode] = useState(initialMode);
    const [ready, setReady] = useState(false);

    // Enable colour transitions after first paint, and reveal each section as it
    // scrolls into view (a gentle fade-up). Both are skipped under reduced-motion,
    // and the reveal only hides content once JS has added `.reveal-on`, so no-JS /
    // crawlers always see everything.
    useEffect(() => {
        setReady(true);
        if (typeof window === 'undefined') return undefined;
        const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const root = document.querySelector('.site-root');
        if (reduce || !root) return undefined;

        root.classList.add('reveal-on');
        const items = root.querySelectorAll('main > *');
        const io = new IntersectionObserver((entries) => {
            entries.forEach((e) => {
                if (e.isIntersecting) {
                    e.target.classList.add('in');
                    io.unobserve(e.target);
                }
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.04 });
        items.forEach((el) => io.observe(el));

        return () => io.disconnect();
    }, []);

    const palette = mode === 'dark' ? (brand?.colors_dark || {}) : (brand?.colors || {});
    const vars = applyAccentOverride(paletteVars(palette), theme?.accent_color, theme?.highlight_color, brand?.colors?.accent);

    const toggleTheme = useCallback(() => {
        setMode((m) => {
            const next = m === 'dark' ? 'light' : 'dark';
            try { window.localStorage.setItem(STORAGE_KEY, next); } catch (e) { /* ignore */ }
            return next;
        });
    }, []);

    const headerNav = (theme?.nav_links?.length ? theme.nav_links : nav?.header) || [];
    const footerNav = (theme?.footer_columns?.length ? theme.footer_columns : nav?.footer) || [];
    const eb = siteEffectiveBrand(brand, theme);
    const fallbackTitle = eb?.name || 'Tracksuite';

    return (
        <div
            className="site-root min-h-screen"
            data-site-theme={mode}
            data-ready={ready ? '1' : undefined}
            style={{ ...vars, background: 'var(--canvas)', color: 'var(--text)' }}
        >
            <Head>
                <title>{meta.title || fallbackTitle}</title>
                {meta.description && <meta name="description" content={meta.description} />}
                {meta.canonical && <link rel="canonical" href={meta.canonical} />}
                {meta.robots && <meta name="robots" content={meta.robots} />}
                <meta property="og:title" content={meta.title || fallbackTitle} />
                {meta.description && <meta property="og:description" content={meta.description} />}
                {meta.canonical && <meta property="og:url" content={meta.canonical} />}
                {meta.image && <meta property="og:image" content={meta.image} />}
                <meta property="og:site_name" content={fallbackTitle} />
                <meta property="og:type" content={meta.type || 'website'} />
                <meta name="twitter:card" content="summary_large_image" />
            </Head>

            {Array.isArray(jsonLd) && jsonLd.length > 0 && (
                <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd) }} />
            )}

            <SiteHeader nav={headerNav} brand={eb} theme={theme} isAuthed={isAuthed} mode={mode} onToggleTheme={toggleTheme} />
            <main>{children}</main>
            <SiteFooter nav={footerNav} brand={eb} theme={theme} />
        </div>
    );
}
