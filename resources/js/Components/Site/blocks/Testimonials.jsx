import { SiteIcon } from '../icons';

export default function Testimonials({ data }) {
    const items = data.items || [];
    if (!items.length) return null;
    return (
        <section className="mx-auto max-w-7xl px-6 py-20 sm:py-24">
            {data.heading && <h2 className="text-center text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl">{data.heading}</h2>}
            <div className="mt-12 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                {items.map((t, i) => (
                    <figure key={i} className="flex flex-col rounded-2xl border border-zinc-200 bg-white p-7 shadow-sm">
                        <div className="flex gap-0.5" style={{ color: 'var(--a)' }}>
                            {[...Array(Math.max(0, Math.min(5, t.rating || 5)))].map((_, j) => <SiteIcon key={j} name="star" className="h-4 w-4 fill-current" />)}
                        </div>
                        <blockquote className="mt-4 flex-1 text-[15px] leading-relaxed text-zinc-700">“{t.quote}”</blockquote>
                        <figcaption className="mt-5 flex items-center gap-3">
                            {t.avatar_url
                                ? <img src={t.avatar_url} alt="" className="h-10 w-10 rounded-full object-cover" />
                                : <span className="flex h-10 w-10 items-center justify-center rounded-full bg-zinc-100 text-sm font-bold text-zinc-500">{(t.name || '?').charAt(0)}</span>}
                            <div>
                                <div className="text-sm font-semibold text-zinc-900">{t.name}</div>
                                <div className="text-xs text-zinc-500">{[t.role, t.company].filter(Boolean).join(' · ')}</div>
                            </div>
                        </figcaption>
                    </figure>
                ))}
            </div>
        </section>
    );
}
