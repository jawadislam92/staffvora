import React, { createContext, useCallback, useContext, useMemo, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import toast from 'react-hot-toast';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import {
    Button, Card, CardHeader, Badge, Field, Input, Select, Toggle,
    LockedFeature, LockBadge,
} from '@/Components/ui';
import {
    Activity,
    Bell,
    Briefcase,
    Building2,
    Calendar,
    CalendarDays,
    Camera,
    CheckCircle2,
    ChevronRight,
    Clock,
    DollarSign,
    Globe,
    Laptop,
    MessageSquare,
    PauseCircle,
    Tags,
    Timer,
    Clock3,
    UserPlus,
    Users,
    Wallet,
} from 'lucide-react';

const cx = (...a) => a.filter(Boolean).join(' ');

// Ziggy throws on a name it doesn't know, which would take this whole page
// down. Nav items that link to their own screen resolve through this so a
// not-yet-regenerated route manifest degrades to the literal path instead.
function safeRoute(name, fallback) {
    try {
        return route(name);
    } catch {
        return fallback;
    }
}

// Which settings sections are gated behind a plan feature flag. A section whose
// flag is off for the tenant is SHOWN, NOT HIDDEN: the real panel still renders,
// greyed and inert, under an "Upgrade to unlock" callout, so the customer can
// see exactly what they'd be buying. The server rejects the write independently
// (see SettingsController::enforceEntitlements) — the lock is presentation only.
const SECTION_LOCKS = {
    activity: 'activity_tracking',
    app_url: 'app_url_tracking',
    weekly_limit: 'weekly_limit',
    attendance_slack: 'attendance_alerts',
};

// One line per gated section on what unlocking actually buys. Sales copy lives
// here rather than in the component so the wording stays with the section.
const SECTION_LOCK_BLURBS = {
    activity_tracking: 'See a keyboard/mouse activity level on every tracked session.',
    app_url_tracking: 'Record the active application and browser URL alongside tracked time.',
    weekly_limit: 'Cap how many hours a person may track in a week, per person or team-wide.',
    attendance_alerts: 'Post clock-in and clock-out events straight into your Slack channel.',
};

/*
 * DEV_SAMPLE — offline visual-QA fixture. Used ONLY when `preview` is true so
 * the whole page renders from memory (no props, no network) with a mock
 * super-admin. Live behavior is unchanged.
 */
const DEV_SAMPLE = {
    workspaceType: 'agency',
    auth: {
        user: {
            id: 1,
            name: 'Jawad',
            email: 'jawad@staffvora.com',
            is_super_admin: true,
            permissions: [],
        },
    },
    entitlements: {
        activity_tracking: true,
        app_url_tracking: true,
        weekly_limit: true,
        attendance_alerts: true,
        max_screenshots_per_hour: 20,
        min_auto_pause_minutes: 3,
    },
    team: {
        screenshots_per_hour: 6,
        blur_screenshots: false,
        capture_enabled: true,
        activity_tracking_enabled: true,
        app_url_tracking_enabled: true,
        weekly_time_limit_hours: 40,
        auto_pause_minutes: 5,
        allow_offline_time: true,
        manual_time_overlap_policy: 'warn',
        notify_on_screenshot: false,
        week_starts_on: 'monday',
        currency_symbol: '$',
        desktop_auto_start: true,
        desktop_force_quit_on_idle: false,
        display_timezone: 'Asia/Karachi',
        time_format: '12',
        slack_connected: true,
        slack_clockin_enabled: true,
        slack_clockout_enabled: false,
        slack_attendance_channel: '#attendance',
        attendance_tracking_start_date: '2026-01-01',
        attendance_week_off_days: [0],
        attendance_auto_absent: true,
        attendance_holidays: ['2026-08-14', '2026-12-25'],
    },
    payPeriod: {
        cadence: 'weekly',
        anchor_date: '2026-01-05',
        options: [
            { key: 'weekly', label: 'Weekly' },
            { key: 'biweekly', label: 'Bi-weekly (every 2 weeks)' },
            { key: 'semimonthly', label: 'Semi-monthly (1st–15th, 16th–end)' },
            { key: 'monthly', label: 'Monthly' },
        ],
        preview: [
            { key: 'weekly:2026-07-13', label: '13 – 19 Jul 2026', start: '2026-07-13', end: '2026-07-19', is_current: false },
            { key: 'weekly:2026-07-20', label: '20 – 26 Jul 2026', start: '2026-07-20', end: '2026-07-26', is_current: false },
            { key: 'weekly:2026-07-27', label: '27 Jul – 2 Aug 2026', start: '2026-07-27', end: '2026-08-02', is_current: true },
        ],
        current: { key: 'weekly:2026-07-27', label: '27 Jul – 2 Aug 2026', start: '2026-07-27', end: '2026-08-02', is_current: true },
    },
    users: [
        { id: 1, name: 'Jawad', email: 'jawad@staffvora.com', role: 'super_admin', overrides: {} },
        { id: 2, name: 'Aqsa Malik', email: 'aqsa@staffvora.com', role: 'member', overrides: { override_screenshots: true, capture_enabled: true, screenshots_per_hour: 12, blur_screenshots: true } },
        { id: 3, name: 'Bilal Ahmed', email: 'bilal@staffvora.com', role: 'member', overrides: {} },
        { id: 4, name: 'Sana Riaz', email: 'sana@staffvora.com', role: 'manager', overrides: {} },
    ],
};

const TIMEZONES = [
    'Asia/Karachi', 'Asia/Dubai', 'Asia/Kolkata', 'Asia/Dhaka', 'Asia/Riyadh',
    'Europe/London', 'Europe/Berlin', 'America/New_York', 'America/Chicago',
    'America/Los_Angeles', 'Australia/Sydney', 'UTC',
];

// ---------------------------------------------------------------------------
// WORKSPACE TYPE — the business-model hint, and the only place a customer can
// change it after the first-run wizard.
//
// ⚠️ NOTHING HERE IS A CAPABILITY. Picking a type changes vocabulary and which
// optional modules are worth OFFERING. It grants no feature — the server
// endpoint writes one string column and nothing else. That is stated on screen
// in as many words, because a control that looks like it unlocks something and
// doesn't is worse than no control at all.
//
// Note what these descriptions deliberately do NOT say: no "Upwork", no
// "marketplace". A workspace that tracks its own employees should never meet
// that vocabulary — it is somebody else's business model, not a feature they
// forgot to buy. It appears once "Client services agency" is the SELECTED type,
// in the panel below, which is exactly the moment it becomes relevant.
// ---------------------------------------------------------------------------
const WORKSPACE_TYPES = [
    {
        value: 'general',
        label: 'In-house team / company',
        icon: Building2,
        blurb: 'You track your own employees against internal work.',
        term: 'Work is filed under Projects.',
    },
    {
        value: 'agency',
        label: 'Client services agency',
        icon: Briefcase,
        blurb: 'You deliver work to external clients and bill against it.',
        term: 'Work is filed under Clients.',
    },
    {
        value: 'bpo',
        label: 'BPO / Call center',
        icon: Users,
        blurb: 'Shift-based attendance for large operational teams.',
        term: 'Work is filed under Accounts.',
    },
    {
        value: 'freelancer',
        label: 'Freelancer',
        icon: UserPlus,
        blurb: 'Just you, tracking against your own clients.',
        term: 'Work is filed under Clients.',
    },
];

const CATEGORIES = [
    { key: 'workspace_type', label: 'Workspace type', icon: Building2, summary: (t, pp, wt) => (WORKSPACE_TYPES.find((o) => o.value === wt) || WORKSPACE_TYPES[0]).label.split(' /')[0] },
    { key: 'display', label: 'Time zone & format', icon: Clock3, summary: (t) => `${(t.display_timezone || 'Asia/Karachi').split('/').pop()} · ${t.time_format === '24' ? '24h' : '12h'}` },
    { key: 'screenshots', label: 'Screenshots', icon: Camera, summary: (t) => `${t.screenshots_per_hour}/hr` },
    { key: 'activity', label: 'Activity Level tracking', icon: Activity, summary: (t) => (t.activity_tracking_enabled ? 'Yes' : 'No') },
    { key: 'app_url', label: 'App & URL tracking', icon: Globe, summary: (t) => (t.app_url_tracking_enabled ? 'On' : 'Off') },
    { key: 'weekly_limit', label: 'Weekly time limit', icon: Timer, summary: (t) => (t.weekly_time_limit_hours ? `${t.weekly_time_limit_hours}h/wk` : 'No limit') },
    { key: 'auto_pause', label: 'Auto-pause tracking after', icon: PauseCircle, summary: (t) => `${t.auto_pause_minutes} min` },
    { key: 'offline_time', label: 'Allow adding Offline Time', icon: Clock, summary: (t) => (t.allow_offline_time ? 'Yes' : 'No') },
    { key: 'notify_screenshot', label: 'Notify when screenshot is taken', icon: Bell, summary: (t) => (t.notify_on_screenshot ? 'Yes' : 'No') },
    { key: 'attendance_policy', label: 'Attendance policy', icon: CalendarDays, summary: (t) => (t.attendance_auto_absent ? 'Auto-absent on' : 'Auto-absent off') },
    // The payroll window a timesheet covers. Its value lives in its own
    // `payPeriod` prop (not the tracker settings payload), so the summary reads
    // the second argument.
    { key: 'pay_period', label: 'Pay period', icon: Wallet, summary: (t, pp) => (pp?.options || []).find((o) => o.key === pp?.cadence)?.label?.split(' (')[0] || 'Weekly' },
    { key: 'attendance_slack', label: 'Attendance Slack alerts', icon: MessageSquare, summary: (t) => [t.slack_clockin_enabled && 'in', t.slack_clockout_enabled && 'out'].filter(Boolean).join(' + ') || 'Off' },
    { key: 'week_starts_on', label: 'Week starts on', icon: Calendar, summary: (t) => (t.week_starts_on === 'sunday' ? 'Sun' : 'Mon') },
    { key: 'currency', label: 'Currency symbol', icon: DollarSign, summary: (t) => t.currency_symbol },
    { key: 'desktop_app', label: 'Employee desktop application settings', icon: Laptop, summary: () => '' },
    // A `route` key turns the nav item into a link to its own screen instead of
    // an in-page panel. Work categories is the workspace's editable "what the
    // work was" list — it needs a table, ordering and an archive tab, so it
    // lives at /settings/work-categories.
    { key: 'work_categories', label: 'Work categories', icon: Tags, route: 'settings.work-categories', path: '/settings/work-categories', summary: () => '' },
];

const CATEGORY_OVERRIDE_KEY = {
    screenshots: 'override_screenshots',
    activity: 'override_activity',
    app_url: 'override_app_url',
    weekly_limit: 'override_weekly_limit',
    auto_pause: 'override_auto_pause',
    offline_time: 'override_offline_time',
    notify_screenshot: 'override_notify_screenshot',
    desktop_app: 'override_desktop_app',
    display: 'override_display',
};

// Native radio/checkbox tinted with the electric-blue accent.
const radioCls = 'h-4 w-4 shrink-0 border-line text-accent accent-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app';

function SectionShell({ title, blurb, children }) {
    return (
        <Card
            header={<CardHeader title={title} description={blurb} />}
        >
            <div className="space-y-5">{children}</div>
        </Card>
    );
}

// Saves fire in the background — the optimistic local state IS the UI, so
// there's no Inertia visit, no progress bar, and no heavy props reload.
// On a rare failure a hard reload resyncs everything from the server
// (router.reload only refreshes props, which local state ignores).
function resyncAfterFailure() {
    toast.error('Could not save that change — reloading current settings.');
    setTimeout(() => window.location.reload(), 1200);
}

function patchTeam(values) {
    axios.put(route('settings.team.update'), values).catch(resyncAfterFailure);
}

function patchUser(user, category, enabled, values) {
    axios.put(
        route('settings.user.update', { user: user.id }),
        { category, enabled, values: values || {} }
    ).catch(resyncAfterFailure);
}

// The override rows render deep inside each section, but the users list
// lives in page state — this dispatch lets a row flip its toggle in that
// state so the UI shows the save (the axios call never refreshes props).
// A no-op `saveTeam` is injected in preview so nothing hits the network.
const OverridesDispatchContext = createContext(() => {});
const SaveContext = createContext({ saveTeam: patchTeam, saveUser: patchUser });

function ScreenshotsSection({ team, users, setTeam, maxShots }) {
    const { saveTeam } = useContext(SaveContext);
    const captureOff = !team.capture_enabled || team.screenshots_per_hour === 0;
    const cap = maxShots ?? 60;
    const rateOptions = [1, 2, 3, 4, 6, 8, 10, 12, 15, 20, 30].filter((n) => n <= cap);
    return (
        <SectionShell
            title="Screenshots"
            blurb={<>How frequently screenshots will be taken.<br />This number is an average since screenshots are taken at random intervals.</>}
        >
            {maxShots != null && (
                <p className="text-xs text-muted">Your plan allows up to <strong className="text-body">{maxShots}</strong> screenshots per hour.</p>
            )}
            <div className="flex flex-wrap items-center gap-6 text-sm text-body">
                <label className="flex items-center gap-2">
                    <input
                        type="radio"
                        className={radioCls}
                        checked={!captureOff}
                        onChange={() => {
                            const next = { ...team, capture_enabled: true, screenshots_per_hour: team.screenshots_per_hour || 6 };
                            setTeam(next);
                            saveTeam(next);
                        }}
                    />
                    <span>Take</span>
                    <Select
                        className="w-auto"
                        value={team.screenshots_per_hour || 6}
                        disabled={captureOff}
                        onChange={(e) => {
                            const next = { ...team, screenshots_per_hour: Number(e.target.value) };
                            setTeam(next);
                            saveTeam(next);
                        }}
                    >
                        {rateOptions.map((n) => (
                            <option key={n} value={n}>{n}</option>
                        ))}
                    </Select>
                    <span>screenshots per hour</span>
                    <Select
                        className="w-auto"
                        value={team.blur_screenshots ? 'allow' : 'disallow'}
                        disabled={captureOff}
                        onChange={(e) => {
                            const next = { ...team, blur_screenshots: e.target.value === 'allow' };
                            setTeam(next);
                            saveTeam(next);
                        }}
                    >
                        <option value="disallow">Disallow blur</option>
                        <option value="allow">Allow blur</option>
                    </Select>
                </label>
                <label className="flex items-center gap-2">
                    <input
                        type="radio"
                        className={radioCls}
                        checked={captureOff}
                        onChange={() => {
                            const next = { ...team, capture_enabled: false, screenshots_per_hour: 0 };
                            setTeam(next);
                            saveTeam(next);
                        }}
                    />
                    <span>Do not take</span>
                </label>
            </div>

            <IndividualSettings
                category="screenshots"
                users={users}
                flagKey="override_screenshots"
                defaultValues={{
                    screenshots_per_hour: team.screenshots_per_hour,
                    blur_screenshots: team.blur_screenshots,
                    capture_enabled: team.capture_enabled,
                }}
                editor={(override, setValues) => {
                    const off = !override.capture_enabled || override.screenshots_per_hour === 0;
                    return (
                        <div className="flex flex-wrap items-center gap-4 text-xs text-body">
                            <label className="flex items-center gap-1.5">
                                <input
                                    type="radio"
                                    className={radioCls}
                                    checked={!off}
                                    onChange={() => setValues({
                                        capture_enabled: true,
                                        screenshots_per_hour: override.screenshots_per_hour || team.screenshots_per_hour || 6,
                                        blur_screenshots: override.blur_screenshots ?? team.blur_screenshots,
                                    })}
                                />
                                Take
                                <Select
                                    className="w-auto py-1 text-xs"
                                    value={override.screenshots_per_hour || team.screenshots_per_hour || 6}
                                    disabled={off}
                                    onChange={(e) => setValues({
                                        capture_enabled: true,
                                        screenshots_per_hour: Number(e.target.value),
                                        blur_screenshots: override.blur_screenshots ?? team.blur_screenshots,
                                    })}
                                >
                                    {[1, 2, 3, 4, 6, 8, 10, 12, 15, 20, 30].map((n) => (
                                        <option key={n} value={n}>{n}</option>
                                    ))}
                                </Select>
                                / hr
                                <Select
                                    className="w-auto py-1 text-xs"
                                    value={override.blur_screenshots ? 'allow' : 'disallow'}
                                    disabled={off}
                                    onChange={(e) => setValues({
                                        capture_enabled: true,
                                        screenshots_per_hour: override.screenshots_per_hour || team.screenshots_per_hour || 6,
                                        blur_screenshots: e.target.value === 'allow',
                                    })}
                                >
                                    <option value="disallow">Disallow blur</option>
                                    <option value="allow">Allow blur</option>
                                </Select>
                            </label>
                            <label className="flex items-center gap-1.5">
                                <input
                                    type="radio"
                                    className={radioCls}
                                    checked={off}
                                    onChange={() => setValues({
                                        capture_enabled: false,
                                        screenshots_per_hour: 0,
                                        blur_screenshots: override.blur_screenshots ?? team.blur_screenshots,
                                    })}
                                />
                                Do not take
                            </label>
                        </div>
                    );
                }}
            />
        </SectionShell>
    );
}

function BooleanSection({ team, users, setTeam, title, blurb, field, category, label = 'Track', offLabel = 'Do not track' }) {
    const { saveTeam } = useContext(SaveContext);
    return (
        <SectionShell title={title} blurb={blurb}>
            <div className="flex flex-wrap items-center gap-6 text-sm text-body">
                <label className="flex items-center gap-2">
                    <input
                        type="radio"
                        className={radioCls}
                        checked={!!team[field]}
                        onChange={() => {
                            const next = { ...team, [field]: true };
                            setTeam(next);
                            saveTeam(next);
                        }}
                    />
                    {label}
                </label>
                <label className="flex items-center gap-2">
                    <input
                        type="radio"
                        className={radioCls}
                        checked={!team[field]}
                        onChange={() => {
                            const next = { ...team, [field]: false };
                            setTeam(next);
                            saveTeam(next);
                        }}
                    />
                    {offLabel}
                </label>
            </div>

            <IndividualSettings
                category={category}
                users={users}
                flagKey={CATEGORY_OVERRIDE_KEY[category]}
                defaultValues={{ [field]: team[field] }}
                editor={(override, setValues) => (
                    <div className="flex flex-wrap items-center gap-4 text-xs text-body">
                        <label className="flex items-center gap-1.5">
                            <input
                                type="radio"
                                className={radioCls}
                                checked={!!override[field]}
                                onChange={() => setValues({ [field]: true })}
                            />
                            {label}
                        </label>
                        <label className="flex items-center gap-1.5">
                            <input
                                type="radio"
                                className={radioCls}
                                checked={!override[field]}
                                onChange={() => setValues({ [field]: false })}
                            />
                            {offLabel}
                        </label>
                    </div>
                )}
            />
        </SectionShell>
    );
}

function WeeklyLimitSection({ team, users, setTeam }) {
    const { saveTeam } = useContext(SaveContext);
    const limit = team.weekly_time_limit_hours;
    const enabled = limit !== null && limit !== undefined && limit !== '';
    return (
        <SectionShell
            title="Weekly time limit"
            blurb={<>Number of hours your employees are allowed to work. The tracking will stop when the limit is reached.<br />The time zone for the time limit is always UTC.</>}
        >
            <div className="flex flex-wrap items-center gap-6 text-sm text-body">
                <label className="flex items-center gap-2">
                    <input
                        type="radio"
                        className={radioCls}
                        checked={enabled}
                        onChange={() => {
                            const next = { ...team, weekly_time_limit_hours: limit || 40 };
                            setTeam(next);
                            saveTeam(next);
                        }}
                    />
                    <span>Limit to</span>
                    <Input
                        type="number"
                        min={0}
                        max={168}
                        value={enabled ? limit : 0}
                        disabled={!enabled}
                        onChange={(e) => {
                            const next = { ...team, weekly_time_limit_hours: Number(e.target.value) };
                            setTeam(next);
                            saveTeam(next);
                        }}
                        className="w-24"
                    />
                    <span>hours per week</span>
                </label>
                <label className="flex items-center gap-2">
                    <input
                        type="radio"
                        className={radioCls}
                        checked={!enabled}
                        onChange={() => {
                            const next = { ...team, weekly_time_limit_hours: null };
                            setTeam(next);
                            saveTeam(next);
                        }}
                    />
                    Do not limit
                </label>
            </div>
            <IndividualSettings
                category="weekly_limit"
                users={users}
                flagKey="override_weekly_limit"
                defaultValues={{ weekly_time_limit_hours: team.weekly_time_limit_hours }}
                editor={(override, setValues) => {
                    const en = override.weekly_time_limit_hours !== null && override.weekly_time_limit_hours !== undefined;
                    return (
                        <div className="flex flex-wrap items-center gap-4 text-xs text-body">
                            <label className="flex items-center gap-1.5">
                                <input
                                    type="radio"
                                    className={radioCls}
                                    checked={en}
                                    onChange={() => setValues({ weekly_time_limit_hours: override.weekly_time_limit_hours || 40 })}
                                />
                                Limit to
                                <Input
                                    type="number"
                                    min={0}
                                    max={168}
                                    value={en ? override.weekly_time_limit_hours : 0}
                                    disabled={!en}
                                    onChange={(e) => setValues({ weekly_time_limit_hours: Number(e.target.value) })}
                                    className="w-20 py-1 text-xs"
                                />
                                hours/week
                            </label>
                            <label className="flex items-center gap-1.5">
                                <input
                                    type="radio"
                                    className={radioCls}
                                    checked={!en}
                                    onChange={() => setValues({ weekly_time_limit_hours: null })}
                                />
                                Do not limit
                            </label>
                        </div>
                    );
                }}
            />
        </SectionShell>
    );
}

function AutoPauseSection({ team, users, setTeam, minMinutes }) {
    const { saveTeam } = useContext(SaveContext);
    const enabled = team.auto_pause_minutes > 0;
    const floor = minMinutes ?? 1;
    return (
        <SectionShell
            title="Auto-pause tracking after"
            blurb="Tracking will automatically pause after the specified period of inactivity and will automatically resume when user becomes active again."
        >
            {minMinutes != null && minMinutes > 1 && (
                <p className="text-xs text-muted">Your plan&apos;s minimum auto-pause is <strong className="text-body">{minMinutes}</strong> minutes. Upgrade for a tighter idle limit.</p>
            )}
            <div className="flex flex-wrap items-center gap-6 text-sm text-body">
                <label className="flex items-center gap-2">
                    <input
                        type="radio"
                        className={radioCls}
                        checked={enabled}
                        onChange={() => {
                            const next = { ...team, auto_pause_minutes: team.auto_pause_minutes || floor };
                            setTeam(next);
                            saveTeam(next);
                        }}
                    />
                    <span>Pause after</span>
                    <Input
                        type="number"
                        min={floor}
                        max={120}
                        value={enabled ? team.auto_pause_minutes : floor}
                        disabled={!enabled}
                        onChange={(e) => {
                            const next = { ...team, auto_pause_minutes: Number(e.target.value) };
                            setTeam(next);
                            saveTeam(next);
                        }}
                        className="w-24"
                    />
                    <span>minutes of user inactivity</span>
                </label>
                <label className="flex items-center gap-2">
                    <input
                        type="radio"
                        className={radioCls}
                        checked={!enabled}
                        onChange={() => {
                            const next = { ...team, auto_pause_minutes: 0 };
                            setTeam(next);
                            saveTeam(next);
                        }}
                    />
                    Do not pause
                </label>
            </div>
            <IndividualSettings
                category="auto_pause"
                users={users}
                flagKey="override_auto_pause"
                defaultValues={{ auto_pause_minutes: team.auto_pause_minutes }}
                editor={(override, setValues) => {
                    const mins = Number(override.auto_pause_minutes || 0);
                    const en = mins > 0;
                    return (
                        <div className="flex flex-wrap items-center gap-4 text-xs text-body">
                            <label className="flex items-center gap-1.5">
                                <input
                                    type="radio"
                                    className={radioCls}
                                    checked={en}
                                    onChange={() => setValues({ auto_pause_minutes: mins || team.auto_pause_minutes || 5 })}
                                />
                                Pause after
                                <Input
                                    type="number"
                                    min={1}
                                    max={120}
                                    value={en ? mins : 5}
                                    disabled={!en}
                                    onChange={(e) => setValues({ auto_pause_minutes: Number(e.target.value) })}
                                    className="w-20 py-1 text-xs"
                                />
                                min idle
                            </label>
                            <label className="flex items-center gap-1.5">
                                <input
                                    type="radio"
                                    className={radioCls}
                                    checked={!en}
                                    onChange={() => setValues({ auto_pause_minutes: 0 })}
                                />
                                Do not pause
                            </label>
                        </div>
                    );
                }}
            />
        </SectionShell>
    );
}

function DisplaySection({ team, users, setTeam }) {
    const { saveTeam } = useContext(SaveContext);
    return (
        <SectionShell
            title="Time zone & format"
            blurb="All dates and times shown across the website and desktop app render in this time zone and format. Set this to where your team works (e.g. Asia/Karachi for Pakistan)."
        >
            <div className="flex flex-wrap items-end gap-6 text-sm">
                <Field label="Time zone" className="w-auto">
                    <Select
                        className="w-auto"
                        value={team.display_timezone || 'Asia/Karachi'}
                        onChange={(e) => {
                            const next = { ...team, display_timezone: e.target.value };
                            setTeam(next);
                            saveTeam(next);
                        }}
                    >
                        {TIMEZONES.map((tz) => (
                            <option key={tz} value={tz}>{tz}</option>
                        ))}
                    </Select>
                </Field>
                <Field label="Time format" className="w-auto">
                    <div className="flex items-center gap-4 text-body">
                        {[['12', '12-hour (1:30 PM)'], ['24', '24-hour (13:30)']].map(([val, label]) => (
                            <label key={val} className="flex items-center gap-1.5">
                                <input
                                    type="radio"
                                    className={radioCls}
                                    checked={(team.time_format || '12') === val}
                                    onChange={() => {
                                        const next = { ...team, time_format: val };
                                        setTeam(next);
                                        saveTeam(next);
                                    }}
                                />
                                {label}
                            </label>
                        ))}
                    </div>
                </Field>
            </div>

            <IndividualSettings
                category="display"
                users={users}
                flagKey="override_display"
                defaultValues={{ display_timezone: team.display_timezone || 'Asia/Karachi', time_format: team.time_format || '12' }}
                editor={(override, setValues) => (
                    <div className="flex flex-wrap items-center gap-4 text-xs">
                        <Select
                            className="w-auto py-1 text-xs"
                            value={override.display_timezone || team.display_timezone || 'Asia/Karachi'}
                            onChange={(e) => setValues({ display_timezone: e.target.value, time_format: override.time_format || team.time_format || '12' })}
                        >
                            {TIMEZONES.map((tz) => (
                                <option key={tz} value={tz}>{tz}</option>
                            ))}
                        </Select>
                        <Select
                            className="w-auto py-1 text-xs"
                            value={override.time_format || team.time_format || '12'}
                            onChange={(e) => setValues({ display_timezone: override.display_timezone || team.display_timezone || 'Asia/Karachi', time_format: e.target.value })}
                        >
                            <option value="12">12-hour</option>
                            <option value="24">24-hour</option>
                        </Select>
                    </div>
                )}
            />
        </SectionShell>
    );
}

/**
 * WORKSPACE TYPE — change the business model after signup.
 *
 * The gap this closes: `workspace_type` could only ever be chosen in the
 * first-run wizard. A workspace that signed up as an in-house team and later
 * started taking client work through a marketplace had no way to say so.
 *
 * ⚠️ THE HONESTY CONTRACT. Selecting "Client services agency" changes wording
 * and makes the Agency toolkit relevant enough to show. It does NOT switch the
 * toolkit on, and the panel says that in plain words with the price path next
 * to it. If this control silently granted a paid add-on, every customer could
 * help themselves to one from a settings screen.
 *
 * @param {string} value              Current workspace_type.
 * @param {Function} onChange         Persist a new type.
 * @param {boolean} entitled          Whether `upwork_profiles` is actually on.
 * @param {boolean} preview           Offline design-QA mode: no network.
 */
function WorkspaceTypeSection({ value, onChange, entitled, preview }) {
    const [saving, setSaving] = useState(null);

    const pick = (next) => {
        if (next === value) return;
        setSaving(next);
        onChange(next, () => setSaving(null));
    };

    return (
        <SectionShell
            title="Workspace type"
            blurb="What kind of team this workspace is for. It sets the wording used across the app — Projects, Clients or Accounts — and which optional modules are offered to you. Change it whenever your business does; nothing is deleted."
        >
            <div className="grid gap-3 sm:grid-cols-2">
                {WORKSPACE_TYPES.map((t) => {
                    const Icon = t.icon;
                    const selected = value === t.value;
                    const busy = saving === t.value;
                    return (
                        <button
                            key={t.value}
                            type="button"
                            onClick={() => pick(t.value)}
                            aria-pressed={selected}
                            disabled={!!saving}
                            className={cx(
                                'flex items-start gap-3 rounded-xl border p-4 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app disabled:opacity-70',
                                selected
                                    ? 'border-accent bg-accent/10 ring-1 ring-accent'
                                    : 'border-line bg-surface-2 hover:border-accent/50'
                            )}
                        >
                            <span className={cx(
                                'mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg',
                                selected ? 'bg-accent text-white' : 'bg-surface text-muted'
                            )}
                            >
                                <Icon className="h-5 w-5" />
                            </span>
                            <span className="min-w-0">
                                <span className="flex items-center gap-1.5 text-sm font-semibold text-body">
                                    {t.label}
                                    {selected && <CheckCircle2 className="h-4 w-4 text-accent" />}
                                    {busy && <span className="text-xs font-normal text-subtle">saving…</span>}
                                </span>
                                <span className="mt-0.5 block text-xs text-subtle">{t.blurb}</span>
                                <span className="mt-1 block text-xs text-muted">{t.term}</span>
                            </span>
                        </button>
                    );
                })}
            </div>

            {/* Agency-only. Marketplace vocabulary appears here and nowhere else
                in a workspace that has not chosen to be an agency. */}
            {value === 'agency' && (
                <div className="rounded-xl border border-line bg-surface-2 p-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h3 className="text-sm font-semibold text-body">Agency toolkit</h3>
                        <Badge tone={entitled ? 'success' : 'neutral'} dot>
                            {entitled ? 'Active' : 'Not included'}
                        </Badge>
                    </div>

                    {entitled ? (
                        <p className="mt-2 max-w-prose text-xs leading-5 text-muted">
                            Your workspace has the Agency toolkit. <strong className="text-body">Upwork Profiles</strong> is
                            switched on — it appears in the main menu, and you can attribute every client and every
                            tracked hour to the seller account it was earned on.
                        </p>
                    ) : (
                        <>
                            <p className="mt-2 max-w-prose text-xs leading-5 text-muted">
                                Calling yourself an agency changes the wording on this screen.{' '}
                                <strong className="text-body">It does not switch anything on.</strong> The Agency toolkit
                                is a paid add-on: it adds <strong className="text-body">Upwork Profiles</strong> (attribute
                                clients and tracked hours to the seller account they were earned on) and{' '}
                                <strong className="text-body">Machine compliance</strong>.
                            </p>
                            <p className="mt-2 max-w-prose text-xs leading-5 text-subtle">
                                Billing shows the price for your current seat count, prorated to your renewal date,
                                before you confirm anything. Nothing is charged from here.
                            </p>
                            <div className="mt-3">
                                <Button
                                    as={Link}
                                    href={preview ? '#' : safeRoute('billing.index', '/billing')}
                                    size="sm"
                                >
                                    See the Agency toolkit in Billing
                                </Button>
                            </div>
                        </>
                    )}
                </div>
            )}
        </SectionShell>
    );
}

function WeekStartsSection({ team, setTeam }) {
    const { saveTeam } = useContext(SaveContext);
    return (
        <SectionShell title="Week starts on" blurb="Used for the Report week ranges and the Attendance grid.">
            <div className="flex items-center gap-6 text-sm text-body">
                {['monday', 'sunday'].map((day) => (
                    <label key={day} className="flex items-center gap-2 capitalize">
                        <input
                            type="radio"
                            className={radioCls}
                            checked={team.week_starts_on === day}
                            onChange={() => {
                                const next = { ...team, week_starts_on: day };
                                setTeam(next);
                                saveTeam(next);
                            }}
                        />
                        {day}
                    </label>
                ))}
            </div>
        </SectionShell>
    );
}

function CurrencySection({ team, setTeam }) {
    const { saveTeam } = useContext(SaveContext);
    return (
        <SectionShell title="Currency symbol" blurb="Cosmetic. Shown in cost columns when hourly rates are configured.">
            <Input
                type="text"
                maxLength={8}
                value={team.currency_symbol}
                onChange={(e) => setTeam({ ...team, currency_symbol: e.target.value })}
                onBlur={() => saveTeam(team)}
                className="w-24"
            />
        </SectionShell>
    );
}

function DesktopAppSection({ team, users, setTeam }) {
    const { saveTeam } = useContext(SaveContext);
    return (
        <SectionShell
            title="Employee desktop application settings"
            blurb="Default behaviour for the desktop tracker on employees' machines."
        >
            <div className="rounded-lg border border-warning/40 bg-warning-soft px-4 py-3 text-xs leading-5 text-warning">
                <strong>How these apply:</strong> each employee also has a “Launch on startup” switch
                inside the desktop app itself, which they control on their own machine. Central enforcement
                of the options below reaches a machine only after that employee updates to the latest
                desktop app. “Force quit on prolonged idle” is not enforced by the app yet — tracking
                already auto-pauses on idle (see <em>Auto-pause</em>).
            </div>
            <div className="space-y-3">
                <Toggle
                    label="Launch on system startup (default)"
                    checked={!!team.desktop_auto_start}
                    onChange={(v) => {
                        const next = { ...team, desktop_auto_start: v };
                        setTeam(next);
                        saveTeam(next);
                    }}
                />
                <Toggle
                    label="Force quit on prolonged idle"
                    checked={!!team.desktop_force_quit_on_idle}
                    onChange={(v) => {
                        const next = { ...team, desktop_force_quit_on_idle: v };
                        setTeam(next);
                        saveTeam(next);
                    }}
                />
            </div>
            <IndividualSettings
                category="desktop_app"
                users={users}
                flagKey="override_desktop_app"
                defaultValues={{
                    desktop_auto_start: team.desktop_auto_start,
                    desktop_force_quit_on_idle: team.desktop_force_quit_on_idle,
                }}
                editor={(override, setValues) => (
                    <div className="space-y-2">
                        <Toggle
                            label="Launch on system startup"
                            checked={!!override.desktop_auto_start}
                            onChange={(v) => setValues({
                                desktop_auto_start: v,
                                desktop_force_quit_on_idle: !!override.desktop_force_quit_on_idle,
                            })}
                        />
                        <Toggle
                            label="Force quit on prolonged idle"
                            checked={!!override.desktop_force_quit_on_idle}
                            onChange={(v) => setValues({
                                desktop_auto_start: !!override.desktop_auto_start,
                                desktop_force_quit_on_idle: v,
                            })}
                        />
                    </div>
                )}
            />
        </SectionShell>
    );
}

function AttendanceSlackSection({ team, setTeam, preview }) {
    const { saveTeam } = useContext(SaveContext);
    const set = (patch) => {
        const next = { ...team, ...patch };
        setTeam(next);
        saveTeam(next);
    };
    const [token, setToken] = useState('');
    const [connected, setConnected] = useState(!!team.slack_connected);
    const [busy, setBusy] = useState(false);

    const connect = () => {
        if (!token.trim()) return;
        if (preview) { setConnected(true); setToken(''); toast.success('Slack connected.'); return; }
        setBusy(true);
        axios.put(route('settings.slack.update'), { slack_bot_token: token.trim() })
            .then((r) => { setConnected(r.data?.connected ?? true); setToken(''); toast.success('Slack connected.'); })
            .catch(() => toast.error('Could not save the Slack token.'))
            .finally(() => setBusy(false));
    };
    const disconnect = () => {
        if (preview) { setConnected(false); toast.success('Slack disconnected.'); return; }
        setBusy(true);
        axios.put(route('settings.slack.update'), { disconnect: true })
            .then(() => { setConnected(false); toast.success('Slack disconnected.'); })
            .catch(() => toast.error('Could not disconnect Slack.'))
            .finally(() => setBusy(false));
    };

    return (
        <SectionShell
            title="Attendance Slack alerts"
            blurb={<>Post a short message to a Slack channel when someone clocks in or out. Turn each on independently.<br />Connect your own Slack below; the bot must be a member of the channel you choose.</>}
        >
            <div className="rounded-lg border border-line bg-surface-2 p-4">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <p className="text-sm font-semibold text-body">Slack connection</p>
                        <p className="text-xs text-subtle">
                            {connected ? 'Connected — your workspace bot token is saved.' : 'Not connected. Paste your Slack bot token (xoxb-…) to enable posts.'}
                        </p>
                    </div>
                    <Badge tone={connected ? 'success' : 'neutral'} dot>
                        {connected ? 'Connected' : 'Not connected'}
                    </Badge>
                </div>
                <div className="mt-3 flex flex-wrap items-center gap-2">
                    <Input
                        type="password"
                        value={token}
                        onChange={(e) => setToken(e.target.value)}
                        placeholder={connected ? 'Paste a new token to replace' : 'xoxb-your-bot-token'}
                        className="w-72"
                        autoComplete="off"
                    />
                    <Button type="button" size="sm" onClick={connect} disabled={busy || !token.trim()}>
                        {connected ? 'Replace' : 'Connect'}
                    </Button>
                    {connected && (
                        <Button type="button" size="sm" variant="secondary" onClick={disconnect} disabled={busy}>
                            Disconnect
                        </Button>
                    )}
                </div>
            </div>

            <div className="space-y-4 text-sm text-body">
                <Toggle
                    checked={!!team.slack_clockin_enabled}
                    onChange={(v) => set({ slack_clockin_enabled: v })}
                    label={<span>Post to Slack when someone <strong>clocks in</strong></span>}
                />
                <Toggle
                    checked={!!team.slack_clockout_enabled}
                    onChange={(v) => set({ slack_clockout_enabled: v })}
                    label={<span>Post to Slack when someone <strong>clocks out</strong></span>}
                />
                <div className="pt-2">
                    <Field
                        label="Attendance channel"
                        hint="Channel name (e.g. #attendance) or ID. Leave blank to use the server default."
                        className="max-w-xs"
                    >
                        <Input
                            type="text"
                            value={team.slack_attendance_channel || ''}
                            onChange={(e) => setTeam({ ...team, slack_attendance_channel: e.target.value })}
                            onBlur={() => saveTeam(team)}
                            placeholder="#attendance"
                        />
                    </Field>
                </div>
            </div>
        </SectionShell>
    );
}

function IndividualSettings({ category, users, flagKey, defaultValues, editor }) {
    const updateOverrides = useContext(OverridesDispatchContext);
    const { saveUser } = useContext(SaveContext);
    return (
        <div className="space-y-3 pt-6">
            <h3 className="text-base font-semibold text-body">Individual settings</h3>
            <p className="text-xs text-subtle">If enabled, the individual setting will be used instead of the team setting.</p>
            <div className="divide-y divide-line border-t border-line">
                {users.length === 0 && (
                    <p className="py-4 text-sm text-subtle">No team members yet.</p>
                )}
                {users.map((user) => {
                    // Super Admins aren't tracked, so monitoring overrides never
                    // apply to them — they're shown read-only so the owner still
                    // sees the full roster (and isn't left wondering who's missing).
                    const isExempt = user.role === 'super_admin';
                    const enabled = !isExempt && !!user.overrides?.[flagKey];
                    const setEnabled = (en) => {
                        updateOverrides(user.id, { [flagKey]: en, ...(en ? defaultValues : {}) });
                        saveUser(user, category, en, en ? defaultValues : {});
                    };
                    const setValues = (values) => {
                        updateOverrides(user.id, values);
                        saveUser(user, category, true, values);
                    };
                    return (
                        <div key={user.id} className="space-y-2 py-3">
                            <div className="flex items-center gap-3">
                                <Toggle checked={enabled} onChange={setEnabled} disabled={isExempt} />
                                <span className="flex-1 text-sm text-muted">{user.name}</span>
                                {isExempt && (
                                    <Badge
                                        tone="neutral"
                                        title="Super Admins aren't monitored, so team/individual tracking settings don't apply to them."
                                    >
                                        Exempt · Super Admin
                                    </Badge>
                                )}
                            </div>
                            {!isExempt && enabled && editor && (
                                <div className="ml-12 rounded-md bg-surface-2 px-3 py-2">
                                    {editor(user.overrides || {}, setValues)}
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

const WEEKDAYS = [
    { n: 0, label: 'Sun' }, { n: 1, label: 'Mon' }, { n: 2, label: 'Tue' },
    { n: 3, label: 'Wed' }, { n: 4, label: 'Thu' }, { n: 5, label: 'Fri' }, { n: 6, label: 'Sat' },
];

// Phase 5F — the humane attendance policy. Saves on its own endpoint so the
// owner can stop the grid from auto-marking people Absent.
function AttendanceSection({ team, setTeam, preview }) {
    const [start, setStart] = useState(team.attendance_tracking_start_date || '');
    const [weekOff, setWeekOff] = useState(team.attendance_week_off_days || [0]);
    const [autoAbsent, setAutoAbsent] = useState(team.attendance_auto_absent ?? true);
    const [holidays, setHolidays] = useState(team.attendance_holidays || []);
    const [newHoliday, setNewHoliday] = useState('');
    const [saving, setSaving] = useState(false);

    const toggleDay = (n) => setWeekOff((prev) => (prev.includes(n) ? prev.filter((d) => d !== n) : [...prev, n].sort()));
    const addHoliday = () => {
        if (newHoliday && !holidays.includes(newHoliday)) {
            setHolidays([...holidays, newHoliday].sort());
            setNewHoliday('');
        }
    };

    const save = () => {
        const payload = {
            attendance_tracking_start_date: start || null,
            attendance_week_off_days: weekOff,
            attendance_holidays: holidays,
            attendance_auto_absent: autoAbsent,
        };
        if (preview) {
            setTeam({ ...team, ...payload });
            toast.success('Attendance policy saved.');
            return;
        }
        setSaving(true);
        router.put(route('settings.attendance.update'), payload, {
            preserveScroll: true,
            onSuccess: () => { setTeam({ ...team, ...payload }); toast.success('Attendance policy saved.'); },
            onError: () => toast.error('Could not save the attendance policy.'),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <SectionShell
            title="Attendance policy"
            blurb="Controls how the monthly grid decides Present / Absent — so a new workspace never brands people Absent for days it wasn't even tracking."
        >
            <div className="space-y-5 text-sm">
                <div>
                    <label className="mb-1 block font-semibold text-body">Tracking start date</label>
                    <p className="mb-2 text-xs text-subtle">Days before this show as “Not tracked”, never Absent. Leave blank to track the whole history.</p>
                    <Input type="date" value={start} onChange={(e) => setStart(e.target.value)} className="w-auto" />
                </div>

                <div>
                    <label className="mb-1 block font-semibold text-body">Weekly off days</label>
                    <div className="flex flex-wrap gap-1.5">
                        {WEEKDAYS.map(({ n, label }) => {
                            const on = weekOff.includes(n);
                            return (
                                <button
                                    key={n}
                                    type="button"
                                    onClick={() => toggleDay(n)}
                                    className={cx(
                                        'rounded-md px-3 py-1.5 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app',
                                        on ? 'bg-accent text-white' : 'border border-line text-muted hover:bg-surface-2'
                                    )}
                                    aria-pressed={on}
                                >
                                    {label}
                                </button>
                            );
                        })}
                    </div>
                </div>

                <div>
                    <label className="mb-1 block font-semibold text-body">Public holidays</label>
                    <div className="flex items-center gap-2">
                        <Input type="date" value={newHoliday} onChange={(e) => setNewHoliday(e.target.value)} className="w-auto" />
                        <Button type="button" size="sm" variant="secondary" onClick={addHoliday}>Add</Button>
                    </div>
                    {holidays.length > 0 && (
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {holidays.map((d) => (
                                <span key={d} className="inline-flex items-center gap-1 rounded-full bg-surface-2 px-2.5 py-1 text-xs text-muted">
                                    {d}
                                    <button type="button" onClick={() => setHolidays(holidays.filter((h) => h !== d))} className="text-subtle transition hover:text-danger">×</button>
                                </span>
                            ))}
                        </div>
                    )}
                </div>

                <Toggle
                    checked={autoAbsent}
                    onChange={setAutoAbsent}
                    label={
                        <span>
                            <span className="block font-semibold text-body">Auto-mark Absent</span>
                            <span className="block text-xs text-subtle">When off, a working day with no clock-in shows a neutral “No record” instead of Absent.</span>
                        </span>
                    }
                />

                <Button type="button" onClick={save} loading={saving}>
                    Save attendance policy
                </Button>
            </div>
        </SectionShell>
    );
}

/**
 * PAY PERIOD — the payroll window a timesheet covers.
 *
 * Periods are DERIVED from (cadence, anchor), never stored, so this choice
 * reshapes future periods the moment it is saved. It does NOT reshape history:
 * an approved timesheet snapshots its own cadence and dates, so everything
 * already signed off stays exactly as it was. The live preview below shows the
 * real dates the current choice produces, so nobody has to guess.
 *
 * The anchor only means anything for the rolling cadences — semi-monthly and
 * monthly are pinned to the calendar — so the field disappears for those, and
 * the server clears it rather than storing something it would never read.
 */
function PayPeriodSection({ payPeriod, setPayPeriod, preview }) {
    const [cadence, setCadence] = useState(payPeriod?.cadence || 'weekly');
    const [anchor, setAnchor] = useState(payPeriod?.anchor_date || '');
    const [saving, setSaving] = useState(false);

    const rolling = cadence === 'weekly' || cadence === 'biweekly';
    const dirty = cadence !== (payPeriod?.cadence || 'weekly')
        || (rolling && (anchor || '') !== (payPeriod?.anchor_date || ''));

    const save = () => {
        const payload = {
            pay_period_cadence: cadence,
            pay_period_anchor_date: rolling ? (anchor || null) : null,
        };

        if (preview) {
            setPayPeriod({ ...payPeriod, cadence, anchor_date: payload.pay_period_anchor_date });
            toast.success('Pay period saved.');
            return;
        }

        setSaving(true);
        axios.put(route('settings.pay-period.update'), payload)
            .then(({ data }) => {
                setPayPeriod(data?.payPeriod || { ...payPeriod, cadence, anchor_date: payload.pay_period_anchor_date });
                toast.success('Pay period saved.');
            })
            .catch(() => toast.error('Could not save the pay period.'))
            .finally(() => setSaving(false));
    };

    return (
        <SectionShell
            title="Pay period"
            blurb="How often payroll closes. Each period becomes one timesheet per person, which they submit and an approver signs off — approving freezes that period's time entries."
        >
            <div className="space-y-5 text-sm">
                <div>
                    <label className="mb-1 block font-semibold text-body">Cadence</label>
                    <div className="flex flex-wrap gap-2">
                        {(payPeriod?.options || []).map((option) => {
                            const on = cadence === option.key;
                            return (
                                <button
                                    key={option.key}
                                    type="button"
                                    onClick={() => setCadence(option.key)}
                                    className={cx(
                                        'rounded-lg border px-3 py-1.5 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app',
                                        on ? 'border-accent bg-accent text-white' : 'border-line bg-surface-2 text-muted hover:bg-surface'
                                    )}
                                    aria-pressed={on}
                                >
                                    {option.label}
                                </button>
                            );
                        })}
                    </div>
                </div>

                {rolling && (
                    <div>
                        <label className="mb-1 block font-semibold text-body">Period start anchor</label>
                        <p className="mb-2 text-xs text-subtle">
                            The first day of any one period — every other period is a whole number of
                            {cadence === 'biweekly' ? ' fortnights ' : ' weeks '}
                            from it. Leave blank to start each period on the day your week starts.
                        </p>
                        <Input type="date" value={anchor} onChange={(e) => setAnchor(e.target.value)} className="w-auto" />
                    </div>
                )}

                <div className="rounded-lg border border-line bg-surface-2 p-3">
                    <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-subtle">
                        {dirty ? 'Currently saved periods' : 'Recent periods'}
                    </p>
                    <div className="space-y-1">
                        {(payPeriod?.preview || []).map((p) => (
                            <div key={p.key} className="flex items-center justify-between gap-3 text-sm">
                                <span className={p.is_current ? 'font-medium text-body' : 'text-muted'}>{p.label}</span>
                                <span className="text-xs tabular-nums text-subtle">
                                    {p.start} → {p.end}{p.is_current ? ' · current' : ''}
                                </span>
                            </div>
                        ))}
                    </div>
                    {dirty && (
                        <p className="mt-2 text-xs text-warning">Save to see the periods this cadence produces.</p>
                    )}
                </div>

                <p className="text-xs text-subtle">
                    Changing this only affects periods from here on. Timesheets that were already approved keep the
                    dates they were signed off with.
                </p>

                <Button type="button" onClick={save} loading={saving} disabled={!dirty}>
                    Save pay period
                </Button>
            </div>
        </SectionShell>
    );
}

/**
 * SettingsIndex — workspace monitoring & attendance settings.
 *
 * @param {object} props.auth - Authenticated user wrapper.
 * @param {object} props.team - Team-level monitoring payload (MonitoringSetting::teamPayload()).
 * @param {Array}  props.users - Roster with per-user overrides.
 * @param {boolean} [props.preview=false] - Render fully from DEV_SAMPLE (offline QA),
 *        skipping all real props/network. Non-preview behavior is unchanged.
 */
export default function SettingsIndex({ auth, team: initialTeam, users: initialUsers, payPeriod: initialPayPeriod, workspaceType: initialWorkspaceType = 'general', preview = false }) {
    const pageEntitlements = usePage().props?.entitlements || {};

    const [team, setTeam] = useState(preview ? DEV_SAMPLE.team : initialTeam);
    const [users, setUsers] = useState(preview ? DEV_SAMPLE.users : initialUsers);
    const [payPeriod, setPayPeriod] = useState(preview ? DEV_SAMPLE.payPeriod : initialPayPeriod);
    const [workspaceType, setWorkspaceType] = useState(preview ? DEV_SAMPLE.workspaceType : initialWorkspaceType);
    const [active, setActive] = useState('screenshots');

    // Persist the workspace-type HINT. A full Inertia visit (not a background
    // axios PUT like the tracker toggles) is deliberate: this changes the SHARED
    // `workspace` prop — the Clients/Projects/Accounts wording in the top nav and
    // on every other screen — so the whole page's props have to come back.
    const saveWorkspaceType = useCallback((next, done) => {
        if (preview) {
            setWorkspaceType(next);
            toast.success('Workspace type updated.');
            done?.();
            return;
        }

        router.put(
            safeRoute('settings.workspace-type.update', '/settings/workspace-type'),
            { workspace_type: next },
            {
                preserveScroll: true,
                onSuccess: () => { setWorkspaceType(next); toast.success('Workspace type updated.'); },
                onError: () => toast.error('Could not change the workspace type.'),
                onFinish: () => done?.(),
            }
        );
    }, [preview]);

    const user = preview ? DEV_SAMPLE.auth.user : auth.user;

    // Effective plan entitlements (shared globally) drive the UI locks. The
    // server enforces them too — this just shows the locks/caps.
    const entitlements = preview ? DEV_SAMPLE.entitlements : pageEntitlements;
    const isLocked = (key) => {
        const flag = SECTION_LOCKS[key];
        return !!flag && entitlements[flag] === false;
    };
    const maxShots = entitlements.max_screenshots_per_hour ?? null;
    const minMinutes = entitlements.min_auto_pause_minutes ?? null;

    // In preview, saves stay in local state — no axios, no Inertia visit.
    const saveApi = useMemo(() => (
        preview
            ? { saveTeam: () => {}, saveUser: () => {} }
            : { saveTeam: patchTeam, saveUser: patchUser }
    ), [preview]);

    const updateOverrides = useCallback((userId, patch) => {
        setUsers((prev) => prev.map((u) => (
            u.id === userId ? { ...u, overrides: { ...(u.overrides || {}), ...patch } } : u
        )));
    }, []);

    const items = useMemo(() => CATEGORIES.map((cat) => ({
        ...cat,
        // The pay-period and workspace-type rows summarise their own props, not
        // the tracker payload.
        summary: cat.summary(team, payPeriod, workspaceType),
    })), [team, payPeriod, workspaceType]);

    const activeLabel = (CATEGORIES.find((c) => c.key === active) || {}).label || 'This feature';

    return (
        <AuthenticatedLayout user={user} header={<h2 className="text-xl font-semibold text-body">Settings</h2>}>
            <Head title="Settings" />

            <SaveContext.Provider value={saveApi}>
            <OverridesDispatchContext.Provider value={updateOverrides}>
                <div className="mx-auto max-w-[1600px] space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                    <div className="grid grid-cols-1 gap-6 md:grid-cols-[280px_1fr]">
                        <Card padded={false} className="h-max overflow-hidden">
                            <nav>
                                {items.map((item) => {
                                    const Icon = item.icon;
                                    const isActive = active === item.key;

                                    // Items that own a screen navigate instead of
                                    // switching the in-page panel.
                                    if (item.route) {
                                        return (
                                            <Link
                                                key={item.key}
                                                href={preview ? '#' : safeRoute(item.route, item.path)}
                                                className="flex w-full items-center justify-between gap-3 border-l-2 border-transparent px-5 py-3 text-left text-sm text-muted transition hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-accent"
                                            >
                                                <span className="flex items-center gap-3">
                                                    <Icon className="h-4 w-4 text-subtle" />
                                                    {item.label}
                                                </span>
                                                <ChevronRight className="h-3.5 w-3.5 text-subtle" />
                                            </Link>
                                        );
                                    }

                                    return (
                                        <button
                                            key={item.key}
                                            type="button"
                                            onClick={() => setActive(item.key)}
                                            className={cx(
                                                'flex w-full items-center justify-between gap-3 border-l-2 px-5 py-3 text-left text-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-accent',
                                                isActive
                                                    ? 'border-accent bg-surface-2 font-medium text-body'
                                                    : 'border-transparent text-muted hover:bg-surface-2'
                                            )}
                                        >
                                            <span className="flex items-center gap-3">
                                                <Icon className={cx('h-4 w-4', isActive ? 'text-accent' : 'text-subtle')} />
                                                {item.label}
                                            </span>
                                            {isLocked(item.key)
                                                ? <LockBadge iconOnly title={`${item.label} isn't in your plan`} />
                                                : <span className="text-xs text-subtle">{item.summary}</span>}
                                        </button>
                                    );
                                })}
                            </nav>
                        </Card>

                        <div>
                            {/* SHOWN, NOT HIDDEN — a section the plan doesn't include
                                still renders, greyed and inert, behind the upgrade
                                callout. `locked={false}` is a zero-cost passthrough,
                                so an entitled workspace sees no wrapper at all. */}
                            <LockedFeature
                                locked={isLocked(active)}
                                feature={SECTION_LOCKS[active]}
                                name={activeLabel}
                                blurb={SECTION_LOCK_BLURBS[SECTION_LOCKS[active]]}
                                href={preview ? '#' : safeRoute('billing.index', '/billing')}
                                minHeight={280}
                            >
                                <>
                                {active === 'workspace_type' && (
                                    <WorkspaceTypeSection
                                        value={workspaceType}
                                        onChange={saveWorkspaceType}
                                        entitled={entitlements.upwork_profiles === true}
                                        preview={preview}
                                    />
                                )}
                                {active === 'screenshots' && <ScreenshotsSection team={team} users={users} setTeam={setTeam} maxShots={maxShots} />}
                                {active === 'activity' && (
                                    <BooleanSection
                                        title="Activity Level tracking"
                                        blurb="Track mouse and keyboard Activity Level"
                                        field="activity_tracking_enabled"
                                        category="activity"
                                        team={team}
                                        users={users}
                                        setTeam={setTeam}
                                    />
                                )}
                                {active === 'app_url' && (
                                    <BooleanSection
                                        title="App & URL tracking"
                                        blurb="Track what applications your team members use and what websites they visit."
                                        field="app_url_tracking_enabled"
                                        category="app_url"
                                        team={team}
                                        users={users}
                                        setTeam={setTeam}
                                    />
                                )}
                                {active === 'weekly_limit' && <WeeklyLimitSection team={team} users={users} setTeam={setTeam} />}
                                {active === 'auto_pause' && <AutoPauseSection team={team} users={users} setTeam={setTeam} minMinutes={minMinutes} />}
                                {active === 'offline_time' && (
                                    <div className="space-y-6">
                                        <BooleanSection
                                            title="Allow adding Offline Time"
                                            blurb="When enabled, employees can manually add work entries outside the tracker."
                                            field="allow_offline_time"
                                            category="offline_time"
                                            team={team}
                                            users={users}
                                            setTeam={setTeam}
                                            label="Allow"
                                            offLabel="Do not allow"
                                        />
                                        {team.allow_offline_time && (
                                            <OverlapPolicyCard team={team} setTeam={setTeam} preview={preview} />
                                        )}
                                    </div>
                                )}
                                {active === 'notify_screenshot' && (
                                    <BooleanSection
                                        title="Notify when screenshot is taken"
                                        blurb="The desktop app will show a tray notification each time a screenshot is captured."
                                        field="notify_on_screenshot"
                                        category="notify_screenshot"
                                        team={team}
                                        users={users}
                                        setTeam={setTeam}
                                        label="Notify"
                                        offLabel="Do not notify"
                                    />
                                )}
                                {active === 'attendance_policy' && <AttendanceSection team={team} setTeam={setTeam} preview={preview} />}
                                {active === 'pay_period' && <PayPeriodSection payPeriod={payPeriod} setPayPeriod={setPayPeriod} preview={preview} />}
                                {active === 'attendance_slack' && <AttendanceSlackSection team={team} setTeam={setTeam} preview={preview} />}
                                {active === 'display' && <DisplaySection team={team} users={users} setTeam={setTeam} />}
                                {active === 'week_starts_on' && <WeekStartsSection team={team} setTeam={setTeam} />}
                                {active === 'currency' && <CurrencySection team={team} setTeam={setTeam} />}
                                {active === 'desktop_app' && <DesktopAppSection team={team} users={users} setTeam={setTeam} />}
                                </>
                            </LockedFeature>
                        </div>
                    </div>
                </div>
            </OverridesDispatchContext.Provider>
            </SaveContext.Provider>
        </AuthenticatedLayout>
    );
}

function OverlapPolicyCard({ team, setTeam }) {
    const { saveTeam } = useContext(SaveContext);
    return (
        <Card>
            <h3 className="text-sm font-semibold text-body">Manual time as a start–end range</h3>
            <p className="mt-1 max-w-prose text-xs text-muted">
                Employees can log offline time as a clock range (e.g. 2:08–3:05&nbsp;pm), not just a duration. Choose what happens when a range overlaps another entry on their own diary.
            </p>
            <div className="mt-3 flex flex-wrap gap-2">
                {[['warn', 'Warn but allow'], ['block', 'Block overlap'], ['allow', 'Allow silently']].map(([val, lbl]) => {
                    const on = (team.manual_time_overlap_policy || 'warn') === val;
                    return (
                        <button
                            key={val}
                            type="button"
                            onClick={() => {
                                const next = { ...team, manual_time_overlap_policy: val };
                                setTeam(next);
                                saveTeam(next);
                            }}
                            className={cx(
                                'rounded-lg border px-3 py-1.5 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app',
                                on ? 'border-accent bg-accent text-white' : 'border-line bg-surface-2 text-muted hover:bg-surface'
                            )}
                            aria-pressed={on}
                        >
                            {lbl}
                        </button>
                    );
                })}
            </div>
        </Card>
    );
}
