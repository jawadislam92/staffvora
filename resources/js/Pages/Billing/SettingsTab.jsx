import { useState } from 'react';
import { Button, Card, Badge, Segmented } from '@/Components/ui';
import { Info, ShieldCheck, CreditCard } from 'lucide-react';
import { money, shortDate } from './money';

const cx = (...a) => a.filter(Boolean).join(' ');

/**
 * Tab 3 — Subscription settings.
 *
 * The billing cycle (monthly ⇄ yearly) with a LIVE INVOICE PREVIEW: as you move
 * the switch, the invoice on the right redraws into exactly what the next
 * charge becomes.
 *
 * ⚠️ The preview does NO arithmetic. Both cycles are derived server-side by
 * BillingPricing (plan line + one line per active add-on, at the live seat
 * count) and shipped as props; the switch only chooses which server-computed
 * invoice to render. That is what stops the displayed price and the charged
 * price from ever drifting apart.
 */
export default function SettingsTab({ data, canManage, busy, onSaveCycle }) {
    const current = data.subscription?.cycle || 'monthly';
    const [cycle, setCycle] = useState(current);

    const preview = data.previews?.[cycle];
    const changed = cycle !== current;
    const yearlyUnpriced = data.previews?.yearly && !data.previews.yearly.priced;

    return (
        <div className="grid gap-6 lg:grid-cols-5">
            {/* --- The control ------------------------------------------------ */}
            <div className="space-y-6 lg:col-span-2">
                <Card title="Billing cycle" description="Pay monthly, or pay for the year and save.">
                    <div>
                        <Segmented
                            value={cycle}
                            onChange={setCycle}
                            tabs={[
                                { key: 'monthly', label: 'Monthly' },
                                { key: 'yearly', label: 'Yearly' },
                            ]}
                        />
                    </div>

                    {data.previews?.saving_cents ? (
                        <p className="mt-3 inline-flex items-center gap-2 rounded-lg bg-success-soft px-3 py-2 text-sm text-success">
                            Paying yearly saves {money(data.previews.saving_cents, data.previews.yearly?.currency)} a year
                            at your current seat count.
                        </p>
                    ) : null}

                    {yearlyUnpriced && (
                        <p className="mt-3 flex items-start gap-2 rounded-lg bg-info-soft px-3 py-2 text-sm text-info">
                            <Info className="mt-0.5 h-4 w-4 shrink-0" />
                            <span>Your plan has no published yearly price. Contact us and we’ll quote it.</span>
                        </p>
                    )}

                    <div className="mt-4 flex items-center gap-3 border-t border-line pt-4">
                        <Button
                            variant="primary"
                            size="sm"
                            disabled={!canManage || !changed || busy || (cycle === 'yearly' && yearlyUnpriced)}
                            onClick={() => onSaveCycle(cycle)}
                        >
                            {changed ? `Switch to ${cycle} billing` : `Billed ${current}`}
                        </Button>
                        {changed && (
                            <button
                                type="button"
                                className="rounded text-sm text-muted hover:text-body focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                                onClick={() => setCycle(current)}
                            >
                                Cancel
                            </button>
                        )}
                    </div>

                    {!canManage && (
                        <p className="mt-3 text-xs text-muted">
                            You can see the numbers, but changing the cycle needs the billing permission.
                        </p>
                    )}
                </Card>

                <Card>
                    <div className="flex items-start gap-3">
                        <span aria-hidden="true" className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-success-soft text-success">
                            <ShieldCheck className="h-5 w-5" />
                        </span>
                        <div className="text-sm leading-6 text-muted">
                            <p className="font-semibold text-body">We never see or store your card details.</p>
                            <p className="mt-0.5">
                                Online card payments aren’t switched on yet. When they are, your card goes straight to a
                                PCI-DSS-compliant provider’s hosted checkout and never touches our servers.
                            </p>
                        </div>
                    </div>
                </Card>

                <Card
                    title={
                        <span className="inline-flex items-center gap-2">
                            <CreditCard className="h-5 w-5 text-accent" />
                            How to pay
                        </span>
                    }
                >
                    <p className="text-sm leading-6 text-muted">{data.bankDetails}</p>
                    {data.supportEmail && (
                        <p className="mt-2 text-sm text-muted">
                            Questions?{' '}
                            <a
                                href={`mailto:${data.supportEmail}`}
                                className="rounded font-medium text-accent hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app"
                            >
                                {data.supportEmail}
                            </a>
                        </p>
                    )}
                </Card>
            </div>

            {/* --- The live invoice preview ----------------------------------- */}
            <div className="lg:col-span-3">
                <Card padded={false} className="overflow-hidden">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4">
                        <div>
                            <h3 className="font-semibold text-body">Next invoice preview</h3>
                            <p className="mt-0.5 text-xs text-muted">
                                {preview?.renews_at
                                    ? `Renews ${shortDate(preview.renews_at)}`
                                    : 'No renewal date on file yet'}{' '}
                                · {preview?.seats ?? 0} active seat{(preview?.seats ?? 0) === 1 ? '' : 's'}
                            </p>
                        </div>
                        <Badge tone={changed ? 'warning' : 'accent'} size="sm">
                            {changed ? `Preview — ${cycle}` : `Current — ${current}`}
                        </Badge>
                    </div>

                    <div className="divide-y divide-line">
                        {(preview?.lines || []).map((line, i) => (
                            <div key={`${line.label}-${i}`} className="flex items-start justify-between gap-4 px-5 py-3.5">
                                <div>
                                    <p className="text-sm font-medium text-body">{line.label}</p>
                                    <p className="text-xs text-muted">
                                        {line.kind === 'plan' ? 'Plan' : 'Add-on'} ·{' '}
                                        {line.priced
                                            ? `${line.seats} × ${money(line.unit_price_cents, line.currency)} per ${cycle === 'yearly' ? 'year' : 'month'}`
                                            : 'no list price for this cycle'}
                                    </p>
                                </div>
                                <p className={cx('shrink-0 text-sm font-medium tabular-nums', line.priced ? 'text-body' : 'text-muted')}>
                                    {line.priced ? money(line.amount_cents, line.currency) : 'Contact us'}
                                </p>
                            </div>
                        ))}
                    </div>

                    <div className="flex items-center justify-between gap-4 border-t border-line bg-surface-2 px-5 py-4">
                        <span className="text-sm font-semibold text-body">
                            Total per {cycle === 'yearly' ? 'year' : 'month'}
                        </span>
                        <span className="text-xl font-bold tabular-nums text-body">
                            {preview?.total_cents != null
                                ? money(preview.total_cents, preview.currency)
                                : <span className="text-base font-medium text-muted">Contact us</span>}
                        </span>
                    </div>

                    <div className="flex items-start gap-2 px-5 py-4 text-xs leading-5 text-muted">
                        <Info className="mt-0.5 h-4 w-4 shrink-0" />
                        <span>
                            Every figure is calculated on the server from your plan, your add-ons and your live seat
                            count — this preview is the same maths that produces the invoice. Changing the cycle takes
                            effect from your next invoice; nothing is charged now.
                        </span>
                    </div>
                </Card>
            </div>
        </div>
    );
}
