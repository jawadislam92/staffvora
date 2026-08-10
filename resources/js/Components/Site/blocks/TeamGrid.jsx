export default function TeamGrid({ data }) {
    const members = data.members || [];
    if (!members.length) return null;
    return (
        <section className="py-16 sm:py-24">
            <div className="mx-auto max-w-[1300px] px-6 sm:px-10">
                {data.heading && <h2 className="text-center text-3xl font-extrabold tracking-tight text-[var(--brand)] sm:text-4xl">{data.heading}</h2>}
                {data.subhead && <p className="mx-auto mt-3 max-w-2xl text-center text-lg text-[var(--muted)]">{data.subhead}</p>}
                <div className="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    {members.map((m, i) => (
                        <div key={i} className="rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-6 text-center shadow-sm">
                            {m.avatar_url
                                ? <img src={m.avatar_url} alt={m.name || ''} className="mx-auto h-20 w-20 rounded-full object-cover" />
                                : <span className="mx-auto flex h-20 w-20 items-center justify-center rounded-full text-2xl font-bold text-white" style={{ background: 'linear-gradient(135deg,var(--g-from),var(--g-to))' }}>{(m.name || '?').charAt(0)}</span>}
                            <div className="mt-4 text-base font-bold text-[var(--brand)]">{m.name}</div>
                            <div className="text-sm text-[var(--subtle)]">{m.role}</div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}
