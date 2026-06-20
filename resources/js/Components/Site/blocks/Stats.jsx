export default function Stats({ data }) {
    const stats = data.stats || [];
    if (!stats.length) return null;
    return (
        <section className="mx-auto max-w-7xl px-6 py-12">
            <div className="grid gap-8 rounded-3xl border border-zinc-200 bg-zinc-50 px-8 py-12 text-center sm:grid-cols-3">
                {stats.map((s, i) => (
                    <div key={i}>
                        <div className="text-4xl font-extrabold tracking-tight" style={{ color: 'var(--a)' }}>{s.value}</div>
                        <div className="mt-2 text-sm font-medium text-zinc-600">{s.label}</div>
                    </div>
                ))}
            </div>
        </section>
    );
}
