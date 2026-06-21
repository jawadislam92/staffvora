<?php

namespace App\Support;

use App\Models\SitePage;
use App\Models\SiteTheme;

/**
 * Ships the full default marketing site (home + every launch page) as published
 * CMS block-pages, so the public site is complete from day one and the owner
 * edits everything in the builder. Idempotent: only creates pages that are
 * missing. Adding a page = one entry in pages(); it auto-appears in nav.
 */
class DefaultSiteContent
{
    public static function ensure(): void
    {
        self::ensureTheme();

        $created = false;
        foreach (self::pages() as $slug => $def) {
            if (SitePage::where('slug', $slug)->exists()) {
                continue;
            }
            $blocks = $def['blocks'];
            SitePage::create([
                'slug' => $slug,
                'title' => $def['title'],
                'meta_title' => $def['meta_title'] ?? $def['title'].' — Tracksuite',
                'meta_description' => $def['meta_description'] ?? null,
                'status' => 'published',
                'show_in_nav' => $def['show_in_nav'] ?? true,
                'blocks' => $blocks,
                'published_blocks' => $blocks,
                'sort' => $def['sort'] ?? 0,
            ]);
            $created = true;
        }

        if ($created) {
            SiteNavigation::forgetCache();
        }
    }

    private static function ensureTheme(): void
    {
        $theme = SiteTheme::current();
        if ($theme->tagline === null) {
            $theme->update([
                'site_name' => $theme->site_name ?: config('brand.name', 'Tracksuite'),
                'tagline' => config('brand.tagline', 'We measure the work, not the worker.'),
                'contact_email' => $theme->contact_email ?: config('brand.support_email'),
            ]);
            SiteTheme::forgetCache();
        }
    }

    private static function b(string $type, array $data): array
    {
        return ['id' => 'b'.bin2hex(random_bytes(4)), 'type' => $type, 'data' => $data];
    }

    private static function cta(string $heading, string $text): array
    {
        return self::b('cta_band', ['heading' => $heading, 'text' => $text, 'primary_cta' => ['label' => 'Start free trial', 'url' => '/register']]);
    }

