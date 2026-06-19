import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

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
                // border-line, bg-accent, …) instead of hard-coded slate-*.
                app: 'var(--bg)',
                surface: 'var(--surface)',
                'surface-2': 'var(--surface-2)',
                line: 'var(--border)',
                body: 'var(--text)',
                muted: 'var(--text-muted)',
                subtle: 'var(--text-subtle)',
                accent: 'var(--accent)',
            },
        },
    },

    plugins: [forms],
};
