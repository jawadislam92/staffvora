import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import { Button, Card, Badge, EmptyState, Select, Textarea, Spinner } from '@/Components/ui';
import { ChevronLeft, ChevronRight, Clock, Flag, Globe, History, Laptop, MonitorPlay, Plus, Sparkles, Trash2, X } from 'lucide-react';

const cx = (...a) => a.filter(Boolean).join(' ');

const HOURS = Array.from({ length: 24 }, (_, i) => i);
const SLOTS_PER_HOUR = 10;
const TOTAL_SLOTS = 24 * SLOTS_PER_HOUR;

// Company display settings, refreshed on each page render from the shared
// Inertia `display` prop so every timestamp renders in the configured business
// timezone + format regardless of the viewer's machine.
let DISPLAY = { timezone: 'Asia/Karachi', format: '12' };

function fmtHm(seconds) {
    const s = Math.max(0, Math.floor(seconds || 0));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    if (h === 0) return `${m}m`;
    return `${h}h ${String(m).padStart(2, '0')}m`;
}

function fmtTime(iso) {
    if (!iso) return '';
    try {
        return new Date(iso)
            .toLocaleTimeString('en-US', { timeZone: DISPLAY.timezone, hour: 'numeric', minute: '2-digit', hour12: String(DISPLAY.format) !== '24' })
            .toLowerCase()
            .replace(' ', '');
    } catch {
        return '';
    }
}

function fmtDateTime(iso) {
    if (!iso) return '';
    try {
        return new Date(iso).toLocaleString('en-US', {
            timeZone: DISPLAY.timezone, year: 'numeric', month: 'short', day: 'numeric',
            hour: 'numeric', minute: '2-digit', hour12: String(DISPLAY.format) !== '24',
        });
    } catch {
        return '';
    }
}

function fmtHour(h) {
    const period = h >= 12 ? 'pm' : 'am';
    const hour = h % 12 === 0 ? 12 : h % 12;
    return `${hour}${period}`;
}

// Activity strength -> a token-colored dot. Low activity stays neutral (it is
// not an error), medium warns, high reads as healthy.
function activityDot(percent) {
    if (percent >= 60) return 'bg-success';
    if (percent >= 30) return 'bg-warning';
    return 'bg-surface-2 ring-1 ring-line';
}

function sessionLabel(session) {
    return session.client_name || session.task_note || 'Untracked';
}

function shiftDate(iso, deltaDays) {
    const d = new Date(iso);
    d.setDate(d.getDate() + deltaDays);
    return d.toISOString().slice(0, 10);
}

// ---------------------------------------------------------------------------
// DEV_SAMPLE — a self-contained sample day used only when `preview` is true.
// Renders the full page offline (no network / props) with a mock super-admin
// viewer "Jawad" and ~4 members. Screenshot thumbnails are solid token-colored
// placeholders (see `tint`), never external images.
// ---------------------------------------------------------------------------
const SAMPLE_TINTS = ['bg-accent/15', 'bg-info-soft', 'bg-success-soft', 'bg-warning-soft'];

function buildSampleBands() {
    // Karachi working hours 9:00–17:30 with a couple of idle dips.
    const bands = [];
    for (let hour = 9; hour <= 17; hour++) {
        for (let q = 0; q < SLOTS_PER_HOUR; q++) {
            const slot = hour * SLOTS_PER_HOUR + q;
            if (hour === 13) continue; // lunch — no tracking
            const idle = (hour === 11 && q > 6) || (hour === 15 && q < 3);
            bands.push({
                slot,
                hour,
                minute: q * (60 / SLOTS_PER_HOUR),
                state: idle ? 'idle' : 'active',
                samples: 1,
            });
        }
    }
    return bands;
}

function sampleShots(sessionId, count, startHourUtc, tintOffset) {
    const today = new Date().toISOString().slice(0, 10);
    const apps = ['VS Code', 'Chrome', 'Figma', 'Slack', 'Terminal'];
    const domains = ['github.com', 'figma.com', 'staffvora.com', null, 'docs.google.com'];
    return Array.from({ length: count }, (_, i) => {
        const hh = String(startHourUtc + Math.floor(i / 4)).padStart(2, '0');
        const mm = String((i % 4) * 15).padStart(2, '0');
        const activity = [78, 92, 41, 66, 88, 24][i % 6];
        return {
            id: sessionId * 100 + i,
            captured_at: `${today}T${hh}:${mm}:00Z`,
            thumbnail_url: null,
            image_url: null,
            tint: SAMPLE_TINTS[(i + tintOffset) % SAMPLE_TINTS.length],
            activity_percent: activity,
            active_app: apps[i % apps.length],
            active_window_title: `${apps[i % apps.length]} — sample window ${i + 1}`,
            url_domain: domains[i % domains.length],
            is_flagged: i === 2,
        };
    });
}

