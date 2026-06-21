import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import PlatformLayout from '@/Layouts/PlatformLayout';

const input = 'w-full rounded-lg border-line bg-surface-2 text-body text-sm focus:border-accent focus:ring-accent';
const lbl = 'block text-xs font-semibold text-muted mb-1';
const card = 'rounded-xl border border-line bg-surface p-5';

const SOCIAL_KEYS = ['linkedin', 'facebook', 'instagram', 'x', 'youtube'];

function LinkListEditor({ value, onChange }) {
    const arr = value || [];
    return (
        <div className="space-y-2">
            {arr.map((l, i) => (
                <div key={i} className="flex gap-2">
                    <input className={input} placeholder="Label" value={l.label || ''} onChange={(e) => { const n = [...arr]; n[i] = { ...l, label: e.target.value }; onChange(n); }} />
                    <input className={input} placeholder="/pricing or https://…" value={l.url || ''} onChange={(e) => { const n = [...arr]; n[i] = { ...l, url: e.target.value }; onChange(n); }} />
                    <button type="button" onClick={() => onChange(arr.filter((_, j) => j !== i))} className="px-2 text-rose-400">✕</button>
                </div>
            ))}
            <button type="button" onClick={() => onChange([...arr, { label: '', url: '' }])} className="text-xs font-semibold text-accent">+ Add link</button>
        </div>
    );
}

