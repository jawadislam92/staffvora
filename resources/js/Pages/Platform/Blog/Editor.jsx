import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import axios from 'axios';
import PlatformLayout from '@/Layouts/PlatformLayout';

const input = 'w-full rounded-lg border-line bg-surface-2 text-body text-sm focus:border-accent focus:ring-accent';
const label = 'block text-xs font-semibold text-muted mb-1';
const slugify = (s) => s.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

export default function BlogEditor({ post }) {
    const isNew = !post.id;
    const form = useForm({
        title: post.title || '', slug: post.slug || '', excerpt: post.excerpt || '', body: post.body || '',
        cover_path: post.cover_path || '', author_name: post.author_name || '', category: post.category || '',
        meta_title: post.meta_title || '', meta_description: post.meta_description || '',
    });
    const [touchedSlug, setTouchedSlug] = useState(!isNew);
    const [busy, setBusy] = useState(false);

    const setTitle = (v) => { form.setData('title', v); if (!touchedSlug) form.setData('slug', slugify(v)); };
    const uploadCover = async (file) => {
        if (!file) return;
        setBusy(true);
        try {
            const fd = new FormData();
            fd.append('image', file);
            const { data } = await axios.post(route('platform.site.image'), fd);
            form.setData('cover_path', data.url);
        } finally { setBusy(false); }
    };

    const save = () => (isNew
        ? form.post(route('platform.blog.store'))
        : form.put(route('platform.blog.update', post.id), { preserveScroll: true }));

    const publish = () => {
        if (isNew) {
            form.transform((d) => ({ ...d, publish: 1 })).post(route('platform.blog.store'), { onFinish: () => form.transform((d) => d) });
            return;
        }
        form.put(route('platform.blog.update', post.id), {
            preserveScroll: true,
            onSuccess: () => router.post(route('platform.blog.publish', post.id), {}, { preserveScroll: true }),
        });
    };
    const unpublish = () => router.post(route('platform.blog.unpublish', post.id), {}, { preserveScroll: true });
    const remove = () => { if (confirm('Delete this post?')) router.delete(route('platform.blog.destroy', post.id)); };

    return (
        <PlatformLayout title={isNew ? 'New post' : 'Edit post'} header={<h2 className="text-xl font-semibold text-white">{isNew ? 'New post' : 'Edit post'}</h2>}>
            <Head title={isNew ? 'New post' : 'Edit post'} />
            <div className="mx-auto w-full max-w-3xl px-4 py-6 sm:px-6">
                <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <Link href={route('platform.blog.index')} className="text-sm text-muted hover:text-body">← All posts</Link>
                    <div className="flex items-center gap-2">
                        {!isNew && post.status === 'published' && <a href={`/blog/${post.slug}`} target="_blank" rel="noreferrer" className="rounded-lg border border-line px-3 py-1.5 text-sm font-semibold text-muted hover:bg-surface-2">View live</a>}
                        {!isNew && post.status === 'published' && <button type="button" onClick={unpublish} className="rounded-lg border border-line px-3 py-1.5 text-sm font-semibold text-body hover:bg-surface-2">Unpublish</button>}
                        <button type="button" onClick={save} disabled={form.processing} className="rounded-lg border border-line px-3 py-1.5 text-sm font-semibold text-body hover:bg-surface-2 disabled:opacity-50">Save draft</button>
                        <button type="button" onClick={publish} disabled={form.processing} className="rounded-lg bg-accent px-4 py-1.5 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50">Publish</button>
                    </div>
                </div>

                <div className="space-y-4 rounded-xl border border-line bg-surface p-5">
                    <div><span className={label}>Title</span><input className={input} value={form.data.title} onChange={(e) => setTitle(e.target.value)} /></div>
                    {form.errors.title && <p className="-mt-2 text-xs text-rose-400">{form.errors.title}</p>}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div><span className={label}>URL slug</span><input className={input} value={form.data.slug} onChange={(e) => { setTouchedSlug(true); form.setData('slug', slugify(e.target.value)); }} /></div>
                        <div><span className={label}>Category</span><input className={input} value={form.data.category} onChange={(e) => form.setData('category', e.target.value)} placeholder="Guides" /></div>
                    </div>
                    {form.errors.slug && <p className="-mt-2 text-xs text-rose-400">{form.errors.slug}</p>}
                    <div><span className={label}>Author</span><input className={input} value={form.data.author_name} onChange={(e) => form.setData('author_name', e.target.value)} /></div>
                    <div>
                        <span className={label}>Cover image</span>
                        <div className="flex items-center gap-3">
                            {form.data.cover_path && <img src={form.data.cover_path} alt="" className="h-12 w-20 rounded object-cover ring-1 ring-line" />}
                            <label className="cursor-pointer rounded-lg border border-line bg-surface-2 px-3 py-1.5 text-xs font-semibold text-muted hover:bg-surface">
                                {busy ? 'Uploading…' : form.data.cover_path ? 'Replace' : 'Upload'}
                                <input type="file" accept="image/*" className="hidden" onChange={(e) => uploadCover(e.target.files?.[0])} />
                            </label>
                            {form.data.cover_path && <button type="button" onClick={() => form.setData('cover_path', '')} className="text-xs text-rose-400">Remove</button>}
                        </div>
                    </div>
                    <div><span className={label}>Excerpt (shown on cards + search results)</span><textarea rows={2} className={input} value={form.data.excerpt} onChange={(e) => form.setData('excerpt', e.target.value)} /></div>
                    <div><span className={label}>Body — HTML (headings, paragraphs, links, lists, images)</span><textarea rows={16} className={`${input} font-mono text-xs`} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} /></div>
                    <div className="grid gap-4 border-t border-line pt-4 sm:grid-cols-2">
                        <div><span className={label}>SEO title</span><input className={input} value={form.data.meta_title} onChange={(e) => form.setData('meta_title', e.target.value)} /></div>
                        <div><span className={label}>SEO description</span><input className={input} value={form.data.meta_description} onChange={(e) => form.setData('meta_description', e.target.value)} /></div>
                    </div>
                </div>

                {!isNew && <button type="button" onClick={remove} className="mt-4 text-sm text-rose-400 hover:text-rose-300">Delete post</button>}
            </div>
        </PlatformLayout>
    );
}
