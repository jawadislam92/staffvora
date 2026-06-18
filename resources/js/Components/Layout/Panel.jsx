export default function Panel({ children, className = '' }) {
    return (
        <section className={`rounded-lg border border-slate-200 bg-white shadow-sm ${className}`}>
            {children}
        </section>
    );
}
