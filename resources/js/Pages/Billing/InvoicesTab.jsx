import { Card, Badge, Table, THead, TBody, Tr, Th, Td, EmptyState, Segmented, Button } from '@/Components/ui';
import { Receipt } from 'lucide-react';
import { money, shortDate } from './money';

const cx = (...a) => a.filter(Boolean).join(' ');

/**
 * Paginator labels arrive as HTML entities ("&laquo; Previous"). We decode the
 * two we actually emit and strip anything else to plain text — deliberately NOT
 * dangerouslySetInnerHTML, so nothing in this table can ever inject markup.
 */
const pageLabel = (label = '') =>
    String(label)
        .replace(/&laquo;/g, '«')
        .replace(/&raquo;/g, '»')
        .replace(/<[^>]*>/g, '')
        .trim();

const STATUS = {
    confirmed: { tone: 'success', label: 'Paid' },
    pending: { tone: 'warning', label: 'Unpaid' },
    canceled: { tone: 'neutral', label: 'Withdrawn' },
};

/**
 * Tab 2 — Invoices.
 *
 * The whole history, PAGINATED IN SQL (never sliced in the browser) and
 * filterable All / Paid / Unpaid. An "Unpaid" row is real money owed — an
 * add-on request waiting for an operator to confirm the transfer — and it is
 * labelled as such rather than shown as if it were live.
 */
export default function InvoicesTab({ invoices, invoiceStatus, invoiceCounts, onFilter, onPage, busy }) {
    const rows = invoices?.data || [];
    const links = invoices?.links || [];
    const meta = invoices?.meta || invoices || {};

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <Segmented
                    value={invoiceStatus || 'all'}
                    onChange={onFilter}
                    tabs={[
                        { key: 'all', label: `All (${invoiceCounts?.all ?? 0})` },
                        { key: 'paid', label: `Paid (${invoiceCounts?.paid ?? 0})` },
                        { key: 'unpaid', label: `Unpaid (${invoiceCounts?.unpaid ?? 0})` },
                    ]}
                />
                <p className="text-xs text-muted">
                    Seat counts on an invoice can take up to 24 hours to reflect people added mid-period.
                </p>
            </div>

            {rows.length === 0 ? (
                <Card padded={false}>
                    <EmptyState
                        icon={<Receipt className="h-6 w-6" />}
                        title="No invoices yet"
                        description="Payments and add-on charges will appear here as soon as there are any."
                    />
                </Card>
            ) : (
                <Card padded={false}>
                    {/* Table brings its own overflow-x wrapper, so the page body
                        never scrolls sideways on a narrow screen. */}
                    <Table>
                            <THead>
                                <Tr>
                                    <Th>Date</Th>
                                    <Th>Item</Th>
                                    <Th>Reference</Th>
                                    <Th>Period</Th>
                                    <Th className="text-right">Amount</Th>
                                    <Th className="text-right">Status</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {rows.map((row) => {
                                    const s = STATUS[row.status] || { tone: 'neutral', label: row.status };
                                    return (
                                        <Tr key={row.id}>
                                            <Td className="whitespace-nowrap text-muted">
                                                {shortDate(row.paid_at || row.issued_at)}
                                            </Td>
                                            <Td>
                                                <span className="font-medium text-body">{row.label}</span>
                                                {row.seats ? (
                                                    <span className="block text-xs text-subtle">
                                                        {row.seats} seat{row.seats === 1 ? '' : 's'}
                                                        {row.unit_price_cents != null
                                                            ? ` × ${money(row.unit_price_cents, row.currency)}`
                                                            : ''}
                                                        {row.billing_cycle ? ` · ${row.billing_cycle}` : ''}
                                                    </span>
                                                ) : null}
                                            </Td>
                                            <Td className="whitespace-nowrap text-subtle">{row.reference || '—'}</Td>
                                            <Td className="whitespace-nowrap text-muted">
                                                {row.period_end ? `to ${shortDate(row.period_end)}` : '—'}
                                            </Td>
                                            <Td className="whitespace-nowrap text-right font-medium tabular-nums text-body">
                                                {money(row.amount_cents, row.currency)}
                                            </Td>
                                            <Td className="text-right">
                                                <Badge tone={s.tone} size="sm">{s.label}</Badge>
                                            </Td>
                                        </Tr>
                                    );
                                })}
                            </TBody>
                        </Table>

                    {links.length > 3 && (
                        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-line px-4 py-3">
                            <span className="text-xs text-muted">
                                {meta.from ?? 0}–{meta.to ?? 0} of {meta.total ?? rows.length}
                            </span>
                            <div className="flex flex-wrap gap-1">
                                {links.map((link, i) => (
                                    <Button
                                        key={i}
                                        size="sm"
                                        variant={link.active ? 'primary' : 'ghost'}
                                        disabled={!link.url || busy}
                                        onClick={() => link.url && onPage(link.url)}
                                        className={cx(!link.url && 'opacity-50')}
                                    >
                                        {pageLabel(link.label)}
                                    </Button>
                                ))}
                            </div>
                        </div>
                    )}
                </Card>
            )}
        </div>
    );
}
