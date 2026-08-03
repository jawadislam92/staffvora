import React, { useEffect, useMemo, useState } from "react";
import { Head, Link, router, usePage } from "@inertiajs/react";
import axios from "axios";
import toast from "react-hot-toast";
import {
    Activity,
    Check,
    Clock,
    Coffee,
    Download,
    FlaskConical,
    Sparkles,
    Users,
    X,
} from "lucide-react";
import AppShell from "@/Layouts/AppShell";
import Avatar from "@/Components/Avatar";
import {
    Button,
    Card,
    Badge,
    StatCard,
    Table,
    THead,
    TBody,
    Tr,
    Th,
    Td,
    EmptyState,
    Skeleton,
    SkeletonCard,
    Tabs,
    LockedFeature,
} from "@/Components/ui";
import { Sparkline, TrendChart, ActivityStrip, RankedBars } from "@/Components/charts";
import { showError, showExportSuccess, showLoading } from "@/Utils/notifications";
import { formatHours } from "@/Utils/timeUtils";

const cx = (...a) => a.filter(Boolean).join(" ");

// ---------------------------------------------------------------------------
// Data-layer helpers — carried over verbatim from the previous Dashboard so the
// live behaviour (today entries + summary, elapsed-hours math, status mapping)
// is unchanged. Only the visuals below were rebuilt.
// ---------------------------------------------------------------------------

