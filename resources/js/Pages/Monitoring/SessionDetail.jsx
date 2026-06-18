import React, { useState, useEffect, useCallback } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { X, ChevronLeft, ChevronRight } from 'lucide-react';

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

export default function SessionDetail({ auth, session, screenshots = [], permissions = {} }) {
    DISPLAY = usePage().props.display || DISPLAY;
    // Lightbox is driven by index so Prev/Next can step through the grid.
    const [lightboxIndex, setLightboxIndex] = useState(null);
    const open = lightboxIndex !== null && screenshots[lightboxIndex];

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
            if (e.key === 'Escape') setLightboxIndex(null);
            else if (e.key === 'ArrowLeft') step(-1);
            else if (e.key === 'ArrowRight') step(1);
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [lightboxIndex, step]);

    const shot = open ? screenshots[lightboxIndex] : null;

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl leading-tight">Tracking Session #{session.id}</h2>}
        >
            <Head title={`Session #${session.id}`} />

            <div className="min-h-screen bg-slate-950">
                <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                    <Link href={route('monitoring.sessions')} className="text-sm text-orange-400 hover:text-orange-300">
                        &larr; Back to sessions
                    </Link>

                    <div className="grid grid-cols-2 gap-4 rounded-lg border border-slate-800 bg-slate-900 p-6 text-sm shadow md:grid-cols-4">
                        <div><div className="text-slate-500">User</div><div className="font-medium text-slate-100">{session.user?.name}</div></div>
                        <div><div className="text-slate-500">Client</div><div className="font-medium text-slate-100">{session.client?.name || '—'}</div></div>
                        <div><div className="text-slate-500">Work type</div><div className="font-medium text-slate-100">{session.work_type || '—'}</div></div>
                        <div><div className="text-slate-500">Status</div><div className="font-medium text-slate-100">{session.status}</div></div>
                        <div><div className="text-slate-500">Started</div><div className="font-medium text-slate-100">{fmtTime(session.started_at)}</div></div>
                        <div><div className="text-slate-500">Stopped</div><div className="font-medium text-slate-100">{fmtTime(session.stopped_at)}</div></div>
                        <div><div className="text-slate-500">Duration</div><div className="font-medium text-slate-100">{fmtDuration(session.total_seconds)}</div></div>
                        <div><div className="text-slate-500">Activity</div><div className="font-medium text-slate-100">{session.activity_percent ?? 0}%</div></div>
                        {session.task_note && (
                            <div className="col-span-full">
                                <div className="text-slate-500">Task note</div>
                                <div className="whitespace-pre-wrap font-medium text-slate-200">{session.task_note}</div>
                            </div>
                        )}
                    </div>

                    <div className="rounded-lg border border-slate-800 bg-slate-900 p-6 shadow">
                        <h3 className="mb-4 text-lg font-semibold text-slate-100">Screenshots</h3>
                        {!permissions?.view_screenshots ? (
                            <p className="text-sm text-slate-500">You do not have permission to view screenshots for this session.</p>
                        ) : screenshots.length === 0 ? (
                            <p className="text-sm text-slate-500">No screenshots captured.</p>
                        ) : (
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                                {screenshots.map((s, i) => (
                                    <button
                                        key={s.id}
                                        type="button"
                                        onClick={() => setLightboxIndex(i)}
                                        className="block overflow-hidden rounded-lg border border-slate-800 bg-slate-950 text-left transition hover:ring-2 hover:ring-orange-400/70"
                                    >
                                        {s.thumbnail_url ? (
                                            <img
                                                src={s.thumbnail_url}
                                                alt={`Screenshot at ${fmtTime(s.captured_at)}`}
                                                className="h-32 w-full object-cover"
                                                loading="lazy"
                                            />
                                        ) : (
                                            <div className="flex h-32 w-full items-center justify-center text-xs text-slate-600">
                                                no preview
                                            </div>
                                        )}
                                        <div className="p-2 text-xs">
                                            <div className="font-medium text-slate-200">{fmtTime(s.captured_at)}</div>
                                            <div className="truncate text-slate-400">{s.active_app || '—'}</div>
                                            <div className="text-slate-500">Activity: {s.activity_percent ?? 0}%</div>
                                        </div>
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {open && (
                <div
                    className="fixed inset-0 z-[70] flex flex-col bg-slate-950/90 backdrop-blur-sm"
                    onClick={() => setLightboxIndex(null)}
                >
                    <div
                        className="flex items-center justify-between gap-3 border-b border-white/10 bg-slate-950/80 px-4 py-3 text-sm text-slate-300"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div className="min-w-0 truncate">
                            <span className="font-semibold text-slate-100">{fmtTime(shot.captured_at)}</span>
                            <span className="text-slate-400"> — {shot.active_app || 'unknown app'}</span>
                            {shot.active_window_title ? <span className="text-slate-500"> · {shot.active_window_title}</span> : ''}
                            <span className="ml-2 text-slate-500">({lightboxIndex + 1} / {screenshots.length})</span>
                        </div>
                        <button
                            type="button"
                            onClick={() => setLightboxIndex(null)}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-white/15 px-3 py-1.5 font-semibold text-slate-200 transition hover:bg-white/10"
                        >
                            <X className="h-4 w-4" /> Close
                        </button>
                    </div>

                    <div className="relative flex flex-1 items-center justify-center overflow-hidden p-4">
                        {lightboxIndex > 0 && (
                            <button
                                type="button"
                                onClick={(e) => { e.stopPropagation(); step(-1); }}
                                className="absolute left-3 top-1/2 z-10 -translate-y-1/2 inline-flex h-11 w-11 items-center justify-center rounded-full border border-white/15 bg-slate-900/80 text-slate-200 transition hover:bg-slate-800"
                                aria-label="Previous"
                            >
                                <ChevronLeft className="h-6 w-6" />
                            </button>
                        )}
                        <img
                            src={shot.image_url}
                            alt="Full screenshot"
                            className="max-h-full max-w-full rounded-lg object-contain shadow-2xl"
                            onClick={(e) => e.stopPropagation()}
                        />
                        {lightboxIndex < screenshots.length - 1 && (
                            <button
                                type="button"
                                onClick={(e) => { e.stopPropagation(); step(1); }}
                                className="absolute right-3 top-1/2 z-10 -translate-y-1/2 inline-flex h-11 w-11 items-center justify-center rounded-full border border-white/15 bg-slate-900/80 text-slate-200 transition hover:bg-slate-800"
                                aria-label="Next"
                            >
                                <ChevronRight className="h-6 w-6" />
                            </button>
                        )}
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
