import { useCallback, useMemo, useRef, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import toast from 'react-hot-toast';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import {
    Badge,
    Button,
    Card,
    CardHeader,
    EmptyState,
    Field,
    Input,
    Table,
    TBody,
    Td,
    Th,
    THead,
    Tabs,
    Toggle,
    Tr,
} from '@/Components/ui';
import {
    Archive,
    ArchiveRestore,
    ArrowDown,
    ArrowUp,
    Check,
    GripVertical,
    Pencil,
    Plus,
    Tags,
    Trash2,
    X,
} from 'lucide-react';

const cx = (...a) => a.filter(Boolean).join(' ');

/*
 * DEV_SAMPLE — offline visual-QA fixture. Used ONLY when `preview` is true, so
 * the whole screen renders from memory with no props and no network. Mirrors
 * the real prop shape from SettingsController::workCategories(). The sample is
 * deliberately an AGENCY workspace's list (Bidding / Outside Upwork sit in the
 * same table as everything else) — there is one mechanism, not two.
 */
const DEV_SAMPLE = {
    user: { id: 1, name: 'Jawad', email: 'jawad@staffvora.test', is_super_admin: true, permissions: [] },
    categories: [
        { id: 1, name: 'Client Work', slug: 'client_work', is_billable_default: true, sort_order: 0, is_active: true, entries_count: 18422 },
        { id: 2, name: 'Internal/Admin', slug: 'internal_admin', is_billable_default: false, sort_order: 1, is_active: true, entries_count: 964 },
        { id: 3, name: 'Business Development', slug: 'business_development', is_billable_default: false, sort_order: 2, is_active: true, entries_count: 51 },
        { id: 4, name: 'Training/Onboarding', slug: 'training_onboarding', is_billable_default: false, sort_order: 3, is_active: true, entries_count: 0 },
        { id: 5, name: 'Meetings', slug: 'meetings', is_billable_default: false, sort_order: 4, is_active: true, entries_count: 233 },
        { id: 6, name: 'Bidding', slug: 'bidding', is_billable_default: false, sort_order: 5, is_active: true, entries_count: 1207 },
        { id: 7, name: 'Outside Upwork', slug: 'outside_upwork', is_billable_default: true, sort_order: 6, is_active: true, entries_count: 3110 },
        { id: 8, name: 'Trial/Test task', slug: 'trial_test_task', is_billable_default: false, sort_order: 7, is_active: false, entries_count: 88 },
    ],
    maxCategories: 200,
};

const numberFmt = new Intl.NumberFormat();

/**
 * Ziggy throws on a name it doesn't know, which would take the whole page down.
 * These routes live in the `permission:monitoring.settings` settings group; the
 * literal fallback keeps preview/QA rendering even before Ziggy is regenerated.
 */
function safeRoute(name, params, fallback) {
    try {
        return typeof route === 'function' ? route(name, params) : fallback;
    } catch {
        return fallback;
    }
}

const URLS = {
    store: () => safeRoute('settings.work-categories.store', undefined, '/settings/work-categories'),
    update: (id) => safeRoute('settings.work-categories.update', { workCategory: id }, `/settings/work-categories/${id}`),
    destroy: (id) => safeRoute('settings.work-categories.destroy', { workCategory: id }, `/settings/work-categories/${id}`),
    reorder: () => safeRoute('settings.work-categories.reorder', undefined, '/settings/work-categories/reorder'),
    settings: () => safeRoute('settings.index', undefined, '/settings'),
};

/** Pull the first useful message out of a Laravel 422 (or fall back). */
function messageFrom(error, fallback) {
    const data = error?.response?.data;
    const first = data?.errors ? Object.values(data.errors)[0] : null;
    return (Array.isArray(first) ? first[0] : first) || data?.message || fallback;
}

export default function WorkCategories({
    auth,
    categories: initialCategories = [],
    maxCategories = 200,
    preview = false,
}) {
    const user = preview ? DEV_SAMPLE.user : auth?.user;
    const cap = preview ? DEV_SAMPLE.maxCategories : maxCategories;

    const [items, setItems] = useState(() => (preview ? DEV_SAMPLE.categories : initialCategories));
    const [tab, setTab] = useState('active');
    const [busyId, setBusyId] = useState(null);
    const [confirming, setConfirming] = useState(null); // the row pending archive/delete
    const [confirmBusy, setConfirmBusy] = useState(false);
    const dragId = useRef(null);

    // Preview never touches the network; every handler funnels through these.
    const api = useMemo(() => ({
        post: (url, payload) => (preview ? Promise.resolve({ data: { ok: true } }) : axios.post(url, payload)),
        put: (url, payload) => (preview ? Promise.resolve({ data: { ok: true } }) : axios.put(url, payload)),
        del: (url, payload) => (preview ? Promise.resolve({ data: { ok: true } }) : axios.delete(url, { data: payload })),
    }), [preview]);

    const active = useMemo(() => items.filter((c) => c.is_active), [items]);
    const archived = useMemo(() => items.filter((c) => !c.is_active), [items]);
    const visible = tab === 'active' ? active : archived;

    const patchItem = useCallback((id, patch) => {
        setItems((prev) => prev.map((c) => (c.id === id ? { ...c, ...patch } : c)));
    }, []);

    // ── Ordering ──────────────────────────────────────────────────────────────
    // The whole ordered id list is submitted, so the server never has to guess
    // and a partially-applied order can't happen. Local state is optimistic; a
    // failure reverts to exactly what we had before the move.
    const persistOrder = useCallback((next, previous) => {
        api.put(URLS.reorder(), { order: next.map((c) => c.id) })
            .catch((error) => {
                setItems(previous);
                toast.error(messageFrom(error, 'Could not save the new order.'));
            });
    }, [api]);

    const reorder = useCallback((fromId, toId, placeAfter = false) => {
        if (fromId === toId) return;

        const from = items.findIndex((c) => c.id === fromId);
        if (from < 0 || !items.some((c) => c.id === toId)) return;

        const next = items.slice();
        const [moved] = next.splice(from, 1);
        const target = next.findIndex((c) => c.id === toId);
        next.splice(placeAfter ? target + 1 : target, 0, moved);

        setItems(next);
        persistOrder(next, items);
    }, [items, persistOrder]);

    // Arrow buttons step within the list you can actually see, so an archived
    // row never silently swallows a move.
    const step = useCallback((id, direction) => {
        const index = visible.findIndex((c) => c.id === id);
        const neighbour = visible[index + direction];
        if (!neighbour) return;
        reorder(id, neighbour.id, direction > 0);
    }, [visible, reorder]);

    // ── Mutations ─────────────────────────────────────────────────────────────
    const rename = useCallback((row, name) => {
        const clean = name.trim();
        if (!clean || clean === row.name) return Promise.resolve(false);

        const before = row.name;
        patchItem(row.id, { name: clean });

        return api.put(URLS.update(row.id), { name: clean })
            .then(() => true)
            .catch((error) => {
                patchItem(row.id, { name: before });
                toast.error(messageFrom(error, 'Could not rename that category.'));
                return false;
            });
    }, [api, patchItem]);

    const setBillableDefault = useCallback((row, value) => {
        patchItem(row.id, { is_billable_default: value });

        api.put(URLS.update(row.id), { is_billable_default: value })
            .catch((error) => {
                patchItem(row.id, { is_billable_default: !value });
                toast.error(messageFrom(error, 'Could not change the billable default.'));
            });
    }, [api, patchItem]);

    const restore = useCallback((row) => {
        setBusyId(row.id);
        patchItem(row.id, { is_active: true });

        api.put(URLS.update(row.id), { is_active: true })
            .then(() => toast.success(`“${row.name}” is back in the list.`))
            .catch((error) => {
                patchItem(row.id, { is_active: false });
                toast.error(messageFrom(error, 'Could not restore that category.'));
            })
            .finally(() => setBusyId(null));
    }, [api, patchItem]);

    const confirmDestroy = useCallback(() => {
        const row = confirming;
        if (!row) return;

        const permanent = row.entries_count === 0;
        setConfirmBusy(true);

        api.del(URLS.destroy(row.id), { permanent })
            .then(() => {
                setItems((prev) => (permanent
                    ? prev.filter((c) => c.id !== row.id)
                    : prev.map((c) => (c.id === row.id ? { ...c, is_active: false } : c))));
                toast.success(permanent ? `“${row.name}” deleted.` : `“${row.name}” archived.`);
                setConfirming(null);
            })
            .catch((error) => toast.error(messageFrom(error, 'Could not archive that category.')))
            .finally(() => setConfirmBusy(false));
    }, [api, confirming]);

    const add = useCallback((name, billable) => api
        .post(URLS.store(), { name, is_billable_default: billable })
        .then(({ data }) => {
            const created = data?.category || {
                id: Math.max(0, ...items.map((c) => c.id)) + 1,
                name,
                slug: null,
                is_billable_default: billable,
                sort_order: items.length,
                is_active: true,
                entries_count: 0,
            };
            setItems((prev) => [...prev, created]);
            setTab('active');
            toast.success(`“${created.name}” added.`);
            return null;
        })
        .catch((error) => messageFrom(error, 'Could not add that category.')), [api, items]);

    const atCap = items.length >= cap;

    return (
        <AuthenticatedLayout
            user={user}
            header={<h2 className="text-xl font-semibold text-body">Work categories</h2>}
        >
            <Head title="Work categories" />

            <div className="mx-auto max-w-[1600px] space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                <Card>
                    <div className="flex flex-wrap items-start gap-4">
                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-accent/10 text-accent">
                            <Tags className="h-5 w-5" />
                        </span>
                        <div className="min-w-0 max-w-prose space-y-2 text-sm">
                            <p className="text-body">
                                These are your workspace&apos;s own words for <strong>what</strong> a
                                piece of work was. Everyone logging time picks from this list, and
                                your timesheets and reports group by it.
                            </p>
                            <p className="text-muted">
                                It is separate from <strong>how</strong> the time was captured
                                (the desktop tracker sets that automatically) and from whether the
                                entry is <strong>billable</strong> — a per-entry yes/no that starts
                                from the default you set here.
                            </p>
                            <p className="text-subtle">
                                Renaming a category updates it everywhere, including on time already
                                logged. Archiving hides it from new entries while history keeps
                                reading exactly as it does today.
                            </p>
                        </div>
                    </div>
                </Card>

                <AddCategoryForm atCap={atCap} cap={cap} onAdd={add} />

                <Card
                    padded={false}
                    header={(
                        <CardHeader
                            title="Your list"
                            description={
                                tab === 'active'
                                    ? 'Drag a row, or use the arrows, to set the order people see when logging time.'
                                    : 'Archived categories stay readable on every past entry and report.'
                            }
                            actions={(
                                <Tabs
                                    size="sm"
                                    value={tab}
                                    onChange={setTab}
                                    tabs={[
                                        { key: 'active', label: `Active (${active.length})` },
                                        { key: 'archived', label: `Archived (${archived.length})` },
                                    ]}
                                />
                            )}
                        />
                    )}
                >
                    {visible.length === 0 ? (
                        <EmptyState
                            icon={<Tags className="h-5 w-5" />}
                            title={tab === 'active' ? 'No active categories' : 'Nothing archived'}
                            description={
                                tab === 'active'
                                    ? 'Add at least one so your team has somewhere to file their time.'
                                    : 'Categories you archive will show up here, and can be restored at any time.'
                            }
                        />
                    ) : (
                        <Table>
                            <THead>
                                <Tr>
                                    <Th className="w-12" aria-label="Order" />
                                    <Th>Category</Th>
                                    <Th className="w-52">Billable by default</Th>
                                    <Th className="w-40 text-right">Time entries</Th>
                                    <Th className="w-56 text-right">Actions</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {visible.map((row, index) => (
                                    <CategoryRow
                                        key={row.id}
                                        row={row}
                                        isFirst={index === 0}
                                        isLast={index === visible.length - 1}
                                        reorderable={tab === 'active'}
                                        busy={busyId === row.id}
                                        onlyActive={tab === 'active' && active.length === 1}
                                        onStep={step}
                                        onRename={rename}
                                        onBillableChange={setBillableDefault}
                                        onArchive={setConfirming}
                                        onRestore={restore}
                                        onDragStart={() => { dragId.current = row.id; }}
                                        onDropOn={() => {
                                            const from = dragId.current;
                                            dragId.current = null;
                                            if (from != null) reorder(from, row.id, false);
                                        }}
                                    />
                                ))}
                            </TBody>
                        </Table>
                    )}
                </Card>

                <p className="text-xs text-subtle">
                    Need to change who can edit this?{' '}
                    <Link href={preview ? '#' : URLS.settings()} className="text-accent hover:underline">
                        Back to settings
                    </Link>
                </p>
            </div>

            <ConfirmArchiveModal
                row={confirming}
                busy={confirmBusy}
                onCancel={() => setConfirming(null)}
                onConfirm={confirmDestroy}
            />
        </AuthenticatedLayout>
    );
}

/** One row: order controls, inline-editable name, billable default, usage, actions. */
function CategoryRow({
    row,
    isFirst,
    isLast,
    reorderable,
    busy,
    onlyActive,
    onStep,
    onRename,
    onBillableChange,
    onArchive,
    onRestore,
    onDragStart,
    onDropOn,
}) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(row.name);

    // The optimistic patch (and the revert on failure) live in the parent, so
    // the row only has to leave edit mode.
    const commit = () => {
        onRename(row, draft);
        setEditing(false);
    };

    const cancel = () => {
        setDraft(row.name);
        setEditing(false);
    };

    return (
        <Tr
            draggable={reorderable}
            onDragStart={reorderable ? onDragStart : undefined}
            onDragOver={reorderable ? (e) => e.preventDefault() : undefined}
            onDrop={reorderable ? (e) => { e.preventDefault(); onDropOn(); } : undefined}
            className={cx(busy && 'opacity-60')}
        >
            <Td className="pr-0">
                {reorderable ? (
                    <div className="flex items-center gap-1">
                        <GripVertical className="h-4 w-4 cursor-grab text-subtle" aria-hidden="true" />
                        <div className="flex flex-col">
                            <IconButton label={`Move ${row.name} up`} disabled={isFirst} onClick={() => onStep(row.id, -1)}>
                                <ArrowUp className="h-3.5 w-3.5" />
                            </IconButton>
                            <IconButton label={`Move ${row.name} down`} disabled={isLast} onClick={() => onStep(row.id, 1)}>
                                <ArrowDown className="h-3.5 w-3.5" />
                            </IconButton>
                        </div>
                    </div>
                ) : (
                    <span className="text-xs text-subtle">—</span>
                )}
            </Td>

            <Td>
                {editing ? (
                    <div className="flex items-center gap-2">
                        <Input
                            value={draft}
                            autoFocus
                            maxLength={60}
                            aria-label={`Rename ${row.name}`}
                            onChange={(e) => setDraft(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') commit();
                                if (e.key === 'Escape') cancel();
                            }}
                            className="max-w-xs"
                        />
                        <IconButton label="Save name" onClick={commit}>
                            <Check className="h-4 w-4" />
                        </IconButton>
                        <IconButton label="Cancel rename" onClick={cancel}>
                            <X className="h-4 w-4" />
                        </IconButton>
                    </div>
                ) : (
                    <button
                        type="button"
                        onClick={() => { setDraft(row.name); setEditing(true); }}
                        className="group inline-flex items-center gap-2 rounded-md text-left text-sm font-medium text-body transition hover:text-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app"
                    >
                        {row.name}
                        <Pencil className="h-3.5 w-3.5 text-subtle opacity-0 transition group-hover:opacity-100" />
                        {!row.is_active && <Badge tone="warning">Archived</Badge>}
                    </button>
                )}
            </Td>

            <Td>
                <Toggle
                    checked={!!row.is_billable_default}
                    onChange={(next) => onBillableChange(row, next)}
                    label={<span className="text-xs text-muted">{row.is_billable_default ? 'Billable' : 'Not billable'}</span>}
                />
            </Td>

            <Td className="text-right tabular-nums">
                {row.entries_count > 0 ? (
                    <span className="text-sm text-body">{numberFmt.format(row.entries_count)}</span>
                ) : (
                    <span className="text-sm text-subtle">Unused</span>
                )}
            </Td>

            <Td className="text-right">
                {row.is_active ? (
                    <Button
                        size="sm"
                        variant="secondary"
                        disabled={busy || onlyActive}
                        title={onlyActive ? 'Keep at least one active category.' : undefined}
                        icon={row.entries_count === 0 ? <Trash2 className="h-3.5 w-3.5" /> : <Archive className="h-3.5 w-3.5" />}
                        onClick={() => onArchive(row)}
                    >
                        {row.entries_count === 0 ? 'Delete' : 'Archive'}
                    </Button>
                ) : (
                    <Button
                        size="sm"
                        variant="secondary"
                        disabled={busy}
                        icon={<ArchiveRestore className="h-3.5 w-3.5" />}
                        onClick={() => onRestore(row)}
                    >
                        Restore
                    </Button>
                )}
            </Td>
        </Tr>
    );
}

