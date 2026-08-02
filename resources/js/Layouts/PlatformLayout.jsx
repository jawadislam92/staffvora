import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ApplicationLogo from '@/Components/ApplicationLogo';

const cx = (...a) => a.filter(Boolean).join(' ');

// Operator shell for the Staffvora landlord/operator portal (platform guard).
// DISTINCT from the customer AppShell — its own sidebar + user menu — but fully
// tokenized so it reads correctly in all three themes (dark / light / black).
// A platform_user has no tenant / permissions / org; nav is role-aware:
//   Support -> read/help only · Admin+ -> manage · Owner -> also runs staff.

// Preview fallback so `preview` routes (no authenticated platform user) render.
const MOCK_PLATFORM_USER = { name: 'Jawad', email: 'jawad@staffvora.test', role: 'owner' };

// href = navigation target (stable path). match = Ziggy route-name patterns used
// for active state via route().current(); path = fallback prefix when Ziggy is
// unavailable (e.g. offline preview render).
function navFor(role) {
    const isAdmin = role === 'owner' || role === 'admin';
    return [
        { label: 'Dashboard', href: '/dashboard', path: '/dashboard', match: ['platform.dashboard'] },
        { label: 'Tenants', href: '/tenants', path: '/tenants', match: ['platform.index', 'platform.show'] },
        isAdmin && { label: 'Plans', href: '/plans', path: '/plans', match: ['platform.plans'] },
        isAdmin && { label: 'Add-ons', href: '/addons', path: '/addons', match: ['platform.addons', 'platform.addons.*'] },
        isAdmin && { label: 'Regions', href: '/regions', path: '/regions', match: ['platform.regions', 'platform.regions.*'] },
        // Owner only: deactivating a feature revokes it for every tenant at once.
        role === 'owner' && { label: 'Features', href: '/features', path: '/features', match: ['platform.features', 'platform.features.*'] },
        isAdmin && { label: 'Activity', href: '/audit', path: '/audit', match: ['platform.audit'] },
        isAdmin && { label: 'Website', href: '/site', path: '/site', match: ['platform.site.*'] },
        isAdmin && { label: 'Blog', href: '/blog', path: '/blog', match: ['platform.blog.*'] },
        isAdmin && { label: 'Leads', href: '/leads', path: '/leads', match: ['platform.leads.*'] },
        isAdmin && { label: 'Developer', href: '/developer', path: '/developer', match: ['developer.*'] },
        role === 'owner' && { label: 'Staff', href: '/staff', path: '/staff', match: ['platform.staff', 'platform.staff.*'] },
        { label: 'Account', href: '/account', path: '/account', match: ['platform.account'] },
    ].filter(Boolean);
}

// Active-state: prefer Ziggy route().current(name); fall back to pathname prefix.
function isActive(item) {
    if (typeof route === 'function') {
        try {
            const r = route();
            if (item.match.some((p) => r.current(p))) return true;
            // If Ziggy resolved a route at all, trust it (don't double-match on path).
            if (r.current()) return false;
        } catch {
            /* fall through to pathname */
        }
    }
    const current = typeof window !== 'undefined' ? window.location.pathname : '';
    if (item.href === '/dashboard') return current === item.path || current === '/';
    return current === item.path || current.startsWith(item.path + '/');
}

function initialOf(name) {
    return (name || 'Operator').trim().charAt(0).toUpperCase() || 'O';
}

