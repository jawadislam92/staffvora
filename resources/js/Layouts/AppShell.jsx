import { useEffect, useLayoutEffect, useMemo, useRef, useState } from "react";
import { Link, router, usePage } from "@inertiajs/react";
import {
    Activity,
    BarChart3,
    Briefcase,
    CalendarDays,
    ClipboardCheck,
    ChevronDown,
    Clock,
    CreditCard,
    HelpCircle,
    LayoutDashboard,
    LogOut,
    Menu,
    MessageSquare,
    MonitorDown,
    Plus,
    Settings as SettingsIcon,
    ShieldCheck,
    UserCircle,
    Users,
    X,
} from "lucide-react";
import AiChatWidget from "@/Components/AiChatWidget";
import ApplicationLogo from "@/Components/ApplicationLogo";
import Avatar from "@/Components/Avatar";
import Dropdown from "@/Components/Dropdown";
import ThemeSwitcher from "@/Components/ThemeSwitcher";
import ClockControl from "@/Components/ClockControl";
import { LockBadge } from "@/Components/ui";

const cx = (...a) => a.filter(Boolean).join(" ");

// Match against one or more route names.
const isActive = (names) => names.some((n) => route().current(n));

// A single top-nav link with an accent underline when active.
// A LOCKED item stays visible and clickable — the click lands on the Upgrade
// page that explains what the feature is and how to get it. It is dimmed and
// carries a lock so nobody mistakes it for something they already have; it is
// NOT disabled, because "why can't I click this?" is a worse experience than
// a page that answers the question.
function NavLink({ item, onClick }) {
    const active = isActive(item.active);
    const locked = item.locked === true;
    return (
        <Link
            href={item.href}
            onClick={onClick}
            prefetch={!locked}
            cacheFor="20s"
            aria-current={active ? "page" : undefined}
            title={locked ? `${item.label} — not included in your plan` : undefined}
            className={cx(
                "relative inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-2 text-[13px] font-medium transition",
                "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app",
                locked
                    ? "text-subtle hover:bg-surface-2 hover:text-muted"
                    : active
                      ? "text-body after:absolute after:inset-x-3 after:-bottom-[13px] after:h-0.5 after:rounded-full after:bg-accent after:content-['']"
                      : "text-muted hover:bg-surface-2 hover:text-body"
            )}
        >
            {item.label}
            {locked && <LockBadge />}
        </Link>
    );
}

// Token-themed row for menus (Dropdown.Link is hard-coded light).
function MenuItem({ icon: Icon, children, ...props }) {
    return (
        <Link
            {...props}
            className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-muted transition hover:bg-surface-2 hover:text-body focus-visible:bg-surface-2 focus-visible:text-body focus-visible:outline-none"
        >
            {Icon && <Icon className="h-4 w-4 shrink-0" />}
            <span className="truncate">{children}</span>
        </Link>
    );
}

/**
 * AppShell — top-nav application layout for Staffvora.
 * Sticky translucent top bar with the brand, primary nav (measured overflow →
 * More), the ClockControl, theme switch and an avatar menu. Below the `lg`
 * breakpoint the primary nav collapses into a hamburger drawer; at and above it
 * the inline item count is measured so the header always fits its container.
 *
 * @param {object} props.user       Auth user.
 * @param {React.ReactNode} [props.header]  Optional page header node.
 * @param {Array|React.ReactNode} [props.subnav]  Contextual sub-nav.
 * @param {React.ReactNode} props.children  Page content.
 */
