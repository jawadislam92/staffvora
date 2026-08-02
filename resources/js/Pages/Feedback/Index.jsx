import { useEffect, useMemo, useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import Avatar from '@/Components/Avatar';
import {
    Button,
    Card,
    Badge,
    StatCard,
    Table,
    THead,
    TBody,
    Tr,
    Th,
    Td,
    Field,
    Input,
    Select,
    Textarea,
    EmptyState,
    Tabs,
} from '@/Components/ui';
import {
    Plus,
    Inbox,
    MessageSquare,
    Search,
    Check,
    CheckCheck,
    Bell,
    CircleDot,
} from 'lucide-react';

const cx = (...a) => a.filter(Boolean).join(' ');

// Ticket statuses. `tone` maps each one onto a semantic Badge tone so the inbox
// reads correctly in all three themes without a single raw color.
const STATUS_META = {
    new: { label: 'New', tone: 'info' },
    in_review: { label: 'In review', tone: 'warning' },
    planned: { label: 'Planned', tone: 'accent' },
    done: { label: 'Done', tone: 'success' },
    declined: { label: 'Declined', tone: 'neutral' },
};
const STATUS_ORDER = ['new', 'in_review', 'planned', 'done', 'declined'];
const OPEN_STATUSES = ['new', 'in_review', 'planned'];
const CLOSED_STATUSES = ['done', 'declined'];

const TYPE_LABEL = {
    feature_request: 'Feature',
    question: 'Question',
    bug: 'Bug',
    missing_doc: 'Missing doc',
};
const TYPE_OPTIONS = [
    ['feature_request', 'Feature request'],
    ['question', 'Question'],
    ['bug', 'Something is broken'],
    ['missing_doc', 'Missing from Help'],
];

// Honors the workspace display settings (timezone + 12/24h) instead of the
// viewer's machine locale — same convention as the monitoring pages.
const DEFAULT_DISPLAY = { timezone: 'Asia/Karachi', format: '12' };

function makeFormatter(display) {
    return (iso) => {
        if (!iso) return '—';
        try {
            return new Date(iso).toLocaleString('en-US', {
                timeZone: display.timezone,
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                hour12: String(display.format) !== '24',
            });
        } catch {
            return iso;
        }
    };
}

// ---------------------------------------------------------------------------
// DEV_SAMPLE — used ONLY when `preview` is true so the page renders fully
// offline for visual QA (no auth, no database). Mirrors the controller's
// payload exactly: items[] as shaped by FeedbackController::present(), plus a
// mock super-admin user. Never touched in normal rendering.
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
    items: [
        {
            id: 1,
            type: 'feature_request',
            subject: 'Let us export attendance as PDF, not just CSV',
            message: 'Finance wants a signed monthly sheet. CSV means someone re-formats it every month.',
            context: { query: 'export pdf', url: '/attendance' },
            status: 'new',
            created_at: '2026-07-28T09:12:00+00:00',
            last_activity_at: '2026-07-28T09:12:00+00:00',
            user: { id: 4, name: 'Ayesha Khan', avatar_url: null },
            handler: null,
            messages: [],
            unread: true,
            submitter_read_latest: null,
        },
        {
            id: 2,
            type: 'bug',
            subject: 'Timer keeps counting during a break',
            message: 'Started a break at 2:10pm on the desktop app and the counter kept moving.',
            context: null,
            status: 'in_review',
            created_at: '2026-07-27T11:40:00+00:00',
            last_activity_at: '2026-07-27T15:02:00+00:00',
            user: { id: 7, name: 'Bilal Ahmed', avatar_url: null },
            handler: { name: 'Jawad' },
            messages: [
                {
                    id: 11,
                    body: 'Thanks — reproduced it on 0.4.5. Fix is in review, should ship this week.',
                    created_at: '2026-07-27T15:02:00+00:00',
                    from_submitter: false,
                    author: { id: 1, name: 'Jawad', avatar_url: null },
                },
            ],
            unread: false,
            submitter_read_latest: false,
        },
        {
            id: 3,
            type: 'question',
            subject: 'How do I move someone to another team?',
            message: 'Cannot find where team assignment lives.',
            context: { query: 'change team', url: '/help' },
            status: 'done',
            created_at: '2026-07-24T08:05:00+00:00',
            last_activity_at: '2026-07-24T10:30:00+00:00',
            user: { id: 9, name: 'Maria Lopez', avatar_url: null },
            handler: { name: 'Jawad' },
            messages: [
                {
                    id: 21,
                    body: 'It is on the person’s profile — Users → open them → Team. Added it to the Help centre too.',
                    created_at: '2026-07-24T10:30:00+00:00',
                    from_submitter: false,
                    author: { id: 1, name: 'Jawad', avatar_url: null },
                },
                {
                    id: 22,
                    body: 'Found it, thank you!',
                    created_at: '2026-07-24T10:44:00+00:00',
                    from_submitter: true,
                    author: { id: 9, name: 'Maria Lopez', avatar_url: null },
                },
            ],
            unread: false,
            submitter_read_latest: true,
        },
        {
            id: 4,
            type: 'missing_doc',
            subject: 'Nothing in Help about idle time rules',
            message: 'Searched "idle" and got no article. What actually counts as idle?',
            context: { query: 'idle', url: '/help' },
            status: 'planned',
            created_at: '2026-07-22T13:20:00+00:00',
            last_activity_at: '2026-07-22T13:20:00+00:00',
            user: { id: 12, name: 'Omar Farooq', avatar_url: null },
            handler: null,
            messages: [],
            unread: false,
            submitter_read_latest: null,
        },
    ],
};

