import { usePage } from '@inertiajs/react';

/**
 * Timezone- and format-aware date helpers bound to the company's configured
 * display settings (shared from the server as the `display` Inertia prop:
 * { timezone, format }). Use this instead of raw toLocaleTimeString so every
 * timestamp renders in the chosen business timezone (e.g. Asia/Karachi),
 * regardless of the viewer's machine timezone.
 */
export function useFormatters() {
    const display = usePage().props.display || {};
    const tz = display.timezone || 'Asia/Karachi';
    const hour12 = String(display.format) !== '24';

    const safe = (iso, fn) => {
        if (!iso) return '';
        try {
            return fn(new Date(iso));
        } catch {
            return '';
        }
    };

    const formatTime = (iso) =>
        safe(iso, (d) => d.toLocaleTimeString('en-US', { timeZone: tz, hour: 'numeric', minute: '2-digit', hour12 }));

    const formatDateTime = (iso) =>
        safe(iso, (d) => d.toLocaleString('en-US', {
            timeZone: tz, year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12,
        }));

    const formatDate = (iso) =>
        safe(iso, (d) => d.toLocaleDateString('en-US', { timeZone: tz, year: 'numeric', month: 'short', day: 'numeric' }));

    return { formatTime, formatDateTime, formatDate, tz, hour12 };
}