export default function AppShell({ user, header, subnav, children }) {
    const page = usePage();
    const can = (p) => user?.is_super_admin || user?.permissions?.includes(p);

    const workspace = page.props?.workspace || {};
    // Capability comes from the shared `entitlements` map — the SAME resolution
    // the server gates on (override → add-ons → plan → deny). `workspace` now
    // carries terminology only and is never asked whether something is allowed.
    const entitlements = page.props?.entitlements || {};
    const usesProfiles = entitlements.upwork_profiles === true;
    const clientTerm = workspace.client_term || "Client";
    const clientLabel = clientTerm.endsWith("s") ? clientTerm : `${clientTerm}s`;
    // Terminology, not capability: an agency that HAS the module calls it
    // "Upwork Profiles", an entitled in-house workspace just calls it
    // "Profiles". Whether the item renders at all is decided by the entitlement
    // below, never by this label.
    const profileTerm = workspace.profile_term || "Profile";
    const profileLabel = profileTerm.endsWith("s") ? profileTerm : `${profileTerm}s`;
    const brandName = page.props?.branding?.name || "Staffvora";
    const impersonating = page.props?.impersonating;

    const [drawerOpen, setDrawerOpen] = useState(false);
    const [moreOpen, setMoreOpen] = useState(false);
    const moreRef = useRef(null);

    // Refs used to measure how many primary-nav items actually fit, so the
    // header can never exceed its container width (see the overflow effect).
    const barRef = useRef(null);
    const brandRef = useRef(null);
    const actionsRef = useRef(null);
    const measureRef = useRef(null);

    // Close the mobile drawer on any navigation.
    useEffect(() => router.on("navigate", () => setDrawerOpen(false)), []);

    // Close the "More" popover on outside click / Escape.
    useEffect(() => {
        if (!moreOpen) return undefined;
        const onDown = (e) => {
            if (moreRef.current && !moreRef.current.contains(e.target)) setMoreOpen(false);
        };
        const onKey = (e) => e.key === "Escape" && setMoreOpen(false);
        document.addEventListener("mousedown", onDown);
        document.addEventListener("keydown", onKey);
        return () => {
            document.removeEventListener("mousedown", onDown);
            document.removeEventListener("keydown", onKey);
        };
    }, [moreOpen]);

    // Lock body scroll while the drawer is open.
    useEffect(() => {
        if (typeof document === "undefined") return undefined;
        document.body.style.overflow = drawerOpen ? "hidden" : "";
        return () => {
            document.body.style.overflow = "";
        };
    }, [drawerOpen]);

    // Primary nav (permission-gated). Order matches the approved mockup.
    const navItems = useMemo(
        () =>
            [
                { label: "Dashboard", href: route("dashboard"), icon: LayoutDashboard, active: ["dashboard"] },
                {
                    label: "Timesheets",
                    href: route("work-hours.index"),
                    icon: Clock,
                    active: ["work-hours.index", "work-hours.create", "work-hours.edit"],
                },
                { label: "Activity", href: route("timeline.index"), icon: Activity, active: ["timeline.index"] },
                (can("shift.edit_own") || can("shift.manage_all")) && {
                    label: "My Schedule",
                    href: route("shift-overrides.index"),
                    icon: CalendarDays,
                    active: ["shift-overrides.index"],
                },
                can("attendance.view") && {
                    label: "Attendance",
                    href: route("employee-attendance.index"),
                    icon: CalendarDays,
                    active: ["employee-attendance.index"],
                },
                can("reports.view") && {
                    label: "Reports",
                    href: route("work-hours.report"),
                    icon: LayoutDashboard,
                    active: ["work-hours.report"],
                },
                can("timeline.view_others") && {
                    label: "Team",
                    href: route("team.index"),
                    icon: Activity,
                    active: ["team.index"],
                },
                can("users.view") && {
                    label: "People",
                    href: route("users.index"),
                    icon: Users,
                    active: ["users.index", "users.create", "users.edit"],
                },
                can("clients.view") && {
                    label: clientLabel,
                    href: route("clients.index"),
                    icon: Briefcase,
                    active: ["clients.index", "clients.create", "clients.edit"],
                },
                entitlements.timesheet_approvals === true && {
                    label: "Approvals",
                    href: route("timesheets.index"),
                    icon: ClipboardCheck,
                    active: ["timesheets.index", "timesheets.show"],
                },
                // SHOWN, NOT HIDDEN (D-2/D-8): a module the workspace has not
                // bought stays in the nav, marked locked. Clicking it lands on the
                // Upgrade page, which is exactly the explanation the click should
                // produce — a vanished menu item teaches the customer nothing.
                // `=== false` is a DEFINITE deny, so an absent/loading entitlement
                // map upsells nothing. PERMISSION still hides an item outright:
                // a role that could never use the module is not a sales chance.
                can("insights.view") && {
                    label: "Insights",
                    href: route("insights.index"),
                    icon: BarChart3,
                    active: ["insights.index"],
                    locked: entitlements.insights === false,
                },
                // Capability from the entitlement map, permission from the role —
                // the two layers stay orthogonal, exactly like Profiles.
                can("compliance.view") && {
                    label: "Compliance",
                    href: route("monitoring.compliance"),
                    icon: ShieldCheck,
                    active: ["monitoring.compliance"],
                    locked: entitlements.machine_compliance === false,
                },
                // ── THE ONE EXCEPTION TO "SHOWN, NOT HIDDEN" ────────────────
                // Profiles is DOMAIN-SPECIFIC, not universal. Everything above
                // it (Insights, Approvals, Compliance) is something any team —
                // in-house, BPO, freelancer — could plausibly want, so showing
                // it locked is a fair upsell. Profiles is Upwork/marketplace
                // seller-account attribution: to a workspace that tracks its own
                // employees it is not a feature they haven't bought, it is
                // vocabulary from somebody else's business. A permanent locked
                // row advertising a marketplace they don't sell on is noise, and
                // the owner called it out as wrong.
                //
                // So the rule is: universal module → show locked; module that
                // introduces a business model the workspace has not opted into →
                // show only when ENTITLED. The upsell for it lives where it is
                // relevant instead — Settings → Workspace type (pick "Client
                // services agency" and the Agency toolkit is explained there)
                // and the Billing add-on store.
                //
                // `=== true` (not `!== false`) is deliberate: a missing or
                // still-loading entitlement map must not flash the module in.
                // This is presentation only — every `upwork-profiles.*` route is
                // independently gated by `entitlement:upwork_profiles`.
                can("profiles.view") && usesProfiles && {
                    label: profileLabel,
                    href: route("upwork-profiles.index"),
                    icon: UserCircle,
                    active: [
                        "upwork-profiles.index",
                        "upwork-profiles.create",
                        "upwork-profiles.edit",
                    ],
                },
            ].filter(Boolean),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [user?.is_super_admin, user?.permissions, usesProfiles, clientLabel, profileLabel]
    );

    // How many primary-nav items are shown inline (the rest fold into "More").
    // This is MEASURED, not a fixed count: a fixed count overflowed the header
    // on 1024–1366px laptops (brand + 6 links + actions needed ~1386px). We
    // start optimistic (show all) and the layout effect below trims to fit
    // before the first paint, so the header always satisfies
    // scrollWidth <= innerWidth at every width.
    const [inlineCount, setInlineCount] = useState(navItems.length);

    useLayoutEffect(() => {
        const bar = barRef.current;
        const measure = measureRef.current;
        if (!bar || !measure) return undefined;

        const recompute = () => {
            const itemEls = Array.from(measure.querySelectorAll("[data-nav-item]"));
            if (itemEls.length === 0) return;
            const moreEl = measure.querySelector("[data-nav-more]");
            const itemW = itemEls.map((el) => el.getBoundingClientRect().width);
            const moreW = moreEl ? moreEl.getBoundingClientRect().width : 0;
            const gap = 2; // nav uses gap-0.5 (0.125rem)

            const barStyles = getComputedStyle(bar);
            const padX =
                parseFloat(barStyles.paddingLeft) + parseFloat(barStyles.paddingRight);
            const brandW = brandRef.current?.getBoundingClientRect().width || 0;
            const actionsW = actionsRef.current?.getBoundingClientRect().width || 0;
            // bar is a flex row (gap-2 = 8px) of: [hamburger(hidden lg)] brand,
            // nav, flex-1 spacer, actions. Reserve brand + actions + the two
            // outer gaps + the nav's own left margin (ml-2 = 8px) + a small
            // safety margin so we never round up into an overflow.
            const available =
                bar.clientWidth - padX - brandW - actionsW - 8 * 2 - 8 - 6;

            let count = 0;
            let used = 0;
            for (let i = 0; i < itemW.length; i += 1) {
                const w = itemW[i] + (i > 0 ? gap : 0);
                if (used + w <= available) {
                    used += w;
                    count += 1;
                } else {
                    break;
                }
            }
            // If anything folds, the "More" trigger needs room too — trim until
            // it fits alongside the visible items.
            if (count < itemW.length) {
                while (count > 0 && used + gap + moreW > available) {
                    count -= 1;
                    used -= itemW[count] + (count > 0 ? gap : 0);
                }
            }
            setInlineCount(count);
        };

        recompute();
        const ro = new ResizeObserver(recompute);
        ro.observe(bar);
        window.addEventListener("resize", recompute);
        return () => {
            ro.disconnect();
            window.removeEventListener("resize", recompute);
        };
    }, [navItems]);

    const inlineItems = navItems.slice(0, inlineCount);
    const overflowItems = navItems.slice(inlineCount);
    // If an active item lives in the overflow, surface it inline instead.
    const activeInOverflow = overflowItems.some((i) => isActive(i.active));

    // Normalize subnav: accept an array of {label,href,active} or a raw node.
    const subnavIsArray = Array.isArray(subnav);

    return (
        <div className="min-h-screen bg-app text-body">
            {impersonating && (
                <div className="flex flex-wrap items-center justify-center gap-3 bg-warning px-4 py-2 text-center text-sm font-semibold text-slate-950">
                    <span>You&apos;re viewing a customer workspace as the platform admin.</span>
                    <button
                        type="button"
                        onClick={() => router.post(route("platform.stop-impersonating"))}
                        className="rounded-md bg-slate-950/20 px-2.5 py-0.5 hover:bg-slate-950/30"
                    >
                        Return to platform
                    </button>
                </div>
            )}

            {/* Sticky top bar */}
            <header className="sticky top-0 z-40 border-b border-line bg-app/80 backdrop-blur supports-[backdrop-filter]:bg-app/70">
                <div
                    ref={barRef}
                    className="mx-auto flex h-16 max-w-[1600px] items-center gap-2 px-4 sm:px-6 lg:px-8"
                >
                    {/* Hidden measurement copy of the full nav — never shown, used
                        to size the overflow fold. It sits inside a 0×0
                        overflow-hidden box so its (wide) natural content never
                        adds to the document's scroll width; the inner row uses
                        w-max so it keeps content width despite the 0-width box. */}
                    <div
                        aria-hidden="true"
                        className="pointer-events-none absolute left-0 top-0 h-0 w-0 overflow-hidden"
                    >
                        <div ref={measureRef} className="inline-flex w-max items-center gap-0.5">
                            {navItems.map((item) => (
                                <span
                                    key={item.label}
                                    data-nav-item
                                    className="relative inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-2 text-[13px] font-medium"
                                >
                                    {item.label}
                                    {item.locked && <LockBadge />}
                                </span>
                            ))}
                            <span
                                data-nav-more
                                className="relative flex items-center gap-1 whitespace-nowrap rounded-lg px-3 py-2 text-[13px] font-medium"
                            >
                                More
                                <ChevronDown className="h-3.5 w-3.5" />
                            </span>
                        </div>
                    </div>

                    {/* Hamburger (below lg) */}
                    <button
                        type="button"
                        onClick={() => setDrawerOpen(true)}
                        className="-ml-1 rounded-lg p-2 text-muted transition hover:bg-surface-2 hover:text-body focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent lg:hidden"
                        aria-label="Open navigation"
                    >
                        <Menu className="h-5 w-5" />
                    </button>

                    {/* Brand */}
                    <Link
                        ref={brandRef}
                        href={route("dashboard")}
                        aria-label={brandName}
                        className="flex shrink-0 items-center gap-2.5 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    >
                        <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-surface-2 ring-1 ring-line">
                            <ApplicationLogo size="7" />
                        </span>
                        <span className="hidden text-[17px] font-bold tracking-tight text-body sm:block">
                            {brandName}
                        </span>
                    </Link>

                    {/* Primary nav (lg+). `min-w-0` lets it shrink rather than
                        push the header wider than the viewport before the
                        measurement effect trims the inline count. */}
                    <nav className="ml-2 hidden min-w-0 items-center gap-0.5 lg:flex">
                        {inlineItems.map((item) => (
                            <NavLink key={item.label} item={item} />
                        ))}

                        {overflowItems.length > 0 && (
                            <div className="relative" ref={moreRef}>
                                <button
                                    type="button"
                                    onClick={() => setMoreOpen((o) => !o)}
                                    aria-expanded={moreOpen}
                                    aria-haspopup="true"
                                    className={cx(
                                        "relative flex items-center gap-1 whitespace-nowrap rounded-lg px-3 py-2 text-[13px] font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent",
                                        activeInOverflow
                                            ? "text-body"
                                            : "text-muted hover:bg-surface-2 hover:text-body"
                                    )}
                                >
                                    More
                                    <ChevronDown
                                        className={cx(
                                            "h-3.5 w-3.5 transition-transform",
                                            moreOpen && "rotate-180"
                                        )}
                                    />
                                </button>
                                {moreOpen && (
                                    <div className="absolute left-0 top-full z-50 mt-2 min-w-[200px] overflow-hidden rounded-xl border border-line bg-surface py-1 shadow-lg">
                                        {overflowItems.map((item) => {
                                            const Icon = item.icon;
                                            const active = isActive(item.active);
                                            return (
                                                <Link
                                                    key={item.label}
                                                    href={item.href}
                                                    onClick={() => setMoreOpen(false)}
                                                    prefetch
                                                    cacheFor="20s"
                                                    className={cx(
                                                        "flex items-center gap-2.5 px-3 py-2 text-sm transition",
                                                        active
                                                            ? "bg-accent/10 text-accent"
                                                            : "text-muted hover:bg-surface-2 hover:text-body"
                                                    )}
                                                >
                                                    {Icon && <Icon className="h-4 w-4 shrink-0" />}
                                                    <span className="truncate">{item.label}</span>
                                                </Link>
                                            );
                                        })}
                                    </div>
                                )}
                            </div>
                        )}
                    </nav>

                    {/* Spacer */}
                    <div className="flex-1" />

                    {/* Right cluster */}
                    <div ref={actionsRef} className="flex shrink-0 items-center gap-1.5 sm:gap-2">
                        {/* Add manual time — compact primary quick action.
                            Hidden on very narrow widths. */}
                        {user?.can_create_manual_work_hour && (
                            <Link
                                href={route("work-hours.create")}
                                className="hidden h-9 items-center gap-1.5 rounded-lg bg-accent px-3 text-[13px] font-semibold text-white transition hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app sm:flex"
                                aria-label="Add manual time"
                            >
                                <Plus className="h-4 w-4" />
                                <span>Time</span>
                            </Link>
                        )}

                        <ClockControl user={user} />

                        <div className="hidden sm:block">
                            <ThemeSwitcher />
                        </div>

                        {/* Avatar menu */}
                        <Dropdown>
                            <Dropdown.Trigger>
                                <button
                                    type="button"
                                    className="flex items-center gap-1.5 rounded-full border border-line bg-surface p-0.5 pr-1.5 transition hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                                    aria-label="Account menu"
                                >
                                    <Avatar user={user} size="sm" />
                                    <ChevronDown className="hidden h-4 w-4 text-subtle sm:block" />
                                </button>
                            </Dropdown.Trigger>
                            <Dropdown.Content
                                align="right"
                                width="56"
                                contentClasses="py-1 bg-surface border border-line"
                            >
                                <div className="border-b border-line px-3 py-2.5">
                                    <div className="truncate text-sm font-semibold text-body">
                                        {user?.name}
                                    </div>
                                    <div className="truncate text-xs text-subtle">{user?.email}</div>
                                </div>
                                <div className="py-1">
                                    <MenuItem href={route("profile.edit")} icon={UserCircle}>
                                        Profile
                                    </MenuItem>
                                    {can("monitoring.settings") && (
                                        <MenuItem href={route("settings.index")} icon={SettingsIcon}>
                                            Settings
                                        </MenuItem>
                                    )}
                                    {user?.is_super_admin && (
                                        <MenuItem href={route("billing.index")} icon={CreditCard}>
                                            Billing
                                        </MenuItem>
                                    )}
                                    <MenuItem href={route("help.index")} icon={HelpCircle}>
                                        Help
                                    </MenuItem>
                                    <MenuItem href={route("feedback.index")} icon={MessageSquare}>
                                        {can("feedback.manage") ? "Feedback inbox" : "Send feedback"}
                                    </MenuItem>
                                    <MenuItem href={route("desktop-downloads.index")} icon={MonitorDown}>
                                        Desktop App
                                    </MenuItem>
                                </div>
                                {/* Theme switch surfaces here on narrow widths. */}
                                <div className="border-t border-line px-3 py-2 sm:hidden">
                                    <ThemeSwitcher />
                                </div>
                                <div className="border-t border-line py-1">
                                    <Link
                                        href={route("logout")}
                                        method="post"
                                        as="button"
                                        className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-muted transition hover:bg-surface-2 hover:text-body"
                                    >
                                        <LogOut className="h-4 w-4 shrink-0" />
                                        Log out
                                    </Link>
                                </div>
                            </Dropdown.Content>
                        </Dropdown>
                    </div>
                </div>

                {/* Contextual sub-nav */}
                {subnav && (
                    <div className="border-t border-line bg-app/60">
                        <div className="mx-auto max-w-[1600px] px-4 sm:px-6 lg:px-8">
                            {subnavIsArray ? (
                                <nav className="flex items-center gap-1 overflow-x-auto">
                                    {subnav.map((tab) => (
                                        <Link
                                            key={tab.label}
                                            href={tab.href}
                                            className={cx(
                                                "relative whitespace-nowrap px-3 py-3 text-[13px] font-medium transition",
                                                tab.active
                                                    ? "text-accent after:absolute after:inset-x-3 after:bottom-0 after:h-0.5 after:rounded-full after:bg-accent after:content-['']"
                                                    : "text-muted hover:text-body"
                                            )}
                                        >
                                            {tab.label}
                                        </Link>
                                    ))}
                                </nav>
                            ) : (
                                subnav
                            )}
                        </div>
                    </div>
                )}
            </header>

            {/* Mobile drawer */}
            {drawerOpen && (
                <div className="fixed inset-0 z-50 lg:hidden">
                    <div
                        className="absolute inset-0 bg-black/60"
                        onClick={() => setDrawerOpen(false)}
                        aria-hidden="true"
                    />
                    <div className="absolute inset-y-0 left-0 flex w-72 max-w-[85vw] flex-col border-r border-line bg-surface shadow-xl">
                        <div className="flex h-16 shrink-0 items-center justify-between border-b border-line px-4">
                            <span className="flex items-center gap-2.5">
                                <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-surface-2 ring-1 ring-line">
                                    <ApplicationLogo size="7" />
                                </span>
                                <span className="text-[16px] font-bold tracking-tight text-body">
                                    {brandName}
                                </span>
                            </span>
                            <button
                                type="button"
                                onClick={() => setDrawerOpen(false)}
                                className="rounded-lg p-1.5 text-muted hover:bg-surface-2 hover:text-body"
                                aria-label="Close navigation"
                            >
                                <X className="h-5 w-5" />
                            </button>
                        </div>
                        <nav className="flex-1 space-y-0.5 overflow-y-auto p-3">
                            {navItems.map((item) => {
                                const Icon = item.icon;
                                const active = isActive(item.active);
                                return (
                                    <Link
                                        key={item.label}
                                        href={item.href}
                                        onClick={() => setDrawerOpen(false)}
                                        className={cx(
                                            "flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition",
                                            active
                                                ? "bg-accent text-white"
                                                : "text-muted hover:bg-surface-2 hover:text-body"
                                        )}
                                    >
                                        {Icon && <Icon className="h-[18px] w-[18px] shrink-0" />}
                                        <span className="truncate">{item.label}</span>
                                    </Link>
                                );
                            })}
                        </nav>
                        <div className="shrink-0 border-t border-line p-3">
                            <ThemeSwitcher />
                        </div>
                    </div>
                </div>
            )}

            {/* Bare main — like the old layout. The page header (passed already
                styled) is full-bleed above the content, and each page owns its
                own container/padding, so drop-in pages don't get double padding. */}
            <main className="min-h-[calc(100vh-4rem)]">
                {header}
                {children}
            </main>

            {/* Floating AI chat — only for people granted the AI permission
                (each question costs API credit). */}
            {can("ai.assistant") && <AiChatWidget />}
        </div>
    );
}
