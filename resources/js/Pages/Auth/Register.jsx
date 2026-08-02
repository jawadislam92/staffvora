import { useEffect } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthLayout from '@/Layouts/AuthLayout';
import { Button, Field, Input, Select } from '@/Components/ui';

export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm({
        company: '',
        workspace_type: 'general',
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    useEffect(() => () => reset('password', 'password_confirmation'), []);

    const submit = (e) => {
        e.preventDefault();
        post(route('register'));
    };

    return (
        <AuthLayout
            title="Start your free trial"
            description="Create your workspace — no card required."
        >
            <Head title="Create your account" />

            <form onSubmit={submit} className="space-y-5">
                <Field label="Company / workspace name" htmlFor="company" error={errors.company}>
                    <Input
                        id="company"
                        name="company"
                        value={data.company}
                        onChange={(e) => setData('company', e.target.value)}
                        placeholder="Acme Inc"
                        autoComplete="organization"
                        autoFocus
                    />
                </Field>

                <Field
                    label="What kind of team are you?"
                    htmlFor="workspace_type"
                    error={errors.workspace_type}
                    hint="This tailors projects, client work, reporting, and optional account profiles to your team."
                >
                    <Select
                        id="workspace_type"
                        name="workspace_type"
                        value={data.workspace_type}
                        onChange={(e) => setData('workspace_type', e.target.value)}
                    >
                        <option value="general">In-house team / company</option>
                        <option value="agency">Client services agency</option>
                        <option value="bpo">BPO / Call center</option>
                        <option value="freelancer">Freelancer</option>
                    </Select>
                </Field>

                <Field label="Your name" htmlFor="name" error={errors.name}>
                    <Input
                        id="name"
                        name="name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder="Jane Doe"
                        autoComplete="name"
                    />
                </Field>

                <Field label="Work email" htmlFor="email" error={errors.email}>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        placeholder="jane@acme.com"
                        autoComplete="username"
                    />
                </Field>

                <Field label="Password" htmlFor="password" error={errors.password}>
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        placeholder="At least 12 characters"
                        autoComplete="new-password"
                    />
                </Field>

                <Field
                    label="Confirm password"
                    htmlFor="password_confirmation"
                    error={errors.password_confirmation}
                >
                    <Input
                        id="password_confirmation"
                        type="password"
                        name="password_confirmation"
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                        placeholder="Re-enter your password"
                        autoComplete="new-password"
                    />
                </Field>

                <Button type="submit" block loading={processing}>
                    {processing ? 'Creating your workspace…' : 'Create workspace'}
                </Button>
            </form>

            <p className="mt-6 text-center text-sm text-muted">
                Already have an account?{' '}
                <Link
                    href={route('login')}
                    className="rounded font-medium text-accent transition hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app"
                >
                    Sign in
                </Link>
            </p>
        </AuthLayout>
    );
}
