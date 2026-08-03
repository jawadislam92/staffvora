import { useMemo, useState } from 'react';
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
    StatCard,
    Table,
    TBody,
    Td,
    Th,
    THead,
    Tabs,
    Textarea,
    Tr,
} from '@/Components/ui';
import {
    Activity,
    CalendarRange,
    CheckCheck,
    CheckCircle2,
    CircleDollarSign,
    ClipboardCheck,
    Clock,
    Hourglass,
    Lock,
    // Pencil, NOT PenLine. With PenLine here, Rollup merges this page's chunk
    // into its sibling Timesheets/Show.jsx (which also imports PenLine) and the
    // page LOSES ITS VITE MANIFEST ENTRY — the built site then 500s on
    // /timesheets with "Unable to locate file in Vite manifest" while dev mode
    // works fine, which is exactly how it shipped broken. Verified by bisect:
    // removing only this import restores the manifest entry deterministically.
    Pencil,
    RotateCcw,
    Send,
    Undo2,
    XCircle,
} from 'lucide-react';

const cx = (...a) => a.filter(Boolean).join(' ');

/**
 * Ziggy throws on a name it doesn't know, which would take the whole page down
 * before the route lines land in routes/web.php. Every URL resolves through
 * this, so preview/QA renders regardless.
 */
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
    bulk: () => safeRoute('timesheets.bulk-review', undefined, '/timesheets/bulk-review'),
    reopen: () => safeRoute('timesheets.reopen', undefined, '/timesheets/reopen'),
};

export const STATUS_META = {
    open: { label: 'Open', tone: 'neutral', blurb: 'Not submitted yet' },
    submitted: { label: 'Awaiting approval', tone: 'warning', blurb: 'Waiting on an approver' },
    approved: { label: 'Approved', tone: 'success', blurb: 'Locked for payroll' },
    rejected: { label: 'Sent back', tone: 'danger', blurb: 'Needs changes, then resubmit' },
};

export function StatusBadge({ status, ...rest }) {
    const meta = STATUS_META[status] || STATUS_META.open;
    return (
        <Badge tone={meta.tone} dot {...rest}>
            {meta.label}
        </Badge>
    );
}

export const hrs = (value) => `${Number(value || 0).toFixed(2)}h`;

/*
 * DEV_SAMPLE — offline visual-QA fixture. Used ONLY when `preview` is true, so
 * the whole screen renders from memory with no props and no network. Mirrors
 * the prop shape of TimesheetApprovalController::index().
 */
