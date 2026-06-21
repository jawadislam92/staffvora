// The "deep band" — a full-bleed deep-indigo moment in the middle of the page
// that anchors the colour journey (light hero → white features → here → CTA).
export default function Stats({ data }) {
    const stats = data.stats || [];
    if (!stats.length) return null;
    return (
        <section className="band-deep relative overflow-hidden py-20 sm:py-24">
            <div className="pointer-events-none absolute inset-0 opacity-40" style={{ background: 'radial-gradient(680px 420px at 85% -15%, var(--g-from), transparent 60%), radial-gradient(520px 360px at 5% 120%, var(--a), transparent 60%)' }} />
            <div className="relative mx-auto grid max-w-[1300px] gap-12 px-6 text-center sm:grid-cols-3 sm:px-10">
                {stats.map((s, i) => (
                    <div key={i}>
                        <div className="text-5xl font-extrabold tracking-tight text-white sm:text-6xl">{s.value}</div>
                        <div className="mt-3 text-sm font-medium uppercase tracking-wide text-white/65">{s.label}</div>
                    </div>
                ))}
            </div>
        </section>
    );
}
