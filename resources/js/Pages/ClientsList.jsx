import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
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
    Input,
    Select,
    Checkbox,
    EmptyState,
    Tabs,
} from '@/Components/ui';
import {
    Plus,
    Search,
    X,
    Download,
    Upload,
    FileDown,
    Trash2,
    Pencil,
    FolderKanban,
    Clock,
    ChevronLeft,
    ChevronRight,
    Archive,
    RotateCcw,
} from 'lucide-react';

const cx = (...a) => a.filter(Boolean).join(' ');

// ---------------------------------------------------------------------------
// DEV_SAMPLE — used ONLY when `preview` is true so the page renders fully
// offline for visual QA with a mock super-admin. Mirrors the controller's
// shape: a Laravel paginator of clients (with upworkProfiles / tags /
// weekly_hours_worked / is_active) plus workTypes and a workspace terminology
// object. Includes ARCHIVED rows (is_active: false) so the Active/Archived tabs,
// the neutral "Archived" badge and the restore action can be QA'd offline.
// Never referenced during normal (non-preview) rendering.
// ---------------------------------------------------------------------------
const DEV_SAMPLE = {
    filters: { search: '', perPage: 10, status: 'all' },
    user: {
        id: 1,
        name: 'Jawad',
        email: 'jawad@staffvora.com',
        is_super_admin: true,
        permissions: [],
    },
    workspace: {
        client_term: 'Project',
        profile_term: 'Profile',
    },
    // Preview renders the AGENCY variant: the profiles column/filter is shown
    // when the ENTITLEMENT is on, exactly as it is live.
    entitlements: { upwork_profiles: true },
    workTypes: {
        tracker_manual: 'Tracked or manual time',
        fixed_fee: 'Fixed-fee work',
        other: 'Other work',
    },
    clients: {
        current_page: 1,
        per_page: 10,
        from: 1,
        to: 6,
        total: 6,
        data: [
            {
                id: 1,
                name: 'Acme Storefront Rebuild',
                work_type: 'tracker_manual',
                tags: ['web development', 'react'],
                weekly_hours_worked: '32:15',
                is_active: true,
                upwork_profiles: [{ id: 1, name: 'Primary Agency' }],
            },
            {
                id: 2,
                name: 'Nimbus Mobile App',
                work_type: 'fixed_fee',
                tags: ['mobile app', 'design'],
                weekly_hours_worked: '18:40',
                is_active: true,
                upwork_profiles: [{ id: 2, name: 'Design Studio' }],
            },
            {
                id: 3,
                name: 'Helios Analytics Dashboard',
                work_type: 'tracker_manual',
                tags: ['data', 'charts'],
                weekly_hours_worked: '27:05',
                is_active: true,
                upwork_profiles: [{ id: 1, name: 'Primary Agency' }],
            },
            {
                id: 4,
                name: 'Orion CRM Integration',
                work_type: 'other',
                tags: ['consulting'],
                weekly_hours_worked: '09:30',
                is_active: false,
                upwork_profiles: [],
            },
            {
                id: 5,
                name: 'Meridian Brand Site',
                work_type: 'fixed_fee',
                tags: ['marketing', 'strategy', 'web'],
                weekly_hours_worked: '14:20',
                is_active: true,
                upwork_profiles: [{ id: 2, name: 'Design Studio' }],
            },
            {
                id: 6,
                name: 'Vertex Internal Tools',
                work_type: 'tracker_manual',
                tags: [],
                weekly_hours_worked: '00:00',
                is_active: false,
                upwork_profiles: [],
            },
        ],
        links: [
            { url: null, label: '&laquo; Previous', active: false },
            { url: '#', label: '1', active: true },
            { url: null, label: 'Next &raquo;', active: false },
        ],
    },
};

/** Renders a Laravel-paginator label (may contain HTML entities) as plain text. */
function PageLabel({ label }) {
    return <span dangerouslySetInnerHTML={{ __html: label }} />;
}

const STATUS_TABS = [
    { key: 'all', label: 'All' },
    { key: 'active', label: 'Active' },
    { key: 'archived', label: 'Archived' },
];

