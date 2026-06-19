import { useEffect, useMemo, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    BarChart3,
    Briefcase,
    Building2,
    CalendarDays,
    ChevronDown,
    Clock,
    CreditCard,
    Film,
    LayoutDashboard,
    LogOut,
    Menu,
    MonitorDown,
    Palette,
    PanelLeftClose,
    PanelLeftOpen,
    Plus,
    Settings as SettingsIcon,
    UserCircle,
    Users,
    UsersRound,
    Wrench,
    X,
} from 'lucide-react';
import AiChatWidget from '@/Components/AiChatWidget';
import ApplicationLogo from '@/Components/ApplicationLogo';
import Avatar from '@/Components/Avatar';
import Dropdown from '@/Components/Dropdown';
import ThemeSwitcher from '@/Components/ThemeSwitcher';

function isActiveRoute(names) {
    return names.some((name) => route().current(name));
}

// A single sidebar link. Collapsed → icon only (label moves to a tooltip).
function SideLink({ item, collapsed, onClick }) {
    const Icon = item.icon;
    const active = isActiveRoute(item.active);

    return (
        <Link
            href={item.href}
            onClick={onClick}
            prefetch
            cacheFor="20s"
            title={collapsed ? item.label : undefined}
            aria-current={active ? 'page' : undefined}
            className={[
                'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition',
                collapsed ? 'justify-center' : '',
                active
                    ? 'bg-accent text-white shadow-sm shadow-black/20'
                    : 'text-muted hover:bg-surface-2 hover:text-body',
            ].join(' ')}
        >
            <Icon className="h-[18px] w-[18px] shrink-0" />
            {!collapsed && <span className="truncate">{item.label}</span>}
        </Link>
    );
}

// A labelled group of links. When collapsed the label becomes a divider so the
// rail stays scannable without text.
function NavSection({ label, items, collapsed, onItemClick }) {
    if (!items.length) {
        return null;
    }

    return (
        <div className="space-y-0.5">
            {label &&
                (collapsed ? (
                    <div className="mx-2 my-2 border-t border-line" aria-hidden="true" />
                ) : (
                    <div className="px-3 pb-1 pt-4 text-[11px] font-semibold uppercase tracking-wider text-subtle">
                        {label}
                    </div>
                ))}
            {items.map((item) => (
                <SideLink key={item.label} item={item} collapsed={collapsed} onClick={onItemClick} />
            ))}
        </div>
    );
}

// Token-themed entry for the bottom user menu (Dropdown.Link is hard-coded light).
function UserMenuLink({ icon: Icon, children, ...props }) {
    return (
        <Link
            {...props}
            className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-muted transition hover:bg-surface-2 hover:text-body"
        >
            <Icon className="h-4 w-4" />
            {children}
        </Link>
    );
}

