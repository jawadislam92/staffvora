import { useMemo, useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CalendarClock, Clock, Info, Trash2 } from 'lucide-react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import {
    Badge,
    Button,
    Card,
    CardHeader,
    EmptyState,
    Field,
    Input,
    StatCard,
} from '@/Components/ui';

// "08:00" -> "8:00 AM"
function prettyTime(hhmm) {
    if (!hhmm) return null;
    const [h, m] = String(hhmm).split(':').map(Number);
    if (Number.isNaN(h)) return null;
    const period = h >= 12 ? 'PM' : 'AM';
    const h12 = h % 12 === 0 ? 12 : h % 12;
    return `${h12}:${String(m || 0).padStart(2, '0')} ${period}`;
}

// 7.5 -> "7h 30m"
function prettyHours(hours) {
    if (hours === null || hours === undefined || hours === '') return null;
    const n = Number(hours);
    if (Number.isNaN(n)) return null;
    const h = Math.floor(n);
    const mins = Math.round((n - h) * 60);
    return mins ? `${h}h ${mins}m` : `${h}h`;
}

// ---------------------------------------------------------------------------
// DEV_SAMPLE — used ONLY when `preview` is true so the page renders fully
// offline for visual QA. Mirrors the ShiftOverrideController payload
// (standingShift + overrides + today + canEdit + canManageAll) with a couple of
// upcoming changes so the list, the badges and the remove action can all be
// seen without a database. Never touched in normal rendering.
// ---------------------------------------------------------------------------
const DEV_SAMPLE = {
    user: {
        id: 1,
        name: 'Jawad',
        email: 'jawad@staffvora.com',
        is_super_admin: true,
        permissions: [],
    },
    standingShift: { name: 'Morning', start_time: '09:00', hours: 8, grace_minutes: 15 },
    today: '2026-07-29',
    overrides: [
        { id: 1, date: '2026-07-31', date_label: 'Friday, Jul 31', shift_start_time: '08:00', shift_hours: 6, reason: 'Off Saturday, covering Friday early' },
        { id: 2, date: '2026-08-04', date_label: 'Tuesday, Aug 4', shift_start_time: '13:00', shift_hours: null, reason: null },
    ],
    canEdit: true,
    canManageAll: true,
};