function IconButton({ label, disabled, onClick, children }) {
    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            disabled={disabled}
            onClick={onClick}
            className={cx(
                'inline-flex h-5 w-5 items-center justify-center rounded transition',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-1 focus-visible:ring-offset-app',
                disabled ? 'cursor-not-allowed text-subtle/40' : 'cursor-pointer text-subtle hover:bg-surface-2 hover:text-body',
            )}
        >
            {children}
        </button>
    );
}

/** The "add a category" strip above the list. */
function AddCategoryForm({ atCap, cap, onAdd }) {
    const [name, setName] = useState('');
    const [billable, setBillable] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);

    const submit = (e) => {
        e.preventDefault();
        const clean = name.trim();
        if (!clean || saving) return;

        setSaving(true);
        setError(null);

        onAdd(clean, billable).then((failure) => {
            if (failure) {
                setError(failure);
            } else {
                setName('');
                setBillable(false);
            }
        }).finally(() => setSaving(false));
    };

    return (
        <Card>
            <form onSubmit={submit} className="flex flex-wrap items-end gap-4">
                <Field
                    label="Add a category"
                    htmlFor="new-work-category"
                    error={error}
                    hint={atCap ? `You have reached the maximum of ${cap} categories.` : 'For example: Support, Design, Research.'}
                    className="max-w-xs"
                >
                    <Input
                        id="new-work-category"
                        value={name}
                        maxLength={60}
                        disabled={atCap}
                        placeholder="Category name"
                        onChange={(e) => setName(e.target.value)}
                    />
                </Field>

                <div className="pb-1">
                    <Toggle
                        checked={billable}
                        onChange={setBillable}
                        disabled={atCap}
                        label={<span className="text-sm text-muted">Billable by default</span>}
                    />
                </div>

                <div className="pb-0.5">
                    <Button type="submit" loading={saving} disabled={atCap || !name.trim()} icon={<Plus className="h-4 w-4" />}>
                        Add category
                    </Button>
                </div>
            </form>
        </Card>
    );
}

