<?php

namespace App\Support;

use App\Models\BlogPost;

/**
 * Launch set of SEO blog posts for the marketing site — keyword-targeted content
 * mapped to the product (time tracking, attendance, screenshots) and the audience
 * pages (agencies, BPOs). Idempotent: each post is firstOrCreate'd by slug, so a
 * real edit made later in the builder is never overwritten. Brand name is
 * interpolated from config at seed time; the owner can re-word any post no-code.
 */
class BlogSeedContent
{
    public static function ensure(): void
    {
        if (! config('features.blog', false)) {
            return;
        }

        $team = config('brand.name', 'Tracksuite').' Team';

        foreach (self::posts() as $p) {
            BlogPost::firstOrCreate(['slug' => $p['slug']], [
                'title' => $p['title'],
                'excerpt' => $p['excerpt'],
                'category' => $p['category'],
                'author_name' => $team,
                'status' => 'published',
                'published_at' => now()->subDays($p['days_ago'] ?? 0),
                'meta_title' => $p['title'],
                'meta_description' => $p['meta'] ?? $p['excerpt'],
                'body' => $p['body'],
            ]);
        }
    }

    private static function posts(): array
    {
        $b = config('brand.name', 'Tracksuite');

        return [
            [
                'slug' => 'measure-the-work-not-the-worker',
                'title' => 'Why we measure the work, not the worker',
                'category' => 'Perspective',
                'days_ago' => 0,
                'excerpt' => 'Time tracking earned a bad reputation. Here’s how we think about visibility that respects your team — and still gives you the numbers you need to run a business.',
                'meta' => 'Our take on humane time tracking: accountability without surveillance, and why employee-visible data builds more trust than secret monitoring.',
                'body' => '<p>Most time trackers were built for one job: to watch people. Screenshots every few seconds, secret activity scores, a red flag the moment someone steps away. It "works" — and it quietly tells your team you don’t trust them.</p>'
                    .'<h2>Visibility, not surveillance</h2>'
                    .'<p>We started '.$b.' from a simple belief: a manager needs to understand the <strong>work</strong>, not police the <strong>worker</strong>. You need to know what got done, where time went, and whether a project is on track. You don’t need to know that someone took a ten-minute coffee break.</p>'
                    .'<p>So everything we show a manager, the employee can see too. Screenshots are opt-in and visible to the person being tracked. Attendance never brands someone "absent" for a day they hadn’t started. Admins are exempt from monitoring by default.</p>'
                    .'<h2>Why transparency wins</h2>'
                    .'<ul><li><strong>Trust compounds.</strong> Teams that know exactly what’s measured stop gaming the metric and start doing the work.</li>'
                    .'<li><strong>The data is honest.</strong> When people aren’t hiding from the tracker, your reports reflect reality.</li>'
                    .'<li><strong>It scales.</strong> Clear, fair rules beat a manager eyeballing screenshots all day.</li></ul>'
                    .'<h2>You still get the numbers</h2>'
                    .'<p>Humane doesn’t mean fuzzy. You get accurate hours, attendance and shift coverage, project and client breakdowns, and exports your finance team will accept. The difference is <em>how</em> you get them — out in the open, with your team’s buy-in.</p>'
                    .'<p>That’s the whole idea: measure the work, not the worker. <a href="/register">Start free</a> and see what honest tracking feels like.</p>',
            ],
            [
                'slug' => 'time-tracking-for-remote-teams',
                'title' => 'Time tracking for remote teams: a practical guide',
                'category' => 'Guides',
                'days_ago' => 2,
                'excerpt' => 'Remote work runs on trust and clarity. Here’s how to track time across a distributed team in a way that improves focus instead of killing morale.',
                'meta' => 'A practical guide to time tracking for remote teams: what to track, how to roll it out, the mistakes to avoid, and how to keep morale high.',
                'body' => '<p>When your team is remote, you lose the easy signals of an office — who’s at their desk, who’s heads-down, who’s blocked. Time tracking replaces those signals with data. Done badly, it feels like spyware. Done well, it gives everyone a shared, honest picture of where the work is going.</p>'
                    .'<h2>What to track (and what not to)</h2>'
                    .'<p>Track the things that help you run projects and bill accurately:</p>'
                    .'<ul><li>Hours worked, by project and client</li><li>Attendance and shift coverage across time zones</li><li>What was worked on (tasks, not keystrokes)</li></ul>'
                    .'<p>Skip the things that only create anxiety: mouse-movement scores, constant covert screenshots, and "idle shaming." They damage trust and rarely change outcomes.</p>'
                    .'<h2>Roll it out without killing morale</h2>'
                    .'<ol><li><strong>Explain the why.</strong> Tell your team it’s about fair workloads, accurate billing and protecting their off-hours — not surveillance.</li>'
                    .'<li><strong>Make it transparent.</strong> Choose a tool where employees can see exactly what’s recorded about them.</li>'
                    .'<li><strong>Start light.</strong> Begin with hours and attendance; add activity features later, only where they earn their keep.</li></ol>'
                    .'<h2>Common mistakes</h2>'
                    .'<ul><li>Tracking everyone the same way regardless of role.</li>'
                    .'<li>Using the data to punish instead of to plan.</li>'
                    .'<li>Ignoring time zones, so attendance looks wrong for half the team.</li></ul>'
                    .'<h2>The payoff</h2>'
                    .'<p>A well-run system gives managers clarity and gives employees cover — proof of the hours they put in, and protection from always-on expectations. See how '.$b.' approaches it on our <a href="/features">features page</a>, or <a href="/register">start free</a> with your team today.</p>',
            ],
            [
                'slug' => 'track-employee-attendance-accurately',
                'title' => 'How to track employee attendance accurately and fairly',
                'category' => 'Guides',
                'days_ago' => 4,
                'excerpt' => 'Attendance sounds simple until shifts, time zones and "I started late but worked late" enter the picture. Here’s how to get it right without unfair flags.',
                'meta' => 'How to track employee attendance accurately: manual vs automatic, shifts and time zones, avoiding false "absent" flags, and clean reporting.',
                'body' => '<p>Attendance is the most-watched and most-disputed number in any team. Get it wrong and you’ll spend your week arguing about who was "really" late. Get it right and it quietly disappears into the background — which is exactly where it belongs.</p>'
                    .'<h2>Why attendance breaks down</h2>'
                    .'<p>Most disputes come from rigid rules meeting messy reality: a developer who starts at 11am but ships until 8pm, a support agent in a different time zone, someone who was onboarding and hadn’t started tracking yet. A fair system accounts for all of it.</p>'
                    .'<h2>Build it on shifts, not gut feel</h2>'
                    .'<ul><li>Define a shift length per person or team, so "on time" means something concrete.</li>'
                    .'<li>Store everything in the organisation’s time zone, then display it correctly for each person.</li>'
                    .'<li>Never mark someone absent for a day before their tracking start date — that’s the fastest way to lose trust.</li></ul>'
                    .'<h2>Manual vs automatic</h2>'
                    .'<p>Automatic clock-in from a desktop app removes friction, but a good system also lets people log time manually for offline or off-app work — as a start–end range, not just a number — so the record stays complete and honest.</p>'
                    .'<h2>Make the reports do the work</h2>'
                    .'<p>The goal is a single view of who worked, when, and for how long, with clean exports for payroll. When the data is trusted, attendance stops being a fight. '.$b.' was built around this fairness-first model — it’s a great fit for <a href="/solutions/bpos">BPOs and call centers</a> running shifts at scale. <a href="/register">Try it free</a>.</p>',
            ],
            [
                'slug' => 'track-billable-hours-agencies',
                'title' => 'How agencies track billable hours (and stop leaking profit)',
                'category' => 'Agencies',
                'days_ago' => 6,
                'excerpt' => 'For agencies, untracked time is unbilled revenue. Here’s how to capture billable hours by client and turn them into invoices you can defend.',
                'meta' => 'How agencies track billable hours by client and project, improve utilization, and turn accurate time data into defensible invoices.',
                'body' => '<p>If you bill by the hour, every minute that doesn’t make it into a timesheet is money you’ve already spent but will never invoice. For most agencies, that leak is bigger than they think — and it hides in "I’ll log it later" and end-of-month guesswork.</p>'
                    .'<h2>Capture time where the work happens</h2>'
                    .'<p>The fix isn’t nagging people to fill timesheets on Friday. It’s capturing time as the work happens, tagged to the right client and project, so the record is accurate by default rather than reconstructed from memory.</p>'
                    .'<ul><li>Track at the client and project level, not just "work."</li>'
                    .'<li>Let people log offline time as a clean start–end range.</li>'
                    .'<li>Keep an audit trail so a billed hour can always be explained.</li></ul>'
                    .'<h2>Turn hours into invoices</h2>'
                    .'<p>Once time is clean, invoicing becomes a report, not a negotiation. You can show a client exactly what was done and when — which shortens payment cycles and reduces disputes.</p>'
                    .'<h2>Watch utilization, not just hours</h2>'
                    .'<p>Billable hours tell you revenue; <strong>utilization</strong> (billable vs total hours) tells you health. If utilization is low, you’re either over-staffed or losing time to unbilled work. Tracking both turns time data into a planning tool, not just a billing one.</p>'
                    .'<p>See how '.$b.' fits agency workflows on our <a href="/solutions/agencies">agencies page</a>, or compare plans on <a href="/pricing">pricing</a>.</p>',
            ],
            [
                'slug' => 'employee-screenshots-ethical-monitoring',
                'title' => 'Are employee screenshots ethical? Activity monitoring done right',
                'category' => 'Perspective',
                'days_ago' => 8,
                'excerpt' => 'Screenshots and activity tracking can build trust or destroy it. The difference is consent and transparency. Here’s how to monitor without crossing the line.',
                'meta' => 'Are employee screenshots ethical? A practical guide to transparent, consent-based activity monitoring that protects both the business and the team.',
                'body' => '<p>"Employee monitoring" is one of the most loaded phrases in modern work. Done in secret, it’s corrosive — people feel watched, trust evaporates, and your best performers quietly leave. Done openly, the same features can be a fair, useful record of work. The technology isn’t the problem; the consent is.</p>'
                    .'<h2>The test: would your team be surprised?</h2>'
                    .'<p>A simple rule: if showing an employee exactly what you collect would embarrass you, you’re collecting the wrong way. Ethical monitoring is monitoring your team already knows about and can see for themselves.</p>'
                    .'<h2>Principles that keep you on the right side</h2>'
                    .'<ul><li><strong>Opt-in, not covert.</strong> People know when activity or screenshots are on.</li>'
                    .'<li><strong>Employee-visible.</strong> Whatever a manager sees, the employee can see too.</li>'
                    .'<li><strong>Purpose-limited.</strong> Collect what helps run the work, nothing more — no keylogging.</li>'
                    .'<li><strong>Respect off-hours.</strong> Tracking stops when the shift does.</li></ul>'
                    .'<h2>Write it down</h2>'
                    .'<p>Put your monitoring policy in writing and share it. A one-page policy that explains what’s collected, why, and who can see it does more for trust than any feature toggle.</p>'
                    .'<p>This is the model '.$b.' is built on — read more on our <a href="/security">security and privacy page</a>, or <a href="/register">start free</a> and configure it for your team.</p>',
            ],
            [
                'slug' => 'time-tracking-bpos-call-centers',
                'title' => 'Time tracking for BPOs and call centers: shifts, attendance & SLAs',
                'category' => 'BPOs',
                'days_ago' => 10,
                'excerpt' => 'BPOs live and die by shift coverage and SLAs. Here’s how to track time and attendance at scale without drowning team leads in spreadsheets.',
                'meta' => 'Time tracking for BPOs and call centers: managing shift coverage, attendance at scale, productivity, and client-ready reporting.',
                'body' => '<p>A BPO is a coverage business. If seats aren’t filled when client volume hits, SLAs slip and penalties follow. That makes time and attendance not just an HR task but an operational one — and at a few hundred agents across rotating shifts, spreadsheets stop working fast.</p>'
                    .'<h2>Shift coverage is the metric that matters</h2>'
                    .'<p>For most BPOs, the headline question isn’t "how many hours" but "were the right seats covered at the right times?" You need per-person shift lengths, clear on-time rules, and a live view of who’s in.</p>'
                    .'<h2>Attendance at scale</h2>'
                    .'<ul><li>Automate clock-in so team leads aren’t chasing people.</li>'
                    .'<li>Handle multiple time zones and overnight shifts that roll past midnight.</li>'
                    .'<li>Bulk-manage shifts, designations and teams instead of editing one agent at a time.</li></ul>'
                    .'<h2>Productivity without micromanaging</h2>'
                    .'<p>Activity and screenshots can help on a floor of hundreds — but keep them transparent and employee-visible, or you’ll trade short-term visibility for long-term churn in an industry that already fights attrition.</p>'
                    .'<h2>Reporting your clients will accept</h2>'
                    .'<p>Finally, the data has to leave the building: clean attendance and productivity reports, exportable, that a client can audit. That’s the difference between "trust us" and "here’s the record."</p>'
                    .'<p>See how '.$b.' handles shift-based teams on our <a href="/solutions/bpos">BPOs &amp; call centers page</a>, or <a href="/register">start free</a>.</p>',
            ],
        ];
    }
}
