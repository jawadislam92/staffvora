export default function ImageBlock({ data }) {
    if (!data.image_url) return null;
    return (
        <section className="mx-auto max-w-5xl px-6 py-12">
            <figure>
                <img src={data.image_url} alt={data.alt || ''} className="w-full rounded-2xl border border-zinc-200 shadow-lg" />
                {data.caption && <figcaption className="mt-3 text-center text-sm text-zinc-500">{data.caption}</figcaption>}
            </figure>
        </section>
    );
}
