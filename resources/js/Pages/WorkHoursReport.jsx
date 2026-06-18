import React, { useState, useEffect } from 'react';
import DatePicker from 'react-datepicker';
import 'react-datepicker/dist/react-datepicker.css';
import AuthenticatedLayout from '../Layouts/AuthenticatedLayout';
import { TraditionalPagination } from '../Components/Pagination';
import { Head, Link, router } from '@inertiajs/react';
import { timeFormat } from '../helpers';
import { exportRowsToCsv } from '@/Utils/csvExport';
import SearchableMultiSelect from '../Components/Filters/SearchableMultiSelect';
import ActiveFilterChips from '../Components/Filters/ActiveFilterChips';
import PageHeader from '../Components/Layout/PageHeader';
import PageShell from '../Components/Layout/PageShell';

// Toast Notification Component
function Toast({ message, type = 'success', onClose }) {
    if (!message) return null;
    
    const bgClass = type === 'error' 
        ? 'bg-gradient-to-r from-red-500 to-red-600' 
        : 'bg-gradient-to-r from-emerald-500 to-teal-500';

    return (
        <div className={`fixed top-5 right-5 z-50 ${bgClass} text-white px-6 py-3 rounded-xl shadow-2xl flex items-center`}>
            <span className="font-medium">{message}</span>
            <button onClick={onClose} className="ml-4 text-white hover:text-gray-200 font-bold text-lg">&times;</button>
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

const getDateRange = (filter) => {
    const today = new Date();
    let start, end;
    
    if (filter === 'today') {
        start = end = formatDateLocal(today);
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
        'upwork_bidding': 'Upwork Bidding',
        'fixed': 'Fixed Project',
        'office_work': 'Office Work',
        'outside_of_upwork': 'Outside of Upwork'
    };
    return types[workType] || workType;
};

const slackFieldOptions = [
    { value: 'client', label: 'Client', description: 'Group entries by client.' },
    { value: 'work_type', label: 'Work type', description: 'Show tracker, manual, fixed, and other work types.' },
    { value: 'tracker', label: 'Tracker', description: 'Group entries by tracker or profile name.' },
    { value: 'hours', label: 'Hours', description: 'Include summed hours for each group.' },
    { value: 'user_total', label: 'User total', description: 'Show each person\'s total hours for the selected period.' },
];

export default function WorkHoursReport({ 
    auth, 
    workHours, 
    users = [], 
    flash,
    filter = 'all',
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
}) {
    const canExport = auth.user?.is_super_admin || auth.user?.permissions?.includes('reports.export');
    const canSendSlack = auth.user?.is_super_admin || auth.user?.permissions?.includes('reports.send_slack');
    const [toast, setToast] = useState(flash?.success || flash?.error || '');
    const [toastType, setToastType] = useState(flash?.success ? 'success' : 'error');
    const [dateFilter, setDateFilter] = useState(filter);
    const [customStartDate, setCustomStartDate] = useState(startDate ? new Date(startDate) : null);
    const [customEndDate, setCustomEndDate] = useState(endDate ? new Date(endDate) : null);
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

    const userOptions = (filterOptions.users || users || []).map((user) => ({
        value: String(user.id),
        label: user.name,
        includedByDefault: user.include_in_slack_reports ?? true,
    }));

    const workTypeOptions = (filterOptions.workTypes || []).map((type) => ({
        value: type,
        label: formatWorkType(type),
    }));

    const clientOptions = (filterOptions.clients || []).map((clientName) => ({
        value: clientName,
        label: clientName,
    }));

    const trackerOptions = (filterOptions.trackers || []).map((tracker) => ({
        value: tracker,
        label: tracker,
    }));

    const designationOptions = (filterOptions.designations || []).map((designation) => ({
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
            params.startDate = formatDateLocal(currentStartDate);
            params.endDate = formatDateLocal(currentEndDate);
        } else if (currentDateFilter !== 'all' && currentDateFilter !== 'custom') {
            const range = getDateRange(currentDateFilter);
            params.startDate = range.start;
            params.endDate = range.end;
        }

        return params;
    };

    // Apply filters with optional parameter overrides
    const applyFilters = (overrides = {}) => {
        router.get(route('work-hours.report'), buildFilterParams(overrides), {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const clearFilters = () => {
        setDateFilter('all');
        setCustomStartDate(null);
        setCustomEndDate(null);
        setSelectedUsers([]);
        setSelectedWorkTypes([]);
        setSelectedClients([]);
        setSelectedTrackers([]);
        setSelectedDesignations([]);
        setSearchTerm('');
        
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
            setCustomStartDate(null);
            setCustomEndDate(null);
            applyFilters({ dateFilter: 'all', customStartDate: null, customEndDate: null });
        }
    };

    const labelForValue = (options, value) => options.find((option) => String(option.value) === String(value))?.label || value;

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
            setSlackStartDate(formatDateLocal(customStartDate));
            setSlackEndDate(formatDateLocal(customEndDate));
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
    const filteredData = workHours?.data?.filter(entry => {
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

    return (
        <AuthenticatedLayout user={auth.user}>
            <Head title="Work Hours Report" />
            
            {toast && <Toast message={toast} type={toastType} onClose={() => setToast('')} />}
            
            <PageShell>
                    <PageHeader
                        title="Work Hours Report"
                        description="Review and export team work hours."
                        actions={(
                            <>
                                {canSendSlack && (
                                    <button
                                        type="button"
                                        onClick={openSlackDialog}
                                        className="inline-flex items-center rounded-lg border border-slate-700 bg-slate-900 px-4 py-2.5 text-sm font-semibold text-slate-200 shadow-sm hover:bg-slate-800"
                                    >
                                        <svg className="mr-2 h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                            <path d="M6.5 14.5a2 2 0 11-2-2h2v2zm1 0a2 2 0 114 0v5a2 2 0 11-4 0v-5zm2-8a2 2 0 112-2v2h-2zm0 1a2 2 0 110 4h-5a2 2 0 110-4h5zm8 2a2 2 0 112 2h-2v-2zm-1 0a2 2 0 11-4 0v-5a2 2 0 114 0v5zm-2 8a2 2 0 11-2 2v-2h2zm0-1a2 2 0 110-4h5a2 2 0 110 4h-5z" />
                                        </svg>
                                        Send to Slack
                                    </button>
                                )}
                                {canExport && (
                                    <button
                                        onClick={exportToCSV}
                                        disabled={isExporting}
                                        className="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 disabled:bg-slate-400"
                                    >
                                        <svg className="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                        {isExporting ? 'Exporting...' : 'Export CSV'}
                                    </button>
                                )}
                            </>
                        )}
                    />

                    {/* Filters & Actions Card */}
                    <div className="rounded-lg border border-slate-800 bg-slate-900 p-3 shadow-sm">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div className="flex flex-wrap gap-2">
                                <button
                                    onClick={() => setShowFilters(!showFilters)}
                                    className={`inline-flex items-center rounded-lg px-3 py-2 text-sm font-semibold transition ${showFilters ? 'bg-gradient-to-r from-orange-500 to-amber-500 text-white' : 'bg-white/10 text-slate-300 hover:bg-white/20'}`}
                                >
                                    <svg className="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                                    </svg>
                                    {showFilters ? 'Hide Filters' : 'Show Filters'}
                                </button>

                                {activeFilterChips.length > 0 && (
                                    <button
                                        onClick={clearFilters}
                                        className="inline-flex items-center px-4 py-2 bg-slate-500 hover:bg-slate-600 text-white rounded-xl font-medium transition-all"
                                    >
                                        <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                        Clear Filters
                                    </button>
                                )}
                            </div>

                            {/* Search */}
                            <div className="relative w-full sm:w-72 lg:w-80">
                                <input
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
                                    className="w-full pl-10 pr-4 py-2 border border-slate-700 bg-slate-900 rounded-lg text-slate-200 placeholder-slate-500 focus:ring-2 focus:ring-orange-500 focus:border-orange-500"
                                />
                                <svg className="w-5 h-5 text-slate-400 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </div>
                        </div>

                        {/* Filters Panel */}
                        {showFilters && (
                            <div className="mt-3 space-y-3 border-t border-slate-800 pt-3">
                                {/* Date Range */}
                                <div>
                                    <label className="mb-1.5 block text-xs font-semibold uppercase text-slate-600">Date Range</label>
                                    <div className="flex flex-wrap gap-2">
                                        {['all', 'today', 'week', 'month', 'custom'].map(filter => (
                                            <button
                                                key={filter}
                                                type="button"
                                                onClick={() => {
                                                    setDateFilter(filter);
                                                    if (filter !== 'custom') {
                                                        // Apply filters immediately with the new filter value
                                                        applyFilters({ dateFilter: filter });
                                                    }
                                                }}
                                                className={`rounded-lg px-3 py-2 text-sm font-medium capitalize transition ${
                                                    dateFilter === filter
                                                        ? 'bg-gradient-to-r from-orange-500 to-amber-500 text-white'
                                                        : 'bg-white/10 text-slate-300 hover:bg-white/20'
                                                }`}
                                            >
                                                {filter === 'all' ? 'All Dates' : filter === 'week' ? 'This Week' : filter === 'month' ? 'This Month' : filter}
                                            </button>
                                        ))}
                                    </div>

                                    {dateFilter === 'custom' && (
                                        <div className="flex flex-wrap gap-4 mt-4">
                                            <div>
                                                <label className="block text-sm text-slate-400 mb-1">Start Date</label>
                                                <DatePicker
                                                    selected={customStartDate}
                                                    onChange={(date) => setCustomStartDate(date)}
                                                    maxDate={customEndDate || new Date()}
                                                    className="px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500"
                                                    dateFormat="yyyy-MM-dd"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-sm text-slate-400 mb-1">End Date</label>
                                                <DatePicker
                                                    selected={customEndDate}
                                                    onChange={(date) => setCustomEndDate(date)}
                                                    minDate={customStartDate}
                                                    maxDate={new Date()}
                                                    className="px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500"
                                                    dateFormat="yyyy-MM-dd"
                                                />
                                            </div>
                                            <div className="flex items-end">
                                                <button
                                                    type="button"
                                                    onClick={applyFilters}
                                                    className="px-4 py-2 bg-slate-900 hover:bg-slate-800 text-white rounded-lg font-medium transition-all"
                                                >
                                                    Apply
                                                </button>
                                            </div>
                                        </div>
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

                        <ActiveFilterChips chips={activeFilterChips} onClearAll={activeFilterChips.length ? clearFilters : null} />
                    </div>

                    {/* Data Table */}
                    <div className="rounded-lg border border-slate-800 bg-slate-900 shadow-sm overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-800">
                                <thead className="bg-slate-950">
                                    <tr>
                                        <th className="sticky left-0 z-10 whitespace-nowrap bg-slate-950 px-4 py-3 text-left text-xs font-bold uppercase text-white">Date</th>
                                        <th className="px-4 py-3 text-left text-xs font-bold text-white uppercase">User</th>
                                        <th className="px-4 py-3 text-left text-xs font-bold text-white uppercase">Client</th>
                                        <th className="px-4 py-3 text-left text-xs font-bold text-white uppercase">Work Type</th>
                                        <th className="px-4 py-3 text-left text-xs font-bold text-white uppercase">Tracker</th>
                                        <th className="px-4 py-3 text-left text-xs font-bold text-white uppercase">Source</th>
                                        <th className="px-4 py-3 text-left text-xs font-bold text-white uppercase">Hours</th>
                                        <th className="px-4 py-3 text-left text-xs font-bold text-white uppercase">Description</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-800">
                                    {filteredData.length === 0 ? (
                                        <tr>
                                            <td colSpan="8" className="px-0 py-12 text-center">
                                                <div className="sticky left-0 flex w-[calc(100vw-4rem)] flex-col items-center px-4 sm:w-auto">
                                                    <svg className="w-12 h-12 text-slate-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                                    </svg>
                                                    <h3 className="text-lg font-medium text-white mb-2">No data found</h3>
                                                    <p className="text-slate-400">Try adjusting your filters</p>
                                                </div>
                                            </td>
                                        </tr>
                                    ) : (
                                        filteredData.map((entry, index) => (
                                            <tr key={entry.id} className={`${index % 2 === 0 ? 'bg-slate-900' : 'bg-slate-950/50'} hover:bg-white/5 transition-colors`}>
                                                <td className="sticky left-0 z-[1] whitespace-nowrap bg-inherit px-4 py-3 text-sm font-medium text-slate-100">{entry.date}</td>
                                                <td className="px-4 py-3 text-sm text-slate-100">{entry.user?.name || 'N/A'}</td>
                                                <td className="px-4 py-3 text-sm text-slate-300">{entry.client?.name || 'No Client'}</td>
                                                <td className="px-4 py-3 text-sm">
                                                    <span className="inline-flex px-2 py-1 text-xs font-medium bg-purple-500/15 text-purple-300 rounded-full">
                                                        {formatWorkType(entry.work_type)}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 text-sm">
                                                    {entry.tracker ? (
                                                        <span className="inline-flex px-2 py-1 text-xs font-medium bg-teal-500/15 text-teal-300 rounded-full capitalize">
                                                            {entry.tracker}
                                                        </span>
                                                    ) : (
                                                        <span className="text-slate-500">—</span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-sm">
                                                    {entry.source === 'tracker' ? (
                                                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/15 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-emerald-300" title="Auto-captured from the desktop tracker">
                                                            <span className="inline-block h-1.5 w-1.5 rounded-full bg-emerald-500" />
                                                            Auto
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex items-center gap-1 rounded-full bg-amber-500/20 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-amber-200" title="Logged by hand in the web app (Add Manual Time)">
                                                            Logged
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-sm font-bold text-emerald-400">
                                                    {timeFormat(entry.hours)}
                                                    {entry.entry_count > 1 && (
                                                        <span className="ml-1.5 rounded bg-slate-700 px-1.5 py-0.5 text-[10px] font-semibold text-slate-200" title={`${entry.entry_count} entries merged into this row`}>
                                                            ×{entry.entry_count}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-sm text-slate-300">
                                                    <div className="max-w-xs truncate" title={entry.description}>
                                                        {entry.description || '-'}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                                {filteredData.length > 0 && (
                                    <tfoot className="bg-slate-950">
                                        <tr>
                                            <td colSpan="6" className="px-4 py-3 text-right font-bold text-slate-100">Total:</td>
                                            <td className="px-4 py-3 font-bold text-emerald-400 text-lg">{timeFormat(totalHours.toFixed(2))}</td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                )}
                            </table>
                        </div>

                        {/* Pagination */}
                        {workHours?.data && workHours.data.length > 0 && (
                            <div className="p-6 border-t border-slate-800">
                                <div className="flex justify-between items-center mb-4">
                                    <div className="text-slate-300 text-sm font-medium">
                                        Showing {workHours.from || 0} to {workHours.to || 0} of {workHours.total || 0} entries
                                    </div>
                                </div>
                                <TraditionalPagination
                                    pagination={workHours}
                                    preserveState={true}
                                    preserveScroll={false}
                                    dark
                                />
                            </div>
                        )}
                    </div>
            </PageShell>

            {showSlackDialog && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4">
                    <div className="flex max-h-[90vh] w-full max-w-2xl flex-col rounded-lg border border-slate-200 bg-white shadow-xl">
                        <div className="flex items-start justify-between border-b border-slate-200 px-5 py-4">
                            <div>
                                <h2 className="text-lg font-bold text-slate-950">Send report to Slack</h2>
                                <p className="mt-1 text-sm text-slate-600">Choose the people and details included in this report.</p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setShowSlackDialog(false)}
                                className="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                                title="Close"
                            >
                                <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <div className="space-y-5 overflow-y-auto px-5 py-4">
                            <div className={`rounded-lg border px-3 py-2 text-sm ${
                                slackConfigured
                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                                    : 'border-amber-200 bg-amber-50 text-amber-800'
                            }`}>
                                {slackConfigured ? 'Slack webhook connected.' : 'Slack webhook is not configured.'}
                                {slackWeeklyEnabled && ' Automatic reports run Sundays at 10:00 AM Pakistan time.'}
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label htmlFor="slack-start-date" className="mb-1.5 block text-sm font-semibold text-slate-700">Start date</label>
                                    <input
                                        id="slack-start-date"
                                        type="date"
                                        value={slackStartDate}
                                        max={slackEndDate}
                                        onChange={(event) => setSlackStartDate(event.target.value)}
                                        className="w-full rounded-lg border-slate-300 text-slate-900 focus:border-blue-500 focus:ring-blue-500"
                                    />
                                </div>
                                <div>
                                    <label htmlFor="slack-end-date" className="mb-1.5 block text-sm font-semibold text-slate-700">End date</label>
                                    <input
                                        id="slack-end-date"
                                        type="date"
                                        value={slackEndDate}
                                        min={slackStartDate}
                                        onChange={(event) => setSlackEndDate(event.target.value)}
                                        className="w-full rounded-lg border-slate-300 text-slate-900 focus:border-blue-500 focus:ring-blue-500"
                                    />
                                </div>
                            </div>

                            <SearchableMultiSelect
                                label="Users to include"
                                options={userOptions}
                                selectedValues={slackUserIds}
                                onChange={setSlackUserIds}
                                placeholder="Select users"
                                searchPlaceholder="Search users..."
                            />

                            <fieldset>
                                <legend className="text-sm font-semibold text-slate-800">Data to include</legend>
                                <p className="mt-1 text-sm text-slate-600">
                                    User names are always shown. Select at least one additional field.
                                </p>
                                <div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    {slackFieldOptions.map((field) => {
                                        const checked = slackFields.includes(field.value);

                                        return (
                                            <label
                                                key={field.value}
                                                className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition ${
                                                    checked
                                                        ? 'border-blue-300 bg-blue-50'
                                                        : 'border-slate-200 bg-white hover:border-slate-300'
                                                }`}
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={checked}
                                                    onChange={() => toggleSlackField(field.value)}
                                                    className="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                                />
                                                <span>
                                                    <span className="block text-sm font-semibold text-slate-900">{field.label}</span>
                                                    <span className="mt-0.5 block text-xs leading-5 text-slate-600">{field.description}</span>
                                                </span>
                                            </label>
                                        );
                                    })}
                                </div>
                            </fieldset>

                            <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs leading-5 text-slate-600">
                                Slack will send these choices as table columns. They are independent from the filters on the report page.
                            </p>
                        </div>

                        <div className="flex justify-end gap-2 border-t border-slate-200 px-5 py-4">
                            <button
                                type="button"
                                onClick={() => setShowSlackDialog(false)}
                                className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={sendToSlack}
                                disabled={
                                    !slackConfigured
                                    || isSendingSlack
                                    || !slackStartDate
                                    || !slackEndDate
                                    || slackUserIds.length === 0
                                    || slackFields.length === 0
                                }
                                className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-slate-300"
                            >
                                {isSendingSlack ? 'Sending...' : 'Send report'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
