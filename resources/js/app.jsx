import './bootstrap';
import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { Toaster } from 'react-hot-toast';
import ErrorBoundary from '@/Components/ErrorBoundary';

const DEFAULT_APP_NAME = import.meta.env.VITE_APP_NAME || 'Staffvora';
const DEFAULT_ACCENT = '#f97316';
// The workspace brand name, kept current by applyTheme so the document <title>
// (resolved per page below) reflects the tenant's branding, not a constant.
let brandName = DEFAULT_APP_NAME;

// How far each orange shade is mixed from the base accent (500): negative =
// toward white (lighter), positive = toward black (darker). Lets a single accent
// colour produce a full, coherent ramp for the remapped `orange-*` utilities.
const ORANGE_MIX = { 50: -0.92, 100: -0.84, 200: -0.66, 300: -0.46, 400: -0.24, 500: 0, 600: 0.16, 700: 0.32, 800: 0.46, 900: 0.58, 950: 0.72 };

const hexToRgb = (hex) => {
    const m = /^#?([0-9a-f]{6})$/i.exec((hex || '').trim());
    if (!m) return null;
    const n = parseInt(m[1], 16);
    return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
};

// Re-base the brand (orange) scale on the workspace accent so the whole UI
// recolours. Skipped for the default ember accent so it stays pixel-exact.
const applyAccentScale = (accent) => {
    const root = document.documentElement;
    const rgb = hexToRgb(accent);
    if (!rgb || (accent || '').toLowerCase() === DEFAULT_ACCENT) {
        Object.keys(ORANGE_MIX).forEach((s) => root.style.removeProperty(`--c-orange-${s}`));
        return;
    }
    const mix = (c, f) => Math.round(f < 0 ? c + (255 - c) * -f : c * (1 - f));
    Object.entries(ORANGE_MIX).forEach(([shade, f]) => {
        root.style.setProperty(`--c-orange-${shade}`, `${mix(rgb.r, f)} ${mix(rgb.g, f)} ${mix(rgb.b, f)}`);
    });
};

// Apply the resolved theme + brand accent (shared by HandleInertiaRequests) to
// <html> on first paint and after every visit, and persist the theme in a
// cookie so the next server render (app.blade.php) sets it with no flash.
const applyTheme = (page) => {
    const t = page?.props?.theme;
    if (t === 'light' || t === 'dark' || t === 'black') {
        document.documentElement.dataset.theme = t;
        document.cookie = `tt_theme=${t};path=/;max-age=31536000;samesite=lax`;
    }
    const accent = page?.props?.branding?.accent_color;
    if (accent) {
        document.documentElement.style.setProperty('--brand-accent', accent);
        applyAccentScale(accent);
    }
    if (page?.props?.branding?.name) brandName = page.props.branding.name;
};
router.on('success', (event) => applyTheme(event.detail.page));

// Auto-recover from stale code chunks after a deploy: when a release ships new
// asset hashes, a browser that still holds the old page references will 404 on
// a dynamic import and render blank. Reload once to fetch the fresh assets
// instead. Guarded by a short cooldown so a genuinely-missing chunk can't loop.
window.addEventListener('vite:preloadError', () => {
    const last = Number(sessionStorage.getItem('vitePreloadReloadAt') || 0);
    if (Date.now() - last > 10000) {
        sessionStorage.setItem('vitePreloadReloadAt', String(Date.now()));
        window.location.reload();
    }
});

createInertiaApp({
    // Idempotent: the marketing CMS `seo_title` already contains the brand (e.g.
    // "Features — Staffvora", or "Staffvora — …" on the home page), so appending
    // the brand again doubled it. A page that already names the brand is left
    // alone; a bare title (the blog, and app pages) still gets the suffix.
    title: (title) => {
        if (!title) return brandName;
        return title.includes(brandName) ? title : `${title} - ${brandName}`;
    },
    resolve: (name) => resolvePageComponent(`./Pages/${name}.tsx`, import.meta.glob('./Pages/**/*.tsx'))
        .catch(() => resolvePageComponent(`./Pages/${name}.jsx`, import.meta.glob('./Pages/**/*.jsx'))),
    setup({ el, App, props }) {
        applyTheme(props.initialPage);
        const root = createRoot(el);

        root.render(
            <ErrorBoundary>
                <App {...props} />
                <Toaster 
                    position="top-right"
                    toastOptions={{
                        duration: 4000,
                        style: {
                            background: '#363636',
                            color: '#fff',
                        },
                        success: {
                            duration: 3000,
                            style: {
                                background: '#22c55e',
                            },
                        },
                        error: {
                            duration: 5000,
                            style: {
                                background: '#ef4444',
                            },
                        },
                    }}
                />
            </ErrorBoundary>
        );
    },
    progress: {
        // Ember orange + thicker bar — the old slate grey was invisible on
        // the dark nav, so page switches felt unresponsive.
        color: '#f97316',
        includeCSS: true,
        showSpinner: true,
    },
});
