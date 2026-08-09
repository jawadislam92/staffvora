import { useEffect, useId, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import SiteLogo from './SiteLogo';
import ThemeToggle from './ThemeToggle';

const isAppRoute = (url) => ['/login', '/register', '/dashboard'].includes(url);

// LAST LINE OF DEFENCE for the site chrome, which renders on EVERY public page.
//
// The nav/footer/social URLs come from the builder-editable `site_themes` row.
// The server cleans them on write AND on read (App\Models\SiteTheme +
// App\Support\HtmlSanitizer), but React does NOT block a `javascript:` href —
// the production react-dom bundle carries no URL sanitiser at all — so the one
// component that actually emits the attribute checks it too. That way a new
// caller, a cached pre-fix payload, or a nav shape nobody wrote a rule for
// cannot turn into script execution on staffvora.com, the origin the customer
// app's session cookie belongs to.
//
// Same rule as HtmlSanitizer::safeUrl: entities are DECODED and control
// characters removed before the scheme test, so `java&#09;script:`,
// `javascript&colon;alert(1)` and `  JaVaScRiPt:` are all caught, and
// `//evil.test` is protocol-relative rather than site-relative and is rejected.
//
// DECODE, don't delete. Strip `&colon;` out of `javascript&colon;alert(1)` and
// what is left has no colon at all, so it reads as an innocuous relative path —
// while a browser resolving that entity sees the scheme. The probe has to see
// what the browser will see.
// Exported so SiteFooter enforces the identical rule instead of a weaker one.
const NAMED_ENTITIES = { colon: ':', tab: '\t', newline: '\n', sol: '/', amp: '&', lpar: '(', rpar: ')', semi: ';', quot: '"', apos: "'", lt: '<', gt: '>', nbsp: ' ' };

function decodeEntities(value) {
    return value
        // Numeric references, with or without the trailing semicolon — browsers
        // resolve both, so the probe must too.
        .replace(/&#x([0-9a-f]+);?/gi, (_, hex) => codePoint(parseInt(hex, 16)))
        .replace(/&#([0-9]+);?/g, (_, dec) => codePoint(parseInt(dec, 10)))
        .replace(/&([a-z]+);/gi, (whole, name) => NAMED_ENTITIES[name.toLowerCase()] ?? whole);
}

function codePoint(n) {
    return Number.isFinite(n) && n > 0 && n <= 0x10ffff ? String.fromCodePoint(n) : '';
}

export function safeHref(value) {
    if (typeof value !== 'string') return undefined;
    const probe = decodeEntities(value.toLowerCase()).replace(/[\x00-\x20\x7f]/g, '');
    if (probe === '') return undefined;
    if (probe.startsWith('//') || probe.startsWith('/\\')) return undefined;
    if (probe.startsWith('#') || probe.startsWith('/')) return value;
    const scheme = probe.match(/^([a-z][a-z0-9+.-]*):/);
    if (scheme) return ['http', 'https', 'mailto', 'tel'].includes(scheme[1]) ? value : undefined;
    return value; // relative path such as `pricing`
}

function Nav({ url, label, className }) {
    const href = safeHref(url);
    // Inertia's <Link> pushes the value straight into history/router, so it only
    // ever gets a URL that already passed the check.
    return href !== undefined && isAppRoute(href)
        ? <Link href={href} className={className}>{label}</Link>
        : <a href={href} className={className}>{label}</a>;
}

// A top-level nav entry: a plain link, or a dropdown when it has children.
function HeaderItem({ item }) {
    if (!item.children) {
        return <Nav url={item.url} label={item.label} className="text-sm font-medium text-[var(--muted)] transition hover:text-[var(--brand)]" />;
    }
    return <HeaderDropdown item={item} />;
}

// A dropdown parent. The panel opens on hover (mouse), on focus-within (keyboard
// Tab), AND on an explicit click/tap of the caret — the last is what makes it
// reachable on a touchscreen ≥1024px, where there is no hover and the hamburger
// is hidden. Escape and an outside click close it. When the parent also carries
// its own `url` (e.g. Solutions → /solutions) the label is a real link and only
// the caret toggles the panel, so the overview page is reachable too.
const CARET = <path d="M6 9l6 6 6-6" />;

function HeaderDropdown({ item }) {
    const [open, setOpen] = useState(false);
    const wrapRef = useRef(null);
    const menuId = useId();
    const hasUrl = item.url && safeHref(item.url) !== undefined;

    useEffect(() => {
        if (!open) return undefined;
        const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
        const onDocClick = (e) => { if (wrapRef.current && !wrapRef.current.contains(e.target)) setOpen(false); };
        document.addEventListener('keydown', onKey);
        document.addEventListener('mousedown', onDocClick);
        return () => {
            document.removeEventListener('keydown', onKey);
            document.removeEventListener('mousedown', onDocClick);
        };
    }, [open]);

    const caretBtnCls = 'inline-flex items-center rounded p-0.5 text-[var(--muted)] transition hover:text-[var(--brand)]';
    const caretSvg = <svg className={`h-4 w-4 opacity-60 transition group-hover:rotate-180 ${open ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">{CARET}</svg>;

    return (
        <div ref={wrapRef} className="group relative" onMouseLeave={() => setOpen(false)}>
            {hasUrl ? (
                <span className="inline-flex items-center gap-1">
                    <Nav url={item.url} label={item.label} className="text-sm font-medium text-[var(--muted)] transition hover:text-[var(--brand)]" />
                    <button type="button" onClick={() => setOpen((o) => !o)} aria-haspopup="true" aria-expanded={open} aria-controls={menuId} aria-label={`${item.label} menu`} className={caretBtnCls}>
                        {caretSvg}
                    </button>
                </span>
            ) : (
                <button type="button" onClick={() => setOpen((o) => !o)} aria-haspopup="true" aria-expanded={open} aria-controls={menuId} className="inline-flex items-center gap-1 text-sm font-medium text-[var(--muted)] transition hover:text-[var(--brand)]">
                    {item.label}
                    {caretSvg}
                </button>
            )}
            <div id={menuId} className={`absolute left-1/2 top-full z-50 -translate-x-1/2 pt-3 transition group-hover:visible group-hover:opacity-100 group-focus-within:visible group-focus-within:opacity-100 ${open ? 'visible opacity-100' : 'invisible opacity-0'}`}>
                <div className="min-w-[230px] rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-2 shadow-xl shadow-black/[0.04]">
                    {item.children.map((c, i) => (
                        <Nav key={i} url={c.url} label={c.label} className="block rounded-xl px-3 py-2 text-sm font-medium text-[var(--muted)] transition hover:bg-[var(--paper)] hover:text-[var(--brand)]" />
                    ))}
                </div>
            </div>
        </div>
    );
}

export default function SiteHeader({ nav = [], brand, isAuthed, mode = 'light', onToggleTheme }) {
    const [open, setOpen] = useState(false);
    const name = brand?.name || 'Staffvora';
    const cta = isAuthed ? { url: '/dashboard', label: 'Go to dashboard' } : { url: '/register', label: 'Start free trial' };

    return (
        <header className="site-header sticky top-0 z-40 border-b border-[var(--line)] backdrop-blur-xl">
            <div className="mx-auto flex max-w-[1600px] items-center justify-between gap-6 px-5 py-3.5 sm:px-10">
                <div className="flex items-center gap-9">
                    <a href="/" aria-label={name}><SiteLogo name={name} size={28} textClass="text-lg" /></a>
                    <nav className="hidden items-center gap-7 lg:flex">
                        {nav.map((item, i) => <HeaderItem key={i} item={item} />)}
                    </nav>
                </div>

                <div className="hidden items-center gap-4 lg:flex">
                    <ThemeToggle mode={mode} onToggle={onToggleTheme} />
                    {!isAuthed && <Link href="/login" className="text-sm font-semibold text-[var(--muted)] transition hover:text-[var(--brand)]">Sign in</Link>}
                    <Nav url={cta.url} label={cta.label} className="btn-primary rounded-full px-5 py-2.5 text-sm font-semibold" />
                </div>

                <div className="flex items-center gap-2 lg:hidden">
                    <ThemeToggle mode={mode} onToggle={onToggleTheme} />
                    <button type="button" onClick={() => setOpen((o) => !o)} className="rounded-lg p-2 text-[var(--brand)]" aria-label="Menu" aria-expanded={open}>
                    <svg className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d={open ? 'M6 18L18 6M6 6l12 12' : 'M4 6h16M4 12h16M4 18h16'} /></svg>
                    </button>
                </div>
            </div>

            {open && (
                <div className="border-t border-[var(--line)] bg-[var(--surface)] px-5 py-4 lg:hidden">
                    <div className="flex flex-col gap-1">
                        {nav.map((item, i) => (
                            <div key={i}>
                                {item.children ? (
                                    <>
                                        {item.url
                                            ? <Nav url={item.url} label={item.label} className="block px-1 pb-1 pt-3 text-xs font-semibold uppercase tracking-wide text-[var(--subtle)] transition hover:text-[var(--brand)]" />
                                            : <div className="px-1 pb-1 pt-3 text-xs font-semibold uppercase tracking-wide text-[var(--subtle)]">{item.label}</div>}
                                        {item.children.map((c, j) => <Nav key={j} url={c.url} label={c.label} className="block rounded-lg px-1 py-2 text-sm font-medium text-[var(--muted)]" />)}
                                    </>
                                ) : (
                                    <Nav url={item.url} label={item.label} className="block rounded-lg px-1 py-2.5 text-sm font-medium text-[var(--brand)]" />
                                )}
                            </div>
                        ))}
                        <div className="mt-3 flex flex-col gap-2 border-t border-[var(--line)] pt-4">
                            {!isAuthed && <Link href="/login" className="px-1 py-2.5 text-sm font-semibold text-[var(--muted)]">Sign in</Link>}
                            <Nav url={cta.url} label={cta.label} className="btn-primary rounded-full px-5 py-2.5 text-center text-sm font-semibold" />
                        </div>
                    </div>
                </div>
            )}
        </header>
    );
}
