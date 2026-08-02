import { useState, useEffect } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import {
    Button,
    Card,
    Badge,
    StatCard,
    Field,
    Input,
    EmptyState,
} from '@/Components/ui';
import { Users, Plus, Trash2, Pencil, UsersRound, Crown } from 'lucide-react';

const cx = (...a) => a.filter(Boolean).join(' ');

// Selectable team colors. These are DATA (each team stores its own hex color),
// not chrome — they're rendered as inline-style swatches, never as UI tokens.
const PALETTE = ['#3b82f6', '#22c55e', '#a855f7', '#ec4899', '#eab308', '#14b8a6', '#ef4444', '#f97316'];

// ---------------------------------------------------------------------------
// DEV_SAMPLE — used ONLY when `preview` is true so the page renders fully
// offline for visual QA. Mirrors the controller's shape (id/name/color/
// users_count) plus a couple of preview-only fields (lead / members) to show
// the richer card layout. Never touched in normal (non-preview) rendering.
// ---------------------------------------------------------------------------
const DEV_SAMPLE = {
    user: {
        id: 1,
        name: 'Jawad',
        email: 'jawad@staffvora.com',
        is_super_admin: true,
        permissions: [],
    },
    canManage: true,
    teams: [
        { id: 1, name: 'Engineering', color: '#3b82f6', users_count: 12, lead: 'Ayesha Khan', members: ['Ayesha Khan', 'Bilal Ahmed', 'Chen Wei', 'Dev Patel'] },
        { id: 2, name: 'Design', color: '#a855f7', users_count: 5, lead: 'Maria Lopez', members: ['Maria Lopez', 'Sana Riaz', 'Tom Hughes'] },
        { id: 3, name: 'Customer Success', color: '#22c55e', users_count: 8, lead: 'Omar Farooq', members: ['Omar Farooq', 'Lena Vogt', 'Priya Nair'] },
        { id: 4, name: 'Operations', color: '#eab308', users_count: 3, lead: 'Zoe Bennett', members: ['Zoe Bennett', 'Raj Kumar'] },
    ],
};

/** Small hex color picker used inside the create/edit modals. */
function ColorPicker({ value, onChange }) {
    return (
        <div className="flex flex-wrap items-center gap-2">
            {PALETTE.map((c) => {
                const active = value === c;
                return (
                    <button
                        key={c}
                        type="button"
                        onClick={() => onChange(c)}
                        aria-label={`Pick color ${c}`}
                        aria-pressed={active}
                        className={cx(
                            'h-7 w-7 rounded-full transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app',
                            active ? 'ring-2 ring-accent ring-offset-2 ring-offset-app' : 'ring-1 ring-line hover:ring-accent'
                        )}
                        style={{ backgroundColor: c }}
                    />
                );
            })}
        </div>
    );
}

