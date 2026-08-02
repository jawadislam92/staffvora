import { useForm } from '@inertiajs/react';
import { Transition } from '@headlessui/react';
import { Button, Field, Input } from '@/Components/ui';

export default function UpdatePasswordForm({ preview = false, className = '' }) {
    const { data, setData, errors, put, reset, processing, recentlySuccessful } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    // The kit <Input> is not a forwardRef control, so focus by id.
    const focusById = (id) => document.getElementById(id)?.focus();

    const updatePassword = (e) => {
        e.preventDefault();
        if (preview) return;

        put(route('password.update'), {
            preserveScroll: true,
            onSuccess: () => reset(),
            onError: (errors) => {
                if (errors.password) {
                    reset('password', 'password_confirmation');
                    focusById('password');
                }

                if (errors.current_password) {
                    reset('current_password');
                    focusById('current_password');
                }
            },
        });
    };

    return (
        <section className={className}>
            <form onSubmit={updatePassword} className="space-y-6">
                <Field
                    label="Current Password"
                    htmlFor="current_password"
                    error={errors.current_password}
                >
                    <Input
                        id="current_password"
                        value={data.current_password}
                        onChange={(e) => setData('current_password', e.target.value)}
                        type="password"
                        autoComplete="current-password"
                    />
                </Field>

                <Field label="New Password" htmlFor="password" error={errors.password}>
                    <Input
                        id="password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        type="password"
                        autoComplete="new-password"
                    />
                </Field>

                <Field
                    label="Confirm Password"
                    htmlFor="password_confirmation"
                    error={errors.password_confirmation}
                >
                    <Input
                        id="password_confirmation"
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                        type="password"
                        autoComplete="new-password"
                    />
                </Field>

                <div className="flex items-center gap-4">
                    <Button type="submit" loading={processing} disabled={processing}>
                        Save
                    </Button>

                    <Transition
                        show={recentlySuccessful}
                        enter="transition ease-in-out"
                        enterFrom="opacity-0"
                        leave="transition ease-in-out"
                        leaveTo="opacity-0"
                    >
                        <p className="text-sm text-muted">Saved.</p>
                    </Transition>
                </div>
            </form>
        </section>
    );
}
