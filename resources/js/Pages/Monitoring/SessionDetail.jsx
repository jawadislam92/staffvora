import React, { useState, useEffect, useCallback } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import Modal from '@/Components/Modal';
import { Button, Card, Badge, EmptyState } from '@/Components/ui';
import { X, ChevronLeft, ChevronRight, ArrowLeft, MonitorPlay, Flag, Camera, Lock } from 'lucide-react';

const cx = (...a) => a.filter(Boolean).join(' ');

function fmtDuration(seconds) {
    if (!seconds) return '0m';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    return h ? `${h}h ${m}m` : `${m}m`;
}

// Honors the company display settings (timezone + 12/24h) instead of the
// viewer's machine locale.
let DISPLAY = { timezone: 'Asia/Karachi', format: '12' };

function fmtTime(iso) {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleTimeString('en-US', {
            timeZone: DISPLAY.timezone,
            hour: 'numeric', minute: '2-digit',
            hour12: String(DISPLAY.format) !== '24',
        });
    } catch {
        return iso;
    }
}

function statusTone(status) {
    if (status === 'active') return 'success';
    if (status === 'abandoned') return 'warning';
    return 'neutral';
}

// Activity strength -> a token-colored dot. Low activity stays neutral (it is
// not an error), medium warns, high reads as healthy.
function activityDot(percent) {
    if (percent >= 60) return 'bg-success';
    if (percent >= 30) return 'bg-warning';
    return 'bg-surface-2 ring-1 ring-line';
}

// ---------------------------------------------------------------------------
// DEV_SAMPLE — one self-contained tracking session used only when `preview` is
// true. Screenshot thumbnails are solid token-colored placeholders (see
// `tint`), never external images.
// ---------------------------------------------------------------------------
const SAMPLE_TINTS = ['bg-accent/15', 'bg-info-soft', 'bg-success-soft', 'bg-warning-soft'];

function buildDevSample() {
    const today = new Date().toISOString().slice(0, 10);
    const apps = ['VS Code', 'Chrome', 'Figma', 'Slack', 'Terminal'];
    const domains = ['github.com', 'figma.com', 'staffvora.com', null, 'docs.google.com'];
    const screenshots = Array.from({ length: 8 }, (_, i) => {
        const hh = String(9 + Math.floor(i / 4)).padStart(2, '0');
        const mm = String((i % 4) * 15).padStart(2, '0');
        return {
            id: 900 + i,
            captured_at: `${today}T${hh}:${mm}:00Z`,
            thumbnail_url: null,
            image_url: null,
            tint: SAMPLE_TINTS[i % SAMPLE_TINTS.length],
            activity_percent: [78, 92, 41, 66, 88, 24, 71, 58][i],
            active_app: apps[i % apps.length],
            active_window_title: `${apps[i % apps.length]} — sample window ${i + 1}`,
            url_domain: domains[i % domains.length],
            is_flagged: i === 2,
        };
    });
    return {
        auth: { user: { id: 99, name: 'Jawad', email: 'jawad@staffvora.com', role: 'owner', is_super_admin: true, permissions: [] } },
        session: {
            id: 1041,
            user: { id: 1, name: 'Ayesha Khan', email: 'ayesha@staffvora.com' },
            client: { name: 'Northwind Retail' },
            work_type: 'client_work',
            status: 'active',
            started_at: `${today}T09:05:00Z`,
            stopped_at: `${today}T12:20:00Z`,
            total_seconds: 11700,
            activity_percent: 84,
            task_note: 'Checkout redesign — building the new payment step and wiring the address form validation.',
        },
        screenshots,
        permissions: { view_screenshots: true, delete_screenshots: true },
    };
}