/** Create / edit dialog. `team` null => create mode. */
function TeamFormModal({ show, team, onClose }) {
    const isEdit = Boolean(team);
    const form = useForm({ name: '', color: PALETTE[0] });

    // Re-seed the form whenever the modal opens for a (different) team.
    useEffect(() => {
        if (show) {
            form.clearErrors();
            form.setData({
                name: team?.name ?? '',
                color: team?.color ?? PALETTE[0],
            });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show, team?.id]);

    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => onClose() };
        if (isEdit) {
            form.patch(route('teams.update', team.id), opts);
        } else {
            form.post(route('teams.store'), { ...opts, onSuccess: () => { form.reset(); onClose(); } });
        }
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="lg">
            <form onSubmit={submit} className="p-6 space-y-5">
                <div>
                    <h3 className="text-lg font-semibold text-body">
                        {isEdit ? 'Edit team' : 'New team'}
                    </h3>
                    <p className="mt-1 text-sm text-muted">
                        {isEdit
                            ? 'Rename this team or change its color. Members stay assigned.'
                            : 'Create a team, then assign people to it from their profile.'}
                    </p>
                </div>

                <Field label="Team name" required error={form.errors.name} htmlFor="team-name">
                    <Input
                        id="team-name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        placeholder="e.g. Engineering"
                        maxLength={100}
                        autoFocus
                    />
                </Field>

                <Field label="Color" error={form.errors.color}>
                    <ColorPicker value={form.data.color} onChange={(c) => form.setData('color', c)} />
                </Field>

                <div className="flex items-center justify-end gap-2 pt-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        loading={form.processing}
                        disabled={!form.data.name.trim()}
                        icon={!isEdit && <Plus className="h-4 w-4" />}
                    >
                        {isEdit ? 'Save changes' : 'Add team'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

/** A single team card. */
function TeamCard({ team, canManage, onEdit, onDelete }) {
    return (
        <Card className="flex flex-col" padded={false}>
            <div className="flex items-start gap-3 p-5">
                <span
                    className="mt-1 h-3.5 w-3.5 shrink-0 rounded-full ring-2 ring-line"
                    style={{ backgroundColor: team.color || '#64748b' }}
                    aria-hidden="true"
                />
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                        <h3 className="truncate text-base font-semibold text-body">{team.name}</h3>
                    </div>
                    <div className="mt-2 flex flex-wrap items-center gap-1.5">
                        <Badge tone="accent">
                            <Users className="h-3 w-3" />
                            {team.users_count} {team.users_count === 1 ? 'member' : 'members'}
                        </Badge>
                        {team.lead && (
                            <Badge tone="neutral">
                                <Crown className="h-3 w-3" />
                                {team.lead}
                            </Badge>
                        )}
                    </div>

                    {Array.isArray(team.members) && team.members.length > 0 && (
                        <p className="mt-3 truncate text-xs text-muted">
                            {team.members.join(', ')}
                        </p>
                    )}
                </div>

                {canManage && (
                    <div className="flex shrink-0 items-center gap-1">
                        <Button
                            variant="ghost"
                            size="sm"
                            className="!px-2"
                            onClick={() => onEdit(team)}
                            title="Rename / recolor"
                            aria-label={`Edit ${team.name}`}
                            icon={<Pencil className="h-4 w-4" />}
                        />
                        <Button
                            variant="ghost"
                            size="sm"
                            className="!px-2 hover:text-danger"
                            onClick={() => onDelete(team)}
                            title="Remove team"
                            aria-label={`Remove ${team.name}`}
                            icon={<Trash2 className="h-4 w-4" />}
                        />
                    </div>
                )}
            </div>
        </Card>
    );
}

export default function TeamsIndex({ auth, teams, canManage, preview = false }) {
    // In preview mode, ignore incoming props and render entirely from DEV_SAMPLE.
    const user = preview ? DEV_SAMPLE.user : auth?.user;
    const list = preview ? DEV_SAMPLE.teams : (teams ?? []);
    const manage = preview ? DEV_SAMPLE.canManage : Boolean(canManage);

    const [formOpen, setFormOpen] = useState(false);
    const [editingTeam, setEditingTeam] = useState(null);

    const openCreate = () => {
        setEditingTeam(null);
        setFormOpen(true);
    };

    const openEdit = (team) => {
        setEditingTeam(team);
        setFormOpen(true);
    };

    const remove = (team) => {
        if (preview) return; // no-op in offline QA
        if (confirm(`Remove the team "${team.name}"? Members will be unassigned (not deleted).`)) {
            router.delete(route('teams.destroy', team.id), { preserveScroll: true });
        }
    };

    const totalMembers = list.reduce((sum, t) => sum + (t.users_count || 0), 0);

    return (
        <AuthenticatedLayout
            user={user}
            header={<h2 className="text-xl font-semibold text-body">Teams</h2>}
        >
            <Head title="Teams" />

            <div className="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8 space-y-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <p className="max-w-2xl text-sm text-muted">
                        Group people into teams you can rename, recolor, or remove at any time.
                        Assign each person to a team from their profile.
                    </p>
                    {manage && (
                        <Button icon={<Plus className="h-4 w-4" />} onClick={openCreate}>
                            New team
                        </Button>
                    )}
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <StatCard
                        label="Teams"
                        value={list.length}
                        icon={<UsersRound className="h-5 w-5" />}
                        hint="Active groups in your organization"
                    />
                    <StatCard
                        label="Assigned members"
                        value={totalMembers}
                        icon={<Users className="h-5 w-5" />}
                        hint="People across all teams"
                    />
                </div>

                {list.length === 0 ? (
                    <Card padded={false}>
                        <EmptyState
                            icon={<UsersRound className="h-5 w-5" />}
                            title="No teams yet"
                            description="Create your first team to start grouping people together."
                            action={
                                manage ? (
                                    <Button icon={<Plus className="h-4 w-4" />} onClick={openCreate}>
                                        New team
                                    </Button>
                                ) : null
                            }
                        />
                    </Card>
                ) : (
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {list.map((team) => (
                            <TeamCard
                                key={team.id}
                                team={team}
                                canManage={manage}
                                onEdit={openEdit}
                                onDelete={remove}
                            />
                        ))}
                    </div>
                )}
            </div>

            {manage && (
                <TeamFormModal
                    show={formOpen}
                    team={editingTeam}
                    onClose={() => setFormOpen(false)}
                />
            )}
        </AuthenticatedLayout>
    );
}
