import { useState } from 'react';
import { Button, Card, Badge, StatCard, KpiCard, EmptyState, Segmented } from '@/Components/ui';
import { Check, Minus, Users, Wallet, Receipt, Puzzle, Clock, Lock } from 'lucide-react';
import { money, moneyOr, perSeat, shortDate } from './money';

const cx = (...a) => a.filter(Boolean).join(' ');

/* ---------------------------------------------------------------------------
 * Add-on card — name, per-seat price on either cycle, what it unlocks, and an
 * HONEST state. Because no payment gateway is wired, "Add" records a charge as
 * owed and grants nothing until it is confirmed; the card says exactly that
 * rather than pretending the feature is live.
 * ------------------------------------------------------------------------- */
function AddonCard({ addon, canManage, onAdd, onRemove, busy }) {
    const [cycle, setCycle] = useState('monthly');
    const quote = addon.quotes?.[cycle];
    const priced = quote?.priced;

    const stateBadge = {
        active: <Badge tone="success" size="sm">Active</Badge>,
        pending: <Badge tone="warning" size="sm">Awaiting payment</Badge>,
        available: null,
    }[addon.state];

    return (
        <Card padded={false} className={cx('flex flex-col', addon.state === 'active' && 'border-accent ring-1 ring-accent')}>
            <div className="flex flex-col gap-3 p-5">
                <div className="flex items-start justify-between gap-3">
                    <div className="flex items-start gap-3">
                        <span aria-hidden="true" className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-accent/10 text-accent">
                            <Puzzle className="h-5 w-5" />
                        </span>
                        <div>
                            <h3 className="font-semibold text-body">{addon.name}</h3>
                            {addon.description && (
                                <p className="mt-1 text-xs leading-5 text-muted">{addon.description}</p>
                            )}
                        </div>
                    </div>
                    {stateBadge}
                </div>

                {/* Per-seat price, both cycles. The numbers are server-derived. */}
                <div className="flex items-center justify-between gap-3 border-t border-line pt-3">
                    <p className="text-xl font-bold tabular-nums text-body">
                        {perSeat(
                            cycle === 'yearly' ? addon.price_cents_yearly : addon.price_cents,
                            addon.currency,
                            cycle,
                        ) || <span className="text-base font-medium text-muted">Contact us</span>}
                    </p>
                    <Segmented
                        size="sm"
                        value={cycle}
                        onChange={setCycle}
                        tabs={[
                            { key: 'monthly', label: 'Monthly' },
                            { key: 'yearly', label: 'Yearly' },
                        ]}
                    />
                </div>

                {/* What it unlocks — read off the live feature catalogue. */}
                {addon.unlocks?.length > 0 && (
                    <ul className="space-y-1.5 border-t border-line pt-3 text-sm">
                        {addon.unlocks.map((label) => (
                            <li key={label} className="flex items-center gap-2 text-body">
                                <Check className="h-4 w-4 shrink-0 text-success" />
                                {label}
                            </li>
                        ))}
                    </ul>
                )}

                {/* What it would cost TODAY — prorated to the current period. */}
                {addon.state === 'available' && priced && (
                    <div className="rounded-lg bg-surface-2 px-3 py-2.5 text-xs leading-5 text-muted">
                        <span className="font-semibold text-body">
                            {money(quote.amount_cents, quote.currency)} due now
                        </span>{' '}
                        — {quote.seats} seat{quote.seats === 1 ? '' : 's'} ×{' '}
                        {money(quote.unit_price_cents, quote.currency)}, prorated for the{' '}
                        {quote.days_remaining} day{quote.days_remaining === 1 ? '' : 's'} left of your{' '}
                        {quote.cycle === 'yearly' ? 'year' : 'month'}
                        {quote.period_end ? ` (to ${shortDate(quote.period_end)})` : ''}. Then{' '}
                        {money(quote.full_period_cents, quote.currency)} per {quote.cycle === 'yearly' ? 'year' : 'month'}.
                    </div>
                )}

                {addon.state === 'pending' && addon.pending_charge && (
                    <div className="flex items-start gap-2 rounded-lg bg-warning-soft px-3 py-2.5 text-xs leading-5 text-warning">
                        <Clock className="mt-0.5 h-4 w-4 shrink-0" />
                        <span>
                            <strong>Not active yet.</strong> We’ve recorded{' '}
                            {money(addon.pending_charge.amount_cents, addon.pending_charge.currency)} for{' '}
                            {addon.pending_charge.seats} seat{addon.pending_charge.seats === 1 ? '' : 's'} (
                            {addon.pending_charge.cycle}, ref {addon.pending_charge.reference}). It switches on once we
                            confirm your payment — nothing is charged automatically.
                        </span>
                    </div>
                )}
            </div>

            <div className="mt-auto border-t border-line p-4">
                {!canManage ? (
                    <div className="flex items-center justify-center gap-2 rounded-lg border border-line px-3 py-2 text-center text-xs font-semibold text-muted">
                        <Lock className="h-3.5 w-3.5" /> Billing permission required
                    </div>
                ) : addon.state === 'active' ? (
                    <Button variant="secondary" block size="sm" disabled={busy} onClick={() => onRemove(addon)}>
                        Remove
                    </Button>
                ) : addon.state === 'pending' ? (
                    <Button variant="secondary" block size="sm" disabled={busy} onClick={() => onRemove(addon)}>
                        Withdraw request
                    </Button>
                ) : (
                    <Button
                        variant="primary"
                        block
                        size="sm"
                        disabled={busy || !priced}
                        onClick={() => onAdd(addon, cycle)}
                    >
                        {priced ? 'Add to workspace' : 'No yearly price — contact us'}
                    </Button>
                )}
            </div>
        </Card>
    );
}

