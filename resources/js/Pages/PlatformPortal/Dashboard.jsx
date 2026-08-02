import PlatformLayout, { PlatformPageHeader } from '@/Layouts/PlatformLayout';
import { StatCard, Card, Badge, Table, THead, TBody, Tr, Th, Td, EmptyState } from '@/Components/ui';
import { TrendChart, RankedBars } from '@/Components/charts';

// Offline sample so `preview` renders fully without a backend. The real
// dashboard payload only carries { tenants, staff }; the extra KPIs / series
// below are illustrative and only surface in preview or once the controller
// starts sending them. Non-preview behavior is unchanged.
const DEV_SAMPLE = {
    stats: {
        tenants: 128,
        staff: 6,
        mrr: 18450,
        active: 112,
        trials: 14,
        deltas: {
            tenants: { value: '+9', dir: 'up' },
            mrr: { value: '+6.2%', dir: 'up' },
            active: { value: '+5', dir: 'up' },
            trials: { value: '-2', dir: 'down', good: true },
        },
    },
    mrrTrend: [
        { label: 'Feb', value: 12100 },
        { label: 'Mar', value: 13250 },
        { label: 'Apr', value: 14600 },
        { label: 'May', value: 15400 },
        { label: 'Jun', value: 16900 },
        { label: 'Jul', value: 18450 },
    ],
    plans: [
        { label: 'Agency', value: 62, meta: '48% of tenants · $11,160 MRR' },
        { label: 'General', value: 41, meta: '32% of tenants · $4,920 MRR' },
        { label: 'Starter', value: 25, meta: '20% of tenants · $2,370 MRR' },
    ],
    tenants: [
        { name: 'Northwind Studio', plan: 'Agency', seats: 24, mrr: 480, status: 'active', joined: 'Jul 18' },
        { name: 'Cedar & Co', plan: 'General', seats: 9, mrr: 135, status: 'trial', joined: 'Jul 16' },
        { name: 'Brightline Media', plan: 'Agency', seats: 41, mrr: 820, status: 'active', joined: 'Jul 11' },
        { name: 'Halcyon Labs', plan: 'Starter', seats: 4, mrr: 48, status: 'past_due', joined: 'Jul 09' },
        { name: 'Meridian Group', plan: 'General', seats: 17, mrr: 255, status: 'active', joined: 'Jul 04' },
    ],
};

const STATUS_TONE = {
    active: 'success',
    trial: 'info',
    past_due: 'warning',
    churned: 'danger',
};
const STATUS_LABEL = {
    active: 'Active',
    trial: 'Trial',
    past_due: 'Past due',
    churned: 'Churned',
};

const fmtNum = (n) => (typeof n === 'number' ? n.toLocaleString() : n ?? '—');
const fmtMoney = (n) =>
    typeof n === 'number' ? `$${n.toLocaleString(undefined, { maximumFractionDigits: 0 })}` : '—';

const Icon = ({ path }) => (
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        {path}
    </svg>
);
const ICONS = {
    tenants: <Icon path={<><rect x="3" y="4" width="7" height="16" rx="1" /><rect x="14" y="9" width="7" height="11" rx="1" /><path d="M6 8h1M6 12h1M6 16h1" /></>} />,
    mrr: <Icon path={<><path d="M12 3v18" /><path d="M16 7.5c0-1.4-1.8-2.5-4-2.5S8 6.1 8 7.5 9.8 10 12 10s4 1.1 4 2.5-1.8 2.5-4 2.5-4-1.1-4-2.5" /></>} />,
    active: <Icon path={<><path d="M22 12h-4l-3 8-6-16-3 8H2" /></>} />,
    trials: <Icon path={<><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></>} />,
    staff: <Icon path={<><circle cx="9" cy="8" r="3.2" /><path d="M3 20a6 6 0 0 1 12 0" /><path d="M16 5.2a3.2 3.2 0 0 1 0 6M18 20a6 6 0 0 0-3-5.2" /></>} />,
};

