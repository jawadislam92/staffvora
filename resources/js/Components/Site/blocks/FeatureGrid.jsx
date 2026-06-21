import { SiteIcon } from '../icons';

export default function FeatureGrid({ data }) {
    const features = data.features || [];
    return (
        <section id={data.anchor || undefined} className="scroll-mt-24 py-20 sm:py-28">
            <div className="mx-auto max-w-[1500px] px-6 sm:px-10">
                {data.heading && <h2 className="mx-auto max-w-3xl text-center text-3xl font-extrabold tracking-tight text-[var(--brand)] sm:text-[2.6rem]">{data.heading}</h2>}
                {data.subhead && <p className="mx-auto mt-4 max-w-2xl text-center text-lg text-[var(--muted)]">{data.subhead}</p>}
                <div className="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    {features.map((f, i) => (
                        <div key={i} className="group rounded-2xl border border-[var(--line)] bg-white p-7 transition hover:-translate-y-1" style={{ boxShadow: '0 1px 2px rgba(30,27,75,.04)' }}>
                            <div className="inline-flex rounded-xl p-3 text-white" style={{ background: 'linear-gradient(135deg,var(--g-from),var(--g-to))', boxShadow: '0 10px 22px -8px color-mix(in srgb, var(--a) 60%, transparent)' }}>
                                <SiteIcon name={f.icon} className="h-6 w-6" />
                            </div>
                            <h3 className="mt-5 text-lg font-bold text-[var(--brand)]">{f.title}</h3>
                            <p className="mt-2 text-sm leading-relaxed text-[var(--muted)]">{f.text}</p>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}
