import { Link } from '@inertiajs/react';
import SiteShell from '@/Components/Site/SiteShell';

export default function BlogShow({ brand, theme, nav, jsonLd, isAuthed, post }) {
    return (
        <SiteShell brand={brand} theme={theme} nav={nav} jsonLd={jsonLd} isAuthed={isAuthed} meta={post?.meta || {}}>
            <article className="mx-auto max-w-3xl px-6 pb-24 pt-10 sm:pt-16">
                <Link href="/blog" className="text-sm font-semibold text-[var(--a)] transition hover:opacity-80">← All articles</Link>

                {post.category && <div className="mt-8 text-xs font-bold uppercase tracking-wider" style={{ color: 'var(--a)' }}>{post.category}</div>}
                <h1 className="mt-3 text-4xl font-extrabold leading-[1.1] tracking-tight text-[var(--brand)] sm:text-5xl">{post.title}</h1>
                <div className="mt-5 text-sm text-[var(--subtle)]">{[post.author, post.date_label].filter(Boolean).join(' · ')}</div>

                {post.cover && <img src={post.cover} alt="" className="mt-8 w-full rounded-2xl border border-[var(--line)] shadow-lg" />}

                <div className="site-prose mt-10" dangerouslySetInnerHTML={{ __html: post.body || '' }} />

                <div className="mt-14 border-t border-[var(--line)] pt-8">
                    <Link href="/register" className="btn-primary inline-flex rounded-full px-6 py-3 text-sm font-semibold">Start tracking free →</Link>
                </div>
            </article>
        </SiteShell>
    );
}
