import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, CardHeader, Badge, LockGlyph } from '@/Components/ui';
import { ArrowLeft, Check, Sparkles } from 'lucide-react';

/**
 * UPGRADE / LOCKED — where `entitlement:<key>` lands a browser.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THIS PAGE IS THE REFUSAL, NOT A WAY AROUND IT
 * ─────────────────────────────────────────────────────────────────────────────
 * It is served by App\Http\Middleware\EnsureEntitlement with HTTP 403 instead of
 * a bare error screen. The guarded controller never ran, so there is no feature
 * data here and nothing on this page can be used to reach any. Every figure —
 * the plan name, the price, the add-on — is read from the catalogue row on the
 * server; the client cannot name a plan or a price.
 *
 * Its whole job is the "shown, not hidden" rule at page scale: say what is
 * missing, say what it unlocks, and offer the one-click way to get it.
 */

/** Cents → a display price. Integer money in, string out; never floats in state. */
function money(cents, currency = 'USD') {
    if (cents === null || cents === undefined) return null;
    const amount = Number(cents) / 100;
    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency,
            minimumFractionDigits: amount % 1 === 0 ? 0 : 2,
            maximumFractionDigits: 2,
        }).format(amount);
    } catch {
        return `${currency} ${amount.toFixed(2)}`;
    }
}

const DEV_SAMPLE = {
    auth: { user: { id: 1, name: 'Jawad', email: 'jawad@staffvora.com', is_super_admin: true, permissions: [] } },
    feature: 'machine_compliance',
    name: 'Machine compliance',
    blurb: 'Per-PC inventory of extensions, programs and VPNs, with a blocklist and a findings dashboard.',
    group: 'Features',
    message: 'Machine compliance is not included in your current plan.',
    billingUrl: '/billing',
    offers: {
        plan: { kind: 'plan', name: 'Growth', price_cents: 900, price_cents_yearly: 9000, currency: 'USD' },
        addon: {
            kind: 'addon',
            name: 'Agency toolkit',
            description: 'Profiles, bidding and machine compliance for agencies.',
            price_cents: 400,
            price_cents_yearly: 4000,
            currency: 'USD',
        },
    },
};

/** One purchasable route to the capability: a plan, or an add-on on top of it. */
function OfferCard({ offer, billingUrl, recommended }) {
    if (!offer) return null;

    const monthly = money(offer.price_cents, offer.currency);
    const yearly = money(offer.price_cents_yearly, offer.currency);
    const isAddon = offer.kind === 'addon';

    return (
        <Card
            className={recommended ? 'ring-1 ring-accent' : undefined}
            header={
                <CardHeader
                    title={
                        <span className="flex items-center gap-2">
                            {offer.name}
                            <Badge tone={isAddon ? 'info' : 'accent'}>
                                {isAddon ? 'Add-on' : 'Plan'}
                            </Badge>
                        </span>
                    }
                    description={
                        isAddon
                            ? offer.description || 'Adds this capability on top of your current plan.'
                            : 'Includes this capability outright.'
                    }
                    actions={recommended ? <Badge tone="success">Cheapest route</Badge> : null}
                />
            }
        >
            <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <span className="text-2xl font-semibold text-body">{monthly ?? '—'}</span>
                <span className="text-sm text-muted">per user / month</span>
            </div>
            {yearly ? (
                <p className="mt-1 text-sm text-muted">
                    or {yearly} per user / year
                </p>
            ) : (
                <p className="mt-1 text-sm text-muted">Yearly pricing on request.</p>
            )}

            <Button as={Link} href={billingUrl} className="mt-4" block>
                {isAddon ? `Add ${offer.name}` : `Switch to ${offer.name}`}
            </Button>
        </Card>
    );
}

/**
 * @param {object} props
 * @param {object} props.auth        Shared auth wrapper.
 * @param {string} props.feature     The entitlement key that was refused.
 * @param {string} props.name        Operator-facing label from the feature catalogue.
 * @param {string} [props.blurb]     Catalogue help text — what the capability does.
 * @param {string} [props.group]     Catalogue group, e.g. "Features" / "Monitoring".
 * @param {string} [props.message]   The refusal sentence the server produced.
 * @param {string} props.billingUrl  Where the upgrade CTA goes.
 * @param {{plan: ?object, addon: ?object}} [props.offers] Server-derived purchase routes.
 * @param {boolean} [props.preview=false] Render entirely from DEV_SAMPLE (offline QA).
 */
