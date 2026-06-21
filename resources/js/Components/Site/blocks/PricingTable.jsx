import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { SiteIcon } from '../icons';

// Curated marketing feature lists per plan key (the price + seats come live from
// the app's Plans; this is the editable "what's included" copy).
const FEATURES = {
    free: ['Up to 3 users', 'One-click time tracking', 'Attendance & shifts', 'Reports & CSV export'],
    starter: ['Everything in Free', 'Screenshots & activity', 'App & URL tracking', 'Slack digests'],
    pro: ['Everything in Starter', 'AI day summaries', 'Integrations & API', 'White-label branding', 'Priority support'],
    enterprise: ['Everything in Pro', 'Unlimited users', 'SSO & advanced security', 'Onboarding & dedicated support'],
};
const POPULAR = 'pro';

function money(usd, currency, rate) {
    if (currency === 'PKR') return 'Rs ' + Math.round(usd * rate).toLocaleString();
    return '$' + (Number.isInteger(usd) ? usd : usd.toFixed(2));
}

export default function PricingTable({ data, brand, plans = [] }) {
    const [annual, setAnnual] = useState(true);
    const [cur, setCur] = useState('USD');
    const rate = brand?.pkr_rate || 280;

    return (
        <section id={data.anchor || 'pricing'} className="scroll-mt-24 py-12 sm:py-16">
            <div className="mx-auto max-w-[1300px] px-6 sm:px-10">
                {data.heading && <h2 className="text-center text-3xl font-extrabold tracking-tight text-[var(--brand)] sm:text-[2.6rem]">{data.heading}</h2>}
                {data.subhead && <p className="mx-auto mt-4 max-w-2xl text-center text-lg text-[var(--muted)]">{data.subhead}</p>}

                {/* toggles */}
                <div className="mt-8 flex flex-wrap items-center justify-center gap-4">
                    <div className="inline-flex rounded-full border border-[var(--line)] bg-[var(--surface)] p-1 text-sm">
                        {[['Monthly', false], ['Annual · save 17%', true]].map(([label, val]) => (
                            <button key={label} type="button" onClick={() => setAnnual(val)}
                                className={`rounded-full px-4 py-1.5 font-semibold transition ${annual === val ? 'text-white' : 'text-[var(--muted)]'}`}
                                style={annual === val ? { background: 'linear-gradient(135deg,var(--g-from),var(--g-to))' } : undefined}>
                                {label}
                            </button>
                        ))}
                    </div>
                    <div className="inline-flex rounded-full border border-[var(--line)] bg-[var(--surface)] p-1 text-sm">
                        {['USD', 'PKR'].map((c) => (
                            <button key={c} type="button" onClick={() => setCur(c)}
                                className={`rounded-full px-4 py-1.5 font-semibold transition ${cur === c ? 'text-white' : 'text-[var(--muted)]'}`}
                                style={cur === c ? { background: 'linear-gradient(135deg,var(--g-from),var(--g-to))' } : undefined}>
                                {c}
                            </button>
                        ))}
                    </div>
                </div>

                <div className="mt-12 grid items-stretch gap-6 lg:grid-cols-4 md:grid-cols-2">
                    {plans.map((p) => {
                        const popular = p.key === POPULAR;
                        const usd = p.price_cents / 100;
                        const perMo = annual && usd ? usd * 10 / 12 : usd;
                        const seats = p.max_users == null ? 'Unlimited users' : `Up to ${p.max_users} users`;
                        const feats = FEATURES[p.key] || [seats];
                        return (
                            <div key={p.key}
                                className={`relative flex flex-col rounded-2xl border bg-[var(--surface)] p-7 ${popular ? 'border-transparent shadow-2xl' : 'border-[var(--line)] shadow-sm'}`}
                                style={popular ? { boxShadow: '0 30px 60px -25px color-mix(in srgb, var(--a) 50%, transparent)', backgroundImage: 'linear-gradient(var(--surface),var(--surface)), linear-gradient(135deg,var(--g-from),var(--g-to))', backgroundOrigin: 'border-box', backgroundClip: 'padding-box, border-box', borderWidth: 2 } : undefined}>
                                {popular && <span className="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full px-3 py-1 text-[11px] font-bold uppercase tracking-wide text-white" style={{ background: 'linear-gradient(135deg,var(--g-from),var(--g-to))' }}>Most popular</span>}
                                <h3 className="text-lg font-bold text-[var(--brand)]">{p.name}</h3>
                                <div className="mt-4 flex items-end gap-1">
                                    {p.contact ? (
                                        <span className="text-3xl font-extrabold text-[var(--brand)]">Let's talk</span>
                                    ) : (
                                        <>
                                            <span className="text-4xl font-extrabold text-[var(--brand)]">{money(perMo, cur, rate)}</span>
                                            <span className="pb-1 text-sm text-[var(--subtle)]">/seat·mo</span>
                                        </>
                                    )}
                                </div>
                                <div className="mt-1 h-4 text-xs text-[var(--subtle)]">{!p.contact && usd > 0 && annual ? 'billed annually' : ' '}</div>
                                <div className="mt-1 text-sm font-medium text-[var(--muted)]">{seats}</div>
                                {p.contact
                                    ? <Link href="/contact" className="btn-ghost mt-6 inline-flex justify-center rounded-full px-5 py-3 text-sm font-semibold">Contact sales</Link>
                                    : <Link href="/register" className={`mt-6 inline-flex justify-center rounded-full px-5 py-3 text-sm font-semibold ${popular ? 'btn-primary' : 'btn-ghost'}`}>Start free trial</Link>}
                                <ul className="mt-7 space-y-3">
                                    {feats.map((f, i) => (
                                        <li key={i} className="flex items-start gap-2.5 text-[14px] text-[var(--brand)]">
                                            <span className="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-white" style={{ background: 'var(--a)' }}><SiteIcon name="check" className="h-2.5 w-2.5" /></span>
                                            {f}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        );
                    })}
                </div>
                <p className="mt-8 text-center text-sm text-[var(--subtle)]">{data.note || 'No credit card required · 14-day Pro trial · Cancel anytime'}</p>
            </div>
        </section>
    );
}
