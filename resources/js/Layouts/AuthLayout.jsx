import { Link, usePage } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';

/**
 * AuthLayout — the shared, on-brand shell for every customer auth screen
 * (login, register, password reset/confirm, email verification).
 *
 * A centered card (bg-surface / border-line) floats on a subtle electric-blue
 * brand wash built entirely from the accent token, so it re-themes cleanly in
 * dark / light / black. Motion is limited to a slow glow breathe that is
 * disabled under prefers-reduced-motion (motion-safe variant).
 *
 * @param {object} props
 * @param {React.ReactNode} props.title - Card heading (h1).
 * @param {React.ReactNode} [props.description] - Supporting line under the title.
 * @param {React.ReactNode} props.children - The form / content of the screen.
 */
export default function AuthLayout({ title, description, children }) {
    const appName = usePage().props?.branding?.name || 'Staffvora';

    return (
        <div className="relative min-h-screen w-full overflow-hidden bg-app text-body">
            {/* Brand wash — accent-tinted glows + a soft top radial. All colour is
                derived from the accent token, so it follows the workspace brand
                and every theme. Decorative only. */}
            <div aria-hidden="true" className="pointer-events-none absolute inset-0 overflow-hidden">
                <div
                    className="absolute inset-0"
                    style={{
                        background:
                            'radial-gradient(70rem 48rem at 50% -18%, color-mix(in srgb, var(--accent) 16%, transparent), transparent 60%)',
                    }}
                />
                <div className="absolute -top-40 left-1/2 h-[34rem] w-[34rem] -translate-x-1/2 rounded-full bg-accent/10 blur-3xl motion-safe:animate-pulse [animation-duration:9s]" />
                <div className="absolute -bottom-48 -left-24 h-[26rem] w-[26rem] rounded-full bg-accent/10 blur-3xl" />
                <div className="absolute -right-24 top-1/3 h-[22rem] w-[22rem] rounded-full bg-accent/5 blur-3xl" />
            </div>

            <div className="relative z-10 flex min-h-screen w-full flex-col items-center justify-center px-4 py-10">
                <div className="w-full max-w-md">
                    {/* Logo + wordmark above the card */}
                    <Link
                        href="/"
                        className="mb-7 flex items-center justify-center gap-2.5 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app"
                        aria-label={`${appName} home`}
                    >
                        <ApplicationLogo size="10" />
                        <span className="text-lg font-semibold tracking-tight text-body">{appName}</span>
                    </Link>

                    <div className="w-full rounded-2xl border border-line bg-surface p-8 shadow-xl shadow-black/5">
                        <div className="mb-6">
                            <h1 className="text-xl font-semibold tracking-tight text-body">{title}</h1>
                            {description != null && (
                                <p className="mt-1.5 text-sm text-muted">{description}</p>
                            )}
                        </div>
                        {children}
                    </div>

                    <p className="mt-6 text-center text-xs text-subtle">
                        &copy; {new Date().getFullYear()} {appName}. All rights reserved.
                    </p>
                </div>
            </div>
        </div>
    );
}
