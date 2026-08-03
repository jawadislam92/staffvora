<?php

namespace Database\Seeders;

use App\Models\SitePage;
use App\Support\SiteNavigation;
use Illuminate\Database\Seeder;

/**
 * THE LAUNCH COPY FOR THE PUBLIC MARKETING SITE.
 *
 * App\Support\DefaultSiteContent ships the *scaffold* — skeleton pages that stay
 * drafts while they still contain a `[REPLACE]` marker, so placeholder legal and
 * pricing text can never go live. This seeder ships the real thing: finished copy
 * for every page a customer can reach, published (status + published_blocks) so
 * the site actually serves something.
 *
 * ------------------------------------------------------------------------------
 * RULES THIS FILE IS WRITTEN UNDER — keep them if you edit the copy
 * ------------------------------------------------------------------------------
 *  1. NEVER ADVERTISE WHAT DOES NOT EXIST. Every capability named below was
 *     grepped for in this repo first. There is no SSO/SAML, no customer-facing
 *     API and no self-serve card checkout, so none of them appear. If you add a
 *     line here, prove the feature exists before you write it.
 *  2. NO COMPLIANCE CLAIMS. GDPR, CCPA, SOC 2 and ISO 27001 have not been
 *     assessed. The security page says so out loud instead of implying otherwise.
 *  3. Prices are the owner-approved placeholders (see documents/
 *     STAFFVORA_MARKETING_COPY_DECK.md). USD + seats render from the live `plans`
 *     table; the PKR figures and the Agency add-on card come from block content.
 *  4. The legal pages are HONEST FIRST DRAFTS, NOT LAWYER-REVIEWED. The copy deck
 *     lists the questions a qualified lawyer must answer before launch.
 *
 * ------------------------------------------------------------------------------
 * IDEMPOTENCE / NOT CLOBBERING THE OPERATOR
 * ------------------------------------------------------------------------------
 * A page is (re)written only when it is missing, unpublished, empty, or still
 * carrying scaffold copy (`[REPLACE]` or one of the STALE_PHRASES below). Once
 * this seeder has run, a second run is a no-op — so an operator's Site Builder
 * edits survive re-seeding on deploy. To deliberately overwrite everything with
 * the copy in this file, set SITE_CONTENT_FORCE=1 for the command:
 *
 *     php artisan db:seed --class=SiteContentSeeder            # safe refresh
 *     SITE_CONTENT_FORCE=1 php artisan db:seed --class=SiteContentSeeder
 */
class SiteContentSeeder extends Seeder
{
    /** Set by tests / callers that want an unconditional rewrite. */
    public static bool $force = false;

    private const UPDATED = '3 August 2026';

    private const SUPPORT_EMAIL = 'hello@staffvora.com';

    /**
     * Fingerprints of the pre-launch scaffold copy. A page still containing any
     * of these has never been meaningfully edited, so replacing it is safe (and
     * desirable — every phrase here is wrong about the product or the pricing).
     */
    private const STALE_PHRASES = [
        '[REPLACE',
        '14-day Pro trial',
        'Your happy customer',
        'Add a real review',
        'Office Work',
        'Up to 3 users',
        'This is a scaffold, not binding legal text',
        'Most time trackers were built to police people',
        'where we\'re based',
        'Additional platforms coming soon',
        // Scaffold copy that promised things the product does not do: payroll
        // processing, client invoicing, and a live "currently tracking" wall.
        'Payroll-ready timesheets',
        'invoices your clients trust',
        'without the surveillance backlash',
        'Questions, a demo, or help getting set up',
    ];

    public function run(): void
    {
        // getenv() as well as env(): with a cached config the .env file is not
        // loaded, and the override is typically passed inline on the command.
        $force = static::$force
            || filter_var(env('SITE_CONTENT_FORCE', getenv('SITE_CONTENT_FORCE')), FILTER_VALIDATE_BOOL);
        $wrote = 0;

        foreach ($this->pages() as $slug => $def) {
            $page = SitePage::where('slug', $slug)->first();

            if ($page && ! $force && ! $this->needsRewrite($page)) {
                continue;
            }

            $attributes = [
                'title' => $def['title'],
                'meta_title' => $def['meta_title'] ?? $def['title'].' — Staffvora',
                'meta_description' => $def['meta_description'] ?? null,
                'status' => 'published',
                'show_in_nav' => $def['show_in_nav'] ?? true,
                'blocks' => $def['blocks'],
                'published_blocks' => $def['blocks'],
                'sort' => $def['sort'] ?? 0,
            ];

            if ($page) {
                $page->update($attributes);
            } else {
                $page = SitePage::create($attributes + ['slug' => $slug]);
            }

            SitePage::forgetCache($slug);
            $wrote++;
        }

        SiteNavigation::forgetCache();

        $this->command?->info("SiteContentSeeder: {$wrote} page(s) written and published.");
    }

