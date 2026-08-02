import { useEffect } from 'react';
import { Head, useForm } from '@inertiajs/react';
import AuthLayout from '@/Layouts/AuthLayout';
import { Button, Field, Input } from '@/Components/ui';

export default function ConfirmPassword() {
    const { data, setData, post, processing, errors, reset } = useForm({
        password: '',
    });

    useEffect(() => {
        return () => {
            reset('password');
        };
    }, []);

    const submit = (e) => {
        e.preventDefault();

        post(route('password.confirm'));
    };

    return (
        <AuthLayout
            title="Confirm password"
            description="This is a secure area of the application. Please confirm your password before continuing."
        >
            <Head title="Confirm Password" />

            <form onSubmit={submit} className="space-y-5">
                <Field label="Password" htmlFor="password" error={errors.password}>
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        value={data.password}
                        autoComplete="current-password"
                        autoFocus
                        onChange={(e) => setData('password', e.target.value)}
                    />
                </Field>

                <Button type="submit" block loading={processing}>
                    Confirm
                </Button>
            </form>
        </AuthLayout>
    );
}
