// Shared helpers for the operator entitlement editors (Plans, Add-ons, the
// per-tenant Access tab and the Feature catalogue).
//
// This file holds PRESENTATION only. Every access decision — and every
// sanitising rule — lives server-side in App\Tenancy\Entitlements; these helpers
// just render what the server sent and shape the form values it expects back.
//
// NOTE: `.js`, not `.jsx`, on purpose — Inertia's page resolver only globs
// `./Pages/**/*.{jsx,tsx}`, so this is never mistaken for a page.

/** The sentinel the server understands as "no ceiling / no floor". */
export const UNLIMITED = 'unlimited';

/** Safe Ziggy route() — returns '#' when routes are unavailable (offline preview). */
export const rt = (name, ...params) => {
    try {
        return typeof route === 'function' ? route(name, ...params) : '#';
    } catch {
        return '#';
    }
};

export const money = (cents, currency = 'USD') => {
    if (cents === null || cents === undefined) return '—';
    try {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currency || 'USD',
            maximumFractionDigits: 2,
        }).format(cents / 100);
    } catch {
        return `${(cents / 100).toFixed(2)}`;
    }
};

/** cents -> editable major-unit string ('' when null, so "no yearly price" survives). */
export const centsToInput = (cents) => (cents === null || cents === undefined ? '' : String(cents / 100));

/** major-unit string -> cents (null when blank, which the server reads as "not set"). */
export const inputToCents = (value) => {
    if (value === '' || value === null || value === undefined) return null;
    const n = Number.parseFloat(value);
    return Number.isFinite(n) ? Math.max(0, Math.round(n * 100)) : null;
};

/**
 * Group a registry ({ key: entry }) into [{ group, entries: [...] }], preserving
 * the server's order — the catalogue's `sort` is the operator's own ordering.
 */
export const groupRegistry = (registry) => {
    const out = [];
    const index = new Map();
    Object.entries(registry || {}).forEach(([key, entry]) => {
        const group = entry.group || 'Features';
        if (!index.has(group)) {
            index.set(group, { group, entries: [] });
            out.push(index.get(group));
        }
        index.get(group).entries.push({ ...entry, key });
    });
    return out;
};

/**
 * A stored PLAN value -> the string the numeric field shows.
 *   max kind: null = unlimited, so show the sentinel (blank would read as
 *             "unlimited" too, but showing it makes the intent explicit).
 *   min kind: null/absent = inherit the catalogue's strict default -> blank.
 */
export const planValueToInput = (entry, value) => {
    if (value === null || value === undefined) return entry.kind === 'max' ? UNLIMITED : '';
    if (value === UNLIMITED) return UNLIMITED;
    return String(value);
};

/** A sparse ADD-ON value -> field string. Blank always means "says nothing". */
export const addonValueToInput = (value) => {
    if (value === null || value === undefined) return '';
    if (value === UNLIMITED) return UNLIMITED;
    return String(value);
};

/** Render any resolved entitlement value for reading: "Unlimited" / "Off" / "30 days". */
export const formatValue = (entry, value) => {
    if (!entry) return String(value);
    if (entry.kind === 'flag') return value ? 'On' : 'Off';
    if (value === null || value === undefined || value === UNLIMITED) return 'Unlimited';
    const n = Number(value);
    if (entry.kind === 'max' && n === 0) return 'Not included';
    const rendered = entry.unit ? `${n} ${entry.unit}` : String(n);
    return entry.kind === 'min' ? `from ${rendered}` : rendered;
};

/** Where a resolved value came from -> Badge tone + label. */
export const SOURCE_META = {
    override: { tone: 'warning', label: 'Override' },
    addon: { tone: 'info', label: 'Add-on' },
    plan: { tone: 'accent', label: 'Plan' },
    default: { tone: 'neutral', label: 'Default' },
    unknown: { tone: 'danger', label: 'Retired' },
};

export const VISIBILITY_META = {
    public: { tone: 'success', label: 'Public', help: 'Listed on the pricing page and open to self-serve signup.' },
    hidden: { tone: 'neutral', label: 'Hidden', help: 'Exists and works, but is not advertised anywhere.' },
    custom: { tone: 'info', label: 'Custom', help: 'Negotiated — an operator assigns it by hand.' },
    legacy: { tone: 'warning', label: 'Legacy', help: 'Grandfathered: current subscribers keep it, nobody new lands on it.' },
};

/** Shallow before/after diff for an audit row — only the keys that moved. */
export const diffEntries = (before, after) => {
    const keys = new Set([...Object.keys(before || {}), ...Object.keys(after || {})]);
    const rows = [];
    keys.forEach((key) => {
        const a = before ? before[key] : undefined;
        const b = after ? after[key] : undefined;
        if (JSON.stringify(a) !== JSON.stringify(b)) rows.push({ key, from: a, to: b });
    });
    return rows;
};

/** Compact, readable rendering of an audit before/after scalar or object. */
export const renderAuditValue = (value) => {
    if (value === null || value === undefined) return '—';
    if (typeof value === 'boolean') return value ? 'on' : 'off';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
};