function StatusBadge({ status }) {
    const meta = STATUS_META[status] || STATUS_META.new;
    return <Badge tone={meta.tone}>{meta.label}</Badge>;
}

/** One post in the thread — the opening request or any later reply. */
function MessageRow({ authorName, avatarUser, when, body, mine = false, opening = false }) {
    return (
        <div className="flex items-start gap-3">
            <div className="shrink-0">
                {avatarUser ? (
                    <Avatar user={{ name: avatarUser.name, avatar_url: avatarUser.avatar_url }} size="sm" />
                ) : (
                    <div className="h-8 w-8 rounded-full bg-surface-2" />
                )}
            </div>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm font-semibold text-body">
                        {authorName || 'Unknown'}
                        {mine ? ' (you)' : ''}
                    </span>
                    <span className="text-xs text-subtle">{when}</span>
                    {opening && (
                        <span className="rounded bg-surface-2 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-muted">
                            Request
                        </span>
                    )}
                </div>
                <div
                    className={cx(
                        'mt-1 whitespace-pre-wrap rounded-lg px-3 py-2 text-sm',
                        mine ? 'bg-accent/10 text-body' : 'bg-surface-2 text-body'
                    )}
                >
                    {body}
                </div>
            </div>
        </div>
    );
}

/**
 * The thread dialog: read the conversation, change status (managers), reply.
 * Replying and re-statusing both stamp the acting side as "seen", so the other
 * side is the one that badges.
 */
