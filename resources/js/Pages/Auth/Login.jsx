import { useEffect, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthLayout from '@/Layouts/AuthLayout';
import { Button, Field, Input, Checkbox } from '@/Components/ui';

export default function Login({ status, canResetPassword }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const [showPassword, setShowPassword] = useState(false);

    useEffect(() => {
        return () => {
            reset('password');
        };
    }, []);

    const submit = (e) => {
        e.preventDefault();
        post(route('login'));
    };

    return (
        <AuthLayout title="Welcome back" description="Sign in to continue to your workspace.">
            <Head title="Log in" />

            {status && (
                <div className="mb-5 rounded-lg border border-line bg-accent/10 px-4 py-3 text-sm font-medium text-accent">
                    {status}
                </div>
            )}

            <form onSubmit={submit} className="space-y-5">
                <Field label="Email" htmlFor="email" error={errors.email}>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        placeholder="you@company.com"
                        autoComplete="username"
                        autoFocus
                    />
                </Field>

                <Field label="Password" htmlFor="password" error={errors.password}>
                    <div className="relative">
                        <Input
                            id="password"
                            type={showPassword ? 'text' : 'password'}
                            name="password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            placeholder="Enter your password"
                            autoComplete="current-password"
                            className="pr-10"
                        />
                        <button
                            type="button"
                            onClick={() => setShowPassword((v) => !v)}
                            aria-label={showPassword ? 'Hide password' : 'Show password'}
                            aria-pressed={showPassword}
                            className="absolute inset-y-0 right-0 flex items-center px-3 text-subtle transition hover:text-body focus-visible:outline-none focus-visible:text-accent"
                        >
                            {showPassword ? (
                                <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L3 3m6.878 6.878L21 21" />
                                </svg>
                            ) : (
                                <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                            )}
                        </button>
                    </div>
                </Field>

                <div className="flex items-center justify-between gap-3">
                    <Checkbox
                        name="remember"
                        checked={data.remember}
                        onChange={(e) => setData('remember', e.target.checked)}
                        label="Remember me"
                    />

                    {canResetPassword && (
                        <Link
                            href={route('password.request')}
                            className="rounded text-sm font-medium text-accent transition hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app"
                        >
                            Forgot password?
                        </Link>
                    )}
                </div>

                <Button type="submit" block loading={processing}>
                    Log in
                </Button>
            </form>

            {route().has('register') && (
                <p className="mt-6 text-center text-sm text-muted">
                    New here?{' '}
                    <Link
                        href={route('register')}
                        className="rounded font-medium text-accent transition hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app"
                    >
                        Create a workspace
                    </Link>
                </p>
            )}
        </AuthLayout>
    );
}
