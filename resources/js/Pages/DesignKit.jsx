import { useEffect, useState } from 'react';
import { Head } from '@inertiajs/react';
import {
    Button, Card, CardHeader, Badge, StatCard, DeltaChip,
    Table, THead, TBody, Tr, Th, Td,
    Field, Input, Select, Textarea, Checkbox, Toggle,
    EmptyState, Skeleton, SkeletonText, SkeletonCard, Tabs, Spinner,
    LockedFeature, LockBadge,
} from '@/Components/ui';

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

export default function DesignKit() {
    const [theme, setTheme] = useState('dark');
    const [tab, setTab] = useState('overview');
    const [on, setOn] = useState(true);

    useEffect(() => {
        const prev = document.documentElement.getAttribute('data-theme');
        document.documentElement.setAttribute('data-theme', theme);
        return () => { if (prev) document.documentElement.setAttribute('data-theme', prev); };
    }, [theme]);

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
