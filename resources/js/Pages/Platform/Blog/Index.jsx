import { Head, Link, router } from '@inertiajs/react';
import PlatformLayout, { PlatformPageHeader } from '@/Layouts/PlatformLayout';
import { Button, Badge, EmptyState } from '@/Components/ui';

export default function BlogIndex({ posts = [] }) {
    const remove = (p) => { if (confirm(`Delete "${p.title}"?`)) router.delete(route('platform.blog.destroy', p.id)); };

    return (
        <PlatformLayout
            title="Blog"
            header={
                <PlatformPageHeader
                    title="Blog"
                    description="Write and publish posts for your marketing blog — no code, no deploy."
                    actions={<Button as={Link} href={route('platform.blog.create')}>New post</Button>}
                />
            }
        >
            <Head title="Blog" />
            <div className="w-full space-y-5">
                <div className="rounded-xl border border-line bg-surface">
                    {posts.length === 0 ? (
                        <div className="p-6">
                            <EmptyState
                                title="No posts yet"
                                description="Click New post to write your first article."
                            />
                        </div>
                    ) : (
                        <div className="divide-y divide-line">
                            {posts.map((p) => (
                                <div key={p.id} className="flex items-center justify-between gap-3 p-4">
                                    <div className="min-w-0">
                                        <div className="truncate font-medium text-body">{p.title}</div>
                                        <div className="mt-0.5 text-xs text-muted">/blog/{p.slug}{p.category ? ' · ' + p.category : ''}{p.date ? ' · ' + p.date : ''}</div>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-3">
                                        <Badge tone={p.status === 'published' ? 'success' : 'warning'}>{p.status}</Badge>
                                        <Button as={Link} href={route('platform.blog.edit', p.id)} size="sm" variant="secondary">Edit</Button>
                                        <button type="button" onClick={() => remove(p)} className="text-xs font-medium text-danger hover:underline">Delete</button>
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
