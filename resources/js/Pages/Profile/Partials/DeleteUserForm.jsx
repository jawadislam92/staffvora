import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import Modal from '@/Components/Modal';
import { Button, Field, Input } from '@/Components/ui';

export default function DeleteUserForm({ preview = false, className = '' }) {
    const [confirmingUserDeletion, setConfirmingUserDeletion] = useState(false);

    const {
        data,
        setData,
        delete: destroy,
        processing,
        reset,
        errors,
    } = useForm({
        password: '',
    });

    const confirmUserDeletion = () => {
        setConfirmingUserDeletion(true);
    };

    const deleteUser = (e) => {
        e.preventDefault();
        if (preview) return;

        destroy(route('profile.destroy'), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
            // The kit <Input> is not a forwardRef control, so focus by id.
            onError: () => document.getElementById('delete_password')?.focus(),
            onFinish: () => reset(),
        });
    };

    const closeModal = () => {
        setConfirmingUserDeletion(false);
        reset();
    };

    return (
        <section className={className}>
            <p className="text-sm text-muted">
                Once your account is deleted, all of its resources and data will be
                permanently deleted. Before deleting your account, please download any data
                or information that you wish to retain.
            </p>

            <div className="mt-6">
                <Button variant="danger" onClick={confirmUserDeletion}>
                    Delete Account
                </Button>
            </div>

            <Modal show={confirmingUserDeletion} onClose={closeModal}>
                <form onSubmit={deleteUser} className="p-6">
                    <h2 className="text-lg font-medium text-body">
                        Are you sure you want to delete your account?
                    </h2>

                    <p className="mt-1 text-sm text-muted">
                        Once your account is deleted, all of its resources and data will be
                        permanently deleted. Please enter your password to confirm you would
                        like to permanently delete your account.
                    </p>

                    <div className="mt-6">
                        <Field label="Password" htmlFor="delete_password" error={errors.password}>
                            <Input
                                id="delete_password"
                                type="password"
                                name="password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                autoFocus
                                placeholder="Password"
                            />
                        </Field>
                    </div>

                    <div className="mt-6 flex justify-end gap-3">
                        <Button type="button" variant="secondary" onClick={closeModal}>
                            Cancel
                        </Button>

                        <Button
                            type="submit"
                            variant="danger"
                            loading={processing}
                            disabled={processing}
                        >
                            Delete Account
                        </Button>
                    </div>
                </form>
            </Modal>
        </section>
    );
}
