import { Link } from '@inertiajs/react';
import HeroParticles from '../HeroParticles';

const isApp = (u) => ['/login', '/register', '/dashboard'].includes(u || '');
function Cta({ cta, variant }) {
    if (!cta?.label) return null;
    const base = 'inline-flex items-center justify-center rounded-full px-7 py-3.5 text-base font-semibold transition';
    const cls = variant === 'primary' ? `${base} btn-primary` : `${base} btn-ghost`;
    return isApp(cta.url)
        ? <Link href={cta.url} className={cls}>{cta.label}</Link>
        : <a href={cta.url} className={cls}>{cta.label}{variant === 'primary' ? ' →' : ''}</a>;
}

// A vibrant product collage — a main dashboard card with floating accent cards
// on coloured blobs (the lively, "alive" hero look). Pure CSS, no images needed.
function HeroCollage() {
    return (
        <div className="relative mx-auto aspect-[5/4] w-full max-w-2xl lg:ml-auto lg:mr-0">
            {/* colour blobs */}
            <div className="absolute -right-10 -top-10 h-72 w-72 rounded-full blur-3xl" style={{ background: 'radial-gradient(circle, var(--g-from), transparent 70%)', opacity: 0.6 }} />
            <div className="absolute -bottom-8 left-0 h-56 w-56 rounded-full blur-3xl" style={{ background: 'radial-gradient(circle, var(--pink), transparent 70%)', opacity: 0.75 }} />

            {/* main dashboard card */}
            <div className="absolute left-1/2 top-1/2 w-[88%] -translate-x-1/2 -translate-y-1/2 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5 shadow-2xl ring-1 ring-black/5 backdrop-blur">
                <div className="mb-4 flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <span className="h-7 w-7 rounded-lg" style={{ background: 'linear-gradient(135deg,var(--g-from),var(--g-to))' }} />
                        <span className="text-sm font-bold text-[var(--brand)]">Today</span>
                    </div>
                    <span className="rounded-full px-2.5 py-1 text-[11px] font-semibold" style={{ background: 'color-mix(in srgb, var(--a) 14%, var(--surface))', color: 'var(--a)' }}>On track</span>
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <div className="rounded-xl bg-[var(--paper)] p-3">
                        <div className="text-[11px] font-medium text-[var(--subtle)]">Worked today</div>
                        <div className="mt-1 text-2xl font-extrabold" style={{ color: 'var(--a)' }}>6h 42m</div>
                    </div>
                    <div className="rounded-xl bg-[var(--paper)] p-3">
                        <div className="text-[11px] font-medium text-[var(--subtle)]">Team active</div>
                        <div className="mt-1 text-2xl font-extrabold text-[var(--brand)]">14</div>
                    </div>
                </div>
                <div className="mt-3 rounded-xl bg-[var(--paper)] p-3">
                    <div className="mb-2 flex items-center justify-between text-[11px] text-[var(--subtle)]"><span>This week</span><span>Mon–Fri</span></div>
                    <div className="flex items-end gap-2" style={{ height: 64 }}>
                        {[52, 78, 64, 96, 70].map((h, i) => (
                            <div key={i} className="flex-1 rounded-t-md" style={{ height: `${h}%`, background: 'linear-gradient(180deg,var(--g-from),var(--g-to))' }} />
                        ))}
                    </div>
                </div>
            </div>

            {/* floating "focus" card */}
            <div className="absolute -left-2 top-4 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-3.5 shadow-xl ring-1 ring-black/5">
                <div className="flex items-center gap-2.5">
                    <span className="flex h-9 w-9 items-center justify-center rounded-full text-white" style={{ background: 'linear-gradient(135deg,var(--g-from),var(--g-to))' }}>
                        <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5" /></svg>
                    </span>
                    <div>
                        <div className="text-[11px] font-medium text-[var(--subtle)]">Focus</div>
                        <div className="text-sm font-bold text-[var(--brand)]">92% today</div>
                    </div>
                </div>
            </div>

            {/* floating "clock-in" card */}
            <div className="absolute -right-3 bottom-6 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-3.5 shadow-xl ring-1 ring-black/5">
                <div className="flex items-center gap-2.5">
                    <span className="relative flex h-9 w-9 items-center justify-center rounded-full bg-[var(--mint)] text-[var(--a)] font-bold">A
                        <span className="absolute -right-0.5 -top-0.5 h-3 w-3 rounded-full border-2 border-[var(--surface)] bg-emerald-500" />
                    </span>
                    <div>
                        <div className="text-[11px] font-medium text-[var(--subtle)]">Clocked in</div>
                        <div className="text-sm font-bold text-[var(--brand)]">9:02 am</div>
                    </div>
                </div>
            </div>
        </div>
    );
}

