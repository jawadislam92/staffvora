import { useEffect, useMemo, useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import {
    Button,
    Card,
    Badge,
    StatCard,
    Table,
    THead,
    TBody,
    Tr,
    Th,
    Td,
    Field,
    Input,
    Textarea,
    Checkbox,
    EmptyState,
    Tabs,
} from '@/Components/ui';
import {
    Plus,
    Pencil,
    Trash2,
    Search,
    UserRound,
    Users,
    CheckCircle2,
    Mail,
    Archive,
    RotateCcw,
} from 'lucide-react';

const cx = (...a) => a.filter(Boolean).join(' ');

// ---------------------------------------------------------------------------
// DEV_SAMPLE — used ONLY when `preview` is true so the page renders fully
// offline for visual QA. Mirrors the controller payload shape (id / name /
// email / description / is_active / created_at) plus a mock super-admin user.
// Includes ARCHIVED rows (is_active: false) so the Active/Archived tabs, the
// neutral "Archived" badge and the restore action can all be QA'd offline.
// Never touched in normal (non-preview) rendering.
// ---------------------------------------------------------------------------
const DEV_SAMPLE = {
    status: 'all',
    user: {
        id: 1,
        name: 'Jawad',
        email: 'jawad@staffvora.com',
        is_super_admin: true,
        permissions: [],
    },
    profiles: [
        { id: 1, name: 'Aqsa Malik', email: 'aqsa@sparkingasia.com', description: 'Full-stack Upwork profile — web & mobile agency work.', is_active: true, created_at: '2025-01-14T09:12:00Z' },
        { id: 2, name: 'Bilal Ahmed', email: 'bilal@sparkingasia.com', description: 'WordPress & Shopify storefront specialist.', is_active: true, created_at: '2025-02-02T11:40:00Z' },
        { id: 3, name: 'Chen Wei', email: '', description: 'Data engineering and automation contracts.', is_active: true, created_at: '2025-03-18T14:05:00Z' },
        { id: 4, name: 'Maria Lopez', email: 'maria@sparkingasia.com', description: 'UI/UX design and brand identity profile.', is_active: false, created_at: '2024-11-27T08:30:00Z' },
        { id: 5, name: 'Omar Farooq', email: 'omar@sparkingasia.com', description: '', is_active: false, created_at: '2024-12-09T16:22:00Z' },
    ],
};

const EMPTY_FORM = { name: '', email: '', description: '', is_active: true };

/**
 * ProfileFormModal — create / edit dialog on the shared Modal + kit fields.
 * `profile` null => create mode. Posts to the same store/update routes, so all
 * CRUD data flow is preserved exactly.
 */
function ProfileFormModal({ show, profile, preview, onClose }) {
    const isEdit = Boolean(profile);
    const form = useForm(EMPTY_FORM);

    // Re-seed the form whenever the modal opens for a (different) profile.
    useEffect(() => {
        if (show) {
            form.clearErrors();
            form.setData({
                name: profile?.name ?? '',
                email: profile?.email ?? '',
                description: profile?.description ?? '',
                is_active: profile ? Boolean(profile.is_active) : true,
            });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show, profile?.id]);

    const submit = (e) => {
        e.preventDefault();
        if (preview) {
            onClose();
            return;
        }
        const opts = { preserveScroll: true, onSuccess: () => onClose() };
        if (isEdit) {
            form.put(route('upwork-profiles.update', profile.id), opts);
        } else {
            form.post(route('upwork-profiles.store'), {
                ...opts,
                onSuccess: () => {
                    form.reset();
                    onClose();
                },
            });
        }
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="lg">
            <form onSubmit={submit} className="p-6 space-y-5">
                <div>
                    <h3 className="text-lg font-semibold text-body">
                        {isEdit ? 'Edit profile' : 'New profile'}
                    </h3>
                    <p className="mt-1 text-sm text-muted">
                        {isEdit
                            ? 'Update the profile details used for client assignments and time tracking.'
                            : 'Add a profile that can be assigned to clients and work entries.'}
                    </p>
                </div>

                <Field label="Profile name" required error={form.errors.name} htmlFor="profile-name">
                    <Input
                        id="profile-name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        placeholder="e.g. John Doe, Jane Smith"
                        maxLength={255}
                        autoFocus
                        required
                    />
                </Field>

                <Field label="Email" hint="Optional" error={form.errors.email} htmlFor="profile-email">
                    <Input
                        id="profile-email"
                        type="email"
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                        placeholder="name@example.com"
                        maxLength={255}
                    />
                </Field>

                <Field label="Description" hint="Optional" error={form.errors.description} htmlFor="profile-description">
                    <Textarea
                        id="profile-description"
                        rows={4}
                        value={form.data.description}
                        onChange={(e) => form.setData('description', e.target.value)}
                        placeholder="Add any notes or description for this profile…"
                        maxLength={1000}
                    />
                </Field>

                <div className="rounded-lg border border-line bg-surface-2/50 p-4">
                    <Checkbox
                        checked={form.data.is_active}
                        onChange={(e) => form.setData('is_active', e.target.checked)}
                        label="Profile is active (active profiles appear in dropdown selections)"
                    />
                    {form.errors.is_active && (
                        <p className="mt-2 text-xs text-danger">{form.errors.is_active}</p>
                    )}
                </div>

                <div className="flex items-center justify-end gap-2 pt-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        loading={form.processing}
                        disabled={!form.data.name.trim()}
                        icon={!isEdit && <Plus className="h-4 w-4" />}
                    >
                        {isEdit ? 'Save changes' : 'Add profile'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

const STATUS_TABS = [
    { key: 'all', label: 'All' },
    { key: 'active', label: 'Active' },
    { key: 'archived', label: 'Archived' },
];

export default function Index({ auth, profiles, status = 'all', preview = false }) {
    // In preview mode, ignore incoming props and render entirely from DEV_SAMPLE.
    const user = preview ? DEV_SAMPLE.user : auth?.user;
    const list = preview ? DEV_SAMPLE.profiles : (profiles ?? []);
    const canManage = preview
        ? true
        : Boolean(user?.is_super_admin || user?.permissions?.includes('profiles.manage'));

    const [query, setQuery] = useState('');
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [statusTab, setStatusTab] = useState(preview ? DEV_SAMPLE.status : (status || 'all'));
    const [selectedIds, setSelectedIds] = useState(new Set());

    // The controller already narrows by status; filtering locally too keeps the
    // tabs honest in preview mode and while the round-trip is in flight.
    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        return list.filter((p) => {
            if (statusTab === 'active' && !p.is_active) return false;
            if (statusTab === 'archived' && p.is_active) return false;
            if (!q) return true;
            return [p.name, p.email, p.description]
                .filter(Boolean)
                .some((v) => v.toLowerCase().includes(q));
        });
    }, [list, query, statusTab]);

    const activeCount = useMemo(() => list.filter((p) => p.is_active).length, [list]);

    const changeStatus = (next) => {
        setStatusTab(next);
        setSelectedIds(new Set());
        if (preview) return;
        router.get(
            route('upwork-profiles.index'),
            { status: next },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    };

    const toggleSelected = (id) => {
        setSelectedIds((prev) => {
            const next = new Set(prev);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    };

    const allOnPageSelected =
        filtered.length > 0 && filtered.every((p) => selectedIds.has(p.id));

    const toggleSelectAll = (checked) => {
        const next = new Set(selectedIds);
        filtered.forEach((p) => (checked ? next.add(p.id) : next.delete(p.id)));
        setSelectedIds(next);
    };

    // Per-row archive / restore. Archiving keeps the profile and its history —
    // it just stops appearing in dropdown selections.
    const setProfileStatus = (profile) => {
        if (preview) return;
        const isActive = !profile.is_active;
        router.patch(
            route('upwork-profiles.set-status', profile.id),
            { is_active: isActive },
            { preserveScroll: true, preserveState: true }
        );
    };

    // Bulk archive / restore across the ticked selection.
    const bulkStatus = (isActive) => {
        if (selectedIds.size === 0) return;
        if (preview) {
            setSelectedIds(new Set());
            return;
        }
        router.post(
            route('upwork-profiles.bulk-status'),
            { is_active: isActive, ids: [...selectedIds], status: statusTab },
            { preserveScroll: true, onFinish: () => setSelectedIds(new Set()) }
        );
    };

    const openCreate = () => {
        setEditing(null);
        setFormOpen(true);
    };

    const openEdit = (profile) => {
        setEditing(profile);
        setFormOpen(true);
    };

    const remove = (profile) => {
        if (preview) return; // no-op in offline QA
        if (confirm(`Are you sure you want to delete the profile "${profile.name}"? This action cannot be undone.`)) {
            router.delete(route('upwork-profiles.destroy', profile.id), { preserveScroll: true });
        }
    };

    const fmtDate = (v) => {
        if (!v) return '—';
        const d = new Date(v);
        return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString();
    };

    return (
        <AuthenticatedLayout
            user={user}
            header={<h2 className="text-xl font-semibold text-body">Profiles</h2>}
        >
            <Head title="Profiles" />

            <div className="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8 space-y-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <p className="max-w-2xl text-sm text-muted">
                        Manage profile names used for client assignments and time tracking.
                        Active profiles appear in dropdown selections across the app.
                    </p>
                    {canManage && (
                        <Button icon={<Plus className="h-4 w-4" />} onClick={openCreate}>
                            Add profile
                        </Button>
                    )}
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <StatCard
                        label="Profiles"
                        value={list.length}
                        icon={<Users className="h-5 w-5" />}
                        hint="Total profiles in your workspace"
                    />
                    <StatCard
                        label="Active"
                        value={activeCount}
                        icon={<CheckCircle2 className="h-5 w-5" />}
                        hint="Shown in dropdown selections"
                    />
                    <StatCard
                        label="Archived"
                        value={list.length - activeCount}
                        icon={<Archive className="h-5 w-5" />}
                        hint="Hidden from selections"
                    />
                </div>

                <Card
                    padded={false}
                    header={
                        <div className="flex flex-wrap items-center justify-between gap-3 px-5 py-4 border-b border-line">
                            <div className="min-w-0">
                                <div className="text-body font-semibold">Profile directory</div>
                                <p className="text-sm text-muted mt-0.5">
                                    {filtered.length} {filtered.length === 1 ? 'profile' : 'profiles'}
                                    {query && ` matching “${query}”`}
                                </p>
                            </div>
                            <div className="flex w-full flex-wrap items-center gap-3 sm:w-auto">
                                <Tabs
                                    size="sm"
                                    tabs={STATUS_TABS}
                                    value={statusTab}
                                    onChange={changeStatus}
                                    aria-label="Filter profiles by status"
                                />
                                <div className="relative w-full sm:w-72">
                                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-subtle" />
                                    <Input
                                        type="search"
                                        value={query}
                                        onChange={(e) => setQuery(e.target.value)}
                                        placeholder="Search profiles…"
                                        className="pl-9"
                                        aria-label="Search profiles"
                                    />
                                </div>
                            </div>
                        </div>
                    }
                >
                    {/* Bulk action strip */}
                    {canManage && selectedIds.size > 0 && (
                        <div className="flex flex-wrap items-center gap-3 border-b border-line bg-accent/5 px-5 py-3">
                            <span className="text-sm font-medium text-accent">
                                {selectedIds.size} selected
                            </span>
                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={() => bulkStatus(false)}
                                icon={<Archive className="h-4 w-4" />}
                            >
                                Archive
                            </Button>
                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={() => bulkStatus(true)}
                                icon={<RotateCcw className="h-4 w-4" />}
                            >
                                Restore
                            </Button>
                            <Button variant="ghost" size="sm" onClick={() => setSelectedIds(new Set())}>
                                Clear
                            </Button>
                        </div>
                    )}

                    {filtered.length === 0 ? (
                        <EmptyState
                            icon={<UserRound className="h-5 w-5" />}
                            title={
                                query
                                    ? 'No matching profiles'
                                    : statusTab === 'archived'
                                        ? 'No archived profiles'
                                        : 'No profiles found'
                            }
                            description={
                                query
                                    ? 'Try a different search term.'
                                    : statusTab === 'archived'
                                        ? 'Archived profiles stay out of dropdown selections until you restore them.'
                                        : 'Create a profile to assign it to clients and work entries.'
                            }
                            action={
                                !query && statusTab !== 'archived' && canManage ? (
                                    <Button icon={<Plus className="h-4 w-4" />} onClick={openCreate}>
                                        Add first profile
                                    </Button>
                                ) : null
                            }
                        />
                    ) : (
                        <Table>
                            <THead>
                                <Tr>
                                    {canManage && (
                                        <Th className="w-10">
                                            <Checkbox
                                                checked={allOnPageSelected}
                                                onChange={(e) => toggleSelectAll(e.target.checked)}
                                                aria-label="Select all listed profiles"
                                            />
                                        </Th>
                                    )}
                                    <Th>Profile</Th>
                                    <Th>Description</Th>
                                    <Th>Status</Th>
                                    <Th>Created</Th>
                                    {canManage && <Th className="text-right">Actions</Th>}
                                </Tr>
                            </THead>
                            <TBody>
                                {filtered.map((profile) => (
                                    <Tr
                                        key={profile.id}
                                        className={cx(
                                            !profile.is_active && 'opacity-60',
                                            selectedIds.has(profile.id) && 'bg-accent/10'
                                        )}
                                    >
                                        {canManage && (
                                            <Td className="w-10">
                                                <Checkbox
                                                    checked={selectedIds.has(profile.id)}
                                                    onChange={() => toggleSelected(profile.id)}
                                                    aria-label={`Select ${profile.name}`}
                                                />
                                            </Td>
                                        )}
                                        <Td>
                                            <div className="font-medium text-body">{profile.name}</div>
                                            <div className="mt-0.5 flex items-center gap-1.5 text-xs text-muted">
                                                {profile.email ? (
                                                    <>
                                                        <Mail className="h-3 w-3 shrink-0" />
                                                        <span className="truncate">{profile.email}</span>
                                                    </>
                                                ) : (
                                                    <span className="text-subtle">No email</span>
                                                )}
                                            </div>
                                        </Td>
                                        <Td className="max-w-md">
                                            {profile.description ? (
                                                <span className="line-clamp-2 text-muted">{profile.description}</span>
                                            ) : (
                                                <span className="text-subtle">No description</span>
                                            )}
                                        </Td>
                                        <Td>
                                            <Badge tone={profile.is_active ? 'success' : 'neutral'} dot>
                                                {profile.is_active ? 'Active' : 'Archived'}
                                            </Badge>
                                        </Td>
                                        <Td className="whitespace-nowrap text-muted">
                                            {fmtDate(profile.created_at)}
                                        </Td>
                                        {canManage && (
                                            <Td className="text-right">
                                                <div className="flex justify-end gap-1">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="!px-2"
                                                        onClick={() => openEdit(profile)}
                                                        title="Edit profile"
                                                        aria-label={`Edit ${profile.name}`}
                                                        icon={<Pencil className="h-4 w-4" />}
                                                    />
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="!px-2"
                                                        onClick={() => setProfileStatus(profile)}
                                                        title={
                                                            profile.is_active
                                                                ? `Archive ${profile.name}`
                                                                : `Restore ${profile.name}`
                                                        }
                                                        aria-label={
                                                            profile.is_active
                                                                ? `Archive ${profile.name}`
                                                                : `Restore ${profile.name}`
                                                        }
                                                        icon={
                                                            profile.is_active ? (
                                                                <Archive className="h-4 w-4" />
                                                            ) : (
                                                                <RotateCcw className="h-4 w-4" />
                                                            )
                                                        }
                                                    />
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="!px-2 hover:text-danger"
                                                        onClick={() => remove(profile)}
                                                        title="Delete profile"
                                                        aria-label={`Delete ${profile.name}`}
                                                        icon={<Trash2 className="h-4 w-4" />}
                                                    />
                                                </div>
                                            </Td>
                                        )}
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                </Card>
            </div>

            {canManage && (
                <ProfileFormModal
                    show={formOpen}
                    profile={editing}
                    preview={preview}
                    onClose={() => setFormOpen(false)}
                />
            )}
        </AuthenticatedLayout>
    );
}