export default function Authenticated({ user, header, children }) {
    const page = usePage();
    const can = (permission) => user?.is_super_admin || user?.permissions?.includes(permission);
    const canCreateManualWorkHour = user?.can_create_manual_work_hour ?? true;
    const brandName = page.props?.branding?.name || 'Tracksuite';
    const impersonating = page.props?.impersonating;

    // Desktop collapse state is remembered; the mobile drawer always starts closed.
    const [collapsed, setCollapsed] = useState(() => {
        if (typeof window === 'undefined') return false;
        return window.localStorage.getItem('tt_sidebar_collapsed') === '1';
    });
    const [mobileOpen, setMobileOpen] = useState(false);

    useEffect(() => {
        window.localStorage.setItem('tt_sidebar_collapsed', collapsed ? '1' : '0');
    }, [collapsed]);

    // Close the mobile drawer after any navigation.
    useEffect(() => router.on('navigate', () => setMobileOpen(false)), []);

    // Primary, always-visible links.
    const primaryItems = useMemo(() => [
        { label: 'Dashboard', href: route('dashboard'), icon: LayoutDashboard, active: ['dashboard'] },
        { label: 'Timeline', href: route('timeline.index'), icon: Film, active: ['timeline.index'] },
        { label: 'Work Diary', href: route('work-hours.index'), icon: Clock, active: ['work-hours.index', 'work-hours.create', 'work-hours.edit'] },
        can('attendance.view') && { label: 'Attendance', href: route('employee-attendance.index'), icon: CalendarDays, active: ['employee-attendance.index'] },
    ].filter(Boolean), [user?.is_super_admin, user?.permissions]);

    // Reporting & analytics.
    const performanceItems = useMemo(() => [
        can('reports.view') && { label: 'Reports', href: route('work-hours.report'), icon: BarChart3, active: ['work-hours.report'] },
        can('timeline.view_others') && { label: 'Team Performance', href: route('team.index'), icon: Activity, active: ['team.index'] },
    ].filter(Boolean), [user?.is_super_admin, user?.permissions]);

    // Internal management — people and client records.
    const managementItems = useMemo(() => [
        can('users.view') && { label: 'Users', href: route('users.index'), icon: Users, active: ['users.index', 'users.create', 'users.edit'] },
        can('users.view') && { label: 'Teams', href: route('teams.index'), icon: UsersRound, active: ['teams.index'] },
        can('clients.view') && { label: 'Clients', href: route('clients.index'), icon: Briefcase, active: ['clients.index', 'clients.create', 'clients.edit'] },
        can('profiles.view') && { label: 'Profiles', href: route('upwork-profiles.index'), icon: UserCircle, active: ['upwork-profiles.index', 'upwork-profiles.create', 'upwork-profiles.edit'] },
    ].filter(Boolean), [user?.is_super_admin, user?.permissions]);

    // System configuration — tenant admins.
    const systemItems = useMemo(() => [
        can('monitoring.settings') && { label: 'Settings', href: route('settings.index'), icon: SettingsIcon, active: ['settings.index'] },
        can('monitoring.settings') && { label: 'Branding', href: route('settings.branding'), icon: Palette, active: ['settings.branding'] },
        user?.is_super_admin && { label: 'Billing', href: route('billing.index'), icon: CreditCard, active: ['billing.index'] },
    ].filter(Boolean), [user?.is_super_admin, user?.permissions]);

    // Platform (landlord) console — only for us. Developer lives here now: it
    // edits platform-wide .env secrets, so it must never be a tenant tool.
    const platformItems = useMemo(() => [
        user?.is_platform_admin && { label: 'Platform', href: route('platform.index'), icon: Building2, active: ['platform.index', 'platform.show'] },
        user?.is_platform_admin && { label: 'Plans', href: route('platform.plans'), icon: CreditCard, active: ['platform.plans'] },
        user?.is_platform_admin && { label: 'Developer', href: route('developer.index'), icon: Wrench, active: ['developer.index'] },
    ].filter(Boolean), [user?.is_platform_admin]);

    const closeMobile = () => setMobileOpen(false);

    return (
        <div className="min-h-screen bg-app text-body">
            {impersonating && (
                <div className="flex flex-wrap items-center justify-center gap-3 bg-amber-500 px-4 py-2 text-center text-sm font-semibold text-slate-950">
                    <span>You&apos;re viewing a customer workspace as the platform admin.</span>
                    <button
                        type="button"
                        onClick={() => router.post(route('platform.stop-impersonating'))}
                        className="rounded-md bg-slate-950/20 px-2.5 py-0.5 hover:bg-slate-950/30"
                    >
                        Return to platform
                    </button>
                </div>
            )}

            <div className="flex min-h-screen">
                {/* Mobile overlay */}
                {mobileOpen && (
                    <div
                        className="fixed inset-0 z-40 bg-black/60 lg:hidden"
                        onClick={closeMobile}
                        aria-hidden="true"
                    />
                )}

                {/* Sidebar */}
                <aside
                    className={[
                        'fixed inset-y-0 left-0 z-50 flex flex-col border-r border-line bg-surface transition-all duration-200',
                        'lg:sticky lg:top-0 lg:h-screen lg:translate-x-0',
                        collapsed ? 'w-64 lg:w-[4.5rem]' : 'w-64',
                        mobileOpen ? 'translate-x-0' : '-translate-x-full',
                    ].join(' ')}
                >
                    {/* Brand */}
                    <div className="flex h-16 shrink-0 items-center gap-3 border-b border-line px-3">
                        <Link href={route('dashboard')} className="flex min-w-0 flex-1 items-center gap-3">
                            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-surface-2 ring-1 ring-line">
                                <ApplicationLogo size="8" />
                            </span>
                            {!collapsed && (
                                <span className="min-w-0 leading-tight">
                                    <span className="block truncate text-sm font-bold text-body">{brandName}</span>
                                    <span className="block text-[11px] font-medium text-subtle">Workspace</span>
                                </span>
                            )}
                        </Link>
                        <button
                            type="button"
                            onClick={closeMobile}
                            className="rounded-lg p-1.5 text-muted hover:bg-surface-2 hover:text-body lg:hidden"
                            aria-label="Close navigation"
                        >
                            <X className="h-5 w-5" />
                        </button>
                    </div>

                    {/* Nav */}
                    <nav className="flex-1 space-y-1 overflow-y-auto px-3 py-3">
                        {canCreateManualWorkHour && (
                            <Link
                                href={route('work-hours.create')}
                                onClick={closeMobile}
                                title={collapsed ? 'Add Manual Time' : undefined}
                                className={[
                                    'mb-1 flex items-center gap-2 rounded-lg bg-accent px-3 py-2 text-sm font-semibold text-white shadow-sm shadow-black/20 transition hover:opacity-90',
                                    collapsed ? 'justify-center' : '',
                                ].join(' ')}
                            >
                                <Plus className="h-[18px] w-[18px] shrink-0" />
                                {!collapsed && <span>Add Manual Time</span>}
                            </Link>
                        )}

                        <NavSection items={primaryItems} collapsed={collapsed} onItemClick={closeMobile} />
                        <NavSection label="Performance" items={performanceItems} collapsed={collapsed} onItemClick={closeMobile} />
                        <NavSection label="Management" items={managementItems} collapsed={collapsed} onItemClick={closeMobile} />
                        <NavSection label="System" items={systemItems} collapsed={collapsed} onItemClick={closeMobile} />
                        <NavSection label="Platform" items={platformItems} collapsed={collapsed} onItemClick={closeMobile} />
                    </nav>

                    {/* Footer: theme + user + collapse */}
                    <div className="shrink-0 space-y-2 border-t border-line p-3">
                        {!collapsed && <ThemeSwitcher />}

                        <Dropdown>
                            <Dropdown.Trigger>
                                <button
                                    type="button"
                                    className={[
                                        'flex w-full items-center gap-2 rounded-lg border border-line bg-surface-2 px-2 py-2 text-left text-sm transition hover:bg-surface',
                                        collapsed ? 'justify-center' : '',
                                    ].join(' ')}
                                    title={collapsed ? user?.name : undefined}
                                >
                                    <Avatar user={user} size="sm" />
                                    {!collapsed && (
                                        <>
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate font-medium text-body">{user?.name}</span>
                                                <span className="block truncate text-xs text-subtle">{user?.email}</span>
                                            </span>
                                            <ChevronDown className="h-4 w-4 shrink-0 text-subtle" />
                                        </>
                                    )}
                                </button>
                            </Dropdown.Trigger>
                            <Dropdown.Content
                                direction="up"
                                align="left"
                                width="56"
                                contentClasses="py-1 bg-surface border border-line"
                            >
                                <UserMenuLink href={route('profile.edit')} icon={UserCircle}>
                                    Profile
                                </UserMenuLink>
                                <UserMenuLink href={route('desktop-downloads.index')} icon={MonitorDown}>
                                    Desktop App
                                </UserMenuLink>
                                <UserMenuLink href={route('logout')} method="post" as="button" icon={LogOut}>
                                    Log Out
                                </UserMenuLink>
                            </Dropdown.Content>
                        </Dropdown>

                        <button
                            type="button"
                            onClick={() => setCollapsed((c) => !c)}
                            className="hidden w-full items-center justify-center gap-2 rounded-lg px-3 py-1.5 text-xs font-medium text-subtle transition hover:bg-surface-2 hover:text-body lg:flex"
                        >
                            {collapsed ? (
                                <PanelLeftOpen className="h-4 w-4" />
                            ) : (
                                <>
                                    <PanelLeftClose className="h-4 w-4" />
                                    <span>Collapse</span>
                                </>
                            )}
                        </button>
                    </div>
                </aside>

                {/* Main column */}
                <div className="flex min-w-0 flex-1 flex-col">
                    {/* Mobile top bar */}
                    <div className="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-line bg-surface px-4 lg:hidden">
                        <button
                            type="button"
                            onClick={() => setMobileOpen(true)}
                            className="rounded-lg p-2 text-muted transition hover:bg-surface-2 hover:text-body"
                            aria-label="Open navigation"
                        >
                            <Menu className="h-6 w-6" />
                        </button>
                        <span className="flex h-8 w-8 items-center justify-center rounded-md bg-surface-2 ring-1 ring-line">
                            <ApplicationLogo size="6" />
                        </span>
                        <span className="truncate font-semibold text-body">{brandName}</span>
                    </div>

                    {header && (
                        <header className="border-b border-line bg-surface">
                            <div className="w-full px-4 py-4 sm:px-6 lg:px-8">
                                <h1 className="flex items-center gap-3 text-xl font-semibold text-body">
                                    <span className="h-5 w-1 rounded-full bg-accent" aria-hidden="true" />
                                    {typeof header === 'string' ? header : (header?.props?.children || 'Page')}
                                </h1>
                            </div>
                        </header>
                    )}

                    <main className="flex-1">{children}</main>
                </div>
            </div>

            {/* Floating AI chat — only for people granted the AI permission
                (each question costs API credit). */}
            {can('ai.assistant') && <AiChatWidget />}
        </div>
    );
}
