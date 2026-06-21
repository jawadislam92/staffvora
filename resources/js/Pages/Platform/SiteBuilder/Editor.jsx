import { useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import {
    DndContext, closestCenter, PointerSensor, KeyboardSensor, useSensor, useSensors,
} from '@dnd-kit/core';
import {
    SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy, arrayMove,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { SCHEMAS, BLOCK_TYPES, newBlock, blankItem } from '@/Components/Site/blockSchemas';

const input = 'w-full rounded-lg border-line bg-surface-2 text-body text-sm focus:border-accent focus:ring-accent';
const label = 'block text-xs font-semibold text-muted mb-1';

/* ---------- image upload field (S3) ---------- */
function ImageField({ value, onChange }) {
    const [busy, setBusy] = useState(false);
    const upload = async (file) => {
        if (!file) return;
        setBusy(true);
        try {
            const fd = new FormData();
            fd.append('image', file);
            const { data } = await axios.post(route('platform.site.image'), fd);
            onChange(data.url);
        } finally {
            setBusy(false);
        }
    };
    return (
        <div className="flex items-center gap-3">
            {value && <img src={value} alt="" className="h-12 w-12 rounded-lg object-cover ring-1 ring-line" />}
            <label className="cursor-pointer rounded-lg border border-line bg-surface px-3 py-1.5 text-xs font-semibold text-muted hover:bg-surface-2">
                {busy ? 'Uploading…' : value ? 'Replace' : 'Upload'}
                <input type="file" accept="image/*" className="hidden" onChange={(e) => upload(e.target.files?.[0])} />
            </label>
            {value && <button type="button" onClick={() => onChange(null)} className="text-xs text-rose-400 hover:text-rose-300">Remove</button>}
        </div>
    );
}

/* ---------- one field, driven by schema ---------- */
function Field({ field, value, onChange }) {
    if (field.type === 'text') return <input className={input} value={value || ''} onChange={(e) => onChange(e.target.value)} />;
    if (field.type === 'textarea') return <textarea rows={2} className={input} value={value || ''} onChange={(e) => onChange(e.target.value)} />;
    if (field.type === 'html') return <textarea rows={6} className={`${input} font-mono text-xs`} value={value || ''} onChange={(e) => onChange(e.target.value)} />;
    if (field.type === 'select') return (
        <select className={input} value={value || field.options[0]} onChange={(e) => onChange(e.target.value)}>
            {field.options.map((o) => <option key={o} value={o}>{o}</option>)}
        </select>
    );
    if (field.type === 'image') return <ImageField value={value} onChange={onChange} />;
    if (field.type === 'cta') {
        const v = value || {};
        return (
            <div className="grid grid-cols-2 gap-2">
                <input className={input} placeholder="Button text" value={v.label || ''} onChange={(e) => onChange({ ...v, label: e.target.value })} />
                <input className={input} placeholder="/register or https://…" value={v.url || ''} onChange={(e) => onChange({ ...v, url: e.target.value })} />
            </div>
        );
    }
    if (field.type === 'stringlist') {
        const arr = Array.isArray(value) ? value : [];
        return (
            <div className="space-y-2">
                {arr.map((s, i) => (
                    <div key={i} className="flex gap-2">
                        <input className={input} value={s} onChange={(e) => { const n = [...arr]; n[i] = e.target.value; onChange(n); }} />
                        <button type="button" onClick={() => onChange(arr.filter((_, j) => j !== i))} className="px-2 text-rose-400">✕</button>
                    </div>
                ))}
                <button type="button" onClick={() => onChange([...arr, ''])} className="text-xs font-semibold text-accent">+ Add</button>
            </div>
        );
    }
    if (field.type === 'list') {
        const arr = Array.isArray(value) ? value : [];
        return (
            <div className="space-y-3">
                {arr.map((item, i) => (
                    <div key={i} className="rounded-lg border border-line bg-surface p-3">
                        <div className="mb-2 flex items-center justify-between">
                            <span className="text-[11px] font-semibold uppercase text-subtle">Item {i + 1}</span>
                            <button type="button" onClick={() => onChange(arr.filter((_, j) => j !== i))} className="text-xs text-rose-400">Remove</button>
                        </div>
                        <div className="space-y-2">
                            {field.item.map((sub) => (
                                <div key={sub.key}>
                                    <span className={label}>{sub.label}</span>
                                    <Field field={sub} value={item[sub.key]} onChange={(v) => { const n = [...arr]; n[i] = { ...item, [sub.key]: v }; onChange(n); }} />
                                </div>
                            ))}
                        </div>
                    </div>
                ))}
                <button type="button" onClick={() => onChange([...arr, blankItem(field.item)])} className="text-xs font-semibold text-accent">+ Add item</button>
            </div>
        );
    }
    return null;
}

/* ---------- one sortable block card ---------- */
function BlockCard({ block, onChange, onDuplicate, onDelete }) {
    const [open, setOpen] = useState(false);
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: block.id });
    const schema = SCHEMAS[block.type];
    const style = { transform: CSS.Transform.toString(transform), transition, opacity: isDragging ? 0.5 : 1 };

    return (
        <div ref={setNodeRef} style={style} className="rounded-xl border border-line bg-surface">
            <div className="flex items-center gap-2 px-3 py-2.5">
                <button type="button" {...attributes} {...listeners} className="cursor-grab px-1 text-subtle hover:text-body" title="Drag to reorder">
                    <svg className="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path d="M7 4a1 1 0 110 2 1 1 0 010-2zm6 0a1 1 0 110 2 1 1 0 010-2zM7 9a1 1 0 110 2 1 1 0 010-2zm6 0a1 1 0 110 2 1 1 0 010-2zM7 14a1 1 0 110 2 1 1 0 010-2zm6 0a1 1 0 110 2 1 1 0 010-2z" /></svg>
                </button>
                <button type="button" onClick={() => setOpen((o) => !o)} className="flex-1 text-left text-sm font-semibold text-body">
                    {schema?.label || block.type}
                </button>
                <button type="button" onClick={onDuplicate} className="rounded p-1 text-subtle hover:text-body" title="Duplicate">
                    <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><rect x="9" y="9" width="11" height="11" rx="2" /><path d="M5 15V5a2 2 0 012-2h10" /></svg>
                </button>
                <button type="button" onClick={onDelete} className="rounded p-1 text-subtle hover:text-rose-400" title="Delete">
                    <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" /></svg>
                </button>
                <button type="button" onClick={() => setOpen((o) => !o)} className="rounded p-1 text-subtle hover:text-body">
                    <svg className={`h-4 w-4 transition-transform ${open ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6" /></svg>
                </button>
            </div>
            {open && schema && (
                <div className="space-y-3 border-t border-line p-4">
                    <div>
                        <span className={label}>Section background</span>
                        <select className={input} value={block.data?._bg || ''} onChange={(e) => onChange({ ...block.data, _bg: e.target.value })}>
                            <option value="">Default (none)</option>
                            <option value="muted">Muted</option>
                            <option value="soft">Soft tint</option>
                            <option value="accent">Accent tint</option>
                        </select>
                    </div>
                    {schema.fields.map((f) => (
                        <div key={f.key}>
                            <span className={label}>{f.label}</span>
                            <Field field={f} value={block.data?.[f.key]} onChange={(v) => onChange({ ...block.data, [f.key]: v })} />
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

export default function Editor({ page }) {
    const [blocks, setBlocks] = useState(page.blocks || []);
    const [title, setTitle] = useState(page.title || '');
    const [metaTitle, setMetaTitle] = useState(page.meta_title || '');
    const [metaDescription, setMetaDescription] = useState(page.meta_description || '');
    const [adding, setAdding] = useState(false);
    const [saving, setSaving] = useState(false);

    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 5 } }), useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }));
    const ids = useMemo(() => blocks.map((b) => b.id), [blocks]);

    const onDragEnd = ({ active, over }) => {
        if (over && active.id !== over.id) {
            setBlocks((bs) => arrayMove(bs, bs.findIndex((b) => b.id === active.id), bs.findIndex((b) => b.id === over.id)));
        }
    };
    const updateBlock = (id, data) => setBlocks((bs) => bs.map((b) => (b.id === id ? { ...b, data } : b)));
    const duplicateBlock = (id) => setBlocks((bs) => { const i = bs.findIndex((b) => b.id === id); const copy = { ...bs[i], id: 'b' + Math.random().toString(36).slice(2, 10) }; const n = [...bs]; n.splice(i + 1, 0, copy); return n; });
    const deleteBlock = (id) => setBlocks((bs) => bs.filter((b) => b.id !== id));
    const addBlock = (type) => { setBlocks((bs) => [...bs, newBlock(type)]); setAdding(false); };

    const payload = () => ({ title, meta_title: metaTitle, meta_description: metaDescription, blocks });
    const saveDraft = () => { setSaving(true); router.put(route('platform.site.save', page.id), payload(), { preserveScroll: true, onFinish: () => setSaving(false) }); };
    const publish = () => { setSaving(true); router.put(route('platform.site.save', page.id), payload(), { preserveScroll: true, onSuccess: () => router.post(route('platform.site.publish', page.id), {}, { preserveScroll: true, onFinish: () => setSaving(false) }), onError: () => setSaving(false) }); };
    const liveUrl = page.slug === 'home' ? '/' : `/${page.slug}`;

    return (
        <PlatformLayout title={`Edit ${page.title}`} header={<h2 className="text-xl font-semibold text-white">Edit page — {page.title}</h2>}>
            <Head title={`Edit ${page.title}`} />
            <div className="mx-auto w-full max-w-3xl px-4 py-6 sm:px-6">
                <div className="mb-4 flex items-center justify-between">
                    <Link href={route('platform.site.index')} className="text-sm text-muted hover:text-body">← All pages</Link>
                    <div className="flex items-center gap-2">
                        <a href={liveUrl} target="_blank" rel="noreferrer" className="rounded-lg border border-line px-3 py-1.5 text-sm font-semibold text-muted hover:bg-surface-2">View live</a>
                        <button type="button" onClick={saveDraft} disabled={saving} className="rounded-lg border border-line px-3 py-1.5 text-sm font-semibold text-body hover:bg-surface-2 disabled:opacity-50">Save draft</button>
                        <button type="button" onClick={publish} disabled={saving} className="rounded-lg bg-accent px-4 py-1.5 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50">{saving ? 'Saving…' : 'Publish'}</button>
                    </div>
                </div>

                <div className="mb-5 space-y-3 rounded-xl border border-line bg-surface p-4">
                    <div><span className={label}>Page title (internal)</span><input className={input} value={title} onChange={(e) => setTitle(e.target.value)} /></div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div><span className={label}>SEO title</span><input className={input} value={metaTitle} onChange={(e) => setMetaTitle(e.target.value)} /></div>
                        <div><span className={label}>SEO description</span><input className={input} value={metaDescription} onChange={(e) => setMetaDescription(e.target.value)} /></div>
                    </div>
                </div>

                <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
                    <SortableContext items={ids} strategy={verticalListSortingStrategy}>
                        <div className="space-y-3">
                            {blocks.map((b) => (
                                <BlockCard key={b.id} block={b} onChange={(d) => updateBlock(b.id, d)} onDuplicate={() => duplicateBlock(b.id)} onDelete={() => deleteBlock(b.id)} />
                            ))}
                        </div>
                    </SortableContext>
                </DndContext>

                <div className="relative mt-4">
                    <button type="button" onClick={() => setAdding((a) => !a)} className="w-full rounded-xl border-2 border-dashed border-line py-3 text-sm font-semibold text-muted hover:border-accent hover:text-accent">+ Add block</button>
                    {adding && (
                        <div className="absolute z-10 mt-2 grid w-full grid-cols-2 gap-2 rounded-xl border border-line bg-surface p-3 shadow-xl sm:grid-cols-3">
                            {BLOCK_TYPES.map((t) => (
                                <button key={t.type} type="button" onClick={() => addBlock(t.type)} className="rounded-lg border border-line px-3 py-2 text-sm text-body hover:bg-surface-2">{t.label}</button>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </PlatformLayout>
    );
}
