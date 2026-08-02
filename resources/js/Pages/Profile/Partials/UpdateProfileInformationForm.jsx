import { Link, useForm, usePage } from '@inertiajs/react';
import { Transition } from '@headlessui/react';
import { useRef } from 'react';
import Avatar from '@/Components/Avatar';
import { Button, Field, Input } from '@/Components/ui';

export default function UpdateProfileInformation({
    user: userProp,
    mustVerifyEmail,
    status,
    preview = false,
    className = '',
}) {
    // In preview there is no Inertia page context, so fall back to the passed
    // sample user. Real behavior (usePage) is preserved otherwise.
    const pageUser = preview ? null : usePage().props.auth.user;
    const user = userProp || pageUser;
    const fileInputRef = useRef();

    const { data, setData, post, errors, processing, recentlySuccessful } = useForm({
        name: user.name,
        email: user.email,
        avatar: null,
        _method: 'PATCH',
    });

    const submit = (e) => {
        e.preventDefault();
        if (preview) return;

        if (data.avatar) {
            // Use POST with _method PATCH for file uploads
            post(route('profile.update'), {
                forceFormData: true,
            });
        } else {
            // Use regular PATCH for text-only updates
            post(route('profile.update'));
        }
    };

    const handleAvatarChange = (e) => {
        const file = e.target.files[0];
        if (file) {
            setData('avatar', file);
        }
    };

    const removeAvatar = () => {
        setData('avatar', null);
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    return (
        <section className={className}>
            <form onSubmit={submit} className="space-y-6">
                <Field label="Profile Picture">
                    <div className="flex items-center gap-4">
                        <Avatar user={{ ...user, avatar: data.avatar ? null : user.avatar }} size="lg" />
                        <div className="flex flex-col gap-2">
                            <input
                                ref={fileInputRef}
                                type="file"
                                accept="image/*"
                                onChange={handleAvatarChange}
                                className="hidden"
                                id="avatar-upload"
                            />
                            <Button
                                as="label"
                                htmlFor="avatar-upload"
                                variant="secondary"
                                size="sm"
                                className="cursor-pointer"
                            >
                                Choose New Picture
                            </Button>
                            {(user.avatar || data.avatar) && (
                                <Button
                                    type="button"
                                    variant="danger"
                                    size="sm"
                                    onClick={removeAvatar}
                                >
                                    Remove Picture
                                </Button>
                            )}
                        </div>
                    </div>
                    {data.avatar && (
                        <p className="mt-2 text-sm text-muted">
                            New picture selected: {data.avatar.name}
                        </p>
                    )}
                    {errors.avatar && (
                        <p className="mt-2 text-xs text-danger">{errors.avatar}</p>
                    )}
                </Field>

                <Field label="Name" htmlFor="name" error={errors.name} required>
                    <Input
                        id="name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        required
                        autoFocus
                        autoComplete="name"
                    />
                </Field>

                <Field label="Email" htmlFor="email" error={errors.email} required>
                    <Input
                        id="email"
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        required
                        autoComplete="username"
                    />
                </Field>

                {mustVerifyEmail && user.email_verified_at === null && (
                    <div className="rounded-lg bg-warning-soft px-4 py-3">
                        <p className="text-sm text-warning">
                            Your email address is unverified.{' '}
                            <Link
                                href={route('verification.send')}
                                method="post"
                                as="button"
                                className="rounded-md font-medium text-accent underline hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                            >
                                Click here to re-send the verification email.
                            </Link>
                        </p>

                        {status === 'verification-link-sent' && (
                            <div className="mt-2 text-sm font-medium text-success">
                                A new verification link has been sent to your email address.
                            </div>
                        )}
                    </div>
                )}

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
