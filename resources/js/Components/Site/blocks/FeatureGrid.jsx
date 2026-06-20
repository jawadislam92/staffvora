import { SiteIcon } from '../icons';

export default function FeatureGrid({ data }) {
    const features = data.features || [];
    return (
        <section id={data.anchor || undefined} className="mx-auto max-w-7xl scroll-mt-24 px-6 py-20 sm:py-28">
            {data.heading && <h2 className="text-center text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl">{data.heading}</h2>}
            {data.subhead && <p className="mx-auto mt-3 max-w-2xl text-center text-lg text-zinc-600">{data.subhead}</p>}
            <div className="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                {features.map((f, i) => (
                    <div key={i} className="group rounded-2xl border border-zinc-200 bg-white p-7 shadow-sm transition hover:-translate-y-1 hover:shadow-lg">
                        <div className="inline-flex rounded-xl p-3 text-white shadow-sm" style={{ background: 'var(--a)' }}>
                            <SiteIcon name={f.icon} className="h-6 w-6" />
                        </div>
                        <h3 className="mt-5 text-lg font-semibold text-zinc-900">{f.title}</h3>
                        <p className="mt-2 text-sm leading-relaxed text-zinc-600">{f.text}</p>
                    </div>
                ))}
            </div>
        </section>
    );
}