export default function MySchedule({
    auth,
    standingShift = {},
    overrides = [],
    today = null,
    canEdit = false,
    canManageAll = false,
    preview = false,
}) {
    // In preview mode, ignore incoming props and render entirely from DEV_SAMPLE.
    const authUser = preview ? DEV_SAMPLE.user : auth?.user;
    standingShift = preview ? DEV_SAMPLE.standingShift : (standingShift || {});
    overrides = preview ? DEV_SAMPLE.overrides : (overrides || []);
    today = preview ? DEV_SAMPLE.today : today;
    canEdit = preview ? DEV_SAMPLE.canEdit : canEdit;
    canManageAll = preview ? DEV_SAMPLE.canManageAll : canManageAll;

    const page = usePage();
    const flash = preview ? {} : (page.props?.flash || {});

    // "Today" comes from the server in the WORKSPACE's business timezone — the
    // same day boundary attendance buckets on — so the date picker's minimum
    // matches the rule the controller enforces, whatever machine you are on.
    const minDate = useMemo(() => {
        if (today) return today;
        try {
            return new Date().toLocaleDateString('en-CA');
        } catch {
            return '';
        }
    }, [today]);

    const [removing, setRemoving] = useState(null);

    const form = useForm({
        date: minDate,
        shift_start_time: standingShift.start_time || '',
        shift_hours: standingShift.hours ?? '',
        reason: '',
    });

    const submit = (event) => {
        event.preventDefault();
        if (preview) return;
        form.post(route('shift-overrides.store'), {
            preserveScroll: true,
            onSuccess: () => form.setData('reason', ''),
        });
    };

    const remove = (override) => {
        if (preview) return;
        if (!window.confirm(`Remove the shift change for ${override.date_label}?`)) return;
        setRemoving(override.id);
        router.delete(route('shift-overrides.destroy', override.id), {
            preserveScroll: true,
            onFinish: () => setRemoving(null),
        });
    };

    const standingStart = prettyTime(standingShift.start_time);
    const standingLength = prettyHours(standingShift.hours);

    return (
        <AuthenticatedLayout user={authUser} header="My Schedule">
            <Head title="My Schedule" />

            <div className="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8 space-y-6">
                <p className="max-w-3xl text-sm text-muted">
                    Change your shift for a single day — for example, start earlier on Friday because you are off
                    Saturday. A change only moves that day&apos;s schedule; it never adds clock-ins or hours.
                </p>

                {flash.success && (
                    <div className="rounded-lg border border-line bg-success-soft px-4 py-3 text-sm text-success">
                        {flash.success}
                    </div>
                )}

                {/* Standing shift */}
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Standing start"
                        value={standingStart || 'Not set'}
                        icon={<Clock className="h-5 w-5" />}
                    />
                    <StatCard label="Shift length" value={standingLength || '—'} />
                    <StatCard label="Grace period" value={`${standingShift.grace_minutes ?? 0} min`} />
                    <StatCard
                        label="Named shift"
                        value={standingShift.name || 'None'}
                        icon={<CalendarClock className="h-5 w-5" />}
                    />
                </div>

                <Card>
                    <p className="flex items-start gap-2 text-sm text-muted">
                        <Info className="mt-0.5 h-4 w-4 shrink-0 text-info" aria-hidden="true" />
                        <span>
                            A one-day change only moves that day&apos;s schedule — when you are expected in, and when a
                            forgotten clock-out auto-closes. It never creates clock-ins or tracked time, so it cannot
                            inflate your hours. Times are in your workspace&apos;s business timezone.
                            {canManageAll && ' As an admin you can also change past days; everyone else is limited to today and later.'}
                        </span>
                    </p>
                </Card>

                {/* Add a one-day change */}
                {canEdit && (
                    <Card padded={false}>
                        <CardHeader
                            title="Change a day"
                            description="Set a start time, a length, or both. Anything you leave blank keeps your standing shift."
                        />
                        <form onSubmit={submit} className="space-y-4 p-5">
                            <div className="grid gap-4 sm:grid-cols-3">
                                <Field label="Date" htmlFor="schedule-date" required error={form.errors.date}>
                                    <Input
                                        id="schedule-date"
                                        type="date"
                                        min={canManageAll ? undefined : minDate}
                                        value={form.data.date}
                                        onChange={(event) => form.setData('date', event.target.value)}
                                        required
                                    />
                                </Field>

                                <Field
                                    label="Start time"
                                    htmlFor="schedule-start"
                                    error={form.errors.shift_start_time}
                                    hint={standingStart ? `Standing: ${standingStart}` : undefined}
                                >
                                    <Input
                                        id="schedule-start"
                                        type="time"
                                        value={form.data.shift_start_time}
                                        onChange={(event) => form.setData('shift_start_time', event.target.value)}
                                    />
                                </Field>

                                <Field
                                    label="Length (hours)"
                                    htmlFor="schedule-hours"
                                    error={form.errors.shift_hours}
                                    hint={standingLength ? `Standing: ${standingLength}` : 'e.g. 8'}
                                >
                                    <Input
                                        id="schedule-hours"
                                        type="number"
                                        min="0"
                                        max="16"
                                        step="0.5"
                                        value={form.data.shift_hours}
                                        onChange={(event) => form.setData('shift_hours', event.target.value)}
                                    />
                                </Field>
                            </div>

                            <Field label="Reason (optional)" htmlFor="schedule-reason" error={form.errors.reason}>
                                <Input
                                    id="schedule-reason"
                                    type="text"
                                    maxLength={255}
                                    placeholder="Off Saturday, covering Friday early"
                                    value={form.data.reason}
                                    onChange={(event) => form.setData('reason', event.target.value)}
                                />
                            </Field>

                            <div className="flex justify-end">
                                <Button type="submit" loading={form.processing}>
                                    {form.processing ? 'Saving…' : 'Save change'}
                                </Button>
                            </div>
                        </form>
                    </Card>
                )}

                {/* Upcoming changes */}
                <Card padded={false}>
                    <CardHeader
                        title="Upcoming changes"
                        description={`${overrides.length} scheduled change${overrides.length === 1 ? '' : 's'} from today onward`}
                    />

                    {overrides.length === 0 ? (
                        <EmptyState
                            icon={<CalendarClock className="h-5 w-5" />}
                            title="No upcoming shift changes"
                            description="Your standing shift applies to every day."
                        />
                    ) : (
                        <ul className="divide-y divide-line">
                            {overrides.map((override) => {
                                const start = prettyTime(override.shift_start_time) || standingStart;
                                const length = prettyHours(override.shift_hours) ?? standingLength;

                                return (
                                    <li
                                        key={override.id}
                                        className="flex flex-wrap items-center justify-between gap-3 px-5 py-4"
                                    >
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="text-sm font-medium text-body">{override.date_label}</span>
                                                {override.date === today && (
                                                    <Badge tone="accent">Today</Badge>
                                                )}
                                                {!override.shift_start_time && (
                                                    <Badge tone="neutral">Length only</Badge>
                                                )}
                                            </div>
                                            <div className="mt-1 text-sm text-muted">
                                                {start || '—'}
                                                {length ? ` · ${length}` : ''}
                                                {override.reason ? ` — ${override.reason}` : ''}
                                            </div>
                                        </div>
                                        {canEdit && (
                                            <Button
                                                variant="danger"
                                                size="sm"
                                                className="!px-2"
                                                loading={removing === override.id}
                                                onClick={() => remove(override)}
                                                title={`Remove the change for ${override.date_label}`}
                                                aria-label={`Remove the change for ${override.date_label}`}
                                                icon={<Trash2 className="h-4 w-4" />}
                                            />
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