    /** Every page the site ships with, keyed by slug. */
    private static function pages(): array
    {
        $startCtas = ['primary_cta' => ['label' => 'Start free', 'url' => '/register'], 'secondary_cta' => ['label' => 'Book a demo', 'url' => '/contact']];

        return [
            'home' => [
                'title' => 'Home', 'sort' => 0,
                'meta_title' => 'Tracksuite — Time tracking that respects your team',
                'meta_description' => 'Time tracking, screenshots, attendance and reports in one place — from desktop tracker to Slack digest. Start free, no credit card.',
                'blocks' => self::homeBlocks(),
            ],

            'features' => [
                'title' => 'Features', 'sort' => 1,
                'meta_description' => 'Everything you need to track time, understand productivity and run payroll — without the surveillance vibe.',
                'blocks' => [
                    self::b('hero', ['variant' => 'center', 'eyebrow' => 'Features', 'heading' => 'Everything you need to track time', 'heading_highlight' => 'and understand your team.', 'subhead' => 'One connected place for hours, screenshots, attendance, reports and payroll — built to be transparent.', ...$startCtas, 'note' => 'No credit card required · 14-day Pro trial']),
                    self::b('feature_grid', ['heading' => 'A complete time-tracking toolkit', 'subhead' => 'From clock-in to client report.', 'features' => self::featureItems()]),
                    self::b('feature_split', ['eyebrow' => 'Productivity insight', 'heading' => 'See exactly where time goes', 'text' => 'Activity levels, app & URL usage and daily timelines turn raw hours into honest insight — so you can plan, not police.', 'bullets' => ['Daily activity timeline', 'App & URL usage', 'Per-project breakdowns', 'Weekly trends'], 'image_side' => 'right']),
                    self::b('feature_split', ['eyebrow' => 'Transparent by design', 'heading' => 'Monitoring your team will actually accept', 'text' => 'Employees see their own data, screenshots are opt-in, and there is no keylogging — accountability without the spyware reflex.', 'bullets' => ['Employee-visible screenshots', 'No keylogging', 'Idle auto-pause', 'Per-workspace privacy controls'], 'image_side' => 'left']),
                    self::cta('See it on your own team', 'Spin up a workspace and explore every feature free for 14 days.'),
                ],
            ],

            'how-it-works' => [
                'title' => 'How it works', 'sort' => 2,
                'meta_description' => 'Get your team tracking time in minutes — install, track with one click, and watch reports flow in.',
                'blocks' => [
                    self::b('hero', ['variant' => 'center', 'eyebrow' => 'How it works', 'heading' => 'Up and running', 'heading_highlight' => 'in minutes.', 'subhead' => 'No lengthy setup. Install the tracker, click start, and the reports build themselves.', ...$startCtas]),
                    self::b('steps', ['heading' => 'Three steps to tracked time', 'steps' => [
                        ['n' => '1', 'title' => 'Install the tracker', 'text' => 'Grab the desktop app for Windows or Mac and sign in.'],
                        ['n' => '2', 'title' => 'Track with one click', 'text' => 'Pick a client — or Office Work — and hit start. Idle time pauses itself.'],
                        ['n' => '3', 'title' => 'Reports flow in', 'text' => 'Hours, screenshots, attendance and Slack digests, all without manual entry.'],
                    ]]),
                    self::b('feature_split', ['eyebrow' => 'No surprises', 'heading' => 'What we track — and what we don\'t', 'text' => 'We are deliberately clear about what is captured. The people being tracked can always see it, and sensitive things stay off by default.', 'bullets' => ['✔ Time, activity level, apps used', '✔ Optional, visible screenshots', '✘ No keylogging', '✘ No webcam, no secret capture'], 'image_side' => 'right']),
                    self::b('faq', ['heading' => 'Common questions', 'items' => [
                        ['q' => 'Do employees know they are being tracked?', 'a' => 'Yes — Tracksuite is transparent by design. The tracker is visible and people can see their own data.'],
                        ['q' => 'Does it work offline?', 'a' => 'Yes. The desktop tracker queues data offline and syncs when the connection returns.'],
                        ['q' => 'Can I turn screenshots off?', 'a' => 'Absolutely — screenshots are configurable per workspace and can be disabled entirely.'],
                    ]]),
                    self::cta('Ready to try it?', 'Create your workspace and see your first tracked hours today.'),
                ],
            ],

            'solutions' => [
                'title' => 'Solutions', 'sort' => 3,
                'meta_description' => 'Time tracking built for agencies, BPOs, software houses, remote teams and freelancers.',
                'blocks' => [
                    self::b('hero', ['variant' => 'center', 'eyebrow' => 'Solutions', 'heading' => 'Built for the way', 'heading_highlight' => 'your team works.', 'subhead' => 'Whether you bill by the hour or run a 24/7 floor, Tracksuite adapts to how your team operates.', ...$startCtas]),
                    self::b('feature_grid', ['heading' => 'Who it\'s for', 'subhead' => 'Pick the setup that matches your team.', 'features' => [
                        ['icon' => 'users', 'title' => 'Agencies & consultancies', 'text' => 'Track billable hours per client and turn them into clean, automatic reports.'],
                        ['icon' => 'globe', 'title' => 'BPOs & call centers', 'text' => 'Shift attendance, live activity and workforce analytics across large, distributed floors.'],
                        ['icon' => 'desktop', 'title' => 'Software houses', 'text' => 'Per-project time, app & URL insight, and humane productivity metrics for dev teams.'],
                        ['icon' => 'clock', 'title' => 'Remote & hybrid teams', 'text' => 'Keep distributed teams aligned without anyone feeling watched.'],
                        ['icon' => 'chart', 'title' => 'Freelancers', 'text' => 'Simple, honest time tracking and client-ready timesheets.'],
                    ]]),
                    self::b('feature_split', ['eyebrow' => 'One platform', 'heading' => 'Accuracy your clients trust', 'text' => 'Every plan turns tracked time into exportable timesheets and reports, so billing and payroll are never a guess.', 'bullets' => ['Per-client billable hours', 'CSV & Slack exports', 'Attendance & shifts', 'Payroll-ready timesheets'], 'image_side' => 'right']),
                    self::cta('Find your fit', 'Start free and shape Tracksuite around your team in minutes.'),
                ],
            ],

            'solutions/agencies' => [
                'title' => 'For agencies', 'sort' => 4, 'show_in_nav' => false,
                'meta_description' => 'Time tracking built for agencies — billable hours, client reports and accurate invoicing.',
                'blocks' => [
                    self::b('hero', ['variant' => 'center', 'eyebrow' => 'For agencies', 'heading' => 'Bill every hour,', 'heading_highlight' => 'accurately.', 'subhead' => 'Track time per client and project, then turn it into reports and invoices your clients trust.', ...$startCtas]),
                    self::b('feature_split', ['eyebrow' => 'The problem', 'heading' => 'Stop leaking billable hours', 'text' => 'Manual timesheets miss time and slow down invoicing. Tracksuite captures it automatically, per client, so nothing slips.', 'bullets' => ['Per-client & per-project time', 'Automatic, exportable timesheets', 'Clear client-ready reports'], 'image_side' => 'right']),
                    self::b('feature_grid', ['heading' => 'What agencies use most', 'features' => [
                        ['icon' => 'clock', 'title' => 'Billable time tracking', 'text' => 'One click per client; overnight and idle time handled for you.'],
                        ['icon' => 'chart', 'title' => 'Client reports', 'text' => 'Filter by client, project or person and export in one click.'],
                        ['icon' => 'camera', 'title' => 'Proof of work', 'text' => 'Optional, employee-visible screenshots for client confidence.'],
                    ]]),
                    self::cta('Win back billable hours', 'Try Tracksuite free with your team for 14 days.'),
                ],
            ],

            'solutions/bpos' => [
                'title' => 'For BPOs & call centers', 'sort' => 5, 'show_in_nav' => false,
                'meta_description' => 'Workforce visibility for BPOs and call centers — shift attendance, live activity and analytics.',
                'blocks' => [
                    self::b('hero', ['variant' => 'center', 'eyebrow' => 'For BPOs & call centers', 'heading' => 'Workforce visibility', 'heading_highlight' => 'across every shift.', 'subhead' => 'Attendance, live activity and analytics for large, distributed floors — without the surveillance backlash.', ...$startCtas]),
                    self::b('feature_split', ['eyebrow' => 'Built for scale', 'heading' => 'See the whole floor at a glance', 'text' => 'Three-shift support around the clock, late-coming detection and a monthly attendance grid your HR team will love.', 'bullets' => ['Three-shift coverage', 'Late-coming detection', 'Monthly attendance grid', 'Live "currently tracking" view'], 'image_side' => 'left']),
                    self::b('feature_grid', ['heading' => 'What teams rely on', 'features' => [
                        ['icon' => 'calendar', 'title' => 'Shift attendance', 'text' => 'Clock in/out with breaks and overnight shifts that land on the right day.'],
                        ['icon' => 'chart', 'title' => 'Workforce analytics', 'text' => 'Activity and productivity trends across teams and locations.'],
                        ['icon' => 'slack', 'title' => 'Daily Slack digests', 'text' => 'Automatic summaries delivered to the right channels.'],
                    ]]),
                    self::cta('Bring your floor online', 'Start free and roll out to your whole team in minutes.'),
                ],
            ],

            'about' => [
                'title' => 'About', 'sort' => 6,
                'meta_description' => 'We are building time tracking teams actually trust — accurate, humane and transparent.',
                'blocks' => [
                    self::b('hero', ['variant' => 'center', 'eyebrow' => 'About us', 'heading' => 'Time tracking teams', 'heading_highlight' => 'actually trust.', 'subhead' => 'We believe you can measure work without watching people. That belief shapes every decision we make.']),
                    self::b('rich_text', ['html' => '<h2>Our story</h2><p>Most time trackers were built to police people. We started Tracksuite because the teams we worked with deserved better — accurate measurement that respects the people doing the work. <em>[REPLACE: your real founding story.]</em></p><h3>What we believe</h3><p>Accountability and respect are not opposites. Transparent data, plain numbers and humane defaults build the trust that makes a team actually want to use a tracker.</p>']),
                    self::b('team_grid', ['heading' => 'The people behind it', 'subhead' => '[REPLACE with your real team.]', 'members' => [
                        ['name' => 'Your name', 'role' => 'Founder'],
                        ['name' => 'Team member', 'role' => 'Engineering'],
                        ['name' => 'Team member', 'role' => 'Customer success'],
                        ['name' => 'Team member', 'role' => 'Design'],
                    ]]),
                    self::b('stats', ['stats' => [
                        ['value' => 'Remote-first', 'label' => 'how we work'],
                        ['value' => 'Rawalpindi', 'label' => 'where we\'re based'],
                        ['value' => 'Humane', 'label' => 'by design'],
                    ]]),
                    self::cta('Come build with us', 'Try Tracksuite free and tell us what you think.'),
                ],
            ],

            'contact' => [
                'title' => 'Contact', 'sort' => 7,
                'meta_description' => 'Talk to the Tracksuite team — questions, demos, or help getting started.',
                'blocks' => [
                    self::b('hero', ['variant' => 'center', 'eyebrow' => 'Contact', 'heading' => 'Let\'s talk.', 'subhead' => 'Questions, a demo, or help getting set up — we\'re happy to help. We usually reply within one business day.']),
                    self::b('contact', ['form_heading' => 'Send us a message']),
                ],
            ],

            'security' => [
                'title' => 'Security', 'sort' => 8,
                'meta_description' => 'How Tracksuite protects your data and your team\'s privacy — encryption, access controls and employee data rights.',
                'blocks' => [
                    self::b('hero', ['variant' => 'center', 'eyebrow' => 'Security & privacy', 'heading' => 'Your data', 'heading_highlight' => 'stays yours.', 'subhead' => 'We hold workforce data to a high bar — encrypted, access-controlled, and built around the rights of the people being tracked.']),
                    self::b('feature_grid', ['heading' => 'How we protect your workspace', 'features' => [
                        ['icon' => 'lock', 'title' => 'Encryption', 'text' => 'Data is encrypted in transit (HTTPS) and protected at rest. Secrets are never exposed in the UI.'],
                        ['icon' => 'shield', 'title' => 'Data ownership', 'text' => 'Your workspace data is yours. Export it anytime; we never sell it.'],
                        ['icon' => 'users', 'title' => 'Employee data rights', 'text' => 'The people being tracked can see their own data. Super-admins are exempt from monitoring.'],
                        ['icon' => 'check', 'title' => 'Access controls', 'text' => 'Role-based permissions decide exactly who can see screenshots, reports and settings.'],
                        ['icon' => 'globe', 'title' => 'Reliable hosting', 'text' => 'Hosted on hardened infrastructure with HTTPS and regular backups.'],
                        ['icon' => 'camera', 'title' => 'Privacy controls', 'text' => 'Screenshots and activity capture are configurable per workspace and can be turned off.'],
                    ]]),
                    self::b('rich_text', ['html' => '<h2>Our approach</h2><p>Tracksuite is designed so monitoring is transparent and proportionate. Sensitive capture is off by default, retention is configurable, and employees can always see what is recorded about them. <em>[REPLACE: add your formal security/compliance posture once finalised.]</em></p>']),
                    self::b('faq', ['heading' => 'Security FAQ', 'items' => [
                        ['q' => 'Where is my data stored?', 'a' => 'On secured servers with HTTPS and regular backups. [REPLACE: name region/provider once confirmed.]'],
                        ['q' => 'Can employees see what\'s captured?', 'a' => 'Yes — transparency is core to Tracksuite. People can view their own tracked data and screenshots.'],
                        ['q' => 'Can I delete data?', 'a' => 'Yes. You control retention, and data can be removed on request.'],
                    ]]),
                    self::cta('Questions about security?', 'Reach out — we\'re happy to walk you through it.'),
                ],
            ],

            'download' => [
                'title' => 'Download', 'sort' => 9,
                'meta_description' => 'Download the Tracksuite desktop tracker for Windows and macOS.',
                'blocks' => [
                    self::b('hero', ['variant' => 'center', 'eyebrow' => 'Download', 'heading' => 'Get the Tracksuite', 'heading_highlight' => 'desktop tracker.', 'subhead' => 'Lightweight, auto-updating and offline-ready. Sign in once and start tracking.', 'primary_cta' => ['label' => 'Create a workspace', 'url' => '/register'], 'secondary_cta' => ['label' => 'Sign in', 'url' => '/login']]),
                    self::b('feature_grid', ['heading' => 'Available platforms', 'subhead' => 'Download links appear inside your dashboard after you sign in.', 'features' => [
                        ['icon' => 'desktop', 'title' => 'Windows', 'text' => 'Native installer with auto-updates, offline queueing and tray controls.'],
                        ['icon' => 'desktop', 'title' => 'macOS', 'text' => 'Native app for Apple Silicon and Intel Macs.'],
                        ['icon' => 'globe', 'title' => 'Linux & more', 'text' => 'Additional platforms coming soon. [REPLACE: confirm availability.]'],
                    ]]),
                    self::b('rich_text', ['html' => '<h2>System requirements</h2><ul><li>Windows 10 or later, or macOS 11 or later</li><li>An active Tracksuite workspace and sign-in</li><li>Internet connection (the tracker queues data offline and syncs later)</li></ul><p><em>[REPLACE: confirm exact versions / signed-installer details.]</em></p>']),
                    self::cta('Start tracking today', 'Create your free workspace, then download the app from your dashboard.'),
                ],
            ],

            'terms' => [
                'title' => 'Terms of Service', 'sort' => 20, 'show_in_nav' => false,
                'meta_description' => 'Tracksuite Terms of Service.',
                'blocks' => [
                    self::b('hero', ['variant' => 'center', 'heading' => 'Terms of Service', 'subhead' => 'Last updated: [REPLACE]. Please have a legal professional review before launch.']),
                    self::b('rich_text', ['html' => '<p><strong>Note:</strong> This is a scaffold, not binding legal text. Have a lawyer review and finalise it.</p><h2>1. Acceptance of terms</h2><p>By using Tracksuite you agree to these terms…</p><h2>2. Your account</h2><p>You are responsible for your workspace and the people you invite…</p><h2>3. Acceptable use</h2><p>Use Tracksuite lawfully and in line with applicable employment and privacy laws…</p><h2>4. Billing</h2><p>Paid plans are billed per seat; trials convert as described on the pricing page…</p><h2>5. Liability</h2><p>The service is provided "as is"…</p><h2>6. Termination</h2><p>You may cancel anytime; we may suspend accounts that violate these terms…</p>']),
                ],
            ],

            'privacy' => [
                'title' => 'Privacy Policy', 'sort' => 21, 'show_in_nav' => false,
                'meta_description' => 'Tracksuite Privacy Policy.',
                'blocks' => [
                    self::b('hero', ['variant' => 'center', 'heading' => 'Privacy Policy', 'subhead' => 'Last updated: [REPLACE]. Please have a legal professional review before launch.']),
                    self::b('rich_text', ['html' => '<p><strong>Note:</strong> This is a scaffold, not binding legal text. Have a lawyer review and finalise it.</p><h2>1. Data we collect</h2><p>Account details, and — for tracked users — time, activity and optional screenshots configured by their workspace…</p><h2>2. How we use it</h2><p>To provide time tracking, reports and the features you enable…</p><h2>3. Monitoring data</h2><p>Workspace admins configure what is captured; tracked users can view their own data. Sensitive capture is off by default…</p><h2>4. Retention</h2><p>Data is retained per your workspace settings and removed on request…</p><h2>5. Your rights</h2><p>You can access, export and delete your data…</p><h2>6. Contact</h2><p>Questions? Contact us at the address on our contact page…</p>']),
                ],
            ],

            'pricing' => [
                'title' => 'Pricing', 'sort' => 1,
                'meta_title' => 'Pricing — Tracksuite',
                'meta_description' => 'Simple, transparent time-tracking pricing. Start free and upgrade as your team grows.',
                'blocks' => [
                    self::b('hero', ['variant' => 'center', 'eyebrow' => 'Pricing', 'heading' => 'Simple, transparent pricing.', 'heading_highlight' => 'No surprises.', 'subhead' => 'Start free, upgrade when your team grows. Every plan includes the full tracker, attendance and reports.', 'note' => 'No credit card required · 14-day Pro trial']),
                    self::b('pricing_table', ['anchor' => 'pricing', 'heading' => '', 'subhead' => '', 'note' => '']),
                    self::b('faq', ['heading' => 'Pricing FAQ', 'items' => [
                        ['q' => 'Is there a free plan?', 'a' => 'Yes — Free covers a small team with core time tracking, attendance and reports. No credit card needed.'],
                        ['q' => 'How does the trial work?', 'a' => 'New workspaces start on a 14-day Pro trial with every feature unlocked. After it ends you can stay on Free or choose a paid plan.'],
                        ['q' => 'Can I pay annually?', 'a' => 'Yes — annual billing saves you about two months compared with paying monthly.'],
                        ['q' => 'Can I cancel anytime?', 'a' => 'Absolutely. There are no lock-in contracts — change or cancel your plan whenever you like.'],
                        ['q' => 'Do you support local payment methods?', 'a' => 'For Pakistan-based teams we accept bank transfer and local wallets like JazzCash and Easypaisa. [REPLACE: confirm methods]'],
                    ]]),
                    self::cta('Try every feature free for 14 days', 'Create your workspace in seconds — no card, no commitment.'),
                ],
            ],
        ];
    }

