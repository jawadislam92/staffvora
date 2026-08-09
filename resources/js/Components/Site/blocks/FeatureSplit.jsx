import { SiteIcon } from '../icons';

// Tinted band with an editable product visual. When a block supplies no image
// (which is every block today) it degrades to a full-width text panel instead of
// falling back to a single hardcoded "Activity & screenshots" card — that mock
// used to render on every split block, unrelated to the copy beside it, and twice
// on some pages. A real per-block image, when one is set, still gets the split.
export default function FeatureSplit({ data }) {
    const right = (data.image_side || 'right') === 'right';
    const bullets = data.bullets || [];
    const hasImage = !!data.image_url;

    const Content = (
        <div className={hasImage ? 'lg:[direction:ltr]' : ''}>
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
    );

    return (
        <section className="wash-tint relative py-20 sm:py-28">
            <div className="mx-auto max-w-[1500px] px-6 sm:px-10">
                {hasImage ? (
                    <div className={`grid items-center gap-14 lg:grid-cols-2 ${right ? '' : 'lg:[direction:rtl]'}`}>
                        {Content}
                        <div className="lg:[direction:ltr]">
                            <div className="relative">
                                <div className="absolute -inset-5 rounded-[2.2rem] opacity-30 blur-2xl" style={{ background: 'linear-gradient(135deg,var(--g-from),var(--pink))' }} />
                                <img src={data.image_url} alt={data.alt || ''} className="relative w-full rounded-2xl border border-[var(--line)] shadow-2xl ring-1 ring-[var(--line)]" />
                            </div>
                        </div>
                    </div>
                ) : (
                    <div className="mx-auto max-w-3xl">{Content}</div>
                )}
            </div>
        </section>
    );
}
