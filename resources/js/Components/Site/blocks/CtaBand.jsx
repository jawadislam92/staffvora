import { Link } from '@inertiajs/react';

const isApp = (u) => ['/login', '/register', '/dashboard'].includes(u || '');

// Full-bleed gradient CTA band (edge-to-edge, like Hubstaff's final CTA).
export default function CtaBand({ data }) {
    const cta = data.primary_cta;
    return (
        <section id={data.anchor || undefined} className="band-cta relative scroll-mt-24 overflow-hidden py-20 sm:py-24">
            <div className="pointer-events-none absolute inset-0 opacity-25" style={{ background: 'radial-gradient(circle at 80% 10%, white, transparent 45%)' }} />
            <div className="relative mx-auto max-w-3xl px-6 text-center">
                {data.heading && <h2 className="text-3xl font-extrabold tracking-tight sm:text-[2.7rem]">{data.heading}</h2>}
                {data.text && <p className="mx-auto mt-4 max-w-xl text-lg text-white/90">{data.text}</p>}
                {cta?.label && (
                    isApp(cta.url)
                        ? <Link href={cta.url} className="mt-9 inline-flex rounded-full bg-[var(--surface)] px-8 py-4 text-base font-semibold text-[var(--brand)] shadow-lg transition hover:-translate-y-0.5 hover:shadow-xl">{cta.label}</Link>
                        : <a href={cta.url} className="mt-9 inline-flex rounded-full bg-[var(--surface)] px-8 py-4 text-base font-semibold text-[var(--brand)] shadow-lg transition hover:-translate-y-0.5 hover:shadow-xl">{cta.label}</a>
                )}
            </div>
        </section>
    );
}
