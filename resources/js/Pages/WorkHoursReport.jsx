import React, { useState, useEffect, useMemo } from 'react';
import AuthenticatedLayout from '../Layouts/AuthenticatedLayout';
import { TraditionalPagination } from '../Components/Pagination';
import { Head, Link, router } from '@inertiajs/react';
import { CalendarDays } from 'lucide-react';
import { timeFormat } from '../helpers';
import { exportRowsToCsv } from '@/Utils/csvExport';
import SearchableMultiSelect from '../Components/Filters/SearchableMultiSelect';
import {
    Badge,
    Button,
    Card,
    CardHeader,
    EmptyState,
    Field,
    Input,
    Select,
    StatCard,
    Table,
    THead,
    TBody,
    Tr,
    Th,
    Td,
} from '@/Components/ui';
import Modal from '@/Components/Modal';
import { TrendChart } from '@/Components/charts';

const cx = (...a) => a.filter(Boolean).join(' ');

// ---------------------------------------------------------------------------
// useColorScheme — mirror the active theme onto native <input type="date">
// controls so their OS picker/glyph render light-on-light or dark-on-dark
// instead of the old hardcoded light DatePicker sitting on a dark surface.
// ---------------------------------------------------------------------------
function useColorScheme() {
    const [scheme, setScheme] = useState(() => (
        typeof document !== 'undefined' && document.documentElement.getAttribute('data-theme') === 'light'
            ? 'light'
            : 'dark'
    ));

    useEffect(() => {
        if (typeof document === 'undefined') return undefined;
        const root = document.documentElement;
        const read = () => setScheme(root.getAttribute('data-theme') === 'light' ? 'light' : 'dark');
        read();
        const observer = new MutationObserver(read);
        observer.observe(root, { attributes: true, attributeFilter: ['data-theme'] });
        return () => observer.disconnect();
    }, []);

    return scheme;
}

// Toast Notification Component (token-driven, theme-aware)
function Toast({ message, type = 'success', onClose }) {
    if (!message) return null;

    const toneClass = type === 'error'
        ? 'bg-danger-soft text-danger'
        : 'bg-success-soft text-success';

    return (
        <div className={cx('fixed top-5 right-5 z-[60] flex items-center gap-4 rounded-xl border border-line px-5 py-3 shadow-lg', toneClass)}>
            <span className="text-sm font-medium">{message}</span>
            <button onClick={onClose} className="text-lg font-bold leading-none opacity-70 transition hover:opacity-100">&times;</button>
        </div>
    );
}

// Helper Functions
const formatDateLocal = (date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
};

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const shortDate = (iso) => {
    if (!iso) return '';
    const parts = String(iso).slice(0, 10).split('-');
    if (parts.length < 3) return String(iso);
    return `${MONTHS[Number(parts[1]) - 1] || parts[1]} ${Number(parts[2])}`;
};

// The report's DEFAULT window. `/report` used to open on all time, whose
// GROUP BY grows for the life of the workspace; it now opens on the last 90
// days. "All dates" stays in the picker, so nothing is unreachable — see
// WorkHourController::DEFAULT_REPORT_FILTER for the server half.
const DEFAULT_DATE_FILTER = 'last90';
const DEFAULT_RANGE_DAYS = 90;

const getDateRange = (filter) => {
    const today = new Date();
    let start, end;

    if (filter === 'today') {
        start = end = formatDateLocal(today);
    } else if (filter === 'last90') {
        const from = new Date(today);
        from.setDate(from.getDate() - (DEFAULT_RANGE_DAYS - 1));
        start = formatDateLocal(from);
        end = formatDateLocal(today);
    } else if (filter === 'week') {
        const dayOfWeek = today.getDay();
        const daysFromMonday = dayOfWeek === 0 ? 6 : dayOfWeek - 1;
        const monday = new Date(today);
        monday.setDate(monday.getDate() - daysFromMonday);
        const sunday = new Date(monday);
        sunday.setDate(sunday.getDate() + 6);
        start = formatDateLocal(monday);
        end = formatDateLocal(sunday);
    } else if (filter === 'month') {
        const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
        const lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0);
        start = formatDateLocal(firstDay);
        end = formatDateLocal(lastDay);
    }

    return { start, end };
};

const formatWorkType = (workType) => {
    const types = {
        'tracker': 'Tracker',
        'manual': 'Manual Time',
        'test_task': 'Test Task',
        'upwork_bidding': 'Business development',
        'fixed': 'Fixed Project',
        'office_work': 'Office Work',
        'outside_of_upwork': 'Other work'
    };
    return types[workType] || workType;
};

const DATE_PRESETS = [
    { value: 'today', label: 'Today' },
    { value: 'week', label: 'This week' },
    { value: 'month', label: 'This month' },
    { value: 'last90', label: 'Last 90 days' },
    { value: 'custom', label: 'Custom range' },
    { value: 'all', label: 'All dates' },
];

const DATE_PRESET_LABEL = (value) => DATE_PRESETS.find((p) => p.value === value)?.label || value;

