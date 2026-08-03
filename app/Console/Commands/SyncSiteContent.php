<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\SiteTheme;
use App\Support\DefaultSiteContent;
use App\Support\SiteNavigation;
use Database\Seeders\SiteContentSeeder;
use Illuminate\Console\Command;

/**
 * Seeds/refreshes everything the PUBLIC marketing surface needs to render, as a
 * DEPLOY STEP rather than as a side effect of somebody visiting the site.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS COMMAND EXISTS
 * ─────────────────────────────────────────────────────────────────────────────
 * `DefaultSiteContent::ensure()` used to run inside Site\PageController::home()
 * and `Plan::ensureSeeded()` inside its pricing payload. That put WRITE logic on
 * the most exposed, least protected, entirely anonymous read path in the
 * product: a steady-state `GET /` cost 26 queries — thirteen site_pages
 * lookups, six blog_posts lookups and four plans lookups — none of which the
 * page needs in order to read one already-published row. Anyone with curl could
 * amplify a single HTTP request into that much database work, on a shared VPS
 * that also hosts the owner's other live businesses.
 *
 * So the seeding moved here. The request path is now read-only, and a fresh
 * install gets its content from the deploy, exactly like migrations do:
 *
 *     php artisan migrate --force
 *     php artisan site:sync          ← this command
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * IDEMPOTENCE — SAFE TO RE-RUN ON EVERY DEPLOY
 * ─────────────────────────────────────────────────────────────────────────────
 * Every step below is a no-op once it has run:
 *   - Plan::ensureSeeded()        firstOrCreate per plan key
 *   - DefaultSiteContent::ensure() creates only pages that are missing, and
 *     un-publishes any page whose LIVE snapshot still carries a [REPLACE]
 *     marker (the release guard that keeps placeholder legal copy off the web)
 *   - SiteContentSeeder            rewrites a page only when it is missing,
 *     unpublished, empty, or still carrying scaffold copy — so an operator's
 *     Site Builder edits survive a re-run. `--force` overrides that, and is the
 *     documented way to restore the shipped copy.
 *
 * It deliberately does NOT touch tenant data. Nothing here is org-scoped.
 */
class SyncSiteContent extends Command
{
    protected $signature = 'site:sync
        {--force : Overwrite operator edits with the shipped launch copy}';

    protected $description = 'Seed/refresh the plan catalogue and the public marketing site (deploy step; safe to re-run).';

    public function handle(): int
    {
        // 1. The plan catalogue. The public pricing block reads it, and signup
        //    picks the operator's default-trial plan out of it.
        Plan::ensureSeeded();
        $this->line('Plans: catalogue ensured ('.Plan::count().' plan(s)).');

        // 2. The page scaffold + the release guard, then the finished launch
        //    copy on top. Scaffold first: the seeder overwrites any page still
        //    carrying [REPLACE] copy, so this order ends with real content.
        DefaultSiteContent::ensure();
        $this->line('Site: default pages + theme ensured.');

        $seeder = new SiteContentSeeder;
        $seeder->setContainer($this->laravel);
        $seeder->setCommand($this);

        // `SiteContentSeeder::$force` is a static override the seeder itself
        // reads; restore it afterwards so a queued/second run in the same
        // process does not inherit a --force from this one.
        $previousForce = SiteContentSeeder::$force;
        SiteContentSeeder::$force = (bool) $this->option('force');

        try {
            $seeder->run();
        } finally {
            SiteContentSeeder::$force = $previousForce;
        }

        // 3. Anything cached off the content we just wrote.
        SiteTheme::forgetCache();
        SiteNavigation::forgetCache();
        $this->line('Caches: site theme + navigation flushed.');

        $this->info('site:sync complete.');

        return self::SUCCESS;
    }
}
