import { useEffect, useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { CalendarClock, Pencil, Plus, Search, ShieldCheck, Trash2, UserCheck, UserX, Users } from 'lucide-react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Avatar from '@/Components/Avatar';
import ActiveFilterChips from '@/Components/Filters/ActiveFilterChips';
import SearchableMultiSelect from '@/Components/Filters/SearchableMultiSelect';
import Modal from '@/Components/Modal';
import {
    Button,
    Card,
    Badge,
    Table,
    THead,
    TBody,
    Tr,
    Th,
    Td,
    Field,
    Input,
    Select,
    EmptyState,
    Tabs,
} from '@/Components/ui';

const cx = (...a) => a.filter(Boolean).join(' ');

// Role -> Badge tone. Kept faithful to the three known roles; anything else
// falls back to neutral. Super Admin also gets a shield glyph.
const roleTone = (role) => ({
    super_admin: 'accent',
    admin: 'info',
    member: 'neutral',
}[role] || 'neutral');

const noDesignationValue = 'no_designation';

// Fallback for the Active / Deactivated switcher when the controller does not
// supply filterOptions.status (e.g. offline preview).
const STATUS_TABS = [
    { key: 'all', label: 'All' },
    { key: 'active', label: 'Active' },
    { key: 'archived', label: 'Deactivated' },
];

// ---------------------------------------------------------------------------
// DEV_SAMPLE — used ONLY when `preview` is true so the page renders fully
// offline for visual QA. Mirrors the controller's Inertia payload shape
// (users paginator + filters + filterOptions + managedDesignations +
// managedShifts + permissionGroups) with ~8 varied users — two of them
// DEACTIVATED (is_active: false) so the Active/Deactivated tabs, the neutral
// badge and the reactivate action can be QA'd offline, and a mix of named /
// unnamed shifts so the Shift column and the shift manager render fully.
// Never touched in normal rendering.
// ---------------------------------------------------------------------------
const DEV_SAMPLE = {
    user: {
        id: 1,
        name: 'Jawad',
        email: 'jawad@staffvora.com',
        is_super_admin: true,
        permissions: [],
    },
    users: {
        total: 8,
        per_page: 10,
        current_page: 1,
        last_page: 1,
        from: 1,
        to: 8,
        links: [
            { url: null, label: '&laquo; Previous', active: false },
            { url: '#', label: '1', active: true },
            { url: null, label: 'Next &raquo;', active: false },
        ],
        data: [
            { id: 1, name: 'Jawad Islam', email: 'jawad@staffvora.com', role: 'super_admin', role_label: 'Super Admin', designation: 'Founder', joining_date_display: '2023-01-04', shift_start_display: '9:00 AM', shift_grace_minutes: 15, shift_id: 1, shift_name: 'Morning', weekly_hours_worked: '41:20', avatar_url: null, is_active: true },
            { id: 2, name: 'Ayesha Khan', email: 'ayesha@staffvora.com', role: 'admin', role_label: 'Admin', designation: 'Engineering Lead', joining_date_display: '2023-03-12', shift_start_display: '9:30 AM', shift_grace_minutes: 10, shift_id: 1, shift_name: 'Morning', weekly_hours_worked: '38:05', avatar_url: null, is_active: true },
            { id: 3, name: 'Bilal Ahmed', email: 'bilal@staffvora.com', role: 'member', role_label: 'Member', designation: 'Backend Developer', joining_date_display: '2023-06-01', shift_start_display: '10:00 AM', shift_grace_minutes: 15, shift_id: 1, shift_name: 'Morning', weekly_hours_worked: '40:00', avatar_url: null, is_active: true },
            { id: 4, name: 'Chen Wei', email: 'chen@staffvora.com', role: 'member', role_label: 'Member', designation: 'Frontend Developer', joining_date_display: '2024-02-19', shift_start_display: null, shift_grace_minutes: 15, shift_id: null, shift_name: null, weekly_hours_worked: '12:45', avatar_url: null, is_active: false },
            { id: 5, name: 'Maria Lopez', email: 'maria@staffvora.com', role: 'admin', role_label: 'Admin', designation: 'Design Lead', joining_date_display: '2023-09-08', shift_start_display: '8:30 AM', shift_grace_minutes: 20, shift_id: 1, shift_name: 'Morning', weekly_hours_worked: '35:30', avatar_url: null, is_active: true },
            { id: 6, name: 'Omar Farooq', email: 'omar@staffvora.com', role: 'member', role_label: 'Member', designation: null, joining_date_display: '2024-05-14', shift_start_display: '9:00 AM', shift_grace_minutes: 15, shift_id: 1, shift_name: 'Morning', weekly_hours_worked: '00:00', avatar_url: null, is_active: false },
            { id: 7, name: 'Sana Riaz', email: 'sana@staffvora.com', role: 'member', role_label: 'Member', designation: 'QA Engineer', joining_date_display: '2024-07-30', shift_start_display: '11:00 AM', shift_grace_minutes: 5, shift_id: 2, shift_name: 'Noon', weekly_hours_worked: '29:15', avatar_url: null, is_active: true },
            { id: 8, name: 'Zoe Bennett', email: 'zoe@staffvora.com', role: 'member', role_label: 'Member', designation: 'Operations', joining_date_display: '2024-11-02', shift_start_display: null, shift_grace_minutes: 15, shift_id: null, shift_name: null, weekly_hours_worked: '18:40', avatar_url: null, is_active: true },
        ],
    },
    filters: { search: '', roles: [], designations: [], status: 'all', sort: 'name', dir: 'asc' },
    filterOptions: {
        roles: [
            { value: 'super_admin', label: 'Super Admin' },
            { value: 'admin', label: 'Admin' },
            { value: 'member', label: 'Member' },
        ],
        designations: ['Backend Developer', 'Design Lead', 'Engineering Lead', 'Founder', 'Frontend Developer', 'Operations', 'QA Engineer'],
        status: [
            { value: 'all', label: 'All' },
            { value: 'active', label: 'Active' },
            { value: 'archived', label: 'Deactivated' },
        ],
    },
    managedDesignations: [
        { id: 1, name: 'Backend Developer' },
        { id: 2, name: 'Design Lead' },
        { id: 3, name: 'Engineering Lead' },
        { id: 4, name: 'Frontend Developer' },
        { id: 5, name: 'QA Engineer' },
    ],
    managedShifts: [
        { id: 1, name: 'Morning', start_time: null, hours: null, grace_minutes: null },
        { id: 2, name: 'Noon', start_time: '13:00', hours: 8, grace_minutes: 10 },
        { id: 3, name: 'Evening', start_time: null, hours: null, grace_minutes: null },
        { id: 4, name: 'Night', start_time: '00:00', hours: 8, grace_minutes: 15 },
    ],
    permissionGroups: {
        Users: { 'users.manage': 'Manage users', 'users.delete': 'Delete users' },
        Reports: { 'reports.view': 'View reports' },
    },
};

export default function UsersList({
    auth,
    users,
    filters = {},
    filterOptions = {},
    managedDesignations = [],
    managedShifts = [],
    permissionGroups = {},
    preview = false,
}) {
    // In preview mode, ignore incoming props and render entirely from DEV_SAMPLE.
    const authUser = preview ? DEV_SAMPLE.user : auth?.user;
    users = preview ? DEV_SAMPLE.users : users;
    filters = preview ? DEV_SAMPLE.filters : filters;
    filterOptions = preview ? DEV_SAMPLE.filterOptions : filterOptions;
    managedDesignations = preview ? DEV_SAMPLE.managedDesignations : managedDesignations;
    managedShifts = preview ? DEV_SAMPLE.managedShifts : managedShifts;
    permissionGroups = preview ? DEV_SAMPLE.permissionGroups : permissionGroups;

    const can = (permission) => authUser?.is_super_admin || authUser?.permissions?.includes(permission);
    const [search, setSearch] = useState(filters.search || '');
    const [roles, setRoles] = useState((filters.roles || []).map(String));
    const [designations, setDesignations] = useState((filters.designations || []).map(String));
    const [statusFilter, setStatusFilter] = useState(filters.status || 'all');
    const [perPage, setPerPage] = useState(users?.per_page || 10);
    const [deleteUser, setDeleteUser] = useState(null);
    const [showDesignationDialog, setShowDesignationDialog] = useState(false);
    const [newDesignation, setNewDesignation] = useState('');
    // Named shifts (Morning / Noon / Evening / Night / …). Curated in one place
    // and assigned to people from the bulk editor, so the value is pick-only.
    const [showShiftDialog, setShowShiftDialog] = useState(false);
    const [newShift, setNewShift] = useState({ name: '', start_time: '', hours: '', grace_minutes: '' });

    // Bulk edit: only the sections the admin enables get applied.
    const [selectedIds, setSelectedIds] = useState(new Set());
    const [selectAllMatching, setSelectAllMatching] = useState(false);
    const [showBulkModal, setShowBulkModal] = useState(false);
    const [bulkSaving, setBulkSaving] = useState(false);
    const [bulk, setBulk] = useState({
        setShift: false, shiftTime: '', shiftGrace: 15,
        setShiftName: false, shiftId: '',
        setDesignation: false, designation: '',
        permsAdd: [], permsRemove: [],
        slackMode: '',
    });

    const permissionOptions = useMemo(() => (
        Object.entries(permissionGroups).flatMap(([group, perms]) =>
            Object.entries(perms).map(([key, def]) => ({
                value: key,
                label: `${group} — ${typeof def === 'string' ? def : def.label}`,
            }))
        )
    ), [permissionGroups]);

    const toggleSelected = (id) => {
        setSelectAllMatching(false);
        setSelectedIds((prev) => {
            const next = new Set(prev);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    };

    const submitBulk = async () => {
        if (preview) { setShowBulkModal(false); return; }
        setBulkSaving(true);
        try {
            const res = await axios.post(route('users.bulk-update'), {
                user_ids: [...selectedIds],
                set_shift: bulk.setShift,
                shift_start_time: bulk.setShift ? (bulk.shiftTime || null) : null,
                shift_grace_minutes: bulk.setShift ? Number(bulk.shiftGrace) : null,
                set_shift_name: bulk.setShiftName,
                shift_id: bulk.setShiftName ? (bulk.shiftId ? Number(bulk.shiftId) : null) : null,
                set_designation: bulk.setDesignation,
                designation: bulk.setDesignation ? bulk.designation : null,
                permissions_add: bulk.permsAdd,
                permissions_remove: bulk.permsRemove,
                slack_reports: bulk.slackMode || null,
            });
            setShowBulkModal(false);
            setSelectedIds(new Set());
            setBulk({ setShift: false, shiftTime: '', shiftGrace: 15, setShiftName: false, shiftId: '', setDesignation: false, designation: '', permsAdd: [], permsRemove: [], slackMode: '' });
            router.reload({ preserveScroll: true });
            window.alert(res.data.message);
        } catch (err) {
            window.alert(err.response?.data?.message || 'Bulk update failed.');
        } finally {
            setBulkSaving(false);
        }
    };

    const roleOptions = filterOptions.roles || [];
    const designationOptions = [
        { value: noDesignationValue, label: 'No designation' },
        ...(filterOptions.designations || []).map((designation) => ({
            value: String(designation),
            label: String(designation),
        })),
    ];

    const applyFilters = (changes = {}) => {
        if (preview) return;
        const next = {
            search: changes.search ?? search,
            roles: changes.roles ?? roles,
            designations: changes.designations ?? designations,
            status: changes.status ?? statusFilter,
            perPage: changes.perPage ?? perPage,
            sort: changes.sort ?? (filters.sort || 'name'),
            dir: changes.dir ?? (filters.dir || 'asc'),
        };

        router.get(route('users.index'), next, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const sortBy = (column) => {
        const dir = (filters.sort || 'name') === column && (filters.dir || 'asc') === 'asc' ? 'desc' : 'asc';
        applyFilters({ sort: column, dir });
    };

    useEffect(() => {
        if (preview) return;
        const timer = window.setTimeout(() => {
            if (search !== (filters.search || '')) applyFilters({ search });
        }, 400);

        return () => window.clearTimeout(timer);
    }, [search]);

    const chips = useMemo(() => {
        const items = [];
        const roleLabels = new Map(roleOptions.map((option) => [String(option.value), option.label]));
        const designationLabels = new Map(designationOptions.map((option) => [String(option.value), option.label]));

        roles.forEach((role) => items.push({
            key: `role-${role}`,
            label: `Role: ${roleLabels.get(role) || role}`,
            onRemove: () => {
                const next = roles.filter((item) => item !== role);
                setRoles(next);
                applyFilters({ roles: next });
            },
        }));
        designations.forEach((designation) => items.push({
            key: `designation-${designation}`,
            label: `Designation: ${designationLabels.get(designation) || designation}`,
            onRemove: () => {
                const next = designations.filter((item) => item !== designation);
                setDesignations(next);
                applyFilters({ designations: next });
            },
        }));

        return items;
    }, [roles, designations, roleOptions, designationOptions]);

    const clearFilters = () => {
        setRoles([]);
        setDesignations([]);
        setSearch('');
        setStatusFilter('all');
        setSelectedIds(new Set());
        setSelectAllMatching(false);
        if (preview) return;
        router.get(route('users.index'), { perPage }, { preserveState: true, replace: true });
    };

    // Active / Deactivated switcher. Clears the selection so a bulk action can
    // never span two different result sets.
    const changeStatus = (next) => {
        setStatusFilter(next);
        setSelectedIds(new Set());
        setSelectAllMatching(false);
        applyFilters({ status: next });
    };

    const currentListUrl = () => (
        typeof window === 'undefined'
            ? route('users.index')
            : `${window.location.pathname}${window.location.search}`
    );

    const editUserHref = (userId) => {
        if (preview) return '#';
        const returnTo = currentListUrl();
        return `${route('users.edit', userId)}?return_to=${encodeURIComponent(returnTo)}`;
    };

    const confirmDelete = () => {
        if (!deleteUser) return;
        if (preview) { setDeleteUser(null); return; }
        router.delete(route('users.destroy', deleteUser.id), {
            data: { return_to: currentListUrl() },
            preserveState: true,
            preserveScroll: true,
            onFinish: () => setDeleteUser(null),
        });
    };

    const addDesignation = (event) => {
        event.preventDefault();
        const name = newDesignation.trim();

        if (!name) return;
        if (preview) { setNewDesignation(''); return; }

        router.post(route('users.designations.store'), {
            name,
            return_to: currentListUrl(),
        }, {
            preserveScroll: true,
            onSuccess: () => setNewDesignation(''),
        });
    };

    // Deactivate someone who left (or reactivate). Their history is kept; a
    // deactivated user can't log in or track, and drops out of active views.
    const setStatus = (user) => {
        const isActive = user.is_active === false;
        if (preview) return;
        if (!isActive && !window.confirm(`Deactivate ${user.name}? They can no longer sign in or track time. History is kept; you can reactivate anytime.`)) return;
        router.patch(
            route('users.set-status', user.id),
            { is_active: isActive, return_to: currentListUrl() },
            { preserveScroll: true }
        );
    };

    // Bulk activate/deactivate: either the explicit ticked set, or — for the
    // first cleanup — everyone matching the current filters ("select all N").
    const bulkStatus = (isActive) => {
        const count = selectAllMatching ? users.total : selectedIds.size;
        if (!count) return;
        if (preview) { setSelectedIds(new Set()); setSelectAllMatching(false); return; }
        const verb = isActive ? 'Reactivate' : 'Deactivate';
        if (!window.confirm(`${verb} ${count} user${count === 1 ? '' : 's'}? History is kept; this is reversible.`)) return;
        const payload = selectAllMatching
            ? { is_active: isActive, all_matching: true, search, roles, designations, status: statusFilter, return_to: currentListUrl() }
            : { is_active: isActive, ids: [...selectedIds], return_to: currentListUrl() };
        router.post(route('users.bulk-status'), payload, {
            preserveScroll: true,
            onFinish: () => { setSelectedIds(new Set()); setSelectAllMatching(false); },
        });
    };

    // Add a named shift to the curated list. Timing is optional: leave it blank
    // and the shift stays a pure label, so each person's own shift start time
    // keeps driving their schedule.
    const addShift = (event) => {
        event.preventDefault();
        const name = newShift.name.trim();

        if (!name) return;
        if (preview) { setNewShift({ name: '', start_time: '', hours: '', grace_minutes: '' }); return; }

        router.post(route('users.shifts.store'), {
            name,
            start_time: newShift.start_time || null,
            hours: newShift.hours === '' ? null : Number(newShift.hours),
            grace_minutes: newShift.grace_minutes === '' ? null : Number(newShift.grace_minutes),
            return_to: currentListUrl(),
        }, {
            preserveScroll: true,
            onSuccess: () => setNewShift({ name: '', start_time: '', hours: '', grace_minutes: '' }),
        });
    };

    const removeShift = (shift) => {
        if (preview) return;
        if (!window.confirm(`Remove the "${shift.name}" shift? Anyone assigned to it keeps their own shift times.`)) return;
        router.delete(route('users.shifts.destroy', shift.id), {
            data: { return_to: currentListUrl() },
            preserveScroll: true,
        });
    };

    const removeDesignation = (designation) => {
        if (preview) return;
        if (!window.confirm(`Remove the "${designation.name}" designation? Anyone currently assigned to it keeps their own designation.`)) return;
        router.delete(route('users.designations.destroy', designation.id), {
            data: { return_to: currentListUrl() },
            preserveScroll: true,
        });
    };

    const activeSort = filters.sort || 'name';
    const activeDir = filters.dir || 'asc';
    const colSpan = can('users.manage') ? 7 : 6;

    return (
        <AuthenticatedLayout
            user={authUser}
            header={<h2 className="text-xl font-semibold text-body">Users</h2>}
        >
            <Head title="Users" />

            <div className="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8 space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <p className="max-w-2xl text-sm text-muted">
                        Manage accounts, roles, designations, and selected access.
                    </p>
                    {can('users.manage') && (
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Button variant="secondary" onClick={() => setShowDesignationDialog(true)}>
                                Manage designations
                            </Button>
                            <Button
                                variant="secondary"
                                onClick={() => setShowShiftDialog(true)}
                                icon={<CalendarClock className="h-4 w-4" />}
                            >
                                Manage shifts
                            </Button>
                            <Button
                                as={Link}
                                href={preview ? '#' : route('users.create')}
                                icon={<Plus className="h-4 w-4" />}
                            >
                                Add user
                            </Button>
                        </div>
                    )}
                </div>

                {/* Filters */}
                <Card padded={false}>
                    <div className="grid gap-4 p-4 md:grid-cols-2 xl:grid-cols-[minmax(280px,1fr)_260px_260px_130px]">
                        <Field label="Search" htmlFor="users-search">
                            <div className="relative">
                                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-subtle" />
                                <Input
                                    id="users-search"
                                    type="search"
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    placeholder="Name or email"
                                    className="pl-9"
                                />
                            </div>
                        </Field>
                        <SearchableMultiSelect
                            label="Roles"
                            options={roleOptions}
                            selectedValues={roles}
                            onChange={(next) => {
                                setRoles(next);
                                applyFilters({ roles: next });
                            }}
                            placeholder="All roles"
                        />
                        <SearchableMultiSelect
                            label="Designations"
                            options={designationOptions}
                            selectedValues={designations}
                            onChange={(next) => {
                                setDesignations(next);
                                applyFilters({ designations: next });
                            }}
                            placeholder="All designations"
                        />
                        <Field label="Rows" htmlFor="users-perpage">
                            <Select
                                id="users-perpage"
                                value={perPage}
                                onChange={(event) => {
                                    const next = Number(event.target.value);
                                    setPerPage(next);
                                    applyFilters({ perPage: next });
                                }}
                            >
                                {[10, 25, 50, 100].map((value) => <option key={value} value={value}>{value}</option>)}
                            </Select>
                        </Field>
                    </div>
                    {chips.length > 0 && (
                        <div className="px-4 pb-4">
                            <ActiveFilterChips chips={chips} onClearAll={clearFilters} />
                        </div>
                    )}
                </Card>

                {/* Directory */}
                <Card padded={false}>
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4">
                        <div className="flex items-center gap-3">
                            <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-accent/10 text-accent">
                                <Users className="h-5 w-5" />
                            </span>
                            <div>
                                <h2 className="font-semibold text-body">Team directory</h2>
                                <p className="text-sm text-muted">{users.total} account{users.total === 1 ? '' : 's'}</p>
                            </div>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <Tabs
                                size="sm"
                                tabs={filterOptions.status?.length
                                    ? filterOptions.status.map((o) => ({ key: String(o.value), label: o.label }))
                                    : STATUS_TABS}
                                value={statusFilter}
                                onChange={changeStatus}
                                aria-label="Filter users by status"
                            />
                            {(selectedIds.size > 0 || selectAllMatching) && can('users.manage') && (
                                <>
                                    <span className="text-sm font-medium text-accent">
                                        {selectAllMatching ? `All ${users.total} matching selected` : `${selectedIds.size} selected`}
                                    </span>
                                    <Button
                                        size="sm"
                                        onClick={() => setShowBulkModal(true)}
                                        disabled={selectAllMatching}
                                        title={selectAllMatching ? 'Bulk edit works on an explicit selection' : undefined}
                                    >
                                        Bulk edit
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        onClick={() => bulkStatus(false)}
                                        icon={<UserX className="h-4 w-4" />}
                                    >
                                        Deactivate
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        onClick={() => bulkStatus(true)}
                                        icon={<UserCheck className="h-4 w-4" />}
                                    >
                                        Reactivate
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => { setSelectedIds(new Set()); setSelectAllMatching(false); }}
                                    >
                                        Clear
                                    </Button>
                                </>
                            )}
                        </div>
                    </div>

                    {/* "Select all N matching" escape hatch for the first big cleanup */}
                    {can('users.manage')
                        && !selectAllMatching
                        && selectedIds.size > 0
                        && users.total > users.data.length
                        && selectedIds.size >= users.data.filter((u) => !(u.role === 'super_admin' && !authUser.is_super_admin)).length && (
                        <div className="border-b border-line bg-accent/5 px-5 py-2 text-center text-sm text-muted">
                            All {selectedIds.size} on this page selected.{' '}
                            <button
                                type="button"
                                onClick={() => setSelectAllMatching(true)}
                                className="font-medium text-accent hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                            >
                                Select all {users.total} matching your filters
                            </button>
                        </div>
                    )}

                    {users.data.length === 0 ? (
                        <EmptyState
                            icon={<Users className="h-5 w-5" />}
                            title="No users found"
                            description="Adjust the search or selected filters."
                        />
                    ) : (
                        <Table>
                            <THead>
                                <Tr className="hover:bg-transparent">
                                    {can('users.manage') && (
                                        <Th className="w-10">
                                            <input
                                                type="checkbox"
                                                aria-label="Select all users"
                                                checked={users.data.length > 0 && users.data.every((u) => selectedIds.has(u.id) || (u.role === 'super_admin' && !authUser.is_super_admin))}
                                                onChange={(e) => {
                                                    const next = new Set(selectedIds);
                                                    users.data.forEach((u) => {
                                                        if (u.role === 'super_admin' && !authUser.is_super_admin) return;
                                                        e.target.checked ? next.add(u.id) : next.delete(u.id);
                                                    });
                                                    setSelectedIds(next);
                                                }}
                                                className="h-4 w-4 rounded border-line bg-surface-2 text-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app"
                                            />
                                        </Th>
                                    )}
                                    <Th sortable active={activeSort === 'name'} dir={activeDir} onClick={() => sortBy('name')}>User</Th>
                                    <Th sortable active={activeSort === 'designation'} dir={activeDir} onClick={() => sortBy('designation')}>Designation</Th>
                                    <Th sortable active={activeSort === 'shift'} dir={activeDir} onClick={() => sortBy('shift')}>Shift</Th>
                                    <Th sortable active={activeSort === 'role'} dir={activeDir} onClick={() => sortBy('role')}>Role</Th>
                                    <Th
                                        sortable
                                        active={activeSort === 'weekly_hours'}
                                        dir={activeDir}
                                        onClick={() => sortBy('weekly_hours')}
                                        title="Work-diary hours this week (tracker + manual entries) — not clock in/out time."
                                    >
                                        Weekly tracked
                                    </Th>
                                    <Th>Actions</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {users.data.map((user) => {
                                    const canEdit = can('users.manage') && (authUser.is_super_admin || user.role !== 'super_admin');
                                    const canDelete = can('users.delete')
                                        && user.id !== authUser.id
                                        && (authUser.is_super_admin || user.role !== 'super_admin');
                                    const selected = selectedIds.has(user.id);
                                    const isArchived = user.is_active === false;

                                    return (
                                        <Tr
                                            key={user.id}
                                            className={cx(
                                                selected && 'bg-accent/10',
                                                isArchived && 'opacity-60'
                                            )}
                                        >
                                            {can('users.manage') && (
                                                <Td className="w-10">
                                                    <input
                                                        type="checkbox"
                                                        aria-label={`Select ${user.name}`}
                                                        checked={selected}
                                                        disabled={user.role === 'super_admin' && !authUser.is_super_admin}
                                                        onChange={() => toggleSelected(user.id)}
                                                        className="h-4 w-4 rounded border-line bg-surface-2 text-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app disabled:opacity-50 disabled:cursor-not-allowed"
                                                    />
                                                </Td>
                                            )}
                                            <Td className="min-w-64">
                                                <div className="flex items-center gap-3">
                                                    <Avatar user={user} size="md" />
                                                    <div className="min-w-0">
                                                        <div className="flex items-center gap-2">
                                                            <span className="truncate text-sm font-medium text-body">{user.name}</span>
                                                            {isArchived && (
                                                                <Badge tone="neutral" className="shrink-0">Deactivated</Badge>
                                                            )}
                                                        </div>
                                                        <div className="truncate text-sm text-muted">{user.email}</div>
                                                    </div>
                                                </div>
                                            </Td>
                                            <Td className="whitespace-nowrap">
                                                <div className="text-body">{user.designation || 'No designation'}</div>
                                                {user.joining_date_display && (
                                                    <div className="text-xs text-subtle">Joined {user.joining_date_display}</div>
                                                )}
                                            </Td>
                                            <Td className="whitespace-nowrap">
                                                {user.shift_name && (
                                                    <Badge tone="info" className="mb-1">{user.shift_name}</Badge>
                                                )}
                                                {user.shift_start_display ? (
                                                    <>
                                                        <div className="text-body">{user.shift_start_display}</div>
                                                        <div className="text-xs text-subtle">{user.shift_grace_minutes ?? 15}m grace</div>
                                                    </>
                                                ) : (
                                                    !user.shift_name && <span className="text-subtle">Not configured</span>
                                                )}
                                            </Td>
                                            <Td className="whitespace-nowrap">
                                                <Badge tone={roleTone(user.role)} dot={user.role !== 'super_admin'}>
                                                    {user.role === 'super_admin' && <ShieldCheck className="h-3.5 w-3.5" />}
                                                    {user.role_label || user.role}
                                                </Badge>
                                            </Td>
                                            <Td className="whitespace-nowrap font-mono font-medium text-body tabular-nums">
                                                {user.weekly_hours_worked || '00:00'}
                                            </Td>
                                            <Td className="whitespace-nowrap">
                                                <div className="flex items-center gap-2">
                                                    {canEdit && (
                                                        <Button
                                                            as={Link}
                                                            href={editUserHref(user.id)}
                                                            variant="secondary"
                                                            size="sm"
                                                            className="!px-2"
                                                            title={`Edit ${user.name}`}
                                                            aria-label={`Edit ${user.name}`}
                                                            icon={<Pencil className="h-4 w-4" />}
                                                        />
                                                    )}
                                                    {canEdit && user.id !== authUser.id && (
                                                        <Button
                                                            variant="secondary"
                                                            size="sm"
                                                            className="!px-2"
                                                            onClick={() => setStatus(user)}
                                                            title={isArchived ? `Reactivate ${user.name}` : `Deactivate ${user.name}`}
                                                            aria-label={isArchived ? `Reactivate ${user.name}` : `Deactivate ${user.name}`}
                                                            icon={isArchived
                                                                ? <UserCheck className="h-4 w-4" />
                                                                : <UserX className="h-4 w-4" />}
                                                        />
                                                    )}
                                                    {canDelete && (
                                                        <Button
                                                            variant="danger"
                                                            size="sm"
                                                            className="!px-2"
                                                            onClick={() => setDeleteUser(user)}
                                                            title={`Delete ${user.name}`}
                                                            aria-label={`Delete ${user.name}`}
                                                            icon={<Trash2 className="h-4 w-4" />}
                                                        />
                                                    )}
                                                    {!canEdit && !canDelete && <span className="text-sm text-subtle">-</span>}
                                                </div>
                                            </Td>
                                        </Tr>
                                    );
                                })}
                            </TBody>
                        </Table>
                    )}

                    {users.total > 0 && users.links && (
                        <div className="flex flex-col items-center justify-between gap-3 border-t border-line px-4 py-4 sm:flex-row">
                            <p className="text-sm text-muted">
                                Showing <span className="font-medium text-body">{users.from}</span> to{' '}
                                <span className="font-medium text-body">{users.to}</span> of{' '}
                                <span className="font-medium text-body">{users.total}</span> results
                            </p>
                            {users.last_page > 1 && (
                                <nav className="flex flex-wrap items-center gap-1" aria-label="Pagination">
                                    {users.links.map((link, index) => {
                                        if (!link.url) {
                                            return (
                                                <span
                                                    key={index}
                                                    className="cursor-not-allowed px-3 py-2 text-sm text-subtle"
                                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                                />
                                            );
                                        }
                                        if (link.active) {
                                            return (
                                                <span
                                                    key={index}
                                                    aria-current="page"
                                                    className="rounded-lg bg-accent px-3.5 py-2 text-sm font-medium text-white"
                                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                                />
                                            );
                                        }
                                        return (
                                            <Link
                                                key={index}
                                                href={link.url}
                                                preserveState
                                                preserveScroll
                                                className="rounded-lg border border-line bg-surface px-3.5 py-2 text-sm font-medium text-body transition hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app"
                                                dangerouslySetInnerHTML={{ __html: link.label }}
                                            />
                                        );
                                    })}
                                </nav>
                            )}
                        </div>
                    )}
                </Card>
            </div>

            {/* Bulk edit */}
            <Modal show={showBulkModal} onClose={() => setShowBulkModal(false)} maxWidth="lg">
                <div className="max-h-[90vh] overflow-y-auto p-5">
                    <h2 className="text-lg font-semibold text-body">Bulk edit {selectedIds.size} user{selectedIds.size === 1 ? '' : 's'}</h2>
                    <p className="mt-1 text-sm text-muted">Only the sections you enable below will be applied — everything else stays untouched.</p>

                    <div className="mt-4 space-y-4">
                        <div className="rounded-lg border border-line p-3">
                            <label className="flex items-center gap-2 text-sm font-medium text-body">
                                <input type="checkbox" checked={bulk.setShift} onChange={(e) => setBulk({ ...bulk, setShift: e.target.checked })} className="h-4 w-4 rounded border-line bg-surface-2 text-accent focus-visible:ring-2 focus-visible:ring-accent" />
                                Set shift
                            </label>
                            {bulk.setShift && (
                                <div className="mt-2 flex flex-wrap items-center gap-3 text-sm text-body">
                                    <label className="flex items-center gap-2">Start
                                        <Input type="time" value={bulk.shiftTime} onChange={(e) => setBulk({ ...bulk, shiftTime: e.target.value })} className="w-auto" />
                                    </label>
                                    <label className="flex items-center gap-2">Grace (min)
                                        <Input type="number" min="0" max="240" value={bulk.shiftGrace} onChange={(e) => setBulk({ ...bulk, shiftGrace: e.target.value })} className="w-20" />
                                    </label>
                                    <span className="text-xs text-subtle">Leave start empty to clear the shift.</span>
                                </div>
                            )}
                        </div>

                        <div className="rounded-lg border border-line p-3">
                            <label className="flex items-center gap-2 text-sm font-medium text-body">
                                <input type="checkbox" checked={bulk.setShiftName} onChange={(e) => setBulk({ ...bulk, setShiftName: e.target.checked })} className="h-4 w-4 rounded border-line bg-surface-2 text-accent focus-visible:ring-2 focus-visible:ring-accent" />
                                Assign named shift
                            </label>
                            {bulk.setShiftName && (
                                <>
                                    <Select value={bulk.shiftId} onChange={(e) => setBulk({ ...bulk, shiftId: e.target.value })} className="mt-2">
                                        <option value="">No shift</option>
                                        {managedShifts.map((shift) => (
                                            <option key={shift.id} value={shift.id}>
                                                {shift.name}{shift.start_time ? ` — ${shift.start_time}` : ''}
                                            </option>
                                        ))}
                                    </Select>
                                    <p className="mt-2 text-xs text-subtle">
                                        A shift that carries its own start time becomes the source of truth for everyone
                                        assigned to it. Label-only shifts leave each person&apos;s own shift time alone.
                                    </p>
                                </>
                            )}
                        </div>

                        <div className="rounded-lg border border-line p-3">
                            <label className="flex items-center gap-2 text-sm font-medium text-body">
                                <input type="checkbox" checked={bulk.setDesignation} onChange={(e) => setBulk({ ...bulk, setDesignation: e.target.checked })} className="h-4 w-4 rounded border-line bg-surface-2 text-accent focus-visible:ring-2 focus-visible:ring-accent" />
                                Set designation
                            </label>
                            {bulk.setDesignation && (
                                <Select value={bulk.designation} onChange={(e) => setBulk({ ...bulk, designation: e.target.value })} className="mt-2">
                                    <option value="">No designation</option>
                                    {(filterOptions.designations || []).map((d) => (
                                        <option key={d} value={d}>{d}</option>
                                    ))}
                                </Select>
                            )}
                        </div>

                        {authUser.is_super_admin && (
                            <div className="space-y-2 rounded-lg border border-line p-3">
                                <p className="text-sm font-medium text-body">Permissions <span className="text-xs font-normal text-subtle">(Super Admin only — applied on top of each user&apos;s current access)</span></p>
                                <SearchableMultiSelect
                                    label="Grant"
                                    options={permissionOptions}
                                    selectedValues={bulk.permsAdd}
                                    onChange={(values) => setBulk({ ...bulk, permsAdd: values })}
                                    placeholder="No permissions to grant"
                                    inline
                                />
                                <SearchableMultiSelect
                                    label="Revoke"
                                    options={permissionOptions}
                                    selectedValues={bulk.permsRemove}
                                    onChange={(values) => setBulk({ ...bulk, permsRemove: values })}
                                    placeholder="No permissions to revoke"
                                    inline
                                />
                            </div>
                        )}

                        <div className="rounded-lg border border-line p-3">
                            <label className="block text-sm font-medium text-body">Slack reports</label>
                            <Select value={bulk.slackMode} onChange={(e) => setBulk({ ...bulk, slackMode: e.target.value })} className="mt-2">
                                <option value="">Leave unchanged</option>
                                <option value="include">Include in reports</option>
                                <option value="exclude">Exclude from reports</option>
                            </Select>
                        </div>
                    </div>

                    <div className="mt-5 flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setShowBulkModal(false)}>Cancel</Button>
                        <Button onClick={submitBulk} loading={bulkSaving}>
                            {bulkSaving ? 'Applying…' : `Apply to ${selectedIds.size}`}
                        </Button>
                    </div>
                </div>
            </Modal>

            {/* Delete confirmation */}
            <Modal show={!!deleteUser} onClose={() => setDeleteUser(null)} maxWidth="md">
                <div className="p-5">
                    <h2 className="text-lg font-semibold text-body">Delete user?</h2>
                    <p className="mt-2 text-sm text-muted">
                        This will permanently delete {deleteUser?.name}. Existing related records may prevent deletion.
                    </p>
                    <div className="mt-5 flex justify-end gap-3">
                        <Button variant="secondary" onClick={() => setDeleteUser(null)}>Cancel</Button>
                        <Button variant="danger" onClick={confirmDelete}>Delete user</Button>
                    </div>
                </div>
            </Modal>

            {/* Manage designations */}
            <Modal show={showDesignationDialog} onClose={() => setShowDesignationDialog(false)} maxWidth="lg">
                <div>
                    <div className="border-b border-line px-5 py-4">
                        <h2 className="text-lg font-semibold text-body">Manage designations</h2>
                        <p className="mt-1 text-sm text-muted">These appear as suggestions when adding or editing users.</p>
                    </div>
                    <div className="space-y-4 p-5">
                        <form onSubmit={addDesignation} className="flex gap-2">
                            <Input
                                value={newDesignation}
                                onChange={(event) => setNewDesignation(event.target.value)}
                                placeholder="Add designation"
                                className="min-w-0 flex-1"
                            />
                            <Button type="submit">Add</Button>
                        </form>

                        <div className="max-h-72 overflow-y-auto rounded-lg border border-line">
                            {managedDesignations.length === 0 ? (
                                <div className="px-4 py-6 text-center text-sm text-subtle">No designation suggestions yet.</div>
                            ) : managedDesignations.map((designation) => (
                                <div key={designation.id} className="flex items-center justify-between gap-3 border-b border-line px-4 py-3 last:border-b-0">
                                    <span className="text-sm font-medium text-body">{designation.name}</span>
                                    <Button
                                        variant="danger"
                                        size="sm"
                                        className="!px-2"
                                        onClick={() => removeDesignation(designation)}
                                        title={`Remove ${designation.name}`}
                                        aria-label={`Remove ${designation.name}`}
                                        icon={<Trash2 className="h-4 w-4" />}
                                    />
                                </div>
                            ))}
                        </div>
                    </div>
                    <div className="flex justify-end border-t border-line px-5 py-4">
                        <Button variant="secondary" onClick={() => setShowDesignationDialog(false)}>Close</Button>
                    </div>
                </div>
            </Modal>

            {/* Manage named shifts */}
            <Modal show={showShiftDialog} onClose={() => setShowShiftDialog(false)} maxWidth="lg">
                <div>
                    <div className="border-b border-line px-5 py-4">
                        <h2 className="text-lg font-semibold text-body">Manage shifts</h2>
                        <p className="mt-1 text-sm text-muted">
                            The curated list people are assigned to. Give a shift a start time to make it drive the
                            schedule of everyone on it, or leave the timing blank to keep it a label.
                        </p>
                    </div>
                    <div className="space-y-4 p-5">
                        <form onSubmit={addShift} className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_120px_100px_110px_auto] sm:items-end">
                            <Field label="Name" htmlFor="shift-name">
                                <Input
                                    id="shift-name"
                                    value={newShift.name}
                                    onChange={(event) => setNewShift({ ...newShift, name: event.target.value })}
                                    placeholder="e.g. Late night"
                                />
                            </Field>
                            <Field label="Start" htmlFor="shift-start">
                                <Input
                                    id="shift-start"
                                    type="time"
                                    value={newShift.start_time}
                                    onChange={(event) => setNewShift({ ...newShift, start_time: event.target.value })}
                                />
                            </Field>
                            <Field label="Hours" htmlFor="shift-hours">
                                <Input
                                    id="shift-hours"
                                    type="number"
                                    min="0"
                                    max="24"
                                    step="0.5"
                                    value={newShift.hours}
                                    onChange={(event) => setNewShift({ ...newShift, hours: event.target.value })}
                                />
                            </Field>
                            <Field label="Grace (min)" htmlFor="shift-grace">
                                <Input
                                    id="shift-grace"
                                    type="number"
                                    min="0"
                                    max="240"
                                    value={newShift.grace_minutes}
                                    onChange={(event) => setNewShift({ ...newShift, grace_minutes: event.target.value })}
                                />
                            </Field>
                            <Button type="submit">Add</Button>
                        </form>

                        <div className="max-h-72 overflow-y-auto rounded-lg border border-line">
                            {managedShifts.length === 0 ? (
                                <div className="px-4 py-6 text-center text-sm text-subtle">No shifts yet.</div>
                            ) : managedShifts.map((shift) => (
                                <div key={shift.id} className="flex items-center justify-between gap-3 border-b border-line px-4 py-3 last:border-b-0">
                                    <div className="min-w-0">
                                        <span className="text-sm font-medium text-body">{shift.name}</span>
                                        <div className="text-xs text-subtle">
                                            {shift.start_time
                                                ? `Starts ${shift.start_time}${shift.hours ? ` · ${shift.hours}h` : ''}${shift.grace_minutes !== null && shift.grace_minutes !== undefined ? ` · ${shift.grace_minutes}m grace` : ''}`
                                                : 'Label only — uses each person’s own shift time'}
                                        </div>
                                    </div>
                                    <Button
                                        variant="danger"
                                        size="sm"
                                        className="!px-2"
                                        onClick={() => removeShift(shift)}
                                        title={`Remove ${shift.name}`}
                                        aria-label={`Remove ${shift.name}`}
                                        icon={<Trash2 className="h-4 w-4" />}
                                    />
                                </div>
                            ))}
                        </div>
                    </div>
                    <div className="flex justify-end border-t border-line px-5 py-4">
                        <Button variant="secondary" onClick={() => setShowShiftDialog(false)}>Close</Button>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
