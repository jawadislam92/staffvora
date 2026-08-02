import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, Badge, Tabs } from '@/Components/ui';
import { CreditCard, Receipt, Settings2, Building2, AlertTriangle } from 'lucide-react';
import PlanAddonsTab from './PlanAddonsTab';
import InvoicesTab from './InvoicesTab';
import SettingsTab from './SettingsTab';
import DetailsTab from './DetailsTab';
import { shortDate } from './money';

const cx = (...a) => a.filter(Boolean).join(' ');

/* ---------------------------------------------------------------------------
 * DEV_SAMPLE — offline visual-QA fixture. Used ONLY when `preview` is true so
 * the whole 4-tab hub renders with no backend and no network. It mirrors the
 * real prop shape from BillingController@index exactly, including the
 * server-derived quotes and invoice previews (the UI never computes these).
 * ------------------------------------------------------------------------- */
const DEV_SAMPLE = {
    auth: { user: { name: 'Jawad', is_super_admin: true } },
    canManage: true,
    subscription: {
        status: 'active',
        plan_key: 'pro',
        plan_name: 'Growth',
        on_trial: false,
        usable: true,
        cycle: 'monthly',
        trial_ends_at: null,
        current_period_end: '2026-08-15',
        price_cents: 900,
        currency: 'USD',
        has_custom_price: false,
        discount_percent: 0,
    },
    seatsUsed: 18,
    seatLimit: 50,
    plans: [
        {
            key: 'free', name: 'Free', price_cents: 0, price_cents_yearly: 0, currency: 'USD', max_users: 2,
            caps: [
                { label: 'User seats', value: '2 seats' },
                { label: 'Screenshots per hour (max)', value: 'Not included' },
                { label: 'Screenshot retention (max days)', value: '7 days' },
            ],
            flags: [
                { label: 'Screenshot monitoring', on: false },
                { label: 'Activity tracking', on: false },
                { label: 'AI assistant', on: false },
                { label: 'White-label branding', on: false },
            ],
        },
        {
            key: 'starter', name: 'Starter', price_cents: 500, price_cents_yearly: 5000, currency: 'USD', max_users: 10,
            caps: [
                { label: 'User seats', value: '10 seats' },
                { label: 'Screenshots per hour (max)', value: '12 /hour' },
                { label: 'Screenshot retention (max days)', value: '90 days' },
            ],
            flags: [
                { label: 'Screenshot monitoring', on: true },
                { label: 'Activity tracking', on: true },
                { label: 'AI assistant', on: false },
                { label: 'White-label branding', on: false },
            ],
        },
        {
            key: 'pro', name: 'Growth', price_cents: 900, price_cents_yearly: 9000, currency: 'USD', max_users: 50,
            caps: [
                { label: 'User seats', value: '50 seats' },
                { label: 'Screenshots per hour (max)', value: '30 /hour' },
                { label: 'Screenshot retention (max days)', value: '365 days' },
            ],
            flags: [
                { label: 'Screenshot monitoring', on: true },
                { label: 'Activity tracking', on: true },
                { label: 'AI assistant', on: true },
                { label: 'White-label branding', on: true },
            ],
        },
        {
            key: 'enterprise', name: 'Enterprise', price_cents: 1800, price_cents_yearly: null, currency: 'USD', max_users: null,
            caps: [
                { label: 'User seats', value: 'Unlimited' },
                { label: 'Screenshots per hour (max)', value: '60 /hour' },
                { label: 'Screenshot retention (max days)', value: 'Unlimited' },
            ],
            flags: [
                { label: 'Screenshot monitoring', on: true },
                { label: 'Activity tracking', on: true },
                { label: 'AI assistant', on: true },
                { label: 'White-label branding', on: true },
            ],
        },
    ],
    addons: [
        {
            key: 'agency_toolkit',
            name: 'Agency toolkit',
            description: 'Upwork profiles, marketplace work categories and Machine Compliance.',
            currency: 'USD',
            price_cents: 400,
            price_cents_yearly: 4000,
            unlocks: ['Upwork profiles', 'Machine compliance'],
            state: 'available',
            quotes: {
                monthly: {
                    addon_key: 'agency_toolkit', addon_name: 'Agency toolkit', cycle: 'monthly', currency: 'USD',
                    seats: 18, unit_price_cents: 400, full_period_cents: 7200, amount_cents: 3120,
                    days_remaining: 13, cycle_days: 30, period_end: '2026-08-15', priced: true,
                },
                yearly: {
                    addon_key: 'agency_toolkit', addon_name: 'Agency toolkit', cycle: 'yearly', currency: 'USD',
                    seats: 18, unit_price_cents: 4000, full_period_cents: 72000, amount_cents: 2564,
                    days_remaining: 13, cycle_days: 365, period_end: '2026-08-15', priced: true,
                },
            },
            pending_charge: null,
        },
        {
            key: 'insights_pack',
            name: 'Insights pack',
            description: 'Deeper team performance analytics and trend reporting.',
            currency: 'USD',
            price_cents: 300,
            price_cents_yearly: null,
            unlocks: ['AI assistant'],
            state: 'pending',
            quotes: {
                monthly: {
                    addon_key: 'insights_pack', addon_name: 'Insights pack', cycle: 'monthly', currency: 'USD',
                    seats: 18, unit_price_cents: 300, full_period_cents: 5400, amount_cents: 2340,
                    days_remaining: 13, cycle_days: 30, period_end: '2026-08-15', priced: true,
                },
                yearly: {
                    addon_key: 'insights_pack', addon_name: 'Insights pack', cycle: 'yearly', currency: 'USD',
                    seats: 18, unit_price_cents: null, full_period_cents: null, amount_cents: null,
                    days_remaining: 13, cycle_days: 365, period_end: '2026-08-15', priced: false,
                },
            },
            pending_charge: {
                amount_cents: 2340, currency: 'USD', cycle: 'monthly', seats: 18,
                reference: 'ADD-20260802-K7F2QX', requested_at: '2026-08-02',
            },
        },
    ],
    invoices: {
        data: [
            { id: 4, kind: 'addon', status: 'pending', label: 'Insights pack — 18 seats, monthly (prorated 13/30 days)', amount_cents: 2340, currency: 'USD', method: 'bank_transfer', reference: 'ADD-20260802-K7F2QX', seats: 18, unit_price_cents: 300, billing_cycle: 'monthly', paid_at: null, issued_at: '2026-08-02', period_start: '2026-08-02', period_end: '2026-08-15' },
            { id: 3, kind: 'subscription', status: 'confirmed', label: 'Subscription', amount_cents: 16200, currency: 'USD', method: 'bank_transfer', reference: 'INV-2026-07', seats: 18, unit_price_cents: 900, billing_cycle: 'monthly', paid_at: '2026-07-15', issued_at: '2026-07-15', period_start: '2026-07-15', period_end: '2026-08-15' },
            { id: 2, kind: 'subscription', status: 'confirmed', label: 'Subscription', amount_cents: 16200, currency: 'USD', method: 'bank_transfer', reference: 'INV-2026-06', seats: 18, unit_price_cents: 900, billing_cycle: 'monthly', paid_at: '2026-06-15', issued_at: '2026-06-15', period_start: '2026-06-15', period_end: '2026-07-15' },
            { id: 1, kind: 'subscription', status: 'confirmed', label: 'Subscription', amount_cents: 14400, currency: 'USD', method: 'bank_transfer', reference: 'INV-2026-05', seats: 16, unit_price_cents: 900, billing_cycle: 'monthly', paid_at: '2026-05-15', issued_at: '2026-05-15', period_start: '2026-05-15', period_end: '2026-06-15' },
        ],
        links: [],
        meta: { from: 1, to: 4, total: 4 },
    },
    invoiceStatus: 'all',
    invoiceCounts: { all: 4, paid: 3, unpaid: 1 },
    previews: {
        monthly: {
            cycle: 'monthly', currency: 'USD', seats: 18, renews_at: '2026-08-15',
            lines: [
                { label: 'Growth', kind: 'plan', unit_price_cents: 900, seats: 18, amount_cents: 16200, currency: 'USD', priced: true },
            ],
            total_cents: 16200, mixed_currency: false, priced: true,
        },
        yearly: {
            cycle: 'yearly', currency: 'USD', seats: 18, renews_at: '2026-08-15',
            lines: [
                { label: 'Growth', kind: 'plan', unit_price_cents: 9000, seats: 18, amount_cents: 162000, currency: 'USD', priced: true },
            ],
            total_cents: 162000, mixed_currency: false, priced: true,
        },
        saving_cents: 32400,
    },
    billingDetails: {
        billing_name: 'Sparking Asia Pvt Ltd',
        billing_email: 'accounts@sparkingasia.com',
        tax_id: 'NTN-1234567-8',
        address_line1: '2nd Floor, Business Centre',
        address_line2: null,
        city: 'Swabi',
        state: 'KP',
        postal_code: '23430',
        country: 'PK',
        notes: null,
    },
    bankDetails:
        'To upgrade or renew, make a bank transfer for your plan amount and email us the receipt with your workspace name. We’ll activate your plan within one business day.',
    supportEmail: 'billing@staffvora.com',
    cardCheckout: false,
    flash: {},
};

