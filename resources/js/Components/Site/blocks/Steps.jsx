export default function Steps({ data }) {
    const steps = data.steps || [];
    return (
        <section className="mx-auto max-w-7xl px-6 py-20">
            <div className="rounded-3xl border border-zinc-200 bg-white px-8 py-14 shadow-sm">
                {data.heading && <h2 className="text-center text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl">{data.heading}</h2>}
                <div className="mx-auto mt-12 grid max-w-4xl gap-10 sm:grid-cols-3">
                    {steps.map((s, i) => (
                        <div key={i} className="text-center">
                            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full text-xl font-extrabold text-white shadow-md" style={{ background: 'var(--a)' }}>
                                {s.n || i + 1}
                            </div>
                            <h3 className="mt-4 font-semibold text-zinc-900">{s.title}</h3>
                            <p className="mt-2 text-sm text-zinc-600">{s.text}</p>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}
