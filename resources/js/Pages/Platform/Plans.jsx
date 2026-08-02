import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import PlatformLayout, { PlatformPageHeader } from '@/Layouts/PlatformLayout';
import { Button, Card, Badge, Field, Input, Select, Toggle, EmptyState } from '@/Components/ui';
import Modal from '@/Components/Modal';
import {
    ArrowDown, ArrowUp, Archive, ArchiveRestore, Check, Copy, History,
    Layers, ListPlus, Plus, Star, ToggleLeft,
} from 'lucide-react';
import {
    UNLIMITED, VISIBILITY_META, centsToInput, groupRegistry, inputToCents,
    money, planValueToInput, rt,
} from './entitlementUi';

// ─────────────────────────────────────────────────────────────────────────────
// PLANS & PRICING — the operator's pricing desk.
//
// Everything the owner asked to run without a developer lives here: create,
// rename, duplicate, archive/restore, reorder, monthly AND yearly price, the
// public/hidden/custom/legacy lifecycle, which plan new signups land on, and the
// per-plan value of every feature in the catalogue.
//
// The feature rows are rendered from the SERVER's registry, so a feature added
// in the Feature catalogue appears here automatically — no code change.
// ─────────────────────────────────────────────────────────────────────────────

const NUMERIC_HINT = {
    max: 'Blank or "unlimited" = no ceiling.',
    min: 'Blank = inherit the strict default. Type "unlimited" for no floor.',
};

/** One numeric entitlement field: a number, or the word "unlimited". */
function LimitInput({ entry, value, onChange, id }) {
    const isUnlimited = String(value).toLowerCase() === UNLIMITED;

    return (
        <div className="flex shrink-0 items-center gap-1">
            <Input
                id={id}
                type="text"
                inputMode="numeric"
                spellCheck={false}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={entry.kind === 'max' ? 'unlimited' : 'default'}
                className="w-28 text-right"
                aria-label={`${entry.label} value`}
            />
            <button
                type="button"
                onClick={() => onChange(isUnlimited ? '' : UNLIMITED)}
                aria-pressed={isUnlimited}
                title={isUnlimited ? 'Clear' : 'Set to unlimited'}
                className={`h-9 w-8 shrink-0 rounded-md border border-line text-sm font-semibold transition cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app ${
                    isUnlimited ? 'bg-accent/10 text-accent' : 'bg-surface-2 text-subtle hover:text-body'
                }`}
            >
                ∞
            </button>
        </div>
    );
}