export default function ClientsList({
    auth,
    clients,
    flash,
    filters = {},
    filterOptions = {},
    workTypes = {},
    preview = false,
}) {
    // usePage must be called unconditionally; its result is ignored in preview.
    const pageProps = usePage().props || {};
    const pageWorkspace = pageProps.workspace || {};
    const pageEntitlements = pageProps.entitlements || {};

    const user = preview ? DEV_SAMPLE.user : auth?.user;
    const workspace = preview ? DEV_SAMPLE.workspace : pageWorkspace;
    // Capability from the ONE entitlement map; `workspace` is terminology only.
    const entitlements = preview ? DEV_SAMPLE.entitlements : pageEntitlements;
    const list = preview ? DEV_SAMPLE.clients : clients;
    const types = preview ? DEV_SAMPLE.workTypes : workTypes;
    filters = preview ? DEV_SAMPLE.filters : filters;

    // Tabs come from the controller when available so the labels stay in one
    // place; STATUS_TABS is the offline / preview fallback.
    const statusTabs = filterOptions.status?.length
        ? filterOptions.status.map((option) => ({ key: String(option.value), label: option.label }))
        : STATUS_TABS;

    const clientTerm = workspace.client_term || 'Project';
    const clientTermPlural = clientTerm.endsWith('s') ? clientTerm : clientTerm + 's';
    const profileTerm = workspace.profile_term || 'Profile';
    const usesProfiles = entitlements.upwork_profiles === true;

    const isSuper = user?.is_super_admin;
    const has = (p) => isSuper || user?.permissions?.includes(p);
    const canManage = has('clients.manage');
    const canImportExport = has('clients.import_export');
    const canViewReports = has('reports.view');

    const [deleteId, setDeleteId] = useState(null);
    const [toast, setToast] = useState(flash?.success || flash?.error || '');
    const [toastType, setToastType] = useState(flash?.success ? 'success' : 'error');
    const [selectedPerPage, setSelectedPerPage] = useState(
        Number(filters.perPage || list?.per_page || 10)
    );
    const [searchTerm, setSearchTerm] = useState(filters.search || '');
    const [statusFilter, setStatusFilter] = useState(filters.status || 'all');
    const [selectedClients, setSelectedClients] = useState(new Set());
    const [selectAll, setSelectAll] = useState(false);
    const [selectAllMatching, setSelectAllMatching] = useState(false);
    const [showBulkDeleteModal, setShowBulkDeleteModal] = useState(false);

    const rows = (list?.data || []).filter((c) => {
        // The controller already narrows by status; filtering locally too keeps
        // the tabs honest in preview mode and while a round-trip is in flight.
        if (statusFilter === 'active') return c.is_active !== false;
        if (statusFilter === 'archived') return c.is_active === false;
        return true;
    });

    const currentListUrl = () =>
        typeof window === 'undefined'
            ? route('clients.index')
            : `${window.location.pathname}${window.location.search}`;

    const editClientHref = (clientId) => {
        if (preview) return '#';
        const returnTo = currentListUrl();
        return `${route('clients.edit', clientId)}?return_to=${encodeURIComponent(returnTo)}`;
    };

    const reportHref = (name) => (preview ? '#' : route('work-hours.report', { clients: [name] }));

    // ---- CRUD / filters (unchanged behavior) -------------------------------

    const confirmDelete = (id) => setDeleteId(id);

    const handleDelete = () => {
        if (!deleteId || preview) return;
        router.delete(route('clients.destroy', deleteId), {
            data: { return_to: currentListUrl() },
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                setToast(clientTerm + ' deleted.');
                setToastType('success');
                setDeleteId(null);
            },
            onError: () => {
                setToast('Failed to delete ' + clientTerm.toLowerCase() + '.');
                setToastType('error');
                setDeleteId(null);
            },
        });
    };

    const closeToast = () => setToast('');

    const handlePerPageChange = (newPerPage) => {
        setSelectedPerPage(newPerPage);
        if (preview) return;
        router.get(route('clients.index'), {
            perPage: newPerPage,
            search: searchTerm,
            status: statusFilter,
        });
    };

    const handleSearch = (e) => {
        const value = e.target.value;
        setSearchTerm(value);
        if (preview) return;
        clearTimeout(window.searchTimeout);
        window.searchTimeout = setTimeout(() => {
            router.get(
                route('clients.index'),
                { search: value, perPage: selectedPerPage, status: statusFilter },
                { preserveState: true, preserveScroll: true }
            );
        }, 300);
    };

    const clearSearch = () => {
        setSearchTerm('');
        if (preview) return;
        router.get(route('clients.index'), { perPage: selectedPerPage, status: statusFilter });
    };

    // Active / Archived tab switch. Clears any selection so a bulk action can't
    // silently span two different result sets.
    const handleStatusChange = (next) => {
        setStatusFilter(next);
        clearAllSelections();
        if (preview) return;
        router.get(
            route('clients.index'),
            { search: searchTerm, perPage: selectedPerPage, status: next },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    };

    const exportClients = () => {
        if (preview) return;
        try {
            window.location.href = route('clients.export');
            setToast('Export started...');
            setToastType('success');
        } catch (error) {
            setToast('Failed to export ' + clientTermPlural.toLowerCase() + '.');
            setToastType('error');
        }
    };

    const handleImport = (event) => {
        const file = event.target.files[0];
        if (!file || preview) return;

        const formData = new FormData();
        formData.append('file', file);

        router.post(route('clients.import'), formData, {
            onSuccess: () => {
                setToast(clientTermPlural + ' imported successfully!');
                setToastType('success');
                event.target.value = '';
            },
            onError: (errors) => {
                const errorMessage = errors.file
                    ? errors.file
                    : 'Failed to import ' + clientTermPlural.toLowerCase() + '.';
                setToast(errorMessage);
                setToastType('error');
                event.target.value = '';
            },
        });
    };

    const downloadSample = () => {
        const labels = Object.values(types);
        const sampleData = [
            ['Name', 'Work Type', usesProfiles ? profileTerm : 'Profile (optional)', 'Tags'],
            ['Example ' + clientTerm + ' 1', labels[0] || 'Tracked or manual time', usesProfiles ? 'Primary ' + profileTerm : '', 'web development, react'],
            ['Example ' + clientTerm + ' 2', labels[1] || 'Fixed-fee work', '', 'mobile app, design'],
            ['Example ' + clientTerm + ' 3', labels[2] || 'Other work', '', 'consulting, strategy'],
        ];

        const csvContent = sampleData
            .map((row) => row.map((field) => `"${field}"`).join(','))
            .join('\n');

        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        if (link.download !== undefined) {
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'client_import_sample.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        setToast('Sample CSV downloaded!');
        setToastType('success');
    };

    // ---- Selection ---------------------------------------------------------

    const handleClientSelect = (clientId) => {
        setSelectAllMatching(false);
        const newSelected = new Set(selectedClients);
        if (newSelected.has(clientId)) newSelected.delete(clientId);
        else newSelected.add(clientId);
        setSelectedClients(newSelected);

        const currentPageIds = rows.map((c) => c.id);
        const allSelected = currentPageIds.every((id) => newSelected.has(id));
        setSelectAll(allSelected && currentPageIds.length > 0);
    };

    const handleSelectAll = () => {
        setSelectAllMatching(false);
        const currentPageIds = rows.map((c) => c.id);
        const newSelected = new Set(selectedClients);
        if (selectAll) currentPageIds.forEach((id) => newSelected.delete(id));
        else currentPageIds.forEach((id) => newSelected.add(id));
        setSelectedClients(newSelected);
        setSelectAll(!selectAll);
    };

    const clearAllSelections = () => {
        setSelectedClients(new Set());
        setSelectAll(false);
        setSelectAllMatching(false);
    };

    // Archive / restore one client. Archiving hides it from the pickers used to
    // log NEW work; every historical hour and report keeps showing.
    const handleSetStatus = (client) => {
        const newStatus = client.is_active === false;
        if (preview) return;
        router.patch(
            route('clients.set-status', client.id),
            { is_active: newStatus, return_to: currentListUrl() },
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => {
                    setToast(newStatus ? clientTerm + ' restored.' : clientTerm + ' archived.');
                    setToastType('success');
                },
                onError: () => {
                    setToast('Failed to update ' + clientTerm.toLowerCase() + '.');
                    setToastType('error');
                },
            }
        );
    };

    // Bulk archive / restore: either the explicit ticked set, or everything
    // matching the current filters ("select all N matching").
    const handleBulkStatus = (isActive) => {
        if (!selectAllMatching && selectedClients.size === 0) return;
        if (preview) {
            clearAllSelections();
            return;
        }

        const data = { is_active: isActive, return_to: currentListUrl() };

        if (selectAllMatching) {
            data.all_matching = true;
            data.search = searchTerm;
            data.status = statusFilter;
        } else {
            data.ids = Array.from(selectedClients);
        }

        router.post(route('clients.bulk-status'), data, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                setToast(
                    isActive
                        ? 'Selected ' + clientTermPlural.toLowerCase() + ' restored.'
                        : 'Selected ' + clientTermPlural.toLowerCase() + ' archived.'
                );
                setToastType('success');
                clearAllSelections();
            },
            onError: () => {
                setToast('Failed to update selected ' + clientTermPlural.toLowerCase() + '.');
                setToastType('error');
            },
        });
    };

    const handleBulkDelete = () => {
        if (selectedClients.size === 0) return;
        setShowBulkDeleteModal(true);
    };

    const confirmBulkDelete = () => {
        if (preview) {
            setShowBulkDeleteModal(false);
            return;
        }
        const clientIds = Array.from(selectedClients);
        router.delete(route('clients.bulk-destroy'), {
            data: { client_ids: clientIds, return_to: currentListUrl() },
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                setToast(`${clientIds.length} ${clientTermPlural.toLowerCase()} deleted successfully.`);
                setToastType('success');
                clearAllSelections();
                setShowBulkDeleteModal(false);
            },
            onError: () => {
                setToast('Failed to delete selected ' + clientTermPlural.toLowerCase() + '.');
                setToastType('error');
                setShowBulkDeleteModal(false);
            },
        });
    };

    // ---- Derived -----------------------------------------------------------

    const colCount =
        1 /* ID */ +
        1 /* Name */ +
        1 /* Work Type */ +
        (usesProfiles ? 1 : 0) +
        1 /* Tags */ +
        1 /* Hours */ +
        (canManage ? 2 : 0); /* select + actions */

    const totalCount = list?.total ?? rows.length;

    return (
        <AuthenticatedLayout
            user={user}
            header={<h2 className="text-xl font-semibold text-body">{clientTermPlural}</h2>}
        >
            <Head title={clientTermPlural} />

            <div className="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8 space-y-6">
                {/* Title row + primary action */}
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <p className="max-w-2xl text-sm text-muted">
                        Manage {clientTermPlural.toLowerCase()}, work types
                        {usesProfiles ? `, ${profileTerm.toLowerCase()}s` : ''}, and tags.
                    </p>
                    {canManage && (
                        <Button
                            as={Link}
                            href={preview ? '#' : route('clients.create')}
                            icon={<Plus className="h-4 w-4" />}
                        >
                            Add {clientTerm}
                        </Button>
                    )}
                </div>

                {/* Stats */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <StatCard
                        label={`Total ${clientTermPlural.toLowerCase()}`}
                        value={totalCount}
                        icon={<FolderKanban className="h-5 w-5" />}
                        hint={`Across your workspace`}
                    />
                    <StatCard
                        label="Work types"
                        value={Object.keys(types || {}).length}
                        icon={<Clock className="h-5 w-5" />}
                        hint="Configured billing / tracking modes"
                    />
                </div>

                {/* Main card */}
                <Card padded={false}>
                    {/* Toolbar */}
                    <div className="flex flex-col gap-3 border-b border-line p-4 xl:flex-row xl:items-center">
                        {/* Import / export secondary actions */}
                        {canImportExport && (
                            <div className="flex flex-wrap items-center gap-2">
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    onClick={exportClients}
                                    icon={<Download className="h-4 w-4" />}
                                >
                                    Export
                                </Button>
                                <label className="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-lg border border-line bg-surface-2 px-3 text-xs font-medium text-body transition hover:bg-surface focus-within:ring-2 focus-within:ring-accent">
                                    <Upload className="h-4 w-4" />
                                    Import
                                    <input
                                        type="file"
                                        accept=".csv,.txt"
                                        onChange={handleImport}
                                        className="hidden"
                                    />
                                </label>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={downloadSample}
                                    title="Download sample CSV template"
                                    icon={<FileDown className="h-4 w-4" />}
                                >
                                    Sample
                                </Button>
                            </div>
                        )}

                        {/* Active / Archived */}
                        <Tabs
                            size="sm"
                            tabs={statusTabs}
                            value={statusFilter}
                            onChange={handleStatusChange}
                            className="shrink-0"
                            aria-label={`Filter ${clientTermPlural.toLowerCase()} by status`}
                        />

                        {/* Search */}
                        <div className="relative min-w-0 flex-1">
                            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-subtle" />
                            <Input
                                type="text"
                                placeholder={`Search ${clientTermPlural.toLowerCase()} by name or tag...`}
                                className="pl-9 pr-9"
                                value={searchTerm}
                                onChange={handleSearch}
                            />
                            {searchTerm && (
                                <button
                                    type="button"
                                    onClick={clearSearch}
                                    title="Clear search"
                                    className="absolute right-2.5 top-1/2 -translate-y-1/2 rounded-md p-0.5 text-subtle transition hover:text-body focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                                >
                                    <X className="h-4 w-4" />
                                </button>
                            )}
                        </div>

                        {/* Per page */}
                        <div className="flex shrink-0 items-center gap-2">
                            <label
                                htmlFor="clients-per-page"
                                className="text-xs font-medium uppercase tracking-wide text-muted"
                            >
                                Rows
                            </label>
                            <Select
                                id="clients-per-page"
                                value={selectedPerPage}
                                onChange={(e) => handlePerPageChange(parseInt(e.target.value))}
                                className="w-20"
                            >
                                <option value={10}>10</option>
                                <option value={25}>25</option>
                                <option value={50}>50</option>
                                <option value={100}>100</option>
                            </Select>
                        </div>
                    </div>

                    {/* Bulk action strip */}
                    {canManage && (selectedClients.size > 0 || selectAllMatching) && (
                        <div className="flex flex-wrap items-center gap-3 border-b border-line bg-accent/5 px-4 py-3">
                            <span className="text-sm font-medium text-accent">
                                {selectAllMatching
                                    ? `All ${totalCount} matching selected`
                                    : `${selectedClients.size} selected`}
                            </span>
                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={() => handleBulkStatus(false)}
                                icon={<Archive className="h-4 w-4" />}
                            >
                                Archive
                            </Button>
                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={() => handleBulkStatus(true)}
                                icon={<RotateCcw className="h-4 w-4" />}
                            >
                                Restore
                            </Button>
                            <Button
                                variant="danger"
                                size="sm"
                                onClick={handleBulkDelete}
                                disabled={selectAllMatching}
                                title={selectAllMatching ? 'Delete works on an explicit selection' : undefined}
                                icon={<Trash2 className="h-4 w-4" />}
                            >
                                Delete
                            </Button>
                            <Button variant="ghost" size="sm" onClick={clearAllSelections}>
                                Clear
                            </Button>
                        </div>
                    )}

                    {/* "Select all N matching" escape hatch for the first big cleanup */}
                    {canManage
                        && !selectAllMatching
                        && selectedClients.size > 0
                        && rows.length > 0
                        && totalCount > rows.length
                        && rows.every((c) => selectedClients.has(c.id)) && (
                        <div className="border-b border-line bg-accent/5 px-4 py-2 text-center text-sm text-muted">
                            All {selectedClients.size} on this page selected.{' '}
                            <button
                                type="button"
                                onClick={() => setSelectAllMatching(true)}
                                className="font-medium text-accent hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                            >
                                Select all {totalCount} matching your filters
                            </button>
                        </div>
                    )}

                    {/* Table */}
                    {rows.length === 0 ? (
                        <EmptyState
                            icon={<FolderKanban className="h-5 w-5" />}
                            title={
                                statusFilter === 'archived'
                                    ? `No archived ${clientTermPlural.toLowerCase()}`
                                    : `No ${clientTermPlural.toLowerCase()} found`
                            }
                            description={
                                statusFilter === 'archived'
                                    ? `Archived ${clientTermPlural.toLowerCase()} stay out of the pickers used to log new work, but keep all their history.`
                                    : `Get started by adding your first ${clientTerm.toLowerCase()}.`
                            }
                            action={
                                canManage && statusFilter !== 'archived' ? (
                                    <Button
                                        as={Link}
                                        href={preview ? '#' : route('clients.create')}
                                        icon={<Plus className="h-4 w-4" />}
                                    >
                                        Add first {clientTerm.toLowerCase()}
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
                                                checked={selectAll}
                                                onChange={handleSelectAll}
                                                aria-label="Select all on this page"
                                            />
                                        </Th>
                                    )}
                                    <Th className="w-14">ID</Th>
                                    <Th>Name</Th>
                                    <Th>Work Type</Th>
                                    {usesProfiles && <Th>{profileTerm}</Th>}
                                    <Th>Tags</Th>
                                    <Th>Weekly Hours</Th>
                                    {canManage && <Th className="text-right">Actions</Th>}
                                </Tr>
                            </THead>
                            <TBody>
                                {rows.map((client) => (
                                    <Tr
                                        key={client.id}
                                        className={cx(
                                            client.is_active === false && 'opacity-60',
                                            selectedClients.has(client.id) && 'bg-accent/10'
                                        )}
                                    >
                                        {canManage && (
                                            <Td>
                                                <Checkbox
                                                    checked={selectedClients.has(client.id)}
                                                    onChange={() => handleClientSelect(client.id)}
                                                    aria-label={`Select ${client.name}`}
                                                />
                                            </Td>
                                        )}
                                        <Td className="text-subtle tabular-nums">{client.id}</Td>
                                        <Td className="font-medium">
                                            <div className="flex flex-wrap items-center gap-2">
                                                {canViewReports ? (
                                                    <Link
                                                        href={reportHref(client.name)}
                                                        className="text-body transition hover:text-accent hover:underline"
                                                        title={`View ${client.name}'s report`}
                                                    >
                                                        {client.name}
                                                    </Link>
                                                ) : (
                                                    client.name
                                                )}
                                                {client.is_active === false && (
                                                    <Badge tone="neutral">Archived</Badge>
                                                )}
                                            </div>
                                        </Td>
                                        <Td>
                                            {client.work_type ? (
                                                <Badge tone="accent">
                                                    {types?.[client.work_type] ||
                                                        client.work_type
                                                            .replace('_', ' ')
                                                            .replace(/\b\w/g, (l) => l.toUpperCase())}
                                                </Badge>
                                            ) : (
                                                <span className="text-xs text-subtle">Not set</span>
                                            )}
                                        </Td>
                                        {usesProfiles && (
                                            <Td>
                                                {client.upwork_profiles && client.upwork_profiles.length > 0 ? (
                                                    <div className="flex flex-wrap gap-1">
                                                        {client.upwork_profiles.map((profile) => (
                                                            <Badge key={profile.id} tone="info">
                                                                {profile.name}
                                                            </Badge>
                                                        ))}
                                                    </div>
                                                ) : client.upwork_profile ? (
                                                    <Badge tone="info">{client.upwork_profile.name}</Badge>
                                                ) : (
                                                    <span className="text-xs text-subtle">Not set</span>
                                                )}
                                            </Td>
                                        )}
                                        <Td>
                                            {client.tags && client.tags.length > 0 ? (
                                                <div className="flex flex-wrap gap-1">
                                                    {client.tags.map((tag, i) => (
                                                        <Badge key={i} tone="neutral">
                                                            {tag}
                                                        </Badge>
                                                    ))}
                                                </div>
                                            ) : (
                                                <span className="text-xs text-subtle">No tags</span>
                                            )}
                                        </Td>
                                        <Td>
                                            <Badge tone="accent" className="font-mono tabular-nums">
                                                {client.weekly_hours_worked || '00:00'}
                                            </Badge>
                                        </Td>
                                        {canManage && (
                                            <Td className="text-right">
                                                <div className="flex items-center justify-end gap-1">
                                                    <Button
                                                        as={Link}
                                                        href={editClientHref(client.id)}
                                                        variant="secondary"
                                                        size="sm"
                                                        icon={<Pencil className="h-3.5 w-3.5" />}
                                                    >
                                                        Edit
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="!px-2"
                                                        onClick={() => handleSetStatus(client)}
                                                        title={
                                                            client.is_active === false
                                                                ? `Restore ${client.name}`
                                                                : `Archive ${client.name}`
                                                        }
                                                        aria-label={
                                                            client.is_active === false
                                                                ? `Restore ${client.name}`
                                                                : `Archive ${client.name}`
                                                        }
                                                        icon={
                                                            client.is_active === false ? (
                                                                <RotateCcw className="h-4 w-4" />
                                                            ) : (
                                                                <Archive className="h-4 w-4" />
                                                            )
                                                        }
                                                    />
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="!px-2 hover:text-danger"
                                                        onClick={() => confirmDelete(client.id)}
                                                        title={`Delete ${client.name}`}
                                                        aria-label={`Delete ${client.name}`}
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

                    {/* Pagination */}
                    {rows.length > 0 && (
                        <div className="flex flex-col items-center justify-between gap-3 border-t border-line px-4 py-3 sm:flex-row">
                            <p className="text-sm text-muted">
                                Showing <span className="font-medium text-body">{list.from}</span> to{' '}
                                <span className="font-medium text-body">{list.to}</span> of{' '}
                                <span className="font-medium text-body">{list.total}</span> results
                            </p>
                            {Array.isArray(list.links) && list.links.length > 3 && (
                                <nav className="flex flex-wrap items-center gap-1" aria-label="Pagination">
                                    {list.links.map((link, index) => {
                                        const isPrev = index === 0;
                                        const isNext = index === list.links.length - 1;
                                        const label = isPrev ? (
                                            <ChevronLeft className="h-4 w-4" />
                                        ) : isNext ? (
                                            <ChevronRight className="h-4 w-4" />
                                        ) : (
                                            <PageLabel label={link.label} />
                                        );

                                        if (!link.url) {
                                            return (
                                                <span
                                                    key={index}
                                                    className="inline-flex h-8 min-w-8 cursor-not-allowed items-center justify-center rounded-lg px-2 text-sm text-subtle"
                                                >
                                                    {label}
                                                </span>
                                            );
                                        }
                                        if (link.active) {
                                            return (
                                                <span
                                                    key={index}
                                                    aria-current="page"
                                                    className="inline-flex h-8 min-w-8 items-center justify-center rounded-lg bg-accent px-2 text-sm font-medium text-white"
                                                >
                                                    {label}
                                                </span>
                                            );
                                        }
                                        return (
                                            <Link
                                                key={index}
                                                href={link.url}
                                                preserveState
                                                preserveScroll={false}
                                                className="inline-flex h-8 min-w-8 items-center justify-center rounded-lg border border-line bg-surface-2 px-2 text-sm text-body transition hover:bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                                            >
                                                {label}
                                            </Link>
                                        );
                                    })}
                                </nav>
                            )}
                        </div>
                    )}
                </Card>
            </div>

            {/* Toast */}
            {toast && (
                <div
                    className={cx(
                        'fixed right-5 top-5 z-50 flex items-center gap-4 rounded-xl border px-5 py-3 shadow-lg',
                        toastType === 'error'
                            ? 'border-danger/30 bg-danger-soft text-danger'
                            : 'border-success/30 bg-success-soft text-success'
                    )}
                    role="status"
                >
                    <span className="text-sm font-medium">{toast}</span>
                    <button
                        onClick={closeToast}
                        className="text-lg leading-none opacity-70 transition hover:opacity-100"
                        aria-label="Dismiss"
                    >
                        &times;
                    </button>
                </div>
            )}

            {/* Single delete confirmation */}
            <Modal show={Boolean(deleteId)} onClose={() => setDeleteId(null)} maxWidth="md">
                <div className="p-6">
                    <h2 className="text-lg font-semibold text-body">Delete {clientTerm.toLowerCase()}</h2>
                    <p className="mt-2 text-sm text-muted">
                        Are you sure you want to delete this {clientTerm.toLowerCase()}? This action cannot be undone.
                    </p>
                    <div className="mt-6 flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setDeleteId(null)}>
                            Cancel
                        </Button>
                        <Button variant="danger" onClick={handleDelete} icon={<Trash2 className="h-4 w-4" />}>
                            Delete
                        </Button>
                    </div>
                </div>
            </Modal>

            {/* Bulk delete confirmation */}
            <Modal show={showBulkDeleteModal} onClose={() => setShowBulkDeleteModal(false)} maxWidth="md">
                <div className="p-6">
                    <h2 className="text-lg font-semibold text-body">Delete selected {clientTermPlural.toLowerCase()}</h2>
                    <p className="mt-2 text-sm text-muted">
                        Are you sure you want to delete {selectedClients.size}{' '}
                        {selectedClients.size === 1 ? clientTerm.toLowerCase() : clientTermPlural.toLowerCase()}? This
                        action cannot be undone.
                    </p>
                    <div className="mt-6 flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setShowBulkDeleteModal(false)}>
                            Cancel
                        </Button>
                        <Button variant="danger" onClick={confirmBulkDelete} icon={<Trash2 className="h-4 w-4" />}>
                            Delete {selectedClients.size}
                        </Button>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
