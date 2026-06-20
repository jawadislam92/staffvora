import { Link } from '@inertiajs/react';

const isApp = (u) => ['/login', '/register', '/dashboard'].includes(u || '');
function Cta({ cta, variant }) {
    if (!cta?.label) return null;
    const base = 'inline-flex items-center justify-center rounded-full px-7 py-3.5 text-base font-semibold transition';
    const cls = variant === 'primary'
        ? `${base} text-white shadow-lg hover:opacity-90`
        : `${base} bg-white text-zinc-900 ring-1 ring-zinc-300 hover:bg-zinc-50`;
    const style = variant === 'primary' ? { background: 'var(--a)' } : undefined;
    return isApp(cta.url)
        ? <Link href={cta.url} className={cls} style={style}>{cta.label}</Link>
        : <a href={cta.url} className={cls} style={style}>{cta.label}{variant === 'primary' ? ' →' : ''}</a>;
}

// Dark product mock — light site + dark screenshot is the category-standard look.
function DeviceMock() {
    return (
        <div className="relative mx-auto mt-16 max-w-4xl">
            <div className="absolute -inset-4 rounded-[2rem] opacity-20 blur-2xl" style={{ background: 'var(--a)' }} />
            <div className="relative overflow-hidden rounded-2xl border border-zinc-800 bg-[#101213] shadow-2xl ring-1 ring-black/5">
                <div className="flex items-center gap-1.5 border-b border-white/10 px-4 py-3">
                    <span className="h-3 w-3 rounded-full bg-rose-400/80" />
                    <span className="h-3 w-3 rounded-full bg-yellow-400/80" />
                    <span className="h-3 w-3 rounded-full bg-emerald-400/80" />
                    <span className="ml-3 text-xs text-zinc-500">app.tracksuite.io/dashboard</span>
                </div>
                <div className="grid gap-4 p-6 sm:grid-cols-3">
                    {[
                        { label: 'Worked today', value: '6h 42m' },
                        { label: 'Team active now', value: '14' },
                        { label: 'This week', value: '31h 05m' },
                    ].map((c) => (
                        <div key={c.label} className="rounded-xl border border-white/10 bg-white/[0.03] p-4">
                            <div className="text-[11px] uppercase tracking-wide text-zinc-500">{c.label}</div>
                            <div className="mt-1 text-2xl font-bold" style={{ color: 'var(--a)' }}>{c.value}</div>
                        </div>
                    ))}
                    <div className="sm:col-span-3 rounded-xl border border-white/10 bg-white/[0.03] p-4">
                        <div className="mb-3 flex items-center justify-between text-xs text-zinc-500">
                            <span className="uppercase tracking-wide">This week</span><span>Mon – Fri</span>
                        </div>
                        <div className="flex items-end gap-3" style={{ height: 90 }}>
                            {[55, 80, 65, 95, 45].map((h, i) => (
                                <div key={i} className="flex-1 rounded-t-md" style={{ height: `${h}%`, background: 'var(--a)', opacity: 0.85 }} />
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

export default function Hero({ data }) {
    return (
        <section className="relative overflow-hidden">
            <div className="pointer-events-none absolute inset-x-0 -top-40 -z-0 mx-auto h-80 max-w-5xl rounded-full opacity-10 blur-3xl" style={{ background: 'var(--a)' }} />
            <div className="relative mx-auto max-w-7xl px-6 pb-8 pt-16 text-center sm:pt-24">
                {data.eyebrow && (
                    <span className="inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-sm font-medium ring-1" style={{ color: 'var(--a)', borderColor: 'var(--a)', background: 'color-mix(in srgb, var(--a) 8%, white)', '--tw-ring-color': 'color-mix(in srgb, var(--a) 25%, white)' }}>
                        <span className="h-2 w-2 animate-pulse rounded-full bg-emerald-500" />
                        {data.eyebrow}
                    </span>
                )}
                <h1 className="mx-auto mt-6 max-w-4xl text-4xl font-extrabold leading-[1.05] tracking-tight text-zinc-900 sm:text-6xl">
                    {data.heading}
                    {data.heading_highlight && <span className="block" style={{ color: 'var(--a)' }}>{data.heading_highlight}</span>}
                </h1>
                {data.subhead && <p className="mx-auto mt-6 max-w-2xl text-lg text-zinc-600">{data.subhead}</p>}
                <div className="mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <Cta cta={data.primary_cta} variant="primary" />
                    <Cta cta={data.secondary_cta} variant="secondary" />
                </div>
                {data.note && <p className="mt-4 text-sm text-zinc-500">{data.note}</p>}
                {data.image_url ? (
                    <div className="mx-auto mt-16 max-w-5xl overflow-hidden rounded-2xl border border-zinc-200 shadow-2xl">
                        <img src={data.image_url} alt="" className="w-full" />
                    </div>
                ) : (
                    <DeviceMock />
                )}
            </div>
        </section>
    );
}
