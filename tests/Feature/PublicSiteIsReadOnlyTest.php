<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\SitePage;
use App\Support\DefaultSiteContent;
use Database\Seeders\SiteContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * THE ANONYMOUS MARKETING SURFACE IS A READ.
 *
 * `DefaultSiteContent::ensure()` ran inside Site\PageController::home() and
 * `Plan::ensureSeeded()` inside its pricing payload, so a steady-state `GET /`
 * — the least protected page in the product — cost 26 queries and could WRITE.
 * That is a query amplifier anyone with curl can point at a shared VPS.
 *
 * Both moved to `php artisan site:sync`, a deploy step. These tests are the
 * inverse of the audit's measurement: the page must now do no writes and must
 * stay cheap, and the command must still produce a working site from nothing.
 */
class PublicSiteIsReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    /** What a deploy does, so the tests start where production starts. */
    private function deploy(): void
    {
        $this->artisan('site:sync')->assertSuccessful();
    }

    public function test_an_anonymous_page_view_never_writes(): void
    {
        $this->deploy();
        $this->get('/')->assertOk();  // warm every cache

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $this->get('/')->assertOk();
        $this->get('/pricing')->assertOk();
        $this->get('/definitely-not-a-page')->assertNotFound();

        $this->assertSame([], $writes, 'The public marketing surface performed a write.');
    }

    public function test_an_anonymous_home_view_is_cheap_in_steady_state(): void
    {
        $this->deploy();
        $this->get('/')->assertOk();  // warm every cache

        DB::enableQueryLog();
        $this->get('/')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Was 26. What remains is the live pricing/add-on catalogue, which the
        // page genuinely renders. If this ever climbs back, something is doing
        // per-request work that belongs in the deploy.
        $this->assertLessThanOrEqual(
            6,
            count($queries),
            'A steady-state anonymous homepage view got expensive again: '
                .implode(' | ', array_column($queries, 'query')),
        );
    }

    /** Flooding bogus URLs must not amplify into database work either. */
    public function test_an_anonymous_404_costs_at_most_one_lookup(): void
    {
        $this->deploy();
        $this->get('/')->assertOk();

        DB::enableQueryLog();
        $this->get('/no-such-page-'.bin2hex(random_bytes(4)))->assertNotFound();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(1, count($queries));
    }

    /**
     * The other half of the bargain: because the request path no longer seeds,
     * the deploy step MUST be able to stand a site up from an empty database,
     * and must be safe to run again on every subsequent deploy.
     */
    public function test_the_deploy_command_builds_a_working_site_from_an_empty_database(): void
    {
        $this->assertSame(0, SitePage::count(), 'a migrated database should carry no marketing pages');

        $this->deploy();

        $this->assertGreaterThan(0, Plan::count(), 'site:sync did not seed the plan catalogue.');
        $this->get('/')->assertOk();
        $this->get('/pricing')->assertOk();
        $this->get('/privacy')->assertOk();
    }

    public function test_the_deploy_command_is_idempotent_and_keeps_operator_edits(): void
    {
        $this->deploy();

        $page = SitePage::where('slug', 'about')->firstOrFail();
        $edited = [['id' => 'operator-1', 'type' => 'rich_text', 'data' => ['html' => '<p>Our own words.</p>']]];
        $page->update(['blocks' => $edited, 'published_blocks' => $edited]);

        $pagesBefore = SitePage::count();
        $plansBefore = Plan::count();

        $this->deploy();
        $this->deploy();

        $this->assertSame($pagesBefore, SitePage::count(), 'Re-running site:sync duplicated pages.');
        $this->assertSame($plansBefore, Plan::count(), 'Re-running site:sync duplicated plans.');
        $this->assertSame($edited, $page->fresh()->published_blocks, 'site:sync clobbered an operator edit.');
        $this->get('/')->assertOk();
    }

    /** --force is the documented way back to the shipped copy. */
    public function test_the_deploy_command_can_be_forced_back_to_the_shipped_copy(): void
    {
        $this->deploy();

        $page = SitePage::where('slug', 'about')->firstOrFail();
        $page->update(['blocks' => [], 'published_blocks' => []]);

        $this->artisan('site:sync', ['--force' => true])->assertSuccessful();

        $this->assertNotEmpty($page->fresh()->published_blocks);
        $this->assertFalse(SiteContentSeeder::$force, 'site:sync leaked its --force flag.');
    }

    /** The release guard that keeps placeholder legal copy offline still runs. */
    public function test_the_deploy_command_still_runs_the_placeholder_release_guard(): void
    {
        DefaultSiteContent::ensure();

        $page = SitePage::where('slug', 'terms')->firstOrFail();
        $placeholder = [['id' => 'x', 'type' => 'rich_text', 'data' => ['html' => '<p>[REPLACE: legal review]</p>']]];
        $page->update(['status' => 'published', 'blocks' => $placeholder, 'published_blocks' => $placeholder]);

        $this->artisan('site:sync')->assertSuccessful();

        // Either taken offline by the guard, or replaced with the real launch
        // copy by the seeder — never left live carrying a [REPLACE] marker.
        $live = (string) json_encode($page->fresh()->published_blocks);
        $this->assertStringNotContainsString('[REPLACE', $live);
    }
}
