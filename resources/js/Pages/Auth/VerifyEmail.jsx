import { Head, Link, useForm } from '@inertiajs/react';
import AuthLayout from '@/Layouts/AuthLayout';
import { Button } from '@/Components/ui';

export default function VerifyEmail({ status }) {
    const { post, processing } = useForm({});

    const submit = (e) => {
        e.preventDefault();

        post(route('verification.send'));
    };

    return (
        <AuthLayout
            title="Verify your email"
            description="Thanks for signing up! Before getting started, could you verify your email address by clicking on the link we just emailed to you? If you didn't receive the email, we will gladly send you another."
        >
            <Head title="Email Verification" />

            {status === 'verification-link-sent' && (
                <div className="mb-5 rounded-lg border border-line bg-accent/10 px-4 py-3 text-sm font-medium text-accent">
                    A new verification link has been sent to the email address you provided during registration.
                </div>
            )}

            <form onSubmit={submit} className="flex items-center justify-between gap-3">
                <Button type="submit" loading={processing}>
                    Resend verification email
                </Button>

                <Link
                    href={route('logout')}
                    method="post"
                    as="button"
                    className="rounded text-sm font-medium text-muted transition hover:text-body focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app"
                >
                    Log out
                </Link>
            </form>
        </AuthLayout>
    );
}