// Shared operator page header — title + optional description + optional actions.
// Pages may import { PlatformPageHeader } and pass it as the layout `header`.
export function PlatformPageHeader({ title, description, actions, className }) {
    return (
        <div className={cx('flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between', className)}>
            <div className="min-w-0">
                <h1 className="truncate text-xl font-semibold text-body">{title}</h1>
                {description && <p className="mt-1 text-sm text-muted">{description}</p>}
            </div>
            {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}

function NavLinks({ items, orientation = 'vertical' }) {
    return (
        <>
            {items.map((n) => {
                const active = isActive(n);
                return (
                    <Link
                        key={n.href}
                        href={n.href}
                        aria-current={active ? 'page' : undefined}
                        className={cx(
                            'rounded-lg px-3 py-2 text-sm font-medium transition',
                            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app',
                            orientation === 'vertical' ? 'block' : 'shrink-0 whitespace-nowrap',
                            active
                                ? 'bg-accent/10 text-accent'
                                : 'text-muted hover:bg-surface-2 hover:text-body',
                        )}
                    >
                        {n.label}
                    </Link>
                );
            })}
        </>
    );
}

function Brand() {
    return (
        <div className="flex items-center gap-2.5">
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-accent/10 ring-1 ring-accent/20">
                <ApplicationLogo size="28" />
            </span>
            <div className="leading-tight">
                <div className="text-sm font-bold text-body">STAFFVORA</div>
                <div className="text-[11px] font-medium uppercase tracking-wide text-subtle">Operations</div>
            </div>
        </div>
    );
}

function UserMenu({ user, align = 'up' }) {
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        if (!open) return undefined;
        const onDoc = (e) => {
            if (ref.current && !ref.current.contains(e.target)) setOpen(false);
        };
        const onKey = (e) => e.key === 'Escape' && setOpen(false);
        document.addEventListener('mousedown', onDoc);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDoc);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    const logout = () => router.post('/logout');

    return (
        <div ref={ref} className="relative">
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-haspopup="menu"
                aria-expanded={open}
                className={cx(
                    'flex w-full items-center gap-2.5 rounded-lg border border-line bg-surface-2 px-2.5 py-2 text-left transition hover:bg-surface',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app',
                )}
            >
                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-accent/15 text-sm font-bold text-accent">
                    {initialOf(user?.name)}
                </span>
                <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium text-body">{user?.name || 'Operator'}</span>
                    <span className="block truncate text-[11px] capitalize text-subtle">{user?.role || 'staff'}</span>
                </span>
                <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" className="h-4 w-4 shrink-0 text-subtle">
                    <path fillRule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clipRule="evenodd" />
                </svg>
            </button>
            {open && (
                <div
                    role="menu"
                    className={cx(
                        'absolute right-0 z-30 w-full min-w-[12rem] overflow-hidden rounded-lg border border-line bg-surface shadow-lg',
                        align === 'up' ? 'bottom-full mb-2' : 'top-full mt-2',
                    )}
                >
                    <div className="border-b border-line px-3 py-2">
                        <div className="truncate text-sm font-medium text-body">{user?.name || 'Operator'}</div>
                        {user?.email && <div className="truncate text-xs text-subtle">{user.email}</div>}
                    </div>
                    <button
                        type="button"
                        role="menuitem"
                        onClick={logout}
                        className="block w-full px-3 py-2 text-left text-sm font-medium text-body transition hover:bg-surface-2 focus-visible:outline-none focus-visible:bg-surface-2"
                    >
                        Log out
                    </button>
                </div>
            )}
        </div>
    );
}

export default function PlatformLayout({ children, title, header }) {
    const platformUser = usePage().props.platformUser || MOCK_PLATFORM_USER;
    const NAV = navFor(platformUser?.role);

    return (
        <div className="min-h-screen bg-app text-body">
            <Head title={title || 'Operator portal'} />
            <div className="flex min-h-screen flex-col lg:flex-row">
                {/* Desktop sidebar */}
                <aside className="hidden w-60 shrink-0 flex-col border-r border-line bg-surface p-4 lg:flex">
                    <div className="mb-6 px-1">
                        <Brand />
                    </div>
                    <nav className="flex-1 space-y-1">
                        <NavLinks items={NAV} />
                    </nav>
                    <div className="pt-3">
                        <UserMenu user={platformUser} align="up" />
                    </div>
                </aside>

                {/* Mobile top bar + nav strip */}
                <header className="border-b border-line bg-surface lg:hidden">
                    <div className="flex items-center justify-between gap-3 p-4">
                        <Brand />
                        <div className="w-44">
                            <UserMenu user={platformUser} align="down" />
                        </div>
                    </div>
                    <nav className="flex gap-1 overflow-x-auto px-3 pb-3">
                        <NavLinks items={NAV} orientation="horizontal" />
                    </nav>
                </header>

                <main className="min-w-0 flex-1 p-5 sm:p-8">
                    {header && <div className="mb-6">{header}</div>}
                    {children}
                </main>
            </div>
        </div>
    );
}
