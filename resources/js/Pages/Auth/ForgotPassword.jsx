import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthLayout from '@/Layouts/AuthLayout';
import { Button, Field, Input } from '@/Components/ui';

export default function ForgotPassword({ status }) {
    const [step, setStep] = useState(1);

    const requestForm = useForm({ email: '' });
    const resetForm = useForm({ email: '', code: '', password: '', password_confirmation: '' });

    const requestCode = (e) => {
        e.preventDefault();
        requestForm.post(route('password.code.request'), {
            preserveScroll: true,
            onSuccess: () => {
                resetForm.setData('email', requestForm.data.email);
                setStep(2);
            },
        });
    };

    const submitReset = (e) => {
        e.preventDefault();
        resetForm.post(route('password.code.reset'), { preserveScroll: true });
    };

    return (
        <AuthLayout
            title="Reset password"
            description={
                step === 1
                    ? 'Enter your account email and we will send a 6-digit code to your Slack.'
                    : 'Enter the code from Slack and choose a new password.'
            }
        >
            <Head title="Forgot Password" />

            {status && (
                <div className="mb-5 rounded-lg border border-line bg-accent/10 px-4 py-3 text-sm font-medium text-accent">
                    {status}
                </div>
            )}

            {step === 1 ? (
                <form onSubmit={requestCode} className="space-y-5">
                    <Field label="Email" htmlFor="email" error={requestForm.errors.email}>
                        <Input
                            id="email"
                            type="email"
                            value={requestForm.data.email}
                            onChange={(e) => requestForm.setData('email', e.target.value)}
                            placeholder="you@company.com"
                            autoComplete="username"
                            autoFocus
                            required
                        />
                    </Field>

                    <Button type="submit" block loading={requestForm.processing}>
                        Send code to Slack
                    </Button>

                    <div className="flex items-center justify-between text-sm">
                        <Link
                            href={route('login')}
                            className="rounded font-medium text-muted transition hover:text-body focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app"
                        >
                            Back to sign in
                        </Link>
                        <button
                            type="button"
                            onClick={() => setStep(2)}
                            className="rounded font-medium text-muted transition hover:text-body focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app"
                        >
                            I already have a code
                        </button>
                    </div>
                </form>
            ) : (
                <form onSubmit={submitReset} className="space-y-5">
                    <Field label="Email" htmlFor="reset-email" error={resetForm.errors.email}>
                        <Input
                            id="reset-email"
                            type="email"
                            value={resetForm.data.email}
                            onChange={(e) => resetForm.setData('email', e.target.value)}
                            autoComplete="username"
                            required
                        />
                    </Field>

                    <Field label="6-digit code" htmlFor="code" error={resetForm.errors.code}>
                        <Input
                            id="code"
                            type="text"
                            inputMode="numeric"
                            maxLength={6}
                            value={resetForm.data.code}
                            onChange={(e) => resetForm.setData('code', e.target.value.replace(/\D/g, ''))}
                            className="text-center text-lg font-bold tracking-[0.5em]"
                            placeholder="••••••"
                            autoFocus
                            required
                        />
                    </Field>

                    <Field label="New password" htmlFor="new-password" error={resetForm.errors.password}>
                        <Input
                            id="new-password"
                            type="password"
                            value={resetForm.data.password}
                            onChange={(e) => resetForm.setData('password', e.target.value)}
                            autoComplete="new-password"
                            placeholder="At least 12 characters"
                            required
                        />
                    </Field>

                    <Field
                        label="Confirm new password"
                        htmlFor="confirm-password"
                        error={resetForm.errors.password_confirmation}
                    >
                        <Input
                            id="confirm-password"
                            type="password"
                            value={resetForm.data.password_confirmation}
                            onChange={(e) => resetForm.setData('password_confirmation', e.target.value)}
                            autoComplete="new-password"
                            required
                        />
                    </Field>

                    <Button type="submit" block loading={resetForm.processing}>
                        Set new password
                    </Button>

                    <div className="flex items-center justify-between text-sm">
                        <button
                            type="button"
                            onClick={() => setStep(1)}
                            className="rounded font-medium text-muted transition hover:text-body focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app"
                        >
                            Request a new code
                        </button>
                        <Link
                            href={route('login')}
                            className="rounded font-medium text-muted transition hover:text-body focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app"
                        >
                            Back to sign in
                        </Link>
                    </div>
                </form>
            )}

            <p className="mt-6 text-center text-xs text-subtle">
                No Slack access? Ask an admin — they can reset your password from the Users page.
            </p>
        </AuthLayout>
    );
}
