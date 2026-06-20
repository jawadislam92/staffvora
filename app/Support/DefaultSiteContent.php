<?php

namespace App\Support;

use App\Models\SitePage;
use App\Models\SiteTheme;

/**
 * Ships a great-looking default marketing site (light theme + the proven
 * homepage structure) so the public site is never empty and the owner has a
 * full page to edit in the builder from day one. Idempotent: only fills in
 * what's missing. Mirrors the Plan::ensureSeeded() pattern.
 */
class DefaultSiteContent
{
    public static function ensure(): void
    {
        self::ensureTheme();
        self::ensureHome();
    }

    private static function ensureTheme(): void
    {
        $theme = SiteTheme::current();

        // Only set chrome defaults once (don't clobber owner edits).
        if ($theme->nav_links === null) {
            $theme->update([
                'site_name' => $theme->site_name ?: 'Tracksuite',
                'tagline' => $theme->tagline ?: 'Time tracking for teams',
                'nav_links' => [
                    ['label' => 'Features', 'url' => '/#features'],
                    ['label' => 'Pricing', 'url' => '/#pricing'],
                    ['label' => 'Contact', 'url' => '/#contact'],
                ],
                'footer_columns' => [
                    ['title' => 'Product', 'links' => [
                        ['label' => 'Features', 'url' => '/#features'],
                        ['label' => 'Pricing', 'url' => '/#pricing'],
                        ['label' => 'Sign in', 'url' => '/login'],
                        ['label' => 'Create a workspace', 'url' => '/register'],
                    ]],
                    ['title' => 'Company', 'links' => [
                        ['label' => 'Contact', 'url' => '/#contact'],
                    ]],
                    ['title' => 'Legal', 'links' => [
                        ['label' => 'Privacy', 'url' => '/privacy'],
                        ['label' => 'Terms', 'url' => '/terms'],
                    ]],
                ],
                'social_links' => [
                    'linkedin' => '', 'facebook' => '', 'instagram' => '', 'x' => '', 'youtube' => '',
                ],
                'contact_email' => $theme->contact_email ?: 'hello@tracksuite.io',
            ]);

            SiteTheme::forgetCache();
        }
    }

    private static function ensureHome(): void
    {
        if (SitePage::where('slug', 'home')->exists()) {
            return;
        }

        $blocks = self::homeBlocks();

        SitePage::create([
            'slug' => 'home',
            'title' => 'Home',
            'meta_title' => 'Tracksuite — Time tracking that respects your team',
            'meta_description' => 'Time tracking, screenshots, attendance and reports in one place — from desktop tracker to Slack digest. Start free, no credit card.',
            'status' => 'published',
            'blocks' => $blocks,
            'published_blocks' => $blocks,
            'sort' => 0,
        ]);
    }