function PlanCard({ plan, currentKey, cycle, onChoose, canManage }) {
    const isCurrent = plan.key === currentKey;
    const cents = cycle === 'yearly' ? plan.price_cents_yearly : plan.price_cents;
    const price = cents ? money(cents, plan.currency) : null;

    return (
        <Card padded={false} className={cx('flex flex-col', isCurrent && 'border-accent ring-1 ring-accent')}>
            <div className="flex flex-col p-5">
                <div className="flex items-center justify-between gap-2">
                    <h3 className="font-semibold text-body">{plan.name}</h3>
                    {isCurrent && <Badge tone="accent" size="sm">Current</Badge>}
                </div>

                <p className="mt-2 text-2xl font-bold tabular-nums text-body">
                    {price ? (
                        <>
                            {price}
                            <span className="text-sm font-normal text-muted">
                                /seat·{cycle === 'yearly' ? 'yr' : 'mo'}
                            </span>
                        </>
                    ) : (
                        <span className="text-base font-medium text-muted">
                            {cents === 0 ? 'Free' : 'Contact us'}
                        </span>
                    )}
                </p>

                <dl className="mt-4 space-y-1.5 border-t border-line pt-3 text-xs">
                    {(plan.caps || []).map((c) => (
                        <div key={c.label} className="flex items-center justify-between gap-2">
                            <dt className="text-muted">{c.label}</dt>
                            <dd className="font-medium text-body">{c.value}</dd>
                        </div>
                    ))}
                </dl>

                <ul className="mt-3 space-y-1.5 border-t border-line pt-3 text-sm">
                    {(plan.flags || []).map((f) => (
                        <li key={f.label} className={cx('flex items-center gap-2', f.on ? 'text-body' : 'text-subtle')}>
                            {f.on ? (
                                <Check className="h-4 w-4 shrink-0 text-success" />
                            ) : (
                                <Minus className="h-4 w-4 shrink-0 text-subtle" />
                            )}
                            {f.label}
                        </li>
                    ))}
                </ul>
            </div>

            <div className="mt-auto border-t border-line p-4">
                {isCurrent ? (
                    <div className="rounded-lg border border-line px-3 py-2 text-center text-xs font-semibold text-muted">
                        Your current plan
                    </div>
                ) : (
                    <Button
                        variant={cents ? 'primary' : 'secondary'}
                        block
                        size="sm"
                        disabled={!canManage}
                        onClick={() => onChoose(plan.key)}
                    >
                        {cents ? 'Choose plan' : 'Talk to us'}
                    </Button>
                )}
            </div>
        </Card>
    );
}

/**
 * Tab 1 — Plan & add-ons.
 *
 * @param {boolean} canManage Whether the viewer holds `billing.manage`. When
 *        false every control is disabled; the page stays fully readable.
 */