function ThreadModal({ show, item, canManage, viewerId, fmtWhen, preview, onClose }) {
    const [body, setBody] = useState('');
    const [nextStatus, setNextStatus] = useState('');
    const [sending, setSending] = useState(false);

    useEffect(() => {
        setBody('');
        setNextStatus('');
    }, [item?.id, show]);

    if (!item) return null;

    const sendReply = () => {
        if (!body.trim() || preview) return;
        setSending(true);
        const payload = { body };
        if (canManage && nextStatus) payload.status = nextStatus;
        router.post(route('feedback.reply', item.id), payload, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setBody('');
                setNextStatus('');
            },
            onFinish: () => setSending(false),
        });
    };

    const changeStatus = (status) => {
        if (preview) return;
        router.patch(route('feedback.update', item.id), { status }, { preserveScroll: true, preserveState: true });
    };

    const firstName = item.user?.name?.split(' ')[0] || 'them';

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <div className="flex max-h-[85vh] flex-col">
                {/* Header */}
                <div className="border-b border-line px-6 py-4">
                    <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                            <h3 className="text-base font-semibold text-body">{item.subject}</h3>
                            <p className="mt-0.5 text-xs text-muted">
                                {item.user?.name || 'Unknown'} · {TYPE_LABEL[item.type] || item.type} · opened{' '}
                                {fmtWhen(item.created_at)}
                                {item.handler?.name ? ` · handled by ${item.handler.name}` : ''}
                            </p>
                        </div>
                        <StatusBadge status={item.status} />
                    </div>

                    {canManage && (
                        <div className="mt-3 flex flex-wrap items-center gap-1.5">
                            {STATUS_ORDER.map((s) => {
                                const active = item.status === s;
                                return (
                                    <button
                                        key={s}
                                        type="button"
                                        onClick={() => changeStatus(s)}
                                        aria-pressed={active}
                                        className={cx(
                                            'rounded-md px-2 py-1 text-xs font-medium transition cursor-pointer',
                                            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app',
                                            active
                                                ? 'bg-accent/10 text-accent'
                                                : 'border border-line text-muted hover:text-body hover:bg-surface-2'
                                        )}
                                    >
                                        {STATUS_META[s].label}
                                    </button>
                                );
                            })}
                        </div>
                    )}
                </div>

                {/* Thread */}
                <div className="flex-1 space-y-3 overflow-y-auto px-6 py-4">
                    <MessageRow
                        authorName={item.user?.name}
                        avatarUser={item.user}
                        when={fmtWhen(item.created_at)}
                        body={item.message}
                        mine={item.user?.id === viewerId}
                        opening
                    />

                    {item.context?.query && (
                        <p className="ml-11 inline-flex items-center gap-1.5 rounded-lg bg-surface-2 px-2 py-1 text-xs text-muted">
                            <Search className="h-3 w-3" /> searched “{item.context.query}”
                        </p>
                    )}

                    {(item.messages || []).map((m) => (
                        <MessageRow
                            key={m.id}
                            authorName={m.author?.name}
                            avatarUser={m.author}
                            when={fmtWhen(m.created_at)}
                            body={m.body}
                            mine={m.author?.id === viewerId}
                        />
                    ))}

                    {/* Read receipt — managers see whether the submitter read the latest reply. */}
                    {canManage && item.submitter_read_latest !== null && item.submitter_read_latest !== undefined && (
                        <p className="ml-11 inline-flex items-center gap-1 text-xs text-subtle">
                            {item.submitter_read_latest ? (
                                <>
                                    <CheckCheck className="h-3.5 w-3.5 text-success" /> Seen by {firstName}
                                </>
                            ) : (
                                <>
                                    <Check className="h-3.5 w-3.5" /> Sent · not read yet
                                </>
                            )}
                        </p>
                    )}
                </div>

                {/* Reply box */}
                <div className="border-t border-line px-6 py-4">
                    <Textarea
                        value={body}
                        onChange={(e) => setBody(e.target.value)}
                        rows={2}
                        maxLength={4000}
                        placeholder={canManage ? 'Reply to the team member…' : 'Add a reply…'}
                        className="min-h-[4.5rem]"
                    />
                    <div className="mt-2 flex flex-wrap items-center justify-between gap-2">
                        {canManage ? (
                            <Select
                                value={nextStatus}
                                onChange={(e) => setNextStatus(e.target.value)}
                                className="w-auto text-xs"
                                aria-label="Change status with this reply"
                            >
                                <option value="">Keep status</option>
                                {STATUS_ORDER.map((s) => (
                                    <option key={s} value={s}>
                                        Set: {STATUS_META[s].label}
                                    </option>
                                ))}
                            </Select>
                        ) : (
                            <span className="text-xs text-subtle">A manager will see this in the inbox.</span>
                        )}
                        <div className="flex items-center gap-2">
                            <Button type="button" variant="secondary" onClick={onClose}>
                                Close
                            </Button>
                            <Button
                                type="button"
                                onClick={sendReply}
                                loading={sending}
                                disabled={!body.trim() || sending}
                            >
                                Send reply
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </Modal>
    );
}

/**
 * File a new request. `initial` can preset the type/subject/context (e.g. the
 * Help search that came up empty) when this is opened from elsewhere later.
 */