function ScreenshotTile({ shot, onOpen }) {
    return (
        <button
            type="button"
            onClick={onOpen}
            className={cx(
                'group block overflow-hidden rounded-lg border border-line bg-surface-2 text-left transition',
                'hover:ring-2 hover:ring-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app',
            )}
        >
            {shot.thumbnail_url ? (
                <img
                    src={shot.thumbnail_url}
                    alt={`Screenshot at ${fmtTime(shot.captured_at)}`}
                    className="h-32 w-full object-cover"
                    loading="lazy"
                />
            ) : shot.tint ? (
                <div className={cx('flex h-32 w-full items-center justify-center', shot.tint)}>
                    <MonitorPlay className="h-6 w-6 text-body/30" />
                </div>
            ) : (
                <div className="flex h-32 w-full items-center justify-center text-xs text-subtle">no preview</div>
            )}
            <div className="space-y-0.5 p-2 text-xs">
                <div className="flex items-center justify-between gap-2">
                    <span className="font-medium text-body">{fmtTime(shot.captured_at)}</span>
                    <span className="flex items-center gap-1.5">
                        {shot.is_flagged && <Flag className="h-3 w-3 text-danger" />}
                        <span className={cx('inline-block h-2 w-2 rounded-full', activityDot(shot.activity_percent))} title={`Activity ${shot.activity_percent ?? 0}%`} />
                    </span>
                </div>
                <div className="truncate text-muted">{shot.url_domain || shot.active_app || '—'}</div>
                <div className="text-subtle">Activity: {shot.activity_percent ?? 0}%</div>
            </div>
        </button>
    );
}

