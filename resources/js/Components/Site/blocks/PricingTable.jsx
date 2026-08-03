import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { SiteIcon } from '../icons';

// Curated marketing feature lists per plan key (the price + seats come live from
// the app's Plans; this is the "what's included" copy).
//
// ONLY list capabilities that actually ship today. This is a public price
// promise — anything here is something a customer can demand on day one, so an
// unbuilt feature is a refund and a trust problem. This list previously
// advertised "SSO & advanced security" and "Integrations & API"; neither exists
// anywhere in the product (no SAML, no OAuth, no customer-facing API), so both
// were removed rather than left as a promise we couldn't keep.
//
// The lines below mirror App\Models\Plan::defaults() entitlements exactly:
//   free        monitoring off, 7-day history
//   starter     monitoring + activity, 90-day history (app/URL tracking is OFF)
//   pro         + app/URL tracking, AI, insights, white-label, 1-year history
//   enterprise  unlimited seats + unlimited history
// Seat counts are NOT repeated here — they render from the live plan row.
const FEATURES = {
    free: ['One-click time tracking', 'Attendance, shifts & breaks', 'Timesheets with approval', 'Reports & CSV export', '7 days of history'],
    starter: ['Everything in Free', 'Screenshots & activity levels', 'Slack digests & attendance alerts', 'Weekly hour limits', '90 days of history'],
    pro: ['Everything in Starter', 'App & website (domain) tracking', 'Team insights & AI day summaries', 'White-label branding', '1 year of history'],
    enterprise: ['Everything in Growth', 'Unlimited seats', 'Unlimited history & audit trail', 'Priority onboarding & support'],
};
const POPULAR = 'pro';

// Local prices are owner-approved fixed figures (not a live FX conversion), so
// the block takes an explicit per-plan PKR map from the page content and only
// falls back to the brand rate when a plan is missing from it.
function money(usd, currency, rate, pkr) {
    if (currency === 'PKR') {
        const value = pkr != null ? pkr : usd * rate;
        return 'Rs ' + Math.round(value).toLocaleString();
    }

    return '$' + (Number.isInteger(usd) ? usd : usd.toFixed(2));
}

/**
 * Resolve the add-on's price from the LIVE catalogue, falling back to the
 * owner-approved figures authored into the block.
 *
 * The price used to come from page content alone, so an operator changing
 * `agency_toolkit` in the panel left this page quoting the old number — a price
 * a customer could reasonably hold us to. The catalogue now wins whenever it has
 * something to say (matched on the block's `key`, defaulting to agency_toolkit),
 * and the authored figures remain the fallback for a deployment whose addons
 * table is empty, or where the row has been made non-public.
 *
 * The local PKR figure is an owner-approved fixed price, not a conversion, so it
 * is only trusted while the live USD still MATCHES the USD it was set against.
 * Once the operator moves the dollar price, holding the old rupee price would
 * advertise two different prices at once — so it is dropped and `money()` falls
 * back to the brand rate.
 */
function resolveAddonPricing(addon, catalogue) {
    const authoredUsd = addon.price_usd;
    const authoredYearly = addon.price_usd_yearly;
    const live = (Array.isArray(catalogue) ? catalogue : [])
        .find((row) => row.key === (addon.key || 'agency_toolkit'));

    if (!live) {
        return { usd: authoredUsd, yearly: authoredYearly, pkr: addon.price_pkr, name: addon.name };
    }

    const usd = live.price_cents / 100;
    const yearly = live.price_cents_yearly == null ? null : live.price_cents_yearly / 100;

    return {
        usd,
        yearly,
        pkr: usd === authoredUsd ? addon.price_pkr : null,
        // The catalogue is also the authority on what the thing is CALLED.
        name: live.name || addon.name,
    };
}