    /** True when the live page is missing content, unpublished, or still scaffold copy. */
    private function needsRewrite(SitePage $page): bool
    {
        if ($page->status !== 'published' || empty($page->published_blocks)) {
            return true;
        }

        $json = (string) json_encode([$page->blocks, $page->published_blocks], JSON_UNESCAPED_UNICODE);

        foreach (self::STALE_PHRASES as $phrase) {
            if (str_contains($json, $phrase)) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------------
    // Block helpers
    // ---------------------------------------------------------------------

    private function b(string $type, array $data): array
    {
        return ['id' => 'b'.bin2hex(random_bytes(4)), 'type' => $type, 'data' => $data];
    }

    private function cta(string $heading, string $text, string $label = 'Start free'): array
    {
        return $this->b('cta_band', [
            'heading' => $heading,
            'text' => $text,
            'primary_cta' => ['label' => $label, 'url' => '/register'],
        ]);
    }

    /** The standard hero CTA pair. */
    private function startCtas(): array
    {
        return [
            'primary_cta' => ['label' => 'Start free', 'url' => '/register'],
            'secondary_cta' => ['label' => 'Talk to us', 'url' => '/contact'],
        ];
    }

    private const TRIAL_NOTE = 'Free for 2 seats · 14-day Growth trial · no card required';

    /**
     * The six capabilities the product genuinely ships. Reused on Home and
     * Features so the two pages can never drift apart.
     */
    private function coreFeatures(): array
    {
        return [
            ['icon' => 'clock', 'title' => 'Clock in, clock out', 'text' => 'Start the day from the web app or the desktop tracker, take breaks, and finish. Overnight shifts land on the right day and idle time pauses itself.'],
            ['icon' => 'calendar', 'title' => 'Attendance & shifts', 'text' => 'Shifts, week-offs, holidays, late detection and a monthly attendance grid your HR team can read at a glance.'],
            ['icon' => 'check', 'title' => 'Timesheets with approval', 'text' => 'Hours roll up into pay-period timesheets a manager reviews, approves or sends back — with an audit trail behind every change.'],
            ['icon' => 'chart', 'title' => 'Reports & insights', 'text' => 'Work-hours reports by person, team, client or work type. Filter any way you like, then export to CSV in one click.'],
            ['icon' => 'camera', 'title' => 'Screenshots & activity', 'text' => 'From Starter up: periodic screenshots and activity levels while tracking — visible to the person tracked, and switchable off per workspace.'],
            ['icon' => 'slack', 'title' => 'Slack digests', 'text' => 'Daily activity digests, weekly summaries and clock-in/out notifications posted to the channels you choose.'],
        ];
    }

    /** The "what we capture and what we don't" split — the trust argument, verbatim true. */
    private function transparencySplit(string $side = 'right'): array
    {
        return $this->b('feature_split', [
            'eyebrow' => 'Transparent by design',
            'heading' => 'Counts, not contents',
            'text' => 'Staffvora is monitoring software, so we are precise about what it records. It captures how much activity there was — not what was said, typed or read.',
            'bullets' => [
                'Keystroke and mouse counts, never the keystrokes themselves',
                'The app name and the website domain, never the full URL or page contents',
                'No webcam, no microphone, no location tracking, no file access',
                'Screenshots only when the workspace enables them — and they can be blurred',
            ],
            'image_side' => $side,
        ]);
    }

    // ---------------------------------------------------------------------
    // The pages
    // ---------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    private function pages(): array
    {
        return [
            'home' => $this->home(),
            'features' => $this->features(),
            'how-it-works' => $this->howItWorks(),
            'pricing' => $this->pricing(),
            'solutions' => $this->solutions(),
            'solutions/agencies' => $this->solutionsAgencies(),
            'solutions/bpos' => $this->solutionsBpos(),
            'solutions/software-houses' => $this->solutionsSoftwareHouses(),
            'solutions/remote-teams' => $this->solutionsRemoteTeams(),
            'solutions/freelancers' => $this->solutionsFreelancers(),
            'about' => $this->about(),
            'contact' => $this->contact(),
            'security' => $this->security(),
            'download' => $this->download(),
            'privacy' => $this->privacy(),
            'terms' => $this->terms(),
        ];
    }

    private function home(): array
    {
        return [
            'title' => 'Home', 'sort' => 0,
            'meta_title' => 'Staffvora — time tracking that respects your team',
            'meta_description' => 'Clock in and out, attendance, timesheets and reports in one workspace — with an optional desktop tracker for screenshots and activity. Free for 2 seats.',
            'blocks' => [
                $this->b('hero', [
                    'eyebrow' => 'Time tracking for teams that bill by the hour',
                    'heading' => 'Every hour accounted for.',
                    'heading_highlight' => 'Nobody watched over.',
                    'subhead' => 'Clock in and out, attendance, timesheets and reports in one workspace — plus an optional desktop tracker with screenshots and activity when you switch it on.',
                    ...$this->startCtas(),
                    'note' => self::TRIAL_NOTE,
                    'image_url' => null,
                ]),
                $this->b('feature_grid', [
                    'anchor' => 'features',
                    'heading' => 'Everything the team needs',
                    'subhead' => 'From the first clock-in to the client report — without a spreadsheet in sight.',
                    'features' => $this->coreFeatures(),
                ]),
                $this->transparencySplit('right'),
                $this->b('stats', ['stats' => [
                    ['value' => '2 seats', 'label' => 'free, with no card'],
                    ['value' => '14 days', 'label' => 'of Growth on every new workspace'],
                    ['value' => '1 click', 'label' => 'to export any report as CSV'],
                ]]),
                $this->b('steps', ['heading' => 'Up and running the same afternoon', 'steps' => [
                    ['n' => '1', 'title' => 'Create your workspace', 'text' => 'Sign up, name the workspace and invite your team. Nothing to install to get started.'],
                    ['n' => '2', 'title' => 'Clock in', 'text' => 'People clock in from the browser — or install the optional desktop tracker if you want screenshots and activity.'],
                    ['n' => '3', 'title' => 'Reports build themselves', 'text' => 'Attendance, timesheets, reports and Slack digests, without anyone filling in a form at the end of the week.'],
                ]]),
                $this->b('feature_split', [
                    'eyebrow' => 'For agencies',
                    'heading' => 'Marketplace work, handled',
                    'text' => 'Agencies that work through Upwork can add the Agency toolkit: profile tracking, marketplace work categories, and Machine Compliance — a per-machine inventory of browser extensions, installed programs, running processes and network tools, checked against a blocklist you control.',
                    'bullets' => ['Upwork profiles and their hours', 'Marketplace work categories', 'Machine Compliance inventory and alerts', 'Sold as a per-seat add-on, not a separate product'],
                    'image_side' => 'left',
                ]),
                $this->b('faq', ['heading' => 'Frequently asked questions', 'items' => [
                    ['q' => 'Do I need a credit card to start?', 'a' => 'No. Create a workspace and you get a 14-day trial of Growth with everything unlocked. When it ends you can stay on the Free plan or choose a paid one.'],
                    ['q' => 'Does everyone have to install something?', 'a' => 'No. Clocking in, attendance, timesheets and reports all work in the browser. The desktop tracker is optional — it is only needed for screenshots and activity capture.'],
                    ['q' => 'Can employees see what is recorded about them?', 'a' => 'Yes. People can see their own tracked time, activity and screenshots. Staffvora is built to be visible to the person being tracked, not hidden from them.'],
                    ['q' => 'Can screenshots be turned off?', 'a' => 'Yes. Capture is a workspace setting: switch it off entirely, blur screenshots, notify people when one is taken, or narrow it per person.'],
                    ['q' => 'How do we pay?', 'a' => 'By bank transfer today — pick a plan, send the transfer and email us the receipt, and we activate it within one business day. Self-serve card checkout is not live yet.'],
                ]]),
                $this->b('cta_band', [
                    'anchor' => 'contact',
                    'heading' => 'Start tracking this week',
                    'text' => 'Create your workspace free — two seats, no card, no sales call.',
                    'primary_cta' => ['label' => 'Start free', 'url' => '/register'],
                ]),
            ],
        ];
    }

    private function features(): array
    {
        return [
            'title' => 'Features', 'sort' => 1,
            'meta_description' => 'Time tracking, attendance, timesheets, reports, optional screenshots and activity, Slack digests and an Agency toolkit — everything Staffvora actually does.',
            'blocks' => [
                $this->b('hero', [
                    'variant' => 'center',
                    'eyebrow' => 'Features',
                    'heading' => 'Everything you need to run time,',
                    'heading_highlight' => 'in one workspace.',
                    'subhead' => 'Hours, attendance, timesheets and reports for every team — with monitoring you can dial up or switch off entirely.',
                    ...$this->startCtas(),
                    'note' => self::TRIAL_NOTE,
                ]),
                $this->b('feature_grid', [
                    'heading' => 'The core, on every plan',
                    'subhead' => 'Time tracking, attendance, timesheets and reports are included from Free up. Screenshots and activity start on Starter.',
                    'features' => $this->coreFeatures(),
                ]),
                $this->b('feature_split', [
                    'eyebrow' => 'Insight',
                    'heading' => 'See where the time actually went',
                    'text' => 'On Growth and above, Staffvora turns tracked time into something you can act on: a day timeline, the apps and website domains people worked in, team and per-person trends, and an AI summary of a day in plain English.',
                    'bullets' => ['Daily activity timeline', 'Top apps and website domains', 'Team and per-person insights', 'AI day summaries in plain English'],
                    'image_side' => 'right',
                ]),
                $this->transparencySplit('left'),
                $this->b('feature_grid', [
                    'heading' => 'Agency toolkit — an optional add-on',
                    'subhead' => 'For agencies delivering work through marketplaces. Attaches to Starter, Growth or Enterprise.',
                    'features' => [
                        ['icon' => 'users', 'title' => 'Upwork profiles', 'text' => 'Track which profile work was delivered under, and see the hours behind each one.'],
                        ['icon' => 'chart', 'title' => 'Marketplace work categories', 'text' => 'Categorise time the way marketplace contracts are actually structured, then report on it.'],
                        ['icon' => 'shield', 'title' => 'Machine Compliance', 'text' => 'A per-machine inventory of browser extensions, installed programs, running processes and network tools, checked against a blocklist you control, with alerts when something risky appears.'],
                    ],
                ]),
                $this->b('feature_grid', [
                    'heading' => 'Built for the people running it',
                    'features' => [
                        ['icon' => 'users', 'title' => 'Roles and permissions', 'text' => 'Fine-grained permissions decide who sees screenshots, reports, attendance and settings — not who happens to know a URL.'],
                        ['icon' => 'lock', 'title' => 'Workspace privacy controls', 'text' => 'Capture on or off, screenshot frequency, blur, screenshot notifications, idle threshold, auto-pause and retention — all yours to set.'],
                        ['icon' => 'globe', 'title' => 'Timezones that behave', 'text' => 'Store once, display per viewer. Overnight shifts stay on the day they belong to.'],
                        ['icon' => 'bolt', 'title' => 'Works offline', 'text' => 'The desktop tracker queues time while the connection is down and syncs it when the network comes back.'],
                        ['icon' => 'star', 'title' => 'White-label branding', 'text' => 'On Growth and above, put your own logo on the workspace your team sees every day.'],
                        ['icon' => 'check', 'title' => 'Help centre and request inbox', 'text' => 'A searchable help centre inside the app, and a request inbox where your team can raise issues and follow the thread.'],
                    ],
                ]),
                $this->cta('See it on your own team', 'Two free seats, no card. Add the rest when you are convinced.'),
            ],
        ];
    }

    private function howItWorks(): array
    {
        return [
            'title' => 'How it works', 'sort' => 2,
            'meta_description' => 'How Staffvora works: create a workspace, clock in from the browser or the desktop tracker, and let attendance, timesheets and reports build themselves.',
            'blocks' => [
                $this->b('hero', [
                    'variant' => 'center',
                    'eyebrow' => 'How it works',
                    'heading' => 'Set it up once.',
                    'heading_highlight' => 'Then mostly forget it.',
                    'subhead' => 'No lengthy rollout, no consultant. Create a workspace, invite the team, and the record keeps itself.',
                    ...$this->startCtas(),
                ]),
                $this->b('steps', ['heading' => 'Three steps to a real record of the week', 'steps' => [
                    ['n' => '1', 'title' => 'Create a workspace and invite people', 'text' => 'Set your shifts, week-offs and holidays once. Add your team by email and give each person a role.'],
                    ['n' => '2', 'title' => 'Everyone clocks in', 'text' => 'From the browser on any device — or from the desktop tracker if you want screenshots and activity. Breaks and idle time are handled for you.'],
                    ['n' => '3', 'title' => 'Approve and report', 'text' => 'Managers approve timesheets for the pay period, reports filter to whatever you need, and Slack gets the daily digest.'],
                ]]),
                $this->transparencySplit('right'),
                $this->b('feature_split', [
                    'eyebrow' => 'Rolling it out',
                    'heading' => 'Tell your team first — we mean it',
                    'text' => 'Monitoring works when people know it is there. Before you enable screenshots or activity capture, tell your team what will be recorded, why, and who can see it. Staffvora is built so that story is easy to tell: everyone can see their own data, and nothing is captured in secret.',
                    'bullets' => ['People see their own screenshots and activity', 'Screenshot notifications can be switched on', 'Capture can be narrowed to specific people', 'Retention windows are yours to set'],
                    'image_side' => 'left',
                ]),
                $this->b('faq', ['heading' => 'Common questions', 'items' => [
                    ['q' => 'Do employees know they are being tracked?', 'a' => 'The tracker is visible and people can see their own data. Telling your team what is recorded, and making sure that is lawful where they work, remains the employer\'s responsibility.'],
                    ['q' => 'Does it work offline?', 'a' => 'Yes. The desktop tracker queues time while the connection is down and syncs when it returns.'],
                    ['q' => 'Can I turn screenshots off?', 'a' => 'Yes — screenshots are a workspace setting and can be disabled entirely, blurred, or limited to certain people.'],
                    ['q' => 'What happens to time nobody tracked?', 'a' => 'Managers can add or correct time, and every manual change is recorded in an audit trail so the timesheet stays defensible.'],
                    ['q' => 'How long is data kept?', 'a' => 'You choose the retention window, up to the maximum your plan allows — 7 days on Free, 90 days on Starter, a year on Growth, and unlimited on Enterprise. A scheduled job deletes anything older, including the stored screenshot files.'],
                ]]),
                $this->cta('Ready to try it?', 'Create your workspace and see your first tracked hours today.'),
            ],
        ];
    }

    private function pricing(): array
    {
        return [
            'title' => 'Pricing', 'sort' => 3,
            'meta_title' => 'Pricing — Staffvora',
            'meta_description' => 'Per-seat pricing from $0. Free for 2 seats, Starter $5, Growth $9, Enterprise from $18 — plus an optional Agency toolkit add-on. Pay yearly for 10 months instead of 12.',
            'blocks' => [
                $this->b('hero', [
                    'variant' => 'center',
                    'eyebrow' => 'Pricing',
                    'heading' => 'Per seat. Per month.',
                    'heading_highlight' => 'No surprises.',
                    'subhead' => 'Start free for two seats. Paying yearly costs ten months instead of twelve — about 17% off. Prices are shown in USD or Pakistani rupees.',
                    'note' => self::TRIAL_NOTE,
                ]),
                $this->b('pricing_table', [
                    'anchor' => 'pricing',
                    'heading' => '',
                    'subhead' => '',
                    // Owner-approved local prices (fixed figures, not an FX
                    // conversion). Keys are plan keys — note Growth's key is `pro`.
                    'pkr' => ['free' => 0, 'starter' => 900, 'pro' => 1600, 'enterprise' => 3200],
                    'addon' => [
                        'eyebrow' => 'Add-on',
                        'name' => 'Agency toolkit',
                        'text' => 'For agencies delivering work through marketplaces. Adds Upwork profiles, marketplace work categories and Machine Compliance to the plan you are already on — the same product, with the agency tools switched on.',
                        'bullets' => [
                            'Upwork profiles and the hours behind each one',
                            'Marketplace work categories in reports',
                            'Machine Compliance: per-machine extension, program, process and network inventory',
                            'Blocklist rules and alerts you control',
                        ],
                        'price_usd' => 4,
                        'price_usd_yearly' => 40,
                        'price_pkr' => 700,
                        'note' => 'Priced per seat and charged alongside your plan. Attaches to Starter, Growth or Enterprise — not to Free. Email '.self::SUPPORT_EMAIL.' and we will switch it on for your workspace.',
                    ],
                    'note' => 'Prices are per seat. Yearly billing costs ten months instead of twelve. We activate paid plans by bank transfer within one business day — self-serve card checkout is not live yet.',
                ]),
                $this->b('feature_grid', [
                    'heading' => 'What changes as you move up',
                    'subhead' => 'Seats and history grow, and monitoring unlocks in two steps.',
                    'features' => [
                        ['icon' => 'clock', 'title' => 'Free — 2 seats, 7 days of history', 'text' => 'Time tracking, attendance, shifts, timesheets, reports and CSV export. No screenshots, no activity capture, no card.'],
                        ['icon' => 'camera', 'title' => 'Starter — 10 seats, 90 days', 'text' => 'Adds screenshots and activity levels, weekly hour limits, attendance alerts and Slack digests.'],
                        ['icon' => 'chart', 'title' => 'Growth — 50 seats, 1 year', 'text' => 'Adds app and website-domain tracking, team insights, AI day summaries and white-label branding. This is the plan every trial runs on.'],
                        ['icon' => 'shield', 'title' => 'Enterprise — unlimited seats and history', 'text' => 'Everything in Growth with no seat cap, unlimited history and audit trail, and priority onboarding. From $18 per seat, with yearly terms agreed with you.'],
                    ],
                ]),
                $this->b('faq', ['heading' => 'Pricing FAQ', 'items' => [
                    ['q' => 'Is the Free plan really free?', 'a' => 'Yes. Two seats, no card, no expiry. It keeps seven days of history and does not include screenshots or activity capture.'],
                    ['q' => 'How does the trial work?', 'a' => 'Every new workspace starts on a 14-day trial of Growth with everything unlocked and no card. When it ends you can stay on Free or pick a plan.'],
                    ['q' => 'What does yearly billing save?', 'a' => 'Paying yearly costs ten months instead of twelve — roughly 17% off. Enterprise yearly terms are agreed with you.'],
                    ['q' => 'How do I actually pay?', 'a' => 'By bank transfer for now. Choose your plan, send the transfer and email the receipt with your workspace name to '.self::SUPPORT_EMAIL.', and we activate it within one business day. Self-serve card checkout is not live yet, so nothing is ever charged to a card automatically.'],
                    ['q' => 'What is the Agency toolkit?', 'a' => 'An optional per-seat add-on: Upwork profiles, marketplace work categories and Machine Compliance. It attaches to Starter, Growth or Enterprise — Staffvora stays one product, and the agency tools are simply switched on.'],
                    ['q' => 'What if we outgrow our seats?', 'a' => 'Each plan includes a seat limit. Move up a plan when you need more, or talk to us about Enterprise if you need an unlimited workspace.'],
                    ['q' => 'Can I cancel?', 'a' => 'Any time — there is no lock-in contract. Export your reports whenever you like, and ask us to delete your workspace when you are done.'],
                ]]),
                $this->cta('Try Growth free for 14 days', 'Create your workspace in seconds. No card, no commitment, no sales call.'),
            ],
        ];
    }

    private function solutions(): array
    {
        return [
            'title' => 'Solutions', 'sort' => 4,
            'meta_description' => 'Staffvora for agencies, BPOs and call centres, software houses, remote teams and freelancers.',
            'blocks' => [
                $this->b('hero', [
                    'variant' => 'center',
                    'eyebrow' => 'Solutions',
                    'heading' => 'Built for the way',
                    'heading_highlight' => 'your team works.',
                    'subhead' => 'Whether you bill by the hour or run a floor around the clock, the same workspace adapts to how your team actually operates.',
                    ...$this->startCtas(),
                ]),
                $this->b('feature_grid', ['heading' => 'Who it is for', 'subhead' => 'Pick the setup closest to your team.', 'features' => [
                    ['icon' => 'users', 'title' => 'Agencies & consultancies', 'text' => 'Track billable hours per client and turn them into reports you can put in front of the client.'],
                    ['icon' => 'globe', 'title' => 'BPOs & call centres', 'text' => 'Shift attendance, live activity and workforce reporting across large, distributed floors.'],
                    ['icon' => 'desktop', 'title' => 'Software houses', 'text' => 'Per-project time, app and website-domain insight, and metrics that do not treat engineers like machines.'],
                    ['icon' => 'clock', 'title' => 'Remote & hybrid teams', 'text' => 'Keep a distributed team aligned across timezones without anyone feeling watched.'],
                    ['icon' => 'chart', 'title' => 'Freelancers', 'text' => 'Honest time tracking and clean timesheets for a team of one.'],
                ]]),
                $this->b('feature_split', [
                    'eyebrow' => 'One workspace',
                    'heading' => 'Numbers your clients can trust',
                    'text' => 'Every plan turns tracked time into timesheets and reports you can export, so billing and payroll stop being a negotiation about what somebody remembers.',
                    'bullets' => ['Time per client, team and work type', 'Timesheets with manager approval', 'CSV exports and Slack digests', 'An audit trail behind manual edits'],
                    'image_side' => 'right',
                ]),
                $this->cta('Find your fit', 'Start free and shape Staffvora around your team in an afternoon.'),
            ],
        ];
    }

    private function solutionsAgencies(): array
    {
        return [
            'title' => 'For agencies', 'sort' => 5, 'show_in_nav' => false,
            'meta_description' => 'Time tracking for agencies — billable hours per client, reports clients trust, and an optional Agency toolkit for marketplace work.',
            'blocks' => [
                $this->b('hero', [
                    'variant' => 'center', 'eyebrow' => 'For agencies',
                    'heading' => 'Bill every hour,',
                    'heading_highlight' => 'and prove it.',
                    'subhead' => 'Track time per client and work type, then turn it into reports your clients accept without an argument.',
                    ...$this->startCtas(),
                ]),
                $this->b('feature_split', [
                    'eyebrow' => 'The problem',
                    'heading' => 'Stop leaking billable hours',
                    'text' => 'Hours reconstructed on Friday afternoon are hours lost. Staffvora captures them as they happen, per client, so invoicing stops being an archaeology project.',
                    'bullets' => ['Time per client and work type', 'Timesheets approved before they are billed', 'One-click CSV for your invoicing tool', 'Optional screenshots as proof of work'],
                    'image_side' => 'right',
                ]),
                $this->b('feature_grid', ['heading' => 'What agencies use most', 'features' => [
                    ['icon' => 'clock', 'title' => 'Billable time per client', 'text' => 'One click to start; breaks, idle time and overnight work handled for you.'],
                    ['icon' => 'chart', 'title' => 'Client-ready reports', 'text' => 'Filter by client, team, person or work type and export in one click.'],
                    ['icon' => 'camera', 'title' => 'Proof of work', 'text' => 'Optional screenshots and activity levels, visible to the person tracked, for clients who ask for evidence.'],
                    ['icon' => 'users', 'title' => 'Upwork profiles', 'text' => 'With the Agency toolkit add-on: see the hours behind each marketplace profile.'],
                    ['icon' => 'shield', 'title' => 'Machine Compliance', 'text' => 'With the Agency toolkit: know which extensions, programs and network tools are on the machines doing marketplace work.'],
                    ['icon' => 'check', 'title' => 'Approvals that hold up', 'text' => 'Manager approval per pay period, with an audit trail behind every manual edit.'],
                ]]),
                $this->cta('Win back the billable hours', 'Start free with two seats and see a week of real data.'),
            ],
        ];
    }

    private function solutionsBpos(): array
    {
        return [
            'title' => 'For BPOs & call centres', 'sort' => 6, 'show_in_nav' => false,
            'meta_description' => 'Workforce visibility for BPOs and call centres — shift attendance, late detection, monthly grids and activity across large floors.',
            'blocks' => [
                $this->b('hero', [
                    'variant' => 'center', 'eyebrow' => 'For BPOs & call centres',
                    'heading' => 'Attendance you can trust',
                    'heading_highlight' => 'on every shift.',
                    'subhead' => 'Shifts around the clock, late detection, week-offs and holidays — and a monthly grid that answers "who was in?" in one look.',
                    ...$this->startCtas(),
                ]),
                $this->b('feature_split', [
                    'eyebrow' => 'Built for the floor',
                    'heading' => 'The whole floor, at a glance',
                    'text' => 'Overnight shifts land on the day they belong to, not the day the clock rolled over. Late arrivals are flagged automatically, and the monthly attendance grid is the document HR actually wants.',
                    'bullets' => ['Named shifts and per-person overrides', 'Late-coming detection', 'Week-offs, holidays and auto-absent rules', 'Monthly attendance grid and CSV export'],
                    'image_side' => 'left',
                ]),
                $this->b('feature_grid', ['heading' => 'What large teams rely on', 'features' => [
                    ['icon' => 'calendar', 'title' => 'Shift attendance', 'text' => 'Clock in and out with breaks, across shifts that run through midnight.'],
                    ['icon' => 'chart', 'title' => 'Workforce reporting', 'text' => 'Hours and activity by team, person or work type, over any date range.'],
                    ['icon' => 'slack', 'title' => 'Slack notifications', 'text' => 'Clock-in and clock-out notifications plus daily digests into the channels supervisors already watch.'],
                ]]),
                $this->cta('Bring your floor online', 'Start free, then roll out shift by shift.'),
            ],
        ];
    }

    private function solutionsSoftwareHouses(): array
    {
        return [
            'title' => 'For software houses', 'sort' => 7, 'show_in_nav' => false,
            'meta_description' => 'Time tracking for software houses — per-project hours, app and website-domain insight, and metrics that respect engineers.',
            'blocks' => [
                $this->b('hero', [
                    'variant' => 'center', 'eyebrow' => 'For software houses',
                    'heading' => 'Measure the work,',
                    'heading_highlight' => 'not the keystrokes.',
                    'subhead' => 'Per-project hours and honest activity data for engineering teams — without the surveillance theatre that makes good developers leave.',
                    ...$this->startCtas(),
                ]),
                $this->b('feature_split', [
                    'eyebrow' => 'Why engineers tolerate it',
                    'heading' => 'Activity that is not a productivity score',
                    'text' => 'Staffvora records keystroke and mouse counts, the app in front, and the website domain — never what was typed or read. Thinking time is not punished, and nobody is ranked by a number nobody trusts.',
                    'bullets' => ['No keylogging, ever', 'Domains, not full URLs', 'Idle auto-pause instead of padded hours', 'Everyone can see their own data'],
                    'image_side' => 'right',
                ]),
                $this->b('feature_grid', ['heading' => 'What dev teams get out of it', 'features' => [
                    ['icon' => 'chart', 'title' => 'Hours per project', 'text' => 'Know what a feature really cost before you quote the next one.'],
                    ['icon' => 'bolt', 'title' => 'AI day summaries', 'text' => 'On Growth and above, a plain-English summary of a day instead of a wall of raw samples.'],
                    ['icon' => 'check', 'title' => 'Timesheets with approval', 'text' => 'A lead approves the period; manual corrections are recorded, not silently overwritten.'],
                ]]),
                $this->cta('Try it with one squad', 'Two free seats — start with a single team and see what the data says.'),
            ],
        ];
    }

    private function solutionsRemoteTeams(): array
    {
        return [
            'title' => 'For remote teams', 'sort' => 8, 'show_in_nav' => false,
            'meta_description' => 'Time tracking for remote and hybrid teams — timezone-correct attendance, optional monitoring and reports everyone can see.',
            'blocks' => [
                $this->b('hero', [
                    'variant' => 'center', 'eyebrow' => 'For remote & hybrid teams',
                    'heading' => 'Distributed, aligned,',
                    'heading_highlight' => 'and not micromanaged.',
                    'subhead' => 'Attendance that respects timezones, a shared picture of the week, and monitoring you can leave switched off.',
                    ...$this->startCtas(),
                ]),
                $this->b('feature_split', [
                    'eyebrow' => 'Timezones, done properly',
                    'heading' => 'Everyone sees the same day',
                    'text' => 'Time is stored once and displayed in the viewer\'s timezone, so a 9pm clock-in in Karachi does not become yesterday for a manager in London. Overnight work stays attached to the shift it belongs to.',
                    'bullets' => ['Per-viewer timezone display', 'Overnight shifts bucketed correctly', 'Week-offs and holidays per workspace', 'Daily Slack digest for the whole team'],
                    'image_side' => 'left',
                ]),
                $this->b('feature_grid', ['heading' => 'Trust, built in', 'features' => [
                    ['icon' => 'users', 'title' => 'Everyone sees their own data', 'text' => 'People can open their own hours, activity and screenshots — the same numbers their manager sees.'],
                    ['icon' => 'lock', 'title' => 'Monitoring is optional', 'text' => 'Run Staffvora as pure time tracking and never enable a single screenshot.'],
                    ['icon' => 'clock', 'title' => 'Idle auto-pause', 'text' => 'Time stops when work stops, so nobody has to defend an hour they did not work.'],
                ]]),
                $this->cta('Bring the team together', 'Start free and get a shared picture of the week by Friday.'),
            ],
        ];
    }

    private function solutionsFreelancers(): array
    {
        return [
            'title' => 'For freelancers', 'sort' => 9, 'show_in_nav' => false,
            'meta_description' => 'Simple time tracking for freelancers — hours per client, clean timesheets and CSV exports for invoicing.',
            'blocks' => [
                $this->b('hero', [
                    'variant' => 'center', 'eyebrow' => 'For freelancers',
                    'heading' => 'A tidy record',
                    'heading_highlight' => 'of every billable hour.',
                    'subhead' => 'Track time per client, export a clean timesheet, and invoice from something better than memory. Free while it is just you.',
                    'primary_cta' => ['label' => 'Start free', 'url' => '/register'],
                    'secondary_cta' => ['label' => 'See pricing', 'url' => '/pricing'],
                    'note' => 'Free plan covers 2 seats — enough for you and one collaborator.',
                ]),
                $this->b('feature_grid', ['heading' => 'What you get for nothing', 'features' => [
                    ['icon' => 'clock', 'title' => 'One-click tracking', 'text' => 'Start against a client, take a break, stop. Idle time pauses itself.'],
                    ['icon' => 'chart', 'title' => 'Reports and CSV export', 'text' => 'Filter to a client and a date range, export, attach to the invoice.'],
                    ['icon' => 'camera', 'title' => 'Proof when a client wants it', 'text' => 'Add screenshots and activity from Starter if a client asks for evidence of the hours.'],
                ]]),
                $this->cta('Start tracking today', 'Two seats free, no card. Upgrade only if you grow.'),
            ],
        ];
    }

    private function about(): array
    {
        return [
            'title' => 'About', 'sort' => 10,
            'meta_description' => 'Staffvora is built by a small remote-first team that ran its own distributed workforce first — and wanted a tracker people did not resent.',
            'blocks' => [
                $this->b('hero', [
                    'variant' => 'center', 'eyebrow' => 'About us',
                    'heading' => 'We measure the work,',
                    'heading_highlight' => 'not the worker.',
                    'subhead' => 'That sentence is not a slogan we reverse-engineered. It is the constraint we build the product under.',
                ]),
                $this->b('rich_text', ['html' =>
                    '<h2>Why Staffvora exists</h2>'
                    .'<p>Staffvora did not start as a product. It started as the tracker we built for our own distributed team, because everything we tried either lost hours or made people feel watched. We ran it internally for years — through overnight shifts, marketplace contracts, disputed timesheets and the occasional awkward conversation — and fixed the things that broke.</p>'
                    .'<p>Staffvora is that system, rebuilt properly for other companies.</p>'
                    .'<h2>What we believe</h2>'
                    .'<p><strong>Accountability and respect are not opposites.</strong> A team will accept a tracker that is honest with them and resent one that is not. So the person being tracked can always see their own data, capture is off until an employer switches it on, and we record counts rather than contents — never what someone typed or read.</p>'
                    .'<p><strong>Say what the software does, exactly.</strong> If a capability is not built, it is not on this website. We would rather lose a sale than win one on a feature that does not exist.</p>'
                    .'<p><strong>Local prices, not converted ones.</strong> We are based in Pakistan and sell to teams here as well as abroad, so the rupee price is a real price rather than a currency conversion of a dollar figure.</p>'
                    .'<h2>How we work</h2>'
                    .'<p>We are a small, remote-first team. There is no support queue theatre — write to <a href="mailto:'.self::SUPPORT_EMAIL.'">'.self::SUPPORT_EMAIL.'</a> and a person who works on the product answers. If something in Staffvora is wrong, tell us; the request inbox inside the app goes to the same people.</p>',
                ]),
                $this->b('stats', ['stats' => [
                    ['value' => 'Remote-first', 'label' => 'how we work'],
                    ['value' => 'Pakistan', 'label' => 'where we are based'],
                    ['value' => 'Two editions', 'label' => 'general and agency, one product'],
                ]]),
                $this->cta('Come and try it', 'Start free and tell us what we got wrong — we will read it.'),
            ],
        ];
    }

    private function contact(): array
    {
        return [
            'title' => 'Contact', 'sort' => 11,
            'meta_description' => 'Talk to the Staffvora team — questions, a demo, help getting set up, or activating a paid plan.',
            'blocks' => [
                $this->b('hero', [
                    'variant' => 'center', 'eyebrow' => 'Contact',
                    'heading' => 'Talk to a human.',
                    'subhead' => 'Questions about the product, a walkthrough for your team, help getting set up, or activating a paid plan — write to '.self::SUPPORT_EMAIL.' or use the form. We usually reply within one business day.',
                ]),
                $this->b('contact', ['form_heading' => 'Send us a message']),
                $this->b('faq', ['heading' => 'Before you write', 'items' => [
                    ['q' => 'I want to buy a plan.', 'a' => 'Tell us your workspace name, the plan and the number of seats, and we will send bank transfer details and activate it within one business day.'],
                    ['q' => 'I want the Agency toolkit.', 'a' => 'Say so in your message with your workspace name — it is a per-seat add-on on top of Starter, Growth or Enterprise, and we switch it on for you.'],
                    ['q' => 'I need a security or privacy review.', 'a' => 'Ask, and we will answer specifics in writing, including where your data is hosted and what the desktop tracker records.'],
                    ['q' => 'Something is broken.', 'a' => 'If you already have a workspace, the request inbox inside the app is the fastest route — it reaches the same team and keeps the thread in one place.'],
                ]]),
            ],
        ];
    }

    private function security(): array
    {
        return [
            'title' => 'Security', 'sort' => 12,
            'meta_description' => 'How Staffvora protects workspace data: private screenshot storage, role-based access, workspace isolation, retention controls — and an honest account of what we have not certified.',
            'blocks' => [
                $this->b('hero', [
                    'variant' => 'center', 'eyebrow' => 'Security & privacy',
                    'heading' => 'Serious about the data',
                    'heading_highlight' => 'people trust us with.',
                    'subhead' => 'Staffvora holds screenshots of people at work. That deserves care — and a straight answer about what we do and do not do.',
                ]),
                $this->b('feature_grid', ['heading' => 'The controls that are actually in the product', 'features' => [
                    ['icon' => 'lock', 'title' => 'Encrypted in transit', 'text' => 'Every request runs over HTTPS. Integration secrets, such as your Slack bot token, are encrypted in the database rather than stored in the clear.'],
                    ['icon' => 'camera', 'title' => 'Screenshots stay private', 'text' => 'Screenshots are written to private storage with no public URL. Every view goes through an authenticated, permission-checked request — a link on its own gets nobody in.'],
                    ['icon' => 'users', 'title' => 'Role-based access', 'text' => 'Fine-grained permissions decide who can see screenshots, reports, attendance and settings, and who can delete them.'],
                    ['icon' => 'globe', 'title' => 'Workspace isolation', 'text' => 'Every query is scoped to one workspace, and the application deliberately fails closed if that workspace context is ever missing.'],
                    ['icon' => 'shield', 'title' => 'Capture controls', 'text' => 'Screenshots and activity capture are workspace settings: off entirely, blurred, announced with a notification, or narrowed to specific people.'],
                    ['icon' => 'check', 'title' => 'Retention you set', 'text' => 'Choose how long monitoring data is kept, up to your plan maximum. A scheduled job deletes what is past the window — including the stored image files, not just the database rows.'],
                ]]),
                $this->b('rich_text', ['html' =>
                    '<h2>What we have not done — said plainly</h2>'
                    .'<p>Plenty of products in this category imply certifications they do not hold. So, precisely:</p>'
                    .'<ul>'
                    .'<li><strong>We have not been audited</strong> against SOC 2, ISO 27001 or any similar scheme, and we do not claim compliance with GDPR, CCPA or any other regime. If a certification matters to your procurement, we are not the right answer today, and we will tell you that rather than sell around it.</li>'
                    .'<li><strong>There is no single sign-on</strong> (no SAML, no Google/Microsoft SSO) and <strong>no public API</strong>. Both have appeared on time-tracker websites we admire; neither exists in Staffvora, so neither is sold here.</li>'
                    .'<li><strong>The desktop tracker is a preview build</strong> and is not yet code-signed or notarised. We will never ask you to disable security software to install it.</li>'
                    .'</ul>'
                    .'<h2>What the product never records</h2>'
                    .'<p>No keystroke contents — only how many keys were pressed. No webcam, no microphone, no GPS or location. No access to your files. Website tracking records the domain, not the full address or the page contents.</p>'
                    .'<h2>Reporting a vulnerability</h2>'
                    .'<p>Email <a href="mailto:'.self::SUPPORT_EMAIL.'">'.self::SUPPORT_EMAIL.'</a> with the words "security report" in the subject and enough detail to reproduce the issue. We will acknowledge it, keep you updated while we fix it, and credit you if you would like. Please do not test against another company\'s workspace.</p>',
                ]),
                $this->b('faq', ['heading' => 'Security FAQ', 'items' => [
                    ['q' => 'Do you log keystrokes?', 'a' => 'No. The tracker counts keyboard and mouse events to produce an activity level; the keys themselves are never captured or transmitted.'],
                    ['q' => 'Do you capture webcam, microphone or location?', 'a' => 'No. None of the three, on any plan.'],
                    ['q' => 'Where is our data stored?', 'a' => 'Workspace data lives in our managed cloud infrastructure, with screenshots in private object storage. If you need the hosting region named in writing for a contract or a review, ask and we will confirm it.'],
                    ['q' => 'Can we get everything deleted?', 'a' => 'Yes. Ask us to delete your workspace and we permanently remove it — the workspace, its users, time records, attendance, activity samples and screenshots, including the stored image files. It cannot be undone.'],
                    ['q' => 'Can your staff see our data?', 'a' => 'A small number of our people can access production data when they need to support or debug a workspace, and operator actions such as signing into a workspace to help are written to an audit log. We do not sell workspace data or use it to train anything.'],
                    ['q' => 'Do you use third-party analytics on this website?', 'a' => 'No. There are no advertising pixels, session recorders or third-party analytics scripts on this site.'],
                ]]),
                $this->cta('Questions we did not answer?', 'Write to us — we would rather have the conversation than have you guess.', 'Talk to us'),
            ],
        ];
    }

    private function download(): array
    {
        return [
            'title' => 'Download', 'sort' => 13,
            'meta_description' => 'Get the optional Staffvora desktop tracker for Windows and macOS. Time tracking, attendance and reports also work in the browser with nothing installed.',
            'blocks' => [
                $this->b('hero', [
                    'variant' => 'center', 'eyebrow' => 'Download',
                    'heading' => 'The desktop tracker',
                    'heading_highlight' => 'is optional.',
                    'subhead' => 'Clocking in, attendance, timesheets and reports all work in the browser. Install the desktop app only if you want screenshots and activity capture.',
                    'primary_cta' => ['label' => 'Create a workspace', 'url' => '/register'],
                    'secondary_cta' => ['label' => 'Sign in', 'url' => '/login'],
                    'note' => 'Installers live inside your workspace under Desktop downloads.',
                ]),
                $this->b('feature_grid', ['heading' => 'Where it runs', 'subhead' => 'Sign in, open Desktop downloads, and take the build for your platform.', 'features' => [
                    ['icon' => 'desktop', 'title' => 'Windows', 'text' => 'Windows 10 or later, 64-bit. The current preview build, with the file size and SHA-256 checksum shown next to the download so you can verify it.'],
                    ['icon' => 'desktop', 'title' => 'macOS', 'text' => 'Apple Silicon, macOS 12 or later. Published to workspaces as a preview build — if you do not see it in your workspace yet, email us and we will sort you out.'],
                    ['icon' => 'globe', 'title' => 'No install at all', 'text' => 'Clock in from any browser on any device. Attendance, timesheets, approvals and reports never need the desktop app.'],
                ]]),
                $this->b('rich_text', ['html' =>
                    '<h2>How to get it</h2>'
                    .'<ol><li>Create a workspace, or sign in to the one you have.</li>'
                    .'<li>Open <strong>Desktop downloads</strong> in the app.</li>'
                    .'<li>Download the build for your platform, check the SHA-256 shown on the page, and install it.</li>'
                    .'<li>Sign in with the same Staffvora account and enter your workspace address when the app asks for a server.</li></ol>'
                    .'<h2>Honest status</h2>'
                    .'<p>The desktop tracker is a <strong>preview build</strong>. It is not yet code-signed on Windows or notarised on macOS, so your operating system or antivirus may warn you when you install it. We are working through signing before broad rollout.</p>'
                    .'<p><strong>Never ask anyone to disable their security software to install Staffvora.</strong> If a machine blocks the installer, write to <a href="mailto:'.self::SUPPORT_EMAIL.'">'.self::SUPPORT_EMAIL.'</a> and we will help properly.</p>'
                    .'<h2>What the desktop app does on the machine</h2>'
                    .'<p>While tracking is running, and only when your workspace has enabled them, it captures periodic screenshots, keyboard and mouse counts, the active application, the window title and the website domain. It queues data while the connection is down and syncs when it returns. It stops when the person clocks out, and idle time pauses it automatically.</p>'
                    .'<h2>Requirements</h2>'
                    .'<ul><li>Windows 10 or later (64-bit), or macOS 12 or later on Apple Silicon</li>'
                    .'<li>An active Staffvora workspace and a sign-in</li>'
                    .'<li>An internet connection for syncing — short offline stretches are queued and sent later</li></ul>',
                ]),
                $this->cta('Start with the browser', 'Create your workspace free and add the desktop tracker only if you need it.'),
            ],
        ];
    }

    private function privacy(): array
    {
        return [
            'title' => 'Privacy Policy', 'sort' => 20, 'show_in_nav' => false,
            'meta_title' => 'Privacy Policy — Staffvora',
            'meta_description' => 'What Staffvora records, who can see it, how long it is kept, and how to have it deleted.',
            'blocks' => [
                $this->b('hero', [
                    'variant' => 'center',
                    'heading' => 'Privacy Policy',
                    'subhead' => 'Last updated: '.self::UPDATED.'. Staffvora is employee-monitoring software, so this page is specific about what is recorded rather than vague about it.',
                ]),
                $this->b('rich_text', ['html' =>
                    '<h2>1. Who does what</h2>'
                    .'<p>Staffvora is used by companies to track their own employees\' and contractors\' working time. <strong>Your employer decides</strong> what is switched on: whether screenshots are captured, whether applications and websites are recorded, how long data is kept, and who inside the company can see it. <strong>We provide and run the software</strong> and store the resulting data on their behalf.</p>'
                    .'<p>If you are a tracked employee and you want to know what is enabled for you, ask your workspace administrator — and sign in, where you can see your own records yourself.</p>'

                    .'<h2>2. What we collect</h2>'
                    .'<p><strong>Account and workspace information.</strong> Name, work email, role and permissions, designation, team, shift, avatar if uploaded, and workspace settings. For a paying workspace: billing contact details, address and tax identifier if you give them to us, and a record of payments received.</p>'
                    .'<p><strong>Time and attendance.</strong> Clock-in and clock-out times, breaks, tracked sessions, the client or work category a session was booked to, manual time entries and corrections (with who made them and when), timesheets and approvals, leave, week-offs and holidays.</p>'
                    .'<p><strong>Activity metrics</strong> — only while the desktop tracker is running and only if your workspace enables activity tracking. Counts of keyboard and mouse events in each sample window, idle seconds, and a derived activity percentage. <strong>We do not record what is typed.</strong> The keys themselves are never captured or transmitted.</p>'
                    .'<p><strong>Application and website information</strong> — only if your workspace enables app and website tracking (available from the Growth plan). The name of the active application, the title of the active window, and the <strong>domain</strong> of the website in use — for example <em>example.com</em> — not the full address, the query string or the page contents.</p>'
                    .'<p><strong>Screenshots</strong> — only if your workspace enables screenshot capture (available from the Starter plan). Periodic images of the screen while tracking is running, taken at an interval the workspace configures, with an activity percentage and the active application recorded alongside each one. A workspace can blur screenshots, and can turn on a notification so the person is told when one is taken. On the Free plan, screenshots are not captured at all.</p>'
                    .'<p><strong>Machine inventory</strong> — only for workspaces with the Agency toolkit add-on. An inventory of the browser extensions, installed programs, running processes and network/VPN tools present on a tracked machine, plus the device name, platform and app version, checked against the blocklist rules the workspace configures. This is used to answer marketplace compliance questions; it is not a file scan and does not read document contents.</p>'
                    .'<p><strong>Technical records.</strong> Standard server logs, the device and app version reporting in, and diagnostic reports the desktop app sends when something goes wrong.</p>'
                    .'<p><strong>What we never collect:</strong> keystroke contents, webcam images, microphone audio, GPS or location, the contents of your files, and anything at all from a personal machine that is not signed in and tracking.</p>'

                    .'<h2>3. Why we hold it</h2>'
                    .'<p>To provide the service your employer bought: recording working time, producing attendance and reports, running approvals, and supporting and securing the workspace. We also use aggregate, non-identifying figures (such as total workspaces or error rates) to keep the service running well.</p>'
                    .'<p>We do not sell workspace data, we do not share it with advertisers, and we do not use it to train machine-learning models.</p>'

                    .'<h2>4. Who can see it</h2>'
                    .'<ul>'
                    .'<li><strong>You.</strong> Every tracked person can sign in and see their own time, attendance, activity and screenshots.</li>'
                    .'<li><strong>People in your workspace with the relevant permission.</strong> Access to screenshots, reports, attendance and settings is controlled by role and per-user permissions that your administrator sets.</li>'
                    .'<li><strong>Us, when we have to.</strong> A small number of our staff can access production data to support, debug or secure the service, and platform operators can sign into a workspace to help with a problem. Those operator actions are recorded in an audit log.</li>'
                    .'</ul>'

                    .'<h2>5. Services we pass data to</h2>'
                    .'<ul>'
                    .'<li><strong>Cloud hosting and object storage</strong> — where the application, the database and the screenshot files live.</li>'
                    .'<li><strong>Slack</strong> — only if your workspace connects it. Digests, summaries and clock-in/out notifications are delivered to the channels you choose, which means the summarised figures leave our systems for Slack.</li>'
                    .'<li><strong>Anthropic</strong> — only for AI features on plans that include them, and only when the workspace uses them. Generating an AI day summary sends the relevant work data — the person\'s name, the day, tracked totals, session times, and top applications and website domains — to Anthropic\'s API to produce the summary text. Screenshots are never sent.</li>'
                    .'<li><strong>Email delivery</strong> — for invitations, password resets and service notices.</li>'
                    .'</ul>'
                    .'<p>This website carries no third-party analytics, no advertising pixels and no session recorders. The app uses its own cookies for signing in and keeping you signed in — nothing for advertising.</p>'

                    .'<h2>6. How long it is kept</h2>'
                    .'<p>Monitoring data — screenshots, activity samples and app/website records — is kept for the retention window your workspace sets, capped by its plan: <strong>7 days on Free, 90 days on Starter, 1 year on Growth</strong>, and unlimited on Enterprise unless the workspace sets a shorter window. A scheduled job deletes anything past the window, including the stored screenshot image files, not just the database rows.</p>'
                    .'<p>Time, attendance and timesheet records are kept while the workspace is active, because they are the employment record the company relies on. Account and billing records are kept while the workspace exists.</p>'

                    .'<h2>7. Deletion and your requests</h2>'
                    .'<p>Individuals should start with their employer, because the workspace decides what is captured and kept. Inside a workspace, an administrator can delete screenshots individually or in bulk, shorten the retention window, or turn capture off.</p>'
                    .'<p>A workspace owner can ask us to <strong>delete the entire workspace</strong>. We permanently remove it and everything belonging to it — users, time records, attendance, timesheets, activity samples, screenshots and their stored image files, machine inventory, and settings. This is irreversible, and once done we cannot restore the data for anyone.</p>'
                    .'<p>To request an export or a deletion, or to ask what is held about you, write to <a href="mailto:'.self::SUPPORT_EMAIL.'">'.self::SUPPORT_EMAIL.'</a>. We will ask for enough information to be sure the request is genuine, and we aim to complete it within 30 days.</p>'

                    .'<h2>8. If you are an employer using Staffvora</h2>'
                    .'<p><strong>Tell your team.</strong> Before enabling screenshots, activity or application tracking, tell the people affected what will be recorded, why, who can see it and how long it is kept. Monitoring rules differ sharply between countries, and in many places notice, a lawful basis, consultation or consent are legally required. Deciding what is lawful where your people work — and informing them — is your responsibility as their employer, not something the software can do for you. We have built Staffvora so that conversation is straightforward to have: capture is off until you turn it on, people can see their own data, and screenshot notifications can be enabled.</p>'

                    .'<h2>9. Security</h2>'
                    .'<p>Traffic runs over HTTPS, screenshots are stored privately and served only through authenticated, permission-checked requests, integration secrets are encrypted, and every query is scoped to a single workspace. Our <a href="/security">security page</a> sets out what we do — and what we have deliberately not claimed.</p>'

                    .'<h2>10. Children</h2>'
                    .'<p>Staffvora is a workplace tool. It is not intended for anyone under 16, and we do not knowingly create accounts for them.</p>'

                    .'<h2>11. Changes</h2>'
                    .'<p>We will update this page when the product changes, and we will change the "last updated" date above. If a change materially affects what is recorded about tracked people, we will tell workspace administrators.</p>'

                    .'<h2>12. Contact</h2>'
                    .'<p>Privacy questions, requests and complaints: <a href="mailto:'.self::SUPPORT_EMAIL.'">'.self::SUPPORT_EMAIL.'</a>. A person reads it.</p>',
                ]),
            ],
        ];
    }

    private function terms(): array
    {
        return [
            'title' => 'Terms of Service', 'sort' => 21, 'show_in_nav' => false,
            'meta_title' => 'Terms of Service — Staffvora',
            'meta_description' => 'The terms under which Staffvora is provided: accounts, acceptable use, monitoring responsibilities, billing, availability and termination.',
            'blocks' => [
                $this->b('hero', [
                    'variant' => 'center',
                    'heading' => 'Terms of Service',
                    'subhead' => 'Last updated: '.self::UPDATED.'. These terms apply to everyone who uses Staffvora.',
                ]),
                $this->b('rich_text', ['html' =>
                    '<h2>1. The agreement</h2>'
                    .'<p>These terms are between Staffvora ("we", "us") and the organisation that creates a workspace ("you", "the customer"). By creating a workspace, or by using one you have been invited to, you accept them. If you are agreeing on behalf of a company, you confirm you are allowed to bind it.</p>'

                    .'<h2>2. Your workspace and your people</h2>'
                    .'<p>You are responsible for your workspace: who you invite, what permissions you grant, what you switch on, and what your team does with the data. Keep sign-in credentials secure and tell us promptly if you think an account has been compromised. Accounts are for named individuals; do not share one between people.</p>'

                    .'<h2>3. Monitoring — your responsibility</h2>'
                    .'<p>Staffvora can capture screenshots, activity metrics, application and website-domain information, and — with the Agency toolkit — a machine inventory. <strong>You decide whether to enable any of it, and you are responsible for doing so lawfully.</strong> That includes informing the people affected, obtaining any notice, consent, works-council agreement or other legal basis required where they work, and honouring their rights under local law. You must not use Staffvora to monitor anyone who has not been told, to monitor personal devices outside working arrangements, or for any purpose prohibited by employment or privacy law in the relevant country. If you breach this section, you indemnify us against claims arising from it.</p>'

                    .'<h2>4. Acceptable use</h2>'
                    .'<p>Do not use Staffvora to break the law, infringe anyone\'s rights, or interfere with the service — no attempts to access another workspace\'s data, no probing or load-testing our systems without written permission, no reselling the service as your own without an agreement, and no automated scraping of the application. We may suspend a workspace that is causing harm or risk to other customers, and we will tell you why.</p>'

                    .'<h2>5. Plans, seats and billing</h2>'
                    .'<p>Plans are priced per seat, per month, and published on our <a href="/pricing">pricing page</a>. Paying yearly costs ten months instead of twelve. The Agency toolkit is a per-seat add-on that attaches to a paid plan. Enterprise pricing and yearly terms are agreed with you directly.</p>'
                    .'<p><strong>Self-serve card checkout is not live.</strong> Paid plans are activated manually: you send a bank transfer and email us the receipt, and we activate the plan — normally within one business day. Nothing is ever charged to a card automatically, and no subscription renews itself by taking your money without you sending it. When we introduce card payments, we will tell you before anything changes.</p>'
                    .'<p>Every new workspace begins on a 14-day trial of the Growth plan at no cost and with no card. When the trial ends, the workspace continues on the Free plan unless you have arranged a paid plan. Prices may change; we will give you at least 30 days\' notice before a change affects a paid period you have already bought.</p>'
                    .'<p>Amounts paid are for the period they cover. If you cancel mid-period, the workspace keeps its plan until the period ends, and we do not refund part-periods unless the law says otherwise or we have failed to provide the service.</p>'

                    .'<h2>6. Plan limits</h2>'
                    .'<p>Each plan includes a seat limit and a maximum history window. Reaching a limit may prevent you adding more seats, and data older than your retention window is deleted automatically. Choose a retention window that suits your record-keeping obligations — if a longer window matters to you, take a plan that allows it.</p>'

                    .'<h2>7. Your data</h2>'
                    .'<p>Your workspace data is yours. We store and process it to run the service, as described in our <a href="/privacy">privacy policy</a>. You can export reports at any time while your workspace is active. We do not sell your data or use it to train machine-learning models. If you ask us to delete your workspace, we permanently delete it and everything in it, and that cannot be undone.</p>'

                    .'<h2>8. Availability, support and the desktop tracker</h2>'
                    .'<p>We work hard to keep Staffvora available, but we do not currently offer a contractual uptime guarantee or service-level agreement, and there will be occasional maintenance. Support is by email at <a href="mailto:'.self::SUPPORT_EMAIL.'">'.self::SUPPORT_EMAIL.'</a> and through the request inbox inside the app.</p>'
                    .'<p>The desktop tracker is currently a <strong>preview build</strong> and is not yet code-signed or notarised. Install it only on machines you or your organisation control and are entitled to monitor. Never instruct anyone to disable security software in order to install it.</p>'

                    .'<h2>9. Changes to the service</h2>'
                    .'<p>We improve Staffvora continuously, and features may change. We will not remove a capability that is material to your plan without reasonable notice. Anything described on this website as coming later is not part of what you are buying today.</p>'

                    .'<h2>10. Termination</h2>'
                    .'<p>You may stop using Staffvora at any time and ask us to close your workspace. We may suspend or terminate a workspace that breaches these terms, does not pay for a plan it is using, or puts the service or other customers at risk — with notice, and with a chance to fix it where the situation allows. On termination, ask us for a deletion and we will carry it out.</p>'

                    .'<h2>11. Warranties and liability</h2>'
                    .'<p>Staffvora is provided "as is" and "as available". To the extent the law allows, we exclude implied warranties, and we are not liable for indirect or consequential loss, lost profits, or lost data beyond what we can restore. Our total liability in any twelve-month period is limited to the amount you paid us for the service in that period. Nothing here excludes liability that cannot lawfully be excluded.</p>'

                    .'<h2>12. Intellectual property</h2>'
                    .'<p>We own Staffvora, its software, design and brand. You own your data and your content. Nothing in these terms transfers ownership either way. If you send us feedback, we may use it to improve the product without owing you anything for it.</p>'

                    .'<h2>13. Changes to these terms</h2>'
                    .'<p>We may update these terms; the "last updated" date above will change and material changes will be notified to workspace administrators. Continuing to use Staffvora after a change means you accept the updated terms.</p>'

                    .'<h2>14. Governing law and contact</h2>'
                    .'<p>We operate from Pakistan. If your organisation requires a specific governing law, jurisdiction or a signed agreement — for example for a procurement or vendor review — contact us before you sign up and we will put it in writing rather than leave it to a website footer.</p>'
                    .'<p>Questions about these terms: <a href="mailto:'.self::SUPPORT_EMAIL.'">'.self::SUPPORT_EMAIL.'</a>.</p>',
                ]),
            ],
        ];
    }
}
