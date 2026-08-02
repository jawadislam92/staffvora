import { Head, Link, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import PlatformLayout, { PlatformPageHeader } from '@/Layouts/PlatformLayout';
import { Button, Card, Badge, Field, Input, Select, Textarea, Toggle, EmptyState } from '@/Components/ui';
import Modal from '@/Components/Modal';
import { Archive, ArchiveRestore, Info, Plus, Save, Users } from 'lucide-react';
import {
    UNLIMITED, VISIBILITY_META, addonValueToInput, centsToInput,
    groupRegistry, inputToCents, money, rt,
} from './entitlementUi';

// ─────────────────────────────────────────────────────────────────────────────
// ADD-ONS — sellable bundles of entitlements that sit on top of a plan.
//
// This is how "Machine Compliance is not welded to a plan" becomes real: an
// add-on is a sparse list of grants, attachable to any company from its own
// page, priced monthly and yearly, with the same public/hidden/custom/legacy
// lifecycle plans have.
//
// The editor is SPARSE on purpose. A flag left off is not stored, because the
// add-on layer resolves ABOVE the plan — storing `false` there would revoke
// what the customer's plan already grants.
// ─────────────────────────────────────────────────────────────────────────────

function AddonEntitlementEditor({ registry, values, onChange, idPrefix }) {
    const groups = useMemo(() => groupRegistry(registry), [registry]);

    return (
        <div className="space-y-4">
            {groups.map(({ group, entries }) => (
                <div key={group}>
                    <div className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-subtle">{group}</div>
                    <div className="divide-y divide-line rounded-lg border border-line">
                        {entries.map((entry) => {
                            const id = `${idPrefix}-${entry.key}`;
                            const value = values[entry.key];
                            return (
                                <div key={entry.key} className="flex items-center justify-between gap-3 px-3 py-2">
                                    <label htmlFor={id} className="min-w-0 cursor-pointer text-sm text-body" title={entry.help || ''}>
                                        {entry.label}
                                        {entry.kind !== 'flag' && (
                                            <span className="ml-1.5 text-[11px] text-subtle">
                                                Blank = leave the plan's value alone.
                                            </span>
                                        )}
                                    </label>
                                    {entry.kind === 'flag' ? (
                                        <Toggle id={id} checked={!!value} onChange={(next) => onChange(entry.key, next)} />
                                    ) : (
                                        <Input
                                            id={id}
                                            type="text"
                                            inputMode="numeric"
                                            className="w-32 shrink-0 text-right"
                                            placeholder="—"
                                            value={value ?? ''}
                                            onChange={(e) => onChange(entry.key, e.target.value)}
                                        />
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

function AddonModal({ addon, registry, visibilities, onClose, preview }) {
    const editing = !!addon;

    const initialEntitlements = Object.fromEntries(
        Object.entries(registry).map(([key, entry]) => [
            key,
            entry.kind === 'flag'
                ? !!addon?.entitlements?.[key]
                : addonValueToInput(addon?.entitlements?.[key]),
        ]),
    );

    const form = useForm({
        key: addon?.key || '',
        name: addon?.name || '',
        description: addon?.description || '',
        price: centsToInput(addon?.price_cents ?? 0),
        price_yearly: centsToInput(addon?.price_cents_yearly ?? null),
        currency: addon?.currency || 'USD',
        visibility: addon?.visibility || 'hidden',
        reason: '',
        entitlements: initialEntitlements,
    });

    const setEnt = (key, value) => form.setData('entitlements', { ...form.data.entitlements, [key]: value });

    const submit = (e) => {
        e.preventDefault();
        if (preview) { onClose(); return; }

        const payload = (d) => ({
            name: d.name,
            description: d.description || null,
            price_cents: inputToCents(d.price) ?? 0,
            price_cents_yearly: inputToCents(d.price_yearly),
            currency: d.currency,
            visibility: d.visibility,
            entitlements: d.entitlements,
            reason: d.reason || null,
        });

        if (editing) {
            form.transform(payload).put(rt('platform.addons.update', addon.id), {
                preserveScroll: true,
                onSuccess: onClose,
            });
        } else {
            form.transform((d) => ({ ...payload(d), key: d.key || null })).post(rt('platform.addons.store'), {
                preserveScroll: true,
                onSuccess: () => { form.reset(); onClose(); },
            });
        }
    };

    return (
        <Modal show onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="max-h-[85vh] overflow-y-auto p-6">
                <h3 className="text-lg font-semibold text-body">{editing ? `Edit “${addon.name}”` : 'New add-on'}</h3>
                <p className="mt-1 text-sm text-muted">
                    An add-on only ever ADDS. Anything you leave off here falls through to the customer's plan.
                </p>

                <div className="mt-5 space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Name" htmlFor="a-name" required error={form.errors.name}>
                            <Input id="a-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="Agency toolkit" />
                        </Field>
                        <Field
                            label="Key"
                            htmlFor="a-key"
                            hint={editing ? 'Permanent.' : 'Leave blank to derive it from the name.'}
                            error={form.errors.key}
                        >
                            <Input id="a-key" value={form.data.key} disabled={editing} onChange={(e) => form.setData('key', e.target.value)} placeholder="auto" />
                        </Field>
                    </div>

                    <Field label="Description" htmlFor="a-desc" error={form.errors.description}>
                        <Textarea id="a-desc" rows={2} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-4">
                        <Field label="Monthly / seat" htmlFor="a-price" error={form.errors.price_cents}>
                            <Input id="a-price" type="number" step="0.01" min="0" value={form.data.price} onChange={(e) => form.setData('price', e.target.value)} />
                        </Field>
                        <Field label="Yearly / seat" htmlFor="a-pricey" hint="Blank = negotiated." error={form.errors.price_cents_yearly}>
                            <Input id="a-pricey" type="number" step="0.01" min="0" placeholder="negotiated" value={form.data.price_yearly} onChange={(e) => form.setData('price_yearly', e.target.value)} />
                        </Field>
                        <Field label="Currency" htmlFor="a-cur">
                            <Input id="a-cur" maxLength={3} value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value.toUpperCase())} />
                        </Field>
                        <Field label="Visibility" htmlFor="a-vis" hint={VISIBILITY_META[form.data.visibility]?.help}>
                            <Select id="a-vis" value={form.data.visibility} onChange={(e) => form.setData('visibility', e.target.value)}>
                                {visibilities.map((v) => <option key={v} value={v}>{VISIBILITY_META[v]?.label || v}</option>)}
                            </Select>
                        </Field>
                    </div>

                    <div className="flex items-start gap-2 rounded-lg border border-line bg-surface-2 px-4 py-3 text-xs text-muted">
                        <Info className="mt-0.5 h-4 w-4 shrink-0 text-info" aria-hidden="true" />
                        <span>
                            A number you type here <span className="font-medium text-body">replaces</span> the plan's value
                            for that limit — it is not added to it. Use it to RAISE a limit, or type “{UNLIMITED}” to lift
                            the ceiling entirely. Leave it blank to say nothing.
                        </span>
                    </div>

                    <AddonEntitlementEditor
                        registry={registry}
                        values={form.data.entitlements}
                        onChange={setEnt}
                        idPrefix={`a${addon?.id || 'new'}`}
                    />

                    <Field label="Why (optional)" htmlFor="a-reason" hint="Recorded in the activity log.">
                        <Input id="a-reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                    </Field>
                </div>

                <div className="mt-6 flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
                    <Button type="submit" loading={form.processing} disabled={form.processing || !form.data.name} icon={<Save className="h-4 w-4" />}>
                        {editing ? 'Save add-on' : 'Create add-on'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

// ─── Offline preview fixture ─────────────────────────────────────────────────
const DEV_REGISTRY = {
    max_users: { key: 'max_users', group: 'Limits', label: 'User seats', kind: 'max', unit: 'seats', help: 'Seat ceiling.' },
    max_storage_gb: { key: 'max_storage_gb', group: 'Limits', label: 'Storage (max GB)', kind: 'max', unit: 'GB', help: 'Storage ceiling.' },
    upwork_profiles: { key: 'upwork_profiles', group: 'Agency', label: 'Upwork profiles', kind: 'flag', help: 'Profiles dimension.' },
    machine_compliance: { key: 'machine_compliance', group: 'Agency', label: 'Machine compliance', kind: 'flag', help: 'Per-PC inventory.' },
    ai: { key: 'ai', group: 'Features', label: 'AI assistant', kind: 'flag', help: 'Day summaries.' },
};

const DEV_ADDONS = [
    {
        id: 1, key: 'agency_toolkit', name: 'Agency toolkit',
        description: 'Upwork profiles, marketplace work categories and Machine Compliance.',
        price_cents: 400, price_cents_yearly: 4000, currency: 'USD',
        visibility: 'public', archived_at: null, sort: 10, holders: 9,
        entitlements: { upwork_profiles: true, machine_compliance: true },
    },
    {
        id: 2, key: 'extra_storage', name: 'Extra storage',
        description: 'Lifts the storage ceiling for screenshot-heavy teams.',
        price_cents: 200, price_cents_yearly: 2000, currency: 'USD',
        visibility: 'hidden', archived_at: null, sort: 20, holders: 2,
        entitlements: { max_storage_gb: 'unlimited' },
    },
];

export default function Addons({ addons, registry, visibilities, preview = false }) {
    const list = preview ? DEV_ADDONS : (addons || []);
    const reg = preview ? DEV_REGISTRY : (registry || {});
    const vis = (preview ? Object.keys(VISIBILITY_META) : visibilities) || Object.keys(VISIBILITY_META);

    const [editing, setEditing] = useState(null); // addon | 'new' | null

    const toggleArchive = (addon) => {
        if (preview) return;
        const name = addon.archived_at ? 'platform.addons.restore' : 'platform.addons.archive';
        router.post(rt(name, addon.id), {}, { preserveScroll: true });
    };

    const grantedKeys = (addon) => Object.keys(addon.entitlements || {});

    return (
        <PlatformLayout
            title="Add-ons"
            header={
                <PlatformPageHeader
                    title="Add-ons"
                    description="Capability bundles a company can buy on top of its plan. Attach one to a single customer from that customer's page — the plan never has to change."
                    actions={
                        <>
                            <Button as={Link} href={rt('platform.plans')} variant="ghost" size="sm">Plans</Button>
                            <Button size="sm" icon={<Plus className="h-4 w-4" />} onClick={() => setEditing('new')}>
                                New add-on
                            </Button>
                        </>
                    }
                />
            }
        >
            <Head title="Add-ons" />

            {list.length === 0 ? (
                <Card>
                    <EmptyState
                        title="No add-ons yet"
                        description="Create one to sell a capability without moving anybody between plans."
                        action={<Button onClick={() => setEditing('new')}>New add-on</Button>}
                    />
                </Card>
            ) : (
                <div className="grid items-start gap-4 lg:grid-cols-2 xl:grid-cols-3">
                    {list.map((addon) => {
                        const meta = VISIBILITY_META[addon.visibility] || VISIBILITY_META.hidden;
                        const keys = grantedKeys(addon);
                        return (
                            <Card
                                key={addon.id}
                                className={addon.archived_at ? 'opacity-70' : ''}
                                title={addon.name}
                                description={addon.description}
                                actions={<Badge tone={meta.tone}>{meta.label}</Badge>}
                            >
                                <div className="space-y-4">
                                    <div className="flex flex-wrap items-center gap-2 text-sm">
                                        <span className="font-semibold text-body">
                                            {money(addon.price_cents, addon.currency)}
                                            <span className="text-xs font-normal text-subtle"> /seat/mo</span>
                                        </span>
                                        <span className="text-subtle">·</span>
                                        <span className="text-body">
                                            {addon.price_cents_yearly === null || addon.price_cents_yearly === undefined
                                                ? <span className="text-muted">yearly negotiated</span>
                                                : <>{money(addon.price_cents_yearly, addon.currency)}<span className="text-xs text-subtle"> /seat/yr</span></>}
                                        </span>
                                    </div>

                                    <div className="flex flex-wrap items-center gap-2 text-xs text-muted">
                                        <Badge tone="neutral" className="gap-1">
                                            <Users className="h-3 w-3" /> {addon.holders} {addon.holders === 1 ? 'company' : 'companies'}
                                        </Badge>
                                        <code className="text-subtle">{addon.key}</code>
                                        {addon.archived_at && <Badge tone="danger">Archived</Badge>}
                                    </div>

                                    <div>
                                        <div className="mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-subtle">Grants</div>
                                        {keys.length === 0 ? (
                                            <p className="text-sm text-muted">Nothing yet — this add-on grants no capability.</p>
                                        ) : (
                                            <ul className="space-y-1 text-sm">
                                                {keys.map((key) => (
                                                    <li key={key} className="flex items-center justify-between gap-3">
                                                        <span className="text-body">{reg[key]?.label || key}</span>
                                                        <span className="text-muted">
                                                            {addon.entitlements[key] === true
                                                                ? 'On'
                                                                : String(addon.entitlements[key])}
                                                        </span>
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </div>

                                    <div className="flex flex-wrap gap-2 pt-1">
                                        <Button size="sm" onClick={() => setEditing(addon)}>Edit</Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className={addon.archived_at ? '' : 'text-danger'}
                                            icon={addon.archived_at ? <ArchiveRestore className="h-4 w-4" /> : <Archive className="h-4 w-4" />}
                                            onClick={() => toggleArchive(addon)}
                                        >
                                            {addon.archived_at ? 'Restore' : 'Archive'}
                                        </Button>
                                    </div>
                                </div>
                            </Card>
                        );
                    })}
                </div>
            )}

            {editing && (
                <AddonModal
                    addon={editing === 'new' ? null : editing}
                    registry={reg}
                    visibilities={vis}
                    preview={preview}
                    onClose={() => setEditing(null)}
                />
            )}
        </PlatformLayout>
    );
}