const slackFieldOptions = [
    { value: 'client', label: 'Client', description: 'Group entries by client.' },
    { value: 'work_type', label: 'Work type', description: 'Show tracker, manual, fixed, and other work types.' },
    { value: 'tracker', label: 'Tracker', description: 'Group entries by tracker or profile name.' },
    { value: 'hours', label: 'Hours', description: 'Include summed hours for each group.' },
    { value: 'user_total', label: 'User total', description: 'Show each person\'s total hours for the selected period.' },
];

// ---------------------------------------------------------------------------
// DEV_SAMPLE — rendered ONLY when `preview` is true. Lets the full Reports page
// render offline (design review / screenshots) with a mock super-admin. None of
// this is ever fetched or shown to a real authenticated user.
// ---------------------------------------------------------------------------
const SAMPLE_USER = {
    id: 1,
    name: 'Jawad',
    is_super_admin: true,
    permissions: ['reports.export', 'reports.send_slack'],
};

const SAMPLE_ROWS = [
    { id: 1, date: '2026-07-15', user: { name: 'Aqsa Khan' }, client: { name: 'Northwind Co.' }, work_type: 'tracker', tracker: 'primary-01', source: 'tracker', hours: 6.75, entry_count: 3, description: 'Dashboard layout · API wiring · QA pass' },
    { id: 2, date: '2026-07-15', user: { name: 'Bilal Ahmed' }, client: { name: 'Acme Studio' }, work_type: 'manual', tracker: null, source: 'manual', hours: 2.5, entry_count: 1, description: 'Client call and notes' },
    { id: 3, date: '2026-07-16', user: { name: 'Aqsa Khan' }, client: { name: 'Acme Studio' }, work_type: 'tracker', tracker: 'primary-01', source: 'tracker', hours: 7.25, entry_count: 4, description: 'Checkout flow refinements' },
    { id: 4, date: '2026-07-16', user: { name: 'Sana Malik' }, client: null, work_type: 'upwork_bidding', tracker: 'bd-team', source: 'manual', hours: 1.75, entry_count: 2, description: 'Proposals and outreach' },
    { id: 5, date: '2026-07-17', user: { name: 'Bilal Ahmed' }, client: { name: 'Northwind Co.' }, work_type: 'tracker', tracker: 'primary-02', source: 'tracker', hours: 5.5, entry_count: 2, description: 'Bug triage' },
    { id: 6, date: '2026-07-17', user: { name: 'Aqsa Khan' }, client: { name: 'Globex' }, work_type: 'fixed', tracker: null, source: 'manual', hours: 3.0, entry_count: 1, description: 'Fixed-scope landing page' },
    { id: 7, date: '2026-07-18', user: { name: 'Sana Malik' }, client: { name: 'Acme Studio' }, work_type: 'tracker', tracker: 'primary-01', source: 'tracker', hours: 8.0, entry_count: 5, description: 'Reporting module build-out' },
    { id: 8, date: '2026-07-18', user: { name: 'Bilal Ahmed' }, client: { name: 'Globex' }, work_type: 'office_work', tracker: null, source: 'manual', hours: 2.0, entry_count: 1, description: 'Standup and planning' },
    { id: 9, date: '2026-07-19', user: { name: 'Aqsa Khan' }, client: { name: 'Northwind Co.' }, work_type: 'tracker', tracker: 'primary-02', source: 'tracker', hours: 6.0, entry_count: 3, description: 'Settings screens' },
    { id: 10, date: '2026-07-20', user: { name: 'Sana Malik' }, client: { name: 'Northwind Co.' }, work_type: 'tracker', tracker: 'primary-01', source: 'tracker', hours: 7.5, entry_count: 4, description: 'Analytics polish and review' },
];

const DEV_SAMPLE = {
    user: SAMPLE_USER,
    workHours: {
        data: SAMPLE_ROWS,
        from: 1,
        to: SAMPLE_ROWS.length,
        total: SAMPLE_ROWS.length,
        links: [],
    },
    filterOptions: {
        workTypes: ['tracker', 'manual', 'test_task', 'upwork_bidding', 'fixed', 'office_work', 'outside_of_upwork'],
        users: [
            { id: 1, name: 'Aqsa Khan', include_in_slack_reports: true },
            { id: 2, name: 'Bilal Ahmed', include_in_slack_reports: true },
            { id: 3, name: 'Sana Malik', include_in_slack_reports: true },
        ],
        clients: ['Acme Studio', 'Globex', 'Northwind Co.'],
        trackers: ['primary-01', 'primary-02', 'bd-team'],
        designations: ['Developer', 'Designer', 'BD'],
    },
};