/** The catalogue-driven entitlement editor shared by every plan card. */
function EntitlementGrid({ registry, values, onChange, idPrefix }) {
    const groups = useMemo(() => groupRegistry(registry), [registry]);

    return (
        <div className="space-y-4">
            {groups.map(({ group, entries }) => (
                <div key={group}>
                    <div className="mb-2 flex items-baseline justify-between gap-2">
                        <div className="text-[11px] font-semibold uppercase tracking-wider text-subtle">{group}</div>
                    </div>
                    <div className="divide-y divide-line rounded-lg border border-line">
                        {entries.map((entry) => {
                            const id = `${idPrefix}-${entry.key}`;
                            const value = values[entry.key];
                            return (
                                <div key={entry.key} className="flex items-center justify-between gap-3 px-3 py-2">
                                    <label htmlFor={id} className="min-w-0 cursor-pointer text-sm text-body" title={entry.help || ''}>
                                        {entry.label}
                                        {entry.kind !== 'flag' && (
                                            <span className="ml-1.5 text-[11px] text-subtle">{NUMERIC_HINT[entry.kind]}</span>
                                        )}
                                    </label>
                                    {entry.kind === 'flag' ? (
                                        <Toggle id={id} checked={!!value} onChange={(next) => onChange(entry.key, next)} />
                                    ) : (
                                        <LimitInput id={id} entry={entry} value={value ?? ''} onChange={(v) => onChange(entry.key, v)} />
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </div>
            ))}
        </div>
    );
}

// ─── One plan card ───────────────────────────────────────────────────────────
function PlanEditor({ plan, registry, visibilities, preview, position, count, onMove, onLifecycle }) {
    const form = useForm({
        name: plan.name,
        price: centsToInput(plan.price_cents),
        price_yearly: centsToInput(plan.price_cents_yearly),
        currency: plan.currency || 'USD',
        trial_days: plan.trial_days ?? '',
        visibility: plan.visibility || 'public',
        is_default_trial: !!plan.is_default_trial,
        reason: '',
        entitlements: Object.fromEntries(
            Object.entries(registry).map(([key, entry]) => [
                key,
                entry.kind === 'flag' ? !!plan.entitlements?.[key] : planValueToInput(entry, plan.entitlements?.[key]),
            ]),
        ),
    });

    const [previewSaved, setPreviewSaved] = useState(false);
    const saved = preview ? previewSaved : form.recentlySuccessful;
    const meta = VISIBILITY_META[form.data.visibility] || VISIBILITY_META.hidden;

    const setEnt = (key, value) => form.setData('entitlements', { ...form.data.entitlements, [key]: value });

    const save = (e) => {
        e.preventDefault();
        if (preview) {
            setPreviewSaved(true);
            setTimeout(() => setPreviewSaved(false), 1500);
            return;
        }
        form
            .transform((d) => ({
                name: d.name,
                price_cents: inputToCents(d.price) ?? 0,
                price_cents_yearly: inputToCents(d.price_yearly),
                currency: d.currency,
                trial_days: d.trial_days === '' ? null : Number(d.trial_days),
                visibility: d.visibility,
                is_default_trial: d.is_default_trial,
                entitlements: d.entitlements,
                reason: d.reason || null,
            }))
            .put(rt('platform.plans.update', plan.id), { preserveScroll: true });
    };

    const header = (
        <div className="space-y-3 border-b border-line px-5 py-4">
            <div className="flex items-start justify-between gap-3">
                <Field className="min-w-0" label="Plan name" htmlFor={`name-${plan.id}`}>
                    <Input
                        id={`name-${plan.id}`}
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        className="font-semibold"
                    />
                </Field>
                <div className="flex shrink-0 flex-col items-end gap-1.5 pt-6">
                    <Badge tone="neutral" className="uppercase tracking-wide">{plan.key}</Badge>
                    {plan.archived_at && <Badge tone="danger">Archived</Badge>}
                    {plan.is_default_trial && (
                        <Badge tone="accent" className="gap-1">
                            <Star className="h-3 w-3" /> Signup plan
                        </Badge>
                    )}
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-2 text-xs text-subtle">
                <Badge tone={meta.tone}>{meta.label}</Badge>
                <span>·</span>
                <span>{plan.subscribers} {plan.subscribers === 1 ? 'subscriber' : 'subscribers'}</span>
                <div className="ml-auto flex items-center gap-1">
                    <button
                        type="button"
                        onClick={() => onMove(plan.id, -1)}
                        disabled={position === 0}
                        aria-label={`Move ${plan.name} earlier`}
                        className="rounded-md border border-line bg-surface-2 p-1.5 text-subtle transition cursor-pointer hover:text-body disabled:cursor-not-allowed disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    >
                        <ArrowUp className="h-3.5 w-3.5" />
                    </button>
                    <button
                        type="button"
                        onClick={() => onMove(plan.id, 1)}
                        disabled={position === count - 1}
                        aria-label={`Move ${plan.name} later`}
                        className="rounded-md border border-line bg-surface-2 p-1.5 text-subtle transition cursor-pointer hover:text-body disabled:cursor-not-allowed disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    >
                        <ArrowDown className="h-3.5 w-3.5" />
                    </button>
                </div>
            </div>
        </div>
    );

    return (
        <Card header={header} className={`flex flex-col ${plan.archived_at ? 'opacity-75' : ''}`}>
            <form onSubmit={save} className="flex flex-1 flex-col gap-5">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field
                        label={`Monthly (${form.data.currency}/seat)`}
                        htmlFor={`price-${plan.id}`}
                        error={form.errors.price_cents}
                    >
                        <Input
                            id={`price-${plan.id}`}
                            type="number"
                            step="0.01"
                            min="0"
                            value={form.data.price}
                            onChange={(e) => form.setData('price', e.target.value)}
                        />
                    </Field>
                    <Field
                        label={`Yearly (${form.data.currency}/seat)`}
                        htmlFor={`pricey-${plan.id}`}
                        hint="Blank = no list price (“talk to us”)."
                        error={form.errors.price_cents_yearly}
                    >
                        <Input
                            id={`pricey-${plan.id}`}
                            type="number"
                            step="0.01"
                            min="0"
                            placeholder="negotiated"
                            value={form.data.price_yearly}
                            onChange={(e) => form.setData('price_yearly', e.target.value)}
                        />
                    </Field>
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <Field label="Currency" htmlFor={`cur-${plan.id}`}>
                        <Input
                            id={`cur-${plan.id}`}
                            maxLength={3}
                            value={form.data.currency}
                            onChange={(e) => form.setData('currency', e.target.value.toUpperCase())}
                        />
                    </Field>
                    <Field label="Trial (days)" htmlFor={`trial-${plan.id}`}>
                        <Input
                            id={`trial-${plan.id}`}
                            type="number"
                            min="0"
                            max="365"
                            placeholder="none"
                            value={form.data.trial_days}
                            onChange={(e) => form.setData('trial_days', e.target.value)}
                        />
                    </Field>
                    <Field label="Visibility" htmlFor={`vis-${plan.id}`} hint={meta.help} error={form.errors.visibility}>
                        <Select
                            id={`vis-${plan.id}`}
                            value={form.data.visibility}
                            onChange={(e) => form.setData('visibility', e.target.value)}
                        >
                            {visibilities.map((v) => (
                                <option key={v} value={v}>{VISIBILITY_META[v]?.label || v}</option>
                            ))}
                        </Select>
                    </Field>
                </div>

                <div className="flex items-center justify-between gap-3 rounded-lg border border-line bg-surface-2 px-4 py-3">
                    <div className="min-w-0">
                        <div className="text-sm font-medium text-body">Default signup plan</div>
                        <div className="text-xs text-subtle">
                            Every brand-new workspace starts here. Exactly one plan can hold it.
                        </div>
                    </div>
                    <Toggle
                        checked={form.data.is_default_trial}
                        disabled={!!plan.archived_at}
                        onChange={(next) => form.setData('is_default_trial', next)}
                    />
                </div>

                <EntitlementGrid
                    registry={registry}
                    values={form.data.entitlements}
                    onChange={setEnt}
                    idPrefix={`p${plan.id}`}
                />

                <Field label="Why (optional)" htmlFor={`why-${plan.id}`} hint="Stored on the activity log entry.">
                    <Input
                        id={`why-${plan.id}`}
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        placeholder="e.g. Q3 price review"
                    />
                </Field>

                <div className="mt-auto flex flex-wrap items-center gap-2 pt-1">
                    <Button type="submit" loading={form.processing} disabled={form.processing} icon={<Check className="h-4 w-4" />}>
                        {saved ? 'Saved' : 'Save plan'}
                    </Button>
                    <Button
                        type="button"
                        variant="secondary"
                        size="md"
                        icon={<Copy className="h-4 w-4" />}
                        onClick={() => onLifecycle('duplicate', plan)}
                    >
                        Duplicate
                    </Button>
                    {plan.archived_at ? (
                        <Button
                            type="button"
                            variant="ghost"
                            icon={<ArchiveRestore className="h-4 w-4" />}
                            onClick={() => onLifecycle('restore', plan)}
                        >
                            Restore
                        </Button>
                    ) : (
                        <Button
                            type="button"
                            variant="ghost"
                            className="text-danger"
                            icon={<Archive className="h-4 w-4" />}
                            onClick={() => onLifecycle('archive', plan)}
                        >
                            Archive
                        </Button>
                    )}
                    {saved && <span className="text-sm text-success">Changes saved.</span>}
                </div>
            </form>
        </Card>
    );
}

// ─── Create-plan dialog ──────────────────────────────────────────────────────
function NewPlanModal({ open, onClose, preview }) {
    const form = useForm({ name: '', key: '', price: '', price_yearly: '', currency: 'USD', trial_days: '', visibility: 'hidden' });

    const submit = (e) => {
        e.preventDefault();
        if (preview) { onClose(); return; }
        form
            .transform((d) => ({
                name: d.name,
                key: d.key || null,
                price_cents: inputToCents(d.price) ?? 0,
                price_cents_yearly: inputToCents(d.price_yearly),
                currency: d.currency,
                trial_days: d.trial_days === '' ? null : Number(d.trial_days),
                visibility: d.visibility,
            }))
            .post(rt('platform.plans.store'), {
                preserveScroll: true,
                onSuccess: () => { form.reset(); onClose(); },
            });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            <form onSubmit={submit} className="p-6">
                <h3 className="text-lg font-semibold text-body">New plan</h3>
                <p className="mt-1 text-sm text-muted">
                    It starts hidden and grants nothing. Turn features on, then publish it.
                </p>

                <div className="mt-5 space-y-4">
                    <Field label="Name" htmlFor="np-name" required error={form.errors.name}>
                        <Input id="np-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="Growth" />
                    </Field>
                    <Field
                        label="Key"
                        htmlFor="np-key"
                        hint="Permanent identifier. Leave blank to derive it from the name. Nothing in the app branches on it."
                        error={form.errors.key}
                    >
                        <Input id="np-key" value={form.data.key} onChange={(e) => form.setData('key', e.target.value)} placeholder="auto" />
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Monthly / seat" htmlFor="np-price">
                            <Input id="np-price" type="number" step="0.01" min="0" value={form.data.price} onChange={(e) => form.setData('price', e.target.value)} placeholder="0.00" />
                        </Field>
                        <Field label="Yearly / seat" htmlFor="np-pricey" hint="Blank = negotiated.">
                            <Input id="np-pricey" type="number" step="0.01" min="0" value={form.data.price_yearly} onChange={(e) => form.setData('price_yearly', e.target.value)} placeholder="negotiated" />
                        </Field>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field label="Currency" htmlFor="np-cur">
                            <Input id="np-cur" maxLength={3} value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value.toUpperCase())} />
                        </Field>
                        <Field label="Trial (days)" htmlFor="np-trial">
                            <Input id="np-trial" type="number" min="0" max="365" value={form.data.trial_days} onChange={(e) => form.setData('trial_days', e.target.value)} placeholder="none" />
                        </Field>
                        <Field label="Visibility" htmlFor="np-vis">
                            <Select id="np-vis" value={form.data.visibility} onChange={(e) => form.setData('visibility', e.target.value)}>
                                {Object.keys(VISIBILITY_META).map((v) => (
                                    <option key={v} value={v}>{VISIBILITY_META[v].label}</option>
                                ))}
                            </Select>
                        </Field>
                    </div>
                </div>

                <div className="mt-6 flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
                    <Button type="submit" loading={form.processing} disabled={form.processing || !form.data.name}>
                        Create plan
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

// ─── Lifecycle confirmation (archive / restore / duplicate) ──────────────────
const LIFECYCLE_COPY = {
    duplicate: {
        title: 'Duplicate this plan?',
        body: (p) => `A hidden copy of ${p.name} is created with the same prices and features. Nobody is moved onto it.`,
        confirm: 'Duplicate',
        tone: 'primary',
        route: 'platform.plans.duplicate',
    },
    archive: {
        title: 'Archive this plan?',
        body: (p) => `${p.name} disappears from the pricing page and every picker. Its ${p.subscribers} current subscriber(s) keep it and keep working.`,
        confirm: 'Archive',
        tone: 'danger',
        route: 'platform.plans.archive',
    },
    restore: {
        title: 'Restore this plan?',
        body: (p) => `${p.name} comes back as HIDDEN. Set it to Public when you want it advertised again.`,
        confirm: 'Restore',
        tone: 'primary',
        route: 'platform.plans.restore',
    },
};

function LifecycleModal({ action, plan, onClose, preview }) {
    const [reason, setReason] = useState('');
    const copy = action ? LIFECYCLE_COPY[action] : null;

    const run = () => {
        if (!preview && copy) {
            router.post(rt(copy.route, plan.id), { reason: reason || null }, { preserveScroll: true });
        }
        setReason('');
        onClose();
    };

    return (
        <Modal show={!!action} onClose={onClose} maxWidth="md">
            {copy && plan && (
                <div className="p-6">
                    <h3 className="text-lg font-semibold text-body">{copy.title}</h3>
                    <p className="mt-2 text-sm text-muted">{copy.body(plan)}</p>
                    <div className="mt-4">
                        <Field label="Why (optional)" htmlFor="lc-reason" hint="Recorded in the activity log.">
                            <Input id="lc-reason" value={reason} onChange={(e) => setReason(e.target.value)} />
                        </Field>
                    </div>
                    <div className="mt-6 flex justify-end gap-2">
                        <Button variant="secondary" onClick={onClose}>Cancel</Button>
                        <Button variant={copy.tone} onClick={run}>{copy.confirm}</Button>
                    </div>
                </div>
            )}
        </Modal>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// DEV_SAMPLE — offline data for preview={true}. Mirrors the controller shape.
// ─────────────────────────────────────────────────────────────────────────────
const DEV_REGISTRY = {
    max_users: { key: 'max_users', group: 'Limits', label: 'User seats', help: 'Maximum users.', kind: 'max', unit: 'seats', default: 1 },
    max_storage_gb: { key: 'max_storage_gb', group: 'Limits', label: 'Storage (max GB)', help: 'Screenshot + upload storage.', kind: 'max', unit: 'GB', default: 0 },
    max_screenshots_per_hour: { key: 'max_screenshots_per_hour', group: 'Monitoring', label: 'Screenshots per hour (max)', help: 'Highest rate.', kind: 'max', unit: '/hour', default: 0 },
    min_auto_pause_minutes: { key: 'min_auto_pause_minutes', group: 'Monitoring', label: 'Auto-pause floor', help: 'Tightest idle pause.', kind: 'min', unit: 'min', default: 10 },
    monitoring: { key: 'monitoring', group: 'Features', label: 'Screenshot monitoring', help: 'Capture screenshots.', kind: 'flag', default: false },
    ai: { key: 'ai', group: 'Features', label: 'AI assistant', help: 'Day summaries.', kind: 'flag', default: false },
    machine_compliance: { key: 'machine_compliance', group: 'Agency', label: 'Machine compliance', help: 'Per-PC inventory.', kind: 'flag', default: false },
    white_label: { key: 'white_label', group: 'Branding', label: 'White-label branding', help: 'Custom brand.', kind: 'flag', default: false },
};

const DEV_PLANS = [
    { id: 1, key: 'free', name: 'Free', price_cents: 0, price_cents_yearly: 0, currency: 'USD', trial_days: null, visibility: 'public', is_public: true, is_default_trial: false, archived_at: null, sort: 10, subscribers: 42, entitlements: { max_users: 2, max_storage_gb: 1, max_screenshots_per_hour: 0, min_auto_pause_minutes: 10, monitoring: false, ai: false, machine_compliance: false, white_label: false } },
    { id: 2, key: 'starter', name: 'Starter', price_cents: 500, price_cents_yearly: 5000, currency: 'USD', trial_days: 14, visibility: 'public', is_public: true, is_default_trial: false, archived_at: null, sort: 20, subscribers: 18, entitlements: { max_users: 10, max_storage_gb: 10, max_screenshots_per_hour: 12, min_auto_pause_minutes: 5, monitoring: true, ai: false, machine_compliance: false, white_label: false } },
    { id: 3, key: 'pro', name: 'Growth', price_cents: 900, price_cents_yearly: 9000, currency: 'USD', trial_days: 14, visibility: 'public', is_public: true, is_default_trial: true, archived_at: null, sort: 30, subscribers: 27, entitlements: { max_users: 50, max_storage_gb: 50, max_screenshots_per_hour: 30, min_auto_pause_minutes: 1, monitoring: true, ai: true, machine_compliance: false, white_label: true } },
    { id: 4, key: 'enterprise', name: 'Enterprise', price_cents: 1800, price_cents_yearly: null, currency: 'USD', trial_days: 30, visibility: 'custom', is_public: false, is_default_trial: false, archived_at: null, sort: 40, subscribers: 3, entitlements: { max_users: null, max_storage_gb: null, max_screenshots_per_hour: 60, min_auto_pause_minutes: 1, monitoring: true, ai: true, machine_compliance: true, white_label: true } },
    { id: 5, key: 'legacy_solo', name: 'Solo (2024)', price_cents: 300, price_cents_yearly: 3000, currency: 'USD', trial_days: null, visibility: 'legacy', is_public: false, is_default_trial: false, archived_at: '2026-05-01', sort: 50, subscribers: 6, entitlements: { max_users: 1, max_storage_gb: 2, max_screenshots_per_hour: 6, min_auto_pause_minutes: 10, monitoring: true, ai: false, machine_compliance: false, white_label: false } },
];

export default function Plans({ plans, registry, visibilities, preview = false }) {
    const sourcePlans = preview ? DEV_PLANS : (plans || []);
    const reg = preview ? DEV_REGISTRY : (registry || {});
    const vis = (preview ? Object.keys(VISIBILITY_META) : visibilities) || Object.keys(VISIBILITY_META);
    const isOwner = preview || usePage().props.platformUser?.role === 'owner';

    const [showArchived, setShowArchived] = useState(false);
    const [newOpen, setNewOpen] = useState(false);
    const [lifecycle, setLifecycle] = useState(null); // { action, plan }
    // Local reorder buffer — the operator nudges rows, then commits once.
    const [order, setOrder] = useState(null);
    const [reordering, setReordering] = useState(false);

    const ordered = useMemo(() => {
        if (!order) return sourcePlans;
        const byId = new Map(sourcePlans.map((p) => [p.id, p]));
        return order.map((id) => byId.get(id)).filter(Boolean);
    }, [sourcePlans, order]);

    const visible = ordered.filter((p) => showArchived || !p.archived_at);
    const publicCount = sourcePlans.filter((p) => p.visibility === 'public' && !p.archived_at).length;
    const archivedCount = sourcePlans.filter((p) => p.archived_at).length;
    const listPrice = sourcePlans.find((p) => p.is_default_trial);

    const move = (id, delta) => {
        const ids = ordered.map((p) => p.id);
        const from = ids.indexOf(id);
        const to = from + delta;
        if (from < 0 || to < 0 || to >= ids.length) return;
        const next = [...ids];
        [next[from], next[to]] = [next[to], next[from]];
        setOrder(next);
    };

    const saveOrder = () => {
        if (!order) return;
        if (preview) { setOrder(null); return; }
        setReordering(true);
        router.post(rt('platform.plans.reorder'), { order }, {
            preserveScroll: true,
            onFinish: () => { setReordering(false); setOrder(null); },
        });
    };

    return (
        <PlatformLayout
            title="Plans"
            header={
                <PlatformPageHeader
                    title="Plans & pricing"
                    description="Everything about what a plan costs and unlocks — no developer required. Changes apply to every workspace on that plan; per-customer exceptions live on each tenant's page."
                    actions={
                        <>
                            {/* The feature catalogue is owner-only server-side; don't
                                offer a link that would only ever return 403. */}
                            {isOwner && (
                                <Button as={Link} href={rt('platform.features')} variant="ghost" size="sm" icon={<ToggleLeft className="h-4 w-4" />}>
                                    Features
                                </Button>
                            )}
                            <Button as={Link} href={rt('platform.addons')} variant="ghost" size="sm" icon={<Layers className="h-4 w-4" />}>
                                Add-ons
                            </Button>
                            <Button as={Link} href={rt('platform.audit')} variant="ghost" size="sm" icon={<History className="h-4 w-4" />}>
                                Activity
                            </Button>
                            <Button size="sm" icon={<Plus className="h-4 w-4" />} onClick={() => setNewOpen(true)}>
                                New plan
                            </Button>
                        </>
                    }
                />
            }
        >
            <Head title="Plans" />

            <div className="mb-5 flex flex-wrap items-center gap-2 text-sm text-muted">
                <Badge tone="neutral" size="md">{sourcePlans.length} plans</Badge>
                <Badge tone="success" size="md">{publicCount} public</Badge>
                {listPrice && (
                    <Badge tone="accent" size="md" className="gap-1">
                        <Star className="h-3.5 w-3.5" />
                        Signups land on {listPrice.name} ({money(listPrice.price_cents, listPrice.currency)}/mo)
                    </Badge>
                )}
                {archivedCount > 0 && (
                    <Button size="sm" variant="ghost" onClick={() => setShowArchived((v) => !v)}>
                        {showArchived ? 'Hide' : 'Show'} {archivedCount} archived
                    </Button>
                )}
                {order && (
                    <div className="ml-auto flex items-center gap-2">
                        <Button size="sm" variant="ghost" onClick={() => setOrder(null)}>Undo</Button>
                        <Button size="sm" loading={reordering} onClick={saveOrder} icon={<ListPlus className="h-4 w-4" />}>
                            Save order
                        </Button>
                    </div>
                )}
            </div>

            {visible.length === 0 ? (
                <EmptyState
                    title="No plans yet"
                    description="Create your first plan to start selling. It stays hidden until you publish it."
                />
            ) : (
                <div className="grid items-start gap-4 lg:grid-cols-2 xl:grid-cols-3">
                    {visible.map((plan, i) => (
                        <PlanEditor
                            key={plan.id}
                            plan={plan}
                            registry={reg}
                            visibilities={vis}
                            preview={preview}
                            position={i}
                            count={visible.length}
                            onMove={move}
                            onLifecycle={(action, p) => setLifecycle({ action, plan: p })}
                        />
                    ))}
                </div>
            )}

            <NewPlanModal open={newOpen} onClose={() => setNewOpen(false)} preview={preview} />
            <LifecycleModal
                action={lifecycle?.action}
                plan={lifecycle?.plan}
                onClose={() => setLifecycle(null)}
                preview={preview}
            />
        </PlatformLayout>
    );
}
