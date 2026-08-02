import React, { useState, useEffect } from 'react';
import DatePicker from 'react-datepicker';
import 'react-datepicker/dist/react-datepicker.css';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { TraditionalPagination } from '@/Components/Pagination';
import { Head, Link, router } from '@inertiajs/react';
import { timeFormat } from '@/helpers';
import { exportRowsToCsv } from '@/Utils/csvExport';
import SearchableMultiSelect from '@/Components/Filters/SearchableMultiSelect';
import ActiveFilterChips from '@/Components/Filters/ActiveFilterChips';
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
    Input,
    Select,
    Checkbox,
    EmptyState,
} from '@/Components/ui';
import {
    Plus,
    Search,
    SlidersHorizontal,
    Download,
    Trash2,
    Pencil,
    FileText,
    Lock,
} from 'lucide-react';

const cx = (...a) => a.filter(Boolean).join(' ');

// Tracker-recorded entries are locked: their hours are what the desktop app
// measured, so they can't be hand-edited or deleted here. Only the labels
// (client, category, billable, description) can be corrected. The server
// enforces this too — WorkHourController::update()/destroy() — the UI explains.
const LOCK_HINT =
    'Tracked by the desktop app — the hours are locked. You can still fix the client, category, billable flag and description; to change the time itself, adjust that day in the Timeline.';

// Mirrors WorkHour::isLocked() on the server; falls back to sniffing the row
// for older payloads that predate the appended `is_locked` flag.
const isLockedEntry = (entry) =>
    entry?.is_locked ?? (entry?.source === 'tracker' || Boolean(entry?.tracking_session_id));

// ---------------------------------------------------------------------------
// DEV_SAMPLE — used ONLY when `preview` is true so the page renders fully
// offline for visual QA with a mock super-admin ("Jawad"). Mirrors the
// controller's shape: a Laravel paginator of work-hour entries plus the
// filterOptions / selectedFilters objects. Never referenced when preview=false.
// ---------------------------------------------------------------------------
const DEV_SAMPLE = {
    user: {
        id: 1,
        name: 'Jawad',
        email: 'jawad@staffvora.com',
        is_super_admin: true,
        permissions: [],
    },
    workHours: {
        current_page: 1,
        per_page: 15,
        from: 1,
        to: 8,
        total: 8,
        // Rows carry the NEW vocabulary: an eager-loaded work_category relation
        // (concept B) and is_billable (concept C). `work_type` is only still
        // here as the legacy reader for a row that predates the backfill —
        // row 4784 below is deliberately category-less to exercise that path.
        data: [
            { id: 4821, date: '2026-07-22', time_range: '09:00 – 12:30', client: { name: 'Acme Storefront Rebuild' }, work_category: { id: 1, name: 'Client Work' }, is_billable: true, work_type: 'tracker', capture_method: 'tracked', tracker: 'Primary Agency', source: 'tracker', is_locked: true, hours: 3.5, description: 'Checkout flow refactor and cart edge-cases.' },
            { id: 4820, date: '2026-07-22', time_range: null, client: { name: 'Acme Storefront Rebuild' }, work_category: { id: 1, name: 'Client Work' }, is_billable: true, work_type: 'manual', capture_method: 'manual', tracker: 'Primary Agency', source: 'manual', is_locked: false, hours: 1.25, description: 'Standup + code review for the payments PR.' },
            { id: 4815, date: '2026-07-21', time_range: '14:00 – 17:45', client: { name: 'Nimbus Mobile App' }, work_category: { id: 5, name: 'Meetings' }, is_billable: false, work_type: 'manual', capture_method: 'manual', tracker: 'Design Studio', source: 'manual', is_locked: false, hours: 3.75, description: 'Onboarding screens, dark-mode pass.' },
            { id: 4809, date: '2026-07-21', time_range: null, client: { name: 'Helios Analytics Dashboard' }, work_category: { id: 1, name: 'Client Work' }, is_billable: true, work_type: 'tracker', capture_method: 'tracked', tracker: 'Primary Agency', source: 'tracker', is_locked: true, hours: 2.0, description: 'Chart theming and CSV export.' },
            { id: 4802, date: '2026-07-20', time_range: null, client: { name: 'Orion CRM Integration' }, work_category: { id: 7, name: 'Outside Upwork' }, is_billable: true, work_type: 'outside_of_upwork', capture_method: 'manual', tracker: null, source: 'manual', is_locked: false, hours: 1.5, description: 'Discovery call with the client stakeholders.' },
            { id: 4796, date: '2026-07-20', time_range: null, client: null, work_category: { id: 2, name: 'Internal/Admin' }, is_billable: false, work_type: 'office_work', capture_method: 'manual', tracker: null, source: 'manual', is_locked: false, hours: 0.75, description: 'Weekly team sync and planning.' },
            { id: 4790, date: '2026-07-19', time_range: null, client: null, work_category: { id: 6, name: 'Bidding' }, is_billable: false, work_type: 'upwork_bidding', capture_method: 'manual', tracker: null, source: 'manual', is_locked: false, hours: 2.25, description: 'Proposals for three inbound leads.' },
            { id: 4784, date: '2026-07-19', time_range: null, client: null, work_category: null, is_billable: false, work_type: 'test_task', capture_method: 'manual', tracker: null, source: 'manual', is_locked: false, hours: 1.0, description: 'Take-home task for the Meridian brand site.' },
        ],
        links: [
            { url: null, label: '&laquo; Previous', active: false },
            { url: '#', label: '1', active: true },
            { url: null, label: 'Next &raquo;', active: false },
        ],
    },
    filterOptions: {
        // The workspace's OWN editable catalogue — an agency workspace is seeded
        // with the extra three rows, a General one is not. Same shape either way.
        workCategories: [
            { id: 1, name: 'Client Work', is_billable_default: true, is_active: true },
            { id: 2, name: 'Internal/Admin', is_billable_default: false, is_active: true },
            { id: 3, name: 'Business Development', is_billable_default: false, is_active: true },
            { id: 4, name: 'Training/Onboarding', is_billable_default: false, is_active: true },
            { id: 5, name: 'Meetings', is_billable_default: false, is_active: true },
            { id: 6, name: 'Bidding', is_billable_default: false, is_active: true },
            { id: 7, name: 'Outside Upwork', is_billable_default: true, is_active: true },
            { id: 8, name: 'Trial/Test task', is_billable_default: false, is_active: false },
        ],
        trackers: ['Primary Agency', 'Design Studio'],
        clients: ['Acme Storefront Rebuild', 'Helios Analytics Dashboard', 'Nimbus Mobile App', 'Orion CRM Integration'],
    },
    selectedFilters: { workCategories: [], billable: 'all', trackers: [], clients: [] },
};

