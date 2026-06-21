export default function Steps({ data }) {
    const steps = data.steps || [];
    return (
        <section className="py-20 sm:py-28">
            <div className="mx-auto max-w-[1300px] px-6 sm:px-10">
                {data.heading && <h2 className="text-center text-3xl font-extrabold tracking-tight text-[var(--brand)] sm:text-[2.6rem]">{data.heading}</h2>}
                <div className="relative mt-16 grid gap-12 sm:grid-cols-3">
                    {/* connecting line behind the number tiles */}
                    <div className="absolute left-[16%] right-[16%] top-7 hidden h-0.5 bg-[var(--line)] sm:block" />
                    {steps.map((s, i) => (
                        <div key={i} className="relative text-center">
                            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl text-xl font-extrabold text-white" style={{ background: 'linear-gradient(135deg,var(--g-from),var(--g-to))', boxShadow: '0 14px 26px -10px color-mix(in srgb, var(--a) 60%, transparent)' }}>
                                {s.n || i + 1}
                            </div>
                            <h3 className="mt-5 text-lg font-bold text-[var(--brand)]">{s.title}</h3>
                            <p className="mx-auto mt-2 max-w-xs text-sm leading-relaxed text-[var(--muted)]">{s.text}</p>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}
