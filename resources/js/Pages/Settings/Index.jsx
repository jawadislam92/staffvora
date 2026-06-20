import React, { createContext, useCallback, useContext, useMemo, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import toast from 'react-hot-toast';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import {
    Activity,
    Bell,
    Calendar,
    CalendarDays,
    Camera,
    Clock,
    DollarSign,
    Globe,
    Laptop,
    Lock,
    MessageSquare,
    PauseCircle,
    Timer,
    Clock3,
} from 'lucide-react';

// Which settings sections are gated behind a plan feature flag. A section whose
// flag is off for the tenant is shown locked with an upgrade prompt; the server
// rejects the write independently (see SettingsController::enforceEntitlements).
const SECTION_LOCKS = {
    activity: 'activity_tracking',
    app_url: 'app_url_tracking',
    weekly_limit: 'weekly_limit',
    attendance_slack: 'attendance_alerts',
};

function LockedNotice({ label }) {
    return (
        <div className="rounded-lg border border-line bg-surface p-8 text-center">
            <span className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-surface-2">
                <Lock className="h-6 w-6 text-subtle" />
            </span>
            <h3 className="mt-4 text-base font-semibold text-body">{label} isn&apos;t in your plan</h3>
            <p className="mx-auto mt-1 max-w-sm text-sm text-muted">
                This feature is available on a higher plan. Upgrade to unlock it, or contact us if you need it added to your workspace.
            </p>
            <Link href={route('billing.index')} className="mt-4 inline-flex rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white hover:opacity-90">
                View plans
            </Link>
        </div>
    );
}

const TIMEZONES = [
    'Asia/Karachi', 'Asia/Dubai', 'Asia/Kolkata', 'Asia/Dhaka', 'Asia/Riyadh',
    'Europe/London', 'Europe/Berlin', 'America/New_York', 'America/Chicago',
    'America/Los_Angeles', 'Australia/Sydney', 'UTC',
];

const CATEGORIES = [
    { key: 'display', label: 'Time zone & format', icon: Clock3, summary: (t) => `${(t.display_timezone || 'Asia/Karachi').split('/').pop()} · ${t.time_format === '24' ? '24h' : '12h'}` },
    { key: 'screenshots', label: 'Screenshots', icon: Camera, summary: (t) => `${t.screenshots_per_hour}/hr` },
    { key: 'activity', label: 'Activity Level tracking', icon: Activity, summary: (t) => (t.activity_tracking_enabled ? 'Yes' : 'No') },
    { key: 'app_url', label: 'App & URL tracking', icon: Globe, summary: (t) => (t.app_url_tracking_enabled ? 'On' : 'Off') },
    { key: 'weekly_limit', label: 'Weekly time limit', icon: Timer, summary: (t) => (t.weekly_time_limit_hours ? `${t.weekly_time_limit_hours}h/wk` : 'No limit') },
    { key: 'auto_pause', label: 'Auto-pause tracking after', icon: PauseCircle, summary: (t) => `${t.auto_pause_minutes} min` },
    { key: 'offline_time', label: 'Allow adding Offline Time', icon: Clock, summary: (t) => (t.allow_offline_time ? 'Yes' : 'No') },
    { key: 'notify_screenshot', label: 'Notify when screenshot is taken', icon: Bell, summary: (t) => (t.notify_on_screenshot ? 'Yes' : 'No') },
    { key: 'attendance_policy', label: 'Attendance policy', icon: CalendarDays, summary: (t) => (t.attendance_auto_absent ? 'Auto-absent on' : 'Auto-absent off') },
    { key: 'attendance_slack', label: 'Attendance Slack alerts', icon: MessageSquare, summary: (t) => [t.slack_clockin_enabled && 'in', t.slack_clockout_enabled && 'out'].filter(Boolean).join(' + ') || 'Off' },
    { key: 'week_starts_on', label: 'Week starts on', icon: Calendar, summary: (t) => (t.week_starts_on === 'sunday' ? 'Sun' : 'Mon') },
    { key: 'currency', label: 'Currency symbol', icon: DollarSign, summary: (t) => t.currency_symbol },
    { key: 'desktop_app', label: 'Employee desktop application settings', icon: Laptop, summary: () => '' },
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

function Toggle({ checked, onChange, disabled }) {
    return (
        <button
            type="button"
            onClick={() => !disabled && onChange(!checked)}
            disabled={disabled}
            className={[
                'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition',
                checked ? 'bg-emerald-500' : 'bg-slate-300',
                disabled ? 'opacity-50' : '',
            ].join(' ')}
            aria-pressed={checked}
        >
            <span className={['inline-block h-4 w-4 transform rounded-full bg-surface shadow transition', checked ? 'translate-x-6' : 'translate-x-1'].join(' ')} />
        </button>
    );
}

function SectionShell({ title, blurb, children }) {
    return (
        <div className="space-y-5">
            <h2 className="text-xl font-semibold text-body">{title}</h2>
            {blurb && (
                <div className="rounded-lg bg-slate-700 px-4 py-3 text-sm text-slate-100">{blurb}</div>
            )}
            {children}
        </div>
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
const OverridesDispatchContext = createContext(() => {});

function ScreenshotsSection({ team, users, setTeam, maxShots }) {
    const captureOff = !team.capture_enabled || team.screenshots_per_hour === 0;
    const cap = maxShots ?? 60;
    const rateOptions = [1, 2, 3, 4, 6, 8, 10, 12, 15, 20, 30].filter((n) => n <= cap);
    return (
        <SectionShell
            title="Screenshots"
            blurb={<>How frequently screenshots will be taken.<br />This number is an average since screenshots are taken at random intervals.</>}
        >
            {maxShots != null && (
                <p className="mb-3 text-xs text-muted">Your plan allows up to <strong>{maxShots}</strong> screenshots per hour.</p>
            )}
            <div className="flex flex-wrap items-center gap-6 text-sm">
                <label className="flex items-center gap-2">
                    <input
                        type="radio"
                        checked={!captureOff}
                        onChange={() => {
                            const next = { ...team, capture_enabled: true, screenshots_per_hour: team.screenshots_per_hour || 6 };
                            setTeam(next);
                            patchTeam(next);
                        }}
                    />
                    <span>Take</span>
                    <select
                        className="rounded border-line text-sm"
                        value={team.screenshots_per_hour || 6}
                        disabled={captureOff}
                        onChange={(e) => {
                            const next = { ...team, screenshots_per_hour: Number(e.target.value) };
                            setTeam(next);
                            patchTeam(next);
                        }}
                    >
                        {rateOptions.map((n) => (
                            <option key={n} value={n}>{n}</option>
                        ))}
                    </select>
                    <span>screenshots per hour</span>
                    <select
                        className="rounded border-line text-sm"
                        value={team.blur_screenshots ? 'allow' : 'disallow'}
                        disabled={captureOff}
                        onChange={(e) => {
                            const next = { ...team, blur_screenshots: e.target.value === 'allow' };
                            setTeam(next);
                            patchTeam(next);
                        }}
                    >
                        <option value="disallow">Disallow blur</option>
                        <option value="allow">Allow blur</option>
                    </select>
                </label>
                <label className="flex items-center gap-2">
                    <input
                        type="radio"
                        checked={captureOff}
                        onChange={() => {
                            const next = { ...team, capture_enabled: false, screenshots_per_hour: 0 };
                            setTeam(next);
                            patchTeam(next);
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
                        <div className="flex flex-wrap items-center gap-4 text-xs">
                            <label className="flex items-center gap-1.5">
                                <input
                                    type="radio"
                                    checked={!off}
                                    onChange={() => setValues({
                                        capture_enabled: true,
                                        screenshots_per_hour: override.screenshots_per_hour || team.screenshots_per_hour || 6,
                                        blur_screenshots: override.blur_screenshots ?? team.blur_screenshots,
                                    })}
                                />
                                Take
                                <select
                                    className="rounded border-line text-xs"
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
                                </select>
                                / hr
                                <select
                                    className="rounded border-line text-xs"
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
                                </select>
                            </label>
                            <label className="flex items-center gap-1.5">
                                <input
                                    type="radio"
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
    return (
        <SectionShell title={title} blurb={blurb}>
            <div className="flex flex-wrap items-center gap-6 text-sm">
                <label className="flex items-center gap-2">
                    <input
                        type="radio"
                        checked={!!team[field]}
                        onChange={() => {
                            const next = { ...team, [field]: true };
                            setTeam(next);
                            patchTeam(next);
                        }}
                    />
                    {label}
                </label>
                <label className="flex items-center gap-2">
                    <input
                        type="radio"
                        checked={!team[field]}
                        onChange={() => {
                            const next = { ...team, [field]: false };
                            setTeam(next);
                            patchTeam(next);
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
                    <div className="flex flex-wrap items-center gap-4 text-xs">
                        <label className="flex items-center gap-1.5">
                            <input
                                type="radio"
                                checked={!!override[field]}
                                onChange={() => setValues({ [field]: true })}
                            />
                            {label}
                        </label>
                        <label className="flex items-center gap-1.5">
                            <input
                                type="radio"
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
    const limit = team.weekly_time_limit_hours;
    const enabled = limit !== null && limit !== undefined && limit !== '';
    return (
        <SectionShell
            title="Weekly time limit"
            blurb={<>Number of hours your employees are allowed to work. The tracking will stop when the limit is reached.<br />The time zone for the time limit is always UTC.</>}
        >
            <div className="flex flex-wrap items-center gap-6 text-sm">
                <label className="flex items-center gap-2">
                    <input
                        type="radio"
                        checked={enabled}
                        onChange={() => {
                            const next = { ...team, weekly_time_limit_hours: limit || 40 };
                            setTeam(next);
                            patchTeam(next);
                        }}
                    />
                    <span>Limit to</span>
                    <input
                        type="number"
                        min={0}
                        max={168}
                        value={enabled ? limit : 0}
                        disabled={!enabled}
                        onChange={(e) => {
                            const next = { ...team, weekly_time_limit_hours: Number(e.target.value) };
                            setTeam(next);
                            patchTeam(next);
                        }}
                        className="w-20 rounded border-line text-sm"
                    />
                    <span>hours per week</span>
                </label>
                <label className="flex items-center gap-2">
                    <input
                        type="radio"
                        checked={!enabled}
                        onChange={() => {
                            const next = { ...team, weekly_time_limit_hours: null };
                            setTeam(next);
                            patchTeam(next);
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
                        <div className="flex flex-wrap items-center gap-4 text-xs">
                            <label className="flex items-center gap-1.5">
                                <input
                                    type="radio"
                                    checked={en}
                                    onChange={() => setValues({ weekly_time_limit_hours: override.weekly_time_limit_hours || 40 })}
                                />
                                Limit to
                                <input
                                    type="number"
                                    min={0}
                                    max={168}
                                    value={en ? override.weekly_time_limit_hours : 0}
                                    disabled={!en}
                                    onChange={(e) => setValues({ weekly_time_limit_hours: Number(e.target.value) })}
                                    className="w-16 rounded border-line text-xs"
                                />
                                hours/week
                            </label>
                            <label className="flex items-center gap-1.5">
                                <input
                                    type="radio"
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
    const enabled = team.auto_pause_minutes > 0;
    const floor = minMinutes ?? 1;
    return (
        <SectionShell
            title="Auto-pause tracking after"
            blurb="Tracking will automatically pause after the specified period of inactivity and will automatically resume when user becomes active again."
        >
            {minMinutes != null && minMinutes > 1 && (
                <p className="mb-3 text-xs text-muted">Your plan&apos;s minimum auto-pause is <strong>{minMinutes}</strong> minutes. Upgrade for a tighter idle limit.</p>
            )}
            <div className="flex flex-wrap items-center gap-6 text-sm">
                <label className="flex items-center gap-2">
                    <input
                        type="radio"
                        checked={enabled}
                        onChange={() => {
                            const next = { ...team, auto_pause_minutes: team.auto_pause_minutes || floor };
                            setTeam(next);
                            patchTeam(next);
                        }}
                    />
                    <span>Pause after</span>
                    <input
                        type="number"
                        min={floor}
                        max={120}
                        value={enabled ? team.auto_pause_minutes : floor}
                        disabled={!enabled}
                        onChange={(e) => {
                            const next = { ...team, auto_pause_minutes: Number(e.target.value) };
                            setTeam(next);
                            patchTeam(next);
                        }}
                        className="w-20 rounded border-line text-sm"
                    />
                    <span>minutes of user inactivity</span>
                </label>
                <label className="flex items-center gap-2">
                    <input
                        type="radio"
                        checked={!enabled}
                        onChange={() => {
                            const next = { ...team, auto_pause_minutes: 0 };
                            setTeam(next);
                            patchTeam(next);
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
                        <div className="flex flex-wrap items-center gap-4 text-xs">
                            <label className="flex items-center gap-1.5">
                                <input
                                    type="radio"
                                    checked={en}
                                    onChange={() => setValues({ auto_pause_minutes: mins || team.auto_pause_minutes || 5 })}
                                />
                                Pause after
                                <input
                                    type="number"
                                    min={1}
                                    max={120}
                                    value={en ? mins : 5}
                                    disabled={!en}
                                    onChange={(e) => setValues({ auto_pause_minutes: Number(e.target.value) })}
                                    className="w-16 rounded border-line text-xs"
                                />
                                min idle
                            </label>
                            <label className="flex items-center gap-1.5">
                                <input
                                    type="radio"
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
    return (
        <SectionShell
            title="Time zone & format"
            blurb="All dates and times shown across the website and desktop app render in this time zone and format. Set this to where your team works (e.g. Asia/Karachi for Pakistan)."
        >
            <div className="flex flex-wrap items-end gap-6 text-sm">
                <label className="flex flex-col gap-1">
                    <span className="text-xs font-medium text-subtle">Time zone</span>
                    <select
                        className="rounded border-line text-sm"
                        value={team.display_timezone || 'Asia/Karachi'}
                        onChange={(e) => {
                            const next = { ...team, display_timezone: e.target.value };
                            setTeam(next);
                            patchTeam(next);
                        }}
                    >
                        {TIMEZONES.map((tz) => (
                            <option key={tz} value={tz}>{tz}</option>
                        ))}
                    </select>
                </label>
                <label className="flex flex-col gap-1">
                    <span className="text-xs font-medium text-subtle">Time format</span>
                    <div className="flex items-center gap-4">
                        {[['12', '12-hour (1:30 PM)'], ['24', '24-hour (13:30)']].map(([val, label]) => (
                            <label key={val} className="flex items-center gap-1.5">
                                <input
                                    type="radio"
                                    checked={(team.time_format || '12') === val}
                                    onChange={() => {
                                        const next = { ...team, time_format: val };
                                        setTeam(next);
                                        patchTeam(next);
                                    }}
                                />
                                {label}
                            </label>
                        ))}
                    </div>
                </label>
            </div>

            <IndividualSettings
                category="display"
                users={users}
                flagKey="override_display"
                defaultValues={{ display_timezone: team.display_timezone || 'Asia/Karachi', time_format: team.time_format || '12' }}
                editor={(override, setValues) => (
                    <div className="flex flex-wrap items-center gap-4 text-xs">
                        <select
                            className="rounded border-line text-xs"
                            value={override.display_timezone || team.display_timezone || 'Asia/Karachi'}
                            onChange={(e) => setValues({ display_timezone: e.target.value, time_format: override.time_format || team.time_format || '12' })}
                        >
                            {TIMEZONES.map((tz) => (
                                <option key={tz} value={tz}>{tz}</option>
                            ))}
                        </select>
                        <select
                            className="rounded border-line text-xs"
                            value={override.time_format || team.time_format || '12'}
                            onChange={(e) => setValues({ display_timezone: override.display_timezone || team.display_timezone || 'Asia/Karachi', time_format: e.target.value })}
                        >
                            <option value="12">12-hour</option>
                            <option value="24">24-hour</option>
                        </select>
                    </div>
                )}
            />
        </SectionShell>
    );
}

function WeekStartsSection({ team, setTeam }) {
    return (
        <SectionShell title="Week starts on" blurb="Used for the Report week ranges and the Attendance grid.">
            <div className="flex items-center gap-6 text-sm">
                {['monday', 'sunday'].map((day) => (
                    <label key={day} className="flex items-center gap-2 capitalize">
                        <input
                            type="radio"
                            checked={team.week_starts_on === day}
                            onChange={() => {
                                const next = { ...team, week_starts_on: day };
                                setTeam(next);
                                patchTeam(next);
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
    return (
        <SectionShell title="Currency symbol" blurb="Cosmetic. Shown in cost columns when hourly rates are configured.">
            <input
                type="text"
                maxLength={8}
                value={team.currency_symbol}
                onChange={(e) => setTeam({ ...team, currency_symbol: e.target.value })}
                onBlur={() => patchTeam(team)}
                className="w-24 rounded border-line text-sm"
            />
        </SectionShell>
    );
}

function DesktopAppSection({ team, users, setTeam }) {
    return (
        <SectionShell
            title="Employee desktop application settings"
            blurb="Default behaviour for the desktop tracker on employees' machines."
        >
            <div className="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-900">
                <strong>How these apply:</strong> each employee also has a “Launch on startup” switch
                inside the desktop app itself, which they control on their own machine. Central enforcement
                of the options below reaches a machine only after that employee updates to the latest
                desktop app. “Force quit on prolonged idle” is not enforced by the app yet — tracking
                already auto-pauses on idle (see <em>Auto-pause</em>).
            </div>
            <div className="space-y-3 text-sm">
                <label className="flex items-center gap-3">
                    <Toggle
                        checked={!!team.desktop_auto_start}
                        onChange={(v) => {
                            const next = { ...team, desktop_auto_start: v };
                            setTeam(next);
                            patchTeam(next);
                        }}
                    />
                    Launch on system startup (default)
                </label>
                <label className="flex items-center gap-3">
                    <Toggle
                        checked={!!team.desktop_force_quit_on_idle}
                        onChange={(v) => {
                            const next = { ...team, desktop_force_quit_on_idle: v };
                            setTeam(next);
                            patchTeam(next);
                        }}
                    />
                    Force quit on prolonged idle
                </label>
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
                    <div className="space-y-2 text-xs">
                        <label className="flex items-center gap-2">
                            <Toggle
                                checked={!!override.desktop_auto_start}
                                onChange={(v) => setValues({
                                    desktop_auto_start: v,
                                    desktop_force_quit_on_idle: !!override.desktop_force_quit_on_idle,
                                })}
                            />
                            Launch on system startup
                        </label>
                        <label className="flex items-center gap-2">
                            <Toggle
                                checked={!!override.desktop_force_quit_on_idle}
                                onChange={(v) => setValues({
                                    desktop_auto_start: !!override.desktop_auto_start,
                                    desktop_force_quit_on_idle: v,
                                })}
                            />
                            Force quit on prolonged idle
                        </label>
                    </div>
                )}
            />
        </SectionShell>
    );
}

function AttendanceSlackSection({ team, setTeam }) {
    const set = (patch) => {
        const next = { ...team, ...patch };
        setTeam(next);
        patchTeam(next);
    };
    const [token, setToken] = useState('');
    const [connected, setConnected] = useState(!!team.slack_connected);
    const [busy, setBusy] = useState(false);

    const connect = () => {
        if (!token.trim()) return;
        setBusy(true);
        axios.put(route('settings.slack.update'), { slack_bot_token: token.trim() })
            .then((r) => { setConnected(r.data?.connected ?? true); setToken(''); toast.success('Slack connected.'); })
            .catch(() => toast.error('Could not save the Slack token.'))
            .finally(() => setBusy(false));
    };
    const disconnect = () => {
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
                    <span className={`shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium ${connected ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-muted'}`}>
                        {connected ? 'Connected' : 'Not connected'}
                    </span>
                </div>
                <div className="mt-3 flex flex-wrap items-center gap-2">
                    <input
                        type="password"
                        value={token}
                        onChange={(e) => setToken(e.target.value)}
                        placeholder={connected ? 'Paste a new token to replace' : 'xoxb-your-bot-token'}
                        className="w-72 rounded border-line text-sm"
                        autoComplete="off"
                    />
                    <button type="button" onClick={connect} disabled={busy || !token.trim()} className="rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">
                        {connected ? 'Replace' : 'Connect'}
                    </button>
                    {connected && (
                        <button type="button" onClick={disconnect} disabled={busy} className="rounded-md border border-line px-3 py-1.5 text-sm text-muted hover:bg-surface-2 disabled:opacity-50">
                            Disconnect
                        </button>
                    )}
                </div>
            </div>

            <div className="space-y-4 text-sm">
                <label className="flex items-center gap-3">
                    <Toggle checked={!!team.slack_clockin_enabled} onChange={(v) => set({ slack_clockin_enabled: v })} />
                    <span>Post to Slack when someone <strong>clocks in</strong></span>
                </label>
                <label className="flex items-center gap-3">
                    <Toggle checked={!!team.slack_clockout_enabled} onChange={(v) => set({ slack_clockout_enabled: v })} />
                    <span>Post to Slack when someone <strong>clocks out</strong></span>
                </label>
                <div className="pt-2">
                    <label className="flex flex-col gap-1">
                        <span className="text-xs font-medium text-subtle">Attendance channel</span>
                        <input
                            type="text"
                            value={team.slack_attendance_channel || ''}
                            onChange={(e) => setTeam({ ...team, slack_attendance_channel: e.target.value })}
                            onBlur={() => patchTeam(team)}
                            placeholder="#attendance"
                            className="w-64 rounded border-line text-sm"
                        />
                        <span className="text-xs text-subtle">Channel name (e.g. #attendance) or ID. Leave blank to use the server default.</span>
                    </label>
                </div>
            </div>
        </SectionShell>
    );
}

function IndividualSettings({ category, users, flagKey, defaultValues, editor }) {
    const updateOverrides = useContext(OverridesDispatchContext);
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
                        patchUser(user, category, en, en ? defaultValues : {});
                    };
                    const setValues = (values) => {
                        updateOverrides(user.id, values);
                        patchUser(user, category, true, values);
                    };
                    return (
                        <div key={user.id} className="space-y-2 py-3">
                            <div className="flex items-center gap-3">
                                <Toggle checked={enabled} onChange={setEnabled} disabled={isExempt} />
                                <span className="flex-1 text-sm text-muted">{user.name}</span>
                                {isExempt && (
                                    <span
                                        className="rounded-full bg-surface-2 px-2.5 py-0.5 text-xs font-medium text-muted"
                                        title="Super Admins aren't monitored, so team/individual tracking settings don't apply to them."
                                    >
                                        Exempt · Super Admin
                                    </span>
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
function AttendanceSection({ team, setTeam }) {
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
        setSaving(true);
        const payload = {
            attendance_tracking_start_date: start || null,
            attendance_week_off_days: weekOff,
            attendance_holidays: holidays,
            attendance_auto_absent: autoAbsent,
        };
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
                    <input type="date" value={start} onChange={(e) => setStart(e.target.value)} className="rounded-md border-line text-sm" />
                </div>

                <div>
                    <label className="mb-1 block font-semibold text-body">Weekly off days</label>
                    <div className="flex flex-wrap gap-1.5">
                        {WEEKDAYS.map(({ n, label }) => (
                            <button
                                key={n}
                                type="button"
                                onClick={() => toggleDay(n)}
                                className={`rounded-md px-3 py-1.5 text-sm font-medium transition ${weekOff.includes(n) ? 'bg-orange-500 text-body' : 'border border-line text-muted hover:bg-surface-2'}`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </div>

                <div>
                    <label className="mb-1 block font-semibold text-body">Public holidays</label>
                    <div className="flex items-center gap-2">
                        <input type="date" value={newHoliday} onChange={(e) => setNewHoliday(e.target.value)} className="rounded-md border-line text-sm" />
                        <button type="button" onClick={addHoliday} className="rounded-md border border-line px-3 py-1.5 text-sm font-medium text-muted hover:bg-surface-2">Add</button>
                    </div>
                    {holidays.length > 0 && (
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {holidays.map((d) => (
                                <span key={d} className="inline-flex items-center gap-1 rounded-full bg-surface-2 px-2.5 py-1 text-xs text-muted">
                                    {d}
                                    <button type="button" onClick={() => setHolidays(holidays.filter((h) => h !== d))} className="text-subtle hover:text-rose-500">×</button>
                                </span>
                            ))}
                        </div>
                    )}
                </div>

                <label className="flex items-center gap-3">
                    <Toggle checked={autoAbsent} onChange={setAutoAbsent} />
                    <span>
                        <span className="block font-semibold text-body">Auto-mark Absent</span>
                        <span className="block text-xs text-subtle">When off, a working day with no clock-in shows a neutral “No record” instead of Absent.</span>
                    </span>
                </label>

                <button
                    type="button"
                    onClick={save}
                    disabled={saving}
                    className="inline-flex items-center gap-1.5 rounded-lg bg-orange-500 px-4 py-2 text-sm font-bold text-body hover:bg-orange-400 disabled:opacity-50"
                >
                    {saving ? 'Saving…' : 'Save attendance policy'}
                </button>
            </div>
        </SectionShell>
    );
}

export default function SettingsIndex({ auth, team: initialTeam, users: initialUsers }) {
    const [team, setTeam] = useState(initialTeam);
    const [users, setUsers] = useState(initialUsers);
    const [active, setActive] = useState('screenshots');

    // Effective plan entitlements (shared globally) drive the UI locks. The
    // server enforces them too — this just shows the locks/caps.
    const entitlements = usePage().props?.entitlements || {};
    const isLocked = (key) => {
        const flag = SECTION_LOCKS[key];
        return !!flag && entitlements[flag] === false;
    };
    const maxShots = entitlements.max_screenshots_per_hour ?? null;
    const minMinutes = entitlements.min_auto_pause_minutes ?? null;

    const updateOverrides = useCallback((userId, patch) => {
        setUsers((prev) => prev.map((u) => (
            u.id === userId ? { ...u, overrides: { ...(u.overrides || {}), ...patch } } : u
        )));
    }, []);

    const items = useMemo(() => CATEGORIES.map((cat) => ({
        ...cat,
        summary: cat.summary(team),
    })), [team]);

    const activeLabel = (CATEGORIES.find((c) => c.key === active) || {}).label || 'This feature';

    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="text-xl font-semibold text-body">Settings</h2>}>
            <Head title="Settings" />

            <OverridesDispatchContext.Provider value={updateOverrides}>
            <div className="min-h-screen bg-slate-950">
            <div className="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
                <div className="overflow-hidden rounded-lg bg-surface shadow">
                    <div className="border-b border-line bg-surface-2 px-6 py-4">
                        <h1 className="text-lg font-semibold text-body">Settings</h1>
                    </div>

                    <div className="grid grid-cols-1 gap-0 md:grid-cols-[280px_1fr]">
                        <nav className="border-b border-line md:border-b-0 md:border-r">
                            {items.map((item) => {
                                const Icon = item.icon;
                                const isActive = active === item.key;
                                return (
                                    <button
                                        key={item.key}
                                        type="button"
                                        onClick={() => setActive(item.key)}
                                        className={[
                                            'flex w-full items-center justify-between gap-3 border-l-2 px-5 py-3 text-left text-sm transition',
                                            isActive
                                                ? 'border-emerald-500 bg-surface-2 font-medium text-body'
                                                : 'border-transparent text-muted hover:bg-surface-2',
                                        ].join(' ')}
                                    >
                                        <span className="flex items-center gap-3">
                                            <Icon className="h-4 w-4 text-subtle" />
                                            {item.label}
                                        </span>
                                        {isLocked(item.key)
                                            ? <Lock className="h-3.5 w-3.5 text-subtle" />
                                            : <span className="text-xs text-subtle">{item.summary}</span>}
                                    </button>
                                );
                            })}
                        </nav>

                        <div className="px-6 py-6">
                            {isLocked(active) ? <LockedNotice label={activeLabel} /> : <>
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
                            {active === 'attendance_policy' && <AttendanceSection team={team} setTeam={setTeam} />}
                            {active === 'attendance_slack' && <AttendanceSlackSection team={team} setTeam={setTeam} />}
                            {active === 'display' && <DisplaySection team={team} users={users} setTeam={setTeam} />}
                            {active === 'week_starts_on' && <WeekStartsSection team={team} setTeam={setTeam} />}
                            {active === 'currency' && <CurrencySection team={team} setTeam={setTeam} />}
                            {active === 'desktop_app' && <DesktopAppSection team={team} users={users} setTeam={setTeam} />}
                            </>}
                        </div>
                    </div>
                </div>
            </div>
            </div>
            </OverridesDispatchContext.Provider>
        </AuthenticatedLayout>
    );
}
