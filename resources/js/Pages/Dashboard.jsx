import React, { useEffect, useMemo, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import {
    Activity,
    CalendarDays,
    Coffee,
    Download,
    PauseCircle,
    PlayCircle,
    RotateCcw,
    Square,
    Timer,
    UserRound,
} from 'lucide-react';
import axios from 'axios';
import toast from 'react-hot-toast';
import {
    showActionBlocked,
    showError,
    showExportSuccess,
    showLoading,
    showTimeActionSuccess,
} from '@/Utils/notifications';
import { formatHours, getTimeBasedGreeting } from '@/Utils/timeUtils';

const ACTIONS = [
    { type: 'clock_in', label: 'Clock In', description: 'Start work', icon: PlayCircle, activeClass: 'bg-emerald-600 text-white hover:bg-emerald-700' },
    { type: 'clock_out', label: 'Clock Out', description: 'End work', icon: Square, activeClass: 'bg-rose-600 text-white hover:bg-rose-700' },
    { type: 'break_start', label: 'Start Break', description: 'Pause work', icon: PauseCircle, activeClass: 'bg-amber-500 text-slate-950 hover:bg-amber-600' },
    { type: 'break_end', label: 'End Break', description: 'Resume work', icon: RotateCcw, activeClass: 'bg-blue-600 text-white hover:bg-blue-700' },
];

const actionLabel = (type) => ACTIONS.find((action) => action.type === type)?.label || 'Not Started';

const statusFromAction = (lastAction) => {
    if (!lastAction) return { label: 'Not Started', className: 'bg-white/10 text-slate-300', dot: 'bg-slate-400' };
    if (lastAction === 'break_start') return { label: 'On Break', className: 'bg-amber-500/15 text-amber-300', dot: 'bg-amber-400' };
    if (lastAction === 'clock_out') return { label: 'Clocked Out', className: 'bg-rose-500/15 text-rose-300', dot: 'bg-rose-400' };
    return { label: 'Working', className: 'bg-emerald-500/15 text-emerald-300', dot: 'bg-emerald-400' };
};

const calculateStats = (entries) => {
    if (!entries.length) {
        return { totalHours: 0, totalBreakTime: 0, sessionsCount: 0, lastAction: null };
    }

    const sorted = [...entries].sort((a, b) => new Date(a.action_timestamp) - new Date(b.action_timestamp));
    let totalMinutes = 0;
    let breakMinutes = 0;
    let sessionsCount = 0;
    let sessionStart = null;
    let breakStart = null;

    sorted.forEach((entry) => {
        const timestamp = new Date(entry.action_timestamp);

        if (entry.action_type === 'clock_in') {
            sessionStart = timestamp;
            sessionsCount += 1;
        } else if (entry.action_type === 'clock_out' && sessionStart) {
            totalMinutes += (timestamp - sessionStart) / 60000;
            sessionStart = null;
        } else if (entry.action_type === 'break_start') {
            breakStart = timestamp;
        } else if (entry.action_type === 'break_end' && breakStart) {
            breakMinutes += (timestamp - breakStart) / 60000;
            breakStart = null;
        }
    });

    if (sessionStart) totalMinutes += (Date.now() - sessionStart.getTime()) / 60000;
    if (breakStart) breakMinutes += (Date.now() - breakStart.getTime()) / 60000;

    return {
        totalHours: Math.max(0, (totalMinutes - breakMinutes) / 60),
        totalBreakTime: Math.max(0, breakMinutes / 60),
        sessionsCount,
        lastAction: entries[0]?.action_type || null,
    };
};

// Falls back to initials when the avatar file 404s (deploys can prune
// storage files), instead of showing the browser's broken-image icon.
function EmployeeAvatar({ src, name }) {
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        setFailed(false);
    }, [src]);

    if (src && !failed) {
        return <img src={src} alt="" onError={() => setFailed(true)} className="h-9 w-9 rounded-full object-cover" />;
    }

    return (
        <span className="flex h-9 w-9 items-center justify-center rounded-full bg-slate-700 text-sm font-bold text-slate-200">
            {name.charAt(0).toUpperCase()}
        </span>
    );
}

