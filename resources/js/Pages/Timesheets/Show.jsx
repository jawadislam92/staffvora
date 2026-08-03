import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import toast from 'react-hot-toast';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import {
    Badge,
    Button,
    Card,
    CardHeader,
    EmptyState,
    Field,
    KpiCard,
    Select,
    Table,
    TBody,
    Td,
    Th,
    THead,
    Textarea,
    Tr,
} from '@/Components/ui';
import { RankedBars } from '@/Components/charts';
import {
    Activity,
    ArrowLeft,
    CalendarRange,
    CheckCircle2,
    CircleDollarSign,
    Clock,
    History,
    Lock,
    PenLine,
    RotateCcw,
    Send,
    XCircle,
} from 'lucide-react';
import { STATUS_META, StatusBadge, hrs, pct } from './Index';

/** Ziggy throws on unknown names; keep the page renderable before routes land. */
function safeRoute(name, params, fallback) {
    try {
        return typeof route === 'function' ? route(name, params) : fallback;
    } catch {
        return fallback;
    }
}

const URLS = {
    index: (query) => safeRoute('timesheets.index', query, '/timesheets'),
    show: (id, query) => safeRoute('timesheets.show', { user: id, ...(query || {}) }, `/timesheets/${id}`),
    submit: () => safeRoute('timesheets.submit', undefined, '/timesheets/submit'),
    review: () => safeRoute('timesheets.review', undefined, '/timesheets/review'),
    reopen: () => safeRoute('timesheets.reopen', undefined, '/timesheets/reopen'),
};

const ACTION_META = {
    submitted: { label: 'Submitted', tone: 'warning' },
    approved: { label: 'Approved', tone: 'success' },
    rejected: { label: 'Sent back', tone: 'danger' },
    reopened: { label: 'Reopened', tone: 'info' },
};

/*
 * DEV_SAMPLE — offline visual-QA fixture, used ONLY when `preview` is true.
 * Mirrors TimesheetApprovalController::show().
 */
const DEV_SAMPLE = {
    user: { id: 1, name: 'Jawad', email: 'jawad@staffvora.test', is_super_admin: true, permissions: [] },
    period: { key: 'weekly:2026-07-20', cadence: 'weekly', cadence_label: 'Weekly', start: '2026-07-20', end: '2026-07-26', label: '20 – 26 Jul 2026', days: 7, is_current: false, has_ended: true },
    periods: [
        { key: 'weekly:2026-07-27', label: '27 Jul – 2 Aug 2026', is_current: true },
        { key: 'weekly:2026-07-20', label: '20 – 26 Jul 2026', is_current: false },
        { key: 'weekly:2026-07-13', label: '13 – 19 Jul 2026', is_current: false },
    ],
    person: { id: 2, name: 'Aqsa Malik', email: 'aqsa@staffvora.test', designation: 'Developer' },
    status: 'submitted',
    timesheet: { id: 11, status: 'submitted', period_key: 'weekly:2026-07-20', submitted_at: '2026-07-27T04:12:00+00:00', note: 'Two days on the migration, one on the report page.', approved_totals: null },
    totals: { total: 41.25, billable: 38.0, tracked: 39.5, manual: 1.75, entries: 52 },
    categories: [
        { id: 1, name: 'Client Work', hours: 31.5, billable: 31.5, entries: 34 },
        { id: 2, name: 'Internal/Admin', hours: 5.25, billable: 0, entries: 9 },
        { id: 5, name: 'Meetings', hours: 3.0, billable: 3.0, entries: 6 },
        { id: 4, name: 'Training/Onboarding', hours: 1.5, billable: 1.5, entries: 3 },
    ],
    daily: [
        { date: '2026-07-20', hours: 8.25, billable: 7.5, tracked: 8.0, entries: 9 },
        { date: '2026-07-21', hours: 7.75, billable: 7.75, tracked: 7.75, entries: 8 },
        { date: '2026-07-22', hours: 8.5, billable: 8.0, tracked: 8.5, entries: 11 },
        { date: '2026-07-23', hours: 9.0, billable: 8.25, tracked: 8.25, entries: 12 },
        { date: '2026-07-24', hours: 7.75, billable: 6.5, tracked: 7.0, entries: 12 },
    ],
    history: [
        { id: 3, action: 'submitted', from_status: 'rejected', to_status: 'submitted', note: 'Fixed the duplicate on Thursday.', actor: 'Aqsa Malik', total_hours: 41.25, at: '2026-07-27T04:12:00+00:00' },
        { id: 2, action: 'rejected', from_status: 'submitted', to_status: 'rejected', note: 'Thursday looks like a duplicate — please check.', actor: 'Jawad', total_hours: 44.5, at: '2026-07-26T11:02:00+00:00' },
        { id: 1, action: 'submitted', from_status: 'open', to_status: 'submitted', note: null, actor: 'Aqsa Malik', total_hours: 44.5, at: '2026-07-26T09:30:00+00:00' },
    ],
    is_self: false,
    can: { submit: false, review: true, reopen: false },
};