export default function PlanAddonsTab({ data, canManage, busy, onAddAddon, onRemoveAddon, onChoosePlan }) {
    const cycle = data.subscription?.cycle || 'monthly';

    const seatPct =
        data.seatLimit && data.seatLimit > 0
            ? Math.min(100, Math.round((data.seatsUsed / data.seatLimit) * 100))
            : null;
    const seatTone = seatPct == null ? 'accent' : seatPct >= 90 ? 'danger' : seatPct >= 75 ? 'warning' : 'success';

    return (
        <div className="space-y-6">
            {/* Contract accents: seats are people (slot 3, blue); price is money
                (slot 5, pink). "Current plan" is plan STATE (trial / renewal
                dates) and stays on the status-neutral StatCard. */}
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <KpiCard
                    accent={3}
                    label="Seats"
                    value={
                        <>
                            {data.seatsUsed}
                            <span className="text-base font-normal text-muted">
                                {data.seatLimit ? ` / ${data.seatLimit}` : ' / ∞'}
                            </span>
                        </>
                    }
                    sub={data.seatLimit ? `${seatPct}% of your plan's seats in use` : 'Unlimited seats on your plan'}
                    icon={<Users className="h-[18px] w-[18px]" />}
                />
                {data.subscription && (
                    <KpiCard
                        accent={5}
                        label="Your price"
                        value={moneyOr(data.subscription.price_cents, data.subscription.currency, 'Contact us')}
                        sub={
                            data.subscription.has_custom_price
                                ? 'Negotiated price, per seat'
                                : `per seat, per ${cycle === 'yearly' ? 'year' : 'month'}`
                        }
                        icon={<Wallet className="h-[18px] w-[18px]" />}
                    />
                )}
                <StatCard
                    label="Current plan"
                    value={data.subscription?.plan_name || '—'}
                    hint={
                        data.subscription?.on_trial
                            ? `Trial ends ${shortDate(data.subscription.trial_ends_at)}`
                            : data.subscription?.current_period_end
                                ? `Renews ${shortDate(data.subscription.current_period_end)}`
                                : 'No renewal date on file'
                    }
                    icon={<Receipt className="h-5 w-5" />}
                />
            </div>

            {seatPct != null && (
                <Card>
                    <div className="mb-2 flex items-center justify-between text-sm">
                        <span className="font-medium text-body">Seat usage</span>
                        <span className="tabular-nums text-muted">
                            {data.seatsUsed} of {data.seatLimit} seats
                        </span>
                    </div>
                    <div className="h-2 w-full overflow-hidden rounded-full bg-surface-2">
                        <div
                            className={cx(
                                'h-full rounded-full transition-all',
                                seatTone === 'danger' ? 'bg-danger' : seatTone === 'warning' ? 'bg-warning' : 'bg-accent',
                            )}
                            style={{ width: `${seatPct}%` }}
                        />
                    </div>
                    <p className="mt-2 text-xs text-muted">
                        Add-ons are priced per active seat, so this is the number every quote below is derived from.
                    </p>
                </Card>
            )}

            {/* --- ADD-ONS ---------------------------------------------------- */}
            <div>
                <h3 className="mb-1 text-sm font-semibold uppercase tracking-wide text-muted">Add-ons</h3>
                <p className="mb-3 text-sm text-muted">
                    Extra toolsets you can switch on for this workspace, priced per active seat.
                </p>
                {data.addons?.length > 0 ? (
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {data.addons.map((a) => (
                            <AddonCard
                                key={a.key}
                                addon={a}
                                canManage={canManage}
                                busy={busy}
                                onAdd={onAddAddon}
                                onRemove={onRemoveAddon}
                            />
                        ))}
                    </div>
                ) : (
                    <EmptyState
                        title="No add-ons available"
                        description="There are no add-ons on sale for your workspace right now."
                    />
                )}
            </div>

            {/* --- PLANS ------------------------------------------------------ */}
            <div>
                <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-muted">
                    Plans ({cycle === 'yearly' ? 'yearly' : 'monthly'} pricing)
                </h3>
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {(data.plans || []).map((p) => (
                        <PlanCard
                            key={p.key}
                            plan={p}
                            cycle={cycle}
                            currentKey={data.subscription?.plan_key}
                            canManage={canManage}
                            onChoose={onChoosePlan}
                        />
                    ))}
                </div>
            </div>
        </div>
    );
}
