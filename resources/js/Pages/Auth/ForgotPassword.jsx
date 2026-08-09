import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthLayout from '@/Layouts/AuthLayout';
import { Button, Field, Input } from '@/Components/ui';

export default function ForgotPassword({ status }) {
    // 'email' is the primary, self-service channel — anyone with their inbox
    // can recover, no Slack workspace required. 'slack' is the accelerator for
    // teams wired to Slack. 'slack' also carries a two-step sub-flow (request a
    // code, then enter it) tracked by `step`.
    const [channel, setChannel] = useState('email');
    const [step, setStep] = useState(1);

    const emailForm = useForm({ email: '' });
    const requestForm = useForm({ email: '' });
    const resetForm = useForm({ email: '', code: '', password: '', password_confirmation: '' });

    const sendEmailLink = (e) => {
        e.preventDefault();
        emailForm.post(route('password.email'), { preserveScroll: true });
    };

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

    const description =
        channel === 'email'
            ? 'Enter your account email and we will send you a link to reset your password.'
            : step === 1
              ? 'Enter your account email and we will send a 6-digit code to your Slack.'
              : 'Enter the code from Slack and choose a new password.';

    return (
        <AuthLayout title="Reset password" description={description}>
            <Head title="Forgot Password" />

            {status && (
                <div className="mb-5 rounded-lg border border-line bg-accent/10 px-4 py-3 text-sm font-medium text-accent">
                    {status}
                </div>
            )}

            {channel === 'email' ? (
                <div className="space-y-5">
                    <form onSubmit={sendEmailLink} className="space-y-5">
                        <Field label="Email" htmlFor="email" error={emailForm.errors.email}>
                            <Input
                                id="email"
                                type="email"
                                value={emailForm.data.email}
                                onChange={(e) => emailForm.setData('email', e.target.value)}
                                placeholder="you@company.com"
                                autoComplete="username"
                                autoFocus
                                required
                            />
                        </Field>

                        <Button type="submit" block loading={emailForm.processing}>
                            Email me a reset link
                        </Button>
                    </form>

                    <div className="border-t border-line pt-5 text-center">
                        <p className="text-sm text-muted">Your workspace uses Slack?</p>
                        <button
                            type="button"
                            onClick={() => {
                                setChannel('slack');
                                setStep(1);
                            }}
                            className="mt-1 rounded text-sm font-medium text-accent transition hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app"
                        >
                            Get a reset code on Slack instead
                        </button>
                    </div>

                    <div className="text-center text-sm">
                        <Link
                            href={route('login')}
                            className="rounded font-medium text-muted transition hover:text-body focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app"
                        >
                            Back to sign in
                        </Link>
                    </div>
                </div>
            ) : step === 1 ? (
                <form onSubmit={requestCode} className="space-y-5">
                    <Field label="Email" htmlFor="slack-email" error={requestForm.errors.email}>
                        <Input
                            id="slack-email"
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
                        <button
                            type="button"
                            onClick={() => setChannel('email')}
                            className="rounded font-medium text-muted transition hover:text-body focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app"
                        >
                            Use email instead
                        </button>
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
                No email or Slack access? Ask an admin — they can reset your password from the Users page.
            </p>
        </AuthLayout>
    );
}
