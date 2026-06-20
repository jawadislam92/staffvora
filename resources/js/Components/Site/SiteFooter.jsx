import { Link } from '@inertiajs/react';

const isAppRoute = (url) => ['/login', '/register', '/dashboard'].includes(url);
const FooterLink = ({ url, label }) =>
    isAppRoute(url)
        ? <Link href={url} className="text-sm text-zinc-500 transition hover:text-zinc-900">{label}</Link>
        : <a href={url} className="text-sm text-zinc-500 transition hover:text-zinc-900">{label}</a>;

const SOCIALS = [
    { key: 'linkedin', label: 'LinkedIn', d: 'M4.98 3.5a2.5 2.5 0 11-.02 5 2.5 2.5 0 01.02-5zM3 9h4v12H3zM9 9h3.8v1.7h.05c.53-1 1.83-2.05 3.77-2.05 4.03 0 4.78 2.65 4.78 6.1V21h-4v-5.3c0-1.27-.02-2.9-1.77-2.9-1.77 0-2.04 1.38-2.04 2.8V21H9z' },
    { key: 'facebook', label: 'Facebook', d: 'M22 12a10 10 0 10-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.78-3.89 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.44 2.89h-2.34v6.99A10 10 0 0022 12z' },
    { key: 'instagram', label: 'Instagram', d: 'M12 2.2c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.7 3.7 0 01-1.38-.9 3.7 3.7 0 01-.9-1.38c-.16-.42-.36-1.06-.41-2.23C2.21 15.58 2.2 15.2 2.2 12s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41C8.42 2.21 8.8 2.2 12 2.2zm0 1.8c-3.15 0-3.5.01-4.74.07-.9.04-1.38.19-1.71.32-.43.17-.74.37-1.06.69-.32.32-.52.63-.69 1.06-.13.33-.28.81-.32 1.71C3.21 8.5 3.2 8.85 3.2 12s.01 3.5.07 4.74c.04.9.19 1.38.32 1.71.17.43.37.74.69 1.06.32.32.63.52 1.06.69.33.13.81.28 1.71.32 1.24.06 1.59.07 4.74.07s3.5-.01 4.74-.07c.9-.04 1.38-.19 1.71-.32.43-.17.74-.37 1.06-.69.32-.32.52-.63.69-1.06.13-.33.28-.81.32-1.71.06-1.24.07-1.59.07-4.74s-.01-3.5-.07-4.74c-.04-.9-.19-1.38-.32-1.71a2.86 2.86 0 00-.69-1.06 2.86 2.86 0 00-1.06-.69c-.33-.13-.81-.28-1.71-.32C15.5 4.01 15.15 4 12 4zm0 3.06A4.94 4.94 0 1116.94 12 4.94 4.94 0 0112 7.06zm0 1.8A3.14 3.14 0 1015.14 12 3.14 3.14 0 0012 8.86zm5.13-2.96a1.15 1.15 0 11-1.15-1.15 1.15 1.15 0 011.15 1.15z' },
    { key: 'x', label: 'X', d: 'M18.9 2H22l-6.9 7.9L22.9 22h-6.6l-5.2-6.8L5.2 22H2l7.4-8.5L1.7 2h6.8l4.7 6.2zm-1.2 18h1.8L7.2 3.8H5.3z' },
    { key: 'youtube', label: 'YouTube', d: 'M23 12s0-3.2-.4-4.7a2.5 2.5 0 00-1.76-1.76C19.34 5.1 12 5.1 12 5.1s-7.34 0-8.84.43A2.5 2.5 0 001.4 7.3C1 8.8 1 12 1 12s0 3.2.4 4.7a2.5 2.5 0 001.76 1.76c1.5.44 8.84.44 8.84.44s7.34 0 8.84-.44a2.5 2.5 0 001.76-1.76C23 15.2 23 12 23 12zM9.75 15.5v-7l6 3.5z' },
];

export default function SiteFooter({ theme }) {
    const columns = theme?.footer_columns || [];
    const social = theme?.social_links || {};

    return (
        <footer className="border-t border-zinc-200 bg-zinc-50">
            <div className="mx-auto max-w-7xl px-6 py-14">
                <div className="grid gap-10 md:grid-cols-[1.4fr_repeat(3,1fr)]">
                    <div>
                        <a href="/" className="flex items-center gap-2.5">
                            {theme?.logo_url ? (
                                <img src={theme.logo_url} alt="" className="h-8 w-8 object-contain" />
                            ) : (
                                <span className="flex h-8 w-8 items-center justify-center rounded-lg text-sm font-black text-white" style={{ background: 'var(--a)' }}>
                                    {(theme?.site_name || 'T').charAt(0)}
                                </span>
                            )}
                            <span className="text-lg font-bold text-zinc-900">{theme?.site_name || 'Tracksuite'}</span>
                        </a>
                        {theme?.tagline && <p className="mt-3 max-w-xs text-sm text-zinc-500">{theme.tagline}</p>}
                        <div className="mt-5 flex gap-3">
                            {SOCIALS.filter((s) => social[s.key]).map((s) => (
                                <a key={s.key} href={social[s.key]} target="_blank" rel="noreferrer" aria-label={s.label}
                                    className="flex h-9 w-9 items-center justify-center rounded-full bg-white text-zinc-500 ring-1 ring-zinc-200 transition hover:text-zinc-900">
                                    <svg className="h-[18px] w-[18px]" fill="currentColor" viewBox="0 0 24 24"><path d={s.d} /></svg>
                                </a>
                            ))}
                        </div>
                    </div>

                    {columns.map((col, i) => (
                        <div key={i}>
                            <h4 className="text-sm font-semibold text-zinc-900">{col.title}</h4>
                            <ul className="mt-3 space-y-2.5">
                                {(col.links || []).map((l, j) => <li key={j}><FooterLink url={l.url} label={l.label} /></li>)}
                            </ul>
                        </div>
                    ))}
                </div>

                <div className="mt-12 flex flex-col items-center justify-between gap-3 border-t border-zinc-200 pt-6 text-sm text-zinc-500 sm:flex-row">
                    <span>© {new Date().getFullYear()} {theme?.site_name || 'Tracksuite'}. All rights reserved.</span>
                    {theme?.contact_email && (
                        <a href={`mailto:${theme.contact_email}`} className="font-medium transition hover:text-zinc-900" style={{ color: 'var(--a)' }}>
                            {theme.contact_email}
                        </a>
                    )}
                </div>
            </div>
        </footer>
    );
}
