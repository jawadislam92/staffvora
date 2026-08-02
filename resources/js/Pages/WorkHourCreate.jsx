import React, { useState, useRef, useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link, usePage, router } from '@inertiajs/react';
import { Button, Card, Badge, Field, Input, Select, Textarea, Toggle } from '@/Components/ui';
import { ArrowLeft, Check, Clock, Building2, User as UserIcon, AlertCircle, CalendarClock, Info } from 'lucide-react';

const cx = (...a) => a.filter(Boolean).join(' ');

// ---------------------------------------------------------------------------
// useColorScheme — mirror the active theme onto native <input type="date"/time>
// controls so their OS picker/glyph render light-on-light or dark-on-dark.
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

const to12 = (hhmm) => {
    if (!hhmm) return '';
    const [h, m] = hhmm.split(':').map(Number);
    const ap = h < 12 ? 'AM' : 'PM';
    const hr = h % 12 === 0 ? 12 : h % 12;
    return `${hr}:${String(m).padStart(2, '0')} ${ap}`;
};

const fmtMins = (mins) => {
    const m = Math.max(0, Math.round(mins || 0));
    return m >= 60 ? `${Math.floor(m / 60)}h ${String(m % 60).padStart(2, '0')}m` : `${m}m`;
};

// ---------------------------------------------------------------------------
// DEV_SAMPLE — used ONLY when `preview` is true so the form renders fully
// offline for visual QA with a mock super-admin ("Jawad"). Mirrors the
// controller's create() payload: the workspace's own work_categories, clients
// (with their engagement type + profiles), the tracker/profile name list, and
// workspace terminology. Never used live.
// ---------------------------------------------------------------------------
const DEV_SAMPLE = {
    user: { id: 1, name: 'Jawad', email: 'jawad@staffvora.com', is_super_admin: true, permissions: [] },
    workspace: { client_term: 'Client', profile_term: 'Profile', timezone: 'UTC' },
    // Preview renders the AGENCY variant: the profile field appears because the
    // ENTITLEMENT is on, exactly as it does live.
    entitlements: { upwork_profiles: true },
    // An AGENCY workspace's catalogue: the five approved General defaults plus
    // the three agency starter rows. A General workspace is seeded with the
    // first five only — same table, same code path, different seed rows.
    workCategories: [
        { id: 1, name: 'Client Work', slug: 'client_work', is_billable_default: true },
        { id: 2, name: 'Internal/Admin', slug: 'internal_admin', is_billable_default: false },
        { id: 3, name: 'Business Development', slug: 'business_development', is_billable_default: false },
        { id: 4, name: 'Training/Onboarding', slug: 'training_onboarding', is_billable_default: false },
        { id: 5, name: 'Meetings', slug: 'meetings', is_billable_default: false },
        { id: 6, name: 'Bidding', slug: 'bidding', is_billable_default: false },
        { id: 7, name: 'Outside Upwork', slug: 'outside_upwork', is_billable_default: true },
        { id: 8, name: 'Trial/Test task', slug: 'trial_test_task', is_billable_default: false },
    ],
    clients: [
        { id: 1, name: 'Acme Storefront Rebuild', work_type: 'tracker_manual', upwork_profiles: [{ id: 1, name: 'Primary Agency' }] },
        { id: 2, name: 'Nimbus Mobile App', work_type: 'fixed', upwork_profiles: [{ id: 2, name: 'Design Studio' }] },
        { id: 3, name: 'Helios Analytics Dashboard', work_type: 'tracker_manual', upwork_profiles: [{ id: 1, name: 'Primary Agency' }] },
        { id: 4, name: 'Orion CRM Integration', work_type: 'outside_of_upwork', upwork_profiles: [] },
    ],
    trackers: ['Primary Agency', 'Design Studio'],
    // The manual-time window the server will accept (see WorkHourController).
    manualWindow: { earliest: '2026-07-01', latest: '2026-07-22' },
    // A day with a clock-in/out where part of the time was never tracked.
    gapDay: {
        date: '2026-07-22',
        has_attendance: true,
        in_office: [{ start: '09:00', end: '18:00', label: '9:00 AM – 6:00 PM', minutes: 540 }],
    },
    gaps: [
        { start: '12:30', end: '13:15', label: '12:30 PM – 1:15 PM', minutes: 45 },
        { start: '16:40', end: '18:00', label: '4:40 PM – 6:00 PM', minutes: 80 },
    ],
};

