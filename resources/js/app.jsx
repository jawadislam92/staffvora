import './bootstrap';
import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { Toaster } from 'react-hot-toast';
import ErrorBoundary from '@/Components/ErrorBoundary';

const appName = import.meta.env.VITE_APP_NAME || 'Tracksuite';

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
    if (accent) document.documentElement.style.setProperty('--brand-accent', accent);
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
    title: (title) => title ? `${title} - ${appName}` : appName,
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
