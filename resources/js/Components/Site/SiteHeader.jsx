import { useState } from 'react';
import { Link } from '@inertiajs/react';
import SiteLogo from './SiteLogo';

const isAppRoute = (url) => ['/login', '/register', '/dashboard'].includes(url);

function Nav({ url, label, className }) {
    return isAppRoute(url)
        ? <Link href={url} className={className}>{label}</Link>
        : <a href={url} className={className}>{label}</a>;
}

// A top-level nav entry: a plain link, or a hover dropdown when it has children.
function HeaderItem({ item }) {
    if (!item.children) {
        return <Nav url={item.url} label={item.label} className="text-sm font-medium text-[var(--muted)] transition hover:text-[var(--brand)]" />;
    }
    return (
        <div className="group relative">
            <button type="button" className="inline-flex items-center gap-1 text-sm font-medium text-[var(--muted)] transition hover:text-[var(--brand)]">
                {item.label}
                <svg className="h-4 w-4 opacity-60 transition group-hover:rotate-180" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6" /></svg>
            </button>
            <div className="invisible absolute left-1/2 top-full z-50 -translate-x-1/2 pt-3 opacity-0 transition group-hover:visible group-hover:opacity-100">
                <div className="min-w-[230px] rounded-2xl border border-[var(--line)] bg-white p-2 shadow-xl shadow-black/[0.04]">
                    {item.children.map((c, i) => (
                        <Nav key={i} url={c.url} label={c.label} className="block rounded-xl px-3 py-2 text-sm font-medium text-[var(--muted)] transition hover:bg-[var(--paper)] hover:text-[var(--brand)]" />
                    ))}
                </div>
            </div>
        </div>
    );
}

export default function SiteHeader({ nav = [], brand, isAuthed }) {
    const [open, setOpen] = useState(false);
    const name = brand?.name || 'Tracksuite';
    const cta = isAuthed ? { url: '/dashboard', label: 'Go to dashboard' } : { url: '/register', label: 'Start free trial' };

    return (
        <header className="sticky top-0 z-40 border-b border-[var(--line)] bg-[var(--canvas)]/85 backdrop-blur-xl">
            <div className="mx-auto flex max-w-[1600px] items-center justify-between gap-6 px-5 py-3.5 sm:px-10">
                <div className="flex items-center gap-9">
                    <a href="/" aria-label={name}><SiteLogo name={name} size={28} textClass="text-lg" /></a>
                    <nav className="hidden items-center gap-7 lg:flex">
                        {nav.map((item, i) => <HeaderItem key={i} item={item} />)}
                    </nav>
                </div>

                <div className="hidden items-center gap-4 lg:flex">
                    {!isAuthed && <Link href="/login" className="text-sm font-semibold text-[var(--muted)] transition hover:text-[var(--brand)]">Sign in</Link>}
                    <Nav url={cta.url} label={cta.label} className="btn-primary rounded-full px-5 py-2.5 text-sm font-semibold" />
                </div>

                <button type="button" onClick={() => setOpen((o) => !o)} className="rounded-lg p-2 text-[var(--brand)] lg:hidden" aria-label="Menu" aria-expanded={open}>
                    <svg className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d={open ? 'M6 18L18 6M6 6l12 12' : 'M4 6h16M4 12h16M4 18h16'} /></svg>
                </button>
            </div>

            {open && (
                <div className="border-t border-[var(--line)] bg-white px-5 py-4 lg:hidden">
                    <div className="flex flex-col gap-1">
                        {nav.map((item, i) => (
                            <div key={i}>
                                {item.children ? (
                                    <>
                                        <div className="px-1 pb-1 pt-3 text-xs font-semibold uppercase tracking-wide text-[var(--subtle)]">{item.label}</div>
                                        {item.children.map((c, j) => <Nav key={j} url={c.url} label={c.label} className="block rounded-lg px-1 py-2 text-sm font-medium text-[var(--muted)]" />)}
                                    </>
                                ) : (
                                    <Nav url={item.url} label={item.label} className="block rounded-lg px-1 py-2.5 text-sm font-medium text-[var(--brand)]" />
                                )}
                            </div>
                        ))}
                        <div className="mt-3 flex flex-col gap-2 border-t border-[var(--line)] pt-4">
                            {!isAuthed && <Link href="/login" className="px-1 text-sm font-semibold text-[var(--muted)]">Sign in</Link>}
                            <Nav url={cta.url} label={cta.label} className="btn-primary rounded-full px-5 py-2.5 text-center text-sm font-semibold" />
                        </div>
                    </div>
                </div>
            )}
        </header>
    );
}