const when = (iso) => {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
    } catch {
        return iso;
    }
};

/**
 * RankedBars renders `value` verbatim and derives the bar width from `pct`, so
 * the hours are formatted here and the percentage is computed against the
 * biggest category rather than handing the component a raw number.
 */
const categoryBars = (categories) => {
    const peak = categories.reduce((max, c) => Math.max(max, c.hours || 0), 0) || 1;

    return categories.map((c) => ({
        label: c.name,
        value: hrs(c.hours),
        pct: ((c.hours || 0) / peak) * 100,
        meta: `${c.entries} ${c.entries === 1 ? 'entry' : 'entries'} · ${hrs(c.billable)} billable`,
    }));
};

const dayLabel = (d) => {
    try {
        return new Date(`${d}T00:00:00`).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
    } catch {
        return d;
    }
};

/**
 * One person's pay period in detail — the number an approver signs off on,
 * with the breakdown that explains it and the audit trail behind it.
 */
export default function TimesheetShow({
    auth,
    period,
    periods = [],
    person,
    status,
    timesheet,
    totals,
    categories = [],
    daily = [],
    history = [],
    is_self: isSelf,
    can,
    preview = false,
}) {
    const S = preview ? DEV_SAMPLE : null;
    const user = S ? S.user : auth?.user;
    const currentPeriod = S ? S.period : period;
    const periodChoices = S ? S.periods : periods;
    const who = S ? S.person : person;
    const state = S ? S.status : status;
    const sheet = S ? S.timesheet : timesheet;
    const sums = S ? S.totals : totals;
    const cats = S ? S.categories : categories;
    const days = S ? S.daily : daily;
    const trail = S ? S.history : history;
    const mine = S ? S.is_self : isSelf;
    const perms = (S ? S.can : can) || {};

    const [dialog, setDialog] = useState(null);
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);

    const peak = days.reduce((max, d) => Math.max(max, d.hours || 0), 0) || 1;

    const go = (periodKey) => {
        if (preview) return;
        router.get(URLS.show(who.id), { period: periodKey }, { preserveScroll: true, replace: true });
    };

    const post = (url, payload) => {
        if (preview) {
            toast.success('Preview mode — nothing was saved.');
            setDialog(null);
            return;
        }
        setBusy(true);
        router.post(url, payload, {
            preserveScroll: true,
            onFinish: () => { setBusy(false); setDialog(null); },
        });
    };

    const confirmDialog = () => {
        if (dialog === 'submit') {
            post(URLS.submit(), { period: currentPeriod.key, note });
        } else if (dialog === 'reopen') {
            post(URLS.reopen(), { period: currentPeriod.key, user_id: who.id, note });
        } else {
            post(URLS.review(), { period: currentPeriod.key, user_id: who.id, decision: dialog, note });
        }
    };

    const copy = {
        submit: { title: 'Submit this timesheet', body: 'This tells your approver the period is complete and correct.', cta: 'Submit for approval', variant: 'primary', noteLabel: 'Note (optional)' },
        approve: { title: `Approve ${who?.name}'s timesheet`, body: 'Approving FREEZES every entry in this period — no edits, no deletes, no new time. Reopening is the only way back.', cta: 'Approve and lock', variant: 'primary', noteLabel: 'Note (optional)' },
        reject: { title: `Send ${who?.name}'s timesheet back`, body: 'The period stays editable and the person is asked to fix it and resubmit.', cta: 'Send back', variant: 'danger', noteLabel: 'What needs fixing? (required)' },
        reopen: { title: `Reopen ${who?.name}'s timesheet`, body: 'This unlocks every entry in the period again. The change is recorded against your name.', cta: 'Reopen period', variant: 'danger', noteLabel: 'Why? (optional)' },
    }[dialog] || {};

    return (
        <AuthenticatedLayout
            user={user}
            header={<h2 className="text-xl font-semibold text-body">Timesheet · {who?.name}</h2>}
        >
            <Head title={`Timesheet · ${who?.name || ''}`} />

            <div className="mx-auto max-w-[1600px] space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                <Card>
                    <div className="flex flex-wrap items-end justify-between gap-4">
                        <div className="flex flex-wrap items-end gap-3">
                            <Button
                                as={Link}
                                variant="ghost"
                                size="sm"
                                href={preview ? '#' : URLS.index({ period: currentPeriod?.key })}
                                icon={<ArrowLeft className="h-4 w-4" />}
                            >
                                All timesheets
                            </Button>
                            <Field label="Pay period" className="w-full sm:w-72">
                                <Select value={currentPeriod?.key || ''} onChange={(e) => go(e.target.value)}>
                                    {periodChoices.map((p) => (
                                        <option key={p.key} value={p.key}>
                                            {p.label}{p.is_current ? ' · current' : ''}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <div className="pb-1 flex items-center gap-2">
                                <Badge tone="info">
                                    <CalendarRange className="mr-1 h-3.5 w-3.5" />
                                    {currentPeriod?.cadence_label}
                                </Badge>
                                <StatusBadge status={state} />
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center gap-2 pb-1">
                            {perms.submit && (
                                <Button size="sm" icon={<Send className="h-4 w-4" />} onClick={() => { setNote(''); setDialog('submit'); }}>
                                    Submit
                                </Button>
                            )}
                            {perms.review && (
                                <>
                                    <Button size="sm" icon={<CheckCircle2 className="h-4 w-4" />} onClick={() => { setNote(''); setDialog('approve'); }}>
                                        Approve
                                    </Button>
                                    <Button size="sm" variant="secondary" icon={<XCircle className="h-4 w-4" />} onClick={() => { setNote(''); setDialog('reject'); }}>
                                        Send back
                                    </Button>
                                </>
                            )}
                            {perms.reopen && (
                                <Button size="sm" variant="secondary" icon={<RotateCcw className="h-4 w-4" />} onClick={() => { setNote(''); setDialog('reopen'); }}>
                                    Reopen
                                </Button>
                            )}
                        </div>
                    </div>

                    <p className="mt-3 text-xs text-subtle">
                        {who?.designation ? `${who.designation} · ` : ''}{who?.email} · {currentPeriod?.start} → {currentPeriod?.end}
                        {mine ? ' · this is your own timesheet' : ''}
                    </p>

                    {state === 'approved' && (
                        <div className="mt-4 flex items-start gap-2 rounded-lg bg-success-soft p-3 text-sm text-success">
                            <Lock className="mt-0.5 h-4 w-4 shrink-0" />
                            <span>
                                Approved {when(sheet?.locked_at)} — every entry in this period is frozen for payroll.
                                {sheet?.approved_totals ? ` Signed off at ${hrs(sheet.approved_totals.total)}.` : ''}
                            </span>
                        </div>
                    )}
                    {state === 'rejected' && sheet?.note && (
                        <div className="mt-4 rounded-lg bg-danger-soft p-3 text-sm text-danger">Sent back: {sheet.note}</div>
                    )}
                    {state === 'submitted' && (
                        <div className="mt-4 rounded-lg bg-warning-soft p-3 text-sm text-warning">
                            Submitted {when(sheet?.submitted_at)}{sheet?.note ? ` — “${sheet.note}”` : ''}
                        </div>
                    )}
                </Card>

                {/* Contract accents: hours=1 (violet), billable=5 (pink), tracked=2 (green), manual=3 (blue) */}
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <KpiCard accent={1} icon={<Clock className="h-[18px] w-[18px]" />} label="Total hours" value={hrs(sums?.total)} sub={`${sums?.entries || 0} entries`} />
                    <KpiCard accent={5} icon={<CircleDollarSign className="h-[18px] w-[18px]" />} label="Billable" value={hrs(sums?.billable)} sub={`${pct(sums?.billable, sums?.total)} of total`} />
                    <KpiCard accent={2} icon={<Activity className="h-[18px] w-[18px]" />} label="Tracked" value={hrs(sums?.tracked)} sub={`${pct(sums?.tracked, sums?.total)} measured`} />
                    <KpiCard accent={3} icon={<PenLine className="h-[18px] w-[18px]" />} label="Manual" value={hrs(sums?.manual)} sub={`${pct(sums?.manual, sums?.total)} added by hand`} />
                </div>

                <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
                    <Card header={<CardHeader title="Where the time went" description="Hours by work category for this pay period." />}>
                        {cats.length === 0 ? (
                            <EmptyState title="No time logged" description="Nothing was recorded in this pay period." />
                        ) : (
                            <RankedBars items={categoryBars(cats)} />
                        )}
                    </Card>

                    <Card padded={false} header={<CardHeader title="Day by day" description="Aggregated in SQL — individual entries are never loaded here." />}>
                        {days.length === 0 ? (
                            <div className="p-6">
                                <EmptyState title="No days with time" description="Nothing was recorded in this pay period." />
                            </div>
                        ) : (
                            <div className="space-y-2 p-5">
                                {days.map((d) => (
                                    <div key={d.date} className="flex items-center gap-3">
                                        <span className="w-28 shrink-0 text-xs text-muted">{dayLabel(d.date)}</span>
                                        <div className="h-2.5 flex-1 overflow-hidden rounded-full bg-surface-2">
                                            {/* Hours series wears the time slot (viz-1), per the cross-page contract. */}
                                            <div
                                                className="h-full rounded-full"
                                                style={{
                                                    width: `${Math.max(3, Math.round((d.hours / peak) * 100))}%`,
                                                    backgroundColor: 'var(--viz-1)',
                                                }}
                                            />
                                        </div>
                                        <span className="w-16 shrink-0 text-right text-xs tabular-nums text-body">{hrs(d.hours)}</span>
                                        <span className="w-20 shrink-0 text-right text-xs tabular-nums text-subtle">{hrs(d.billable)} bill.</span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </Card>
                </div>

                <Card
                    padded={false}
                    header={
                        <CardHeader
                            title="Approval history"
                            description="Every transition, who made it and when. Append-only — nothing here is ever rewritten."
                            actions={<History className="h-4 w-4 text-subtle" />}
                        />
                    }
                >
                    {trail.length === 0 ? (
                        <div className="p-6">
                            <EmptyState
                                title="Nothing has happened yet"
                                description={`This pay period is ${STATUS_META[state]?.label?.toLowerCase() || 'open'}.`}
                            />
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <Table>
                                <THead>
                                    <Tr>
                                        <Th>Action</Th>
                                        <Th>By</Th>
                                        <Th>When</Th>
                                        <Th className="text-right">Total then</Th>
                                        <Th>Note</Th>
                                    </Tr>
                                </THead>
                                <TBody>
                                    {trail.map((row) => (
                                        <Tr key={row.id}>
                                            <Td>
                                                <Badge tone={ACTION_META[row.action]?.tone || 'neutral'} dot>
                                                    {ACTION_META[row.action]?.label || row.action}
                                                </Badge>
                                            </Td>
                                            <Td className="text-muted">{row.actor || 'System'}</Td>
                                            <Td className="text-muted">{when(row.at)}</Td>
                                            <Td className="text-right tabular-nums text-muted">
                                                {row.total_hours === null || row.total_hours === undefined ? '—' : hrs(row.total_hours)}
                                            </Td>
                                            <Td className="max-w-md text-muted">{row.note || '—'}</Td>
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        </div>
                    )}
                </Card>
            </div>

            <Modal show={!!dialog} onClose={() => setDialog(null)} maxWidth="lg">
                <div className="p-6">
                    <h3 className="text-base font-semibold text-body">{copy.title}</h3>
                    <p className="mt-2 text-sm text-muted">{copy.body}</p>
                    <div className="mt-4">
                        <Field label={copy.noteLabel}>
                            <Textarea rows={3} value={note} onChange={(e) => setNote(e.target.value)} />
                        </Field>
                    </div>
                    <div className="mt-5 flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setDialog(null)}>Cancel</Button>
                        <Button
                            variant={copy.variant}
                            loading={busy}
                            disabled={dialog === 'reject' && note.trim() === ''}
                            onClick={confirmDialog}
                        >
                            {copy.cta}
                        </Button>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
