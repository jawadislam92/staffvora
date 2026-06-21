import { Head, Link, router } from '@inertiajs/react';
import PlatformLayout from '@/Layouts/PlatformLayout';

export default function BlogIndex({ posts = [] }) {
    const remove = (p) => { if (confirm(`Delete “${p.title}”?`)) router.delete(route('platform.blog.destroy', p.id)); };

    return (
        <PlatformLayout title="Blog" header={<h2 className="text-xl font-semibold text-white">Blog</h2>}>
            <Head title="Blog" />
            <div className="mx-auto w-full max-w-4xl space-y-5 px-4 py-6 sm:px-6">
                <div className="flex items-center justify-between gap-4">
                    <p className="text-sm text-muted">Write and publish posts for your marketing blog — no code, no deploy.</p>
                    <Link href={route('platform.blog.create')} className="shrink-0 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white hover:opacity-90">New post</Link>
                </div>

                <div className="rounded-xl border border-line bg-surface">
                    {posts.length === 0 ? (
                        <p className="p-10 text-center text-sm text-muted">No posts yet. Click <span className="font-semibold text-body">New post</span> to write your first.</p>
                    ) : (
                        <div className="divide-y divide-line">
                            {posts.map((p) => (
                                <div key={p.id} className="flex items-center justify-between gap-3 p-4">
                                    <div className="min-w-0">
                                        <div className="truncate font-medium text-body">{p.title}</div>
                                        <div className="mt-0.5 text-xs text-subtle">/blog/{p.slug}{p.category ? ' · ' + p.category : ''}{p.date ? ' · ' + p.date : ''}</div>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-3">
                                        <span className={`rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase ${p.status === 'published' ? 'bg-emerald-500/15 text-emerald-400' : 'bg-amber-500/15 text-amber-400'}`}>{p.status}</span>
                                        <Link href={route('platform.blog.edit', p.id)} className="rounded-lg bg-accent px-3 py-1.5 text-xs font-semibold text-white hover:opacity-90">Edit</Link>
                                        <button type="button" onClick={() => remove(p)} className="text-xs text-rose-400 hover:text-rose-300">Delete</button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </PlatformLayout>
    );
}
