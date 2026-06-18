import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ChevronLeft, ChevronRight, Clock, Flag, Globe, History, Laptop, MonitorPlay, Plus, Sparkles, Trash2, X } from 'lucide-react';

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

function activityDot(percent) {
    if (percent >= 60) return 'bg-emerald-500';
    if (percent >= 30) return 'bg-amber-400';
    return 'bg-slate-600';
}

function sessionLabel(session) {
    return session.client_name || session.task_note || 'Untracked';
}

function isoFromDate(date, day) {
    const d = new Date(date);
    d.setDate(day);
    return d.toISOString().slice(0, 10);
}

function shiftDate(iso, deltaDays) {
    const d = new Date(iso);
    d.setDate(d.getDate() + deltaDays);
    return d.toISOString().slice(0, 10);
}

function MonthStrip({ days, onPick }) {
    const selRef = useRef(null);
    // Center the selected day so you never have to scroll sideways hunting for
    // it — the strip lands on the active date every time it changes.
    useEffect(() => {
        selRef.current?.scrollIntoView({ inline: 'center', block: 'nearest' });
    }, [days]);

    return (
        <div className="overflow-x-auto">
            <div className="flex min-w-full gap-1 px-2 pb-3 pt-1">
                {days.map((d) => {
                    const has = d.total_seconds > 0;
                    return (
                        <button
                            key={d.date}
                            ref={d.is_selected ? selRef : null}
                            type="button"
                            onClick={() => onPick(d.date)}
                            className={[
                                'flex w-10 shrink-0 flex-col items-center rounded-md border px-1 py-2 text-xs transition',
                                d.is_selected
                                    ? 'border-orange-500 bg-orange-500/15 text-orange-300'
                                    : d.is_today
                                    ? 'border-slate-500 bg-slate-800 text-white'
                                    : 'border-transparent text-slate-400 hover:bg-white/10',
                            ].join(' ')}
                            title={fmtHm(d.total_seconds)}
                        >
                            <span className="text-[10px] uppercase">{d.weekday}</span>
                            <span className="text-base font-semibold leading-none">{d.day}</span>
                            <span className={['mt-1 h-1.5 w-6 rounded-full', has ? 'bg-emerald-400' : 'bg-transparent'].join(' ')} />
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
                        className={[
                            'h-full',
                            state === 'active' ? 'bg-emerald-400' : state === 'idle' ? 'bg-amber-300' : 'bg-slate-800',
                        ].join(' ')}
                        title={`${Math.floor(i / SLOTS_PER_HOUR)}:${String((i % SLOTS_PER_HOUR) * (60 / SLOTS_PER_HOUR)).padStart(2, '0')} ${state}`}
                    />
                ))}
            </div>
            <div className="grid border-b border-slate-800 text-[10px] text-slate-500" style={{ gridTemplateColumns: 'repeat(24, minmax(0, 1fr))' }}>
                {HOURS.map((h) => (
                    <div key={h} className={['flex h-5 items-end justify-start border-l px-1 pb-0.5', isToday && h === nowHour ? 'border-emerald-500 bg-emerald-500/10' : 'border-slate-800'].join(' ')}>
                        {h % 3 === 0 ? fmtHour(h) : ''}
                    </div>
                ))}
            </div>
            <div className="flex items-center gap-3 text-[10px] text-slate-400">
                <span className="inline-flex items-center gap-1"><span className="inline-block h-2 w-3 rounded-sm bg-emerald-400" /> Active</span>
                <span className="inline-flex items-center gap-1"><span className="inline-block h-2 w-3 rounded-sm bg-amber-300" /> Idle</span>
                <span className="inline-flex items-center gap-1"><span className="inline-block h-2 w-3 rounded-sm bg-slate-700" /> No tracking</span>
            </div>
        </div>
    );
}

function RollupList({ items, icon: Icon, emptyLabel }) {
    if (!items || items.length === 0) {
        return <p className="text-xs text-slate-400">{emptyLabel}</p>;
    }
    const max = Math.max(...items.map((x) => x.total_seconds || 0), 1);
    return (
        <ul className="space-y-2">
            {items.map((item) => {
                const pct = Math.max(2, Math.round((item.total_seconds / max) * 100));
                return (
                    <li key={item.name} className="space-y-1">
                        <div className="flex items-center justify-between text-xs">
                            <span className="inline-flex items-center gap-1.5 truncate text-slate-300">
                                {Icon && <Icon className="h-3 w-3 text-slate-500" />}
                                <span className="truncate">{item.name || 'Unknown'}</span>
                            </span>
                            <strong className="text-slate-100">{fmtHm(item.total_seconds)}</strong>
                        </div>
                        <div className="h-1.5 overflow-hidden rounded-full bg-slate-800">
                            <div className="h-full bg-emerald-400" style={{ width: `${pct}%` }} />
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}

function ScreenshotTile({ shot, canManage, canDelete, onChanged, selected = false, onToggleSelect = null, onRequestDelete = null, onOpen = null }) {
    const [busy, setBusy] = useState(false);

    const toggleFlag = () => {
        if (!canManage || busy) return;
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
        <figure className={`relative overflow-hidden rounded-md border bg-slate-950 ${selected ? 'border-rose-500/70 ring-1 ring-rose-500/50' : 'border-slate-800'}`}>
            <div className="flex items-center justify-between bg-slate-900 px-2 py-1 text-[11px] text-slate-400">
                <span className="flex items-center gap-1.5">
                    {canDelete && onToggleSelect && (
                        <input
                            type="checkbox"
                            checked={selected}
                            onChange={() => onToggleSelect(shot.id)}
                            title="Select for deletion"
                            className="h-3.5 w-3.5 rounded border-slate-600 bg-slate-800 text-rose-500 focus:ring-rose-500"
                        />
                    )}
                    {fmtTime(shot.captured_at)}
                </span>
                <div className="flex items-center gap-1.5">
                    {shot.is_flagged && <Flag className="h-3 w-3 text-rose-500" />}
                    <span className={['inline-block h-2 w-2 rounded-full', activityDot(shot.activity_percent)].join(' ')} title={`Activity ${shot.activity_percent}%`} />
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
            ) : (
                <div className="flex h-32 w-full items-center justify-center text-xs text-slate-500">Image unavailable</div>
            )}
            {(shot.active_app || shot.url_domain) && (
                <figcaption className="truncate bg-slate-900 px-2 py-1 text-[11px] text-slate-400" title={shot.active_window_title || ''}>
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
                            className={[
                                'inline-flex h-6 w-6 items-center justify-center rounded-full border bg-slate-900/90 shadow-sm transition',
                                shot.is_flagged ? 'border-rose-500/40 text-rose-400 hover:bg-rose-500/10' : 'border-slate-700 text-slate-400 hover:bg-slate-800',
                            ].join(' ')}
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
                            className="inline-flex h-6 w-6 items-center justify-center rounded-full border border-slate-700 bg-slate-900/90 text-slate-400 shadow-sm transition hover:bg-rose-500/10 hover:text-rose-400"
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

function SessionCard({ session, canViewScreenshots, canManageScreenshots, canDeleteScreenshots, view, onShotChanged, selectedShots, onToggleSelect, onSelectSession, onRequestDelete, onRequestDeleteSession, onOpenShot }) {
    const daySeconds = session.day_seconds ?? session.total_seconds;
    const isSplit = session.started_before_day || session.continues_after_day;
    return (
        <section className="space-y-3">
            <header className="flex flex-wrap items-center gap-2 text-sm font-semibold">
                <span className="inline-flex h-2 w-2 rounded-full bg-emerald-500" />
                <span className="text-orange-400">{fmtTime(session.started_at)} - {fmtTime(session.stopped_at)}</span>
                <span className="text-slate-200">• {sessionLabel(session)}</span>
                {session.started_before_day && (
                    <span className="rounded bg-white/10 px-1.5 py-0.5 text-[10px] font-medium text-slate-300">
                        overnight · started {fmtDayTime(session.started_at)}
                    </span>
                )}
                {session.continues_after_day && (
                    <span className="rounded bg-white/10 px-1.5 py-0.5 text-[10px] font-medium text-slate-300">
                        continues past midnight
                    </span>
                )}
                <span className="ml-auto flex items-center gap-2 text-xs font-normal text-slate-400">
                    {fmtHm(daySeconds)}{isSplit ? ` this day of ${fmtHm(session.total_seconds)}` : ''} · activity {session.activity_percent ?? 0}%
                    {canDeleteScreenshots && view !== 'apps' && (session.screenshots || []).length > 0 && (
                        <button
                            type="button"
                            onClick={() => onSelectSession?.(session.screenshots.map((s) => s.id))}
                            className="rounded border border-slate-700 px-1.5 py-0.5 text-[10px] text-slate-300 hover:bg-slate-800"
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
                            className="rounded border border-rose-500/40 px-1.5 py-0.5 text-[10px] text-rose-300 hover:bg-rose-500/10"
                            title="Delete this whole session: its time, screenshots, and apps & URLs data"
                        >
                            Delete session
                        </button>
                    )}
                </span>
            </header>

            {view === 'apps' ? (
                <div className="grid grid-cols-1 gap-4 rounded-md border border-slate-800 bg-slate-950/60 p-3 sm:grid-cols-2">
                    <div className="space-y-2">
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Apps</p>
                        <RollupList items={session.apps} icon={Laptop} emptyLabel="No app data captured." />
                    </div>
                    <div className="space-y-2">
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">URLs</p>
                        <RollupList items={session.urls} icon={Globe} emptyLabel="No browser activity captured." />
                    </div>
                </div>
            ) : (
                <>
                    {!canViewScreenshots && session.screenshot_count_hidden > 0 && (
                        <p className="rounded-md bg-white/5 px-3 py-2 text-xs text-slate-400">
                            {session.screenshot_count_hidden} screenshot{session.screenshot_count_hidden === 1 ? '' : 's'} captured. Permission required to view.
                        </p>
                    )}

                    {canViewScreenshots && session.screenshots.length === 0 && (
                        <p className="text-xs text-slate-400">No screenshots captured for this session.</p>
                    )}

                    {canViewScreenshots && session.screenshots.length > 0 && (
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            {session.screenshots.map((shot) => (
                                <ScreenshotTile
                                    key={shot.id}
                                    shot={shot}
                                    canManage={canManageScreenshots}
                                    canDelete={canDeleteScreenshots}
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
}) {
    // Keep the module-level display config current for fmtTime/fmtDateTime.
    DISPLAY = usePage().props.display || DISPLAY;

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

    const confirmDelete = () => {
        if (deleting) return;
        setDeleting(true);
        const finish = () => {
            setDeleting(false);
            setDeleteIds(null);
            setDeleteSession(null);
            setDeleteReason('');
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
        let handled = false;
        const onBlur = () => { handled = true; };
        window.addEventListener('blur', onBlur);
        window.location.href = 'timetracker://open';
        setTimeout(() => {
            window.removeEventListener('blur', onBlur);
            if (!handled) setShowDownloadHint(true);
        }, 1600);
    };

    const reload = (nextDate, nextUserId) => {
        const d = nextDate ?? activeDate;
        const u = nextUserId ?? activeUserId;
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

    useEffect(() => {
        if (!historyOpen) return;
        const onEsc = (e) => e.key === 'Escape' && setHistoryOpen(false);
        document.addEventListener('keydown', onEsc);
        return () => document.removeEventListener('keydown', onEsc);
    }, [historyOpen]);

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
            if (e.key === 'Escape') setLightboxId(null);
            else if (e.key === 'ArrowRight') stepLightbox(1);
            else if (e.key === 'ArrowLeft') stepLightbox(-1);
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [lightboxShot, lightboxIndex, allShots]);

    const flagFromLightbox = () => {
        if (!lightboxShot) return;
        const next = !lightboxShot.is_flagged;
        const reason = next ? prompt('Reason for flagging? (optional)') ?? '' : '';
        router.patch(route('monitoring.screenshots.flag', { screenshot: lightboxShot.id }), { is_flagged: next, reason }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => reload(activeDate, activeUserId),
        });
    };

    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="text-xl font-semibold text-slate-900">Timeline</h2>}>
            <Head title="Timeline" />

            <div className="min-h-screen bg-slate-950">
            <div className="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
                <div className="overflow-hidden rounded-lg border border-slate-800 bg-slate-900 shadow">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-800 px-5 py-4">
                        <div className="flex items-center gap-3">
                            {showUserPicker ? (
                                <select
                                    value={activeUserId}
                                    onChange={(e) => reload(activeDate, Number(e.target.value))}
                                    className="rounded border-slate-700 bg-slate-900 text-sm font-semibold text-slate-200 [color-scheme:dark]"
                                >
                                    {userOptions.map((u) => (
                                        <option key={u.id} value={u.id}>{u.name}</option>
                                    ))}
                                </select>
                            ) : (
                                <h1 className="text-base font-semibold text-white">{targetUser.name}</h1>
                            )}
                        </div>
                        <div className="flex items-center gap-2 text-xs text-slate-400">
                            <button type="button" onClick={() => reload(shiftDate(activeDate, -1))} className="rounded p-1 text-slate-300 hover:bg-white/10 hover:text-white" aria-label="Previous day">
                                <ChevronLeft className="h-4 w-4" />
                            </button>
                            <input
                                type="date"
                                value={activeDate}
                                onChange={(e) => reload(e.target.value)}
                                className="rounded border-slate-700 bg-slate-900 text-xs text-slate-200 [color-scheme:dark]"
                            />
                            <button type="button" onClick={() => reload(shiftDate(activeDate, 1))} className="rounded p-1 text-slate-300 hover:bg-white/10 hover:text-white" aria-label="Next day">
                                <ChevronRight className="h-4 w-4" />
                            </button>
                            <button type="button" onClick={() => reload(new Date().toISOString().slice(0, 10))} className="ml-2 rounded border border-slate-700 px-2 py-1 text-xs text-slate-300 hover:bg-slate-800">
                                Today
                            </button>
                        </div>
                    </div>

                    <MonthStrip days={data.month_strip || []} onPick={(d) => reload(d)} />

                    <div className="grid grid-cols-1 gap-5 border-t border-slate-800 px-5 py-5 md:grid-cols-[1fr_320px]">
                        <div className="space-y-2">
                            <p className="text-xs uppercase tracking-wide text-slate-400">{data.day_label}</p>
                            <div className="flex items-end gap-3">
                                <div>
                                    <div className="text-4xl font-bold text-white">{fmtHm(data.totals?.day || 0)}</div>
                                    <div className="text-[11px] uppercase tracking-wide text-slate-500">Tracked this day</div>
                                </div>
                                <div className="text-xs text-slate-400">
                                    This week: <strong className="text-slate-200">{fmtHm(data.totals?.week || 0)}</strong>
                                    <span className="mx-2">·</span>
                                    This month: <strong className="text-slate-200">{fmtHm(data.totals?.month || 0)}</strong>
                                </div>
                            </div>
                            <p className="text-xs text-slate-500">Desktop-tracker time. Week starts {weekStartsOn === 'sunday' ? 'Sunday' : 'Monday'}.</p>
                            {aiEnabled && (
                                <div className="space-y-2 pt-1">
                                    <button
                                        type="button"
                                        onClick={summarizeDay}
                                        disabled={aiLoading}
                                        className="inline-flex items-center gap-1.5 rounded-lg bg-gradient-to-r from-orange-500 to-amber-500 px-3 py-1.5 text-xs font-semibold text-white shadow-lg shadow-orange-500/25 transition hover:from-orange-600 hover:to-amber-600 disabled:opacity-60"
                                    >
                                        <Sparkles className="h-3.5 w-3.5" />
                                        {aiLoading ? 'Summarizing…' : 'Summarize this day'}
                                    </button>
                                    {aiSummary && (
                                        <p className="max-w-xl rounded-md border border-orange-500/20 bg-orange-500/10 px-3 py-2 text-xs leading-5 text-slate-200">
                                            {aiSummary}
                                        </p>
                                    )}
                                </div>
                            )}
                        </div>
                        <div className="space-y-2 rounded-md border border-slate-800 bg-slate-950/60 p-3">
                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Tasks</p>
                            {(data.client_breakdown || []).length === 0 && (
                                <p className="text-xs text-slate-400">No tracked client work for this day.</p>
                            )}
                            <ul className="space-y-1">
                                {(data.client_breakdown || []).map((row) => (
                                    <li key={row.client} className="flex items-center justify-between text-sm">
                                        <span className="truncate text-slate-300">{row.client}</span>
                                        <strong className="text-slate-100">{fmtHm(row.total_seconds)}</strong>
                                    </li>
                                ))}
                            </ul>
                            {clientBreakdownTotal !== (data.totals?.day || 0) && data.totals?.day > clientBreakdownTotal && (
                                <p className="border-t border-slate-800 pt-1 text-[11px] text-slate-500">
                                    Sessions without a client: {fmtHm((data.totals?.day || 0) - clientBreakdownTotal)}
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="border-t border-slate-800 px-5 py-4">
                        <HourRuler date={activeDate} bands={data.activity_bands || []} />
                    </div>

                    <div className="flex items-center gap-1 border-t border-slate-800 px-5 pt-3">
                        <button
                            type="button"
                            onClick={() => setView('tasks')}
                            className={[
                                'rounded-md px-3 py-1.5 text-sm transition',
                                view === 'tasks'
                                    ? 'bg-gradient-to-r from-orange-500 to-amber-500 text-white shadow-sm'
                                    : 'text-slate-300 hover:bg-white/10 hover:text-white',
                            ].join(' ')}
                        >
                            Tasks
                        </button>
                        <button
                            type="button"
                            onClick={() => setView('apps')}
                            className={[
                                'rounded-md px-3 py-1.5 text-sm transition',
                                view === 'apps'
                                    ? 'bg-gradient-to-r from-orange-500 to-amber-500 text-white shadow-sm'
                                    : 'text-slate-300 hover:bg-white/10 hover:text-white',
                            ].join(' ')}
                        >
                            Apps &amp; URLs
                        </button>
                    </div>

                    {view === 'apps' && (
                        <div className="grid grid-cols-1 gap-4 border-t border-slate-800 px-5 py-4 md:grid-cols-2">
                            <div className="space-y-2 rounded-md border border-slate-800 bg-slate-950/60 p-3">
                                <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Top apps today</p>
                                <RollupList items={data.day_apps} icon={Laptop} emptyLabel="No app activity tracked yet." />
                            </div>
                            <div className="space-y-2 rounded-md border border-slate-800 bg-slate-950/60 p-3">
                                <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Top URLs today</p>
                                <RollupList items={data.day_urls} icon={Globe} emptyLabel="No browser activity tracked yet." />
                            </div>
                        </div>
                    )}

                    <div className="space-y-8 px-5 pb-8 pt-4">
                        {loading && <p className="text-xs text-slate-500">Loading...</p>}
                        {(data.sessions || []).length === 0 && !loading && (
                            <div className="rounded-md border border-dashed border-slate-700 px-4 py-10 text-center text-sm text-slate-400">
                                No tracking sessions for {data.day_label}.
                            </div>
                        )}
                        {(data.sessions || []).map((session) => (
                            <SessionCard
                                key={session.id}
                                session={session}
                                canViewScreenshots={permissions.view_screenshots}
                                canManageScreenshots={permissions.manage_screenshots}
                                canDeleteScreenshots={permissions.delete_screenshots}
                                view={view}
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

                    <div className="flex flex-wrap items-center gap-4 border-t border-slate-800 bg-slate-950 px-5 py-3 text-sm">
                        {permissions.add_offline_time && (
                            <Link href={route('work-hours.create', { date: activeDate })} className="inline-flex items-center gap-1 text-emerald-400 hover:text-emerald-300">
                                <Plus className="h-4 w-4" />
                                Add offline time
                            </Link>
                        )}
                        <button type="button" onClick={openHistory} className="inline-flex items-center gap-1 text-slate-300 hover:text-white">
                            <History className="h-4 w-4" />
                            History of changes
                        </button>
                        <button type="button" onClick={openDesktopApp} className="inline-flex items-center gap-1 text-slate-300 hover:text-white">
                            <MonitorPlay className="h-4 w-4" />
                            Open desktop tracker
                        </button>
                        {showDownloadHint && (
                            <span className="inline-flex items-center gap-2 rounded-md bg-amber-500/15 px-2 py-1 text-xs text-amber-300">
                                Tracker not installed?
                                <Link href={route('desktop-downloads.index')} className="font-semibold underline">Download it here</Link>
                            </span>
                        )}
                        <span className="ml-auto inline-flex items-center gap-1 text-xs text-slate-500">
                            <Clock className="h-3 w-3" /> Times in your local timezone
                        </span>
                    </div>
                </div>
            </div>
            </div>

            {lightboxShot && (
                <div className="fixed inset-0 z-[70] flex flex-col bg-black/95" onClick={() => setLightboxId(null)}>
                    <div className="flex items-center justify-between gap-3 border-b border-white/10 bg-slate-950/80 px-4 py-3 text-sm text-slate-200" onClick={(e) => e.stopPropagation()}>
                        <div className="min-w-0">
                            <div className="flex items-center gap-2 font-semibold text-white">
                                <span className={['inline-block h-2.5 w-2.5 rounded-full', activityDot(lightboxShot.activity_percent)].join(' ')} />
                                {fmtTime(lightboxShot.captured_at)}
                                <span className="font-normal text-slate-400">· activity {lightboxShot.activity_percent ?? 0}%</span>
                                {lightboxShot.is_flagged && <Flag className="h-4 w-4 text-rose-400" />}
                            </div>
                            <div className="truncate text-xs text-slate-400">
                                {(lightboxShot.url_domain || lightboxShot.active_app || 'Unknown')}
                                {lightboxShot.active_window_title ? ` — ${lightboxShot.active_window_title}` : ''}
                                <span className="ml-2 text-slate-500">{lightboxIndex + 1} / {allShots.length}</span>
                            </div>
                        </div>
                        <div className="flex shrink-0 items-center gap-2">
                            {permissions.manage_screenshots && (
                                <button type="button" onClick={flagFromLightbox} className="inline-flex items-center gap-1 rounded-lg border border-slate-700 px-2.5 py-1.5 text-xs text-slate-200 hover:bg-slate-800">
                                    <Flag className="h-3.5 w-3.5" /> {lightboxShot.is_flagged ? 'Unflag' : 'Flag'}
                                </button>
                            )}
                            {permissions.delete_screenshots && (
                                <button type="button" onClick={() => { const id = lightboxShot.id; setLightboxId(null); setDeleteIds([id]); }} className="inline-flex items-center gap-1 rounded-lg bg-rose-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-rose-500">
                                    <Trash2 className="h-3.5 w-3.5" /> Delete &amp; remove time
                                </button>
                            )}
                            <button type="button" onClick={() => setLightboxId(null)} className="inline-flex items-center gap-1 rounded-lg border border-slate-600 bg-slate-800 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-slate-700" aria-label="Close viewer">
                                <X className="h-4 w-4" /> Close
                            </button>
                        </div>
                    </div>
                    {/* Clicking the dark area around the image closes the
                        viewer; the image and arrows stop the click. */}
                    <div className="relative flex flex-1 items-center justify-center overflow-hidden px-4 pb-4">
                        <button
                            type="button"
                            onClick={(e) => { e.stopPropagation(); stepLightbox(-1); }}
                            disabled={lightboxIndex <= 0}
                            className="absolute left-3 z-10 flex h-12 w-12 items-center justify-center rounded-full bg-slate-900/80 text-white hover:bg-slate-800 disabled:opacity-30"
                            aria-label="Previous"
                        >
                            <ChevronLeft className="h-7 w-7" />
                        </button>
                        <img
                            src={lightboxShot.image_url || lightboxShot.thumbnail_url}
                            alt={lightboxShot.active_window_title || 'Screenshot'}
                            onClick={(e) => e.stopPropagation()}
                            className="max-h-full max-w-full rounded-lg object-contain shadow-2xl"
                        />
                        <button
                            type="button"
                            onClick={(e) => { e.stopPropagation(); stepLightbox(1); }}
                            disabled={lightboxIndex >= allShots.length - 1}
                            className="absolute right-3 z-10 flex h-12 w-12 items-center justify-center rounded-full bg-slate-900/80 text-white hover:bg-slate-800 disabled:opacity-30"
                            aria-label="Next"
                        >
                            <ChevronRight className="h-7 w-7" />
                        </button>
                    </div>
                    <p className="pb-3 text-center text-[11px] text-slate-500" onClick={(e) => e.stopPropagation()}>
                        Use ← → arrow keys to move between screenshots · Esc to close
                    </p>
                </div>
            )}

            {selectedShots.size > 0 && !deleteIds && (
                <div className="fixed bottom-5 left-1/2 z-40 flex -translate-x-1/2 items-center gap-3 rounded-full border border-slate-700 bg-slate-900 px-4 py-2.5 shadow-2xl shadow-black/50">
                    <span className="text-sm font-semibold text-white">{selectedShots.size} screenshot{selectedShots.size === 1 ? '' : 's'} selected</span>
                    <button
                        type="button"
                        onClick={() => setDeleteIds([...selectedShots])}
                        className="rounded-full bg-rose-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-rose-500"
                    >
                        Delete & remove time
                    </button>
                    <button
                        type="button"
                        onClick={() => setSelectedShots(new Set())}
                        className="rounded-full border border-slate-700 px-3 py-1.5 text-sm text-slate-300 hover:bg-slate-800"
                    >
                        Clear
                    </button>
                </div>
            )}

            {(deleteIds || deleteSession) && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 px-4" onClick={() => !deleting && (setDeleteIds(null), setDeleteSession(null))}>
                    <div className="w-full max-w-md rounded-lg border border-slate-800 bg-slate-900 p-5 shadow-2xl" onClick={(e) => e.stopPropagation()}>
                        <h3 className="text-base font-bold text-white">
                            {deleteSession
                                ? 'Delete this whole session?'
                                : `Delete ${deleteIds.length} screenshot${deleteIds.length === 1 ? '' : 's'}?`}
                        </h3>
                        {deleteSession ? (
                            <p className="mt-2 text-sm leading-6 text-slate-400">
                                <span className="font-medium text-slate-300">{deleteSession.label}</span><br />
                                All of it goes: <span className="font-semibold text-rose-300">{fmtHm(deleteSession.seconds)} of tracked time</span>,
                                every screenshot, the apps &amp; URLs data, and the synced report hours.
                                Recorded in the audit history.
                            </p>
                        ) : (
                            <p className="mt-2 text-sm leading-6 text-slate-400">
                                The tracked time these screenshots represent (the minutes between each one and the previous capture)
                                will be <span className="font-semibold text-rose-300">removed from the session and from reports</span>,
                                along with the matching apps &amp; URLs data. Everything is recorded in the audit history.
                            </p>
                        )}
                        <textarea
                            value={deleteReason}
                            onChange={(e) => setDeleteReason(e.target.value)}
                            rows={2}
                            placeholder="Reason (e.g. watching YouTube — not work)…"
                            className="mt-3 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-200 placeholder-slate-500 focus:border-rose-500 focus:ring-rose-500"
                        />
                        <div className="mt-4 flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => { setDeleteIds(null); setDeleteSession(null); }}
                                disabled={deleting}
                                className="rounded-lg border border-slate-700 px-4 py-2 text-sm font-semibold text-slate-300 hover:bg-slate-800"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={confirmDelete}
                                disabled={deleting}
                                className="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-500 disabled:opacity-50"
                            >
                                {deleting ? 'Deleting…' : deleteSession ? 'Delete entire session' : 'Delete & remove time'}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {historyOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 px-4" onClick={() => setHistoryOpen(false)}>
                    <div className="max-h-[80vh] w-full max-w-2xl overflow-hidden rounded-lg border border-slate-800 bg-slate-900 shadow-2xl" onClick={(e) => e.stopPropagation()}>
                        <div className="flex items-center justify-between border-b border-slate-800 px-4 py-3">
                            <div>
                                <h3 className="text-sm font-semibold text-white">History of changes</h3>
                                <p className="text-xs text-slate-400">{data.day_label} · {targetUser.name}</p>
                            </div>
                            <button type="button" onClick={() => setHistoryOpen(false)} className="rounded p-1 text-slate-300 hover:bg-white/10 hover:text-white" aria-label="Close">
                                <X className="h-4 w-4" />
                            </button>
                        </div>
                        <div className="max-h-[65vh] overflow-y-auto px-4 py-3">
                            {historyLoading && <p className="text-sm text-slate-400">Loading...</p>}
                            {!historyLoading && history.length === 0 && (
                                <p className="text-sm text-slate-400">No edits recorded for this day.</p>
                            )}
                            <ul className="divide-y divide-slate-800">
                                {history.map((entry) => (
                                    <li key={entry.id} className="py-3 text-sm">
                                        <div className="flex items-center justify-between">
                                            <span className="font-medium text-slate-100">{entry.action_label}</span>
                                            <span className="text-xs text-slate-400">{fmtDateTime(entry.created_at)}</span>
                                        </div>
                                        <div className="mt-1 text-xs text-slate-400">
                                            By <strong className="text-slate-200">{entry.actor_name}</strong>
                                            {entry.tracking_screenshot_id && <span className="text-slate-500"> · screenshot #{entry.tracking_screenshot_id}</span>}
                                            {entry.tracking_session_id && <span className="text-slate-500"> · session #{entry.tracking_session_id}</span>}
                                        </div>
                                        {entry.reason && (
                                            <p className="mt-1 rounded bg-white/5 px-2 py-1 text-xs text-slate-300">“{entry.reason}”</p>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
