import { useEffect, useState } from 'react';
import { Head } from '@inertiajs/react';
import {
    Button, Card, CardHeader, Badge, StatCard, DeltaChip, KpiCard,
    Table, THead, TBody, Tr, Th, Td,
    Field, Input, Select, Textarea, Checkbox, Toggle,
    EmptyState, Skeleton, SkeletonText, SkeletonCard, Tabs, Spinner,
    LockedFeature, LockBadge,
} from '@/Components/ui';
import {
    Sparkline as VizSparkline, TrendChart, ActivityStrip, RankedBars,
    Gauge, GradientBar,
} from '@/Components/charts';
import { vizPalette } from '@/Components/charts/theme';
import { LazyBarChart, LazyDoughnutChart, LazyLineChart, ChartWrapper } from '@/Components/LazyLoad';
import { Activity, CircleDollarSign, Clock, Coffee, Users } from 'lucide-react';

/**
 * Internal design-kit gallery (/_kit, local only). Renders every shared UI
 * component in its key states across all three themes so we can eyeball the
 * kit before building pages on it. Not part of the product.
 */

function Section({ title, children }) {
    return (
        <section className="space-y-4">
            <h2 className="text-sm font-semibold uppercase tracking-wide text-subtle">{title}</h2>
            <div className="rounded-xl border border-line bg-surface/40 p-6">{children}</div>
        </section>
    );
}