function NewRequestModal({ show, initial = {}, preview, onClose }) {
    const form = useForm({ type: 'feature_request', subject: '', message: '', context: null });

    useEffect(() => {
        if (show) {
            form.clearErrors();
            form.setData({
                type: initial.type || 'feature_request',
                subject: initial.subject || '',
                message: '',
                context: initial.context || null,
            });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show]);

    const submit = (e) => {
        e.preventDefault();
        if (preview) return;
        form.post(route('feedback.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="lg">
            <form onSubmit={submit} className="space-y-5 p-6">
                <div>
                    <h3 className="text-lg font-semibold text-body">Send a request</h3>
                    <p className="mt-1 text-sm text-muted">
                        Ask a question, request a feature, or report something broken. It lands in your
                        workspace's inbox and you can follow the replies here.
                    </p>
                </div>

                {initial.context?.query && (
                    <p className="rounded-lg bg-surface-2 px-3 py-2 text-xs text-muted">
                        You searched: <span className="text-body">“{initial.context.query}”</span>
                    </p>
                )}

                <Field label="Type" error={form.errors.type}>
                    <div className="flex flex-wrap gap-1.5">
                        {TYPE_OPTIONS.map(([key, label]) => {
                            const active = form.data.type === key;
                            return (
                                <button
                                    key={key}
                                    type="button"
                                    onClick={() => form.setData('type', key)}
                                    aria-pressed={active}
                                    className={cx(
                                        'rounded-md px-2.5 py-1 text-xs font-medium transition cursor-pointer',
                                        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app',
                                        active
                                            ? 'bg-accent/10 text-accent'
                                            : 'border border-line text-muted hover:text-body hover:bg-surface-2'
                                    )}
                                >
                                    {label}
                                </button>
                            );
                        })}
                    </div>
                </Field>

                <Field label="Subject" required error={form.errors.subject} htmlFor="feedback-subject">
                    <Input
                        id="feedback-subject"
                        value={form.data.subject}
                        onChange={(e) => form.setData('subject', e.target.value)}
                        maxLength={160}
                        placeholder="Short summary"
                        autoFocus
                    />
                </Field>

                <Field label="Details" required error={form.errors.message} htmlFor="feedback-message">
                    <Textarea
                        id="feedback-message"
                        value={form.data.message}
                        onChange={(e) => form.setData('message', e.target.value)}
                        rows={4}
                        maxLength={4000}
                        placeholder="What do you need, or what's missing?"
                    />
                </Field>

                <div className="flex items-center justify-end gap-2 pt-1">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        loading={form.processing}
                        disabled={!form.data.subject.trim() || !form.data.message.trim()}
                        icon={<Plus className="h-4 w-4" />}
                    >
                        Send request
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

export default function FeedbackIndex({ auth, items, canManage, preview = false }) {
    const page = usePage();

    // In preview mode, ignore incoming props and render entirely from DEV_SAMPLE.
    const user = preview ? DEV_SAMPLE.user : auth?.user;
    const list = preview ? DEV_SAMPLE.items : (items ?? []);
    const manage = preview ? DEV_SAMPLE.canManage : Boolean(canManage);

    const display = (!preview && page.props.display) || DEFAULT_DISPLAY;
    const fmtWhen = useMemo(() => makeFormatter(display), [display.timezone, display.format]);

    const [filter, setFilter] = useState(manage ? 'open' : 'all');
    const [openId, setOpenId] = useState(null);
    const [newOpen, setNewOpen] = useState(false);

    const byStatus = useMemo(() => {
        const m = {};
        list.forEach((i) => {
            m[i.status] = (m[i.status] || 0) + 1;
        });
        return m;
    }, [list]);

    const openCount = OPEN_STATUSES.reduce((n, s) => n + (byStatus[s] || 0), 0);
    const closedCount = CLOSED_STATUSES.reduce((n, s) => n + (byStatus[s] || 0), 0);
    const repliedCount = list.filter((i) => (i.messages || []).length > 0).length;
    const unreadCount = list.filter((i) => i.unread).length;

    const TABS = [
        { key: 'all', label: `All (${list.length})` },
        { key: 'open', label: `Open (${openCount})` },
        { key: 'replied', label: `Replied (${repliedCount})` },
        { key: 'closed', label: `Closed (${closedCount})` },
    ];

    const visible = useMemo(
        () =>
            list.filter((i) => {
                if (filter === 'all') return true;
                if (filter === 'open') return OPEN_STATUSES.includes(i.status);
                if (filter === 'closed') return CLOSED_STATUSES.includes(i.status);
                if (filter === 'replied') return (i.messages || []).length > 0;
                return i.status === filter;
            }),
        [list, filter]
    );

    const selected = list.find((i) => i.id === openId) || null;

    // Opening an unread ticket marks it read for THIS side (clears their badge).
    useEffect(() => {
        if (preview || !openId) return;
        const it = list.find((i) => i.id === openId);
        if (it?.unread) {
            router.post(route('feedback.seen', openId), {}, { preserveScroll: true, preserveState: true });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [openId]);

    const title = manage ? 'Feedback inbox' : 'My requests';

    return (
        <AuthenticatedLayout user={user} header={title}>
            <Head title={title} />

            <div className="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8 space-y-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <p className="max-w-2xl text-sm text-muted">
                        {manage
                            ? 'Questions, feature requests and bugs sent by your team — including searches the Help centre could not answer. Reply, set a status, and everyone sees where their request stands.'
                            : 'Requests you have sent, the replies you got, and where each one stands.'}
                    </p>
                    <Button icon={<Plus className="h-4 w-4" />} onClick={() => setNewOpen(true)}>
                        New request
                    </Button>
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <StatCard
                        label={manage ? 'Requests' : 'My requests'}
                        value={list.length}
                        icon={<Inbox className="h-5 w-5" />}
                        hint="Everything in this inbox"
                    />
                    <StatCard
                        label="Open"
                        value={openCount}
                        icon={<CircleDot className="h-5 w-5" />}
                        hint="New, in review or planned"
                    />
                    <StatCard
                        label="Needs your attention"
                        value={unreadCount}
                        icon={<Bell className="h-5 w-5" />}
                        hint={manage ? 'Unread activity from the team' : 'Replies you have not read'}
                    />
                </div>

                <Card padded={false}>
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4">
                        <Tabs tabs={TABS} value={filter} onChange={setFilter} size="sm" />
                        <span className="text-xs text-subtle">
                            {visible.length} {visible.length === 1 ? 'request' : 'requests'} shown
                        </span>
                    </div>

                    {list.length === 0 ? (
                        <EmptyState
                            icon={manage ? <Inbox className="h-5 w-5" /> : <MessageSquare className="h-5 w-5" />}
                            title={manage ? 'The inbox is empty' : 'No requests yet'}
                            description={
                                manage
                                    ? 'New requests from your team will land here — with the search that triggered them, so you can act without a back-and-forth.'
                                    : 'Send a request and follow its replies right here.'
                            }
                            action={
                                <Button icon={<Plus className="h-4 w-4" />} onClick={() => setNewOpen(true)}>
                                    New request
                                </Button>
                            }
                        />
                    ) : visible.length === 0 ? (
                        <EmptyState
                            icon={<Search className="h-5 w-5" />}
                            title="Nothing in this view"
                            description="No requests match this filter right now."
                            action={
                                <Button variant="secondary" onClick={() => setFilter('all')}>
                                    Show all
                                </Button>
                            }
                        />
                    ) : (
                        <Table>
                                <THead>
                                    <Tr>
                                        <Th>Request</Th>
                                        {manage && <Th>From</Th>}
                                        <Th>Type</Th>
                                        <Th>Status</Th>
                                        <Th>Replies</Th>
                                        <Th>Last activity</Th>
                                        <Th className="text-right">&nbsp;</Th>
                                    </Tr>
                                </THead>
                                <TBody>
                                    {visible.map((it) => (
                                        <Tr
                                            key={it.id}
                                            className="cursor-pointer hover:bg-surface-2"
                                            onClick={() => setOpenId(it.id)}
                                        >
                                            <Td>
                                                <div className="flex items-start gap-2">
                                                    <span
                                                        aria-hidden="true"
                                                        className={cx(
                                                            'mt-1.5 h-2 w-2 shrink-0 rounded-full',
                                                            it.unread ? 'bg-accent' : 'bg-transparent'
                                                        )}
                                                    />
                                                    <div className="min-w-0">
                                                        <p
                                                            className={cx(
                                                                'truncate max-w-[26rem]',
                                                                it.unread
                                                                    ? 'font-semibold text-body'
                                                                    : 'font-medium text-body'
                                                            )}
                                                        >
                                                            {it.subject}
                                                        </p>
                                                        {it.context?.query && (
                                                            <p className="mt-0.5 truncate max-w-[26rem] text-xs text-subtle">
                                                                searched “{it.context.query}”
                                                            </p>
                                                        )}
                                                    </div>
                                                </div>
                                            </Td>
                                            {manage && (
                                                <Td>
                                                    <div className="flex items-center gap-2">
                                                        <Avatar
                                                            user={{
                                                                name: it.user?.name,
                                                                avatar_url: it.user?.avatar_url,
                                                            }}
                                                            size="sm"
                                                        />
                                                        <span className="truncate text-sm text-body">
                                                            {it.user?.name || 'Unknown'}
                                                        </span>
                                                    </div>
                                                </Td>
                                            )}
                                            <Td className="text-muted">{TYPE_LABEL[it.type] || it.type}</Td>
                                            <Td>
                                                <StatusBadge status={it.status} />
                                            </Td>
                                            <Td className="tabular-nums text-muted">
                                                {(it.messages || []).length}
                                            </Td>
                                            <Td className="whitespace-nowrap text-muted">
                                                {fmtWhen(it.last_activity_at)}
                                            </Td>
                                            <Td className="text-right">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        setOpenId(it.id);
                                                    }}
                                                    icon={<MessageSquare className="h-4 w-4" />}
                                                >
                                                    Open
                                                </Button>
                                            </Td>
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                    )}
                </Card>
            </div>

            <ThreadModal
                show={Boolean(selected)}
                item={selected}
                canManage={manage}
                viewerId={user?.id}
                fmtWhen={fmtWhen}
                preview={preview}
                onClose={() => setOpenId(null)}
            />

            <NewRequestModal show={newOpen} preview={preview} onClose={() => setNewOpen(false)} />
        </AuthenticatedLayout>
    );
}
