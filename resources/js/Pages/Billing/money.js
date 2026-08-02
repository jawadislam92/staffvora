/**
 * Display formatters for the billing hub.
 *
 * ⚠️ FORMATTING ONLY. Nothing in the billing UI computes a price, a proration
 * or a total — every figure is derived server-side by App\Services\BillingPricing
 * and shipped as a prop. These helpers turn integer cents into text and nothing
 * more, so there is no second (and inevitably divergent) pricing implementation
 * living in the browser.
 */

/** Integer cents → "$9.00". `null` cents means "no list price", not zero. */
export const money = (cents, currency = 'USD') => {
    if (cents === null || cents === undefined) return null;
    try {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currency || 'USD',
            minimumFractionDigits: cents % 100 === 0 ? 0 : 2,
        }).format(cents / 100);
    } catch {
        return `${currency || 'USD'} ${(cents / 100).toFixed(2)}`;
    }
};

/** "$9.00" or the given fallback when the cycle carries no list price. */
export const moneyOr = (cents, currency, fallback = 'Contact us') =>
    money(cents, currency) ?? fallback;

/** A per-seat price line: "$4 / seat / mo". */
export const perSeat = (cents, currency, cycle) =>
    money(cents, currency) === null
        ? null
        : `${money(cents, currency)} / seat / ${cycle === 'yearly' ? 'yr' : 'mo'}`;

/** "2026-08-15" → "15 Aug 2026". Dates arrive as plain Y-m-d strings. */
export const shortDate = (value) => {
    if (!value) return null;
    const d = new Date(`${value}T00:00:00`);
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
};
