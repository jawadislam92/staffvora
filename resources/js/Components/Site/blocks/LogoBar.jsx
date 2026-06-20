export default function LogoBar({ data }) {
    const logos = data.logos || [];
    return (
        <section className="border-y border-zinc-100 bg-zinc-50/60 py-10">
            <div className="mx-auto max-w-7xl px-6">
                {data.caption && <p className="text-center text-sm font-medium uppercase tracking-wider text-zinc-400">{data.caption}</p>}
                {logos.length > 0 ? (
                    <div className="mt-6 flex flex-wrap items-center justify-center gap-x-12 gap-y-6 opacity-70">
                        {logos.map((l, i) => (
                            l.image_url
                                ? <img key={i} src={l.image_url} alt={l.name || ''} className="h-8 w-auto object-contain grayscale" />
                                : <span key={i} className="text-lg font-bold text-zinc-400">{l.name}</span>
                        ))}
                    </div>
                ) : (
                    <p className="mt-3 text-center text-xs text-zinc-300">Add customer logos in the builder</p>
                )}
            </div>
        </section>
    );
}
