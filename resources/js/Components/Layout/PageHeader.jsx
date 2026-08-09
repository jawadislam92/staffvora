export default function PageHeader({ eyebrow, title, description, actions = null }) {
    return (
        <section className="flex flex-col gap-3 border-b border-line pb-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="min-w-0">
                {eyebrow && (
                    <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-accent">{eyebrow}</p>
                )}
                <h1 className="flex items-center gap-3 text-2xl font-bold text-body">
                    <span className="h-6 w-1 rounded-full bg-accent" aria-hidden="true" />
                    {title}
                </h1>
                {description && <p className="mt-0.5 text-sm text-muted">{description}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </section>
    );
}
