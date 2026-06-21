import { useState } from 'react';

function Item({ q, a }) {
    const [open, setOpen] = useState(false);
    return (
        <div className="px-6">
            <button type="button" onClick={() => setOpen((o) => !o)} className="flex w-full items-center justify-between gap-4 py-5 text-left">
                <span className="text-base font-bold text-[var(--brand)]">{q}</span>
                <span className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full transition ${open ? 'text-white' : 'text-[var(--a)]'}`} style={open ? { background: 'linear-gradient(135deg,var(--g-from),var(--g-to))' } : { background: 'color-mix(in srgb, var(--a) 10%, white)' }}>
                    <svg className={`h-4 w-4 transition-transform ${open ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6" /></svg>
                </span>
            </button>
            <div className={`grid transition-all duration-200 ${open ? 'grid-rows-[1fr] pb-5 opacity-100' : 'grid-rows-[0fr] opacity-0'}`}>
                <div className="overflow-hidden"><p className="pr-10 text-[15px] leading-relaxed text-[var(--muted)]">{a}</p></div>
            </div>
        </div>
    );
}

export default function Faq({ data }) {
    const items = data.items || [];
    if (!items.length) return null;
    return (
        <section className="py-20 sm:py-28">
            <div className="mx-auto max-w-3xl px-6">
                {data.heading && <h2 className="text-center text-3xl font-extrabold tracking-tight text-[var(--brand)] sm:text-[2.6rem]">{data.heading}</h2>}
                <div className="mt-12 divide-y divide-[var(--line)] overflow-hidden rounded-2xl border border-[var(--line)] bg-white shadow-sm">
                    {items.map((it, i) => <Item key={i} q={it.q} a={it.a} />)}
                </div>
            </div>
        </section>
    );
}
