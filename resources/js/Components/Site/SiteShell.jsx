import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import SiteHeader from './SiteHeader';
import SiteFooter from './SiteFooter';

const STORAGE_KEY = 'staffvora-site-theme';
// Pre-rename key ("Tracksuite" was the old product name). Migrated once on read
// so a returning visitor keeps the light/dark choice they already made.
const LEGACY_STORAGE_KEY = 'tracksuite-site-theme';

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

// JSON-LD is injected into a `<script type="application/ld+json">` with
// dangerouslySetInnerHTML, and its contents are OPERATOR-AUTHORED: FAQ q/a from
// the page builder, blog title/excerpt. `JSON.stringify` escapes quotes and
// backslashes — it escapes neither `<` nor `/`, so an FAQ answer containing
// `</script><img src=x onerror=…>` lands verbatim in the script's text.
//
// It happens not to execute TODAY, and only by accident of one browser rule:
// assigning .innerHTML on a <script> element parses the value in the script-data
// tokenizer state with no start tag emitted, so `</script>` is not an
// "appropriate end tag" and no element is created. That accident evaporates the
// moment this page is server-rendered (Inertia SSR), because the same string is
// then written into the HTML SOURCE, where `</script>` closes the block for
// real and everything after it is markup.
//
// So escape for the script context now, while it costs four replacements:
// `<`, `>`, `&` and `/` cannot appear in JSON outside a string literal, and
// `\uXXXX` is valid JSON inside one, so this is lossless — a consumer parses
// back the identical graph.
function jsonLdText(graph) {
    return JSON.stringify(graph)
        .replace(/&/g, '\\u0026')
        .replace(/</g, '\\u003c')
        .replace(/>/g, '\\u003e')
        .replace(/\//g, '\\u002f');
}

function initialMode() {
    if (typeof window === 'undefined') return 'light';
    try {
        let saved = window.localStorage.getItem(STORAGE_KEY);
        if (saved !== 'light' && saved !== 'dark') {
            // One-time migration from the pre-rename key.
            const legacy = window.localStorage.getItem(LEGACY_STORAGE_KEY);
            if (legacy === 'light' || legacy === 'dark') {
                saved = legacy;
                window.localStorage.setItem(STORAGE_KEY, legacy);
                window.localStorage.removeItem(LEGACY_STORAGE_KEY);
            }
        }
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

    // The palette is fully resolved server-side (config → preset → custom), so the
    // client just emits it. The chosen font drives --site-font.
    const palette = mode === 'dark' ? (brand?.colors_dark || {}) : (brand?.colors || {});
    const font = brand?.font || 'Hanken Grotesk';
    const fontHref = `https://fonts.googleapis.com/css2?family=${font.replace(/ /g, '+')}:wght@400;500;600;700;800&display=swap`;
    const vars = { ...paletteVars(palette), '--site-font': `'${font}', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif` };

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
    const fallbackTitle = eb?.name || 'Staffvora';

    return (
        <div
            className="site-root min-h-screen"
            data-site-theme={mode}
            data-ready={ready ? '1' : undefined}
            style={{ ...vars, background: 'var(--canvas)', color: 'var(--text)', colorScheme: mode }}
        >
            <Head>
                <title>{meta.title || fallbackTitle}</title>
                <link rel="preconnect" href="https://fonts.googleapis.com" />
                <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="" />
                <link href={fontHref} rel="stylesheet" />
                {meta.description && <meta name="description" content={meta.description} />}
                {meta.canonical && <link rel="canonical" href={meta.canonical} />}
                {meta.robots && <meta name="robots" content={meta.robots} />}
                <meta property="og:title" content={meta.title || fallbackTitle} />
                {meta.description && <meta property="og:description" content={meta.description} />}
                {meta.canonical && <meta property="og:url" content={meta.canonical} />}
                {meta.image && <meta property="og:image" content={meta.image} />}
                <meta property="og:site_name" content={fallbackTitle} />
                <meta property="og:type" content={meta.type || 'website'} />
                {/* Only claim a large-image card when there actually is an image —
                    otherwise every shared link rendered a blank large card. */}
                <meta name="twitter:card" content={meta.image ? 'summary_large_image' : 'summary'} />
                <meta name="color-scheme" content={mode} />
            </Head>

            {Array.isArray(jsonLd) && jsonLd.length > 0 && (
                <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLdText(jsonLd) }} />
            )}

            <SiteHeader nav={headerNav} brand={eb} theme={theme} isAuthed={isAuthed} mode={mode} onToggleTheme={toggleTheme} />
            <main>{children}</main>
            <SiteFooter nav={footerNav} brand={eb} theme={theme} />
        </div>
    );
}