const DEV_SAMPLE = {
    user: { id: 1, name: 'Jawad', email: 'jawad@staffvora.test', is_super_admin: true, permissions: [] },
    period: { key: 'weekly:2026-07-27', cadence: 'weekly', cadence_label: 'Weekly', start: '2026-07-27', end: '2026-08-02', label: '27 Jul – 2 Aug 2026', days: 7, is_current: true, has_ended: false },
    periods: [
        { key: 'weekly:2026-07-27', label: '27 Jul – 2 Aug 2026', is_current: true, start: '2026-07-27', end: '2026-08-02' },
        { key: 'weekly:2026-07-20', label: '20 – 26 Jul 2026', is_current: false, start: '2026-07-20', end: '2026-07-26' },
        { key: 'weekly:2026-07-13', label: '13 – 19 Jul 2026', is_current: false, start: '2026-07-13', end: '2026-07-19' },
        { key: 'weekly:2026-07-06', label: '6 – 12 Jul 2026', is_current: false, start: '2026-07-06', end: '2026-07-12' },
    ],
    cadence: { key: 'weekly', label: 'Weekly' },
    mine: {
        user: { id: 1, name: 'Jawad' },
        status: 'open',
        timesheet: null,
        totals: { total: 34.75, billable: 29.5, tracked: 30.25, manual: 4.5, entries: 41 },
        can_submit: true,
    },
    can: { review: true, review_self: true, reopen: true },
    filters: { status: 'all' },
    summary: {
        people: 6,
        counts: { open: 2, submitted: 2, approved: 1, rejected: 1 },
        pending_all_periods: 3,
    },
    queue: {
        rows: [
            { user: { id: 2, name: 'Aqsa Malik', email: 'aqsa@staffvora.test', designation: 'Developer' }, status: 'submitted', timesheet: { id: 11, status: 'submitted', submitted_at: '2026-08-02T09:12:00+00:00', note: 'Two days on the migration.' }, totals: { total: 41.25, billable: 38.0, tracked: 39.5, manual: 1.75, entries: 52 }, is_self: false, can_review: true, can_reopen: false },
            { user: { id: 3, name: 'Bilal Ahmed', email: 'bilal@staffvora.test', designation: 'QA' }, status: 'submitted', timesheet: { id: 12, status: 'submitted', submitted_at: '2026-08-02T08:40:00+00:00', note: null }, totals: { total: 37.0, billable: 30.5, tracked: 33.0, manual: 4.0, entries: 44 }, is_self: false, can_review: true, can_reopen: false },
            { user: { id: 4, name: 'Sana Riaz', email: 'sana@staffvora.test', designation: 'Designer' }, status: 'approved', timesheet: { id: 13, status: 'approved', locked_at: '2026-08-01T15:02:00+00:00', note: 'Approved for payroll.', approved_totals: { total: 40.0, billable: 36.0, tracked: 38.0, manual: 2.0, entries: 47 } }, totals: { total: 40.0, billable: 36.0, tracked: 38.0, manual: 2.0, entries: 47 }, is_self: false, can_review: false, can_reopen: true },
            { user: { id: 5, name: 'Hamza Khan', email: 'hamza@staffvora.test', designation: 'Support' }, status: 'rejected', timesheet: { id: 14, status: 'rejected', note: 'Thursday looks like a duplicate — please check.' }, totals: { total: 44.5, billable: 20.0, tracked: 22.0, manual: 22.5, entries: 60 }, is_self: false, can_review: false, can_reopen: false },
            { user: { id: 6, name: 'Zara Iqbal', email: 'zara@staffvora.test', designation: 'Developer' }, status: 'open', timesheet: null, totals: { total: 12.5, billable: 12.5, tracked: 12.5, manual: 0, entries: 15 }, is_self: false, can_review: false, can_reopen: false },
            { user: { id: 1, name: 'Jawad', email: 'jawad@staffvora.test', designation: 'Owner' }, status: 'open', timesheet: null, totals: { total: 34.75, billable: 29.5, tracked: 30.25, manual: 4.5, entries: 41 }, is_self: true, can_review: false, can_reopen: false },
        ],
        links: [],
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 6, from: 1, to: 6 },
    },
};

const STATUS_TABS = [
    { key: 'all', label: 'Everyone' },
    { key: 'submitted', label: 'Awaiting approval' },
    { key: 'approved', label: 'Approved' },
    { key: 'rejected', label: 'Sent back' },
    { key: 'open', label: 'Not submitted' },
];

/**
 * Timesheets — the pay-period approval surface.
 *
 * Your own period sits on top (submit it), and an approver additionally gets
 * the queue of everyone else's underneath, filtered by status and closable in
 * bulk. Every figure arrives pre-aggregated from SQL; this page never adds up
 * time entries itself.
 */