function buildDevSample() {
    const today = new Date().toISOString().slice(0, 10);
    const monthStart = today.slice(0, 8) + '01';
    const selDay = Number(today.slice(8, 10));
    const daysInMonth = new Date(new Date().getFullYear(), new Date().getMonth() + 1, 0).getDate();
    const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    const monthStrip = Array.from({ length: daysInMonth }, (_, i) => {
        const day = i + 1;
        const iso = `${monthStart.slice(0, 8)}${String(day).padStart(2, '0')}`;
        const dow = new Date(iso + 'T00:00:00Z').getUTCDay();
        const worked = dow !== 0 && dow !== 6 && day <= selDay;
        return {
            date: iso,
            day,
            weekday: weekdays[dow],
            total_seconds: worked ? (18000 + ((day * 917) % 9000)) : 0,
            is_today: day === selDay,
            is_selected: day === selDay,
        };
    });

    const sessions = [
        {
            id: 1,
            client_name: 'Northwind Retail',
            task_note: 'Checkout redesign',
            work_type: 'client_work',
            tracker: 'Agency · Web',
            started_at: `${today}T04:05:00Z`,
            stopped_at: `${today}T07:20:00Z`,
            total_seconds: 11700,
            day_seconds: 11700,
            started_before_day: false,
            continues_after_day: false,
            activity_percent: 84,
            status: 'stopped',
            screenshots: sampleShots(1, 6, 9, 0),
            screenshot_count_hidden: 0,
            apps: [
                { name: 'VS Code', samples: 92, total_seconds: 5520 },
                { name: 'Chrome', samples: 40, total_seconds: 2400 },
                { name: 'Figma', samples: 18, total_seconds: 1080 },
            ],
            urls: [
                { name: 'github.com', samples: 30, total_seconds: 1800 },
                { name: 'figma.com', samples: 15, total_seconds: 900 },
            ],
        },
        {
            id: 2,
            client_name: null,
            task_note: 'Team standup & planning',
            work_type: 'office_work',
            tracker: null,
            started_at: `${today}T09:10:00Z`,
            stopped_at: `${today}T12:35:00Z`,
            total_seconds: 12300,
            day_seconds: 12300,
            started_before_day: false,
            continues_after_day: false,
            activity_percent: 57,
            status: 'stopped',
            screenshots: sampleShots(2, 4, 14, 2),
            screenshot_count_hidden: 0,
            apps: [
                { name: 'Slack', samples: 60, total_seconds: 3600 },
                { name: 'Chrome', samples: 45, total_seconds: 2700 },
            ],
            urls: [
                { name: 'staffvora.com', samples: 22, total_seconds: 1320 },
                { name: 'docs.google.com', samples: 12, total_seconds: 720 },
            ],
        },
    ];

    return {
        auth: {
            user: {
                id: 99, name: 'Jawad', email: 'jawad@staffvora.com', role: 'owner',
                is_super_admin: true, permissions: [],
            },
        },
        targetUser: { id: 1, name: 'Ayesha Khan', email: 'ayesha@staffvora.com', role: 'member' },
        date: today,
        users: [
            { id: 1, name: 'Ayesha Khan', email: 'ayesha@staffvora.com', role: 'member' },
            { id: 2, name: 'Bilal Ahmed', email: 'bilal@staffvora.com', role: 'member' },
            { id: 3, name: 'Sara Malik', email: 'sara@staffvora.com', role: 'member' },
            { id: 99, name: 'Jawad', email: 'jawad@staffvora.com', role: 'owner' },
        ],
        permissions: {
            view_screenshots: true, view_others: true, add_offline_time: true,
            manage_screenshots: true, delete_screenshots: true,
        },
        weekStartsOn: 'monday',
        aiEnabled: true,
        initialData: {
            date: today,
            day_label: new Date(today + 'T00:00:00Z').toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', timeZone: 'UTC' }),
            sessions,
            totals: { day: 24000, week: 118800, month: 486000 },
            week_range: { start: today, end: today },
            month_strip: monthStrip,
            client_breakdown: [
                { client: 'Northwind Retail', total_seconds: 11700 },
                { client: 'Office work', total_seconds: 12300 },
            ],
            day_apps: [
                { name: 'VS Code', samples: 92, total_seconds: 5520 },
                { name: 'Chrome', samples: 85, total_seconds: 5100 },
                { name: 'Slack', samples: 60, total_seconds: 3600 },
                { name: 'Figma', samples: 18, total_seconds: 1080 },
            ],
            day_urls: [
                { name: 'github.com', samples: 30, total_seconds: 1800 },
                { name: 'staffvora.com', samples: 22, total_seconds: 1320 },
                { name: 'figma.com', samples: 15, total_seconds: 900 },
            ],
            activity_bands: buildSampleBands(),
            sample_interval_seconds: 60,
        },
    };
}

const SAMPLE_HISTORY = [
    { id: 1, action: 'screenshot.flag', action_label: 'Flagged screenshot', reason: 'Non-work browsing', old_value: null, new_value: null, actor_name: 'Jawad', created_at: new Date().toISOString(), tracking_screenshot_id: 102, tracking_session_id: 1 },
    { id: 2, action: 'session.note_edit', action_label: 'Edited session note', reason: null, old_value: 'Checkout', new_value: 'Checkout redesign', actor_name: 'Ayesha Khan', created_at: new Date(Date.now() - 3600e3).toISOString(), tracking_screenshot_id: null, tracking_session_id: 1 },
];

