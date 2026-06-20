import { useState } from 'react';
import { Link } from '@inertiajs/react';

// Internal app routes use Inertia <Link>; marketing links use plain <a>.
const isAppRoute = (url) => ['/login', '/register', '/dashboard'].includes(url);

function NavLink({ url, label, className, style }) {
    return isAppRoute(url)
        ? <Link href={url} className={className} style={style}>{label}</Link>
        : <a href={url} className={className} style={style}>{label}</a>;
}

export default function SiteHeader({ theme, isAuthed }) {
    const [open, setOpen] = useState(false);
    const links = theme?.nav_links || [];
    const cta = isAuthed
        ? { url: '/dashboard', label: 'Go to dashboard' }
        : { url: '/register', label: 'Start free' };

    return (
        <header className="sticky top-0 z-40 border-b border-zinc-200/70 bg-white/80 backdrop-blur-xl">
            <div className="mx-auto flex max-w-7xl items-center justify-between px-6 py-3.5">
                <a href="/" className="flex items-center gap-2.5">
                    {theme?.logo_url ? (
                        <img src={theme.logo_url} alt="" className="h-8 w-8 object-contain" />
                    ) : (
                        <span className="flex h-8 w-8 items-center justify-center rounded-lg text-sm font-black text-white" style={{ background: 'var(--a)' }}>
                            {(theme?.site_name || 'T').charAt(0)}
                        </span>
                    )}
                    <span className="text-lg font-bold tracking-tight text-zinc-900">{theme?.site_name || 'Tracksuite'}</span>
                </a>

                <nav className="hidden items-center gap-7 md:flex">
                    {links.map((l, i) => (
                        <NavLink key={i} url={l.url} label={l.label} className="text-sm font-medium text-zinc-600 transition hover:text-zinc-900" />
                    ))}
                </nav>

                <div className="hidden items-center gap-3 md:flex">
                    {!isAuthed && (
                        <Link href="/login" className="text-sm font-semibold text-zinc-600 transition hover:text-zinc-900">Sign in</Link>
                    )}
                    <NavLink url={cta.url} label={cta.label} style={{ background: 'var(--a)' }} className="rounded-full px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90" />
                </div>

                <button type="button" onClick={() => setOpen((o) => !o)} className="rounded-lg p-2 text-zinc-700 md:hidden" aria-label="Menu">
                    <svg className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
                </button>
            </div>

            {open && (
                <div className="border-t border-zinc-200 bg-white px-6 py-4 md:hidden">
                    <div className="flex flex-col gap-3">
                        {links.map((l, i) => (
                            <NavLink key={i} url={l.url} label={l.label} className="text-sm font-medium text-zinc-700" />
                        ))}
                        {!isAuthed && <Link href="/login" className="text-sm font-semibold text-zinc-700">Sign in</Link>}
                        <NavLink url={cta.url} label={cta.label} style={{ background: 'var(--a)' }} className="mt-1 rounded-full px-4 py-2 text-center text-sm font-semibold text-white" />
                    </div>
                </div>
            )}
        </header>
    );
}
