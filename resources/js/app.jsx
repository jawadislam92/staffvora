import './bootstrap';
import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { Toaster } from 'react-hot-toast';
import ErrorBoundary from '@/Components/ErrorBoundary';

const appName = import.meta.env.VITE_APP_NAME || 'SA Track';

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