    /** The default Home composition — the editable starting point. */
    public static function homeBlocks(): array
    {
        $b = fn (string $type, array $data) => [
            'id' => 'b'.bin2hex(random_bytes(4)),
            'type' => $type,
            'data' => $data,
        ];

        return [
            $b('hero', [
                'eyebrow' => 'Time tracking that respects your team',
                'heading' => 'Every hour, tracked.',
                'heading_highlight' => 'Zero busywork.',
                'subhead' => 'Time tracking, screenshots, attendance and reports — connected in one place, from desktop tracker to Slack digest.',
                'primary_cta' => ['label' => 'Start free', 'url' => '/register'],
                'secondary_cta' => ['label' => 'Book a demo', 'url' => '/#contact'],
                'note' => 'No credit card required · 14-day Pro trial',
                'image_url' => null,
            ]),
            $b('logo_bar', [
                'caption' => 'Built for teams who bill by the hour',
                'logos' => [],
            ]),
            $b('feature_grid', [
                'anchor' => 'features',
                'heading' => 'Everything the team needs',
                'subhead' => 'From clock-in to client report, without leaving the flow.',
                'features' => [
                    ['icon' => 'clock', 'title' => 'One-click time tracking', 'text' => 'Pick a client, hit start. Idle time pauses itself, overnight shifts stay on the right day, and hours flow into reports automatically.'],
                    ['icon' => 'camera', 'title' => 'Screenshots & activity', 'text' => 'Periodic screenshots with live activity levels, securely stored in the cloud with automatic retention cleanup.'],
                    ['icon' => 'calendar', 'title' => 'Attendance & shifts', 'text' => 'Clock in/out with breaks, three-shift support around the clock, late-coming detection, and a monthly grid HR will love.'],
                    ['icon' => 'chart', 'title' => 'Reports & exports', 'text' => 'Work-hours reports per client, tracker and work type — filter anything, export CSV in one click.'],
                    ['icon' => 'slack', 'title' => 'Slack digests', 'text' => 'Daily activity digests and weekly summaries delivered straight to your Slack channels.'],
                    ['icon' => 'desktop', 'title' => 'Desktop apps', 'text' => 'Native Windows and macOS trackers with auto-updates, offline queueing and tray controls.'],
                ],
            ]),
            $b('feature_split', [
                'eyebrow' => 'Transparent by design',
                'heading' => 'Monitoring your team will actually accept',
                'text' => 'Screenshots and activity are visible to the people being tracked, attendance never brands someone absent for a day they hadn\'t started, and super-admins are exempt. Accountability without the surveillance vibe.',
                'bullets' => ['Employee-visible screenshots', 'Humane attendance — no false "absent"', 'Idle auto-pause', 'Per-workspace privacy controls'],
                'image_url' => null,
                'image_side' => 'right',
            ]),
            $b('stats', [
                'stats' => [
                    ['value' => '3 shifts', 'label' => 'covered around the clock'],
                    ['value' => '1-click', 'label' => 'CSV exports & Slack digests'],
                    ['value' => '100%', 'label' => 'of hours in one place'],
                ],
            ]),
            $b('steps', [
                'heading' => 'Up and running in minutes',
                'steps' => [
                    ['n' => '1', 'title' => 'Install the tracker', 'text' => 'Grab the desktop app for Windows or Mac from your dashboard.'],
                    ['n' => '2', 'title' => 'Track with one click', 'text' => 'Pick a client — or Office Work — and start. Everything else is automatic.'],
                    ['n' => '3', 'title' => 'Reports flow in', 'text' => 'Hours, screenshots, attendance and Slack digests, all without manual entry.'],
                ],
            ]),
            $b('testimonials', [
                'heading' => 'Teams get their hours back',
                'items' => [
                    ['quote' => 'Setup took minutes and our weekly client reports build themselves now.', 'name' => 'Your happy customer', 'role' => 'Operations Lead', 'company' => '', 'avatar_url' => null, 'rating' => 5],
                    ['quote' => 'Finally a tracker the team doesn\'t resent — they can see exactly what\'s captured.', 'name' => 'Add a real review', 'role' => 'Founder', 'company' => '', 'avatar_url' => null, 'rating' => 5],
                ],
            ]),
            $b('faq', [
                'heading' => 'Frequently asked questions',
                'items' => [
                    ['q' => 'Do I need a credit card to start?', 'a' => 'No. Create a workspace and you get a 14-day Pro trial with full features — no card required.'],
                    ['q' => 'Can employees see their own screenshots?', 'a' => 'Yes. Tracksuite is built to be transparent — the people being tracked can see what\'s captured.'],
                    ['q' => 'Which platforms does the desktop tracker support?', 'a' => 'Windows and macOS, with auto-updates and offline queueing.'],
                ],
            ]),
            $b('cta_band', [
                'anchor' => 'contact',
                'heading' => 'Start tracking in minutes',
                'text' => 'Create your workspace free and see your hours, screenshots and attendance in one place.',
                'primary_cta' => ['label' => 'Start free', 'url' => '/register'],
            ]),
        ];
    }
}