export default function Hero({ data }) {
    const center = data.variant === 'center';
    return (
        <section className="relative isolate overflow-hidden">
            {/* full-bleed vibrant gradient backdrop */}
            <div className="absolute inset-0 -z-10" style={{ background: 'radial-gradient(820px 560px at 6% -6%, color-mix(in srgb, var(--g-from) 38%, var(--canvas)), transparent 58%), radial-gradient(760px 560px at 102% 4%, color-mix(in srgb, var(--pink) 80%, var(--canvas)), transparent 56%), radial-gradient(700px 520px at 70% 108%, color-mix(in srgb, var(--a) 22%, var(--canvas)), transparent 60%), var(--canvas)' }} />

            {/* interactive constellation background (home hero only) */}
            {!center && <div className="absolute inset-0 -z-0 opacity-80"><HeroParticles /></div>}

            <div className={`relative z-10 mx-auto items-center gap-10 px-6 pb-16 pt-14 sm:px-10 lg:gap-16 lg:pb-24 lg:pt-24 ${center ? 'max-w-3xl text-center' : 'grid max-w-[1600px] pb-20 lg:grid-cols-[1fr_1.1fr] lg:pb-28'}`}>
                <div className={center ? '' : 'text-center lg:text-left'}>
                    {data.eyebrow && (
                        <span className="inline-flex items-center gap-2 rounded-full border bg-[var(--surface)] px-4 py-1.5 text-sm font-medium backdrop-blur" style={{ color: 'var(--a)', borderColor: 'color-mix(in srgb, var(--a) 25%, var(--canvas))' }}>
                            <span className="h-2 w-2 animate-pulse rounded-full" style={{ background: 'var(--a)' }} />
                            {data.eyebrow}
                        </span>
                    )}
                    <h1 className={`mt-6 text-[2.6rem] font-extrabold leading-[1.04] tracking-tight text-[var(--brand)] sm:text-6xl ${center ? 'mx-auto max-w-3xl' : 'mx-auto max-w-2xl lg:mx-0'}`}>
                        {data.heading}
                        {data.heading_highlight && (
                            <span className="block" style={{ background: 'linear-gradient(120deg,var(--g-from),var(--g-to))', WebkitBackgroundClip: 'text', backgroundClip: 'text', color: 'transparent' }}>{data.heading_highlight}</span>
                        )}
                    </h1>
                    {data.subhead && <p className={`mt-6 text-lg leading-relaxed text-[var(--muted)] ${center ? 'mx-auto max-w-2xl' : 'mx-auto max-w-xl lg:mx-0'}`}>{data.subhead}</p>}
                    {(data.primary_cta?.label || data.secondary_cta?.label) && (
                        <div className={`mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row ${center ? '' : 'lg:justify-start'}`}>
                            <Cta cta={data.primary_cta} variant="primary" />
                            <Cta cta={data.secondary_cta} variant="secondary" />
                        </div>
                    )}
                    {data.note && <p className="mt-4 text-sm text-[var(--subtle)]">{data.note}</p>}
                </div>

                {!center && (
                    <div className="relative">
                        {data.image_url
                            ? <div className="overflow-hidden rounded-2xl border border-[var(--line)] shadow-2xl"><img src={data.image_url} alt="" className="w-full" /></div>
                            : <HeroCollage />}
                    </div>
                )}
            </div>
        </section>
    );
}
