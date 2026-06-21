import { useForm } from '@inertiajs/react';

const inputCls = 'w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] px-4 py-2.5 text-[var(--text)] placeholder-[var(--subtle)] focus:border-[var(--a)] focus:ring-1 focus:ring-[var(--a)]';

function Field({ label, value, onChange, type = 'text', error, placeholder }) {
    return (
        <div>
            <label className="mb-1 block text-sm font-medium text-[var(--brand)]">{label}</label>
            <input type={type} value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} className={inputCls} />
            {error && <p className="mt-1 text-xs text-rose-500">{error}</p>}
        </div>
    );
}

function Method({ icon, label, value, href }) {
    if (!value) return null;
    const inner = (
        <div className="flex items-center gap-3 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-4 transition hover:border-[color-mix(in_srgb,var(--a)_40%,var(--line))]">
            <span className="flex h-10 w-10 items-center justify-center rounded-full text-white" style={{ background: 'linear-gradient(135deg,var(--g-from),var(--g-to))' }}>{icon}</span>
            <div>
                <div className="text-xs font-medium text-[var(--subtle)]">{label}</div>
                <div className="text-sm font-bold text-[var(--brand)]">{value}</div>
            </div>
        </div>
    );
    return href ? <a href={href} target="_blank" rel="noreferrer">{inner}</a> : inner;
}

export default function ContactBlock({ data, brand }) {
    const form = useForm({ name: '', company: '', email: '', team_size: '', message: '' });
    const submit = (e) => { e.preventDefault(); form.post('/contact', { preserveScroll: true }); };
    const wa = (brand?.whatsapp || '').replace(/\D/g, '');

    return (
        <section className="py-16 sm:py-24">
            <div className="mx-auto grid max-w-[1300px] gap-8 px-6 sm:px-10 lg:grid-cols-[1.2fr_1fr]">
                <div className="rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-8 shadow-sm">
                    {form.wasSuccessful ? (
                        <div className="py-12 text-center">
                            <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full text-2xl text-white" style={{ background: 'linear-gradient(135deg,var(--g-from),var(--g-to))' }}>✓</div>
                            <h3 className="text-xl font-bold text-[var(--brand)]">Thanks — we'll be in touch.</h3>
                            <p className="mt-2 text-[var(--muted)]">We usually reply within one business day.</p>
                        </div>
                    ) : (
                        <form onSubmit={submit} className="space-y-4">
                            <h3 className="text-xl font-bold text-[var(--brand)]">{data.form_heading || 'Send us a message'}</h3>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Name" value={form.data.name} onChange={(v) => form.setData('name', v)} error={form.errors.name} />
                                <Field label="Company" value={form.data.company} onChange={(v) => form.setData('company', v)} />
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Work email" type="email" value={form.data.email} onChange={(v) => form.setData('email', v)} error={form.errors.email} />
                                <Field label="Team size" value={form.data.team_size} onChange={(v) => form.setData('team_size', v)} placeholder="e.g. 10–50" />
                            </div>
                            <div>
                                <label className="mb-1 block text-sm font-medium text-[var(--brand)]">Message</label>
                                <textarea value={form.data.message} onChange={(e) => form.setData('message', e.target.value)} rows={4} className={inputCls} />
                                {form.errors.message && <p className="mt-1 text-xs text-rose-500">{form.errors.message}</p>}
                            </div>
                            <button type="submit" disabled={form.processing} className="btn-primary rounded-full px-6 py-3 text-sm font-semibold disabled:opacity-60">{form.processing ? 'Sending…' : 'Send message'}</button>
                        </form>
                    )}
                </div>

                <div className="space-y-3">
                    {brand?.demo_url && (
                        <a href={brand.demo_url} target="_blank" rel="noreferrer" className="block rounded-2xl p-5 text-white shadow-lg" style={{ background: 'linear-gradient(135deg,var(--g-from),var(--g-to))' }}>
                            <div className="text-sm font-medium opacity-90">Prefer a walkthrough?</div>
                            <div className="mt-0.5 text-lg font-bold">Book a live demo →</div>
                        </a>
                    )}
                    <Method icon={<svg className="h-5 w-5" fill="currentColor" viewBox="0 0 24 24"><path d="M.06 24l1.7-6.2A11.9 11.9 0 1112 24a11.9 11.9 0 01-5.9-1.6L.06 24zM6.6 20.1l.4.2a9.9 9.9 0 005 1.4 9.9 9.9 0 10-9.9-9.9c0 1.9.5 3.6 1.5 5.2l.3.4-1 3.6 3.7-.9z" /></svg>} label="WhatsApp" value={wa ? 'Chat with us' : ''} href={wa ? `https://wa.me/${wa}` : null} />
                    <Method icon={<svg className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 7l9 6 9-6" /></svg>} label="Email" value={brand?.support_email} href={brand?.support_email ? `mailto:${brand.support_email}` : null} />
                    <Method icon={<svg className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M22 16.9v3a2 2 0 01-2.2 2 19.8 19.8 0 01-8.6-3 19.5 19.5 0 01-6-6 19.8 19.8 0 01-3-8.6A2 2 0 014.1 2h3a2 2 0 012 1.7c.1.9.4 1.8.7 2.6a2 2 0 01-.5 2.1L8.1 9.9a16 16 0 006 6l1.5-1.2a2 2 0 012.1-.5c.8.3 1.7.6 2.6.7a2 2 0 011.7 2z" /></svg>} label="Phone" value={brand?.phone} href={brand?.phone ? `tel:${brand.phone}` : null} />
                    <Method icon={<svg className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M12 21s7-6.2 7-11a7 7 0 10-14 0c0 4.8 7 11 7 11z" /><circle cx="12" cy="10" r="2.5" /></svg>} label="Office" value={brand?.address} />
                </div>
            </div>
        </section>
    );
}
