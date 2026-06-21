import { Link } from '@inertiajs/react';

const isApp = (u) => ['/login', '/register', '/dashboard'].includes(u || '');

export default function CtaBand({ data }) {
    const cta = data.primary_cta;
    return (
        <section id={data.anchor || undefined} className="mx-auto max-w-7xl scroll-mt-24 px-6 py-16">
            <div className="band-cta relative overflow-hidden rounded-3xl px-8 py-16 text-center shadow-xl">
                <div className="pointer-events-none absolute inset-0 opacity-25" style={{ background: 'radial-gradient(circle at 78% 12%, white, transparent 45%)' }} />
                <div className="relative">
                    {data.heading && <h2 className="text-3xl font-extrabold tracking-tight sm:text-4xl">{data.heading}</h2>}
                    {data.text && <p className="mx-auto mt-3 max-w-xl text-white/90">{data.text}</p>}
                    {cta?.label && (
                        isApp(cta.url)
                            ? <Link href={cta.url} className="mt-8 inline-flex rounded-full bg-white px-8 py-3.5 text-base font-semibold text-[var(--brand)] shadow-lg transition hover:-translate-y-0.5 hover:shadow-xl">{cta.label}</Link>
                            : <a href={cta.url} className="mt-8 inline-flex rounded-full bg-white px-8 py-3.5 text-base font-semibold text-[var(--brand)] shadow-lg transition hover:-translate-y-0.5 hover:shadow-xl">{cta.label}</a>
                    )}
                </div>
            </div>
        </section>
    );
}