export default function SessionDetail({ auth, session, screenshots = [], permissions = {}, preview = false }) {
    // Preview: swap every prop for the built-in offline sample and a mock
    // super-admin viewer, so the page renders in full with no network/props.
    if (preview) {
        const sample = buildDevSample();
        auth = auth || sample.auth;
        session = sample.session;
        screenshots = sample.screenshots;
        permissions = sample.permissions;
    }

    DISPLAY = preview ? DISPLAY : (usePage().props.display || DISPLAY);

    // Lightbox is driven by index so Prev/Next can step through the grid.
    const [lightboxIndex, setLightboxIndex] = useState(null);
    const shot = lightboxIndex !== null ? screenshots[lightboxIndex] : null;

    const step = useCallback((delta) => {
        setLightboxIndex((current) => {
            if (current === null) return current;
            const next = current + delta;
            if (next < 0 || next >= screenshots.length) return current;
            return next;
        });
    }, [screenshots.length]);

    useEffect(() => {
        if (lightboxIndex === null) return;
        const onKey = (e) => {
            if (e.key === 'ArrowLeft') step(-1);
            else if (e.key === 'ArrowRight') step(1);
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [lightboxIndex, step]);

    const meta = [
        { label: 'User', value: session.user?.name || '—' },
        { label: 'Client', value: session.client?.name || '—' },
        { label: 'Work type', value: session.work_type || '—' },
        { label: 'Started', value: fmtTime(session.started_at) },
        { label: 'Stopped', value: fmtTime(session.stopped_at) },
        { label: 'Duration', value: fmtDuration(session.total_seconds) },
        { label: 'Activity', value: `${session.activity_percent ?? 0}%` },
    ];

    return (
        <AuthenticatedLayout user={auth.user} header={`Tracking Session #${session.id}`}>
            <Head title={`Session #${session.id}`} />

            <div className="mx-auto max-w-[1600px] space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                <Button
                    as={Link}
                    href={preview ? '#' : route('monitoring.sessions')}
                    variant="ghost"
                    size="sm"
                    icon={<ArrowLeft className="h-4 w-4" />}
                >
                    Back to sessions
                </Button>

                <Card>
                    <div className="grid grid-cols-2 gap-x-4 gap-y-5 md:grid-cols-4">
                        {meta.map((m) => (
                            <div key={m.label}>
                                <div className="text-xs uppercase tracking-wide text-muted">{m.label}</div>
                                <div className="mt-1 font-medium text-body">{m.value}</div>
                            </div>
                        ))}
                        <div>
                            <div className="text-xs uppercase tracking-wide text-muted">Status</div>
                            <div className="mt-1">
                                <Badge tone={statusTone(session.status)} dot>{session.status}</Badge>
                            </div>
                        </div>
                        {session.task_note && (
                            <div className="col-span-full">
                                <div className="text-xs uppercase tracking-wide text-muted">Task note</div>
                                <div className="mt-1 whitespace-pre-wrap font-medium text-body">{session.task_note}</div>
                            </div>
                        )}
                    </div>
                </Card>

                <Card
                    header={
                        <div className="flex items-center justify-between gap-4 border-b border-line px-5 py-4">
                            <div className="font-semibold text-body">Screenshots</div>
                            {permissions?.view_screenshots && screenshots.length > 0 && (
                                <Badge tone="neutral">{screenshots.length}</Badge>
                            )}
                        </div>
                    }
                >
                    {!permissions?.view_screenshots ? (
                        <EmptyState
                            icon={<Lock className="h-5 w-5" />}
                            title="Screenshots hidden"
                            description="You do not have permission to view screenshots for this session."
                        />
                    ) : screenshots.length === 0 ? (
                        <EmptyState
                            icon={<Camera className="h-5 w-5" />}
                            title="No screenshots captured"
                            description="This session recorded no screenshots."
                        />
                    ) : (
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                            {screenshots.map((s, i) => (
                                <ScreenshotTile key={s.id} shot={s} onOpen={() => setLightboxIndex(i)} />
                            ))}
                        </div>
                    )}
                </Card>
            </div>

            {/* Screenshot lightbox — standardized on the shared Modal */}
            <Modal show={shot !== null} maxWidth="2xl" onClose={() => setLightboxIndex(null)}>
                {shot && (
                    <div className="flex flex-col">
                        <div className="flex items-center justify-between gap-3 border-b border-line px-4 py-3 text-sm text-body">
                            <div className="min-w-0">
                                <div className="flex items-center gap-2 font-semibold text-body">
                                    <span className={cx('inline-block h-2.5 w-2.5 rounded-full', activityDot(shot.activity_percent))} />
                                    {fmtTime(shot.captured_at)}
                                    <span className="font-normal text-muted">· activity {shot.activity_percent ?? 0}%</span>
                                    {shot.is_flagged && <Flag className="h-4 w-4 text-danger" />}
                                </div>
                                <div className="truncate text-xs text-muted">
                                    {shot.url_domain || shot.active_app || 'Unknown'}
                                    {shot.active_window_title ? ` — ${shot.active_window_title}` : ''}
                                    <span className="ml-2 text-subtle">{lightboxIndex + 1} / {screenshots.length}</span>
                                </div>
                            </div>
                            <Button variant="ghost" size="sm" onClick={() => setLightboxIndex(null)} aria-label="Close viewer" icon={<X className="h-4 w-4" />} />
                        </div>
                        <div className="relative flex items-center justify-center bg-app px-4 py-4">
                            <button
                                type="button"
                                onClick={() => step(-1)}
                                disabled={lightboxIndex <= 0}
                                className="absolute left-3 z-10 flex h-11 w-11 items-center justify-center rounded-full border border-line bg-surface/90 text-body transition hover:bg-surface-2 disabled:opacity-30"
                                aria-label="Previous"
                            >
                                <ChevronLeft className="h-6 w-6" />
                            </button>
                            {shot.image_url || shot.thumbnail_url ? (
                                <img
                                    src={shot.image_url || shot.thumbnail_url}
                                    alt={shot.active_window_title || 'Screenshot'}
                                    className="max-h-[70vh] max-w-full rounded-lg object-contain"
                                />
                            ) : (
                                <div className={cx('flex h-64 w-full items-center justify-center rounded-lg', shot.tint || 'bg-surface-2')}>
                                    <MonitorPlay className="h-10 w-10 text-body/30" />
                                </div>
                            )}
                            <button
                                type="button"
                                onClick={() => step(1)}
                                disabled={lightboxIndex >= screenshots.length - 1}
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
        </AuthenticatedLayout>
    );
}
