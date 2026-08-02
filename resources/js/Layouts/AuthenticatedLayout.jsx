import AppShell from '@/Layouts/AppShell';

/**
 * AuthenticatedLayout — thin compatibility wrapper over the new top-nav
 * {@link AppShell}. Kept so the pages that already import this layout as
 * `<AuthenticatedLayout user={auth.user} header={...}>…</AuthenticatedLayout>`
 * migrate onto the new shell with zero page edits.
 *
 * Public interface is unchanged: `({ user, header, children })`.
 *
 * It reproduces the old page-header bar (bordered, leading accent tick + title)
 * and hands it to AppShell as an already-styled `header` node. AppShell renders
 * that node raw above the page content and provides all shared chrome: the top
 * nav, search, ClockControl, "+ Time" quick action, notifications, theme
 * switch, avatar menu, impersonation banner and the floating AI widget.
 */
export default function Authenticated({ user, header, children }) {
    // The old layout accepted either a plain string or an <h2>Title</h2> node
    // and always rendered its own <h1> with a leading accent bar. Preserve that
    // title-extraction behaviour exactly so no page needs to change.
    const title =
        typeof header === 'string' ? header : header?.props?.children || 'Page';

    const styledHeader = header ? (
        <header className="border-b border-line bg-surface">
            <div className="w-full px-4 py-4 sm:px-6 lg:px-8">
                <h1 className="flex items-center gap-3 text-xl font-semibold text-body">
                    <span className="h-5 w-1 rounded-full bg-accent" aria-hidden="true" />
                    {title}
                </h1>
            </div>
        </header>
    ) : null;

    return (
        <AppShell user={user} header={styledHeader}>
            {children}
        </AppShell>
    );
}