function MonthStrip({ days, onPick }) {
    const selRef = useRef(null);
    // Center the selected day so you never have to scroll sideways hunting for
    // it — the strip lands on the active date every time it changes.
    useEffect(() => {
        selRef.current?.scrollIntoView({ inline: 'center', block: 'nearest' });
    }, [days]);

    return (
        <div className="overflow-x-auto">
            <div className="flex min-w-full gap-1 pb-1 pt-1">
                {days.map((d) => {
                    const has = d.total_seconds > 0;
                    return (
                        <button
                            key={d.date}
                            ref={d.is_selected ? selRef : null}
                            type="button"
                            onClick={() => onPick(d.date)}
                            className={cx(
                                'flex w-10 shrink-0 flex-col items-center rounded-md border px-1 py-2 text-xs transition',
                                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app',
                                d.is_selected
                                    ? 'border-accent bg-accent/10 text-accent'
                                    : d.is_today
                                    ? 'border-line bg-surface-2 text-body'
                                    : 'border-transparent text-muted hover:bg-surface-2',
                            )}
                            title={fmtHm(d.total_seconds)}
                        >
                            <span className="text-[10px] uppercase">{d.weekday}</span>
                            <span className="text-base font-semibold leading-none">{d.day}</span>
                            <span className={cx('mt-1 h-1.5 w-6 rounded-full', has ? 'bg-success' : 'bg-transparent')} />
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

function HourRuler({ date, bands = [] }) {
    const nowHour = new Date().getHours();
    const isToday = date === new Date().toISOString().slice(0, 10);

    // Build a slot map: 240 slots (24h × 10 per hour), each "active" | "idle" | "quiet".
    const slotMap = useMemo(() => {
        const arr = Array(TOTAL_SLOTS).fill('quiet');
        bands.forEach((b) => {
            if (b.slot >= 0 && b.slot < TOTAL_SLOTS) arr[b.slot] = b.state;
        });
        return arr;
    }, [bands]);

    return (
        <div className="relative mt-3 space-y-1">
            <div className="grid h-3 overflow-hidden rounded-sm" style={{ gridTemplateColumns: `repeat(${TOTAL_SLOTS}, minmax(0, 1fr))` }}>
                {slotMap.map((state, i) => (
                    <div
                        key={i}
                        className={cx(
                            'h-full',
                            state === 'active' ? 'bg-success' : state === 'idle' ? 'bg-warning' : 'bg-surface-2',
                        )}
                        title={`${Math.floor(i / SLOTS_PER_HOUR)}:${String((i % SLOTS_PER_HOUR) * (60 / SLOTS_PER_HOUR)).padStart(2, '0')} ${state}`}
                    />
                ))}
            </div>
            <div className="grid border-b border-line text-[10px] text-subtle" style={{ gridTemplateColumns: 'repeat(24, minmax(0, 1fr))' }}>
                {HOURS.map((h) => (
                    <div key={h} className={cx('flex h-5 items-end justify-start border-l px-1 pb-0.5', isToday && h === nowHour ? 'border-accent bg-accent/10' : 'border-line')}>
                        {h % 3 === 0 ? fmtHour(h) : ''}
                    </div>
                ))}
            </div>
            <div className="flex items-center gap-3 pt-1 text-[10px] text-muted">
                <span className="inline-flex items-center gap-1"><span className="inline-block h-2 w-3 rounded-sm bg-success" /> Active</span>
                <span className="inline-flex items-center gap-1"><span className="inline-block h-2 w-3 rounded-sm bg-warning" /> Idle</span>
                <span className="inline-flex items-center gap-1"><span className="inline-block h-2 w-3 rounded-sm bg-surface-2 ring-1 ring-line" /> No tracking</span>
            </div>
        </div>
    );
}

function RollupList({ items, icon: Icon, emptyLabel }) {
    if (!items || items.length === 0) {
        return <p className="text-xs text-muted">{emptyLabel}</p>;
    }
    const max = Math.max(...items.map((x) => x.total_seconds || 0), 1);
    return (
        <ul className="space-y-2">
            {items.map((item) => {
                const pct = Math.max(2, Math.round((item.total_seconds / max) * 100));
                return (
                    <li key={item.name} className="space-y-1">
                        <div className="flex items-center justify-between text-xs">
                            <span className="inline-flex items-center gap-1.5 truncate text-body">
                                {Icon && <Icon className="h-3 w-3 text-subtle" />}
                                <span className="truncate">{item.name || 'Unknown'}</span>
                            </span>
                            <strong className="text-body">{fmtHm(item.total_seconds)}</strong>
                        </div>
                        <div className="h-1.5 overflow-hidden rounded-full bg-surface-2">
                            <div className="h-full bg-accent" style={{ width: `${pct}%` }} />
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}

function ScreenshotTile({ shot, canManage, canDelete, onChanged, preview = false, selected = false, onToggleSelect = null, onRequestDelete = null, onOpen = null }) {
    const [busy, setBusy] = useState(false);

    const toggleFlag = () => {
        if (!canManage || busy || preview) return;
        const next = !shot.is_flagged;
        const reason = next ? prompt('Reason for flagging? (optional)') ?? '' : '';
        setBusy(true);
        router.patch(
            route('monitoring.screenshots.flag', { screenshot: shot.id }),
            { is_flagged: next, reason },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => onChanged?.(),
                onFinish: () => setBusy(false),
            }
        );
    };

    const remove = () => {
        if (!canDelete || busy) return;
        onRequestDelete?.([shot.id]);
    };

    return (
        <figure className={cx('relative overflow-hidden rounded-md border bg-surface-2', selected ? 'border-danger ring-1 ring-danger/50' : 'border-line')}>
            <div className="flex items-center justify-between bg-surface px-2 py-1 text-[11px] text-muted">
                <span className="flex items-center gap-1.5">
                    {canDelete && onToggleSelect && (
                        <input
                            type="checkbox"
                            checked={selected}
                            onChange={() => onToggleSelect(shot.id)}
                            title="Select for deletion"
                            className="h-3.5 w-3.5 rounded border-line bg-surface-2 text-accent focus:ring-accent"
                        />
                    )}
                    {fmtTime(shot.captured_at)}
                </span>
                <div className="flex items-center gap-1.5">
                    {shot.is_flagged && <Flag className="h-3 w-3 text-danger" />}
                    <span className={cx('inline-block h-2 w-2 rounded-full', activityDot(shot.activity_percent))} title={`Activity ${shot.activity_percent}%`} />
                </div>
            </div>
            {shot.thumbnail_url ? (
                <button type="button" onClick={() => onOpen?.(shot.id)} className="block w-full cursor-zoom-in" title="Open viewer">
                    <img
                        src={shot.thumbnail_url}
                        alt={shot.active_window_title || 'Screenshot'}
                        loading="lazy"
                        className="block h-32 w-full object-cover"
                    />
                </button>
            ) : shot.tint ? (
                <button type="button" onClick={() => onOpen?.(shot.id)} className={cx('flex h-32 w-full cursor-zoom-in items-center justify-center', shot.tint)} title="Open viewer">
                    <MonitorPlay className="h-6 w-6 text-body/30" />
                </button>
            ) : (
                <div className="flex h-32 w-full items-center justify-center text-xs text-subtle">Image unavailable</div>
            )}
            {(shot.active_app || shot.url_domain) && (
                <figcaption className="truncate bg-surface px-2 py-1 text-[11px] text-muted" title={shot.active_window_title || ''}>
                    {shot.url_domain || shot.active_app}
                </figcaption>
            )}
            {(canManage || canDelete) && (
                <div className="absolute right-1 top-7 flex gap-1">
                    {canManage && (
                        <button
                            type="button"
                            onClick={toggleFlag}
                            disabled={busy}
                            className={cx(
                                'inline-flex h-6 w-6 items-center justify-center rounded-full border bg-surface/90 shadow-sm transition',
                                shot.is_flagged ? 'border-danger/40 text-danger hover:bg-danger-soft' : 'border-line text-muted hover:bg-surface-2',
                            )}
                            title={shot.is_flagged ? 'Remove flag' : 'Flag this screenshot'}
                        >
                            <Flag className="h-3 w-3" />
                        </button>
                    )}
                    {canDelete && (
                        <button
                            type="button"
                            onClick={remove}
                            disabled={busy}
                            className="inline-flex h-6 w-6 items-center justify-center rounded-full border border-line bg-surface/90 text-muted shadow-sm transition hover:bg-danger-soft hover:text-danger"
                            title="Delete screenshot"
                        >
                            <Trash2 className="h-3 w-3" />
                        </button>
                    )}
                </div>
            )}
        </figure>
    );
}

function fmtDayTime(iso) {
    if (!iso) return '';
    try {
        return new Date(iso).toLocaleString('en-US', {
            timeZone: DISPLAY.timezone, weekday: 'short',
            hour: 'numeric', minute: '2-digit', hour12: String(DISPLAY.format) !== '24',
        });
    } catch {
        return '';
    }
}

function SessionCard({ session, canViewScreenshots, canManageScreenshots, canDeleteScreenshots, view, preview, onShotChanged, selectedShots, onToggleSelect, onSelectSession, onRequestDelete, onRequestDeleteSession, onOpenShot }) {
    const daySeconds = session.day_seconds ?? session.total_seconds;
    const isSplit = session.started_before_day || session.continues_after_day;
    return (
        <section className="space-y-3">
            <header className="flex flex-wrap items-center gap-2 text-sm font-semibold">
                <span className="inline-flex h-2 w-2 rounded-full bg-success" />
                <span className="text-accent">{fmtTime(session.started_at)} - {fmtTime(session.stopped_at)}</span>
                <span className="text-body">• {sessionLabel(session)}</span>
                {session.started_before_day && (
                    <Badge tone="neutral" size="sm">overnight · started {fmtDayTime(session.started_at)}</Badge>
                )}
                {session.continues_after_day && (
                    <Badge tone="neutral" size="sm">continues past midnight</Badge>
                )}
                <span className="ml-auto flex items-center gap-2 text-xs font-normal text-muted">
                    {fmtHm(daySeconds)}{isSplit ? ` this day of ${fmtHm(session.total_seconds)}` : ''} · activity {session.activity_percent ?? 0}%
                    {canDeleteScreenshots && view !== 'apps' && (session.screenshots || []).length > 0 && (
                        <button
                            type="button"
                            onClick={() => onSelectSession?.(session.screenshots.map((s) => s.id))}
                            className="rounded border border-line px-1.5 py-0.5 text-[10px] text-body hover:bg-surface-2"
                            title="Select every screenshot in this session"
                        >
                            Select all
                        </button>
                    )}
                    {canDeleteScreenshots && (
                        <button
                            type="button"
                            onClick={() => onRequestDeleteSession?.({
                                id: session.id,
                                label: `${fmtTime(session.started_at)} - ${fmtTime(session.stopped_at)} · ${sessionLabel(session)}`,
                                seconds: session.total_seconds,
                            })}
                            className="rounded border border-danger/40 px-1.5 py-0.5 text-[10px] text-danger hover:bg-danger-soft"
                            title="Delete this whole session: its time, screenshots, and apps & URLs data"
                        >
                            Delete session
                        </button>
                    )}
                </span>
            </header>

            {view === 'apps' ? (
                <div className="grid grid-cols-1 gap-4 rounded-md border border-line bg-surface-2 p-3 sm:grid-cols-2">
                    <div className="space-y-2">
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-muted">Apps</p>
                        <RollupList items={session.apps} icon={Laptop} emptyLabel="No app data captured." />
                    </div>
                    <div className="space-y-2">
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-muted">URLs</p>
                        <RollupList items={session.urls} icon={Globe} emptyLabel="No browser activity captured." />
                    </div>
                </div>
            ) : (
                <>
                    {!canViewScreenshots && session.screenshot_count_hidden > 0 && (
                        <p className="rounded-md bg-surface-2 px-3 py-2 text-xs text-muted">
                            {session.screenshot_count_hidden} screenshot{session.screenshot_count_hidden === 1 ? '' : 's'} captured. Permission required to view.
                        </p>
                    )}

                    {canViewScreenshots && session.screenshots.length === 0 && (
                        <p className="text-xs text-muted">No screenshots captured for this session.</p>
                    )}

                    {canViewScreenshots && session.screenshots.length > 0 && (
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                            {session.screenshots.map((shot) => (
                                <ScreenshotTile
                                    key={shot.id}
                                    shot={shot}
                                    canManage={canManageScreenshots}
                                    canDelete={canDeleteScreenshots}
                                    preview={preview}
                                    onChanged={onShotChanged}
                                    selected={selectedShots?.has(shot.id)}
                                    onToggleSelect={onToggleSelect}
                                    onRequestDelete={onRequestDelete}
                                    onOpen={onOpenShot}
                                />
                            ))}
                        </div>
                    )}
                </>
            )}
        </section>
    );
}

export default function TimelineIndex({
    auth,
    targetUser,
    date,
    users,
    permissions,
    initialData,
    weekStartsOn,
    aiEnabled = false,
    preview = false,
}) {
    // Preview: swap every prop for the built-in offline sample and a mock
    // super-admin viewer, so the page renders in full with no network/props.
    if (preview) {
        const sample = buildDevSample();
        auth = auth || sample.auth;
        targetUser = sample.targetUser;
        date = sample.date;
        users = sample.users;
        permissions = sample.permissions;
        initialData = sample.initialData;
        weekStartsOn = sample.weekStartsOn;
        aiEnabled = sample.aiEnabled;
    }

    // Keep the module-level display config current for fmtTime/fmtDateTime.
    const page = usePage();
    DISPLAY = (preview ? DISPLAY : (page?.props?.display || DISPLAY));

    const [data, setData] = useState(initialData);
    const [activeDate, setActiveDate] = useState(date);
    const [activeUserId, setActiveUserId] = useState(targetUser.id);
    const [loading, setLoading] = useState(false);
    const [view, setView] = useState('tasks'); // 'tasks' | 'apps'
    const [historyOpen, setHistoryOpen] = useState(false);
    const [history, setHistory] = useState([]);
    const [historyLoading, setHistoryLoading] = useState(false);
    const [showDownloadHint, setShowDownloadHint] = useState(false);
    const [aiSummary, setAiSummary] = useState(null);
    const [aiLoading, setAiLoading] = useState(false);

    // Screenshot deletion: select tiles, then one modal collects the reason.
    // Deleting removes the tracked minutes those screenshots represent.
    const [selectedShots, setSelectedShots] = useState(new Set());
    const [lightboxId, setLightboxId] = useState(null);
    const [deleteIds, setDeleteIds] = useState(null); // array => modal open (screenshots)
    const [deleteSession, setDeleteSession] = useState(null); // {id, label, seconds} => modal open (whole session)
    const [deleteReason, setDeleteReason] = useState('');
    const [deleting, setDeleting] = useState(false);

    const toggleShot = (id) => {
        setSelectedShots((prev) => {
            const next = new Set(prev);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    };

    const selectSession = (ids) => {
        setSelectedShots((prev) => {
            const next = new Set(prev);
            const allIn = ids.every((id) => next.has(id));
            ids.forEach((id) => (allIn ? next.delete(id) : next.add(id)));
            return next;
        });
    };

    const closeDeleteModals = () => {
        setDeleteIds(null);
        setDeleteSession(null);
        setDeleteReason('');
    };

    const confirmDelete = () => {
        if (deleting) return;
        if (preview) { // offline: no network, just clear the selection
            closeDeleteModals();
            setSelectedShots(new Set());
            return;
        }
        setDeleting(true);
        const finish = () => {
            setDeleting(false);
            closeDeleteModals();
            setSelectedShots(new Set());
            reload(activeDate, activeUserId);
        };
        if (deleteSession) {
            router.post(route('monitoring.sessions.delete-session', { session: deleteSession.id }), {
                reason: deleteReason,
            }, {
                preserveScroll: true,
                preserveState: true,
                onFinish: finish,
            });
            return;
        }
        if (!deleteIds || deleteIds.length === 0) {
            setDeleting(false);
            return;
        }
        router.post(route('monitoring.screenshots.bulk-delete'), {
            screenshot_ids: deleteIds,
            reason: deleteReason,
        }, {
            preserveScroll: true,
            preserveState: true,
            onFinish: finish,
        });
    };

    const summarizeDay = async () => {
        if (aiLoading) return;
        if (preview) {
            setAiLoading(true);
            setTimeout(() => {
                setAiSummary('Ayesha logged 6h 40m across two sessions. The morning was focused work on the Northwind checkout redesign (84% activity, mostly VS Code and Figma), followed by team standup and planning in the afternoon. One screenshot was flagged for review.');
                setAiLoading(false);
            }, 500);
            return;
        }
        setAiLoading(true);
        try {
            const res = await fetch(route('timeline.ai-summary', { user_id: activeUserId, date: activeDate }), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const json = await res.json();
            setAiSummary(res.ok ? json.summary : (json.message || 'Could not generate the summary.'));
        } catch {
            setAiSummary('Could not generate the summary — check your connection and try again.');
        } finally {
            setAiLoading(false);
        }
    };

    // Try to launch the desktop tracker via its custom protocol. If nothing
    // handles it within ~1.6s (window never lost focus), the app isn't
    // installed — offer the download page instead.
    const openDesktopApp = () => {
        if (preview) { setShowDownloadHint(true); return; }
        let handled = false;
        const onBlur = () => { handled = true; };
        window.addEventListener('blur', onBlur);
        window.location.href = 'tracksuite://open';
        setTimeout(() => {
            window.removeEventListener('blur', onBlur);
            if (!handled) setShowDownloadHint(true);
        }, 1600);
    };

    const reload = (nextDate, nextUserId) => {
        const d = nextDate ?? activeDate;
        const u = nextUserId ?? activeUserId;
        if (preview) { // offline: track selection locally, keep the sample day
            setActiveDate(d);
            setActiveUserId(u);
            setAiSummary(null);
            return;
        }
        setLoading(true);
        router.get(
            route('timeline.index'),
            { date: d, user_id: u },
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: (page) => {
                    const props = page.props;
                    setActiveDate(props.date);
                    setActiveUserId(props.targetUser.id);
                    setData(props.initialData);
                    setAiSummary(null);
                },
                onFinish: () => setLoading(false),
            }
        );
    };

    const openHistory = async () => {
        setHistoryOpen(true);
        setHistoryLoading(true);
        if (preview) {
            setTimeout(() => { setHistory(SAMPLE_HISTORY); setHistoryLoading(false); }, 300);
            return;
        }
        try {
            const res = await fetch(route('timeline.history', { user_id: activeUserId, date: activeDate }), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const json = await res.json();
            setHistory(json.logs || []);
        } catch {
            setHistory([]);
        } finally {
            setHistoryLoading(false);
        }
    };

    const clientBreakdownTotal = useMemo(
        () => (data.client_breakdown || []).reduce((s, x) => s + x.total_seconds, 0),
        [data.client_breakdown]
    );

    const userOptions = users || [];
    const showUserPicker = permissions.view_others && userOptions.length > 1;

    // Flat chronological list of the day's screenshots so the lightbox can
    // step Prev/Next across every session without leaving the page.
    const allShots = useMemo(
        () => (data.sessions || []).flatMap((s) => (s.screenshots || []).map((shot) => ({ ...shot, sessionLabel: sessionLabel(s) }))),
        [data.sessions]
    );
    const lightboxIndex = lightboxId == null ? -1 : allShots.findIndex((s) => s.id === lightboxId);
    const lightboxShot = lightboxIndex >= 0 ? allShots[lightboxIndex] : null;
    const stepLightbox = (delta) => {
        if (lightboxIndex < 0) return;
        const next = lightboxIndex + delta;
        if (next >= 0 && next < allShots.length) setLightboxId(allShots[next].id);
    };

    useEffect(() => {
        if (!lightboxShot) return;
        const onKey = (e) => {
            if (e.key === 'ArrowRight') stepLightbox(1);
            else if (e.key === 'ArrowLeft') stepLightbox(-1);
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [lightboxShot, lightboxIndex, allShots]);

    const flagFromLightbox = () => {
        if (!lightboxShot || preview) return;
        const next = !lightboxShot.is_flagged;
        const reason = next ? prompt('Reason for flagging? (optional)') ?? '' : '';
        router.patch(route('monitoring.screenshots.flag', { screenshot: lightboxShot.id }), { is_flagged: next, reason }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => reload(activeDate, activeUserId),
        });
    };

    return (
        <AuthenticatedLayout user={auth.user} header="Timeline">
            <Head title="Timeline" />

            <div className="mx-auto max-w-[1600px] space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                {/* Toolbar: member picker + day navigation + month strip */}
                <Card padded={false}>
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4">
                        <div className="flex items-center gap-3">
                            {showUserPicker ? (
                                <Select
                                    value={activeUserId}
                                    onChange={(e) => reload(activeDate, Number(e.target.value))}
                                    className="w-auto font-semibold"
                                >
                                    {userOptions.map((u) => (
                                        <option key={u.id} value={u.id}>{u.name}</option>
                                    ))}
                                </Select>
                            ) : (
                                <h1 className="text-base font-semibold text-body">{targetUser.name}</h1>
                            )}
                        </div>
                        <div className="flex items-center gap-2">
                            <Button variant="ghost" size="sm" onClick={() => reload(shiftDate(activeDate, -1))} aria-label="Previous day" icon={<ChevronLeft className="h-4 w-4" />} />
                            <input
                                type="date"
                                value={activeDate}
                                onChange={(e) => reload(e.target.value)}
                                className="rounded-md border border-line bg-surface-2 px-3 py-1.5 text-xs text-body focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app"
                            />
                            <Button variant="ghost" size="sm" onClick={() => reload(shiftDate(activeDate, 1))} aria-label="Next day" icon={<ChevronRight className="h-4 w-4" />} />
                            <Button variant="secondary" size="sm" onClick={() => reload(new Date().toISOString().slice(0, 10))}>Today</Button>
                        </div>
                    </div>
                    <div className="px-3 py-2">
                        <MonthStrip days={data.month_strip || []} onPick={(d) => reload(d)} />
                    </div>
                </Card>

                {/* Summary: totals, tasks breakdown, AI, 24h ruler */}
                <Card padded={false}>
                    <div className="grid grid-cols-1 gap-5 px-5 py-5 md:grid-cols-[1fr_320px]">
                        <div className="space-y-2">
                            <p className="text-xs uppercase tracking-wide text-muted">{data.day_label}</p>
                            <div className="flex items-end gap-3">
                                <div>
                                    <div className="text-4xl font-bold text-body tabular-nums">{fmtHm(data.totals?.day || 0)}</div>
                                    <div className="text-[11px] uppercase tracking-wide text-subtle">Tracked this day</div>
                                </div>
                                <div className="text-xs text-muted">
                                    This week: <strong className="text-body">{fmtHm(data.totals?.week || 0)}</strong>
                                    <span className="mx-2">·</span>
                                    This month: <strong className="text-body">{fmtHm(data.totals?.month || 0)}</strong>
                                </div>
                            </div>
                            <p className="text-xs text-subtle">Desktop-tracker time. Week starts {weekStartsOn === 'sunday' ? 'Sunday' : 'Monday'}.</p>
                            {aiEnabled && (
                                <div className="space-y-2 pt-1">
                                    <Button
                                        variant="primary"
                                        size="sm"
                                        onClick={summarizeDay}
                                        loading={aiLoading}
                                        icon={<Sparkles className="h-3.5 w-3.5" />}
                                    >
                                        {aiLoading ? 'Summarizing…' : 'Summarize this day'}
                                    </Button>
                                    {aiSummary && (
                                        <p className="max-w-xl rounded-md border border-accent/20 bg-accent/10 px-3 py-2 text-xs leading-5 text-body">
                                            {aiSummary}
                                        </p>
                                    )}
                                </div>
                            )}
                        </div>
                        <div className="space-y-2 rounded-md border border-line bg-surface-2 p-3">
                            <p className="text-xs font-semibold uppercase tracking-wide text-muted">Tasks</p>
                            {(data.client_breakdown || []).length === 0 && (
                                <p className="text-xs text-muted">No tracked client work for this day.</p>
                            )}
                            <ul className="space-y-1">
                                {(data.client_breakdown || []).map((row) => (
                                    <li key={row.client} className="flex items-center justify-between text-sm">
                                        <span className="truncate text-body">{row.client}</span>
                                        <strong className="text-body">{fmtHm(row.total_seconds)}</strong>
                                    </li>
                                ))}
                            </ul>
                            {clientBreakdownTotal !== (data.totals?.day || 0) && data.totals?.day > clientBreakdownTotal && (
                                <p className="border-t border-line pt-1 text-[11px] text-subtle">
                                    Sessions without a client: {fmtHm((data.totals?.day || 0) - clientBreakdownTotal)}
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="border-t border-line px-5 py-4">
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-muted">Activity across the day</p>
                        <HourRuler date={activeDate} bands={data.activity_bands || []} />
                    </div>
                </Card>

                {/* View switch + optional day-wide apps/URLs + session list */}
                <Card padded={false}>
                    <div className="flex items-center gap-1 border-b border-line px-5 py-3">
                        <button
                            type="button"
                            onClick={() => setView('tasks')}
                            className={cx(
                                'rounded-md px-3 py-1.5 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app',
                                view === 'tasks' ? 'bg-accent text-white' : 'text-muted hover:bg-surface-2 hover:text-body',
                            )}
                        >
                            Tasks
                        </button>
                        <button
                            type="button"
                            onClick={() => setView('apps')}
                            className={cx(
                                'rounded-md px-3 py-1.5 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app',
                                view === 'apps' ? 'bg-accent text-white' : 'text-muted hover:bg-surface-2 hover:text-body',
                            )}
                        >
                            Apps &amp; URLs
                        </button>
                    </div>

                    {view === 'apps' && (
                        <div className="grid grid-cols-1 gap-4 border-b border-line px-5 py-4 md:grid-cols-2">
                            <div className="space-y-2 rounded-md border border-line bg-surface-2 p-3">
                                <p className="text-[11px] font-semibold uppercase tracking-wide text-muted">Top apps today</p>
                                <RollupList items={data.day_apps} icon={Laptop} emptyLabel="No app activity tracked yet." />
                            </div>
                            <div className="space-y-2 rounded-md border border-line bg-surface-2 p-3">
                                <p className="text-[11px] font-semibold uppercase tracking-wide text-muted">Top URLs today</p>
                                <RollupList items={data.day_urls} icon={Globe} emptyLabel="No browser activity tracked yet." />
                            </div>
                        </div>
                    )}

                    <div className="space-y-8 px-5 pb-8 pt-5">
                        {loading && (
                            <p className="inline-flex items-center gap-2 text-xs text-subtle"><Spinner size={14} /> Loading…</p>
                        )}
                        {(data.sessions || []).length === 0 && !loading && (
                            <EmptyState
                                icon={<Clock className="h-5 w-5" />}
                                title="No tracking sessions"
                                description={`Nothing was tracked for ${data.day_label}.`}
                            />
                        )}
                        {(data.sessions || []).map((session) => (
                            <SessionCard
                                key={session.id}
                                session={session}
                                canViewScreenshots={permissions.view_screenshots}
                                canManageScreenshots={permissions.manage_screenshots}
                                canDeleteScreenshots={permissions.delete_screenshots}
                                view={view}
                                preview={preview}
                                onShotChanged={() => reload(activeDate, activeUserId)}
                                selectedShots={selectedShots}
                                onToggleSelect={toggleShot}
                                onSelectSession={selectSession}
                                onRequestDelete={(ids) => setDeleteIds(ids)}
                                onRequestDeleteSession={(info) => setDeleteSession(info)}
                                onOpenShot={(id) => setLightboxId(id)}
                            />
                        ))}
                    </div>

                    <div className="flex flex-wrap items-center gap-4 border-t border-line bg-surface-2 px-5 py-3 text-sm">
                        {permissions.add_offline_time && (
                            <Link href={route('work-hours.create', { date: activeDate })} className="inline-flex items-center gap-1 text-success hover:brightness-110">
                                <Plus className="h-4 w-4" />
                                Add offline time
                            </Link>
                        )}
                        <button type="button" onClick={openHistory} className="inline-flex items-center gap-1 text-muted hover:text-body">
                            <History className="h-4 w-4" />
                            History of changes
                        </button>
                        <button type="button" onClick={openDesktopApp} className="inline-flex items-center gap-1 text-muted hover:text-body">
                            <MonitorPlay className="h-4 w-4" />
                            Open desktop tracker
                        </button>
                        {showDownloadHint && (
                            <span className="inline-flex items-center gap-2 rounded-md bg-warning-soft px-2 py-1 text-xs text-warning">
                                Tracker not installed?
                                <Link href={route('desktop-downloads.index')} className="font-semibold underline">Download it here</Link>
                            </span>
                        )}
                        <span className="ml-auto inline-flex items-center gap-1 text-xs text-subtle">
                            <Clock className="h-3 w-3" /> Times in {DISPLAY.timezone} timezone
                        </span>
                    </div>
                </Card>
            </div>

            {/* Screenshot lightbox — standardized on the shared Modal */}
            <Modal show={!!lightboxShot} maxWidth="2xl" onClose={() => setLightboxId(null)}>
                {lightboxShot && (
                    <div className="flex flex-col">
                        <div className="flex items-center justify-between gap-3 border-b border-line px-4 py-3 text-sm text-body">
                            <div className="min-w-0">
                                <div className="flex items-center gap-2 font-semibold text-body">
                                    <span className={cx('inline-block h-2.5 w-2.5 rounded-full', activityDot(lightboxShot.activity_percent))} />
                                    {fmtTime(lightboxShot.captured_at)}
                                    <span className="font-normal text-muted">· activity {lightboxShot.activity_percent ?? 0}%</span>
                                    {lightboxShot.is_flagged && <Flag className="h-4 w-4 text-danger" />}
                                </div>
                                <div className="truncate text-xs text-muted">
                                    {(lightboxShot.url_domain || lightboxShot.active_app || 'Unknown')}
                                    {lightboxShot.active_window_title ? ` — ${lightboxShot.active_window_title}` : ''}
                                    <span className="ml-2 text-subtle">{lightboxIndex + 1} / {allShots.length}</span>
                                </div>
                            </div>
                            <div className="flex shrink-0 items-center gap-2">
                                {permissions.manage_screenshots && (
                                    <Button variant="secondary" size="sm" onClick={flagFromLightbox} icon={<Flag className="h-3.5 w-3.5" />}>
                                        {lightboxShot.is_flagged ? 'Unflag' : 'Flag'}
                                    </Button>
                                )}
                                {permissions.delete_screenshots && (
                                    <Button variant="danger" size="sm" onClick={() => { const id = lightboxShot.id; setLightboxId(null); setDeleteIds([id]); }} icon={<Trash2 className="h-3.5 w-3.5" />}>
                                        Delete &amp; remove time
                                    </Button>
                                )}
                                <Button variant="ghost" size="sm" onClick={() => setLightboxId(null)} aria-label="Close viewer" icon={<X className="h-4 w-4" />} />
                            </div>
                        </div>
                        <div className="relative flex items-center justify-center bg-app px-4 py-4">
                            <button
                                type="button"
                                onClick={() => stepLightbox(-1)}
                                disabled={lightboxIndex <= 0}
                                className="absolute left-3 z-10 flex h-11 w-11 items-center justify-center rounded-full border border-line bg-surface/90 text-body transition hover:bg-surface-2 disabled:opacity-30"
                                aria-label="Previous"
                            >
                                <ChevronLeft className="h-6 w-6" />
                            </button>
                            {lightboxShot.image_url || lightboxShot.thumbnail_url ? (
                                <img
                                    src={lightboxShot.image_url || lightboxShot.thumbnail_url}
                                    alt={lightboxShot.active_window_title || 'Screenshot'}
                                    className="max-h-[70vh] max-w-full rounded-lg object-contain"
                                />
                            ) : (
                                <div className={cx('flex h-64 w-full items-center justify-center rounded-lg', lightboxShot.tint || 'bg-surface-2')}>
                                    <MonitorPlay className="h-10 w-10 text-body/30" />
                                </div>
                            )}
                            <button
                                type="button"
                                onClick={() => stepLightbox(1)}
                                disabled={lightboxIndex >= allShots.length - 1}
                                className="absolute right-3 z-10 flex h-11 w-11 items-center justify-center rounded-full border border-line bg-surface/90 text-body transition hover:bg-surface-2 disabled:opacity-30"
                                aria-label="Next"
                            >
                                <ChevronRight className="h-6 w-6" />
                            </button>
                        </div>
                        <p className="border-t border-line py-2 text-center text-[11px] text-subtle">
                            Use ← → arrow keys to move between screenshots · Esc to close
                        </p>
                    </div>
                )}
            </Modal>

            {/* Selection toolbar for bulk screenshot deletion */}
            {selectedShots.size > 0 && !deleteIds && (
                <div className="fixed bottom-5 left-1/2 z-40 flex -translate-x-1/2 items-center gap-3 rounded-full border border-line bg-surface px-4 py-2.5 shadow-2xl">
                    <span className="text-sm font-semibold text-body">{selectedShots.size} screenshot{selectedShots.size === 1 ? '' : 's'} selected</span>
                    <Button variant="danger" size="sm" onClick={() => setDeleteIds([...selectedShots])}>Delete &amp; remove time</Button>
                    <Button variant="secondary" size="sm" onClick={() => setSelectedShots(new Set())}>Clear</Button>
                </div>
            )}

            {/* Delete confirmation — screenshots or whole session */}
            <Modal show={!!(deleteIds || deleteSession)} maxWidth="md" closeable={!deleting} onClose={closeDeleteModals}>
                {(deleteIds || deleteSession) && (
                    <div className="p-5">
                        <h3 className="text-base font-bold text-body">
                            {deleteSession
                                ? 'Delete this whole session?'
                                : `Delete ${deleteIds.length} screenshot${deleteIds.length === 1 ? '' : 's'}?`}
                        </h3>
                        {deleteSession ? (
                            <p className="mt-2 text-sm leading-6 text-muted">
                                <span className="font-medium text-body">{deleteSession.label}</span><br />
                                All of it goes: <span className="font-semibold text-danger">{fmtHm(deleteSession.seconds)} of tracked time</span>,
                                every screenshot, the apps &amp; URLs data, and the synced report hours.
                                Recorded in the audit history.
                            </p>
                        ) : (
                            <p className="mt-2 text-sm leading-6 text-muted">
                                The tracked time these screenshots represent (the minutes between each one and the previous capture)
                                will be <span className="font-semibold text-danger">removed from the session and from reports</span>,
                                along with the matching apps &amp; URLs data. Everything is recorded in the audit history.
                            </p>
                        )}
                        <Textarea
                            value={deleteReason}
                            onChange={(e) => setDeleteReason(e.target.value)}
                            rows={2}
                            placeholder="Reason (e.g. watching YouTube — not work)…"
                            className="mt-3"
                        />
                        <div className="mt-4 flex justify-end gap-2">
                            <Button variant="secondary" onClick={closeDeleteModals} disabled={deleting}>Cancel</Button>
                            <Button variant="danger" onClick={confirmDelete} loading={deleting}>
                                {deleteSession ? 'Delete entire session' : 'Delete & remove time'}
                            </Button>
                        </div>
                    </div>
                )}
            </Modal>

            {/* History of changes */}
            <Modal show={historyOpen} maxWidth="2xl" onClose={() => setHistoryOpen(false)}>
                <div className="flex items-center justify-between border-b border-line px-4 py-3">
                    <div>
                        <h3 className="text-sm font-semibold text-body">History of changes</h3>
                        <p className="text-xs text-muted">{data.day_label} · {targetUser.name}</p>
                    </div>
                    <Button variant="ghost" size="sm" onClick={() => setHistoryOpen(false)} aria-label="Close" icon={<X className="h-4 w-4" />} />
                </div>
                <div className="max-h-[65vh] overflow-y-auto px-4 py-3">
                    {historyLoading && <p className="inline-flex items-center gap-2 text-sm text-muted"><Spinner size={14} /> Loading…</p>}
                    {!historyLoading && history.length === 0 && (
                        <p className="text-sm text-muted">No edits recorded for this day.</p>
                    )}
                    <ul className="divide-y divide-line">
                        {history.map((entry) => (
                            <li key={entry.id} className="py-3 text-sm">
                                <div className="flex items-center justify-between">
                                    <span className="font-medium text-body">{entry.action_label}</span>
                                    <span className="text-xs text-muted">{fmtDateTime(entry.created_at)}</span>
                                </div>
                                <div className="mt-1 text-xs text-muted">
                                    By <strong className="text-body">{entry.actor_name}</strong>
                                    {entry.tracking_screenshot_id && <span className="text-subtle"> · screenshot #{entry.tracking_screenshot_id}</span>}
                                    {entry.tracking_session_id && <span className="text-subtle"> · session #{entry.tracking_session_id}</span>}
                                </div>
                                {entry.reason && (
                                    <p className="mt-1 rounded bg-surface-2 px-2 py-1 text-xs text-body">“{entry.reason}”</p>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
