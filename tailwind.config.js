import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

// Build a Tailwind colour scale whose every shade reads a per-theme CSS var
// (channel triple) defined in app.css, so `bg-slate-900`, `text-slate-400/70`,
// etc. all re-theme via `data-theme` on <html>.
const scaleVar = (name) =>
    [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950].reduce((scale, shade) => {
        scale[shade] = `rgb(var(--c-${name}-${shade}) / <alpha-value>)`;
        return scale;
    }, {});

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'system-ui', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'sans-serif'],
            },
            colors: {
                // Consistent text colors (legacy — kept for back-compat)
                'text-primary': '#0f172a', // slate-900
                'text-secondary': '#334155', // slate-700
                'text-tertiary': '#64748b', // slate-500

                // Semantic theme tokens — var-backed, switch via data-theme on
                // <html>. Use these (bg-app, bg-surface, text-body, text-muted,
                // border-line, bg-accent, …) for new components.
                app: 'var(--bg)',
                surface: 'var(--surface)',
                'surface-2': 'var(--surface-2)',
                line: 'var(--border)',
                body: 'var(--text)',
                muted: 'var(--text-muted)',
                subtle: 'var(--text-subtle)',
                accent: 'var(--accent)',

                // Remap the slate/gray scales to per-theme CSS vars (see app.css)
                // so the ~40 pages built against the original dark palette
                // re-theme automatically. <alpha-value> keeps `/opacity` working.
                slate: scaleVar('slate'),
                gray: scaleVar('gray'),
            },
        },
    },

    plugins: [forms],
};
