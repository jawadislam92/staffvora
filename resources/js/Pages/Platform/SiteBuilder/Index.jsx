import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import PlatformLayout, { PlatformPageHeader } from '@/Layouts/PlatformLayout';
import { Badge } from '@/Components/ui';

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
                    <input aria-label="Link label" className={input} placeholder="Label" value={l.label || ''} onChange={(e) => { const n = [...arr]; n[i] = { ...l, label: e.target.value }; onChange(n); }} />
                    <input aria-label="Link URL" className={input} placeholder="/pricing or https://…" value={l.url || ''} onChange={(e) => { const n = [...arr]; n[i] = { ...l, url: e.target.value }; onChange(n); }} />
                    <button type="button" aria-label="Remove link" onClick={() => onChange(arr.filter((_, j) => j !== i))} className="px-2 text-danger hover:underline">✕</button>
                </div>
            ))}
            <button type="button" onClick={() => onChange([...arr, { label: '', url: '' }])} className="text-xs font-semibold text-accent">+ Add link</button>
        </div>
    );
}

function ColorRow({ label, value, onChange }) {
    return (
        <div className="mb-2 flex items-center gap-2">
            <input type="color" aria-label={`${label} colour picker`} value={value || '#888888'} onChange={(e) => onChange(e.target.value)} className="h-8 w-9 shrink-0 rounded border border-line bg-surface-2" />
            <input aria-label={label} className={input} placeholder={label} value={value || ''} onChange={(e) => onChange(e.target.value)} />
            {value && <button type="button" aria-label={`Clear ${label}`} onClick={() => onChange('')} className="shrink-0 px-1 text-xs text-muted hover:text-danger">✕</button>}
        </div>
    );
}

