import { Link } from '@inertiajs/react';
import SiteShell from '@/Components/Site/SiteShell';

function PostCard({ post }) {
    return (
        <Link href={`/blog/${post.slug}`} className="group flex flex-col overflow-hidden rounded-2xl border border-[var(--line)] bg-[var(--surface)] shadow-sm transition hover:-translate-y-1">
            {post.cover
                ? <img src={post.cover} alt="" className="aspect-[16/9] w-full object-cover" />
                : <div className="aspect-[16/9] w-full" style={{ background: 'linear-gradient(135deg,var(--g-from),var(--g-to))' }} />}
            <div className="flex flex-1 flex-col p-6">
                {post.category && <span className="mb-2 text-xs font-bold uppercase tracking-wider" style={{ color: 'var(--a)' }}>{post.category}</span>}
                <h3 className="text-lg font-bold leading-snug text-[var(--brand)] transition group-hover:opacity-80">{post.title}</h3>
                {post.excerpt && <p className="mt-2 flex-1 text-sm leading-relaxed text-[var(--muted)]">{post.excerpt}</p>}
                <div className="mt-4 text-xs text-[var(--subtle)]">{[post.author, post.date_label].filter(Boolean).join(' · ')}</div>
            </div>
        </Link>
    );
}

export default function BlogIndex({ brand, theme, nav, jsonLd, isAuthed, posts = [], pagination = {}, meta = {} }) {
    return (
        <SiteShell brand={brand} theme={theme} nav={nav} jsonLd={jsonLd} isAuthed={isAuthed} meta={meta}>
            <section className="relative isolate overflow-hidden">
                <div className="absolute inset-0 -z-10 wash-hero" />
                <div className="mx-auto max-w-[1300px] px-6 pb-8 pt-16 text-center sm:px-10 sm:pt-24">
                    <span className="inline-flex items-center gap-2 rounded-full border bg-[var(--surface)] px-4 py-1.5 text-sm font-medium" style={{ color: 'var(--a)', borderColor: 'color-mix(in srgb, var(--a) 25%, var(--canvas))' }}>The blog</span>
                    <h1 className="mt-6 text-4xl font-extrabold tracking-tight text-[var(--brand)] sm:text-5xl">Work, measured well.</h1>
                    <p className="mx-auto mt-4 max-w-2xl text-lg text-[var(--muted)]">Guides on time tracking, remote teams and building a humane, productive workplace.</p>
                </div>
            </section>

            <section className="pb-24">
                <div className="mx-auto max-w-[1300px] px-6 sm:px-10">
                    {posts.length === 0 ? (
                        <p className="py-16 text-center text-[var(--muted)]">No posts yet — check back soon.</p>
                    ) : (
                        <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                            {posts.map((p) => <PostCard key={p.slug} post={p} />)}
                        </div>
                    )}
                    {(pagination.prev || pagination.next) && (
                        <div className="mt-12 flex items-center justify-between">
                            {pagination.prev ? <Link href={pagination.prev} className="btn-ghost rounded-full px-5 py-2.5 text-sm font-semibold">← Newer</Link> : <span />}
                            {pagination.next ? <Link href={pagination.next} className="btn-ghost rounded-full px-5 py-2.5 text-sm font-semibold">Older →</Link> : <span />}
                        </div>
                    )}
                </div>
            </section>
        </SiteShell>
    );
}