export default function Index({ pages, theme }) {
    const [t, setT] = useState(theme || {});
    const [savingTheme, setSavingTheme] = useState(false);
    const newPage = useForm({ title: '', slug: '' });

    const set = (k, v) => setT((p) => ({ ...p, [k]: v }));
    const setSocial = (k, v) => setT((p) => ({ ...p, social_links: { ...(p.social_links || {}), [k]: v } }));

    const saveTheme = () => {
        setSavingTheme(true);
        router.put(route('platform.site.theme'), {
            site_name: t.site_name, tagline: t.tagline,
            accent_color: t.accent_color, highlight_color: t.highlight_color,
            nav_links: t.nav_links || [], footer_columns: t.footer_columns || [],
            social_links: t.social_links || {},
            contact_email: t.contact_email, contact_phone: t.contact_phone, contact_address: t.contact_address,
        }, { preserveScroll: true, onFinish: () => setSavingTheme(false) });
    };

    const uploadLogo = (file) => { if (file) router.post(route('platform.site.logo'), { logo: file }, { forceFormData: true, preserveScroll: true }); };
    const createPage = (e) => { e.preventDefault(); newPage.post(route('platform.site.pages.create')); };
    const deletePage = (p) => { if (confirm(`Delete the “${p.title}” page?`)) router.delete(route('platform.site.pages.destroy', p.id)); };

    return (
        <PlatformLayout title="Website builder" header={<h2 className="text-xl font-semibold text-white">Website</h2>}>
            <Head title="Website builder" />
            <div className="mx-auto w-full max-w-4xl space-y-6 px-4 py-6 sm:px-6">
                <p className="text-sm text-muted">Edit your public marketing site — pages, text, images, colours, links and contact details — without touching code. Changes go live when you <span className="font-semibold text-body">Publish</span>.</p>

                {/* Pages */}
                <div className={card}>
                    <h2 className="mb-3 font-semibold text-body">Pages</h2>
                    <div className="divide-y divide-line">
                        {pages.map((p) => (
                            <div key={p.id} className="flex items-center justify-between py-2.5">
                                <div className="min-w-0">
                                    <span className="font-medium text-body">{p.title}</span>
                                    <span className="ml-2 text-xs text-subtle">/{p.slug === 'home' ? '' : p.slug}</span>
                                    <span className={`ml-2 rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase ${p.status === 'published' ? 'bg-emerald-500/15 text-emerald-400' : 'bg-amber-500/15 text-amber-400'}`}>{p.status}</span>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Link href={route('platform.site.edit', p.id)} className="rounded-lg bg-accent px-3 py-1.5 text-xs font-semibold text-white hover:opacity-90">Edit</Link>
                                    {p.slug !== 'home' && <button type="button" onClick={() => deletePage(p)} className="text-xs text-rose-400 hover:text-rose-300">Delete</button>}
                                </div>
                            </div>
                        ))}
                    </div>
                    <form onSubmit={createPage} className="mt-4 flex flex-wrap items-end gap-2 border-t border-line pt-4">
                        <div className="flex-1"><span className={lbl}>New page title</span><input className={input} value={newPage.data.title} onChange={(e) => newPage.setData('title', e.target.value)} placeholder="Pricing" /></div>
                        <div className="flex-1"><span className={lbl}>URL slug</span><input className={input} value={newPage.data.slug} onChange={(e) => newPage.setData('slug', e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '-'))} placeholder="pricing" /></div>
                        <button type="submit" disabled={newPage.processing} className="rounded-lg border border-line px-4 py-2 text-sm font-semibold text-body hover:bg-surface-2">Create</button>
                    </form>
                    {newPage.errors.slug && <p className="mt-1 text-xs text-rose-400">{newPage.errors.slug}</p>}
                </div>

                {/* Brand & colours */}
                <div className={card}>
                    <h2 className="mb-3 font-semibold text-body">Brand &amp; colours</h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div><span className={lbl}>Site name</span><input className={input} value={t.site_name || ''} onChange={(e) => set('site_name', e.target.value)} /></div>
                        <div><span className={lbl}>Tagline</span><input className={input} value={t.tagline || ''} onChange={(e) => set('tagline', e.target.value)} /></div>
                        <div>
                            <span className={lbl}>Accent colour</span>
                            <div className="flex items-center gap-2">
                                <input type="color" value={t.accent_color || '#f97316'} onChange={(e) => set('accent_color', e.target.value)} className="h-9 w-12 rounded border border-line bg-surface-2" />
                                <input className={input} value={t.accent_color || ''} onChange={(e) => set('accent_color', e.target.value)} />
                            </div>
                        </div>
                        <div>
                            <span className={lbl}>Highlight colour</span>
                            <div className="flex items-center gap-2">
                                <input type="color" value={t.highlight_color || '#f59e0b'} onChange={(e) => set('highlight_color', e.target.value)} className="h-9 w-12 rounded border border-line bg-surface-2" />
                                <input className={input} value={t.highlight_color || ''} onChange={(e) => set('highlight_color', e.target.value)} />
                            </div>
                        </div>
                        <div className="sm:col-span-2">
                            <span className={lbl}>Logo</span>
                            <label className="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-line bg-surface-2 px-3 py-2 text-sm font-semibold text-muted hover:bg-surface">
                                Upload logo
                                <input type="file" accept="image/*" className="hidden" onChange={(e) => uploadLogo(e.target.files?.[0])} />
                            </label>
                        </div>
                    </div>
                </div>

                {/* Header navigation */}
                <div className={card}>
                    <h2 className="mb-3 font-semibold text-body">Header navigation</h2>
                    <LinkListEditor value={t.nav_links} onChange={(v) => set('nav_links', v)} />
                </div>

                {/* Footer columns */}
                <div className={card}>
                    <h2 className="mb-3 font-semibold text-body">Footer columns</h2>
                    <div className="space-y-4">
                        {(t.footer_columns || []).map((col, i) => (
                            <div key={i} className="rounded-lg border border-line bg-surface-2 p-3">
                                <div className="mb-2 flex gap-2">
                                    <input className={input} placeholder="Column title" value={col.title || ''} onChange={(e) => { const n = [...t.footer_columns]; n[i] = { ...col, title: e.target.value }; set('footer_columns', n); }} />
                                    <button type="button" onClick={() => set('footer_columns', t.footer_columns.filter((_, j) => j !== i))} className="px-2 text-rose-400">✕</button>
                                </div>
                                <LinkListEditor value={col.links} onChange={(links) => { const n = [...t.footer_columns]; n[i] = { ...col, links }; set('footer_columns', n); }} />
                            </div>
                        ))}
                        <button type="button" onClick={() => set('footer_columns', [...(t.footer_columns || []), { title: '', links: [] }])} className="text-xs font-semibold text-accent">+ Add column</button>
                    </div>
                </div>

                {/* Socials + contact */}
                <div className={card}>
                    <h2 className="mb-3 font-semibold text-body">Social links &amp; contact</h2>
                    <div className="grid gap-3 sm:grid-cols-2">
                        {SOCIAL_KEYS.map((k) => (
                            <div key={k}><span className={`${lbl} capitalize`}>{k}</span><input className={input} value={(t.social_links || {})[k] || ''} onChange={(e) => setSocial(k, e.target.value)} placeholder={`https://…`} /></div>
                        ))}
                        <div><span className={lbl}>Contact email</span><input className={input} value={t.contact_email || ''} onChange={(e) => set('contact_email', e.target.value)} /></div>
                        <div><span className={lbl}>Phone</span><input className={input} value={t.contact_phone || ''} onChange={(e) => set('contact_phone', e.target.value)} /></div>
                        <div className="sm:col-span-2"><span className={lbl}>Address</span><input className={input} value={t.contact_address || ''} onChange={(e) => set('contact_address', e.target.value)} /></div>
                    </div>
                </div>

                <div className="sticky bottom-4 flex justify-end">
                    <button type="button" onClick={saveTheme} disabled={savingTheme} className="rounded-lg bg-accent px-6 py-2.5 text-sm font-semibold text-white shadow-lg hover:opacity-90 disabled:opacity-50">{savingTheme ? 'Saving…' : 'Save website settings'}</button>
                </div>
            </div>
        </PlatformLayout>
    );
}