// Helper Functions
const formatDateLocal = (date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
};

const getDateRange = (filter) => {
    const today = new Date();
    let start;
    let end;

    if (filter === 'today') {
        start = end = formatDateLocal(today);
    } else if (filter === 'week') {
        const dayOfWeek = today.getDay();
        const daysFromMonday = dayOfWeek === 0 ? 6 : dayOfWeek - 1;
        const monday = new Date(today);
        monday.setDate(monday.getDate() - daysFromMonday);
        const sunday = new Date(monday);
        sunday.setDate(monday.getDate() + 6);
        start = formatDateLocal(monday);
        end = formatDateLocal(sunday);
    } else if (filter === 'month') {
        start = formatDateLocal(new Date(today.getFullYear(), today.getMonth(), 1));
        end = formatDateLocal(new Date(today.getFullYear(), today.getMonth() + 1, 0));
    }

    return { start, end };
};

// ONE vocabulary source. This page used to carry its own work-type label map —
// the same stored value then rendered under a different name here, in the Slack
// digest, on the Timeline and in the client picker. A row's category name comes
// straight from the workspace's own work_categories row (eager-loaded server
// side as `work_category`). There is deliberately NO fallback label table: a
// legacy row that predates the backfill renders the legacy work_type accessor.
const categoryLabel = (entry) => {
    if (entry?.work_category?.name) return entry.work_category.name;
    if (!entry?.work_type) return '—';
    return String(entry.work_type).replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());
};

const BILLABLE_OPTIONS = [
    ['all', 'All work'],
    ['yes', 'Billable only'],
    ['no', 'Non-billable only'],
];

