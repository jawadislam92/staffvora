export default function LogoBar({ data }) {
    const logos = data.logos || [];
    return (
        <section className="border-y border-[var(--line)] bg-[var(--surface-2)] py-10">
            <div className="mx-auto max-w-[1500px] px-6 sm:px-10">
                {data.caption && <p className="text-center text-xs font-semibold uppercase tracking-[0.16em] text-[var(--subtle)]">{data.caption}</p>}
                {logos.length > 0 ? (
                    <div className="mt-7 flex flex-wrap items-center justify-center gap-x-14 gap-y-6 opacity-70">
                        {logos.map((l, i) => (
                            l.image_url
                                ? <img key={i} src={l.image_url} alt={l.name || ''} className="h-8 w-auto object-contain grayscale" />
                                : <span key={i} className="text-lg font-bold text-[var(--subtle)]">{l.name}</span>
                        ))}
                    </div>
                ) : (
                    <p className="mt-3 text-center text-xs text-[var(--subtle)]/60">Add customer logos in the builder</p>
                )}
            </div>
        </section>
    );
}