export default function Dashboard({ auth }) {
    const can = (permission) => auth.user?.is_super_admin || auth.user?.permissions?.includes(permission);
    const canViewTeam = can('dashboard.view_team') || can('attendance.view');
    const [entries, setEntries] = useState([]);
    const [employeesData, setEmployeesData] = useState([]);
    const [loading, setLoading] = useState(false);
    const [currentTime, setCurrentTime] = useState(new Date());
    // Seed the clock state from the server-rendered page props so the action
    // buttons are correct on first paint — no "Clock In" flash while the
    // entries request is in flight.
    const [todayStats, setTodayStats] = useState(() => ({
        ...calculateStats([]),
        lastAction: auth.lastActionToday || null,
    }));

    useEffect(() => {
        const timer = window.setInterval(() => setCurrentTime(new Date()), 1000);
        return () => window.clearInterval(timer);
    }, []);

    const loadDashboard = async (notifyOnError = true) => {
        try {
            const [entriesResponse, summaryResponse] = await Promise.all([
                axios.get('/time-entries/today'),
                axios.get('/time-entries/today-summary'),
            ]);
            const nextEntries = entriesResponse.data.entries || [];
            setEntries(nextEntries);
            setTodayStats(calculateStats(nextEntries));
            setEmployeesData(summaryResponse.data.employees || []);
        } catch (error) {
            console.error('Dashboard load failed:', error);
            if (notifyOnError) showError('Unable to load dashboard data.');
        }
    };

    // Load on mount, then keep in sync with actions taken elsewhere (the
    // desktop app's clock bar): refresh when the tab regains focus and on a
    // slow background cadence.
    useEffect(() => {
        loadDashboard();
        const onFocus = () => loadDashboard(false);
        window.addEventListener('focus', onFocus);
        const interval = window.setInterval(() => loadDashboard(false), 45_000);
        return () => {
            window.removeEventListener('focus', onFocus);
            window.clearInterval(interval);
        };
    }, []);

    const currentStatus = useMemo(() => statusFromAction(todayStats.lastAction), [todayStats.lastAction]);

    const isActionDisabled = (actionType) => {
        if (loading) return true;
        if (!todayStats.lastAction) return actionType !== 'clock_in';
        if (todayStats.lastAction === 'clock_in') return actionType === 'clock_in' || actionType === 'break_end';
        if (todayStats.lastAction === 'clock_out') return actionType !== 'clock_in';
        if (todayStats.lastAction === 'break_start') return actionType !== 'break_end';
        if (todayStats.lastAction === 'break_end') return actionType === 'clock_in' || actionType === 'break_end';
        return false;
    };

    const blockedReason = (actionType) => {
        if (actionType === 'clock_in') return 'Finish the current work session before clocking in again.';
        if (actionType === 'clock_out' && todayStats.lastAction === 'break_start') return 'End the break before clocking out.';
        if (actionType === 'break_end') return 'Start a break before ending it.';
        return 'This action is not available for your current status.';
    };

    const addEntry = async (actionType) => {
        if (isActionDisabled(actionType)) {
            showActionBlocked(actionType, blockedReason(actionType));
            return;
        }

        const loadingToast = showLoading('Recording time entry...');
        setLoading(true);

        try {
            const response = await axios.post('/time-entries', { action_type: actionType });
            const nextEntries = [response.data.entry, ...entries];
            setEntries(nextEntries);
            setTodayStats(calculateStats(nextEntries));
            const summaryResponse = await axios.get('/time-entries/today-summary');
            setEmployeesData(summaryResponse.data.employees || []);
            toast.dismiss(loadingToast);
            showTimeActionSuccess(actionType);
        } catch (error) {
            console.error('Time entry failed:', error);
            toast.dismiss(loadingToast);
            showError(error.response?.data?.message || 'Failed to record time entry.');
        } finally {
            setLoading(false);
        }
    };

    const downloadCSV = async () => {
        const loadingToast = showLoading('Preparing time report...');

        try {
            const response = await axios.get('/time-entries/export', { responseType: 'blob' });
            const url = window.URL.createObjectURL(new Blob([response.data], { type: 'text/csv' }));
            const anchor = document.createElement('a');
            anchor.href = url;
            anchor.download = `sparking-asia-timesheet-${new Date().toISOString().split('T')[0]}.csv`;
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();
            window.URL.revokeObjectURL(url);
            toast.dismiss(loadingToast);
            showExportSuccess();
        } catch (error) {
            console.error('Export failed:', error);
            toast.dismiss(loadingToast);
            showError('Failed to export the timesheet.');
        }
    };

    // Presence (clock in/out) vs actual work product (tracker + manual
    // entries) — two different clocks, labeled honestly.
    const myTracked = employeesData.find((e) => e.user_id === auth.user.id)?.tracked_hours;

    const metrics = [
        { label: 'In Office', sub: 'Clocked-in time · today', value: formatHours(todayStats.totalHours), icon: Timer, color: 'text-emerald-400', bg: 'bg-emerald-500/10', title: 'Time clocked in today (clock-in to clock-out, minus breaks). This is attendance/presence — not the desktop tracker.' },
        { label: 'Tracked Work', sub: 'Tracker active · today', value: myTracked != null ? formatHours(myTracked) : '0m', icon: Activity, color: 'text-orange-400', bg: 'bg-orange-500/10', title: 'Active work recorded by the desktop tracker today (plus any manual work-diary hours). Can be lower than In Office if the tracker is off or idle.' },
        { label: 'Break Time', sub: 'On break · today', value: formatHours(todayStats.totalBreakTime), icon: Coffee, color: 'text-amber-400', bg: 'bg-amber-500/10', title: 'Total time on break today.' },
        { label: 'Actions', sub: 'Clock punches · today', value: entries.length, icon: CalendarDays, color: 'text-violet-400', bg: 'bg-violet-500/10', title: 'Number of clock in / out / break-start / break-end punches today.' },
    ];

    // Team table order: working people first, on-break in the middle, clocked
    // out at the bottom; within a status, earliest shift first, then name.
    const STATUS_ORDER = { Working: 0, 'On Break': 1, 'Clocked Out': 2 };
    const sortedEmployees = [...employeesData].sort((a, b) => {
        const ra = STATUS_ORDER[a.current_status] ?? 1.5;
        const rb = STATUS_ORDER[b.current_status] ?? 1.5;
        if (ra !== rb) return ra - rb;
        const sa = a.shift_start_time || '99:99';
        const sb = b.shift_start_time || '99:99';
        if (sa !== sb) return sa < sb ? -1 : 1;
        return (a.user_name || '').localeCompare(b.user_name || '');
    });

    return (
        <AuthenticatedLayout user={auth.user}>
            <Head title="Dashboard" />

            <div className="min-h-screen bg-slate-950">
                <div className="mx-auto max-w-[1600px] space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                    <section className="relative overflow-hidden rounded-2xl border border-slate-800 bg-gradient-to-br from-slate-950 via-slate-900 to-slate-950 px-6 py-6 shadow-xl shadow-slate-950/20">
                        <div className="pointer-events-none absolute -top-24 right-10 h-56 w-56 rounded-full bg-orange-500/15 blur-3xl" aria-hidden="true" />
                        <div className="pointer-events-none absolute -bottom-32 left-1/3 h-56 w-72 rounded-full bg-amber-500/10 blur-3xl" aria-hidden="true" />
                        <div className="relative flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                            <div>
                                <p className="text-sm font-semibold text-orange-400">{getTimeBasedGreeting()}</p>
                                <h1 className="mt-1 text-2xl font-bold text-white">{auth.user.name}</h1>
                                <p className="mt-1 text-sm text-slate-400">Track today&apos;s work and review current team activity.</p>
                            </div>

                            <div className="flex flex-wrap items-center gap-3">
                                <div className={`inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold ${currentStatus.className}`}>
                                    <span className={`h-2 w-2 rounded-full ${currentStatus.dot}`} />
                                    {currentStatus.label}
                                </div>
                                <div className="rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-right backdrop-blur">
                                    <div className="text-lg font-bold text-white">
                                        {currentTime.toLocaleTimeString('en-US', {
                                            timeZone: 'Asia/Karachi',
                                            hour: 'numeric',
                                            minute: '2-digit',
                                        })}
                                    </div>
                                    <div className="text-xs font-medium text-slate-400">Pakistan Time</div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        {metrics.map((metric) => {
                            const Icon = metric.icon;
                            return (
                                <div key={metric.label} title={metric.title} className="rounded-lg border border-slate-800 bg-slate-900 p-4 shadow-sm">
                                    <div className="flex items-center gap-3">
                                        <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${metric.bg}`}>
                                            <Icon className={`h-5 w-5 ${metric.color}`} />
                                        </span>
                                        <div className="min-w-0">
                                            <div className="text-xl font-bold text-white">{metric.value}</div>
                                            <div className="text-sm font-medium text-slate-200">{metric.label}</div>
                                            <div className="text-[11px] text-slate-400">{metric.sub}</div>
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                    </section>

                    <section className="rounded-lg border border-slate-800 bg-slate-900 shadow-sm">
                        <div className="flex flex-col gap-3 border-b border-slate-800 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 className="text-base font-bold text-white">Time Tracking</h2>
                                <p className="text-sm text-slate-400">Last action: {actionLabel(todayStats.lastAction)}</p>
                            </div>
                            <div className="text-sm text-slate-400">Available actions follow your current status.</div>
                        </div>

                        <div className="grid grid-cols-2 gap-3 p-4 lg:grid-cols-4">
                            {ACTIONS.map((action) => {
                                const Icon = action.icon;
                                const disabled = isActionDisabled(action.type);
                                return (
                                    <button
                                        key={action.type}
                                        type="button"
                                        onClick={() => addEntry(action.type)}
                                        disabled={disabled}
                                        className={`flex min-h-20 items-center gap-3 rounded-lg px-4 py-3 text-left transition ${
                                            disabled
                                                ? 'cursor-not-allowed border border-slate-800 bg-slate-900/60 text-slate-600'
                                                : action.activeClass
                                        }`}
                                    >
                                        <Icon className="h-6 w-6 shrink-0" />
                                        <span>
                                            <span className="block text-sm font-bold">{action.label}</span>
                                            <span className={`block text-xs ${disabled ? 'text-slate-600' : 'opacity-80'}`}>{action.description}</span>
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </section>

                    <section className="rounded-lg border border-slate-800 bg-slate-900 shadow-sm">
                        <div className="flex flex-col gap-3 border-b border-slate-800 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 className="text-base font-bold text-white">
                                    {canViewTeam ? 'Team Activity Today' : 'Your Activity Today'}
                                </h2>
                                <p className="text-sm text-slate-400">Work, break, and attendance status.</p>
                            </div>
                            <div className="flex items-center gap-2">
                                {can('attendance.view') && (
                                    <Link
                                        href={route('employee-attendance.index')}
                                        className="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm font-semibold text-slate-300 transition hover:bg-slate-800 hover:text-white"
                                    >
                                        Open Attendance
                                    </Link>
                                )}
                                {employeesData.length > 0 && (
                                    <button
                                        type="button"
                                        onClick={downloadCSV}
                                        className="inline-flex items-center gap-2 rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm font-semibold text-white transition hover:bg-slate-700"
                                    >
                                        <Download className="h-4 w-4" />
                                        Export
                                    </button>
                                )}
                            </div>
                        </div>

                        {employeesData.length === 0 ? (
                            <div className="px-5 py-12 text-center">
                                <UserRound className="mx-auto h-10 w-10 text-slate-600" />
                                <h3 className="mt-3 font-semibold text-white">No activity recorded today</h3>
                                <p className="mt-1 text-sm text-slate-400">Time tracking activity will appear here.</p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-slate-800">
                                    <thead className="bg-slate-950">
                                        <tr>
                                            {['Employee', 'Status', 'In Office', 'Tracked', 'Activity', 'Break', 'Week', 'Month'].map((heading) => (
                                                <th key={heading} className="px-4 py-3 text-left text-xs font-bold uppercase text-slate-300">{heading}</th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-800">
                                        {sortedEmployees.map((employee) => {
                                            const status = statusFromAction(
                                                employee.current_status === 'On Break'
                                                    ? 'break_start'
                                                    : employee.current_status === 'Clocked Out'
                                                        ? 'clock_out'
                                                        : 'clock_in'
                                            );
                                            return (
                                                <tr key={employee.user_id} className="hover:bg-white/5">
                                                    <td className="whitespace-nowrap px-4 py-3">
                                                        <div className="flex items-center gap-3">
                                                            <EmployeeAvatar src={employee.avatar} name={employee.user_name} />
                                                            <div>
                                                                <div className="text-sm font-semibold text-slate-100">{employee.user_name}</div>
                                                                <div className="text-xs text-slate-400">{employee.designation}</div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td className="whitespace-nowrap px-4 py-3">
                                                        <span className={`inline-flex items-center gap-2 rounded-full px-2.5 py-1 text-xs font-semibold ${status.className}`}>
                                                            <span className={`h-1.5 w-1.5 rounded-full ${status.dot}`} />
                                                            {employee.current_status}
                                                        </span>
                                                    </td>
                                                    <td className="whitespace-nowrap px-4 py-3 text-sm font-semibold text-slate-100">{formatHours(employee.total_work_hours)}</td>
                                                    <td className="whitespace-nowrap px-4 py-3 text-sm font-semibold text-orange-300">{formatHours(employee.tracked_hours || 0)}</td>
                                                    <td className="whitespace-nowrap px-4 py-3">
                                                        {(() => {
                                                            const hasData = employee.is_live || (Number(employee.tracked_hours) || 0) > 0;
                                                            if (!hasData) return <span className="text-xs text-slate-400">—</span>;
                                                            const pct = Math.max(0, Math.min(100, Number(employee.activity_percent) || 0));
                                                            const cls = pct >= 60
                                                                ? 'bg-emerald-500/15 text-emerald-300'
                                                                : pct >= 30
                                                                    ? 'bg-amber-500/15 text-amber-300'
                                                                    : 'bg-rose-500/15 text-rose-300';
                                                            return (
                                                                <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ${cls}`} title="Average activity (keyboard/mouse) across today's tracked time">
                                                                    {pct}%
                                                                </span>
                                                            );
                                                        })()}
                                                    </td>
                                                    <td className="whitespace-nowrap px-4 py-3 text-sm text-slate-300">{formatHours(employee.total_break_hours)}</td>
                                                    <td className="whitespace-nowrap px-4 py-3 text-sm text-slate-300">{formatHours(employee.weekly_work_hours || 0)}</td>
                                                    <td className="whitespace-nowrap px-4 py-3 text-sm text-slate-300">{formatHours(employee.monthly_work_hours || 0)}</td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
