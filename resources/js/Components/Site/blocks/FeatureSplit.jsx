import { SiteIcon } from '../icons';

// Tinted band with a light product visual — vibrant, full-width. Image side editable.
export default function FeatureSplit({ data }) {
    const right = (data.image_side || 'right') === 'right';
    const bullets = data.bullets || [];

    const Visual = (
        <div className="relative">
            <div className="absolute -inset-5 rounded-[2.2rem] opacity-30 blur-2xl" style={{ background: 'linear-gradient(135deg,var(--g-from),var(--pink))' }} />
            {data.image_url ? (
                <img src={data.image_url} alt="" className="relative w-full rounded-2xl border border-[var(--line)] shadow-2xl" />
            ) : (
                <div className="relative overflow-hidden rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5 shadow-2xl ring-1 ring-black/5">
                    <div className="mb-4 flex items-center justify-between">
                        <div className="text-sm font-bold text-[var(--brand)]">Activity &amp; screenshots</div>
                        <span className="rounded-full bg-[var(--mint)] px-2.5 py-1 text-[11px] font-semibold" style={{ color: 'var(--a)' }}>Visible to the team</span>
                    </div>
                    <div className="grid grid-cols-3 gap-3">
                        {[...Array(6)].map((_, i) => (
                            <div key={i} className="aspect-video rounded-lg" style={{ background: i % 4 === 1 ? 'linear-gradient(135deg,var(--g-from),var(--g-to))' : 'var(--paper)' }} />
                        ))}
                    </div>
                    <div className="mt-4 flex items-center gap-3 rounded-xl bg-[var(--paper)] p-3">
                        <span className="flex h-9 w-9 items-center justify-center rounded-full text-white" style={{ background: 'linear-gradient(135deg,var(--g-from),var(--g-to))' }}><SiteIcon name="lock" className="h-4 w-4" /></span>
                        <div>
                            <div className="text-xs font-semibold text-[var(--brand)]">No keylogging, ever</div>
                            <div className="text-[11px] text-[var(--subtle)]">Screenshots are opt-in and employee-visible</div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );

    return (
        <section className="wash-tint relative py-20 sm:py-28">
            <div className="mx-auto max-w-[1500px] px-6 sm:px-10">
                <div className={`grid items-center gap-14 lg:grid-cols-2 ${right ? '' : 'lg:[direction:rtl]'}`}>
                    <div className="lg:[direction:ltr]">
                        {data.eyebrow && <span className="inline-flex rounded-full bg-[var(--surface)] px-3 py-1 text-xs font-bold uppercase tracking-wider shadow-sm ring-1 ring-[var(--line)]" style={{ color: 'var(--a)' }}>{data.eyebrow}</span>}
                        {data.heading && <h2 className="mt-4 text-3xl font-extrabold tracking-tight text-[var(--brand)] sm:text-[2.4rem]">{data.heading}</h2>}
                        {data.text && <p className="mt-4 text-lg leading-relaxed text-[var(--muted)]">{data.text}</p>}
                        {bullets.length > 0 && (
                            <ul className="mt-7 grid gap-3 sm:grid-cols-2">
                                {bullets.map((b, i) => (
                                    <li key={i} className="flex items-start gap-2.5">
                                        <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-white" style={{ background: 'linear-gradient(135deg,var(--g-from),var(--g-to))' }}><SiteIcon name="check" className="h-3 w-3" /></span>
                                        <span className="text-[15px] font-medium text-[var(--brand)]">{b}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                    <div className="lg:[direction:ltr]">{Visual}</div>
                </div>
            </div>
        </section>
    );
}
