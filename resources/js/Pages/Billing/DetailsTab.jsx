import { useState } from 'react';
import { Button, Card, Field, Input, Textarea } from '@/Components/ui';
import { Info } from 'lucide-react';

/**
 * Tab 4 — Billing details.
 *
 * The company's bill-to name, tax id and address: what gets printed on an
 * invoice, and where invoices are emailed. Presentation data only — nothing
 * here is ever consulted by a gate or a price.
 *
 * The kit `Input` is not forwardRef, so this form is plain controlled state.
 */
export default function DetailsTab({ details, canManage, busy, errors = {}, onSave }) {
    const [form, setForm] = useState({
        billing_name: details?.billing_name || '',
        billing_email: details?.billing_email || '',
        tax_id: details?.tax_id || '',
        address_line1: details?.address_line1 || '',
        address_line2: details?.address_line2 || '',
        city: details?.city || '',
        state: details?.state || '',
        postal_code: details?.postal_code || '',
        country: details?.country || '',
        notes: details?.notes || '',
    });

    const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));

    const submit = (e) => {
        e.preventDefault();
        if (!canManage) return;
        onSave(form);
    };

    const preview = [
        form.address_line1,
        form.address_line2,
        [form.city, form.state, form.postal_code].filter(Boolean).join(' '),
        form.country,
    ].filter((line) => line && line.trim());

    return (
        <form onSubmit={submit} className="grid gap-6 lg:grid-cols-5">
            <div className="lg:col-span-3">
                <Card title="Bill to" description="These details appear on every invoice we issue you.">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Company / billing name" error={errors.billing_name} className="sm:col-span-2">
                            <Input
                                value={form.billing_name}
                                onChange={set('billing_name')}
                                disabled={!canManage}
                                placeholder="Acme Pvt Ltd"
                            />
                        </Field>

                        <Field label="Invoice email" error={errors.billing_email} hint="Where we send invoices and receipts.">
                            <Input
                                type="email"
                                value={form.billing_email}
                                onChange={set('billing_email')}
                                disabled={!canManage}
                                placeholder="accounts@acme.com"
                            />
                        </Field>

                        <Field label="Tax ID" error={errors.tax_id} hint="NTN, VAT or GST number — optional.">
                            <Input value={form.tax_id} onChange={set('tax_id')} disabled={!canManage} />
                        </Field>

                        <Field label="Address line 1" error={errors.address_line1} className="sm:col-span-2">
                            <Input value={form.address_line1} onChange={set('address_line1')} disabled={!canManage} />
                        </Field>

                        <Field label="Address line 2" error={errors.address_line2} className="sm:col-span-2">
                            <Input value={form.address_line2} onChange={set('address_line2')} disabled={!canManage} />
                        </Field>

                        <Field label="City" error={errors.city}>
                            <Input value={form.city} onChange={set('city')} disabled={!canManage} />
                        </Field>

                        <Field label="State / province" error={errors.state}>
                            <Input value={form.state} onChange={set('state')} disabled={!canManage} />
                        </Field>

                        <Field label="Postal code" error={errors.postal_code}>
                            <Input value={form.postal_code} onChange={set('postal_code')} disabled={!canManage} />
                        </Field>

                        <Field label="Country code" error={errors.country} hint="Two letters, e.g. PK or GB.">
                            <Input
                                value={form.country}
                                onChange={(e) => setForm((f) => ({ ...f, country: e.target.value.toUpperCase().slice(0, 2) }))}
                                disabled={!canManage}
                                maxLength={2}
                            />
                        </Field>

                        <Field
                            label="Invoice notes"
                            error={errors.notes}
                            hint="A PO number or anything else your finance team needs on the invoice."
                            className="sm:col-span-2"
                        >
                            <Textarea rows={3} value={form.notes} onChange={set('notes')} disabled={!canManage} />
                        </Field>
                    </div>

                    <div className="mt-5 flex items-center gap-3 border-t border-line pt-4">
                        <Button type="submit" variant="primary" size="sm" disabled={!canManage || busy}>
                            Save billing details
                        </Button>
                        {!canManage && (
                            <span className="text-xs text-muted">
                                You need the billing permission to change these.
                            </span>
                        )}
                    </div>
                </Card>
            </div>

            <div className="lg:col-span-2">
                <Card title="How it will look" description="A live preview of the bill-to block on your invoice.">
                    <div className="rounded-lg border border-line bg-surface-2 p-4 text-sm leading-6">
                        <p className="font-semibold text-body">{form.billing_name || '—'}</p>
                        {preview.length > 0 ? (
                            preview.map((line, i) => (
                                <p key={i} className="text-muted">{line}</p>
                            ))
                        ) : (
                            <p className="text-subtle">No address on file</p>
                        )}
                        {form.tax_id && <p className="mt-2 text-muted">Tax ID: {form.tax_id}</p>}
                        {form.billing_email && <p className="text-muted">{form.billing_email}</p>}
                        {form.notes && <p className="mt-2 whitespace-pre-line text-subtle">{form.notes}</p>}
                    </div>

                    <p className="mt-3 flex items-start gap-2 text-xs leading-5 text-muted">
                        <Info className="mt-0.5 h-4 w-4 shrink-0" />
                        <span>
                            These details are for your invoices only. They never affect your plan, your add-ons or what
                            anyone in the workspace can access.
                        </span>
                    </p>
                </Card>
            </div>
        </form>
    );
}