const calculateStats = (entries) => {
    if (!entries.length) {
        return { totalHours: 0, totalBreakTime: 0, sessionsCount: 0, lastAction: null };
    }

    const sorted = [...entries].sort(
        (a, b) => new Date(a.action_timestamp) - new Date(b.action_timestamp)
    );
    let totalMinutes = 0;
    let breakMinutes = 0;
    let sessionsCount = 0;
    let sessionStart = null;
    let breakStart = null;

    sorted.forEach((entry) => {
        const timestamp = new Date(entry.action_timestamp);

        if (entry.action_type === "clock_in") {
            sessionStart = timestamp;
            sessionsCount += 1;
        } else if (entry.action_type === "clock_out" && sessionStart) {
            totalMinutes += (timestamp - sessionStart) / 60000;
            sessionStart = null;
        } else if (entry.action_type === "break_start") {
            breakStart = timestamp;
        } else if (entry.action_type === "break_end" && breakStart) {
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

// Map a raw punch / status string to a semantic Badge tone + label.
const statusFromAction = (lastAction) => {
    if (lastAction === "break_start") return { tone: "warning", label: "On Break" };
    if (lastAction === "clock_out") return { tone: "neutral", label: "Clocked Out" };
    if (!lastAction) return { tone: "neutral", label: "Not Started" };
    return { tone: "success", label: "Working" };
};

const statusBadge = (currentStatus) => {
    if (currentStatus === "On Break") return { tone: "warning", label: "On break" };
    if (currentStatus === "Working") return { tone: "success", label: "Working" };
    return { tone: "neutral", label: "Offline" };
};

// Activity-% → bar fill token.
const activityBarClass = (pct) => {
    if (pct >= 75) return "bg-success";
    if (pct >= 50) return "bg-accent";
    if (pct >= 30) return "bg-warning";
    return "bg-danger";
};

// ---------------------------------------------------------------------------
// LOCKED MODULES — the "shown, not hidden" rule at nav scale.
//
// A module the workspace has not bought disappears from the top nav, which
// teaches the customer nothing. These cards put the missing ones back on the
// dashboard: named, explained, with one CTA to the plan page.
//
// Deliberately NOT a fake screenshot of the feature — a lock must never imply
// something works when it does not. `permission` keeps the upsell off the
// dashboard of somebody whose role could not use the module anyway.
// ---------------------------------------------------------------------------
const LOCKABLE_MODULES = [
    {
        key: "insights",
        name: "Insights",
        permission: "insights.view",
        blurb: "Utilization against target, idle time and team highlights over any date range.",
    },
    {
        key: "timesheet_approvals",
        name: "Timesheet approvals",
        permission: null,
        blurb: "Submit, review and approve timesheets by pay period before they're paid.",
    },
    {
        key: "machine_compliance",
        name: "Machine compliance",
        permission: "compliance.view",
        blurb: "Per-PC inventory of extensions, programs and VPNs, with a blocklist and findings.",
    },
    {
        key: "upwork_profiles",
        name: "Profiles",
        permission: "profiles.view",
        blurb: "Track work per marketplace profile and keep client work attributed to the right one.",
    },
    {
        key: "ai",
        name: "AI assistant",
        permission: "ai.assistant",
        blurb: "Ask questions about your team's day and get written summaries of tracked work.",
    },
];

// ---------------------------------------------------------------------------
// DEV_SAMPLE — used ONLY when `preview` is true. Lets the page render fully
// without auth or a backend (design review / storybook-style previews). None of
// this is ever fetched or shown to a real, authenticated user.
// ---------------------------------------------------------------------------
const DEV_SAMPLE = {
    user: {
        id: 0,
        name: "Jawad",
        email: "jawad@staffvora.com",
        role_label: "Owner",
        is_super_admin: true,
        permissions: [],
    },
    // Team snapshot mirroring the approved mockup.
    employees: [
        { user_id: 1, user_name: "Aqsa Khan", designation: "Frontend Engineer", avatar: null, current_status: "Working", total_work_hours: 7.2, tracked_hours: 6.6, total_break_hours: 0.5, activity_percent: 82, weekly_work_hours: 34.17, monthly_work_hours: 142.5 },
        { user_id: 2, user_name: "Musa Raza", designation: "Backend Engineer", avatar: null, current_status: "Working", total_work_hours: 6.8, tracked_hours: 6.1, total_break_hours: 0.6, activity_percent: 74, weekly_work_hours: 31.75, monthly_work_hours: 133.2 },
        { user_id: 3, user_name: "Sana Faisal", designation: "Product Designer", avatar: null, current_status: "On Break", total_work_hours: 5.5, tracked_hours: 4.9, total_break_hours: 0.8, activity_percent: 69, weekly_work_hours: 28.5, monthly_work_hours: 121.0 },
        { user_id: 4, user_name: "Hamza Zaidi", designation: "QA Analyst", avatar: null, current_status: "Working", total_work_hours: 4.92, tracked_hours: 4.3, total_break_hours: 0.4, activity_percent: 61, weekly_work_hours: 26.25, monthly_work_hours: 110.5 },
        { user_id: 5, user_name: "Nida Amin", designation: "Marketing Lead", avatar: null, current_status: "Clocked Out", total_work_hours: 3.08, tracked_hours: 2.5, total_break_hours: 0.7, activity_percent: 47, weekly_work_hours: 19.83, monthly_work_hours: 88.0 },
        { user_id: 6, user_name: "Bilal Tariq", designation: "DevOps", avatar: null, current_status: "Clocked Out", total_work_hours: 2.33, tracked_hours: 1.9, total_break_hours: 0.5, activity_percent: 38, weekly_work_hours: 16.08, monthly_work_hours: 72.4 },
    ],
    stats: [
        { key: "hours", label: "Hours tracked", value: "142h 30m", icon: Clock, delta: { value: "8.4%", dir: "up" }, sparkData: [18, 22, 19, 26, 24, 30, 27, 33, 31, 35, 34, 37, 36, 38], sparkTone: "text-accent", hint: "Team total · this week" },
        { key: "activity", label: "Avg activity", value: "68%", icon: Activity, delta: { value: "3.1%", dir: "up" }, sparkData: [61, 64, 62, 66, 65, 69, 68, 70, 67, 71, 69, 72, 70, 68], sparkTone: "text-accent", hint: "Across active members" },
        { key: "active", label: "Active members", value: "12 / 15", icon: Users, delta: { value: "2", dir: "down", good: false }, sparkData: [14, 13, 15, 14, 13, 12, 13, 12], sparkTone: "text-warning", hint: "Clocked in right now" },
        { key: "break", label: "On break", value: "2", icon: Coffee, hint: "Members paused · now" },
    ],
    trend: [
        { label: "Jul 9", value: 20 }, { label: "Jul 10", value: 24 }, { label: "Jul 11", value: 19 },
        { label: "Jul 12", value: 28 }, { label: "Jul 13", value: 25 }, { label: "Jul 14", value: 31 },
        { label: "Jul 15", value: 27 }, { label: "Jul 16", value: 33 }, { label: "Jul 17", value: 29 },
        { label: "Jul 18", value: 35 }, { label: "Jul 19", value: 22 }, { label: "Jul 20", value: 37.5 },
        { label: "Jul 21", value: 30 }, { label: "Jul 22", value: 34 },
    ],
    compare: [17, 20, 16, 24, 22, 27, 25, 29, 26, 31, 20, 33, 27, 31],
    trendAvg: 26,
    cstats: [
        { label: "Total this period", value: "142h 30m", delta: { value: "8.4%", dir: "up" } },
        { label: "Peak day", value: "37.5h", delta: { value: "12%", dir: "up" } },
        { label: "Avg / day", value: "10.2h" },
        { label: "Idle time", value: "21%", delta: { value: "1.4%", dir: "down" } },
    ],
    activity: { tracked: 64, idle: 21, manual: 9, untracked: 6 },
    projects: [
        { label: "Atlas Mobile App", value: "48h 10m", pct: 100, meta: "34% of tracked time" },
        { label: "Orbit Dashboard", value: "36h 25m", pct: 76, meta: "26% of tracked time" },
        { label: "Nimbus API", value: "24h 50m", pct: 52, meta: "17% of tracked time" },
        { label: "Helio Website", value: "18h 05m", pct: 38, meta: "13% of tracked time" },
        { label: "Vela CRM", value: "14h 00m", pct: 29, meta: "10% of tracked time" },
    ],
};

const RANGE_TABS = [
    { key: "today", label: "Today" },
    { key: "week", label: "This week" },
    { key: "month", label: "This month" },
];

// ---------------------------------------------------------------------------
// FIRST RUN
//
// An empty workspace is the moment a customer decides whether this product is
// real. Two pieces live here:
//
//  1. GET STARTED — four things that have to happen before the dashboard can
//     say anything. Every done-state is computed server-side from actual rows
//     (App\Services\GettingStartedChecklist) — there is no per-step flag that
//     could keep a green tick after the thing it claims stopped being true.
//
//  2. DEMO DATA — a small, loudly-labelled sample so the customer can see a
//     populated product without waiting a fortnight for their own history.
//     Its removability is the whole promise, so the control to remove it is
//     never more than one click from wherever the demo data is visible.
//
// Dismissal is stored in the browser, per person. That is deliberate: it is a
// preference about what to LOOK at, not a claim about the data, so it cannot
// lie about progress the way a stored "onboarded" flag would.
// ---------------------------------------------------------------------------
const DISMISS_KEY = (userId) => `staffvora.getting-started.dismissed.${userId ?? "anon"}`;

// Ziggy throws on an unknown route name, so ask before offering a control that
// depends on one. Keeps this page renderable even if the demo endpoints are not
// present in a given deployment.
const routeExists = (name) => {
    try {
        return typeof route === "function" && route().has(name);
    } catch {
        return false;
    }
};

function readDismissed(userId) {
    try {
        return window.localStorage.getItem(DISMISS_KEY(userId)) === "1";
    } catch {
        return false;
    }
}

function GettingStarted({ checklist, demo, onDismiss, onLoadDemo, onRemoveDemo, busy, demoAvailable }) {
    const { steps = [], done = 0, total = 0 } = checklist || {};
    const pct = total ? Math.round((done / total) * 100) : 0;
    const canLoadDemo = !demo?.loaded && (demo?.seat_headroom ?? 0) > 0;

    return (
        <Card
            padded={false}
            className="overflow-hidden border-accent/30"
            title="Get started"
            description={`${done} of ${total} done — finish these and every screen in Staffvora has something to say.`}
            actions={
                <button
                    type="button"
                    onClick={onDismiss}
                    className="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium text-muted transition hover:bg-surface-2 hover:text-body focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                >
                    <X className="h-3.5 w-3.5" /> Dismiss
                </button>
            }
        >
            <div className="h-1.5 w-full bg-surface-2">
                <div
                    className="h-full bg-accent transition-all"
                    style={{ width: `${pct}%` }}
                    role="progressbar"
                    aria-valuenow={done}
                    aria-valuemin={0}
                    aria-valuemax={total}
                    aria-label="Setup progress"
                />
            </div>

            <ol className="divide-y divide-line">
                {steps.map((step) => (
                    <li
                        key={step.key}
                        className="flex flex-wrap items-start justify-between gap-3 px-5 py-4"
                    >
                        <div className="flex min-w-0 items-start gap-3">
                            <span
                                aria-hidden="true"
                                className={cx(
                                    "mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border",
                                    step.done
                                        ? "border-success bg-success text-white"
                                        : "border-line bg-surface-2"
                                )}
                            >
                                {step.done && <Check className="h-3 w-3" />}
                            </span>
                            <div className="min-w-0">
                                <p
                                    className={cx(
                                        "text-sm font-semibold",
                                        step.done ? "text-muted line-through" : "text-body"
                                    )}
                                >
                                    {step.title}
                                </p>
                                <p className="mt-0.5 text-xs text-subtle">{step.description}</p>
                            </div>
                        </div>

                        {step.done ? (
                            <span className="text-xs font-semibold text-success">Done</span>
                        ) : step.can && step.route ? (
                            <Button as={Link} href={route(step.route)} variant="secondary" size="sm">
                                {step.cta}
                            </Button>
                        ) : null}
                    </li>
                ))}
            </ol>

            {/* Sample data lives at the bottom of the checklist: it is the
                answer to "what will this look like when it's full?", which is
                exactly the question an empty workspace provokes. */}
            <div
                className={cx(
                    "flex flex-wrap items-center justify-between gap-3 border-t border-line bg-surface-2 px-5 py-4",
                    !demoAvailable && "hidden"
                )}
            >
                <p className="flex items-start gap-2 text-xs text-muted">
                    <FlaskConical className="mt-0.5 h-4 w-4 shrink-0 text-accent" />
                    <span>
                        {demo?.loaded
                            ? "Demo data is loaded. Every demo person and project is named “Demo · …”, and none of it touches your real hours."
                            : canLoadDemo
                              ? "Want to see a populated workspace first? Load two weeks of clearly-labelled sample data — demo teammates take up seats while loaded, and one click removes all of it."
                              : "Sample data needs a spare seat for its demo teammates, and your plan's seats are all in use."}
                    </span>
                </p>
                {demo?.loaded ? (
                    <Button variant="secondary" size="sm" onClick={onRemoveDemo} disabled={busy}>
                        Remove demo data
                    </Button>
                ) : (
                    <Button variant="secondary" size="sm" onClick={onLoadDemo} disabled={busy || !canLoadDemo}>
                        Load demo data
                    </Button>
                )}
            </div>
        </Card>
    );
}

function DemoDataBanner({ demo, onRemove, busy, demoAvailable }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-warning/40 bg-warning-soft px-5 py-4">
            <div className="flex items-start gap-3">
                <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-warning text-white">
                    <FlaskConical className="h-4 w-4" />
                </span>
                <div>
                    <p className="text-sm font-semibold text-body">
                        You're looking at demo data
                    </p>
                    <p className="text-xs text-muted">
                        {demo.people} demo {demo.people === 1 ? "teammate" : "teammates"} and{" "}
                        {demo.entries} sample entries over the last {demo.days} days. They are
                        named “Demo · …” everywhere they appear and are never counted as anyone's
                        real hours. Remove them whenever you like.
                    </p>
                </div>
            </div>
            {demoAvailable && (
                <Button variant="secondary" size="sm" onClick={onRemove} disabled={busy}>
                    Remove demo data
                </Button>
            )}
        </div>
    );
}

export default function Dashboard({
    auth,
    needsOnboarding,
    gettingStarted = null,
    demoData = null,
    preview = false,
}) {
    const page = usePage();
    const workspace = page.props?.workspace || {};
    const timezone = workspace.timezone || (preview ? "Asia/Karachi" : "UTC");
    const projectTerm = workspace.client_term || "Project";

    // In preview mode we synthesize the auth user from the sample so the page
    // renders with no backend/session at all.
    const user = preview ? DEV_SAMPLE.user : auth?.user;
    const can = (p) => user?.is_super_admin || user?.permissions?.includes(p);
    const canViewTeam = can("dashboard.view_team") || can("attendance.view");

    // Modules this workspace does not have. Read from the shared `entitlements`
    // map — the SAME resolution the server gates on — and only when it says a
    // definite `false`, so a missing/unavailable map upsells nothing. Purely
    // presentational: every one of these routes is enforced by `entitlement:`
    // middleware regardless of what is drawn here.
    const entitlements = page.props?.entitlements || {};
    const lockedModules = useMemo(() => {
        if (preview) return LOCKABLE_MODULES.slice(0, 3);

        return LOCKABLE_MODULES.filter(
            (m) => entitlements[m.key] === false && (!m.permission || can(m.permission))
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [preview, entitlements, user?.is_super_admin, user?.permissions]);

    // --- first-run state ---------------------------------------------------
    const [checklistDismissed, setChecklistDismissed] = useState(() =>
        preview ? false : readDismissed(auth?.user?.id)
    );
    const [demoBusy, setDemoBusy] = useState(false);

    const dismissChecklist = () => {
        setChecklistDismissed(true);
        try {
            window.localStorage.setItem(DISMISS_KEY(auth?.user?.id), "1");
        } catch {
            /* private mode — the card simply comes back next visit. */
        }
    };

    // Both demo actions are full Inertia visits, so the page re-renders from
    // freshly derived server state rather than from anything guessed here.
    const runDemoAction = (method, routeName, fallbackMessage) => {
        if (preview) return;
        setDemoBusy(true);
        const options = {
            preserveScroll: true,
            // Read the flash off the RESPONSE page, not off the closure's stale
            // props — a refusal (no spare seats) has to surface as a refusal.
            onSuccess: (nextPage) => {
                const flash = nextPage?.props?.flash || {};
                if (flash.error) showError(flash.error);
                else toast.success(flash.success || fallbackMessage);
            },
            onError: () => showError("That didn't work. Please try again."),
            onFinish: () => setDemoBusy(false),
        };

        if (method === "delete") router.delete(route(routeName), options);
        else router.post(route(routeName), {}, options);
    };

    const loadDemoData = () =>
        runDemoAction("post", "onboarding.demo.load", "Demo data loaded.");
    const removeDemoData = () =>
        runDemoAction("delete", "onboarding.demo.remove", "Demo data removed.");

    const showChecklist =
        !preview && gettingStarted && !gettingStarted.complete && !checklistDismissed;
    const demoLoaded = !preview && !!demoData?.loaded;
    const demoAvailable = useMemo(
        () =>
            !preview &&
            routeExists("onboarding.demo.load") &&
            routeExists("onboarding.demo.remove"),
        [preview]
    );

    // --- live state --------------------------------------------------------
    const [entries, setEntries] = useState([]);
    const [employeesData, setEmployeesData] = useState([]);
    const [trend, setTrend] = useState([]); // live daily worked-hours series (last 14 days)
    const [initialLoading, setInitialLoading] = useState(!preview);
    const [currentTime, setCurrentTime] = useState(new Date());
    const [range, setRange] = useState("week"); // visual only — defaults to This week
    const [todayStats, setTodayStats] = useState(() => ({
        ...calculateStats([]),
        lastAction: auth?.lastActionToday || null,
    }));

    // 1-second wall clock for the live header time.
    useEffect(() => {
        const timer = window.setInterval(() => setCurrentTime(new Date()), 1000);
        return () => window.clearInterval(timer);
    }, []);

    const loadDashboard = async (notifyOnError = true) => {
        try {
            // The trend is a best-effort extra: its own catch keeps a trend
            // failure from clearing the (more important) entries + summary.
            const [entriesResponse, summaryResponse, trendResponse] = await Promise.all([
                axios.get("/time-entries/today"),
                axios.get("/time-entries/today-summary"),
                axios
                    .get("/time-entries/dashboard-trend")
                    .catch(() => ({ data: { series: [] } })),
            ]);
            const nextEntries = entriesResponse.data.entries || [];
            setEntries(nextEntries);
            setTodayStats(calculateStats(nextEntries));
            setEmployeesData(summaryResponse.data.employees || []);
            setTrend(
                (trendResponse.data.series || []).map((d) => ({
                    label: d.label,
                    value: Number(d.hours) || 0,
                }))
            );
        } catch (error) {
            console.error("Dashboard load failed:", error);
            if (notifyOnError) showError("Unable to load dashboard data.");
        } finally {
            setInitialLoading(false);
        }
    };

    // Load on mount, then keep in sync with actions taken elsewhere (the desktop
    // clock bar): refresh when the tab regains focus and on a slow cadence.
    // Preview mode skips all network entirely.
    useEffect(() => {
        if (preview) return undefined;
        loadDashboard();
        const onFocus = () => loadDashboard(false);
        window.addEventListener("focus", onFocus);
        const interval = window.setInterval(() => loadDashboard(false), 45_000);
        return () => {
            window.removeEventListener("focus", onFocus);
            window.clearInterval(interval);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [preview]);

    const downloadCSV = async () => {
        const loadingToast = showLoading("Preparing time report...");
        try {
            const response = await axios.get("/time-entries/export", { responseType: "blob" });
            const url = window.URL.createObjectURL(new Blob([response.data], { type: "text/csv" }));
            const anchor = document.createElement("a");
            anchor.href = url;
            anchor.download = `timesheet-${new Date().toISOString().split("T")[0]}.csv`;
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();
            window.URL.revokeObjectURL(url);
            toast.dismiss(loadingToast);
            showExportSuccess();
        } catch (error) {
            console.error("Export failed:", error);
            toast.dismiss(loadingToast);
            showError("Failed to export the timesheet.");
        }
    };

    // --- derived view data -------------------------------------------------
    const employees = preview ? DEV_SAMPLE.employees : employeesData;

    // Team table order: working first, then on-break, then clocked out; within a
    // status by earliest shift, then name.
    const STATUS_ORDER = { Working: 0, "On Break": 1, "Clocked Out": 2 };
    const sortedEmployees = useMemo(
        () =>
            [...employees].sort((a, b) => {
                const ra = STATUS_ORDER[a.current_status] ?? 1.5;
                const rb = STATUS_ORDER[b.current_status] ?? 1.5;
                if (ra !== rb) return ra - rb;
                const sa = a.shift_start_time || "99:99";
                const sb = b.shift_start_time || "99:99";
                if (sa !== sb) return sa < sb ? -1 : 1;
                return (a.user_name || "").localeCompare(b.user_name || "");
            }),
        [employees]
    );

    // Greeting word + formatted date/time in the workspace timezone.
    const tzHour = useMemo(() => {
        try {
            return Number(
                new Intl.DateTimeFormat("en-US", { timeZone: timezone, hour: "numeric", hour12: false }).format(currentTime)
            );
        } catch {
            return currentTime.getHours();
        }
    }, [currentTime, timezone]);
    const greetWord = tzHour < 12 ? "Good morning" : tzHour < 17 ? "Good afternoon" : "Good evening";
    const firstName = (user?.name || "there").split(" ")[0];
    const dateLabel = currentTime.toLocaleDateString("en-US", {
        timeZone: timezone,
        weekday: "long",
        month: "long",
        day: "numeric",
    });
    const timeLabel = currentTime.toLocaleTimeString("en-US", {
        timeZone: timezone,
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
    });

    // Stat cards — real data only. Deltas/sparklines are shown ONLY in preview
    // (we have no historical series live, and we never fabricate a trend).
    const stats = useMemo(() => {
        if (preview) return DEV_SAMPLE.stats;

        if (canViewTeam) {
            const withActivity = employees.filter(
                (e) => e.is_live || (Number(e.tracked_hours) || 0) > 0
            );
            const teamTracked = employees.reduce((s, e) => s + (Number(e.tracked_hours) || 0), 0);
            const avgActivity = withActivity.length
                ? Math.round(
                      withActivity.reduce((s, e) => s + (Number(e.activity_percent) || 0), 0) /
                          withActivity.length
                  )
                : null;
            const working = employees.filter((e) => e.current_status === "Working").length;
            const onBreak = employees.filter((e) => e.current_status === "On Break").length;

            return [
                { key: "hours", label: "Hours tracked", value: formatHours(teamTracked), icon: Clock, hint: "Team total · today" },
                { key: "activity", label: "Avg activity", value: avgActivity != null ? `${avgActivity}%` : "—", icon: Activity, hint: "Across active members" },
                { key: "active", label: "Active members", value: `${working} / ${employees.length}`, icon: Users, hint: "Clocked in right now" },
                { key: "break", label: "On break", value: `${onBreak}`, icon: Coffee, hint: "Members paused · now" },
            ];
        }

        // Self view.
        const me = employees.find((e) => e.user_id === user?.id);
        const myActivity = me?.activity_percent;
        return [
            { key: "office", label: "In office", value: formatHours(todayStats.totalHours), icon: Clock, hint: "Clocked-in time · today" },
            { key: "tracked", label: "Tracked work", value: formatHours(me?.tracked_hours || 0), icon: Activity, hint: "Tracker active · today" },
            { key: "activity", label: "Activity", value: myActivity != null ? `${Math.round(myActivity)}%` : "—", icon: Activity, hint: "Keyboard / mouse · today" },
            { key: "break", label: "Break time", value: formatHours(todayStats.totalBreakTime), icon: Coffee, hint: "On break · today" },
        ];
    }, [preview, canViewTeam, employees, todayStats, user?.id]);

    // Activity breakdown — honestly derived from the summary (active vs idle vs
    // clocked-in-but-untracked). ActivityStrip renders its own empty state when
    // there's nothing to show.
    const breakdown = useMemo(() => {
        if (preview) return DEV_SAMPLE.activity;
        return employees.reduce(
            (acc, e) => {
                const tracked = Number(e.tracked_hours) || 0;
                const act = Math.max(0, Math.min(100, Number(e.activity_percent) || 0));
                const office = Number(e.total_work_hours) || 0;
                acc.tracked += tracked * (act / 100);
                acc.idle += tracked * (1 - act / 100);
                acc.untracked += Math.max(0, office - tracked);
                return acc;
            },
            { tracked: 0, idle: 0, untracked: 0 }
        );
    }, [preview, employees]);

    // Average worked hours/day across the live trend — drives TrendChart's dotted
    // average line + legend. Only meaningful with 2+ non-empty days.
    const trendAvg = useMemo(() => {
        if (preview) return DEV_SAMPLE.trendAvg;
        if (trend.length < 2) return undefined;
        const total = trend.reduce((s, p) => s + (Number(p.value) || 0), 0);
        return total > 0 ? Number((total / trend.length).toFixed(1)) : undefined;
    }, [preview, trend]);

    const teamHeading = canViewTeam ? "Team activity" : "Your activity";
    const activeCount = employees.filter((e) => e.current_status === "Working").length;

    return (
        <AppShell user={user}>
            <Head title="Dashboard" />

            <div className="mx-auto max-w-[1600px] space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                {needsOnboarding && (
                    <Link
                        href={route("onboarding.show")}
                        className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-accent/40 bg-accent/10 px-5 py-4 transition hover:bg-accent/15 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app"
                    >
                        <span className="flex items-center gap-3">
                            <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-accent text-white">
                                <Sparkles className="h-4 w-4" />
                            </span>
                            <span>
                                <span className="block text-sm font-semibold text-body">
                                    Finish setting up your workspace
                                </span>
                                <span className="block text-xs text-muted">
                                    Invite your team, install the tracker and create your first{" "}
                                    {projectTerm.toLowerCase()}.
                                </span>
                            </span>
                        </span>
                        <span className="text-sm font-semibold text-accent">Resume setup →</span>
                    </Link>
                )}

                {/* Demo data is announced ABOVE everything it affects, and the
                    banner outlives the checklist — it must stay visible even
                    once setup is finished or the checklist has been dismissed,
                    or the numbers below would be unexplained. */}
                {demoLoaded && (
                    <DemoDataBanner
                        demo={demoData}
                        onRemove={removeDemoData}
                        busy={demoBusy}
                        demoAvailable={demoAvailable}
                    />
                )}

                {showChecklist && (
                    <GettingStarted
                        checklist={gettingStarted}
                        demo={demoData}
                        onDismiss={dismissChecklist}
                        onLoadDemo={loadDemoData}
                        onRemoveDemo={removeDemoData}
                        busy={demoBusy}
                        demoAvailable={demoAvailable}
                    />
                )}

                {/* 1 — Page header: greeting + live clock + range control + export */}
                <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div className="min-w-0">
                        <p className="text-[13px] font-medium text-muted">
                            {dateLabel} · {timezone.replaceAll("_", " ").split("/").pop()}
                        </p>
                        <h1 className="mt-1 truncate text-2xl font-bold tracking-tight text-body">
                            {greetWord}, <span className="text-accent">{firstName}</span>
                        </h1>
                        <p className="mt-1 flex items-center gap-2 text-sm text-muted">
                            <span
                                aria-hidden="true"
                                className="h-1.5 w-1.5 rounded-full bg-success motion-safe:animate-pulse"
                            />
                            <span className="font-mono tabular-nums">{timeLabel}</span> local time
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Tabs tabs={RANGE_TABS} value={range} onChange={setRange} aria-label="Date range" />
                        <Button
                            variant="secondary"
                            icon={<Download className="h-4 w-4" />}
                            onClick={preview ? undefined : downloadCSV}
                            disabled={preview}
                        >
                            Export
                        </Button>
                    </div>
                </div>

                {/* 2 — KPI stat cards */}
                {initialLoading ? (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {[0, 1, 2, 3].map((i) => (
                            <SkeletonCard key={i} />
                        ))}
                    </div>
                ) : (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {stats.map((s) => {
                            const Icon = s.icon || Clock;
                            return (
                                <StatCard
                                    key={s.key}
                                    label={s.label}
                                    value={s.value}
                                    hint={s.hint}
                                    delta={s.delta}
                                    icon={<Icon className="h-[18px] w-[18px]" />}
                                    spark={
                                        s.sparkData ? (
                                            <Sparkline
                                                data={s.sparkData}
                                                area
                                                height={30}
                                                className={s.sparkTone || "text-accent"}
                                            />
                                        ) : undefined
                                    }
                                />
                            );
                        })}
                    </div>
                )}

                {/* 3 — Worked-hours trend + 4 — activity breakdown */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <Card
                        className="min-w-0 lg:col-span-2"
                        title="Worked hours"
                        description={
                            preview
                                ? "Last 14 days · team total"
                                : canViewTeam
                                  ? "Last 14 days · team total"
                                  : "Last 14 days · your hours"
                        }
                    >
                        {preview ? (
                            <>
                                <TrendChart
                                    data={DEV_SAMPLE.trend}
                                    compare={DEV_SAMPLE.compare}
                                    avg={DEV_SAMPLE.trendAvg}
                                    unit="h"
                                />
                                <div className="mt-4 grid grid-cols-2 border-t border-line sm:grid-cols-4">
                                    {DEV_SAMPLE.cstats.map((c, i) => (
                                        <div
                                            key={c.label}
                                            className={cx(
                                                "px-4 py-3",
                                                i > 0 && "sm:border-l sm:border-line",
                                                i === 1 && "border-l border-line sm:border-l",
                                                i === 3 && "border-l border-line sm:border-l",
                                                i >= 2 && "border-t border-line sm:border-t-0"
                                            )}
                                        >
                                            <div className="text-[11px] font-medium uppercase tracking-wide text-subtle">
                                                {c.label}
                                            </div>
                                            <div className="mt-1.5 font-mono text-lg font-semibold tabular-nums text-body">
                                                {c.value}
                                            </div>
                                            {c.delta && (
                                                <div className="mt-1">
                                                    <Badge
                                                        tone={c.delta.dir === "up" ? "success" : "danger"}
                                                        size="sm"
                                                    >
                                                        {c.delta.dir === "up" ? "▲" : "▼"} {c.delta.value}
                                                    </Badge>
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            </>
                        ) : initialLoading ? (
                            <Skeleton className="h-[240px] w-full" rounded="rounded-xl" />
                        ) : (
                            // TrendChart renders its own empty state when the series
                            // is empty (no activity in the last 14 days).
                            <TrendChart data={trend} avg={trendAvg} unit="h" />
                        )}
                    </Card>

                    <Card title="Time breakdown" description="Tracked vs idle vs untracked · today">
                        {initialLoading ? (
                            <div className="space-y-4">
                                <Skeleton className="h-11 w-full" rounded="rounded-xl" />
                                <Skeleton className="h-3 w-2/3" />
                            </div>
                        ) : (
                            <ActivityStrip breakdown={breakdown} hours={false} />
                        )}
                    </Card>
                </div>

                {/* 5 — Team table + 6 — top projects */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <Card
                        className="min-w-0 lg:col-span-2"
                        padded={false}
                        title={teamHeading}
                        description={
                            canViewTeam
                                ? `${activeCount} of ${employees.length} members active today`
                                : "Your work, break and attendance status today"
                        }
                        actions={
                            can("attendance.view") ? (
                                <Button
                                    as={Link}
                                    href={route("employee-attendance.index")}
                                    variant="secondary"
                                    size="sm"
                                >
                                    View all
                                </Button>
                            ) : undefined
                        }
                    >
                        {initialLoading ? (
                            <div className="divide-y divide-line">
                                {[0, 1, 2, 3].map((i) => (
                                    <div key={i} className="flex items-center gap-3 px-5 py-3.5">
                                        <Skeleton className="h-9 w-9" rounded="rounded-full" />
                                        <div className="flex-1 space-y-2">
                                            <Skeleton className="h-3.5 w-40" />
                                            <Skeleton className="h-3 w-24" />
                                        </div>
                                        <Skeleton className="h-6 w-20" rounded="rounded-full" />
                                    </div>
                                ))}
                            </div>
                        ) : employees.length === 0 ? (
                            // A workspace that has NEVER been clocked into is at
                            // a beginning, not at a fault — so it gets a
                            // different sentence, and something to do, from a
                            // workspace whose team simply hasn't started today.
                            gettingStarted && !gettingStarted.complete ? (
                                <EmptyState
                                    icon={<Users className="h-5 w-5" />}
                                    title="Nobody has clocked in yet"
                                    description="This is where your team appears — who's working, who's on break, how long they've been at it. It fills in the moment the first person clocks in."
                                    action={
                                        <Button
                                            as={Link}
                                            href={route("users.create")}
                                            variant="secondary"
                                            size="sm"
                                        >
                                            Invite your team
                                        </Button>
                                    }
                                />
                            ) : (
                                <EmptyState
                                    icon={<Users className="h-5 w-5" />}
                                    title="No activity recorded today"
                                    description="Time-tracking activity will appear here as your team clocks in."
                                />
                            )
                        ) : (
                            <Table>
                                <THead>
                                    <Tr>
                                        <Th>Member</Th>
                                        <Th>Status</Th>
                                        <Th className="text-right">Today</Th>
                                        <Th className="text-right">Tracked</Th>
                                        <Th className="text-right">Activity</Th>
                                        <Th className="text-right">This week</Th>
                                    </Tr>
                                </THead>
                                <TBody>
                                    {sortedEmployees.map((e) => {
                                        const badge = statusBadge(e.current_status);
                                        const hasActivity =
                                            e.is_live || (Number(e.tracked_hours) || 0) > 0;
                                        const pct = Math.max(
                                            0,
                                            Math.min(100, Number(e.activity_percent) || 0)
                                        );
                                        return (
                                            <Tr key={e.user_id}>
                                                <Td>
                                                    <div className="flex items-center gap-3">
                                                        <Avatar
                                                            user={{
                                                                name: e.user_name,
                                                                avatar_url: e.avatar,
                                                            }}
                                                            size="sm"
                                                        />
                                                        <div className="min-w-0">
                                                            <div className="truncate text-sm font-semibold text-body">
                                                                {e.user_name}
                                                            </div>
                                                            {e.designation && (
                                                                <div className="truncate text-xs text-subtle">
                                                                    {e.designation}
                                                                </div>
                                                            )}
                                                        </div>
                                                    </div>
                                                </Td>
                                                <Td>
                                                    <Badge tone={badge.tone} dot>
                                                        {badge.label}
                                                    </Badge>
                                                </Td>
                                                <Td className="text-right font-medium tabular-nums">
                                                    {formatHours(e.total_work_hours)}
                                                </Td>
                                                <Td className="text-right tabular-nums text-muted">
                                                    {formatHours(e.tracked_hours || 0)}
                                                </Td>
                                                <Td className="text-right">
                                                    {hasActivity ? (
                                                        <div className="flex items-center justify-end gap-2">
                                                            <span className="w-9 text-right text-xs font-semibold tabular-nums text-muted">
                                                                {pct}%
                                                            </span>
                                                            <span className="h-1.5 w-16 overflow-hidden rounded-full bg-surface-2">
                                                                <span
                                                                    className={cx(
                                                                        "block h-full rounded-full",
                                                                        activityBarClass(pct)
                                                                    )}
                                                                    style={{ width: `${pct}%` }}
                                                                />
                                                            </span>
                                                        </div>
                                                    ) : (
                                                        <span className="text-xs text-subtle">—</span>
                                                    )}
                                                </Td>
                                                <Td className="text-right tabular-nums text-muted">
                                                    {formatHours(e.weekly_work_hours || 0)}
                                                </Td>
                                            </Tr>
                                        );
                                    })}
                                </TBody>
                            </Table>
                        )}
                    </Card>

                    <Card title="Top projects" description="By tracked hours · this week">
                        {preview ? (
                            <RankedBars items={DEV_SAMPLE.projects} />
                        ) : (
                            <EmptyState
                                title="No project data yet"
                                description="Once time is tracked against projects, the leaders show up here."
                            />
                        )}
                    </Card>
                </div>

                {/* Not in this plan — shown, never silently missing. */}
                {lockedModules.length > 0 && (
                    <section className="space-y-3" aria-labelledby="locked-modules-heading">
                        <h2
                            id="locked-modules-heading"
                            className="text-sm font-semibold uppercase tracking-wide text-subtle"
                        >
                            Not in your plan
                        </h2>
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                            {lockedModules.map((m) => (
                                <LockedFeature
                                    key={m.key}
                                    variant="panel"
                                    feature={m.key}
                                    name={m.name}
                                    blurb={m.blurb}
                                    // In preview there is no Ziggy manifest; the
                                    // component resolves billing itself otherwise.
                                    href={preview ? "#" : undefined}
                                />
                            ))}
                        </div>
                    </section>
                )}
            </div>
        </AppShell>
    );
}
