export default function PageHeader({ title, description, actions = null }) {
    return (
        <section className="flex flex-col gap-3 border-b border-slate-800 pb-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="min-w-0">
                <h1 className="flex items-center gap-3 text-2xl font-bold text-white">
                    <span className="h-6 w-1 rounded-full bg-gradient-to-b from-orange-400 to-amber-500" aria-hidden="true" />
                    {title}
                </h1>
                {description && <p className="mt-0.5 text-sm text-slate-400">{description}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </section>
    );
}