export default function Dashboard({ stats, preview = false }) {
    const data = preview ? DEV_SAMPLE : { stats: stats || {}, mrrTrend: [], plans: [], tenants: [] };
    const s = data.stats || {};
    const d = s.deltas || {};

    const tiles = [
        { key: 'tenants', label: 'Tenants', value: fmtNum(s.tenants ?? 0), hint: 'Total organizations', icon: ICONS.tenants, delta: d.tenants },
        { key: 'mrr', label: 'MRR', value: s.mrr != null ? fmtMoney(s.mrr) : '—', hint: 'Monthly recurring revenue', icon: ICONS.mrr, delta: d.mrr },
        { key: 'active', label: 'Active tenants', value: s.active != null ? fmtNum(s.active) : '—', hint: 'Paying, in good standing', icon: ICONS.active, delta: d.active },
        { key: 'trials', label: 'Trials', value: s.trials != null ? fmtNum(s.trials) : '—', hint: 'In trial period', icon: ICONS.trials, delta: d.trials },
        { key: 'staff', label: 'Platform staff', value: fmtNum(s.staff ?? 0), hint: 'Operator portal seats', icon: ICONS.staff },
    ];

    const hasTrend = Array.isArray(data.mrrTrend) && data.mrrTrend.length > 1;
    const hasPlans = Array.isArray(data.plans) && data.plans.length > 0;
    const hasTenants = Array.isArray(data.tenants) && data.tenants.length > 0;

    return (
        <PlatformLayout
            title="Operator dashboard"
            header={
                <PlatformPageHeader
                    title="Operator console"
                    description="Tenants, revenue, plans and staff — the landlord view, separate from the customer app."
                />
            }
        >
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                {tiles.map((t) => (
                    <StatCard key={t.key} label={t.label} value={t.value} hint={t.hint} icon={t.icon} delta={t.delta} />
                ))}
            </div>

            {(hasTrend || hasPlans) && (
                <div className="mt-6 grid gap-4 lg:grid-cols-3">
                    {hasTrend && (
                        <Card
                            className="lg:col-span-2"
                            title="Revenue trend"
                            description="Monthly recurring revenue, last 6 months"
                        >
                            <TrendChart data={data.mrrTrend} unit="" height={240} />
                        </Card>
                    )}
                    {hasPlans && (
                        <Card title="Tenants by plan" description="Distribution across editions">
                            <RankedBars items={data.plans} />
                        </Card>
                    )}
                </div>
            )}

            <div className="mt-6">
                <Card
                    padded={false}
                    title="Recent tenants"
                    description="Latest organizations to join the platform"
                >
                    {hasTenants ? (
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>Organization</Th>
                                    <Th>Plan</Th>
                                    <Th className="text-right">Seats</Th>
                                    <Th className="text-right">MRR</Th>
                                    <Th>Status</Th>
                                    <Th className="text-right">Joined</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {data.tenants.map((t, i) => (
                                    <Tr key={i}>
                                        <Td className="font-medium">{t.name}</Td>
                                        <Td>
                                            <Badge tone="accent">{t.plan}</Badge>
                                        </Td>
                                        <Td className="text-right tabular-nums text-muted">{fmtNum(t.seats)}</Td>
                                        <Td className="text-right tabular-nums text-muted">{fmtMoney(t.mrr)}</Td>
                                        <Td>
                                            <Badge tone={STATUS_TONE[t.status] || 'neutral'} dot>
                                                {STATUS_LABEL[t.status] || t.status}
                                            </Badge>
                                        </Td>
                                        <Td className="text-right tabular-nums text-subtle">{t.joined}</Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    ) : (
                        <div className="p-5">
                            <EmptyState
                                title="No tenant activity yet"
                                description="New organizations and their plans will appear here as they sign up. Manage tenants from the Tenants tab."
                            />
                        </div>
                    )}
                </Card>
            </div>
        </PlatformLayout>
    );
}