function Sparkline() {
    return (
        <svg viewBox="0 0 120 32" className="h-8 w-28 text-accent" fill="none" preserveAspectRatio="none">
            <polyline points="0,26 20,20 40,24 60,12 80,16 100,6 120,10" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

const THEMES = ['dark', 'light', 'black'];

// ── Sample series for the visual-language sections (kit fixtures only) ──────
// Slot hues are CVD-validated and fixed: 1=violet, 2=green, 3=blue, 4=amber,
// 5=pink. Never re-order, never cycle past 5 — extras wear --text-subtle.
const KIT_SPARK = [18, 22, 19, 26, 24, 30, 27, 33, 31, 35, 34, 37];
const KIT_KPIS = [
    { accent: 1, icon: <Clock size={18} />, label: 'Hours tracked', value: '142h 30m', sub: 'Time → violet (slot 1)', delta: { pct: 8.4, direction: 'up', good: true, label: 'vs prev 14 days' } },
    { accent: 2, icon: <Activity size={18} />, label: 'Avg activity', value: '68%', sub: 'Activity → green (slot 2)', delta: { pct: 3.1, direction: 'up', good: true, label: 'vs prev 14 days' } },
    { accent: 3, icon: <Users size={18} />, label: 'Active members', value: '12 / 15', sub: 'People → blue (slot 3)', delta: { pct: 14.3, direction: 'down', good: false, label: 'vs prev 14 days' } },
    { accent: 4, icon: <Coffee size={18} />, label: 'Break time', value: '5h 20m', sub: 'Breaks → amber (slot 4)', delta: { pct: 0, direction: 'flat', good: null, label: 'no change' } },
    { accent: 5, icon: <CircleDollarSign size={18} />, label: 'Billable', value: '$8,420', sub: 'Money → pink (slot 5)', delta: { pct: 5.9, direction: 'up', good: true, label: 'vs prev 14 days' } },
];
const KIT_TREND = [
    { label: 'Mon', value: 6.5 }, { label: 'Tue', value: 7.2 }, { label: 'Wed', value: 5.8 },
    { label: 'Thu', value: 8.1 }, { label: 'Fri', value: 7.6 }, { label: 'Sat', value: 3.2 },
    { label: 'Sun', value: 2.1 }, { label: 'Mon', value: 6.9 }, { label: 'Tue', value: 7.8 },
    { label: 'Wed', value: 8.4 }, { label: 'Thu', value: 7.1 }, { label: 'Fri', value: 6.3 },
];
const KIT_TREND_COMPARE = [5.9, 6.8, 6.1, 7.2, 7.9, 2.8, 1.9, 6.2, 7.1, 7.6, 6.8, 6.9];

export default function DesignKit() {
    const [theme, setTheme] = useState('dark');
    const [tab, setTab] = useState('overview');
    const [on, setOn] = useState(true);

    useEffect(() => {
        const prev = document.documentElement.getAttribute('data-theme');
        document.documentElement.setAttribute('data-theme', theme);
        return () => { if (prev) document.documentElement.setAttribute('data-theme', prev); };
    }, [theme]);

    // Chart.js canvases can't consume var() strings, so the doughnut/line demos
    // need RESOLVED --viz colors. Re-read them after the effect above has set
    // data-theme (this effect is declared later, so it runs after it).
    const [palette, setPalette] = useState(() => vizPalette());
    useEffect(() => { setPalette(vizPalette()); }, [theme]);

    return (
        <div className="min-h-screen bg-app text-body">
            <Head title="Design kit" />
            <div className="mx-auto max-w-[1100px] px-6 py-10 space-y-10">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <p className="text-xs uppercase tracking-wide text-accent">Staffvora</p>
                        <h1 className="text-2xl font-semibold">Design kit</h1>
                        <p className="text-sm text-muted">The shared component system — every state, every theme.</p>
                    </div>
                    <div className="inline-flex items-center gap-1 rounded-lg border border-line bg-surface-2 p-1">
                        {THEMES.map((t) => (
                            <button
                                key={t}
                                onClick={() => setTheme(t)}
                                className={`rounded-md px-3 py-1.5 text-sm font-medium capitalize transition ${theme === t ? 'bg-surface text-body shadow-sm' : 'text-muted hover:text-body'}`}
                            >
                                {t}
                            </button>
                        ))}
                    </div>
                </header>

                <Section title="Buttons">
                    <div className="flex flex-wrap items-center gap-3">
                        <Button>Primary</Button>
                        <Button variant="secondary">Secondary</Button>
                        <Button variant="ghost">Ghost</Button>
                        <Button variant="danger">Danger</Button>
                        <Button loading>Saving</Button>
                        <Button size="sm">Small</Button>
                        <Button disabled>Disabled</Button>
                        <Button icon={<Spinner size={16} />}>With icon</Button>
                    </div>
                </Section>

                <Section title="Badges / status pills">
                    <div className="flex flex-wrap items-center gap-3">
                        <Badge>Neutral</Badge>
                        <Badge tone="accent" dot>Accent</Badge>
                        <Badge tone="success" dot>Working</Badge>
                        <Badge tone="warning" dot>On break</Badge>
                        <Badge tone="danger" dot>Offline</Badge>
                        <Badge tone="info">Info</Badge>
                    </div>
                </Section>

                <Section title="Stat cards">
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <StatCard label="Hours tracked" value="142h 30m" delta={{ value: '8.4%', dir: 'up' }} spark={<Sparkline />} />
                        <StatCard label="Avg activity" value="68%" delta={{ value: '3.1%', dir: 'up' }} hint="vs last week" />
                        <StatCard label="Idle time" value="21%" delta={{ value: '1.4%', dir: 'down', good: true }} />
                        <StatCard label="Overtime" value="6h 10m" delta={{ value: '12%', dir: 'up', good: false }} />
                    </div>
                </Section>

                <Section title="KPI cards (KpiCard) — the 5 accent slots">
                    <p className="mb-4 text-sm text-muted">
                        The cross-page color contract: 1 violet = time/hours · 2 green = activity/rates ·
                        3 blue = people/members · 4 amber = breaks/pending · 5 pink = billable/money.
                        Delta arrows follow <span className="text-body">good</span>, not direction; spark glows in dark/black only.
                    </p>
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                        {KIT_KPIS.map((k) => (
                            <KpiCard key={k.accent} accent={k.accent} icon={k.icon} label={k.label} value={k.value} sub={k.sub} delta={k.delta} spark={KIT_SPARK} />
                        ))}
                    </div>
                    <p className="mt-6 mb-3 text-xs uppercase tracking-wide text-subtle">Value-only (no delta / no spark — the default when props carry no series)</p>
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                        {KIT_KPIS.map((k) => (
                            <KpiCard key={k.accent} accent={k.accent} icon={k.icon} label={k.label} value={k.value} sub={k.sub} />
                        ))}
                    </div>
                </Section>

                <Section title="Gradient bars (GradientBar)">
                    <div className="grid gap-x-8 gap-y-4 sm:grid-cols-2">
                        <GradientBar accent={1} label="Deep work" pct={72.4} count={133} />
                        <GradientBar accent={2} label="Active input" pct={58} count={97} />
                        <GradientBar accent={3} label="Meetings" pct={34.5} count={41} />
                        <GradientBar accent={4} label="Breaks" pct={12} count={18} />
                        <GradientBar accent={5} label="Billable share" pct={81} />
                    </div>
                </Section>

                <Section title="SVG chart kit — gradient + glow props">
                    <div className="space-y-8">
                        <div>
                            <p className="mb-2 text-xs uppercase tracking-wide text-subtle">TrendChart · gradientFill + glow (glow renders in dark/black only)</p>
                            <TrendChart data={KIT_TREND} compare={KIT_TREND_COMPARE} avg={6.4} gradientFill glow />
                        </div>

                        <div>
                            <p className="mb-2 text-xs uppercase tracking-wide text-subtle">Sparkline · accent={'{1..5}'} + glow</p>
                            <div className="grid grid-cols-2 gap-4 sm:grid-cols-5">
                                {[1, 2, 3, 4, 5].map((slot) => (
                                    <div key={slot} className="rounded-lg border border-line bg-surface p-3">
                                        <VizSparkline data={KIT_SPARK} height={36} accent={slot} glow />
                                        <div className="mt-2 text-[11px] text-subtle">accent={slot}</div>
                                    </div>
                                ))}
                            </div>
                        </div>

                        <div className="grid gap-6 lg:grid-cols-2">
                            <div>
                                <p className="mb-2 text-xs uppercase tracking-wide text-subtle">Gauge · legacy semantic bands (status stays status)</p>
                                <Gauge value={68} label="Avg activity" thresholds={[30, 50]} caption="Band tones: danger < 30, warning < 50, success above." />
                            </div>
                            <div>
                                <p className="mb-2 text-xs uppercase tracking-wide text-subtle">Gauge · accent={'{2}'} + glow (categorical, not stateful)</p>
                                <Gauge value={68} label="Avg activity" thresholds={[30, 50]} accent={2} glow caption="Same reading wearing the activity slot instead of a band tone." />
                            </div>
                        </div>

                        <div>
                            <p className="mb-2 text-xs uppercase tracking-wide text-subtle">ActivityStrip · per-segment accent + glow (legacy tones first)</p>
                            <div className="space-y-6">
                                <ActivityStrip breakdown={{ tracked: 58, manual: 12, idle: 14, untracked: 16 }} hours={false} />
                                <ActivityStrip
                                    hours={false}
                                    glow
                                    segments={[
                                        { label: 'Deep work', pct: 46, accent: 1 },
                                        { label: 'Collaboration', pct: 22, accent: 3 },
                                        { label: 'Breaks', pct: 14, accent: 4 },
                                        { label: 'Untracked', pct: 18, tone: 'untracked' },
                                    ]}
                                />
                            </div>
                        </div>

                        <div>
                            <p className="mb-2 text-xs uppercase tracking-wide text-subtle">RankedBars · accent + glow (per-item accent stays entity-fixed under filtering)</p>
                            <RankedBars
                                accent={1}
                                glow
                                items={[
                                    { label: 'Website redesign', value: '38h 20m', pct: 100, meta: 'Northwind Studio' },
                                    { label: 'Mobile app', value: '24h 05m', pct: 63 },
                                    { label: 'Internal tooling', value: '11h 40m', pct: 30 },
                                ]}
                            />
                        </div>
                    </div>
                </Section>

                <Section title="Chart.js kit — value labels, viz colors, dot legends">
                    <p className="mb-4 text-sm text-muted">
                        Lazy-loaded (Chart.js never enters the SVG-kit bundle). Value labels print only at ≤8 bars;
                        donut legend dots carry % + count; one axis per chart, always.
                    </p>
                    <div className="grid gap-6 lg:grid-cols-2">
                        <div>
                            <p className="mb-2 text-xs uppercase tracking-wide text-subtle">BarChart · valueLabels + roundedTops + vizColors</p>
                            <ChartWrapper
                                component={LazyBarChart}
                                valueLabels
                                roundedTops
                                vizColors
                                data={{
                                    labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
                                    datasets: [{ label: 'Hours', data: [6.5, 7.2, 5.8, 8.1, 7.6, 3.2] }],
                                }}
                                options={{ plugins: { legend: { display: false } } }}
                            />
                        </div>
                        <div>
                            <p className="mb-2 text-xs uppercase tracking-wide text-subtle">DoughnutChart · centerValue + centerLabel + legend=&quot;dots&quot;</p>
                            <ChartWrapper
                                component={LazyDoughnutChart}
                                centerValue="128h"
                                centerLabel="This week"
                                legend="dots"
                                data={{
                                    labels: ['Deep work', 'Collaboration', 'Meetings', 'Ops'],
                                    datasets: [{ data: [54, 34, 24, 16], backgroundColor: palette.slice(0, 4), borderWidth: 0 }],
                                }}
                                // DoughnutChart defaults maintainAspectRatio:false — give it
                                // a real aspect (same as the app dashboard's donut) or the
                                // canvas grows unbounded.
                                options={{ maintainAspectRatio: true, aspectRatio: 1.9, plugins: { legend: { display: false } } }}
                            />
                        </div>
                        <div className="lg:col-span-2">
                            <p className="mb-2 text-xs uppercase tracking-wide text-subtle">LineChart · gradientFill + glow</p>
                            <ChartWrapper
                                component={LazyLineChart}
                                gradientFill
                                glow
                                data={{
                                    labels: KIT_TREND.map((p) => p.label),
                                    datasets: [{ label: 'Hours', data: KIT_TREND.map((p) => p.value), borderColor: palette[0], pointRadius: 0, tension: 0.4 }],
                                }}
                                options={{ plugins: { legend: { display: false } } }}
                            />
                        </div>
                    </div>
                </Section>

                <Section title="Card">
                    <Card title="Team activity" description="12 of 15 members active today" actions={<Button size="sm" variant="secondary">View all</Button>}>
                        <p className="text-sm text-muted">Any content sits in the padded body. Delta chip: <DeltaChip value="4.2%" dir="up" /> inline.</p>
                    </Card>
                </Section>

                <Section title="Table">
                    <Card padded={false}>
                        <Table>
                            <THead>
                                <Tr>
                                    <Th sortable active dir="asc">Member</Th>
                                    <Th>Status</Th>
                                    <Th className="text-right" sortable>Today</Th>
                                    <Th className="text-right">Activity</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {[['Aqsa Khan', 'success', 'Working', '7h 12m', '82%'],
                                  ['Sana Faisal', 'warning', 'On break', '5h 30m', '69%'],
                                  ['Bilal Tariq', 'danger', 'Offline', '2h 20m', '38%']].map((r) => (
                                    <Tr key={r[0]}>
                                        <Td className="font-medium">{r[0]}</Td>
                                        <Td><Badge tone={r[1]} dot>{r[2]}</Badge></Td>
                                        <Td className="text-right tabular-nums">{r[3]}</Td>
                                        <Td className="text-right tabular-nums">{r[4]}</Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    </Card>
                </Section>

                <Section title="Form controls">
                    <div className="grid gap-5 sm:grid-cols-2">
                        <Field label="Full name" required htmlFor="dk-name"><Input id="dk-name" placeholder="Jane Doe" /></Field>
                        <Field label="Role" htmlFor="dk-role"><Select id="dk-role"><option>Engineer</option><option>Designer</option></Select></Field>
                        <Field label="With error" error="This field is required"><Input placeholder="Invalid" /></Field>
                        <Field label="Notes" hint="Optional"><Textarea rows={3} placeholder="Add a note…" /></Field>
                        <div className="flex items-center gap-6">
                            <Checkbox label="Remember me" defaultChecked />
                            <Toggle checked={on} onChange={setOn} label="Notifications" />
                        </div>
                    </div>
                </Section>

                <Section title="Tabs">
                    <Tabs
                        tabs={[{ key: 'overview', label: 'Overview' }, { key: 'activity', label: 'Activity' }, { key: 'reports', label: 'Reports' }]}
                        value={tab}
                        onChange={setTab}
                    />
                    <p className="mt-3 text-sm text-muted">Active tab: <span className="text-body">{tab}</span></p>
                </Section>

                <Section title="Empty & loading states">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="rounded-xl border border-line bg-surface">
                            <EmptyState title="No idle alerts" description="Every active session stayed above your activity threshold this week." action={<Button size="sm" variant="secondary">Adjust thresholds</Button>} />
                        </div>
                        <SkeletonCard />
                    </div>
                </Section>

                <Section title="Locked feature (upgrade pattern)">
                    <p className="mb-4 max-w-2xl text-sm text-muted">
                        &ldquo;Shown, not hidden&rdquo;: a capability the workspace has not bought stays
                        visible, greyed and inert, with the upgrade CTA. Presentation only — the
                        server still refuses through <code className="text-body">entitlement:&lt;key&gt;</code>.
                    </p>

                    <div className="space-y-6">
                        <div>
                            <div className="mb-2 text-xs uppercase tracking-wide text-subtle">overlay</div>
                            <LockedFeature
                                feature="insights"
                                name="Insights"
                                blurb="Utilization against target, idle time and team highlights over any date range."
                                href="#"
                            >
                                <Card title="Utilization" description="Last 30 days">
                                    <div className="flex items-end gap-6">
                                        <div>
                                            <div className="text-3xl font-semibold text-body">82%</div>
                                            <div className="text-sm text-muted">of target hours</div>
                                        </div>
                                        <Sparkline />
                                    </div>
                                </Card>
                            </LockedFeature>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <div className="mb-2 text-xs uppercase tracking-wide text-subtle">panel</div>
                                <LockedFeature
                                    variant="panel"
                                    feature="machine_compliance"
                                    name="Machine compliance"
                                    blurb="Per-PC inventory of extensions, programs and VPNs, with a blocklist and findings."
                                    href="#"
                                />
                            </div>
                            <div>
                                <div className="mb-2 text-xs uppercase tracking-wide text-subtle">inline + LockBadge</div>
                                <LockedFeature
                                    variant="inline"
                                    feature="app_url_tracking"
                                    name="App &amp; URL tracking"
                                    blurb="Record the active application and browser URL alongside tracked time."
                                    href="#"
                                >
                                    <Field label="Track apps &amp; URLs">
                                        <Toggle checked={true} onChange={() => {}} />
                                    </Field>
                                </LockedFeature>
                                <div className="mt-3 flex items-center gap-3">
                                    <LockBadge />
                                    <LockBadge iconOnly />
                                </div>
                            </div>
                        </div>
                    </div>
                </Section>
            </div>
        </div>
    );
}
