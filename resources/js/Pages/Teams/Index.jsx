import { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Users, Plus, Trash2, Check, X, Pencil } from 'lucide-react';

const PALETTE = ['#f97316', '#22c55e', '#3b82f6', '#a855f7', '#ec4899', '#eab308', '#14b8a6', '#ef4444'];

function ColorDots({ value, onChange }) {
    return (
        <div className="flex items-center gap-1.5">
            {PALETTE.map((c) => (
                <button
                    key={c}
                    type="button"
                    onClick={() => onChange(c)}
                    className={`h-5 w-5 rounded-full ring-2 transition ${value === c ? 'ring-white' : 'ring-transparent hover:ring-slate-500'}`}
                    style={{ backgroundColor: c }}
                    aria-label={`Pick ${c}`}
                />
            ))}
        </div>
    );
}

function TeamRow({ team, canManage }) {
    const [editing, setEditing] = useState(false);
    const [name, setName] = useState(team.name);
    const [color, setColor] = useState(team.color || '#f97316');

    const save = () => {
        router.patch(route('teams.update', team.id), { name, color }, {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    const remove = () => {
        if (confirm(`Remove the team "${team.name}"? Members will be unassigned (not deleted).`)) {
            router.delete(route('teams.destroy', team.id), { preserveScroll: true });
        }
    };

    return (
        <div className="flex items-center gap-3 px-5 py-3.5">
            <span className="h-3.5 w-3.5 shrink-0 rounded-full" style={{ backgroundColor: team.color || '#64748b' }} />
            {editing ? (
                <>
                    <input
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        className="w-48 rounded-md border-slate-700 bg-slate-900 text-sm text-slate-100 focus:border-orange-500"
                        autoFocus
                    />
                    <ColorDots value={color} onChange={setColor} />
                    <button onClick={save} className="ml-auto inline-flex items-center gap-1 rounded-md bg-orange-500 px-2.5 py-1 text-xs font-semibold text-slate-950 hover:bg-orange-400">
                        <Check className="h-3.5 w-3.5" /> Save
                    </button>
                    <button onClick={() => { setEditing(false); setName(team.name); setColor(team.color || '#f97316'); }} className="rounded-md border border-slate-700 px-2 py-1 text-xs text-slate-300 hover:bg-slate-800">
                        <X className="h-3.5 w-3.5" />
                    </button>
                </>
            ) : (
                <>
                    <span className="text-sm font-medium text-slate-100">{team.name}</span>
                    <span className="inline-flex items-center gap-1 rounded-full bg-slate-800 px-2 py-0.5 text-xs text-slate-300">
                        <Users className="h-3 w-3" /> {team.users_count}
                    </span>
                    {canManage && (
                        <div className="ml-auto flex items-center gap-1">
                            <button onClick={() => setEditing(true)} className="rounded-md p-1.5 text-slate-400 hover:bg-slate-800 hover:text-slate-100" title="Rename / recolor">
                                <Pencil className="h-4 w-4" />
                            </button>
                            <button onClick={remove} className="rounded-md p-1.5 text-slate-400 hover:bg-rose-500/15 hover:text-rose-300" title="Remove team">
                                <Trash2 className="h-4 w-4" />
                            </button>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

export default function TeamsIndex({ auth, teams, canManage }) {
    const form = useForm({ name: '', color: PALETTE[0] });

    const submit = (e) => {
        e.preventDefault();
        form.post(route('teams.store'), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="text-xl font-semibold text-slate-100">Teams</h2>}>
            <Head title="Teams" />
            <div className="min-h-screen bg-slate-950">
                <div className="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
                    <p className="mb-6 text-sm text-slate-400">
                        Group people into teams you can rename, recolor, or remove at any time. Assign each person to a team on their profile.
                    </p>

                    {canManage && (
                        <form onSubmit={submit} className="mb-6 rounded-lg border border-slate-800 bg-slate-900 p-4">
                            <div className="flex flex-wrap items-center gap-3">
                                <input
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    placeholder="New team name"
                                    className="w-56 rounded-md border-slate-700 bg-slate-950 text-sm text-slate-100 placeholder:text-slate-500 focus:border-orange-500"
                                />
                                <ColorDots value={form.data.color} onChange={(c) => form.setData('color', c)} />
                                <button
                                    type="submit"
                                    disabled={form.processing || !form.data.name.trim()}
                                    className="ml-auto inline-flex items-center gap-1.5 rounded-md bg-orange-500 px-3 py-1.5 text-sm font-semibold text-slate-950 hover:bg-orange-400 disabled:opacity-50"
                                >
                                    <Plus className="h-4 w-4" /> Add team
                                </button>
                            </div>
                            {form.errors.name && <p className="mt-2 text-xs text-rose-400">{form.errors.name}</p>}
                        </form>
                    )}

                    <div className="overflow-hidden rounded-lg border border-slate-800 bg-slate-900 divide-y divide-slate-800">
                        {teams.length === 0 && (
                            <p className="px-5 py-8 text-center text-sm text-slate-400">No teams yet. Add your first team above.</p>
                        )}
                        {teams.map((team) => (
                            <TeamRow key={team.id} team={team} canManage={canManage} />
                        ))}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
