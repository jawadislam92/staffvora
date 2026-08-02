import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link, usePage, router } from '@inertiajs/react';
import { Button, Card, Badge, Field, Input, Select, Textarea, Toggle } from '@/Components/ui';
import { ArrowLeft, Check, Clock, Building2, User as UserIcon, AlertCircle, Lock, CalendarClock, Info } from 'lucide-react';

const cx = (...a) => a.filter(Boolean).join(' ');

// ---------------------------------------------------------------------------
// useColorScheme — mirror the active theme onto native <input type="date"/time>
// controls so their OS picker/glyph render light-on-light or dark-on-dark.
// ---------------------------------------------------------------------------
function useColorScheme() {
    const [scheme, setScheme] = React.useState(() => (
        typeof document !== 'undefined' && document.documentElement.getAttribute('data-theme') === 'light'
            ? 'light'
            : 'dark'
    ));

    React.useEffect(() => {
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

const DEFAULT_LOCK_REASON =
    "These hours were measured by the desktop tracker, so they can't be edited or deleted by hand. Adjust the tracked time from the Timeline for that day instead.";

// ---------------------------------------------------------------------------
// DEV_SAMPLE — used ONLY when `preview` is true so the form renders fully
// offline for visual QA with a mock super-admin ("Jawad"). Mirrors the
// controller's edit() payload: a seeded workHour (with its work category +
// billable flag), the workspace's own category catalogue, the client/tracker
// lists, workspace terminology, the manual-time window and the day's open gaps.
// Add `?locked=1` to the preview URL to see the tracker-locked variant.
// Never referenced when preview=false.
// ---------------------------------------------------------------------------
const DEV_SAMPLE = {
    user: { id: 1, name: 'Jawad', email: 'jawad@staffvora.com', is_super_admin: true, permissions: [] },
    workspace: { client_term: 'Client', profile_term: 'Profile' },
    // Preview renders the AGENCY variant: the profile field appears because the
    // ENTITLEMENT is on, exactly as it does live.
    entitlements: { upwork_profiles: true },
    // An AGENCY workspace's catalogue (General is seeded with the first five
    // rows only) — one table, one code path, different seed rows. The archived
    // row is included because an entry sitting on one must stay selectable.
    workCategories: [
        { id: 1, name: 'Client Work', slug: 'client_work', is_billable_default: true, is_active: true },
        { id: 2, name: 'Internal/Admin', slug: 'internal_admin', is_billable_default: false, is_active: true },
        { id: 3, name: 'Business Development', slug: 'business_development', is_billable_default: false, is_active: true },
        { id: 4, name: 'Training/Onboarding', slug: 'training_onboarding', is_billable_default: false, is_active: true },
        { id: 5, name: 'Meetings', slug: 'meetings', is_billable_default: false, is_active: true },
        { id: 6, name: 'Bidding', slug: 'bidding', is_billable_default: false, is_active: true },
        { id: 7, name: 'Outside Upwork', slug: 'outside_upwork', is_billable_default: true, is_active: true },
        { id: 8, name: 'Trial/Test task', slug: 'trial_test_task', is_billable_default: false, is_active: false },
    ],
    workHour: {
        id: 4815,
        date: '2026-07-21',
        started_at: '2026-07-21T14:00:00',
        ended_at: '2026-07-21T17:45:00',
        hours: 3.75,
        description: 'Onboarding screens, dark-mode pass.',
        work_category_id: 1,
        work_category: { id: 1, name: 'Client Work' },
        is_billable: true,
        capture_method: 'manual',
        work_type: 'tracker',
        client_id: 1,
        tracker: 'Primary Agency',
        source: 'manual',
        is_locked: false,
        client: { id: 1, name: 'Acme Storefront Rebuild' },
    },
    clients: [
        { id: 1, name: 'Acme Storefront Rebuild' },
        { id: 2, name: 'Nimbus Mobile App' },
        { id: 3, name: 'Helios Analytics Dashboard' },
        { id: 4, name: 'Orion CRM Integration' },
    ],
    trackers: ['Primary Agency', 'Design Studio'],
    manualWindow: { earliest: '2026-07-01', latest: '2026-07-22' },
    gapDay: {
        date: '2026-07-21',
        has_attendance: true,
        in_office: [{ start: '09:00', end: '18:00', label: '9:00 AM – 6:00 PM', minutes: 540 }],
    },
    gaps: [
        { start: '12:30', end: '13:15', label: '12:30 PM – 1:15 PM', minutes: 45 },
        { start: '17:45', end: '18:00', label: '5:45 PM – 6:00 PM', minutes: 15 },
    ],
};

export default function WorkHourEdit({
    auth,
    workHour,
    trackers = [],
    clients = [],
    workCategories = [],
    captureMethod = null,
    locked = false,
    lockReason = null,
    manualWindow = {},
    gapDay = {},
    gaps = [],
    preview = false,
}) {
    const pageProps = usePage().props || {};
    const pageWorkspace = pageProps.workspace || {};
    const pageEntitlements = pageProps.entitlements || {};

    // Preview shows the editable variant by default; `?locked=1` renders the
    // tracker-locked one so both states can be reviewed offline.
    const previewLocked = preview
        && typeof window !== 'undefined'
        && window.location.search.includes('locked');

    const user = preview ? DEV_SAMPLE.user : auth?.user;
    const workspace = preview ? DEV_SAMPLE.workspace : pageWorkspace;
    // Capability from the ONE entitlement map; `workspace` is terminology only.
    const entitlements = preview ? DEV_SAMPLE.entitlements : pageEntitlements;
    const entry = preview
        ? { ...DEV_SAMPLE.workHour, ...(previewLocked ? { source: 'tracker', is_locked: true } : {}) }
        : workHour;
    const clientList = preview ? DEV_SAMPLE.clients : clients;
    const trackerList = preview ? DEV_SAMPLE.trackers : trackers;
    // The workspace's OWN editable list (active rows, plus this entry's own row
    // when it has since been archived) — the single source for what work was.
    const categoryList = preview ? DEV_SAMPLE.workCategories : workCategories;

    // Tracker-recorded entries have locked time: the hours always reflect what
    // the desktop measured, so they can't be edited up or down and the entry
    // can't be moved to another day. Description, client, work type and profile
    // stay editable. WorkHourController::update() enforces exactly this — the
    // disabled fields below only explain it.
    const isLocked = preview
        ? previewLocked
        : Boolean(locked ?? (entry?.is_locked ?? (entry?.source === 'tracker' || Boolean(entry?.tracking_session_id))));
    const lockCopy = (preview ? DEFAULT_LOCK_REASON : (lockReason || DEFAULT_LOCK_REASON));

    const window_ = preview ? DEV_SAMPLE.manualWindow : (manualWindow || {});
    const day = preview ? DEV_SAMPLE.gapDay : (gapDay || {});
    const openGaps = isLocked ? [] : (preview ? DEV_SAMPLE.gaps : (gaps || []));
    const colorScheme = useColorScheme();

    const usesProfiles = entitlements.upwork_profiles === true;
    const clientTerm = workspace.client_term || 'Project';
    const profileTerm = workspace.profile_term || 'Profile';

    // Convert decimal hours back to hours and minutes for display
    const totalMinutes = Math.round(entry.hours * 60);
    const displayHours = Math.floor(totalMinutes / 60);
    const displayMinutes = totalMinutes % 60;

    // Pull the wall-clock HH:MM straight out of the ISO string (no Date(), which
    // would shift it by the browser timezone) so editing keeps the entered time.
    const clock = (iso) => (iso ? String(iso).substring(11, 16) : '');

    const form = useForm({
        date: entry.date,
        entry_mode: entry.started_at ? 'range' : 'duration',
        start_time: clock(entry.started_at),
        end_time: clock(entry.ended_at),
        hours: displayHours.toString(),
        minutes: displayMinutes.toString(),
        description: entry.description || '',
        // CONCEPT B — the workspace's own category. CONCEPT C — billable.
        // CONCEPT A (capture method) is derived from the tracking session and is
        // shown below as a fact, never as an editable field.
        work_category_id: entry.work_category_id || entry.work_category?.id || '',
        is_billable: entry.is_billable ?? true,
        client_id: entry.client_id || '',
        tracker: entry.tracker || '',
        trackerSearch: '',
        clientSearch: '',
    });

    const selectedCategory = categoryList.find(
        (category) => String(category.id) === String(form.data.work_category_id)
    ) || null;

    const capture = preview
        ? (previewLocked ? 'tracked' : 'manual')
        : (captureMethod || entry.capture_method || (isLocked ? 'tracked' : 'manual'));

    const rangeMinutes = () => {
        if (!form.data.start_time || !form.data.end_time) return null;
        const [sh, sm] = form.data.start_time.split(':').map(Number);
        const [eh, em] = form.data.end_time.split(':').map(Number);
        let mins = (eh * 60 + em) - (sh * 60 + sm);
        if (mins <= 0) mins += 24 * 60;
        return mins;
    };
    const fmtDuration = (mins) => {
        if (mins == null) return null;
        return `${Math.floor(mins / 60)}h ${(mins % 60).toString().padStart(2, '0')}m`;
    };
    const [showTrackerOptions, setShowTrackerOptions] = React.useState(false);
    const [showClientOptions, setShowClientOptions] = React.useState(false);
    const [clientValidationError, setClientValidationError] = React.useState('');
    const [trackerValidationError, setTrackerValidationError] = React.useState('');
    const trackerRef = React.useRef(null);
    const clientRef = React.useRef(null);
    const trackerDropdownRef = React.useRef(null);
    const clientDropdownRef = React.useRef(null);

    // Close dropdowns when clicking outside
    React.useEffect(() => {
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

        const matchingClient = clientList.find(client => client.name === searchValue);
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

    // Validate tracker selection. A profile is optional here, but anything TYPED
    // must be a real one — the server rejects an unknown name outright.
    const validateTracker = (searchValue) => {
        if (!searchValue || searchValue.trim() === '') {
            if (!isTrackerRequired()) {
                setTrackerValidationError('');
                return true;
            }

            setTrackerValidationError('Please select a ' + profileTerm.toLowerCase() + ' from the dropdown');
            return false;
        }

        const matchingTracker = trackerList.find(tracker => tracker === searchValue);
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

    // Changing the date re-asks the server for that day's open gaps, via a
    // partial reload of this same page (no extra endpoint).
    const handleDateChange = (value) => {
        form.setData('date', value);
        if (preview || isLocked || !value) return;
        router.reload({
            only: ['gaps', 'gapDay'],
            data: { date: value },
            preserveState: true,
            preserveScroll: true,
        });
    };

    const fillGap = (gap) => {
        form.setData((current) => ({
            ...current,
            entry_mode: 'range',
            start_time: gap.start,
            end_time: gap.end,
        }));
    };

    const openGapMinutes = openGaps.reduce((sum, g) => sum + (g.minutes || 0), 0);

    const handleSubmit = (e) => {
        e.preventDefault();

        // Locked entries submit labels only — the server ignores any time the
        // client sends, so there is nothing to validate here.
        if (!isLocked) {
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
        }

        const isClientValid = validateClient(form.data.clientSearch);
        const isTrackerValid = validateTracker(form.data.trackerSearch || form.data.tracker);
        if (!isClientValid || !isTrackerValid) {
            return;
        }

        if (preview) return;

        // The server reads entry_mode + start/end (range) or hours/minutes (duration).
        form.put(route('work-hours.update', entry.id));
    };

    const handleTrackerFocus = () => {
        setShowTrackerOptions(true);
        setShowClientOptions(false);
    };

    const handleClientFocus = () => {
        setShowClientOptions(true);
        setShowTrackerOptions(false);
    };

    // Re-filing an entry seeds the billable flag from the new category's
    // default; an explicit flip afterwards still wins. Existing client and
    // profile assignments are left alone — they no longer depend on the
    // category, so changing it must not silently unassign the client.
    const handleCategoryChange = (categoryId) => {
        const category = categoryList.find((item) => String(item.id) === String(categoryId)) || null;

        form.setData((current) => ({
            ...current,
            work_category_id: categoryId,
            is_billable: category ? Boolean(category.is_billable_default) : current.is_billable,
        }));
    };

    // The profile picker is offered whenever the workspace uses profiles; it is
    // never *required* here (the server keeps it nullable too). A typed value
    // must still match a real profile — see validateTracker.
    const showTracker = usesProfiles && trackerList.length > 0;
    const isTrackerRequired = () => false;

    // Billable time has to be attributable to someone — derived from concept C,
    // not from a list of work-type names.
    const isClientRequired = () => Boolean(form.data.is_billable) && clientList.length > 0;

    const handleClientSearchChange = (e) => {
        const val = e.target.value;
        form.setData('clientSearch', val);

        const matchingClient = clientList.find(client => client.name === val);
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

        const matchingTracker = trackerList.find(tracker => tracker === val);
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

    // Set initial search values
    React.useEffect(() => {
        if (entry.client && form.data.clientSearch === '') {
            form.setData(prev => ({
                ...prev,
                clientSearch: entry.client.name,
            }));
        }
        if (entry.tracker && form.data.trackerSearch === '') {
            form.setData(prev => ({
                ...prev,
                trackerSearch: entry.tracker,
            }));
        }
    }, [entry]);

    // Derived from what is actually required right now — no per-work-type table.
    const requiredFieldsHint = () => {
        const fields = [];
        if (isClientRequired()) fields.push('Client Name');
        fields.push('Description');
        if (!isLocked) {
            fields.push(form.data.entry_mode === 'range' ? 'Start & End time' : 'Hours/Minutes', 'Date');
        }

        return fields.join(', ');
    };

    return (
        <AuthenticatedLayout
            user={user}
            header={<h2 className="text-xl font-semibold text-body">Edit Work Entry</h2>}
        >
            <Head title="Edit Work Entry" />

            <div className="mx-auto max-w-4xl px-4 py-6 sm:px-6 lg:px-8 space-y-6">
                <p className="text-sm text-muted">
                    {isLocked
                        ? 'This entry came from the desktop tracker. Its time is locked — you can still correct how it is labelled.'
                        : 'Update the recorded time and its client or profile assignment.'}
                </p>

                <Card padded>
                    <form onSubmit={handleSubmit} className="space-y-6">
                        {/* Work category (B) + billable (C). Capture method (A)
                            is derived from the tracking session, so it is stated
                            here as a fact — it was never a user's choice. Both
                            stay editable on a locked entry: the LABELS can always
                            be corrected, only the time itself is frozen. */}
                        <div className="rounded-lg border border-line bg-surface-2 p-4">
                            <div className="mb-3 flex flex-wrap items-center gap-2">
                                <span className="inline-flex h-8 w-8 items-center justify-center rounded-md bg-accent/10 text-accent">
                                    <Clock className="h-4 w-4" />
                                </span>
                                <h3 className="text-base font-semibold text-body">What kind of work?</h3>
                                {capture === 'tracked' ? (
                                    <Badge tone="success" size="sm" dot title="Measured by the desktop tracker">
                                        Tracked
                                    </Badge>
                                ) : (
                                    <Badge tone="warning" size="sm" title="Typed in by hand in the web app">
                                        Manual entry
                                    </Badge>
                                )}
                            </div>
                            <p className="mb-4 text-sm text-muted">
                                <span className="font-semibold text-accent">Required fields:</span>{' '}
                                {requiredFieldsHint()}
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
                                            <option key={category.id} value={category.id}>
                                                {category.is_active === false ? `${category.name} (archived)` : category.name}
                                            </option>
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

                        {/* Profile + Client. Both are labels, so both stay
                            editable even on a tracker-locked entry. */}
                        {(clientList.length > 0 || showTracker) && (
                            <div className={cx(
                                'grid gap-6',
                                showTracker && clientList.length > 0 ? 'md:grid-cols-2' : 'md:grid-cols-1'
                            )}>
                                {/* Profile Name */}
                                {showTracker && (
                                    <div className="relative">
                                        <Field
                                            label="Profile Name"
                                            htmlFor="tracker"
                                            error={trackerValidationError || form.errors.tracker || undefined}
                                            hint={
                                                !trackerValidationError && !form.errors.tracker
                                                    ? 'Optional — leave blank when no profile applies.'
                                                    : undefined
                                            }
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
                                                className="absolute z-50 mt-1 max-h-48 w-full overflow-y-auto rounded-lg border border-line bg-surface p-1.5 shadow-xl"
                                            >
                                                {trackerList.filter(tracker =>
                                                    !form.data.trackerSearch || tracker.toLowerCase().includes(form.data.trackerSearch.toLowerCase())
                                                ).map((tracker, index) => (
                                                    <button
                                                        key={index}
                                                        type="button"
                                                        className="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm text-body transition hover:bg-surface-2"
                                                        onMouseDown={(e) => e.preventDefault()}
                                                        onClick={() => handleTrackerSelect(tracker)}
                                                    >
                                                        <UserIcon className="h-4 w-4 text-subtle" />
                                                        {tracker}
                                                    </button>
                                                ))}
                                                {trackerList.filter(tracker =>
                                                    !form.data.trackerSearch || tracker.toLowerCase().includes(form.data.trackerSearch.toLowerCase())
                                                ).length === 0 && form.data.trackerSearch && (
                                                    <div className="px-3 py-2 text-sm text-subtle">
                                                        No profiles found. Please select from available options.
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}

                                {/* Client Name */}
                                {clientList.length > 0 && (
                                    <div className="relative">
                                        <Field
                                            label="Client Name"
                                            required={isClientRequired()}
                                            htmlFor="client"
                                            error={clientValidationError || form.errors.client_id || undefined}
                                            hint={
                                                !clientValidationError && !form.errors.client_id
                                                    ? (isClientRequired()
                                                        ? 'Billable time needs a client.'
                                                        : 'Optional for non-billable time.')
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
                                                className="absolute z-50 mt-1 max-h-48 w-full overflow-y-auto rounded-lg border border-line bg-surface p-1.5 shadow-xl"
                                            >
                                                {clientList.filter(client =>
                                                    !form.data.clientSearch || client.name.toLowerCase().includes(form.data.clientSearch.toLowerCase())
                                                ).map(client => (
                                                    <button
                                                        key={client.id}
                                                        type="button"
                                                        className="w-full rounded-md px-3 py-2 text-left text-sm text-body transition hover:bg-surface-2"
                                                        onMouseDown={(e) => e.preventDefault()}
                                                        onClick={() => handleClientSelect(client)}
                                                    >
                                                        {client.name}
                                                    </button>
                                                ))}
                                                {clientList.filter(client =>
                                                    !form.data.clientSearch || client.name.toLowerCase().includes(form.data.clientSearch.toLowerCase())
                                                ).length === 0 && form.data.clientSearch && (
                                                    <div className="px-3 py-2 text-sm text-subtle">
                                                        No clients found. Please select from available options.
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        )}

                        {/* Locked notice — tracker-recorded time */}
                        {isLocked && (
                            <div className="rounded-lg border border-warning/30 bg-warning-soft p-4">
                                <div className="flex items-start gap-3">
                                    <Lock className="mt-0.5 h-5 w-5 shrink-0 text-warning" />
                                    <div className="space-y-1.5">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <p className="text-sm font-semibold text-warning">Tracked time is locked</p>
                                            <Badge tone="warning" size="sm">
                                                <Lock className="h-3 w-3" />
                                                Locked
                                            </Badge>
                                        </div>
                                        <p className="max-w-prose text-xs text-muted">{lockCopy}</p>
                                        <p className="max-w-prose text-xs text-muted">
                                            You can still correct the category, billable flag, client, profile and description below.
                                        </p>
                                        {!preview && entry.user_id && entry.date && (
                                            <Link
                                                href={route('timeline.index', { user_id: entry.user_id, date: entry.date })}
                                                className="inline-block text-xs font-semibold text-accent underline"
                                            >
                                                Open this day in the Timeline →
                                            </Link>
                                        )}
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Range vs Duration toggle */}
                        <div className={cx('inline-flex rounded-lg border border-line bg-surface-2 p-1 text-sm', isLocked && 'opacity-50')}>
                            {[['range', 'Start–End time'], ['duration', 'Duration']].map(([val, lbl]) => (
                                <button
                                    key={val}
                                    type="button"
                                    disabled={isLocked}
                                    title={isLocked ? lockCopy : undefined}
                                    onClick={() => form.setData('entry_mode', val)}
                                    className={cx(
                                        'rounded-md px-3 py-1.5 font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent disabled:cursor-not-allowed',
                                        form.data.entry_mode === val ? 'bg-accent text-white' : 'text-muted hover:text-body'
                                    )}
                                >
                                    {lbl}
                                </button>
                            ))}
                        </div>

                        <div className="grid grid-cols-1 gap-6 md:grid-cols-3">
                            {/* Date — locked on tracked entries, else limited to the manual window */}
                            <Field
                                label="Date"
                                required
                                htmlFor="date"
                                error={form.errors.date || undefined}
                                hint={
                                    form.errors.date
                                        ? undefined
                                        : isLocked
                                            ? 'Set by the tracker — an entry can\'t be moved to another day.'
                                            : (window_.earliest
                                                ? `You can log time from ${window_.earliest} up to ${window_.latest}.`
                                                : (window_.latest ? `You can log time up to ${window_.latest} — no future dates.` : undefined))
                                }
                            >
                                <Input
                                    type="date"
                                    id="date"
                                    value={form.data.date}
                                    min={isLocked ? undefined : (window_.earliest || undefined)}
                                    max={isLocked ? undefined : (window_.latest || undefined)}
                                    onChange={(e) => handleDateChange(e.target.value)}
                                    disabled={isLocked}
                                    title={isLocked ? lockCopy : undefined}
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
                                            disabled={isLocked}
                                            title={isLocked ? lockCopy : undefined}
                                            style={{ colorScheme }}
                                            required
                                        />
                                    </Field>
                                    <Field label="End time" required error={form.errors.end_time || undefined}>
                                        <Input
                                            type="time"
                                            value={form.data.end_time}
                                            onChange={(e) => form.setData('end_time', e.target.value)}
                                            disabled={isLocked}
                                            title={isLocked ? lockCopy : undefined}
                                            style={{ colorScheme }}
                                            required
                                        />
                                    </Field>
                                </>
                            ) : (
                                <>
                                    <Field label="Hours" required htmlFor="hours" error={form.errors.hours || undefined}>
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
                                            disabled={isLocked}
                                            title={isLocked ? lockCopy : undefined}
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
                                            disabled={isLocked}
                                            title={isLocked ? lockCopy : undefined}
                                            placeholder="Enter minutes (0-59)"
                                        />
                                    </Field>
                                </>
                            )}
                        </div>

                        {/* Untracked time (gap-fill) — manual entries only */}
                        {!isLocked && (
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
                                    Stretches between the clock-in and clock-out that the tracker didn&apos;t record and
                                    aren&apos;t already logged elsewhere (this entry&apos;s own time is excluded).
                                </p>

                                {!day.has_attendance ? (
                                    <p className="inline-flex items-start gap-2 text-xs text-subtle">
                                        <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                        <span>No clock-in was recorded for this day — enter the times by hand above.</span>
                                    </p>
                                ) : openGaps.length === 0 ? (
                                    <p className="inline-flex items-start gap-2 text-xs text-subtle">
                                        <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                        <span>Nothing left to fill — the rest of the day is tracked, on a break, or already logged.</span>
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
                        )}

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
                                                    {isLocked ? ' · measured by the tracker' : ''}
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
                                {form.processing ? 'Updating...' : (isLocked ? 'Update Labels' : 'Update Entry')}
                            </Button>
                        </div>
                    </form>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