/* The subscription banner: one soft status pill driven by real state tokens. */
function StatusBanner({ subscription }) {
    if (!subscription) {
        return (
            <Card padded={false}>
                <div className="px-5 py-4 text-sm text-muted">No subscription on file.</div>
            </Card>
        );
    }
    const { status, plan_name, on_trial, trial_ends_at, current_period_end, usable, cycle } = subscription;
    const tone = !usable ? 'danger' : on_trial ? 'warning' : 'success';
    const dotClass = tone === 'danger' ? 'bg-danger' : tone === 'warning' ? 'bg-warning' : 'bg-success';
    const label = on_trial
        ? `${plan_name} trial — ends ${shortDate(trial_ends_at)}`
        : status === 'active'
            ? `${plan_name} — active${current_period_end ? ` until ${shortDate(current_period_end)}` : ''}`
            : status === 'past_due'
                ? 'Payment past due — please renew to keep full access'
                : `${plan_name} — ${status}`;

    return (
        <Card padded={false}>
            <div className="flex flex-wrap items-center gap-3 px-5 py-4">
                <span aria-hidden="true" className={cx('h-2.5 w-2.5 shrink-0 rounded-full', dotClass)} />
                <span className="text-sm font-medium text-body">{label}</span>
                <Badge tone="accent" size="sm" className="capitalize">Billed {cycle || 'monthly'}</Badge>
                <Badge tone={tone} size="sm" className="ml-auto capitalize">
                    {on_trial ? 'Trial' : String(status).replace('_', ' ')}
                </Badge>
            </div>
        </Card>
    );
}