export default function WorkHourCreate({
    auth,
    clients = [],
    trackers = [],
    workCategories = [],
    manualWindow = {},
    gapDay = {},
    gaps = [],
    preview = false,
}) {
    const pageProps = usePage().props || {};
    const pageWorkspace = pageProps.workspace || {};
    const pageEntitlements = pageProps.entitlements || {};

    const user = preview ? DEV_SAMPLE.user : auth?.user;
    const workspace = preview ? DEV_SAMPLE.workspace : pageWorkspace;
    // Capability from the ONE entitlement map; `workspace` is terminology only.
    const entitlements = preview ? DEV_SAMPLE.entitlements : pageEntitlements;
    // The workspace's OWN editable list — the single source for what work was.
    // No hardcoded map lives on this page any more.
    const categoryList = preview ? DEV_SAMPLE.workCategories : workCategories;
    const clientList = preview ? DEV_SAMPLE.clients : clients;
    const trackerList = preview ? DEV_SAMPLE.trackers : trackers;
    const window_ = preview ? DEV_SAMPLE.manualWindow : (manualWindow || {});
    const day = preview ? DEV_SAMPLE.gapDay : (gapDay || {});
    const openGaps = preview ? DEV_SAMPLE.gaps : (gaps || []);
    const colorScheme = useColorScheme();

    const usesProfiles = entitlements.upwork_profiles === true;
    const clientTerm = workspace.client_term || 'Project';
    const profileTerm = workspace.profile_term || 'Profile';
    const businessTimezone = workspace.timezone || 'UTC';

    const form = useForm({
        date: (() => {
            const businessDate = new Date().toLocaleDateString('en-CA', {
                timeZone: businessTimezone,
            });
            // Never open on a date the server would reject (the workspace prop
            // can be missing, in which case the browser guess may be a day out).
            if (window_.latest && businessDate > window_.latest) return window_.latest;
            if (window_.earliest && businessDate < window_.earliest) return window_.earliest;
            return businessDate;
        })(),
        entry_mode: 'range',
        start_time: '',
        end_time: '',
        hours: '0',
        minutes: '0',
        description: '',
        // CONCEPT B — what the work was, chosen from the workspace's own list.
        work_category_id: '',
        // CONCEPT C — billable yes/no, seeded from the category's default and
        // overridable per entry. (Concept A, the capture method, is system-set:
        // anything typed in here is `manual` by definition, never a choice.)
        is_billable: false,
        client_id: '',
        tracker: '',
        trackerSearch: '',
        clientSearch: '',
    });

    const selectedCategory = categoryList.find(
        (category) => String(category.id) === String(form.data.work_category_id)
    ) || null;

    // The client list is no longer filtered by the work type — a category and a
    // client are independent facts now, so every client stays selectable.
    const getFilteredClients = () => clientList;

    // Profiles attached to a client (agency workspaces only); also what decides
    // whether a profile is even meaningful for the chosen client.
    const clientProfileNames = (client) => {
        if (!client) return [];
        if (client.upwork_profiles?.length) return client.upwork_profiles.map((profile) => profile.name);
        if (client.upwork_profile?.name) return [client.upwork_profile.name];
        return [];
    };

    // Get filtered trackers based on selected client
    const getFilteredTrackers = () => {
        if (!form.data.client_id) return trackerList;

        const selectedClient = clientList.find(client => client.id == form.data.client_id);
        if (!selectedClient) return trackerList;

        const availableProfiles = clientProfileNames(selectedClient);

        if (availableProfiles.length > 0) {
            return trackerList.filter(tracker => availableProfiles.includes(tracker));
        }

        return trackerList;
    };

    const [showTrackerOptions, setShowTrackerOptions] = useState(false);
    const [showClientOptions, setShowClientOptions] = useState(false);
    const [clientValidationError, setClientValidationError] = useState('');
    const [trackerValidationError, setTrackerValidationError] = useState('');
    const trackerRef = useRef(null);
    const clientRef = useRef(null);
    const trackerDropdownRef = useRef(null);
    const clientDropdownRef = useRef(null);

    // Close dropdowns when clicking outside
    useEffect(() => {
        function handleClickOutside(event) {
            if (trackerRef.current && trackerDropdownRef.current &&
                !trackerRef.current.contains(event.target) &&
                !trackerDropdownRef.current.contains(event.target)) {
                setShowTrackerOptions(false);
            }
            if (clientRef.current && clientDropdownRef.current &&
                !clientRef.current.contains(event.target) &&
                !clientDropdownRef.current.contains(event.target)) {
                setShowClientOptions(false);
            }
        }

        document.addEventListener('mousedown', handleClickOutside);
        return () => {
            document.removeEventListener('mousedown', handleClickOutside);
        };
    }, []);

    // Validate client selection
    const validateClient = (searchValue) => {
        if (!isClientRequired()) {
            setClientValidationError('');
            return true;
        }

        if (!searchValue || searchValue.trim() === '') {
            setClientValidationError('Please select a ' + clientTerm.toLowerCase() + ' from the dropdown');
            return false;
        }

        const filteredClients = getFilteredClients();
        const matchingClient = filteredClients.find(client => client.name === searchValue);
        if (!matchingClient) {
            setClientValidationError('Please select a valid ' + clientTerm.toLowerCase() + ' from the dropdown');
            return false;
        }

        if (form.data.client_id != matchingClient.id) {
            form.setData('client_id', matchingClient.id);
        }

        setClientValidationError('');
        return true;
    };

    // Validate tracker selection. Anything TYPED must be a real profile — the
    // server rejects an unknown name outright — but an empty box is fine unless
    // the chosen client actually has profiles attached.
    const validateTracker = (searchValue) => {
        if (!searchValue || searchValue.trim() === '') {
            if (!isTrackerRequired()) {
                setTrackerValidationError('');
                return true;
            }

            setTrackerValidationError('Please select a ' + profileTerm.toLowerCase() + ' from the dropdown');
            return false;
        }

        const filteredTrackers = getFilteredTrackers();
        const matchingTracker = filteredTrackers.find(tracker => tracker === searchValue);
        if (!matchingTracker) {
            setTrackerValidationError('Please select a valid ' + profileTerm.toLowerCase() + ' from the dropdown');
            return false;
        }

        if (form.data.tracker !== matchingTracker) {
            form.setData('tracker', matchingTracker);
        }

        setTrackerValidationError('');
        return true;
    };

    // Minutes spanned by the start–end range (rolls past midnight for overnight).
    const rangeMinutes = () => {
        if (!form.data.start_time || !form.data.end_time) return null;
        const [sh, sm] = form.data.start_time.split(':').map(Number);
        const [eh, em] = form.data.end_time.split(':').map(Number);
        let mins = (eh * 60 + em) - (sh * 60 + sm);
        if (mins <= 0) mins += 24 * 60;
        return mins;
    };

    const handleSubmit = (e) => {
        e.preventDefault();

        if (!form.data.work_category_id) {
            alert('Please choose what kind of work this was.');
            return;
        }

        if (form.data.entry_mode === 'range') {
            if (!form.data.start_time || !form.data.end_time) {
                alert('Please enter both a start and end time.');
                return;
            }
        } else {
            const hours = Number(form.data.hours) || 0;
            const minutes = Number(form.data.minutes) || 0;
            if (hours === 0 && minutes === 0) {
                alert('Please enter at least some time (hours or minutes).');
                return;
            }
        }

        const isClientValid = validateClient(form.data.clientSearch);
        const isTrackerValid = validateTracker(form.data.trackerSearch || form.data.tracker);
        if (!isClientValid || !isTrackerValid) {
            return;
        }

        if (preview) return;

        // The server reads entry_mode + start/end (range) or hours/minutes (duration).
        form.post(route('work-hours.store'));
    };

    const fmtDuration = (mins) => {
        if (mins == null) return null;
        const h = Math.floor(mins / 60);
        const m = mins % 60;
        return `${h}h ${m.toString().padStart(2, '0')}m`;
    };

    // Changing the date re-asks the server for that day's open gaps. A partial
    // reload of the create page keeps this on the existing route — no extra
    // endpoint — and the server recomputes gaps from real attendance data.
    const handleDateChange = (value) => {
        form.setData('date', value);
        if (preview || !value) return;
        router.reload({
            only: ['gaps', 'gapDay'],
            data: { date: value },
            preserveState: true,
            preserveScroll: true,
        });
    };

    // One-click "fill this gap": switches to range mode and drops the gap's
    // clock endpoints in. The server still re-checks the window + overlap
    // policy, so an edited or stale chip can never bypass the rules.
    const fillGap = (gap) => {
        form.setData((current) => ({
            ...current,
            entry_mode: 'range',
            start_time: gap.start,
            end_time: gap.end,
        }));
    };

    const openGapMinutes = openGaps.reduce((sum, g) => sum + (g.minutes || 0), 0);

    const handleTrackerFocus = () => {
        setShowTrackerOptions(true);
        setShowClientOptions(false);
    };

    const handleClientFocus = () => {
        setShowClientOptions(true);
        setShowTrackerOptions(false);
    };

    // Picking a category seeds the billable flag from that category's default.
    // The person can still flip it — a category default is a starting point,
    // not a rule. The client stays selected: it no longer depends on the
    // category, so re-picking it would just be busywork.
    const handleCategoryChange = (categoryId) => {
        const category = categoryList.find((item) => String(item.id) === String(categoryId)) || null;

        form.setData((current) => ({
            ...current,
            work_category_id: categoryId,
            is_billable: category ? Boolean(category.is_billable_default) : current.is_billable,
        }));

        setClientValidationError('');
    };

    // A profile is only meaningful when the workspace uses profiles AND the
    // chosen client actually has one attached — read from the client's own
    // record rather than from a work-type lookup table.
    const isTrackerRequired = () => {
        if (!usesProfiles || !form.data.client_id) return false;
        const client = clientList.find((item) => item.id == form.data.client_id);

        return clientProfileNames(client).length > 0;
    };

    // Billable time has to be attributable to someone, so a client is required
    // whenever the entry bills — derived from concept C, not from a name list.
    // (Never demanded when the workspace has no clients yet.)
    const isClientRequired = () => Boolean(form.data.is_billable) && clientList.length > 0;

    const handleClientSearchChange = (e) => {
        const val = e.target.value;
        form.setData('clientSearch', val);

        const filteredClients = getFilteredClients();
        const matchingClient = filteredClients.find(client => client.name === val);
        if (!matchingClient) {
            form.setData('client_id', '');
        } else {
            form.setData('client_id', matchingClient.id);
        }

        if (clientValidationError) {
            setClientValidationError('');
        }

        setShowClientOptions(true);
    };

    const handleTrackerSearchChange = (e) => {
        const val = e.target.value;
        form.setData('trackerSearch', val);

        const filteredTrackers = getFilteredTrackers();
        const matchingTracker = filteredTrackers.find(tracker => tracker === val);
        if (!matchingTracker) {
            form.setData('tracker', '');
        } else {
            form.setData('tracker', matchingTracker);
        }

        if (trackerValidationError) {
            setTrackerValidationError('');
        }

        setShowTrackerOptions(true);
    };

    const handleClientSelect = (client) => {
        form.setData('client_id', client.id);
        form.setData('clientSearch', client.name);
        setClientValidationError('');
        setShowClientOptions(false);

        // Clear tracker selection when client changes to force reselection.
        form.setData('tracker', '');
        form.setData('trackerSearch', '');
        setTrackerValidationError('');

        setTimeout(() => {
            validateClient(client.name);
        }, 50);
    };

    const handleTrackerSelect = (tracker) => {
        form.setData('tracker', tracker);
        form.setData('trackerSearch', tracker);
        setTrackerValidationError('');
        setShowTrackerOptions(false);

        setTimeout(() => {
            validateTracker(tracker);
        }, 50);
    };

    const handleClientBlur = () => {
        setTimeout(() => {
            setShowClientOptions(false);
            if (isClientRequired()) {
                validateClient(form.data.clientSearch);
            }
        }, 300);
    };

    const handleTrackerBlur = () => {
        setTimeout(() => {
            setShowTrackerOptions(false);
            if (isTrackerRequired()) {
                validateTracker(form.data.trackerSearch || form.data.tracker);
            }
        }, 300);
    };

    // Derived from what is actually required right now — no per-work-type table.
    const requiredFieldsHint = () => {
        const fields = [];
        if (isClientRequired()) fields.push('Client Name');
        if (isTrackerRequired()) fields.push('Profile Name');
        fields.push('Description', form.data.entry_mode === 'range' ? 'Start & End time' : 'Hours/Minutes', 'Date');

        return fields.join(', ');
    };

    return (
        <AuthenticatedLayout
            user={user}
            header={<h2 className="text-xl font-semibold text-body">Add Work Entry</h2>}
        >
            <Head title="Add Work Entry" />

            <div className="mx-auto max-w-4xl px-4 py-6 sm:px-6 lg:px-8 space-y-6">
                <p className="text-sm text-muted">
                    Log time against the right category, client, and profile.
                </p>

                <Card padded>
                    <form onSubmit={handleSubmit} className="space-y-6">
                        {/* Work category (B) + billable (C). Capture method (A)
                            is system-set — hand-logged time is always "manual" —
                            so it is stated, never offered as a choice. */}
                        <div className="rounded-lg border border-line bg-surface-2 p-4">
                            <div className="mb-3 flex flex-wrap items-center gap-2">
                                <span className="inline-flex h-8 w-8 items-center justify-center rounded-md bg-accent/10 text-accent">
                                    <Clock className="h-4 w-4" />
                                </span>
                                <h3 className="text-base font-semibold text-body">What kind of work?</h3>
                                <Badge tone="warning" size="sm" title="Typed in by hand — not measured by the desktop tracker">
                                    Manual entry
                                </Badge>
                            </div>
                            <p className="mb-4 text-sm text-muted">
                                {form.data.work_category_id ? (
                                    <>
                                        <span className="font-semibold text-accent">Required fields:</span>{' '}
                                        {requiredFieldsHint()}
                                    </>
                                ) : (
                                    <span className="italic text-subtle">Pick a category to see the required fields</span>
                                )}
                            </p>

                            <div className="grid gap-4 md:grid-cols-2">
                                <Field
                                    label="Work category"
                                    required
                                    htmlFor="work_category_id"
                                    error={form.errors.work_category_id || undefined}
                                    hint={
                                        !form.errors.work_category_id
                                            ? 'This list belongs to your workspace — an admin can rename or add to it.'
                                            : undefined
                                    }
                                >
                                    <Select
                                        id="work_category_id"
                                        value={form.data.work_category_id}
                                        onChange={(e) => handleCategoryChange(e.target.value)}
                                        required
                                    >
                                        <option value="">Select a category…</option>
                                        {categoryList.map((category) => (
                                            <option key={category.id} value={category.id}>{category.name}</option>
                                        ))}
                                    </Select>
                                </Field>

                                <Field
                                    label="Billable"
                                    hint={
                                        selectedCategory
                                            ? `${selectedCategory.name} normally counts as ${selectedCategory.is_billable_default ? 'billable' : 'non-billable'} — you can change it for this entry.`
                                            : 'Set automatically from the category you pick.'
                                    }
                                >
                                    <div className="flex min-h-[2.5rem] items-center">
                                        <Toggle
                                            checked={Boolean(form.data.is_billable)}
                                            onChange={(next) => form.setData('is_billable', next)}
                                            label={form.data.is_billable ? 'Billable time' : 'Non-billable time'}
                                        />
                                    </div>
                                </Field>
                            </div>
                        </div>

                        {/* Client + Profile. The client picker is always offered
                            — a category no longer decides which clients exist —
                            and is only *required* when the entry is billable. */}
                        {(clientList.length > 0 || isTrackerRequired()) && (
                            <div className={cx(
                                'grid gap-6',
                                isTrackerRequired() ? 'md:grid-cols-2' : 'md:grid-cols-1'
                            )}>
                                {/* Client Name */}
                                {clientList.length > 0 && (
                                    <div className="relative">
                                        <Field
                                            label="Client Name"
                                            required={isClientRequired()}
                                            htmlFor="client"
                                            error={clientValidationError || undefined}
                                            hint={
                                                !clientValidationError
                                                    ? (isClientRequired()
                                                        ? `Billable time needs a client (${getFilteredClients().length} available).`
                                                        : `Optional for non-billable time (${getFilteredClients().length} available).`)
                                                    : undefined
                                            }
                                        >
                                            <div className="relative">
                                                <Building2 className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-accent" />
                                                <Input
                                                    id="client"
                                                    ref={clientRef}
                                                    type="text"
                                                    placeholder="Search or select client..."
                                                    className={cx('pl-9', clientValidationError && 'border-danger')}
                                                    value={form.data.clientSearch}
                                                    onChange={handleClientSearchChange}
                                                    onFocus={handleClientFocus}
                                                    onBlur={handleClientBlur}
                                                    required={isClientRequired()}
                                                />
                                            </div>
                                        </Field>
                                        {showClientOptions && (
                                            <div
                                                ref={clientDropdownRef}
                                                className="absolute z-50 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-line bg-surface p-1.5 shadow-xl"
                                            >
                                                {getFilteredClients().filter(client =>
                                                    !form.data.clientSearch || client.name.toLowerCase().includes(form.data.clientSearch.toLowerCase())
                                                ).map(client => (
                                                    <button
                                                        key={client.id}
                                                        type="button"
                                                        className="flex w-full items-center justify-between gap-2 rounded-md px-3 py-2 text-left text-sm text-body transition hover:bg-surface-2"
                                                        onMouseDown={(e) => e.preventDefault()}
                                                        onClick={() => handleClientSelect(client)}
                                                    >
                                                        <span className="font-medium">{client.name}</span>
                                                        {/* The client's attached profiles — read from its own
                                                            record. The old engagement-code badge here was a
                                                            fifth competing label map; it is gone. */}
                                                        {usesProfiles && clientProfileNames(client).length > 0 && (
                                                            <span className="rounded bg-surface-2 px-2 py-0.5 text-xs font-medium text-muted">
                                                                {clientProfileNames(client).join(', ')}
                                                            </span>
                                                        )}
                                                    </button>
                                                ))}
                                                {getFilteredClients().filter(client =>
                                                    !form.data.clientSearch || client.name.toLowerCase().includes(form.data.clientSearch.toLowerCase())
                                                ).length === 0 && (
                                                    <div className="px-3 py-4 text-center text-sm text-subtle">
                                                        {form.data.clientSearch
                                                            ? 'No matching clients found.'
                                                            : 'No clients available yet.'}
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}

                                {/* Profile Name */}
                                {isTrackerRequired() && (
                                    <div className="relative">
                                        <Field
                                            label="Profile Name"
                                            required
                                            htmlFor="tracker"
                                            error={trackerValidationError || undefined}
                                        >
                                            <div className="relative">
                                                <UserIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-accent" />
                                                <Input
                                                    id="tracker"
                                                    ref={trackerRef}
                                                    type="text"
                                                    placeholder="Search or select profile..."
                                                    className={cx('pl-9', trackerValidationError && 'border-danger')}
                                                    value={form.data.trackerSearch || (form.data.tracker ? form.data.tracker : '')}
                                                    onChange={handleTrackerSearchChange}
                                                    onFocus={handleTrackerFocus}
                                                    onBlur={handleTrackerBlur}
                                                    required={isTrackerRequired()}
                                                />
                                            </div>
                                        </Field>
                                        {showTrackerOptions && (
                                            <div
                                                ref={trackerDropdownRef}
                                                className="absolute z-50 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-line bg-surface p-1.5 shadow-xl"
                                            >
                                                {getFilteredTrackers().filter(tracker =>
                                                    !form.data.trackerSearch || tracker.toLowerCase().includes(form.data.trackerSearch.toLowerCase())
                                                ).map((tracker, index) => (
                                                    <button
                                                        key={index}
                                                        type="button"
                                                        className="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm font-medium text-body transition hover:bg-surface-2"
                                                        onMouseDown={(e) => e.preventDefault()}
                                                        onClick={() => handleTrackerSelect(tracker)}
                                                    >
                                                        <UserIcon className="h-4 w-4 text-subtle" />
                                                        {tracker}
                                                    </button>
                                                ))}
                                                {getFilteredTrackers().filter(tracker =>
                                                    !form.data.trackerSearch || tracker.toLowerCase().includes(form.data.trackerSearch.toLowerCase())
                                                ).length === 0 && (
                                                    <div className="px-3 py-4 text-center text-sm text-subtle">
                                                        {form.data.trackerSearch
                                                            ? 'No matching profiles found for this client.'
                                                            : 'No profiles available for this client.'}
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        )}

                        {/* Range vs Duration toggle */}
                        <div className="inline-flex rounded-lg border border-line bg-surface-2 p-1 text-sm">
                            {[['range', 'Start–End time'], ['duration', 'Duration']].map(([val, lbl]) => (
                                <button
                                    key={val}
                                    type="button"
                                    onClick={() => form.setData('entry_mode', val)}
                                    className={cx(
                                        'rounded-md px-3 py-1.5 font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent',
                                        form.data.entry_mode === val ? 'bg-accent text-white' : 'text-muted hover:text-body'
                                    )}
                                >
                                    {lbl}
                                </button>
                            ))}
                        </div>

                        <div className="grid grid-cols-1 gap-6 md:grid-cols-3">
                            {/* Date — restricted to the window the server accepts */}
                            <Field
                                label="Date"
                                required
                                htmlFor="date"
                                error={form.errors.date || undefined}
                                hint={
                                    !form.errors.date
                                        ? (window_.earliest
                                            ? `You can log time from ${window_.earliest} up to ${window_.latest}.`
                                            : `You can log time up to ${window_.latest} — no future dates.`)
                                        : undefined
                                }
                            >
                                <Input
                                    type="date"
                                    id="date"
                                    value={form.data.date}
                                    min={window_.earliest || undefined}
                                    max={window_.latest || undefined}
                                    onChange={(e) => handleDateChange(e.target.value)}
                                    style={{ colorScheme }}
                                    required
                                />
                            </Field>

                            {form.data.entry_mode === 'range' ? (
                                <>
                                    <Field label="Start time" required error={form.errors.start_time || undefined}>
                                        <Input
                                            type="time"
                                            value={form.data.start_time}
                                            onChange={(e) => form.setData('start_time', e.target.value)}
                                            style={{ colorScheme }}
                                            required
                                        />
                                    </Field>
                                    <Field label="End time" required error={form.errors.end_time || undefined}>
                                        <Input
                                            type="time"
                                            value={form.data.end_time}
                                            onChange={(e) => form.setData('end_time', e.target.value)}
                                            style={{ colorScheme }}
                                            required
                                        />
                                    </Field>
                                </>
                            ) : (
                                <>
                                    <Field label="Hours" required htmlFor="hours">
                                        <Input
                                            type="number"
                                            id="hours"
                                            min="0"
                                            max="24"
                                            step="1"
                                            value={form.data.hours}
                                            onChange={(e) => {
                                                let value = parseInt(e.target.value);
                                                if (isNaN(value) || value < 0) value = 0;
                                                if (value > 24) value = 24;
                                                form.setData('hours', value.toString());
                                            }}
                                            placeholder="Enter hours (0-24)"
                                            required
                                        />
                                    </Field>
                                    <Field label="Minutes" htmlFor="minutes">
                                        <Input
                                            type="number"
                                            id="minutes"
                                            min="0"
                                            max="59"
                                            step="1"
                                            value={form.data.minutes}
                                            onChange={(e) => {
                                                let value = parseInt(e.target.value);
                                                if (isNaN(value) || value < 0) value = 0;
                                                if (value > 59) value = 59;
                                                form.setData('minutes', value.toString());
                                            }}
                                            placeholder="Enter minutes (0-59)"
                                        />
                                    </Field>
                                </>
                            )}
                        </div>

                        {/* Untracked time (gap-fill) — offer only the parts of the
                            day the person was in office but nothing was tracked. */}
                        <div className="rounded-lg border border-line bg-surface-2 p-4">
                            <div className="mb-1 flex flex-wrap items-center justify-between gap-2">
                                <h3 className="inline-flex items-center gap-2 text-sm font-semibold text-body">
                                    <CalendarClock className="h-4 w-4 text-accent" />
                                    Untracked time on {day.date || form.data.date}
                                </h3>
                                {openGaps.length > 0 && (
                                    <span className="text-xs font-medium text-muted">{fmtMins(openGapMinutes)} open</span>
                                )}
                            </div>
                            <p className="mb-3 max-w-prose text-xs text-muted">
                                These are the stretches between your clock-in and clock-out that the tracker
                                didn&apos;t record and you haven&apos;t already logged. Pick one to fill it in,
                                or enter your own times above.
                            </p>

                            {!day.has_attendance ? (
                                <p className="inline-flex items-start gap-2 text-xs text-subtle">
                                    <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                    <span>
                                        No clock-in was recorded for this day, so there&apos;s no in-office time to
                                        compare against. You can still enter the times by hand above.
                                    </span>
                                </p>
                            ) : openGaps.length === 0 ? (
                                <p className="inline-flex items-start gap-2 text-xs text-subtle">
                                    <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                    <span>
                                        Nothing left to fill — every minute you were in office is already tracked,
                                        on a break, or logged.
                                    </span>
                                </p>
                            ) : (
                                <div className="flex flex-wrap gap-2">
                                    {openGaps.map((gap, i) => {
                                        const active = form.data.entry_mode === 'range'
                                            && form.data.start_time === gap.start
                                            && form.data.end_time === gap.end;
                                        return (
                                            <button
                                                key={`${gap.start}-${gap.end}-${i}`}
                                                type="button"
                                                onClick={() => fillGap(gap)}
                                                title="Fill this untracked stretch"
                                                aria-pressed={active}
                                                className={cx(
                                                    'inline-flex items-center gap-2 rounded-lg border px-2.5 py-1.5 text-xs font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app',
                                                    active
                                                        ? 'border-accent bg-accent/10 text-accent'
                                                        : 'border-line bg-surface text-body hover:border-accent hover:text-accent'
                                                )}
                                            >
                                                <span>{gap.label || `${to12(gap.start)} – ${to12(gap.end)}`}</span>
                                                <span className="text-subtle">{fmtMins(gap.minutes)}</span>
                                            </button>
                                        );
                                    })}
                                </div>
                            )}
                        </div>

                        {/* Time summary */}
                        {(() => {
                            const mins = form.data.entry_mode === 'range'
                                ? rangeMinutes()
                                : ((parseInt(form.data.hours) || 0) * 60 + (parseInt(form.data.minutes) || 0));
                            const ok = mins != null && mins > 0;
                            const overnight = form.data.entry_mode === 'range' && ok
                                && form.data.start_time && form.data.end_time
                                && form.data.end_time <= form.data.start_time;
                            return (
                                <div className={cx(
                                    'rounded-lg border p-4',
                                    ok ? 'border-line bg-surface-2' : 'border-danger/30 bg-danger-soft'
                                )}>
                                    <div className="flex items-center gap-3">
                                        {ok ? (
                                            <Clock className="h-6 w-6 text-accent" />
                                        ) : (
                                            <AlertCircle className="h-6 w-6 text-danger" />
                                        )}
                                        <div>
                                            <div className={cx('text-sm font-medium', ok ? 'text-muted' : 'text-danger')}>
                                                Total time {!ok && '(required)'}
                                            </div>
                                            <div className={cx('text-2xl font-bold', ok ? 'text-body' : 'text-danger')}>
                                                {ok ? fmtDuration(mins) : (form.data.entry_mode === 'range' ? 'Enter a start and end time' : 'No time entered')}
                                            </div>
                                            {ok && (
                                                <div className="text-xs text-subtle">
                                                    {(mins / 60).toFixed(2)} decimal hours{overnight ? ' · spans midnight' : ''}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            );
                        })()}

                        {/* Description */}
                        <Field label="Description" required htmlFor="description">
                            <Textarea
                                id="description"
                                rows={4}
                                value={form.data.description}
                                onChange={(e) => form.setData('description', e.target.value)}
                                placeholder="Describe the work you did..."
                                required
                            />
                        </Field>

                        <div className="flex items-center justify-between border-t border-line pt-6">
                            <Button
                                as={Link}
                                href={preview ? '#' : route('work-hours.index')}
                                variant="secondary"
                                icon={<ArrowLeft className="h-4 w-4" />}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                loading={form.processing}
                                icon={!form.processing ? <Check className="h-4 w-4" /> : undefined}
                            >
                                {form.processing ? 'Creating...' : 'Create Entry'}
                            </Button>
                        </div>
                    </form>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
