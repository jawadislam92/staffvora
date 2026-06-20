import { SiteIcon } from '../icons';

// Light text panel + a dark product visual; image side is editable.
export default function FeatureSplit({ data }) {
    const right = (data.image_side || 'right') === 'right';
    const bullets = data.bullets || [];

    const Visual = (
        <div className="relative">
            <div className="absolute -inset-3 rounded-3xl opacity-10 blur-2xl" style={{ background: 'var(--a)' }} />
            {data.image_url ? (
                <img src={data.image_url} alt="" className="relative w-full rounded-2xl border border-zinc-200 shadow-xl" />
            ) : (
                <div className="relative aspect-[4/3] overflow-hidden rounded-2xl border border-zinc-800 bg-[#101213] p-6 shadow-xl">
                    <div className="grid h-full grid-cols-3 gap-3">
                        {[...Array(9)].map((_, i) => (
                            <div key={i} className="rounded-lg border border-white/10 bg-white/[0.03]" style={i % 4 === 0 ? { background: 'color-mix(in srgb, var(--a) 30%, #101213)' } : undefined} />
                        ))}
                    </div>
                </div>
            )}
        </div>
    );

    return (
        <section className="mx-auto max-w-7xl px-6 py-20 sm:py-24">
            <div className={`grid items-center gap-12 lg:grid-cols-2 ${right ? '' : 'lg:[direction:rtl]'}`}>
                <div className="lg:[direction:ltr]">
                    {data.eyebrow && <span className="text-sm font-semibold uppercase tracking-wider" style={{ color: 'var(--a)' }}>{data.eyebrow}</span>}
                    {data.heading && <h2 className="mt-3 text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl">{data.heading}</h2>}
                    {data.text && <p className="mt-4 text-lg text-zinc-600">{data.text}</p>}
                    {bullets.length > 0 && (
                        <ul className="mt-6 space-y-3">
                            {bullets.map((b, i) => (
                                <li key={i} className="flex items-start gap-3 text-zinc-700">
                                    <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-white" style={{ background: 'var(--a)' }}>
                                        <SiteIcon name="check" className="h-3.5 w-3.5" />
                                    </span>
                                    <span className="text-[15px]">{b}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
                <div className="lg:[direction:ltr]">{Visual}</div>
            </div>
        </section>
    );
}
