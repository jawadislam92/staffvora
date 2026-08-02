import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Field, Input, Textarea, Checkbox } from '@/Components/ui';
import { Plus, ArrowLeft } from 'lucide-react';

const DEV_SAMPLE = {
    user: { id: 1, name: 'Jawad', email: 'jawad@staffvora.com', is_super_admin: true, permissions: [] },
};

export default function Create({ auth, preview = false }) {
    const user = preview ? DEV_SAMPLE.user : auth?.user;
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        description: '',
        is_active: true,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        if (preview) return;
        post(route('upwork-profiles.store'));
    };

    return (
        <AuthenticatedLayout
            user={user}
            header={<h2 className="text-xl font-semibold text-body">Create Profile</h2>}
        >
            <Head title="Create Profile" />

            <div className="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8 space-y-6">
                <div>
                    <Link
                        href={preview ? '#' : route('upwork-profiles.index')}
                        className="inline-flex items-center gap-1.5 text-sm text-muted hover:text-body transition"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Back to profiles
                    </Link>
                    <p className="mt-2 max-w-2xl text-sm text-muted">
                        Add a profile for client assignments and time tracking.
                    </p>
                </div>

                <Card>
                    <form onSubmit={handleSubmit} className="space-y-5">
                        <Field label="Profile name" required error={errors.name} htmlFor="name">
                            <Input
                                id="name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="e.g. John Doe, Jane Smith"
                                maxLength={255}
                                autoFocus
                                required
                            />
                        </Field>

                        <Field label="Email" hint="Optional" error={errors.email} htmlFor="email">
                            <Input
                                id="email"
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                placeholder="name@example.com"
                                maxLength={255}
                            />
                        </Field>

                        <Field label="Description" hint="Optional" error={errors.description} htmlFor="description">
                            <Textarea
                                id="description"
                                rows={4}
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                placeholder="Add any notes or description for this profile…"
                                maxLength={1000}
                            />
                        </Field>

                        <div className="rounded-lg border border-line bg-surface-2/50 p-4">
                            <Checkbox
                                checked={data.is_active}
                                onChange={(e) => setData('is_active', e.target.checked)}
                                label="Profile is active (active profiles appear in dropdown selections)"
                            />
                            {errors.is_active && <p className="mt-2 text-xs text-danger">{errors.is_active}</p>}
                        </div>

                        <div className="flex items-center justify-end gap-2 border-t border-line pt-5">
                            <Button
                                as={Link}
                                href={preview ? '#' : route('upwork-profiles.index')}
                                variant="secondary"
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                loading={processing}
                                disabled={!data.name.trim()}
                                icon={<Plus className="h-4 w-4" />}
                            >
                                Create profile
                            </Button>
                        </div>
                    </form>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