    private static function featureItems(): array
    {
        return [
            ['icon' => 'clock', 'title' => 'One-click time tracking', 'text' => 'Pick a client, hit start. Idle time pauses itself, overnight shifts stay on the right day, and hours flow into reports automatically.'],
            ['icon' => 'camera', 'title' => 'Screenshots & activity', 'text' => 'Optional, employee-visible screenshots with live activity levels, stored securely with automatic retention cleanup.'],
            ['icon' => 'calendar', 'title' => 'Attendance & shifts', 'text' => 'Clock in/out with breaks, three-shift support around the clock, late-coming detection, and a monthly grid HR will love.'],
            ['icon' => 'chart', 'title' => 'Reports & exports', 'text' => 'Work-hours reports per client, tracker and work type — filter anything, export CSV in one click.'],
            ['icon' => 'slack', 'title' => 'Slack digests', 'text' => 'Daily activity digests and weekly summaries delivered straight to your Slack channels.'],
            ['icon' => 'desktop', 'title' => 'Desktop apps', 'text' => 'Native Windows and macOS trackers with auto-updates, offline queueing and tray controls.'],
        ];
    }

    /** The Home composition — the editable starting point. */
    public static function homeBlocks(): array
    {
        return [
            self::b('hero', [
                'eyebrow' => 'Time tracking that respects your team',
                'heading' => 'Every hour, tracked.',
                'heading_highlight' => 'Zero busywork.',
                'subhead' => 'Time tracking, screenshots, attendance and reports — connected in one place, from desktop tracker to Slack digest.',
                'primary_cta' => ['label' => 'Start free', 'url' => '/register'],
                'secondary_cta' => ['label' => 'Book a demo', 'url' => '/contact'],
                'note' => 'No credit card required · 14-day Pro trial',
                'image_url' => null,
            ]),
            self::b('logo_bar', ['caption' => 'Built for teams who bill by the hour', 'logos' => []]),
            self::b('feature_grid', ['anchor' => 'features', 'heading' => 'Everything the team needs', 'subhead' => 'From clock-in to client report, without leaving the flow.', 'features' => self::featureItems()]),
            self::b('feature_split', ['eyebrow' => 'Transparent by design', 'heading' => 'Monitoring your team will actually accept', 'text' => 'Screenshots and activity are visible to the people being tracked, attendance never brands someone absent for a day they hadn\'t started, and super-admins are exempt. Accountability without the surveillance vibe.', 'bullets' => ['Employee-visible screenshots', 'Humane attendance — no false "absent"', 'Idle auto-pause', 'Per-workspace privacy controls'], 'image_url' => null, 'image_side' => 'right']),
            self::b('stats', ['stats' => [
                ['value' => '3 shifts', 'label' => 'covered around the clock'],
                ['value' => '1-click', 'label' => 'CSV exports & Slack digests'],
                ['value' => '100%', 'label' => 'of hours in one place'],
            ]]),
            self::b('steps', ['heading' => 'Up and running in minutes', 'steps' => [
                ['n' => '1', 'title' => 'Install the tracker', 'text' => 'Grab the desktop app for Windows or Mac from your dashboard.'],
                ['n' => '2', 'title' => 'Track with one click', 'text' => 'Pick a client — or Office Work — and start. Everything else is automatic.'],
                ['n' => '3', 'title' => 'Reports flow in', 'text' => 'Hours, screenshots, attendance and Slack digests, all without manual entry.'],
            ]]),
            self::b('testimonials', ['heading' => 'Teams get their hours back', 'items' => [
                ['quote' => 'Setup took minutes and our weekly client reports build themselves now.', 'name' => 'Your happy customer', 'role' => 'Operations Lead', 'company' => '', 'avatar_url' => null, 'rating' => 5],
                ['quote' => 'Finally a tracker the team doesn\'t resent — they can see exactly what\'s captured.', 'name' => 'Add a real review', 'role' => 'Founder', 'company' => '', 'avatar_url' => null, 'rating' => 5],
            ]]),
            self::b('faq', ['heading' => 'Frequently asked questions', 'items' => [
                ['q' => 'Do I need a credit card to start?', 'a' => 'No. Create a workspace and you get a 14-day Pro trial with full features — no card required.'],
                ['q' => 'Can employees see their own screenshots?', 'a' => 'Yes. Tracksuite is built to be transparent — the people being tracked can see what\'s captured.'],
                ['q' => 'Which platforms does the desktop tracker support?', 'a' => 'Windows and macOS, with auto-updates and offline queueing.'],
            ]]),
            self::b('cta_band', ['anchor' => 'contact', 'heading' => 'Start tracking in minutes', 'text' => 'Create your workspace free and see your hours, screenshots and attendance in one place.', 'primary_cta' => ['label' => 'Start free', 'url' => '/register']]),
        ];
    }
}