export default function WorkHoursReport({
    auth,
    workHours,
    users = [],
    flash,
    filter = DEFAULT_DATE_FILTER,
    defaultFilter = DEFAULT_DATE_FILTER,
    hasAnyEntries = true,
    startDate = null,
    endDate = null,
    userId = 'all',
    workType = 'all',
    client = 'all',
    selectedFilters = {},
    filterOptions = {},
    slackConfigured = false,
    slackWeeklyEnabled = false,
    search = '',
    preview = false,
}) {
    // In preview mode synthesize everything so the page renders offline.
    const user = preview ? DEV_SAMPLE.user : auth?.user;
    const effectiveWorkHours = preview ? DEV_SAMPLE.workHours : workHours;
    const effectiveFilterOptions = preview ? DEV_SAMPLE.filterOptions : filterOptions;
    const effectiveSlackConfigured = preview ? true : slackConfigured;
    const effectiveSlackWeekly = preview ? true : slackWeeklyEnabled;

    const colorScheme = useColorScheme();

    const canExport = preview || user?.is_super_admin || user?.permissions?.includes('reports.export');
    const canSendSlack = preview || user?.is_super_admin || user?.permissions?.includes('reports.send_slack');

    const [toast, setToast] = useState(flash?.success || flash?.error || '');
    const [toastType, setToastType] = useState(flash?.success ? 'success' : 'error');
    const [dateFilter, setDateFilter] = useState(filter);
    const [customStartDate, setCustomStartDate] = useState(startDate || '');
    const [customEndDate, setCustomEndDate] = useState(endDate || '');
    const [selectedUsers, setSelectedUsers] = useState(selectedFilters.userIds || (userId !== 'all' ? [String(userId)] : []));
    const [selectedWorkTypes, setSelectedWorkTypes] = useState(selectedFilters.workTypes || (workType !== 'all' ? [workType] : []));
    const [selectedClients, setSelectedClients] = useState(selectedFilters.clients || (client !== 'all' ? [client] : []));
    const [selectedTrackers, setSelectedTrackers] = useState(selectedFilters.trackers || []);
    const [selectedDesignations, setSelectedDesignations] = useState(selectedFilters.designations || []);
    const [searchTerm, setSearchTerm] = useState(search);
    const [isExporting, setIsExporting] = useState(false);
    const [showFilters, setShowFilters] = useState(false);
    const [showSlackDialog, setShowSlackDialog] = useState(false);
    const [isSendingSlack, setIsSendingSlack] = useState(false);
    const [slackUserIds, setSlackUserIds] = useState([]);
    const [slackFields, setSlackFields] = useState(slackFieldOptions.map((field) => field.value));
    const [slackStartDate, setSlackStartDate] = useState(() => {
        const date = new Date();
        date.setDate(date.getDate() - 6);
        return startDate || formatDateLocal(date);
    });
    const [slackEndDate, setSlackEndDate] = useState(endDate || formatDateLocal(new Date()));

    useEffect(() => {
        if (flash?.success || flash?.error) {
            setToast(flash.success || flash.error);
            setToastType(flash.success ? 'success' : 'error');
        }
    }, [flash]);

    const userOptions = (effectiveFilterOptions.users || users || []).map((u) => ({
        value: String(u.id),
        label: u.name,
        includedByDefault: u.include_in_slack_reports ?? true,
    }));

    const workTypeOptions = (effectiveFilterOptions.workTypes || []).map((type) => ({
        value: type,
        label: formatWorkType(type),
    }));

    const clientOptions = (effectiveFilterOptions.clients || []).map((clientName) => ({
        value: clientName,
        label: clientName,
    }));

    const trackerOptions = (effectiveFilterOptions.trackers || []).map((tracker) => ({
        value: tracker,
        label: tracker,
    }));

    const designationOptions = (effectiveFilterOptions.designations || []).map((designation) => ({
        value: designation,
        label: designation,
    }));

    const buildFilterParams = (overrides = {}) => {
        const currentDateFilter = overrides.dateFilter !== undefined ? overrides.dateFilter : dateFilter;
        const currentStartDate = overrides.customStartDate !== undefined ? overrides.customStartDate : customStartDate;
        const currentEndDate = overrides.customEndDate !== undefined ? overrides.customEndDate : customEndDate;
        const currentUsers = overrides.selectedUsers !== undefined ? overrides.selectedUsers : selectedUsers;
        const currentWorkTypes = overrides.selectedWorkTypes !== undefined ? overrides.selectedWorkTypes : selectedWorkTypes;
        const currentClients = overrides.selectedClients !== undefined ? overrides.selectedClients : selectedClients;
        const currentTrackers = overrides.selectedTrackers !== undefined ? overrides.selectedTrackers : selectedTrackers;
        const currentDesignations = overrides.selectedDesignations !== undefined ? overrides.selectedDesignations : selectedDesignations;
        const currentSearch = overrides.searchTerm !== undefined ? overrides.searchTerm : searchTerm;

        const params = {
            filter: currentDateFilter,
        };

        if (currentSearch && currentSearch.trim() !== '') params.search = currentSearch.trim();

        if (currentUsers.length) params.userIds = currentUsers;
        if (currentWorkTypes.length) params.workTypes = currentWorkTypes;
        if (currentClients.length) params.clients = currentClients;
        if (currentTrackers.length) params.trackers = currentTrackers;
        if (currentDesignations.length) params.designations = currentDesignations;

        if (currentDateFilter === 'custom' && currentStartDate && currentEndDate) {
            params.startDate = currentStartDate;
            params.endDate = currentEndDate;
        } else if (currentDateFilter !== 'all' && currentDateFilter !== 'custom') {
            const range = getDateRange(currentDateFilter);
            params.startDate = range.start;
            params.endDate = range.end;
        }

        return params;
    };

    // Apply filters with optional parameter overrides
    const applyFilters = (overrides = {}) => {
        if (preview) return;
        router.get(route('work-hours.report'), buildFilterParams(overrides), {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const clearFilters = () => {
        // Back to the page's default view, which is the 90-day window — not
        // all time. A bare GET is what the server treats as "no opinion", so
        // the two halves agree without passing anything.
        setDateFilter(defaultFilter);
        setCustomStartDate('');
        setCustomEndDate('');
        setSelectedUsers([]);
        setSelectedWorkTypes([]);
        setSelectedClients([]);
        setSelectedTrackers([]);
        setSelectedDesignations([]);
        setSearchTerm('');

        if (preview) return;
        router.get(route('work-hours.report'));
    };

    const removeFilterValue = (type, value) => {
        if (type === 'users') {
            const next = selectedUsers.filter((item) => item !== value);
            setSelectedUsers(next);
            applyFilters({ selectedUsers: next });
        } else if (type === 'workTypes') {
            const next = selectedWorkTypes.filter((item) => item !== value);
            setSelectedWorkTypes(next);
            applyFilters({ selectedWorkTypes: next });
        } else if (type === 'clients') {
            const next = selectedClients.filter((item) => item !== value);
            setSelectedClients(next);
            applyFilters({ selectedClients: next });
        } else if (type === 'trackers') {
            const next = selectedTrackers.filter((item) => item !== value);
            setSelectedTrackers(next);
            applyFilters({ selectedTrackers: next });
        } else if (type === 'designations') {
            const next = selectedDesignations.filter((item) => item !== value);
            setSelectedDesignations(next);
            applyFilters({ selectedDesignations: next });
        } else if (type === 'date') {
            setDateFilter('all');
            setCustomStartDate('');
            setCustomEndDate('');
            applyFilters({ dateFilter: 'all', customStartDate: '', customEndDate: '' });
        }
    };

    const labelForValue = (options, value) => options.find((option) => String(option.value) === String(value))?.label || value;

    const activeFilterChips = [
        ...(dateFilter !== 'all'
            ? [{
                key: 'date',
                label: dateFilter === 'custom' && customStartDate && customEndDate
                    ? `Date: ${customStartDate} to ${customEndDate}`
                    : `Date: ${DATE_PRESET_LABEL(dateFilter)}`,
                // Removing the date chip means "show me everything", so it
                // clears to All dates rather than back to the 90-day default —
                // otherwise the all-time view would be unreachable from here.
                onRemove: () => removeFilterValue('date'),
            }]
            : []),
        ...selectedUsers.map((value) => ({
            key: `users-${value}`,
            label: `User: ${labelForValue(userOptions, value)}`,
            onRemove: () => removeFilterValue('users', value),
        })),
        ...selectedWorkTypes.map((value) => ({
            key: `workTypes-${value}`,
            label: `Type: ${formatWorkType(value)}`,
            onRemove: () => removeFilterValue('workTypes', value),
        })),
        ...selectedClients.map((value) => ({
            key: `clients-${value}`,
            label: `Client: ${value}`,
            onRemove: () => removeFilterValue('clients', value),
        })),
        ...selectedTrackers.map((value) => ({
            key: `trackers-${value}`,
            label: `Tracker: ${value}`,
            onRemove: () => removeFilterValue('trackers', value),
        })),
        ...selectedDesignations.map((value) => ({
            key: `designations-${value}`,
            label: `Designation: ${value}`,
            onRemove: () => removeFilterValue('designations', value),
        })),
    ];

    const exportToCSV = async () => {
        setIsExporting(true);
        setToast('Preparing export data...');
        setToastType('success');

        if (preview) {
            const exportData = SAMPLE_ROWS.map((entry) => ({
                'Date': entry.date,
                'User': entry.user?.name || 'N/A',
                'Client': entry.client?.name || 'No Client',
                'Work Type': formatWorkType(entry.work_type),
                'Tracker': entry.tracker,
                'Source': entry.source === 'tracker' ? 'Auto' : 'Logged',
                'Hours': timeFormat(entry.hours),
                'Description': entry.description || '',
            }));
            exportRowsToCsv(exportData, `work-hours-report-${new Date().toISOString().split('T')[0]}.csv`);
            setToast(`Successfully exported ${exportData.length} entries to CSV`);
            setToastType('success');
            setIsExporting(false);
            return;
        }

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

            const queryString = query.toString();
            const url = queryString
                ? `${route('work-hours.export')}?${queryString}`
                : route('work-hours.export');

            // Fetch all data from backend
            const response = await fetch(url, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                throw new Error('Failed to fetch export data');
            }

            const result = await response.json();
            const allData = result.data;

            // Apply client-side search filter if needed
            const exportData = allData
                .filter(entry => {
                    if (!searchTerm) return true;
                    const search = searchTerm.toLowerCase();
                    return (
                        entry.user?.name?.toLowerCase().includes(search) ||
                        entry.client?.name?.toLowerCase().includes(search) ||
                        entry.description?.toLowerCase().includes(search) ||
                        entry.tracker?.toLowerCase().includes(search)
                    );
                })
                .map(entry => ({
                    'Date': entry.date,
                    'User': entry.user?.name || 'N/A',
                    'Client': entry.client?.name || 'No Client',
                    'Work Type': formatWorkType(entry.work_type),
                    'Tracker': entry.tracker,
                    'Source': entry.source === 'tracker' ? 'Auto' : 'Logged',
                    'Hours': timeFormat(entry.hours),
                    'Description': entry.description || ''
                }));

            exportRowsToCsv(exportData, `work-hours-report-${new Date().toISOString().split('T')[0]}.csv`);

            setToast(`Successfully exported ${exportData.length} entries to CSV`);
            setToastType('success');
        } catch (error) {
            console.error('Export error:', error);
            setToast('Export failed. Please try again.');
            setToastType('error');
        } finally {
            setIsExporting(false);
        }
    };

    const openSlackDialog = () => {
        if (dateFilter === 'custom' && customStartDate && customEndDate) {
            setSlackStartDate(customStartDate);
            setSlackEndDate(customEndDate);
        } else if (dateFilter !== 'all' && dateFilter !== 'custom') {
            const range = getDateRange(dateFilter);
            setSlackStartDate(range.start);
            setSlackEndDate(range.end);
        }

        setSlackUserIds(
            selectedUsers.length
                ? selectedUsers
                : userOptions.filter((option) => option.includedByDefault).map((option) => option.value)
        );
        setShowSlackDialog(true);
    };

    const toggleSlackField = (field) => {
        setSlackFields((current) => (
            current.includes(field)
                ? current.filter((item) => item !== field)
                : [...current, field]
        ));
    };

    const sendToSlack = () => {
        if (preview) {
            setShowSlackDialog(false);
            setToast('Report sent to Slack.');
            setToastType('success');
            return;
        }

        setIsSendingSlack(true);

        router.post(route('work-hours.slack'), {
            start_date: slackStartDate,
            end_date: slackEndDate,
            user_ids: slackUserIds,
            include_fields: slackFields,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setShowSlackDialog(false);
                setToast('Report sent to Slack.');
                setToastType('success');
            },
            onError: (errors) => {
                setToast(
                    errors.slack
                    || errors.end_date
                    || errors.user_ids
                    || errors.include_fields
                    || 'Unable to send the Slack report.'
                );
                setToastType('error');
            },
            onFinish: () => setIsSendingSlack(false),
        });
    };

    // Filter data based on search
    const filteredData = effectiveWorkHours?.data?.filter(entry => {
        if (!searchTerm) return true;
        const search = searchTerm.toLowerCase();
        return (
            entry.user?.name?.toLowerCase().includes(search) ||
            entry.client?.name?.toLowerCase().includes(search) ||
            entry.description?.toLowerCase().includes(search) ||
            entry.tracker?.toLowerCase().includes(search)
        );
    }) || [];

    // Calculate statistics
    const totalHours = filteredData.reduce((sum, entry) => sum + Number(entry.hours || 0), 0);
    const totalEntries = filteredData.length;
    const uniqueUsers = new Set(filteredData.map(entry => entry.user?.name).filter(Boolean)).size;
    const uniqueClientsCount = new Set(filteredData.map(entry => entry.client?.name).filter(Boolean)).size;

    // Trend series — total hours per day across the rows currently shown. Derived
    // straight from the report data; when nothing is derivable, TrendChart falls
    // back to its own empty state (we never synthesize points).
    const trendSeries = useMemo(() => {
        const byDate = new Map();
        filteredData.forEach((entry) => {
            const day = String(entry.date || '').slice(0, 10);
            if (!day) return;
            byDate.set(day, (byDate.get(day) || 0) + Number(entry.hours || 0));
        });
        return Array.from(byDate.entries())
            .sort((a, b) => (a[0] < b[0] ? -1 : a[0] > b[0] ? 1 : 0))
            .map(([day, hours]) => ({ label: shortDate(day), value: Number(hours.toFixed(2)) }));
    }, [filteredData]);

    const trendAvg = trendSeries.length > 1
        ? Number((trendSeries.reduce((s, p) => s + p.value, 0) / trendSeries.length).toFixed(1))
        : undefined;

    return (
        <AuthenticatedLayout user={user} header="Work Hours Report">
            <Head title="Work Hours Report" />

            {toast && <Toast message={toast} type={toastType} onClose={() => setToast('')} />}

            <div className="mx-auto max-w-[1600px] space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                {/* Toolbar — one primary action (Export), the rest secondary. */}
                <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <p className="text-sm text-muted">Review, chart, and export team work hours.</p>

                    <div className="flex flex-wrap items-center gap-2">
                        {canSendSlack && (
                            <Button
                                variant="secondary"
                                onClick={openSlackDialog}
                                icon={(
                                    <svg className="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                        <path d="M6.5 14.5a2 2 0 11-2-2h2v2zm1 0a2 2 0 114 0v5a2 2 0 11-4 0v-5zm2-8a2 2 0 112-2v2h-2zm0 1a2 2 0 110 4h-5a2 2 0 110-4h5zm8 2a2 2 0 112 2h-2v-2zm-1 0a2 2 0 11-4 0v-5a2 2 0 114 0v5zm-2 8a2 2 0 11-2 2v-2h2zm0-1a2 2 0 110-4h5a2 2 0 110 4h-5z" />
                                    </svg>
                                )}
                            >
                                Send to Slack
                            </Button>
                        )}
                        {canExport && (
                            <Button
                                onClick={exportToCSV}
                                loading={isExporting}
                                icon={(
                                    <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                )}
                            >
                                {isExporting ? 'Exporting...' : 'Export CSV'}
                            </Button>
                        )}
                    </div>
                </div>

                {/* Stats */}
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <StatCard label="Total hours" value={timeFormat(totalHours.toFixed(2))} hint="Across the rows shown" />
                    <StatCard label="Entries" value={totalEntries} hint="Grouped rows on this page" />
                    <StatCard label="People" value={uniqueUsers} hint="Distinct team members" />
                    <StatCard label="Clients" value={uniqueClientsCount} hint="Distinct clients" />
                </div>

                {/* Trend chart */}
                <Card
                    header={(
                        <CardHeader
                            title="Hours trend"
                            description="Total hours per day across the entries shown below."
                        />
                    )}
                >
                    <TrendChart data={trendSeries} avg={trendAvg} unit="h" height={240} />
                </Card>

                {/* Filters & Actions Card */}
                <Card padded={false}>
                    <div className="p-4">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex flex-wrap items-center gap-2">
                                <Button
                                    variant={showFilters ? 'primary' : 'secondary'}
                                    size="sm"
                                    onClick={() => setShowFilters(!showFilters)}
                                    icon={(
                                        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                                        </svg>
                                    )}
                                >
                                    {showFilters ? 'Hide filters' : 'Show filters'}
                                </Button>

                                {activeFilterChips.length > 0 && (
                                    <Button variant="ghost" size="sm" onClick={clearFilters}>
                                        Clear filters
                                    </Button>
                                )}
                            </div>

                            {/* Search */}
                            <div className="relative w-full sm:w-72 lg:w-80">
                                <Input
                                    type="text"
                                    placeholder="Search report..."
                                    value={searchTerm}
                                    onChange={(e) => {
                                        const value = e.target.value;
                                        setSearchTerm(value);
                                        // Debounced server-side search so matches on
                                        // other pages are found, not just this page.
                                        clearTimeout(window.reportSearchTimeout);
                                        window.reportSearchTimeout = setTimeout(() => {
                                            applyFilters({ searchTerm: value });
                                        }, 400);
                                    }}
                                    className="pl-10"
                                />
                                <svg className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </div>
                        </div>

                        {/* Filters Panel */}
                        {showFilters && (
                            <div className="mt-4 space-y-4 border-t border-line pt-4">
                                {/* Date Range */}
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                    <Field label="Date range">
                                        <Select
                                            value={dateFilter}
                                            onChange={(e) => {
                                                const value = e.target.value;
                                                setDateFilter(value);
                                                if (value !== 'custom') {
                                                    applyFilters({ dateFilter: value });
                                                }
                                            }}
                                        >
                                            {DATE_PRESETS.map((preset) => (
                                                <option key={preset.value} value={preset.value}>{preset.label}</option>
                                            ))}
                                        </Select>
                                    </Field>

                                    {dateFilter === 'custom' && (
                                        <>
                                            <Field label="Start date">
                                                <Input
                                                    type="date"
                                                    value={customStartDate}
                                                    max={customEndDate || undefined}
                                                    onChange={(e) => setCustomStartDate(e.target.value)}
                                                    style={{ colorScheme }}
                                                />
                                            </Field>
                                            <Field label="End date">
                                                <Input
                                                    type="date"
                                                    value={customEndDate}
                                                    min={customStartDate || undefined}
                                                    onChange={(e) => setCustomEndDate(e.target.value)}
                                                    style={{ colorScheme }}
                                                />
                                            </Field>
                                            <div className="flex items-end">
                                                <Button
                                                    variant="secondary"
                                                    onClick={() => applyFilters()}
                                                    disabled={!customStartDate || !customEndDate}
                                                    block
                                                >
                                                    Apply range
                                                </Button>
                                            </div>
                                        </>
                                    )}
                                </div>

                                {/* Other Filters */}
                                <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
                                    {userOptions.length > 0 && (
                                        <SearchableMultiSelect
                                            label="Users"
                                            options={userOptions}
                                            selectedValues={selectedUsers}
                                            onChange={(values) => {
                                                setSelectedUsers(values);
                                                applyFilters({ selectedUsers: values });
                                            }}
                                            placeholder="All users"
                                        />
                                    )}

                                    <SearchableMultiSelect
                                        label="Work Type"
                                        options={workTypeOptions}
                                        selectedValues={selectedWorkTypes}
                                        onChange={(values) => {
                                            setSelectedWorkTypes(values);
                                            applyFilters({ selectedWorkTypes: values });
                                        }}
                                        placeholder="All types"
                                    />

                                    <SearchableMultiSelect
                                        label="Clients"
                                        options={clientOptions}
                                        selectedValues={selectedClients}
                                        onChange={(values) => {
                                            setSelectedClients(values);
                                            applyFilters({ selectedClients: values });
                                        }}
                                        placeholder="All clients"
                                    />

                                    <SearchableMultiSelect
                                        label="Trackers"
                                        options={trackerOptions}
                                        selectedValues={selectedTrackers}
                                        onChange={(values) => {
                                            setSelectedTrackers(values);
                                            applyFilters({ selectedTrackers: values });
                                        }}
                                        placeholder="All trackers"
                                    />

                                    <SearchableMultiSelect
                                        label="Designation"
                                        options={designationOptions}
                                        selectedValues={selectedDesignations}
                                        onChange={(values) => {
                                            setSelectedDesignations(values);
                                            applyFilters({ selectedDesignations: values });
                                        }}
                                        placeholder="All designations"
                                    />
                                </div>
                            </div>
                        )}

                        {/* Active filter chips — token-driven, theme-aware */}
                        {activeFilterChips.length > 0 && (
                            <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-4">
                                {activeFilterChips.map((chip) => (
                                    <button
                                        key={chip.key}
                                        type="button"
                                        onClick={chip.onRemove}
                                        title={`Remove ${chip.label}`}
                                        className="inline-flex items-center gap-1.5 rounded-md border border-line bg-surface-2 px-2.5 py-1 text-xs font-medium text-body transition hover:border-accent hover:text-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app"
                                    >
                                        <span>{chip.label}</span>
                                        <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" />
                                        </svg>
                                    </button>
                                ))}
                                <button
                                    type="button"
                                    onClick={clearFilters}
                                    className="rounded-md px-2.5 py-1 text-xs font-semibold text-accent transition hover:bg-accent/10"
                                >
                                    Clear all
                                </button>
                            </div>
                        )}
                    </div>
                </Card>

                {/* Data Table */}
                <Card padded={false} className="overflow-hidden">
                    {filteredData.length === 0 ? (
                        // TWO DIFFERENT EMPTIES, and they must not be worded the
                        // same. A workspace that has never logged an hour is at
                        // a BEGINNING, and telling it to "adjust your filters"
                        // reads as though it did something wrong. Which case
                        // this is comes from the server (`hasAnyEntries`), not
                        // from the page of rows in front of us.
                        !hasAnyEntries && !preview ? (
                            <EmptyState
                                icon={<CalendarDays className="h-6 w-6" />}
                                title="No time has been logged yet"
                                description="This report fills in as your team tracks. Once someone clocks in with the desktop app — or adds time to their work diary — every hour shows up here, ready to filter, total and export."
                                action={(
                                    <Link
                                        href={route('work-hours.create')}
                                        className="inline-flex items-center gap-1.5 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90"
                                    >
                                        Add time manually
                                    </Link>
                                )}
                            />
                        ) : (
                            <EmptyState
                                title="Nothing in this view"
                                description={
                                    dateFilter === 'all'
                                        ? 'No entries match these filters. Clear one and try again.'
                                        : `No entries match these filters between ${customStartDate || getDateRange(dateFilter).start || '—'} and ${customEndDate || getDateRange(dateFilter).end || '—'}. Widen the date range or clear a filter.`
                                }
                                icon={(
                                    <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={1.75}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                    </svg>
                                )}
                                action={(
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setDateFilter('all');
                                            setCustomStartDate('');
                                            setCustomEndDate('');
                                            applyFilters({ dateFilter: 'all', customStartDate: '', customEndDate: '' });
                                        }}
                                        className="inline-flex items-center gap-1.5 rounded-lg border border-line px-4 py-2 text-sm font-semibold text-body transition hover:bg-surface-2"
                                    >
                                        Search all dates
                                    </button>
                                )}
                            />
                        )
                    ) : (
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>Date</Th>
                                    <Th>User</Th>
                                    <Th>Client</Th>
                                    <Th>Work Type</Th>
                                    <Th>Tracker</Th>
                                    <Th>Source</Th>
                                    <Th className="text-right">Hours</Th>
                                    <Th>Description</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {filteredData.map((entry) => (
                                    <Tr key={entry.id}>
                                        <Td className="whitespace-nowrap font-medium text-body">{entry.date}</Td>
                                        <Td className="whitespace-nowrap">{entry.user?.name || 'N/A'}</Td>
                                        <Td className="text-muted">{entry.client?.name || 'No Client'}</Td>
                                        <Td>
                                            <Badge tone="info">{formatWorkType(entry.work_type)}</Badge>
                                        </Td>
                                        <Td>
                                            {entry.tracker ? (
                                                <Badge tone="accent" className="capitalize">{entry.tracker}</Badge>
                                            ) : (
                                                <span className="text-subtle">—</span>
                                            )}
                                        </Td>
                                        <Td>
                                            {entry.source === 'tracker' ? (
                                                <Badge tone="success" dot title="Auto-captured from the desktop tracker">Auto</Badge>
                                            ) : (
                                                <Badge tone="warning" title="Logged by hand in the web app (Add Manual Time)">Logged</Badge>
                                            )}
                                        </Td>
                                        <Td className="text-right">
                                            <span className="font-semibold tabular-nums text-success">{timeFormat(entry.hours)}</span>
                                            {entry.entry_count > 1 && (
                                                <Badge tone="neutral" className="ml-1.5" title={`${entry.entry_count} entries merged into this row`}>
                                                    ×{entry.entry_count}
                                                </Badge>
                                            )}
                                        </Td>
                                        <Td className="text-muted">
                                            <div className="max-w-xs truncate" title={entry.description}>
                                                {entry.description || '-'}
                                            </div>
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                            <tfoot className="border-t border-line bg-surface-2/40">
                                <tr>
                                    <td colSpan="6" className="px-4 py-3 text-right font-semibold text-body">Total</td>
                                    <td className="px-4 py-3 text-right text-base font-bold tabular-nums text-success">{timeFormat(totalHours.toFixed(2))}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </Table>
                    )}

                    {/* Pagination */}
                    {effectiveWorkHours?.data && effectiveWorkHours.data.length > 0 && (
                        <div className="border-t border-line p-4">
                            <TraditionalPagination
                                pagination={effectiveWorkHours}
                                preserveState={true}
                                preserveScroll={false}
                                dark
                            />
                        </div>
                    )}
                </Card>
            </div>

            {/* Slack modal — rebuilt on the kit Modal, token-driven, theme-aware */}
            <Modal show={showSlackDialog} onClose={() => setShowSlackDialog(false)} maxWidth="2xl">
                <div className="flex max-h-[85vh] flex-col">
                    <div className="flex items-start justify-between gap-4 border-b border-line px-5 py-4">
                        <div>
                            <h2 className="text-lg font-semibold text-body">Send report to Slack</h2>
                            <p className="mt-1 text-sm text-muted">Choose the people and details included in this report.</p>
                        </div>
                        <button
                            type="button"
                            onClick={() => setShowSlackDialog(false)}
                            className="rounded-lg p-2 text-muted transition hover:bg-surface-2 hover:text-body"
                            title="Close"
                        >
                            <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div className="space-y-5 overflow-y-auto px-5 py-4">
                        <div className={cx(
                            'rounded-lg px-3 py-2 text-sm',
                            effectiveSlackConfigured ? 'bg-success-soft text-success' : 'bg-warning-soft text-warning'
                        )}>
                            {effectiveSlackConfigured ? 'Slack webhook connected.' : 'Slack webhook is not configured.'}
                            {effectiveSlackWeekly && ' Automatic reports run Sundays at 10:00 AM Pakistan time.'}
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="Start date" htmlFor="slack-start-date">
                                <Input
                                    id="slack-start-date"
                                    type="date"
                                    value={slackStartDate}
                                    max={slackEndDate}
                                    onChange={(event) => setSlackStartDate(event.target.value)}
                                    style={{ colorScheme }}
                                />
                            </Field>
                            <Field label="End date" htmlFor="slack-end-date">
                                <Input
                                    id="slack-end-date"
                                    type="date"
                                    value={slackEndDate}
                                    min={slackStartDate}
                                    onChange={(event) => setSlackEndDate(event.target.value)}
                                    style={{ colorScheme }}
                                />
                            </Field>
                        </div>

                        <SearchableMultiSelect
                            label="Users to include"
                            options={userOptions}
                            selectedValues={slackUserIds}
                            onChange={setSlackUserIds}
                            placeholder="Select users"
                            searchPlaceholder="Search users..."
                            inline
                        />

                        <fieldset>
                            <legend className="text-sm font-medium text-body">Data to include</legend>
                            <p className="mt-1 text-sm text-muted">
                                User names are always shown. Select at least one additional field.
                            </p>
                            <div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                {slackFieldOptions.map((field) => {
                                    const checked = slackFields.includes(field.value);

                                    return (
                                        <label
                                            key={field.value}
                                            className={cx(
                                                'flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition',
                                                checked
                                                    ? 'border-accent bg-accent/10'
                                                    : 'border-line bg-surface-2 hover:border-accent/50'
                                            )}
                                        >
                                            <input
                                                type="checkbox"
                                                checked={checked}
                                                onChange={() => toggleSlackField(field.value)}
                                                className="mt-0.5 h-4 w-4 rounded border-line bg-surface-2 text-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app"
                                            />
                                            <span>
                                                <span className="block text-sm font-medium text-body">{field.label}</span>
                                                <span className="mt-0.5 block text-xs leading-5 text-muted">{field.description}</span>
                                            </span>
                                        </label>
                                    );
                                })}
                            </div>
                        </fieldset>

                        <p className="rounded-lg bg-surface-2 px-3 py-2 text-xs leading-5 text-muted">
                            Slack will send these choices as table columns. They are independent from the filters on the report page.
                        </p>
                    </div>

                    <div className="flex justify-end gap-2 border-t border-line px-5 py-4">
                        <Button variant="secondary" onClick={() => setShowSlackDialog(false)}>
                            Cancel
                        </Button>
                        <Button
                            onClick={sendToSlack}
                            loading={isSendingSlack}
                            disabled={
                                !effectiveSlackConfigured
                                || isSendingSlack
                                || !slackStartDate
                                || !slackEndDate
                                || slackUserIds.length === 0
                                || slackFields.length === 0
                            }
                        >
                            {isSendingSlack ? 'Sending...' : 'Send report'}
                        </Button>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