/**
 * Archive / delete confirmation. The entry count is what decides which one is
 * even offered: a category that time is filed against can only be archived,
 * because deleting it would break every report that already renders its name.
 */
function ConfirmArchiveModal({ row, busy, onCancel, onConfirm }) {
    const permanent = row?.entries_count === 0;

    return (
        <Modal show={!!row} onClose={busy ? () => {} : onCancel} maxWidth="md">
            {row && (
                <div className="bg-surface p-6">
                    <h2 className="text-base font-semibold text-body">
                        {permanent ? `Delete “${row.name}”?` : `Archive “${row.name}”?`}
                    </h2>

                    <div className="mt-3 space-y-3 text-sm text-muted">
                        {permanent ? (
                            <p>
                                No time has ever been logged against this category, so it can be
                                removed for good. Nothing else changes.
                            </p>
                        ) : (
                            <>
                                <p className="rounded-lg bg-info-soft px-3 py-2 text-info">
                                    <strong>{numberFmt.format(row.entries_count)}</strong>{' '}
                                    {row.entries_count === 1 ? 'time entry is' : 'time entries are'} filed
                                    under this category.
                                </p>
                                <p>
                                    Those entries keep it — every past timesheet, report and digest
                                    reads exactly as it does today. Archiving only removes it from
                                    the picker people use when logging <em>new</em> time, and you can
                                    restore it whenever you like.
                                </p>
                            </>
                        )}
                    </div>

                    <div className="mt-6 flex justify-end gap-2">
                        <Button variant="secondary" onClick={onCancel} disabled={busy}>
                            Cancel
                        </Button>
                        <Button
                            variant={permanent ? 'danger' : 'primary'}
                            loading={busy}
                            onClick={onConfirm}
                            icon={permanent ? <Trash2 className="h-4 w-4" /> : <Archive className="h-4 w-4" />}
                        >
                            {permanent ? 'Delete category' : 'Archive category'}
                        </Button>
                    </div>
                </div>
            )}
        </Modal>
    );
}