export default function TimesheetsIndex({
    auth,
    period,
    periods = [],
    cadence,
    mine,
    can,
    filters,
    queue,
    summary,
    preview = false,
}) {
    const S = preview ? DEV_SAMPLE : null;
    const user = S ? S.user : auth?.user;
    const currentPeriod = S ? S.period : period;
    const periodChoices = S ? S.periods : periods;
    const cadenceMeta = S ? S.cadence : cadence;
    const own = S ? S.mine : mine;
    const perms = S ? S.can : can || {};
    const activeStatus = (S ? S.filters : filters)?.status || 'all';
    const board = S ? S.queue : queue;
    const rollup = S ? S.summary : summary;

    const [dialog, setDialog] = useState(null); // { kind, userId, name, title }
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);

    const pendingRows = useMemo(
        () => (board?.rows || []).filter((row) => row.can_review),
        [board]
    );

    // ── Empty workspace vs empty filter ──────────────────────────────────────
    //
    // These are two completely different situations and they must not share a
    // sentence. "No one matches this filter" told a brand-new workspace it had
    // made a mistake, when in fact it had simply never run a pay period.
    //
    // The discriminator needs NO server probe and therefore no query: the queue
    // is the roster for the period, so with the status tab on "Everyone" there
    // is no filter left to blame — an empty queue means the workspace itself is
    // empty. Any other tab is, by definition, a filter narrowing it.
    const filteringByStatus = activeStatus !== 'all';
    const statusLabel = (STATUS_TABS.find((t) => t.key === activeStatus) || {}).label || '';
    const canInvite = Boolean(user?.is_super_admin || user?.permissions?.includes('users.manage'));

    // A period nobody has logged a minute against — the moment to say what this
    // screen is actually for rather than showing four confident zeroes.
    const ownPeriodUntouched = (own?.totals?.entries ?? 0) === 0 && own?.status === 'open';

    const go = (query) => {
        if (preview) return;
        router.get(URLS.index(), { period: currentPeriod.key, status: activeStatus, ...query }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const post = (url, payload, done) => {
        if (preview) {
            toast.success('Preview mode — nothing was saved.');
            done?.();
            return;
        }
        setBusy(true);
        router.post(url, payload, {
            preserveScroll: true,
            onFinish: () => { setBusy(false); done?.(); },
        });
    };

    const openDialog = (kind, row) => {
        setNote('');
        setDialog({
            kind,
            userId: row?.user?.id ?? null,
            name: row?.user?.name ?? null,
            count: kind === 'bulk' ? pendingRows.length : 1,
        });
    };

    const confirmDialog = () => {
        if (!dialog) return;
        const close = () => setDialog(null);

        if (dialog.kind === 'submit') {
            post(URLS.submit(), { period: currentPeriod.key, note }, close);
        } else if (dialog.kind === 'bulk') {
            post(URLS.bulk(), { period: currentPeriod.key, decision: 'approve', note }, close);
        } else if (dialog.kind === 'reopen') {
            post(URLS.reopen(), { period: currentPeriod.key, user_id: dialog.userId, note }, close);
        } else {
            post(URLS.review(), {
                period: currentPeriod.key,
                user_id: dialog.userId,
                decision: dialog.kind,
                note,
            }, close);
        }
    };

    const dialogCopy = {
        submit: {
            title: `Submit your timesheet for ${currentPeriod?.label}`,
            body: 'This tells your approver the period is complete and correct. You can still edit it until it is approved.',
            cta: 'Submit for approval',
            noteLabel: 'Note for your approver (optional)',
            variant: 'primary',
        },
        approve: {
            title: `Approve ${dialog?.name}'s timesheet`,
            body: 'Approving FREEZES every time entry in this pay period — they can no longer be edited, deleted, or added to. Reopening is the only way back.',
            cta: 'Approve and lock',
            noteLabel: 'Note (optional)',
            variant: 'primary',
        },
        reject: {
            title: `Send ${dialog?.name}'s timesheet back`,
            body: 'The period stays editable and the person is asked to fix it and resubmit.',
            cta: 'Send back',
            noteLabel: 'What needs fixing? (required)',
            variant: 'danger',
        },
        reopen: {
            title: `Reopen ${dialog?.name}'s timesheet`,
            body: 'This unlocks every time entry in the pay period again. The change is recorded against your name.',
            cta: 'Reopen period',
            noteLabel: 'Why is it being reopened? (optional)',
            variant: 'danger',
        },
        bulk: {
            title: `Approve ${dialog?.count} submitted ${dialog?.count === 1 ? 'timesheet' : 'timesheets'}`,
            body: `Every submitted timesheet for ${currentPeriod?.label} is approved and locked in one go. Anything not submitted is left alone.`,
            cta: 'Approve them all',
            noteLabel: 'Note added to each (optional)',
            variant: 'primary',
        },
    }[dialog?.kind] || {};

    return (
        <AuthenticatedLayout
            user={user}
            header={<h2 className="text-xl font-semibold text-body">Timesheets</h2>}
        >
            <Head title="Timesheets" />

            <div className="mx-auto max-w-[1600px] space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                {/* Period picker */}
                <Card>
                    <div className="flex flex-wrap items-end justify-between gap-4">
                        <div className="flex flex-wrap items-end gap-3">
                            <Field label="Pay period" className="w-full sm:w-72">
                                <Select
                                    value={currentPeriod?.key || ''}
                                    onChange={(e) => go({ period: e.target.value })}
                                >
                                    {periodChoices.map((p) => (
                                        <option key={p.key} value={p.key}>
                                            {p.label}{p.is_current ? ' · current' : ''}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <div className="pb-1">
                                <Badge tone="info">
                                    <CalendarRange className="mr-1 h-3.5 w-3.5" />
                                    {cadenceMeta?.label || 'Weekly'}
                                </Badge>
                            </div>
                        </div>
                        <p className="pb-2 text-xs text-subtle">
                            {currentPeriod?.start} → {currentPeriod?.end}
                            {rollup?.pending_all_periods > 0 && perms.review ? (
                                <span className="ml-3 text-warning">
                                    {rollup.pending_all_periods} waiting across all periods
                                </span>
                            ) : null}
                        </p>
                    </div>
                </Card>

                {/* Your own timesheet */}
                <Card
                    header={
                        <CardHeader
                            title="Your timesheet"
                            description={`${currentPeriod?.label} · ${STATUS_META[own?.status]?.blurb || ''}`}
                            actions={
                                <div className="flex items-center gap-2">
                                    <StatusBadge status={own?.status} />
                                    {own?.can_submit && (
                                        <Button size="sm" icon={<Send className="h-4 w-4" />} onClick={() => openDialog('submit')}>
                                            Submit
                                        </Button>
                                    )}
                                    <Button
                                        as={Link}
                                        size="sm"
                                        variant="secondary"
                                        href={preview ? '#' : URLS.show(own?.user?.id, { period: currentPeriod?.key })}
                                    >
                                        Details
                                    </Button>
                                </div>
                            }
                        />
                    }
                >
                    {/* Contract accents: hours=1 (violet), billable=5 (pink), tracked=2 (green), manual=3 (blue) */}
                    <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                        <KpiCard accent={1} icon={<Clock className="h-[18px] w-[18px]" />} label="Total hours" value={hrs(own?.totals?.total)} sub={`${own?.totals?.entries || 0} entries`} />
                        <KpiCard accent={5} icon={<CircleDollarSign className="h-[18px] w-[18px]" />} label="Billable" value={hrs(own?.totals?.billable)} sub={`${pct(own?.totals?.billable, own?.totals?.total)} of total`} />
                        <KpiCard accent={2} icon={<Activity className="h-[18px] w-[18px]" />} label="Tracked" value={hrs(own?.totals?.tracked)} sub="Measured by the desktop tracker" />
                        <KpiCard accent={3} icon={<Pencil className="h-[18px] w-[18px]" />} label="Manual" value={hrs(own?.totals?.manual)} sub="Added by hand" />
                    </div>

                    {/* Four zeroes explain nothing on day one. Say what the
                        screen is for instead — and only while it is genuinely
                        empty, so it disappears the moment there is real data. */}
                    {ownPeriodUntouched && (
                        <div className="mt-4 flex items-start gap-2 rounded-lg border border-line bg-surface-2 p-3 text-sm text-muted">
                            <CalendarRange className="mt-0.5 h-4 w-4 shrink-0 text-subtle" />
                            <span>
                                Nothing is logged against {currentPeriod?.label} yet. Track time with the desktop
                                app or add it in your work diary — the totals here update as you go, and you submit
                                the period for approval once it is complete.
                            </span>
                        </div>
                    )}

                    {own?.status === 'approved' && (
                        <div className="mt-4 flex items-start gap-2 rounded-lg bg-success-soft p-3 text-sm text-success">
                            <Lock className="mt-0.5 h-4 w-4 shrink-0" />
                            <span>This pay period is approved and frozen for payroll — its entries can no longer be edited.</span>
                        </div>
                    )}
                    {own?.status === 'rejected' && own?.timesheet?.note && (
                        <div className="mt-4 flex items-start gap-2 rounded-lg bg-danger-soft p-3 text-sm text-danger">
                            <Undo2 className="mt-0.5 h-4 w-4 shrink-0" />
                            <span>Sent back: {own.timesheet.note}</span>
                        </div>
                    )}
                </Card>

                {/* Approver queue */}
                {perms.review && board && (
                    <>
                        {/* Approval-queue counts are TRUE STATUS — they keep the
                            status tokens (awaiting=warning, approved=success,
                            sent-back=danger) and never wear a viz identity hue. */}
                        {rollup && (
                            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                                <StatCard label="Awaiting approval" value={rollup.counts?.submitted ?? 0} hint="In this period" icon={<Hourglass className="h-4 w-4 text-warning" />} />
                                <StatCard label="Approved" value={rollup.counts?.approved ?? 0} hint="Locked for payroll" icon={<CheckCircle2 className="h-4 w-4 text-success" />} />
                                <StatCard label="Sent back" value={rollup.counts?.rejected ?? 0} hint="Needs a resubmit" icon={<XCircle className="h-4 w-4 text-danger" />} />
                                <StatCard label="Not submitted" value={rollup.counts?.open ?? 0} hint={`of ${rollup.people} people`} icon={<Send className="h-4 w-4 text-muted" />} />
                            </div>
                        )}

                        <Card
                            padded={false}
                            header={
                                <CardHeader
                                    title="Approval queue"
                                    description={`Everyone's time for ${currentPeriod?.label}. Totals are live until a timesheet is approved.`}
                                    actions={
                                        pendingRows.length > 0 ? (
                                            <Button
                                                size="sm"
                                                icon={<CheckCheck className="h-4 w-4" />}
                                                onClick={() => openDialog('bulk')}
                                            >
                                                Approve all submitted ({pendingRows.length})
                                            </Button>
                                        ) : null
                                    }
                                />
                            }
                        >
                            <div className="px-5 pt-4">
                                <Tabs
                                    tabs={STATUS_TABS}
                                    value={activeStatus}
                                    onChange={(key) => go({ status: key, page: 1 })}
                                    size="sm"
                                />
                            </div>

                            {board.rows.length === 0 ? (
                                <div className="p-6">
                                    {filteringByStatus ? (
                                        <EmptyState
                                            icon={<ClipboardCheck className="h-5 w-5" />}
                                            title={`Nobody is “${statusLabel}” in this period`}
                                            description={`The ${statusLabel.toLowerCase()} filter is on. Everyone else's timesheet for ${currentPeriod?.label} is in another state.`}
                                            action={(
                                                <Button
                                                    size="sm"
                                                    variant="secondary"
                                                    onClick={() => go({ status: 'all', page: 1 })}
                                                >
                                                    Show everyone
                                                </Button>
                                            )}
                                        />
                                    ) : (
                                        <EmptyState
                                            icon={<ClipboardCheck className="h-5 w-5" />}
                                            title="No one to approve yet"
                                            description={`A timesheet is a pay period's hours, signed off once and then frozen for payroll — so an approved period can't be quietly edited afterwards. ${currentPeriod?.label} will fill in on its own as your team tracks time; you approve it here when the period ends.`}
                                            action={canInvite ? (
                                                <Button as={Link} size="sm" href={safeRoute('users.create', undefined, '/users/create')}>
                                                    Invite your team
                                                </Button>
                                            ) : null}
                                        />
                                    )}
                                </div>
                            ) : (
                                <div className="mt-4 overflow-x-auto">
                                    <Table>
                                        <THead>
                                            <Tr>
                                                <Th>Person</Th>
                                                <Th>Status</Th>
                                                <Th className="text-right">Total</Th>
                                                <Th className="text-right">Billable</Th>
                                                <Th className="text-right">Tracked</Th>
                                                <Th className="text-right">Manual</Th>
                                                <Th className="text-right">Actions</Th>
                                            </Tr>
                                        </THead>
                                        <TBody>
                                            {board.rows.map((row) => (
                                                <Tr key={row.user.id}>
                                                    <Td>
                                                        <Link
                                                            href={preview ? '#' : URLS.show(row.user.id, { period: currentPeriod?.key })}
                                                            className="font-medium text-body transition hover:text-accent"
                                                        >
                                                            {row.user.name}
                                                            {row.is_self && <span className="ml-2 text-xs text-subtle">(you)</span>}
                                                        </Link>
                                                        <div className="text-xs text-subtle">{row.user.designation || row.user.email}</div>
                                                    </Td>
                                                    <Td>
                                                        <StatusBadge status={row.status} />
                                                        {row.status === 'rejected' && row.timesheet?.note && (
                                                            <div className="mt-1 max-w-xs truncate text-xs text-subtle" title={row.timesheet.note}>
                                                                {row.timesheet.note}
                                                            </div>
                                                        )}
                                                    </Td>
                                                    <Td className="text-right tabular-nums font-medium text-body">{hrs(row.totals.total)}</Td>
                                                    <Td className="text-right tabular-nums text-muted">{hrs(row.totals.billable)}</Td>
                                                    <Td className="text-right tabular-nums text-muted">{hrs(row.totals.tracked)}</Td>
                                                    <Td className="text-right tabular-nums text-muted">{hrs(row.totals.manual)}</Td>
                                                    <Td className="text-right">
                                                        <div className="flex justify-end gap-1.5">
                                                            {row.can_review && (
                                                                <>
                                                                    <Button
                                                                        size="sm"
                                                                        icon={<CheckCircle2 className="h-4 w-4" />}
                                                                        disabled={busy}
                                                                        onClick={() => openDialog('approve', row)}
                                                                    >
                                                                        Approve
                                                                    </Button>
                                                                    <Button
                                                                        size="sm"
                                                                        variant="secondary"
                                                                        icon={<XCircle className="h-4 w-4" />}
                                                                        disabled={busy}
                                                                        onClick={() => openDialog('reject', row)}
                                                                    >
                                                                        Send back
                                                                    </Button>
                                                                </>
                                                            )}
                                                            {row.can_reopen && (
                                                                <Button
                                                                    size="sm"
                                                                    variant="secondary"
                                                                    icon={<RotateCcw className="h-4 w-4" />}
                                                                    disabled={busy}
                                                                    onClick={() => openDialog('reopen', row)}
                                                                >
                                                                    Reopen
                                                                </Button>
                                                            )}
                                                            {row.status === 'submitted' && !row.can_review && (
                                                                <span className="text-xs text-subtle">
                                                                    {row.is_self ? 'Needs another approver' : '—'}
                                                                </span>
                                                            )}
                                                        </div>
                                                    </Td>
                                                </Tr>
                                            ))}
                                        </TBody>
                                    </Table>
                                </div>
                            )}

                            {board.meta?.last_page > 1 && (
                                <div className="flex items-center justify-between border-t border-line px-5 py-3 text-sm text-muted">
                                    <span>
                                        {board.meta.from}–{board.meta.to} of {board.meta.total}
                                    </span>
                                    <div className="flex gap-2">
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            disabled={board.meta.current_page <= 1}
                                            onClick={() => go({ page: board.meta.current_page - 1 })}
                                        >
                                            Previous
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            disabled={board.meta.current_page >= board.meta.last_page}
                                            onClick={() => go({ page: board.meta.current_page + 1 })}
                                        >
                                            Next
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </Card>
                    </>
                )}
            </div>

            <Modal show={!!dialog} onClose={() => setDialog(null)} maxWidth="lg">
                <div className="p-6">
                    <h3 className="text-base font-semibold text-body">{dialogCopy.title}</h3>
                    <p className="mt-2 text-sm text-muted">{dialogCopy.body}</p>

                    <div className="mt-4">
                        <Field label={dialogCopy.noteLabel}>
                            <Textarea
                                rows={3}
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                                placeholder={dialog?.kind === 'reject' ? 'e.g. Thursday looks like a duplicate entry.' : ''}
                            />
                        </Field>
                    </div>

                    <div className="mt-5 flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setDialog(null)}>Cancel</Button>
                        <Button
                            variant={dialogCopy.variant}
                            loading={busy}
                            disabled={dialog?.kind === 'reject' && note.trim() === ''}
                            onClick={confirmDialog}
                        >
                            {dialogCopy.cta}
                        </Button>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}

export function pct(part, whole) {
    const total = Number(whole || 0);
    if (total <= 0) return '0%';
    return `${Math.round((Number(part || 0) / total) * 100)}%`;
}
