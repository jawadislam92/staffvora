// Renders rich text authored in the builder (sanitised server-side on save).
// Child-element utilities style headings/links/lists without the typography plugin.
//
// Colours come from the site's CSS variables, NOT fixed zinc greys: the public
// site has a day/night switcher, and the legal pages (privacy, terms) are almost
// entirely this block — hardcoded zinc-700 body text rendered as near-invisible
// dark grey on the dark canvas. Ordered lists are styled too, because the
// download page numbers its install steps.
export default function RichText({ data }) {
    if (!data.html) return null;
    return (
        <section className="mx-auto max-w-3xl px-6 py-12">
            <div
                className="text-[17px] leading-relaxed text-[var(--muted)]
                    [&_h2]:mt-10 [&_h2]:text-2xl [&_h2]:font-bold [&_h2]:tracking-tight [&_h2]:text-[var(--brand)]
                    [&_h3]:mt-6 [&_h3]:text-xl [&_h3]:font-semibold [&_h3]:text-[var(--brand)]
                    [&_p]:mt-4 [&_strong]:font-semibold [&_strong]:text-[var(--brand)]
                    [&_a]:font-medium [&_a]:underline [&_a]:[color:var(--a)]
                    [&_ul]:mt-4 [&_ul]:list-disc [&_ul]:pl-6
                    [&_ol]:mt-4 [&_ol]:list-decimal [&_ol]:pl-6
                    [&_li]:mt-1.5 [&_li]:pl-1
                    [&_em]:italic
                    [&_hr]:my-10 [&_hr]:border-[var(--line)]"
                dangerouslySetInnerHTML={{ __html: data.html }}
            />
        </section>
    );
}