/** The Agency toolkit add-on card. */
function AddonCard({ addon, catalogue, annual, cur, rate }) {
    const price = resolveAddonPricing(addon, catalogue);
    const usd = annual && price.yearly ? price.yearly / 12 : price.usd;
    const pkr = annual && price.pkr != null ? (price.pkr * 10) / 12 : price.pkr;
    const bullets = addon.bullets || [];

    return (
        <div className="mt-8 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-7 shadow-sm sm:p-9">
            <div className="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                <div className="lg:max-w-2xl">
                    <span className="inline-flex rounded-full px-3 py-1 text-[11px] font-bold uppercase tracking-wide text-white" style={{ background: 'linear-gradient(135deg,var(--g-from),var(--g-to))' }}>
                        {addon.eyebrow || 'Add-on'}
                    </span>
                    <h3 className="mt-4 text-2xl font-extrabold tracking-tight text-[var(--brand)]">{price.name}</h3>
                    {addon.text && <p className="mt-3 text-[15px] leading-relaxed text-[var(--muted)]">{addon.text}</p>}
                    {bullets.length > 0 && (
                        <ul className="mt-5 grid gap-2.5 sm:grid-cols-2">
                            {bullets.map((b, i) => (
                                <li key={i} className="flex items-start gap-2.5 text-[14px] text-[var(--brand)]">
                                    <span className="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-white" style={{ background: 'var(--a)' }}><SiteIcon name="check" className="h-2.5 w-2.5" /></span>
                                    {b}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
                <div className="shrink-0 rounded-2xl bg-[var(--surface-2)] p-6 text-center lg:min-w-[15rem]">
                    <div className="flex items-end justify-center gap-1">
                        <span className="text-4xl font-extrabold text-[var(--brand)]">+{money(usd, cur, rate, pkr)}</span>
                        <span className="pb-1 text-sm text-[var(--subtle)]">/seat·mo</span>
                    </div>
                    <div className="mt-1 text-xs text-[var(--subtle)]">{annual ? 'billed annually' : 'billed monthly'}</div>
                    <Link href="/contact" className="btn-primary mt-5 inline-flex w-full justify-center rounded-full px-5 py-3 text-sm font-semibold">Talk to us</Link>
                </div>
            </div>
            {addon.note && <p className="mt-6 text-sm text-[var(--subtle)]">{addon.note}</p>}
        </div>
    );
}

export default function PricingTable({ data, brand, plans = [], addons = [] }) {
    const [annual, setAnnual] = useState(true);
    const [cur, setCur] = useState('USD');
    const rate = brand?.pkr_rate || 280;
    const pkrPrices = data.pkr || {};

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
                        // Enterprise publishes a monthly floor and negotiates the
                        // yearly deal, so the 10-months-for-12 maths must not be
                        // applied to it — that would quote a discount nobody agreed.
                        const perMo = annual && usd && ! p.contact ? usd * 10 / 12 : usd;
                        const pkr = pkrPrices[p.key];
                        const perMoPkr = pkr == null ? null : (annual && pkr && ! p.contact ? (pkr * 10) / 12 : pkr);
                        const seats = p.max_users == null ? 'Unlimited seats' : `Up to ${p.max_users} seats`;
                        const feats = FEATURES[p.key] || [seats];
                        const priceNote = p.contact
                            ? 'custom yearly terms'
                            : (usd > 0 && annual ? 'billed annually' : ' ');
                        return (
                            <div key={p.key}
                                className={`relative flex flex-col rounded-2xl border bg-[var(--surface)] p-7 ${popular ? 'border-transparent shadow-2xl' : 'border-[var(--line)] shadow-sm'}`}
                                style={popular ? { boxShadow: '0 30px 60px -25px color-mix(in srgb, var(--a) 50%, transparent)', backgroundImage: 'linear-gradient(var(--surface),var(--surface)), linear-gradient(135deg,var(--g-from),var(--g-to))', backgroundOrigin: 'border-box', backgroundClip: 'padding-box, border-box', borderWidth: 2 } : undefined}>
                                {popular && <span className="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full px-3 py-1 text-[11px] font-bold uppercase tracking-wide text-white" style={{ background: 'linear-gradient(135deg,var(--g-from),var(--g-to))' }}>Most popular</span>}
                                <h3 className="text-lg font-bold text-[var(--brand)]">{p.name}</h3>
                                <div className="mt-4 flex items-end gap-1">
                                    {p.contact && <span className="pb-1.5 text-sm font-semibold text-[var(--subtle)]">from</span>}
                                    <span className="text-4xl font-extrabold text-[var(--brand)]">{money(perMo, cur, rate, perMoPkr)}</span>
                                    <span className="pb-1 text-sm text-[var(--subtle)]">/seat·mo</span>
                                </div>
                                <div className="mt-1 h-4 text-xs text-[var(--subtle)]">{priceNote}</div>
                                <div className="mt-1 text-sm font-medium text-[var(--muted)]">{seats}</div>
                                {p.contact
                                    ? <Link href="/contact" className="btn-ghost mt-6 inline-flex justify-center rounded-full px-5 py-3 text-sm font-semibold">Contact sales</Link>
                                    : <Link href="/register" className={`mt-6 inline-flex justify-center rounded-full px-5 py-3 text-sm font-semibold ${popular ? 'btn-primary' : 'btn-ghost'}`}>{usd > 0 ? 'Start free trial' : 'Start free'}</Link>}
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

                {data.addon && <AddonCard addon={data.addon} catalogue={addons} annual={annual} cur={cur} rate={rate} />}

                <p className="mt-8 text-center text-sm text-[var(--subtle)]">
                    {data.note || 'No card required · 14-day Growth trial · Cancel anytime'}
                </p>
            </div>
        </section>
    );
}