export default function WorkHoursList({
    auth,
    workHours,
    flash,
    filter = 'all',
    startDate = null,
    endDate = null,
    selectedFilters = {},
    filterOptions = {},
    preview = false,
}) {
    const user = preview ? DEV_SAMPLE.user : auth?.user;
    const list = preview ? DEV_SAMPLE.workHours : workHours;
    const options = preview ? DEV_SAMPLE.filterOptions : filterOptions;
    const initialSelected = preview ? DEV_SAMPLE.selectedFilters : selectedFilters;

    // Only people who can manage everyone's entries may delete tracker-recorded
    // rows (that tears down the whole session behind them). Preview renders the
    // employee view on purpose, so the lock affordances are visible for QA.
    const canManageAll = preview
        ? false
        : Boolean(user?.is_super_admin || user?.permissions?.includes('work_hours.manage_all'));
    const isRowLocked = (entry) => isLockedEntry(entry) && !canManageAll;

    const [deleteId, setDeleteId] = useState(null);
    const [toast, setToast] = useState(flash?.success || flash?.error || '');
    const [toastType, setToastType] = useState(flash?.success ? 'success' : 'error');
    const [selectedEntries, setSelectedEntries] = useState(new Set());
    const [isExporting, setIsExporting] = useState(false);
    const [searchTerm, setSearchTerm] = useState('');
    const [showFilters, setShowFilters] = useState(false);
    const [dateFilter, setDateFilter] = useState(filter);
    const [customStartDate, setCustomStartDate] = useState(startDate ? new Date(startDate) : null);
    const [customEndDate, setCustomEndDate] = useState(endDate ? new Date(endDate) : null);
    const [selectedWorkCategories, setSelectedWorkCategories] = useState(
        (initialSelected.workCategories || []).map(String)
    );
    const [selectedBillable, setSelectedBillable] = useState(initialSelected.billable || 'all');
    const [selectedTrackers, setSelectedTrackers] = useState(initialSelected.trackers || []);
    const [selectedClients, setSelectedClients] = useState(initialSelected.clients || []);

    useEffect(() => {
        if (flash?.success || flash?.error) {
            setToast(flash.success || flash.error);
            setToastType(flash.success ? 'success' : 'error');
        }
    }, [flash]);

    const currentListUrl = () => (
        typeof window === 'undefined'
            ? route('work-hours.index')
            : `${window.location.pathname}${window.location.search}`
    );

    const handleDelete = (id) => setDeleteId(id);

    const confirmDelete = () => {
        if (!deleteId || preview) return;
        router.delete(route('work-hours.destroy', deleteId), {
            data: { return_to: currentListUrl() },
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                setToast('Work entry deleted successfully');
                setToastType('success');
                setDeleteId(null);
            },
            onError: () => {
                setToast('Failed to delete work entry');
                setToastType('error');
                setDeleteId(null);
            },
        });
    };

    const toggleSelection = (id) => {
        const newSelected = new Set(selectedEntries);
        if (newSelected.has(id)) {
            newSelected.delete(id);
        } else {
            newSelected.add(id);
        }
        setSelectedEntries(newSelected);
    };

    // Locked rows are never selectable, so a bulk delete can't be used to get
    // around the single-row lock (the server skips them either way).
    const selectAllPage = () => {
        const pageIds = (list?.data || []).filter((entry) => !isRowLocked(entry)).map((entry) => entry.id);
        setSelectedEntries(new Set(pageIds));
    };

    const clearSelection = () => setSelectedEntries(new Set());

    // The workspace's own catalogue, archived rows included — history still
    // points at them, so they must stay filterable (the name carries a hint).
    const workCategoryList = options.workCategories || [];
    const workCategoryOptions = workCategoryList.map((category) => ({
        value: String(category.id),
        label: category.is_active === false ? `${category.name} (archived)` : category.name,
    }));
    const categoryNameById = new Map(workCategoryList.map((c) => [String(c.id), c.name]));

    const trackerOptions = (options.trackers || []).map((tracker) => ({
        value: tracker,
        label: tracker,
    }));

    const clientOptions = (options.clients || []).map((client) => ({
        value: client,
        label: client,
    }));

    const buildFilterParams = (overrides = {}) => {
        const nextDateFilter = overrides.dateFilter !== undefined ? overrides.dateFilter : dateFilter;
        const nextStartDate = overrides.customStartDate !== undefined ? overrides.customStartDate : customStartDate;
        const nextEndDate = overrides.customEndDate !== undefined ? overrides.customEndDate : customEndDate;
        const nextCategories = overrides.selectedWorkCategories !== undefined ? overrides.selectedWorkCategories : selectedWorkCategories;
        const nextBillable = overrides.selectedBillable !== undefined ? overrides.selectedBillable : selectedBillable;
        const nextTrackers = overrides.selectedTrackers !== undefined ? overrides.selectedTrackers : selectedTrackers;
        const nextClients = overrides.selectedClients !== undefined ? overrides.selectedClients : selectedClients;
        const params = {
            filter: nextDateFilter,
        };

        if (nextCategories.length) params.workCategories = nextCategories;
        if (nextBillable && nextBillable !== 'all') params.billable = nextBillable;
        if (nextTrackers.length) params.trackers = nextTrackers;
        if (nextClients.length) params.clients = nextClients;

        if (nextDateFilter === 'custom' && nextStartDate && nextEndDate) {
            params.startDate = formatDateLocal(nextStartDate);
            params.endDate = formatDateLocal(nextEndDate);
        } else if (nextDateFilter !== 'all' && nextDateFilter !== 'custom') {
            const range = getDateRange(nextDateFilter);
            params.startDate = range.start;
            params.endDate = range.end;
        }

        return params;
    };

    const applyFilters = (overrides = {}) => {
        if (preview) { clearSelection(); return; }
        router.get(route('work-hours.index'), buildFilterParams(overrides), {
            preserveState: true,
            preserveScroll: true,
        });
        clearSelection();
    };

    const clearFilters = () => {
        setDateFilter('all');
        setCustomStartDate(null);
        setCustomEndDate(null);
        setSelectedWorkCategories([]);
        setSelectedBillable('all');
        setSelectedTrackers([]);
        setSelectedClients([]);
        setSearchTerm('');
        if (!preview) router.get(route('work-hours.index'));
        clearSelection();
    };

    const removeFilterValue = (type, value) => {
        if (type === 'workCategories') {
            const next = selectedWorkCategories.filter((item) => item !== value);
            setSelectedWorkCategories(next);
            applyFilters({ selectedWorkCategories: next });
        } else if (type === 'billable') {
            setSelectedBillable('all');
            applyFilters({ selectedBillable: 'all' });
        } else if (type === 'trackers') {
            const next = selectedTrackers.filter((item) => item !== value);
            setSelectedTrackers(next);
            applyFilters({ selectedTrackers: next });
        } else if (type === 'clients') {
            const next = selectedClients.filter((item) => item !== value);
            setSelectedClients(next);
            applyFilters({ selectedClients: next });
        } else if (type === 'date') {
            setDateFilter('all');
            setCustomStartDate(null);
            setCustomEndDate(null);
            applyFilters({ dateFilter: 'all', customStartDate: null, customEndDate: null });
        }
    };

    const activeFilterChips = [
        ...(dateFilter !== 'all'
            ? [{
                key: 'date',
                label: dateFilter === 'custom' && customStartDate && customEndDate
                    ? `Date: ${formatDateLocal(customStartDate)} to ${formatDateLocal(customEndDate)}`
                    : `Date: ${dateFilter === 'week' ? 'This Week' : dateFilter === 'month' ? 'This Month' : 'Today'}`,
                onRemove: () => removeFilterValue('date'),
            }]
            : []),
        ...selectedWorkCategories.map((value) => ({
            key: `workCategories-${value}`,
            label: `Category: ${categoryNameById.get(String(value)) || value}`,
            onRemove: () => removeFilterValue('workCategories', value),
        })),
        ...(selectedBillable !== 'all'
            ? [{
                key: 'billable',
                label: `Billable: ${selectedBillable === 'yes' ? 'Yes' : 'No'}`,
                onRemove: () => removeFilterValue('billable'),
            }]
            : []),
        ...selectedTrackers.map((value) => ({
            key: `trackers-${value}`,
            label: `Tracker: ${value}`,
            onRemove: () => removeFilterValue('trackers', value),
        })),
        ...selectedClients.map((value) => ({
            key: `clients-${value}`,
            label: `Client: ${value}`,
            onRemove: () => removeFilterValue('clients', value),
        })),
    ];

    const handleBulkDelete = () => {
        if (selectedEntries.size === 0 || preview) return;

        const entryIds = Array.from(selectedEntries);
        router.post(route('work-hours.bulk-delete'), {
            ids: entryIds,
        }, {
            onSuccess: () => {
                setToast(`${entryIds.length} entries deleted successfully`);
                setToastType('success');
                clearSelection();
            },
            onError: () => {
                setToast('Failed to delete entries');
                setToastType('error');
            },
        });
    };

    const exportToCSV = async () => {
        if (preview) return;
        setIsExporting(true);
        try {
            const params = buildFilterParams();
            const query = new URLSearchParams();

            Object.entries(params).forEach(([key, value]) => {
                if (Array.isArray(value)) {
                    value.forEach((item) => query.append(`${key}[]`, item));
                } else if (value !== undefined && value !== null && value !== '') {
                    query.append(key, value);
                }
            });

            const response = await fetch(`${route('work-hours.export-personal')}?${query.toString()}`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error('Failed to fetch export data');
            }

            const result = await response.json();
            const data = result.data || [];
            const csvData = data.map(entry => ({
                'ID': entry.id,
                'Date': entry.date,
                'Client': entry.client?.name || 'No Client',
                'Category': categoryLabel(entry),
                'Billable': entry.is_billable ? 'Yes' : 'No',
                'Tracker': entry.tracker,
                'Source': entry.source === 'tracker' ? 'Auto' : 'Logged',
                'Hours': timeFormat(entry.hours),
                'Description': entry.description || '',
            }));

            exportRowsToCsv(csvData, `work-hours-${new Date().toISOString().split('T')[0]}.csv`);

            setToast('Export successful!');
            setToastType('success');
        } catch (error) {
            setToast('Export failed');
            setToastType('error');
        } finally {
            setIsExporting(false);
        }
    };

    const filteredData = list?.data?.filter(entry => {
        if (searchTerm) {
            const search = searchTerm.toLowerCase();
            return (
                entry.client?.name?.toLowerCase().includes(search) ||
                entry.description?.toLowerCase().includes(search) ||
                categoryLabel(entry).toLowerCase().includes(search) ||
                entry.tracker?.toLowerCase().includes(search)
            );
        }
        return true;
    }) || [];

    const totalHours = filteredData.reduce((sum, entry) => sum + Number(entry.hours || 0), 0);

    // Surface a subtotal for any client logged more than once on the same day
    // (the confusing "same client, three rows" case) — without touching the
    // editable rows below, which stay individually editable/deletable.
    const dailyGroups = (() => {
        const map = new Map();
        for (const e of filteredData) {
            const key = `${e.date}|${e.client?.name || 'No Client'}`;
            if (!map.has(key)) {
                map.set(key, { key, date: e.date, client: e.client?.name || 'No Client', count: 0, total: 0 });
            }
            const g = map.get(key);
            g.count += 1;
            g.total += Number(e.hours || 0);
        }
        return [...map.values()].filter((g) => g.count > 1).sort((a, b) => (a.date < b.date ? 1 : -1));
    })();

    const createHref = preview ? '#' : route('work-hours.create');
    const editHref = (id) => (preview ? '#' : route('work-hours.edit', id));

    const selectableData = filteredData.filter((entry) => !isRowLocked(entry));
    const lockedCount = filteredData.length - selectableData.length;
    const allPageSelected = selectedEntries.size === selectableData.length && selectableData.length > 0;

    return (
        <AuthenticatedLayout
            user={user}
            header={<h2 className="text-xl font-semibold text-body">Work Diary</h2>}
        >
            <Head title="Work Diary" />

            <div className="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8 space-y-6">
                {/* Title row + primary action */}
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <p className="max-w-2xl text-sm text-muted">
                        Review, filter, and manage your personal work entries.
                    </p>
                    <Button
                        as={Link}
                        href={createHref}
                        icon={<Plus className="h-4 w-4" />}
                    >
                        Add Manual Time
                    </Button>
                </div>

                {/* Toolbar */}
                <Card padded>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                variant={showFilters ? 'primary' : 'secondary'}
                                icon={<SlidersHorizontal className="h-4 w-4" />}
                                onClick={() => setShowFilters((current) => !current)}
                            >
                                {showFilters ? 'Hide Filters' : 'Filters'}
                            </Button>

                            <Button
                                variant="secondary"
                                icon={<Download className="h-4 w-4" />}
                                loading={isExporting}
                                onClick={exportToCSV}
                            >
                                {isExporting ? 'Exporting...' : 'Export CSV'}
                            </Button>

                            {selectedEntries.size > 0 && (
                                <>
                                    <Button
                                        variant="danger"
                                        icon={<Trash2 className="h-4 w-4" />}
                                        onClick={handleBulkDelete}
                                    >
                                        Delete ({selectedEntries.size})
                                    </Button>
                                    <Button variant="ghost" onClick={clearSelection}>
                                        Clear Selection
                                    </Button>
                                </>
                            )}
                        </div>

                        {/* Search */}
                        <div className="relative w-full sm:w-72 lg:w-80">
                            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-subtle" />
                            <Input
                                type="text"
                                placeholder="Search entries..."
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                                className="pl-9"
                            />
                        </div>
                    </div>

                    {showFilters && (
                        <div className="mt-4 border-t border-line pt-4 space-y-4">
                            <div>
                                <label className="mb-2 block text-xs font-medium uppercase tracking-wide text-muted">Date Range</label>
                                <div className="flex flex-wrap gap-2">
                                    {[
                                        ['all', 'All Dates'],
                                        ['today', 'Today'],
                                        ['week', 'This Week'],
                                        ['month', 'This Month'],
                                        ['custom', 'Custom'],
                                    ].map(([value, label]) => (
                                        <Button
                                            key={value}
                                            size="sm"
                                            variant={dateFilter === value ? 'primary' : 'secondary'}
                                            onClick={() => {
                                                setDateFilter(value);
                                                if (value !== 'custom') {
                                                    applyFilters({ dateFilter: value });
                                                }
                                            }}
                                        >
                                            {label}
                                        </Button>
                                    ))}
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                                <SearchableMultiSelect
                                    label="Category"
                                    options={workCategoryOptions}
                                    selectedValues={selectedWorkCategories}
                                    onChange={(values) => {
                                        setSelectedWorkCategories(values);
                                        applyFilters({ selectedWorkCategories: values });
                                    }}
                                    placeholder="All categories"
                                />

                                <div>
                                    <label
                                        htmlFor="billable-filter"
                                        className="mb-1.5 block text-sm font-medium text-body"
                                    >
                                        Billable
                                    </label>
                                    <Select
                                        id="billable-filter"
                                        value={selectedBillable}
                                        onChange={(e) => {
                                            setSelectedBillable(e.target.value);
                                            applyFilters({ selectedBillable: e.target.value });
                                        }}
                                    >
                                        {BILLABLE_OPTIONS.map(([value, label]) => (
                                            <option key={value} value={value}>{label}</option>
                                        ))}
                                    </Select>
                                </div>

                                <SearchableMultiSelect
                                    label="Tracker"
                                    options={trackerOptions}
                                    selectedValues={selectedTrackers}
                                    onChange={(values) => {
                                        setSelectedTrackers(values);
                                        applyFilters({ selectedTrackers: values });
                                    }}
                                    placeholder="All trackers"
                                />

                                <SearchableMultiSelect
                                    label="Client"
                                    options={clientOptions}
                                    selectedValues={selectedClients}
                                    onChange={(values) => {
                                        setSelectedClients(values);
                                        applyFilters({ selectedClients: values });
                                    }}
                                    placeholder="All clients"
                                />
                            </div>

                            {dateFilter === 'custom' && (
                                <div className="flex flex-wrap items-end gap-4">
                                    <div>
                                        <label className="mb-1.5 block text-sm font-medium text-body">Start Date</label>
                                        <DatePicker
                                            selected={customStartDate}
                                            onChange={(date) => setCustomStartDate(date)}
                                            maxDate={customEndDate || new Date()}
                                            className="w-full rounded-md border border-line bg-surface-2 px-3 py-2 text-sm text-body focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                                            dateFormat="yyyy-MM-dd"
                                        />
                                    </div>
                                    <div>
                                        <label className="mb-1.5 block text-sm font-medium text-body">End Date</label>
                                        <DatePicker
                                            selected={customEndDate}
                                            onChange={(date) => setCustomEndDate(date)}
                                            minDate={customStartDate}
                                            maxDate={new Date()}
                                            className="w-full rounded-md border border-line bg-surface-2 px-3 py-2 text-sm text-body focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                                            dateFormat="yyyy-MM-dd"
                                        />
                                    </div>
                                    <Button onClick={() => applyFilters()}>Apply Dates</Button>
                                </div>
                            )}
                        </div>
                    )}

                    {/* Bulk select-all-page */}
                    {filteredData.length > 0 && (
                        <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-4">
                            <Button
                                size="sm"
                                variant="secondary"
                                onClick={selectAllPage}
                                disabled={selectableData.length === 0}
                            >
                                Select Page ({selectableData.length})
                            </Button>
                            {lockedCount > 0 && (
                                <span className="inline-flex items-center gap-1.5 text-xs text-subtle" title={LOCK_HINT}>
                                    <Lock className="h-3.5 w-3.5" />
                                    {lockedCount} tracked {lockedCount === 1 ? 'entry is' : 'entries are'} locked and can&apos;t be selected
                                </span>
                            )}
                        </div>
                    )}

                    <ActiveFilterChips chips={activeFilterChips} onClearAll={activeFilterChips.length ? clearFilters : null} />
                </Card>

                {/* Totals by client · day */}
                {dailyGroups.length > 0 && (
                    <Card padded>
                        <div className="mb-2 text-xs font-medium uppercase tracking-wide text-muted">
                            Totals by client · day{' '}
                            <span className="font-normal normal-case text-subtle">— clients you logged more than once on this page</span>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {dailyGroups.map((g) => (
                                <span key={g.key} className="inline-flex items-center gap-2 rounded-lg border border-line bg-surface-2 px-2.5 py-1.5 text-xs">
                                    <span className="text-subtle">{g.date}</span>
                                    <span className="font-medium text-body">{g.client}</span>
                                    <span className="font-bold text-accent">{timeFormat(g.total)}</span>
                                    <Badge tone="neutral" size="sm">×{g.count}</Badge>
                                </span>
                            ))}
                        </div>
                    </Card>
                )}

                {/* Table */}
                <Card padded={false} className="overflow-hidden">
                    {filteredData.length === 0 ? (
                        <EmptyState
                            icon={<FileText className="h-5 w-5" />}
                            title="No work hours found"
                            description="Start tracking your work hours to see them here."
                            action={
                                <Button as={Link} href={createHref} icon={<Plus className="h-4 w-4" />}>
                                    Add Manual Time
                                </Button>
                            }
                        />
                    ) : (
                        <>
                            <Table>
                                <THead>
                                    <Tr className="hover:bg-transparent">
                                        <Th className="w-12">
                                            <Checkbox
                                                checked={allPageSelected}
                                                onChange={selectAllPage}
                                                aria-label="Select all entries on this page"
                                            />
                                        </Th>
                                        <Th>ID</Th>
                                        <Th>Date</Th>
                                        <Th>Client</Th>
                                        <Th>Category</Th>
                                        <Th>Billable</Th>
                                        <Th>Tracker</Th>
                                        <Th>Source</Th>
                                        <Th className="text-right">Hours</Th>
                                        <Th>Description</Th>
                                        <Th className="text-right">Actions</Th>
                                    </Tr>
                                </THead>
                                <TBody>
                                    {filteredData.map((entry) => {
                                        const locked = isRowLocked(entry);
                                        return (
                                        <Tr
                                            key={entry.id}
                                            className={cx(selectedEntries.has(entry.id) && 'bg-accent/5')}
                                        >
                                            <Td>
                                                <Checkbox
                                                    checked={selectedEntries.has(entry.id)}
                                                    onChange={() => toggleSelection(entry.id)}
                                                    disabled={locked}
                                                    title={locked ? LOCK_HINT : undefined}
                                                    aria-label={`Select entry ${entry.id}`}
                                                />
                                            </Td>
                                            <Td className="font-semibold text-accent tabular-nums">{entry.id}</Td>
                                            <Td>
                                                <div className="font-medium text-body">{entry.date}</div>
                                                {entry.time_range && (
                                                    <div className="text-xs text-subtle">{entry.time_range}</div>
                                                )}
                                            </Td>
                                            <Td className="text-muted">{entry.client?.name || 'No Client'}</Td>
                                            <Td>
                                                <Badge tone="accent" size="sm">{categoryLabel(entry)}</Badge>
                                            </Td>
                                            <Td>
                                                {entry.is_billable ? (
                                                    <Badge tone="success" size="sm" title="Counts towards billable hours">
                                                        Billable
                                                    </Badge>
                                                ) : (
                                                    <Badge tone="neutral" size="sm" title="Not billed to a client">
                                                        Non-billable
                                                    </Badge>
                                                )}
                                            </Td>
                                            <Td>
                                                {entry.tracker ? (
                                                    <Badge tone="info" size="sm" className="capitalize">{entry.tracker}</Badge>
                                                ) : (
                                                    <span className="text-subtle">—</span>
                                                )}
                                            </Td>
                                            <Td>
                                                {isLockedEntry(entry) ? (
                                                    <div className="flex flex-wrap items-center gap-1.5">
                                                        <Badge tone="success" size="sm" dot title="Auto-captured from the desktop tracker">
                                                            Auto
                                                        </Badge>
                                                        <Badge tone="neutral" size="sm" title={LOCK_HINT}>
                                                            <Lock className="h-3 w-3" />
                                                            Locked
                                                        </Badge>
                                                    </div>
                                                ) : (
                                                    <Badge tone="warning" size="sm" title="Logged by hand in the web app (Add Manual Time)">
                                                        Logged
                                                    </Badge>
                                                )}
                                            </Td>
                                            <Td className="text-right font-bold text-accent tabular-nums">{timeFormat(entry.hours)}</Td>
                                            <Td className="text-muted">
                                                <div className="max-w-xs truncate" title={entry.description}>
                                                    {entry.description || '-'}
                                                </div>
                                            </Td>
                                            <Td>
                                                <div className="flex justify-end gap-2">
                                                    <Button
                                                        as={Link}
                                                        href={editHref(entry.id)}
                                                        size="sm"
                                                        variant="secondary"
                                                        icon={<Pencil className="h-3.5 w-3.5" />}
                                                        title={isLockedEntry(entry) ? LOCK_HINT : undefined}
                                                    >
                                                        {isLockedEntry(entry) ? 'Edit labels' : 'Edit'}
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="danger"
                                                        icon={locked ? <Lock className="h-3.5 w-3.5" /> : <Trash2 className="h-3.5 w-3.5" />}
                                                        onClick={() => handleDelete(entry.id)}
                                                        disabled={locked}
                                                        title={locked ? LOCK_HINT : undefined}
                                                    >
                                                        Delete
                                                    </Button>
                                                </div>
                                            </Td>
                                        </Tr>
                                        );
                                    })}
                                </TBody>
                                <tfoot className="border-t border-line bg-surface-2/40">
                                    <tr>
                                        <td colSpan="8" className="px-4 py-3 text-right font-semibold text-body">Total:</td>
                                        <td className="px-4 py-3 text-right text-base font-bold text-accent tabular-nums">{timeFormat(totalHours.toFixed(2))}</td>
                                        <td colSpan="2"></td>
                                    </tr>
                                </tfoot>
                            </Table>

                            {/* Pagination */}
                            <div className="border-t border-line p-5">
                                <div className="mb-4 text-sm text-muted">
                                    Showing {list.from || 0} to {list.to || 0} of {list.total || 0} entries
                                </div>
                                <TraditionalPagination
                                    pagination={list}
                                    preserveState={true}
                                    preserveScroll={false}
                                    dark
                                />
                            </div>
                        </>
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
                        onClick={() => setToast('')}
                        className="text-lg leading-none opacity-70 transition hover:opacity-100"
                        aria-label="Dismiss"
                    >
                        &times;
                    </button>
                </div>
            )}

            {/* Delete confirmation */}
            <Modal show={Boolean(deleteId)} onClose={() => setDeleteId(null)} maxWidth="md">
                <div className="p-6">
                    <h2 className="text-lg font-semibold text-body">Confirm Delete</h2>
                    <p className="mt-2 text-sm text-muted">
                        Are you sure you want to delete this work entry? This action cannot be undone.
                    </p>
                    <div className="mt-6 flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setDeleteId(null)}>
                            Cancel
                        </Button>
                        <Button variant="danger" onClick={confirmDelete} icon={<Trash2 className="h-4 w-4" />}>
                            Delete
                        </Button>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
