import { Head, Link, router, usePage } from '@inertiajs/react';

// Operator shell — fixed dark (zinc), separate from the customer app's themed
// AuthenticatedLayout. Nav grows as the console moves in (milestone 2).
const NAV = [
    { label: 'Dashboard', href: '/dashboard' },
];

export default function PlatformLayout({ children, title }) {
    const platformUser = usePage().props.platformUser;
    const current = typeof window !== 'undefined' ? window.location.pathname : '';

    return (
        <div className="min-h-screen bg-zinc-950 text-zinc-100">
            <Head title={title || 'Operator portal'} />
            <div className="flex min-h-screen">
                <aside className="flex w-60 shrink-0 flex-col border-r border-zinc-800 bg-zinc-900 p-4">
                    <div className="mb-6 flex items-center gap-2">
                        <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-orange-500 font-black text-white">T</span>
                        <div className="leading-tight">
                            <div className="text-sm font-bold">Tracksuite</div>
                            <div className="text-[11px] text-zinc-500">Operations</div>
                        </div>
                    </div>
                    <nav className="flex-1 space-y-1">
                        {NAV.map((n) => (
                            <Link key={n.href} href={n.href}
                                className={`block rounded-lg px-3 py-2 text-sm font-medium transition ${
                                    current === n.href ? 'bg-orange-500 text-white' : 'text-zinc-300 hover:bg-zinc-800'
                                }`}>
                                {n.label}
                            </Link>
                        ))}
                    </nav>
                    <div className="border-t border-zinc-800 pt-3">
                        <div className="px-1 text-sm font-medium text-zinc-200">{platformUser?.name}</div>
                        <div className="px-1 text-[11px] capitalize text-zinc-500">{platformUser?.role}</div>
                        <button type="button" onClick={() => router.post('/logout')}
                            className="mt-2 w-full rounded-lg px-3 py-1.5 text-left text-xs font-semibold text-zinc-400 transition hover:bg-zinc-800 hover:text-white">
                            Log out
                        </button>
                    </div>
                </aside>
                <main className="flex-1 p-8">{children}</main>
            </div>
        </div>
    );
}