export default function Index({ pages, theme, palettes = [], fonts = [], editableColors = {} }) {
    const [t, setT] = useState(theme || {});
    const [savingTheme, setSavingTheme] = useState(false);
    const newPage = useForm({ title: '', slug: '' });

    const set = (k, v) => setT((p) => ({ ...p, [k]: v }));
    const setSocial = (k, v) => setT((p) => ({ ...p, social_links: { ...(p.social_links || {}), [k]: v } }));
    const setPalette = (mode, key, v) => setT((p) => ({
        ...p,
        palette: { ...(p.palette || {}), [mode]: { ...((p.palette || {})[mode] || {}), [key]: v } },
    }));

    const saveTheme = () => {
        setSavingTheme(true);
        router.put(route('platform.site.theme'), {
            site_name: t.site_name, tagline: t.tagline,
            accent_color: t.accent_color, highlight_color: t.highlight_color,
            palette_preset: t.palette_preset || null, palette: t.palette || {}, font: t.font || null,
            nav_links: t.nav_links || [], footer_columns: t.footer_columns || [],
            social_links: t.social_links || {},
            contact_email: t.contact_email, contact_phone: t.contact_phone, contact_address: t.contact_address,
        }, { preserveScroll: true, onFinish: () => setSavingTheme(false) });
    };

    const uploadLogo = (file) => { if (file) router.post(route('platform.site.logo'), { logo: file }, { forceFormData: true, preserveScroll: true }); };
    const createPage = (e) => { e.preventDefault(); newPage.post(route('platform.site.pages.create')); };
    const deletePage = (p) => { if (confirm(`Delete the “${p.title}” page?`)) router.delete(route('platform.site.pages.destroy', p.id)); };

    return (
        <PlatformLayout
            title="Website builder"
            header={<PlatformPageHeader title="Website" description="Edit your public marketing site — pages, text, images, colours, links and contact details — without touching code. Changes go live when you Publish." />}
        >
            <Head title="Website builder" />
            <div className="w-full max-w-5xl space-y-6">

                {/* Pages */}
                <div className={card}>
                    <h2 className="mb-3 font-semibold text-body">Pages</h2>
                    <div className="divide-y divide-line">
                        {pages.map((p) => (
                            <div key={p.id} className="flex items-center justify-between py-2.5">
                                <div className="min-w-0">
                                    <span className="font-medium text-body">{p.title}</span>
                                    <span className="ml-2 text-xs text-muted">/{p.slug === 'home' ? '' : p.slug}</span>
                                    <Badge tone={p.status === 'published' ? 'success' : 'warning'} className="ml-2 uppercase">{p.status}</Badge>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Link href={route('platform.site.edit', p.id)} className="rounded-lg bg-accent px-3 py-1.5 text-xs font-semibold text-white hover:opacity-90">Edit</Link>
                                    {p.slug !== 'home' && <button type="button" onClick={() => deletePage(p)} className="text-xs font-medium text-danger hover:underline">Delete</button>}
                                </div>
                            </div>
                        ))}
                    </div>
                    <form onSubmit={createPage} className="mt-4 flex flex-wrap items-end gap-2 border-t border-line pt-4">
                        <div className="flex-1"><span className={lbl}>New page title</span><input className={input} value={newPage.data.title} onChange={(e) => newPage.setData('title', e.target.value)} placeholder="Pricing" /></div>
                        <div className="flex-1"><span className={lbl}>URL slug</span><input className={input} value={newPage.data.slug} onChange={(e) => newPage.setData('slug', e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '-'))} placeholder="pricing" /></div>
                        <button type="submit" disabled={newPage.processing} className="rounded-lg border border-line px-4 py-2 text-sm font-semibold text-body hover:bg-surface-2">Create</button>
                    </form>
                    {newPage.errors.slug && <p className="mt-1 text-xs text-danger">{newPage.errors.slug}</p>}
                </div>

                {/* Brand */}
                <div className={card}>
                    <h2 className="mb-3 font-semibold text-body">Brand</h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div><span className={lbl}>Site name</span><input className={input} value={t.site_name || ''} onChange={(e) => set('site_name', e.target.value)} /></div>
                        <div><span className={lbl}>Tagline</span><input className={input} value={t.tagline || ''} onChange={(e) => set('tagline', e.target.value)} /></div>
                        <div className="sm:col-span-2">
                            <span className={lbl}>Logo</span>
                            <label className="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-line bg-surface-2 px-3 py-2 text-sm font-semibold text-muted hover:bg-surface">
                                Upload logo
                                <input type="file" accept="image/*" className="hidden" onChange={(e) => uploadLogo(e.target.files?.[0])} />
                            </label>
                        </div>
                    </div>
                </div>

                {/* Appearance — colour theme + font + fine-tune */}
                <div className={card}>
                    <h2 className="font-semibold text-body">Appearance</h2>
                    <p className="mb-4 mt-1 text-xs text-muted">Pick a colour theme and font for your whole site (light &amp; dark). Changes go live when you Save.</p>

                    <span className={lbl}>Colour theme</span>
                    <div className="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-3">
                        {palettes.map((p) => (
                            <button key={p.id} type="button" onClick={() => set('palette_preset', p.id)}
                                className={`rounded-xl border p-2.5 text-left transition ${t.palette_preset === p.id ? 'border-accent ring-2 ring-accent/40' : 'border-line hover:border-accent/60'}`}>
                                <div className="h-10 w-full rounded-lg" style={{ background: `linear-gradient(135deg, ${p.from}, ${p.to})` }} />
                                <div className="mt-2 text-xs font-semibold text-body">{p.label}</div>
                            </button>
                        ))}
                    </div>

                    <div className="mt-5 max-w-xs">
                        <span className={lbl}>Font</span>
                        <select className={input} value={t.font || 'Hanken Grotesk'} onChange={(e) => set('font', e.target.value)}>
                            {fonts.map((f) => <option key={f} value={f}>{f}</option>)}
                        </select>
                    </div>

                    <details className="mt-5">
                        <summary className="cursor-pointer text-xs font-semibold text-accent">Fine-tune individual colours (optional)</summary>
                        <p className="mb-3 mt-2 text-xs text-muted">Override specific colours on top of the theme. Leave a field blank to keep the theme’s colour.</p>
                        <div className="grid gap-5 sm:grid-cols-2">
                            <div>
                                <div className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-subtle">Light mode</div>
                                {Object.entries(editableColors).map(([key, label]) => (
                                    <ColorRow key={key} label={label} value={(t.palette?.light || {})[key]} onChange={(v) => setPalette('light', key, v)} />
                                ))}
                            </div>
                            <div>
                                <div className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-subtle">Dark mode</div>
                                {Object.entries(editableColors).map(([key, label]) => (
                                    <ColorRow key={key} label={label} value={(t.palette?.dark || {})[key]} onChange={(v) => setPalette('dark', key, v)} />
                                ))}
                            </div>
                        </div>
                    </details>
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
                                    <input aria-label="Footer column title" className={input} placeholder="Column title" value={col.title || ''} onChange={(e) => { const n = [...t.footer_columns]; n[i] = { ...col, title: e.target.value }; set('footer_columns', n); }} />
                                    <button type="button" aria-label="Remove footer column" onClick={() => set('footer_columns', t.footer_columns.filter((_, j) => j !== i))} className="px-2 text-danger hover:underline">✕</button>
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
