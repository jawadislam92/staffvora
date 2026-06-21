import { SiteIcon } from '../icons';

export default function Testimonials({ data }) {
    const items = data.items || [];
    if (!items.length) return null;
    return (
        <section className="wash-tint py-20 sm:py-28">
            <div className="mx-auto max-w-[1300px] px-6 sm:px-10">
                {data.heading && <h2 className="text-center text-3xl font-extrabold tracking-tight text-[var(--brand)] sm:text-[2.6rem]">{data.heading}</h2>}
                <div className="mx-auto mt-14 grid max-w-5xl gap-6 md:grid-cols-2 lg:grid-cols-3">
                    {items.map((t, i) => (
                        <figure key={i} className="flex flex-col rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-7 shadow-sm">
                            <div className="flex gap-0.5" style={{ color: 'var(--a)' }}>
                                {[...Array(Math.max(0, Math.min(5, t.rating || 5)))].map((_, j) => <SiteIcon key={j} name="star" className="h-4 w-4 fill-current" />)}
                            </div>
                            <blockquote className="mt-4 flex-1 text-[15px] leading-relaxed text-[var(--brand)]">“{t.quote}”</blockquote>
                            <figcaption className="mt-5 flex items-center gap-3">
                                {t.avatar_url
                                    ? <img src={t.avatar_url} alt="" className="h-10 w-10 rounded-full object-cover" />
                                    : <span className="flex h-10 w-10 items-center justify-center rounded-full text-sm font-bold text-white" style={{ background: 'linear-gradient(135deg,var(--g-from),var(--g-to))' }}>{(t.name || '?').charAt(0)}</span>}
                                <div>
                                    <div className="text-sm font-bold text-[var(--brand)]">{t.name}</div>
                                    <div className="text-xs text-[var(--subtle)]">{[t.role, t.company].filter(Boolean).join(' · ')}</div>
                                </div>
                            </figcaption>
                        </figure>
                    ))}
                </div>
            </div>
        </section>
    );
}