export default function UpgradeLocked({
    auth,
    feature,
    name,
    blurb,
    group,
    message,
    billingUrl,
    offers,
    preview = false,
}) {
    const s = preview ? DEV_SAMPLE : null;
    const user = s ? s.auth.user : auth?.user;
    const key = s ? s.feature : feature;
    const title = s ? s.name : name || 'This feature';
    const help = s ? s.blurb : blurb;
    const groupName = s ? s.group : group;
    const refusal = s ? s.message : message;
    const billing = (s ? s.billingUrl : billingUrl) || '/billing';
    const routes = (s ? s.offers : offers) || { plan: null, addon: null };

    // The cheaper of the two routes gets the "cheapest route" flag. Comparison
    // is on integer cents — never on a formatted string.
    const planCents = routes.plan ? Number(routes.plan.price_cents) : null;
    const addonCents = routes.addon ? Number(routes.addon.price_cents) : null;
    const addonWins =
        addonCents !== null && (planCents === null || addonCents < planCents);

    const hasOffer = !!routes.plan || !!routes.addon;

    return (
        <AuthenticatedLayout user={user} header={title}>
            <Head title={`${title} — upgrade required`} />

            <div className="mx-auto max-w-[1600px] space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                <Card>
                    <div className="flex flex-col gap-5 sm:flex-row sm:items-start">
                        <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-surface-2 text-subtle ring-1 ring-line">
                            <LockGlyph className="h-6 w-6" />
                        </span>

                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <h2 className="text-lg font-semibold text-body">
                                    {title} isn&apos;t in your plan
                                </h2>
                                {groupName ? <Badge>{groupName}</Badge> : null}
                            </div>

                            <p className="mt-1 max-w-2xl text-sm text-muted">
                                {help || refusal || 'This capability is available on a higher plan.'}
                            </p>

                            <p className="mt-3 text-xs text-subtle">
                                Nothing was changed and nothing was charged. Your workspace, your
                                people and your tracked time are untouched.
                            </p>

                            <div className="mt-4 flex flex-wrap items-center gap-2">
                                <Button as={Link} href={billing} icon={<Sparkles className="h-4 w-4" />}>
                                    See plans &amp; upgrade
                                </Button>
                                <Button
                                    variant="secondary"
                                    icon={<ArrowLeft className="h-4 w-4" />}
                                    onClick={() => {
                                        if (typeof window !== 'undefined') window.history.back();
                                    }}
                                >
                                    Go back
                                </Button>
                            </div>
                        </div>
                    </div>
                </Card>

                {hasOffer ? (
                    <div className="space-y-3">
                        <h3 className="text-sm font-semibold uppercase tracking-wide text-subtle">
                            How to unlock {title.toLowerCase()}
                        </h3>
                        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                            <OfferCard
                                offer={routes.addon}
                                billingUrl={billing}
                                recommended={addonWins}
                            />
                            <OfferCard
                                offer={routes.plan}
                                billingUrl={billing}
                                recommended={!addonWins && !!routes.plan}
                            />
                        </div>
                        <p className="text-xs text-subtle">
                            Prices are per user and come from the published catalogue. The exact
                            amount for your workspace is confirmed on the billing page before
                            anything is charged.
                        </p>
                    </div>
                ) : (
                    <Card>
                        <div className="flex items-start gap-3">
                            <Check className="mt-0.5 h-4 w-4 shrink-0 text-subtle" />
                            <p className="text-sm text-muted">
                                This capability isn&apos;t self-serve right now. Talk to us and
                                we&apos;ll switch it on for your workspace.
                            </p>
                        </div>
                    </Card>
                )}

                {key ? (
                    <p className="text-xs text-subtle">
                        Reference: <code className="rounded bg-surface-2 px-1.5 py-0.5">{key}</code>
                    </p>
                ) : null}
            </div>
        </AuthenticatedLayout>
    );
}
