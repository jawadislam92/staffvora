import { useState } from 'react';

function Item({ q, a }) {
    const [open, setOpen] = useState(false);
    return (
        <div className="border-b border-zinc-200 py-5">
            <button type="button" onClick={() => setOpen((o) => !o)} className="flex w-full items-center justify-between gap-4 text-left">
                <span className="text-base font-semibold text-zinc-900">{q}</span>
                <svg className={`h-5 w-5 shrink-0 text-zinc-400 transition-transform ${open ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6" /></svg>
            </button>
            <div className={`grid transition-all duration-200 ${open ? 'mt-3 grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'}`}>
                <div className="overflow-hidden"><p className="text-[15px] leading-relaxed text-zinc-600">{a}</p></div>
            </div>
        </div>
    );
}

export default function Faq({ data }) {
    const items = data.items || [];
    return (
        <section className="mx-auto max-w-3xl px-6 py-20">
            {data.heading && <h2 className="text-center text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl">{data.heading}</h2>}
            <div className="mt-10">
                {items.map((it, i) => <Item key={i} q={it.q} a={it.a} />)}
            </div>
        </section>
    );
}
