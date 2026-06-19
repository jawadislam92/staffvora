import { Link } from '@inertiajs/react';
import { HelpCircle } from 'lucide-react';

/**
 * A consistent page header: the title, a one-line "what this page does", and an
 * optional help link + actions. Drop it at the top of a page's content so every
 * screen explains its purpose (Phase 5G).
 */
export default function PageHeader({ title, purpose, children, helpHref }) {
    return (
        <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div className="min-w-0">
                <h1 className="flex items-center gap-2 text-xl font-bold text-body">{title}</h1>
                {purpose && <p className="mt-1 max-w-2xl text-sm text-muted">{purpose}</p>}
            </div>
            <div className="flex shrink-0 items-center gap-2">
                {children}
                <Link
                    href={helpHref || route('help.index')}
                    className="inline-flex items-center gap-1.5 rounded-lg border border-line px-2.5 py-1.5 text-xs font-medium text-muted transition hover:bg-surface-2 hover:text-body"
                    title="Open the help center"
                >
                    <HelpCircle className="h-4 w-4" />
                    Help
                </Link>
            </div>
        </div>
    );
}
