import { useEffect } from 'react';
import { Head, useForm } from '@inertiajs/react';
import AuthLayout from '@/Layouts/AuthLayout';
import { Button, Field, Input } from '@/Components/ui';

export default function ResetPassword({ token, email }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        token: token,
        email: email,
        password: '',
        password_confirmation: '',
    });

    useEffect(() => {
        return () => {
            reset('password', 'password_confirmation');
        };
    }, []);

    const submit = (e) => {
        e.preventDefault();

        post(route('password.store'));
    };

    return (
        <AuthLayout title="Reset password" description="Choose a new password for your account.">
            <Head title="Reset Password" />

            <form onSubmit={submit} className="space-y-5">
                <Field label="Email" htmlFor="email" error={errors.email}>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        autoComplete="username"
                        onChange={(e) => setData('email', e.target.value)}
                    />
                </Field>

                <Field label="Password" htmlFor="password" error={errors.password}>
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        value={data.password}
                        autoComplete="new-password"
                        autoFocus
                        onChange={(e) => setData('password', e.target.value)}
                    />
                </Field>

                <Field
                    label="Confirm Password"
                    htmlFor="password_confirmation"
                    error={errors.password_confirmation}
                >
                    <Input
                        id="password_confirmation"
                        type="password"
                        name="password_confirmation"
                        value={data.password_confirmation}
                        autoComplete="new-password"
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                    />
                </Field>

                <Button type="submit" block loading={processing}>
                    Reset Password
                </Button>
            </form>
        </AuthLayout>
    );
}
