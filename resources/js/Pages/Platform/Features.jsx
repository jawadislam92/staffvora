import { Head, Link, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import PlatformLayout, { PlatformPageHeader } from '@/Layouts/PlatformLayout';
import {
    Button, Card, Badge, Table, THead, TBody, Tr, Th, Td,
    Field, Input, Select, Textarea, Toggle, EmptyState,
} from '@/Components/ui';
import Modal from '@/Components/Modal';
import { ArrowDown, ArrowUp, Plus, Save, ShieldAlert, Tag } from 'lucide-react';
import { rt } from './entitlementUi';

// ─────────────────────────────────────────────────────────────────────────────
// THE FEATURE CATALOGUE — owner only.
//
// This is the list of every capability the product can sell. Adding a row here
// makes a new switch appear in the plan editor, the add-on editor, every
// per-company override panel and the app's own UI locks — with no code change.
//
// It is deliberately the strictest page in the console: deactivating a row
// revokes that capability for EVERY workspace on the next request.
// ─────────────────────────────────────────────────────────────────────────────

const KIND_META = {
    flag: { label: 'On / off', tone: 'accent', help: 'A switch. Off unless something grants it.' },
    max: { label: 'Ceiling', tone: 'info', help: 'A maximum a workspace may not exceed. Deny value is 0.' },
    min: { label: 'Floor', tone: 'warning', help: 'A minimum a workspace may not go below (e.g. a gentler auto-pause on cheap plans).' },
};

const blankFeature = () => ({
    key: '',
    label: '',
    help: '',
    kind: 'flag',
    group: 'Features',
    unit: '',
    default_value: '',
    sort: '',
    is_active: true,
    reason: '',
});

/** The create/edit dialog. `feature` null = create. */
function FeatureModal({ feature, groups, kinds, onClose, preview }) {
    const editing = !!feature;
    const form = useForm(
        editing
            ? {
                key: feature.key,
                label: feature.label,
                help: feature.help || '',
                kind: feature.kind,
                group: feature.group,
                unit: feature.unit || '',
                default_value: feature.kind === 'flag' ? !!feature.default_value : (feature.default_value ?? 0),
                sort: feature.sort ?? '',
                is_active: !!feature.is_active,
                reason: '',
            }
            : blankFeature(),
    );

    const isFlag = form.data.kind === 'flag';

    const submit = (e) => {
        e.preventDefault();
        if (preview) { onClose(); return; }

        const payload = (d) => ({
            label: d.label,
            help: d.help || null,
            kind: d.kind,
            group: d.group,
            unit: d.unit || null,
            default_value: d.kind === 'flag' ? !!d.default_value : (d.default_value === '' ? 0 : Number(d.default_value)),
            sort: d.sort === '' ? null : Number(d.sort),
            is_active: !!d.is_active,
            reason: d.reason || null,
        });

        if (editing) {
            form.transform(payload).put(rt('platform.features.update', feature.id), {
                preserveScroll: true,
                onSuccess: onClose,
            });
        } else {
            form.transform((d) => ({ ...payload(d), key: d.key })).post(rt('platform.features.store'), {
                preserveScroll: true,
                onSuccess: () => { form.reset(); onClose(); },
            });
        }
    };

    return (
        <Modal show onClose={onClose} maxWidth="lg">
            <form onSubmit={submit} className="p-6">
                <h3 className="text-lg font-semibold text-body">
                    {editing ? `Edit “${feature.label}”` : 'New feature'}
                </h3>
                <p className="mt-1 text-sm text-muted">
                    {editing
                        ? 'The key is permanent — every plan, add-on and per-company override is written against it. Renaming means changing the label.'
                        : 'A new feature is denied everywhere until a plan, an add-on or a single company grants it.'}
                </p>

                <div className="mt-5 space-y-4">
                    <Field
                        label="Key"
                        htmlFor="f-key"
                        required={!editing}
                        hint={editing ? 'Permanent — cannot be changed.' : 'lower_snake_case. This is what the code and every stored grant refer to.'}
                        error={form.errors.key}
                    >
                        <Input
                            id="f-key"
                            value={form.data.key}
                            disabled={editing}
                            onChange={(e) => form.setData('key', e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_'))}
                            placeholder="machine_compliance"
                        />
                    </Field>

                    <Field label="Label" htmlFor="f-label" required error={form.errors.label}>
                        <Input id="f-label" value={form.data.label} onChange={(e) => form.setData('label', e.target.value)} placeholder="Machine compliance" />
                    </Field>

                    <Field label="Help text" htmlFor="f-help" hint="Shown to you in every editor. Keep it about what it unlocks." error={form.errors.help}>
                        <Textarea id="f-help" rows={2} value={form.data.help} onChange={(e) => form.setData('help', e.target.value)} />
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field label="Kind" htmlFor="f-kind" hint={KIND_META[form.data.kind]?.help} error={form.errors.kind}>
                            <Select id="f-kind" value={form.data.kind} onChange={(e) => form.setData('kind', e.target.value)}>
                                {kinds.map((k) => <option key={k} value={k}>{KIND_META[k]?.label || k}</option>)}
                            </Select>
                        </Field>
                        <Field label="Group" htmlFor="f-group" hint="Section heading in the editors." error={form.errors.group}>
                            <Input id="f-group" list="feature-groups" value={form.data.group} onChange={(e) => form.setData('group', e.target.value)} />
                            <datalist id="feature-groups">
                                {groups.map((g) => <option key={g} value={g} />)}
                            </datalist>
                        </Field>
                        <Field label="Unit" htmlFor="f-unit" hint="e.g. seats, GB, days." error={form.errors.unit}>
                            <Input id="f-unit" value={form.data.unit} disabled={isFlag} onChange={(e) => form.setData('unit', e.target.value)} />
                        </Field>
                    </div>

                    <div className="rounded-lg border border-line bg-surface-2 p-4">
                        <div className="text-sm font-medium text-body">Default (what applies when nobody granted it)</div>
                        <p className="mt-1 text-xs text-subtle">
                            This must be the DENY value. A ceiling cannot default to unlimited — that would give the
                            feature away to everyone who was never sold it.
                        </p>
                        <div className="mt-3">
                            {isFlag ? (
                                <Toggle
                                    checked={!!form.data.default_value}
                                    onChange={(next) => form.setData('default_value', next)}
                                    label={form.data.default_value ? 'On by default (rarely what you want)' : 'Off by default'}
                                />
                            ) : (
                                <Input
                                    type="number"
                                    min="0"
                                    className="w-40"
                                    value={form.data.default_value}
                                    onChange={(e) => form.setData('default_value', e.target.value)}
                                    aria-label="Default value"
                                />
                            )}
                        </div>
                    </div>

                    {editing && (
                        <div className="flex items-start justify-between gap-3 rounded-lg border border-line px-4 py-3">
                            <div className="min-w-0">
                                <div className="text-sm font-medium text-body">Active</div>
                                <div className="text-xs text-subtle">
                                    Switching this off retires the feature: it is denied for every workspace immediately,
                                    whatever their plan says. Nothing is deleted, so you can switch it back.
                                </div>
                            </div>
                            <Toggle checked={form.data.is_active} onChange={(next) => form.setData('is_active', next)} />
                        </div>
                    )}

                    <Field label="Why (optional)" htmlFor="f-reason" hint="Recorded in the activity log.">
                        <Input id="f-reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                    </Field>
                </div>

                <div className="mt-6 flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
                    <Button type="submit" loading={form.processing} disabled={form.processing || !form.data.label || (!editing && !form.data.key)} icon={<Save className="h-4 w-4" />}>
                        {editing ? 'Save feature' : 'Add feature'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

// ─── Offline preview fixture ─────────────────────────────────────────────────
const DEV_SAMPLE = {
    kinds: ['flag', 'max', 'min'],
    groups: ['Limits', 'Monitoring', 'Features', 'Agency', 'Branding'],
    features: [
        { id: 1, key: 'max_users', label: 'User seats', help: 'Maximum users in the workspace.', kind: 'max', group: 'Limits', unit: 'seats', default_value: 1, sort: 10, is_active: true, granted_by: { plans: ['Free', 'Starter', 'Growth', 'Enterprise'], addons: [] } },
        { id: 2, key: 'max_storage_gb', label: 'Storage (max GB)', help: 'Screenshot + upload storage.', kind: 'max', group: 'Limits', unit: 'GB', default_value: 0, sort: 20, is_active: true, granted_by: { plans: ['Starter', 'Growth', 'Enterprise'], addons: [] } },
        { id: 3, key: 'min_auto_pause_minutes', label: 'Auto-pause floor', help: 'Tightest idle auto-pause.', kind: 'min', group: 'Monitoring', unit: 'min', default_value: 10, sort: 40, is_active: true, granted_by: { plans: ['Starter', 'Growth'], addons: [] } },
        { id: 4, key: 'ai', label: 'AI assistant', help: 'AI day-summary and assistant features.', kind: 'flag', group: 'Features', unit: null, default_value: false, sort: 110, is_active: true, granted_by: { plans: ['Growth', 'Enterprise'], addons: [] } },
        { id: 5, key: 'machine_compliance', label: 'Machine compliance', help: 'Per-PC extension / program / VPN inventory.', kind: 'flag', group: 'Agency', unit: null, default_value: false, sort: 150, is_active: true, granted_by: { plans: [], addons: ['Agency toolkit'] } },
        { id: 6, key: 'api', label: 'API access', help: 'Programmatic API access.', kind: 'flag', group: 'Features', unit: null, default_value: false, sort: 130, is_active: false, granted_by: { plans: [], addons: [] } },
    ],
};

export default function Features({ features, kinds, groups, preview = false }) {
    const data = preview ? DEV_SAMPLE : { features: features || [], kinds: kinds || [], groups: groups || [] };

    const [editing, setEditing] = useState(null); // feature | 'new' | null
    const [order, setOrder] = useState(null);
    const [saving, setSaving] = useState(false);

    const ordered = useMemo(() => {
        if (!order) return data.features;
        const byId = new Map(data.features.map((f) => [f.id, f]));
        return order.map((id) => byId.get(id)).filter(Boolean);
    }, [data.features, order]);

    const activeCount = data.features.filter((f) => f.is_active).length;

    const move = (id, delta) => {
        const ids = ordered.map((f) => f.id);
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
        setSaving(true);
        router.post(rt('platform.features.reorder'), { order }, {
            preserveScroll: true,
            onFinish: () => { setSaving(false); setOrder(null); },
        });
    };

    return (
        <PlatformLayout
            title="Features"
            header={
                <PlatformPageHeader
                    title="Feature catalogue"
                    description="Every capability the product can sell. Add one here and it appears in the plan editor, the add-on editor and every per-company override — no code change, no deploy."
                    actions={
                        <>
                            <Button as={Link} href={rt('platform.plans')} variant="ghost" size="sm">Plans</Button>
                            <Button size="sm" icon={<Plus className="h-4 w-4" />} onClick={() => setEditing('new')}>
                                New feature
                            </Button>
                        </>
                    }
                />
            }
        >
            <Head title="Feature catalogue" />

            <div className="w-full space-y-5">
                <Card className="border-warning/40">
                    <div className="flex items-start gap-3">
                        <ShieldAlert className="mt-0.5 h-5 w-5 shrink-0 text-warning" aria-hidden="true" />
                        <div className="text-sm text-muted">
                            <span className="font-medium text-body">This page changes the product itself.</span>{' '}
                            Deactivating a feature denies it to <em>every</em> workspace on the next request, whatever
                            their plan grants. Keys are permanent and rows are never deleted, so historic plans and
                            overrides stay readable.
                        </div>
                    </div>
                </Card>

                <div className="flex flex-wrap items-center gap-2 text-sm text-muted">
                    <Badge tone="neutral" size="md">{data.features.length} features</Badge>
                    <Badge tone="success" size="md">{activeCount} active</Badge>
                    {order && (
                        <div className="ml-auto flex items-center gap-2">
                            <Button size="sm" variant="ghost" onClick={() => setOrder(null)}>Undo</Button>
                            <Button size="sm" loading={saving} onClick={saveOrder}>Save order</Button>
                        </div>
                    )}
                </div>

                <Card padded={false}>
                    {ordered.length === 0 ? (
                        <EmptyState
                            title="No features yet"
                            description="Add the first capability you want to gate. Nothing is granted until a plan turns it on."
                        />
                    ) : (
                        <Table>
                                <THead>
                                    <Tr>
                                        <Th>Feature</Th>
                                        <Th>Kind</Th>
                                        <Th>Group</Th>
                                        <Th>Default</Th>
                                        <Th>Granted by</Th>
                                        <Th className="text-right">Order</Th>
                                        <Th className="text-right">Actions</Th>
                                    </Tr>
                                </THead>
                                <TBody>
                                    {ordered.map((f, i) => (
                                        <Tr key={f.id} className={f.is_active ? '' : 'opacity-60'}>
                                            <Td>
                                                <div className="font-medium text-body">{f.label}</div>
                                                <div className="flex items-center gap-1.5 text-xs text-subtle">
                                                    <Tag className="h-3 w-3" aria-hidden="true" />
                                                    <code>{f.key}</code>
                                                    {!f.is_active && <Badge tone="danger">Retired</Badge>}
                                                </div>
                                            </Td>
                                            <Td>
                                                <Badge tone={KIND_META[f.kind]?.tone || 'neutral'}>
                                                    {KIND_META[f.kind]?.label || f.kind}
                                                </Badge>
                                            </Td>
                                            <Td className="text-muted">{f.group}</Td>
                                            <Td className="tabular-nums text-muted">
                                                {f.kind === 'flag'
                                                    ? (f.default_value ? 'On' : 'Off')
                                                    : `${f.default_value ?? 0}${f.unit ? ' ' + f.unit : ''}`}
                                            </Td>
                                            <Td className="text-muted">
                                                <div className="flex flex-wrap gap-1">
                                                    {f.granted_by?.plans?.map((n) => <Badge key={'p' + n} tone="accent">{n}</Badge>)}
                                                    {f.granted_by?.addons?.map((n) => <Badge key={'a' + n} tone="info">{n}</Badge>)}
                                                    {!f.granted_by?.plans?.length && !f.granted_by?.addons?.length && (
                                                        <span className="text-subtle">Nobody</span>
                                                    )}
                                                </div>
                                            </Td>
                                            <Td>
                                                <div className="flex items-center justify-end gap-1">
                                                    <button
                                                        type="button"
                                                        onClick={() => move(f.id, -1)}
                                                        disabled={i === 0}
                                                        aria-label={`Move ${f.label} up`}
                                                        className="rounded-md border border-line bg-surface-2 p-1.5 text-subtle transition cursor-pointer hover:text-body disabled:cursor-not-allowed disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                                                    >
                                                        <ArrowUp className="h-3.5 w-3.5" />
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => move(f.id, 1)}
                                                        disabled={i === ordered.length - 1}
                                                        aria-label={`Move ${f.label} down`}
                                                        className="rounded-md border border-line bg-surface-2 p-1.5 text-subtle transition cursor-pointer hover:text-body disabled:cursor-not-allowed disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                                                    >
                                                        <ArrowDown className="h-3.5 w-3.5" />
                                                    </button>
                                                </div>
                                            </Td>
                                            <Td className="text-right">
                                                <Button size="sm" variant="secondary" onClick={() => setEditing(f)}>Edit</Button>
                                            </Td>
                                        </Tr>
                                    ))}
                                </TBody>
                        </Table>
                    )}
                </Card>
            </div>

            {editing && (
                <FeatureModal
                    feature={editing === 'new' ? null : editing}
                    groups={data.groups}
                    kinds={data.kinds}
                    preview={preview}
                    onClose={() => setEditing(null)}
                />
            )}
        </PlatformLayout>
    );
}
