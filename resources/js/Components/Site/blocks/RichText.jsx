// Renders rich text authored in the builder (sanitised server-side on save).
// Child-element utilities style headings/links/lists without the typography plugin.
export default function RichText({ data }) {
    if (!data.html) return null;
    return (
        <section className="mx-auto max-w-3xl px-6 py-12">
            <div
                className="text-[17px] leading-relaxed text-zinc-700
                    [&_h2]:mt-8 [&_h2]:text-2xl [&_h2]:font-bold [&_h2]:tracking-tight [&_h2]:text-zinc-900
                    [&_h3]:mt-6 [&_h3]:text-xl [&_h3]:font-semibold [&_h3]:text-zinc-900
                    [&_p]:mt-4 [&_strong]:font-semibold [&_strong]:text-zinc-900
                    [&_a]:font-medium [&_a]:underline [&_a]:[color:var(--a)]
                    [&_ul]:mt-4 [&_ul]:list-disc [&_ul]:pl-6 [&_li]:mt-1"
                dangerouslySetInnerHTML={{ __html: data.html }}
            />
        </section>
    );
}