const TABS = [
    { key: 'plan', label: 'Plan & add-ons', icon: <CreditCard className="h-4 w-4" /> },
    { key: 'invoices', label: 'Invoices', icon: <Receipt className="h-4 w-4" /> },
    { key: 'settings', label: 'Subscription settings', icon: <Settings2 className="h-4 w-4" /> },
    { key: 'details', label: 'Billing details', icon: <Building2 className="h-4 w-4" /> },
];

/**
 * The customer billing hub — four tabs (plan & add-ons · invoices ·
 * subscription settings with a live invoice preview · billing details).
 *
 * ⚠️ NO PRICE IS COMPUTED HERE. Every amount, proration and total arrives as a
 * prop already derived by App\Services\BillingPricing from the plan/add-on rows
 * and the live seat count. Mutations post only an add-on KEY, a CYCLE or
 * address fields — never a number — so a tampered request cannot set its price.
 *
 * @param {boolean} [preview=false] Render fully from DEV_SAMPLE (offline QA),
 *        skipping all real props and network. Live behaviour is unchanged.
 */
export default function BillingIndex({
    auth,
    canManage = false,
    tab: initialTab = 'plan',
    subscription,
    seatsUsed,
    seatLimit,
    plans,
    addons,
    invoices,
    invoiceStatus,
    invoiceCounts,
    previews,
    billingDetails,
    bankDetails,
    supportEmail,
    cardCheckout,
    errors = {},
    preview = false,
}) {
    const pageProps = usePage().props;
    const pageFlash = pageProps.flash || {};
    const [tab, setTab] = useState(initialTab);
    const [busy, setBusy] = useState(false);

    const data = preview
        ? DEV_SAMPLE
        : {
            auth,
            subscription,
            seatsUsed,
            seatLimit,
            plans: plans || [],
            addons: addons || [],
            invoices,
            invoiceStatus,
            invoiceCounts,
            previews,
            billingDetails,
            bankDetails,
            supportEmail,
            cardCheckout,
        };
    const flash = preview ? DEV_SAMPLE.flash : pageFlash;
    const manage = preview ? DEV_SAMPLE.canManage : canManage;

    // Every mutation goes through here so `busy` is honest and no control can
    // be double-fired into two charges.
    const visit = (fn) => {
        if (preview) return;
        setBusy(true);
        fn({
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    };

    const choosePlan = (planKey) =>
        visit((opts) => router.post(route('billing.checkout'), { plan: planKey }, opts));

    // Only a KEY and a CYCLE leave the browser. The price is the server's.
    const addAddon = (addon, cycle) =>
        visit((opts) => router.post(route('billing.addons.request'), { addon: addon.key, cycle }, opts));

    const removeAddon = (addon) =>
        visit((opts) => router.delete(route('billing.addons.remove', addon.key), opts));

    const saveCycle = (cycle) =>
        visit((opts) => router.put(route('billing.cycle'), { cycle }, opts));

    const saveDetails = (form) =>
        visit((opts) => router.put(route('billing.details'), form, opts));

    const invoiceOnly = { only: ['invoices', 'invoiceCounts', 'invoiceStatus'], preserveState: true, preserveScroll: true };

    const filterInvoices = (status) => {
        if (preview) return;
        setBusy(true);
        router.get(
            route('billing.index'),
            { tab: 'invoices', invoice_status: status },
            { ...invoiceOnly, onFinish: () => setBusy(false) },
        );
    };

    const pageInvoices = (url) => {
        if (preview) return;
        setBusy(true);
        router.get(url, {}, { ...invoiceOnly, onFinish: () => setBusy(false) });
    };

    return (
        <AuthenticatedLayout user={data.auth?.user} header="Billing">
            <Head title="Billing" />

            <div className="mx-auto max-w-[1600px] space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                <StatusBanner subscription={data.subscription} />

                {(flash.info || flash.success || flash.error) && (
                    <div
                        className={cx(
                            'flex items-start gap-2 rounded-lg px-4 py-3 text-sm',
                            flash.error ? 'bg-danger-soft text-danger' : flash.success ? 'bg-success-soft text-success' : 'bg-info-soft text-info',
                        )}
                        role="status"
                    >
                        <span>{flash.error || flash.success || flash.info}</span>
                    </div>
                )}

                {/* No gateway is wired — say so once, plainly, at the top. */}
                {!data.cardCheckout && (
                    <div className="flex items-start gap-2 rounded-lg bg-warning-soft px-4 py-3 text-sm leading-6 text-warning">
                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                        <span>
                            Online card payments aren’t switched on yet. Plan changes and add-ons are recorded as
                            <strong> unpaid charges</strong> and go live once we confirm your transfer — nothing is ever
                            billed to you automatically.
                        </span>
                    </div>
                )}

                <div className="overflow-x-auto">
                    <Tabs tabs={TABS} value={tab} onChange={setTab} />
                </div>

                {tab === 'plan' && (
                    <PlanAddonsTab
                        data={data}
                        canManage={manage}
                        busy={busy}
                        onAddAddon={addAddon}
                        onRemoveAddon={removeAddon}
                        onChoosePlan={choosePlan}
                    />
                )}

                {tab === 'invoices' && (
                    <InvoicesTab
                        invoices={data.invoices}
                        invoiceStatus={data.invoiceStatus}
                        invoiceCounts={data.invoiceCounts}
                        busy={busy}
                        onFilter={filterInvoices}
                        onPage={pageInvoices}
                    />
                )}

                {tab === 'settings' && (
                    <SettingsTab data={data} canManage={manage} busy={busy} onSaveCycle={saveCycle} />
                )}

                {tab === 'details' && (
                    <DetailsTab
                        details={data.billingDetails}
                        canManage={manage}
                        busy={busy}
                        errors={preview ? {} : errors}
                        onSave={saveDetails}
                    />
                )}
            </div>
        </AuthenticatedLayout>
    );
}
