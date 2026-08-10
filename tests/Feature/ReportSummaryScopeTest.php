<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\WorkCategory;
use App\Models\WorkHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * REGRESSION for audit customer-app W3 — the Work Hours Report's headline KPIs
 * (Total hours / People / Clients) and its trend chart were summed on the client
 * from the CURRENT PAGE of paginated rows, so they changed every time you paged
 * and a 90-day report could headline a single day's total next to an 811-page
 * pager.
 *
 * The fix computes them in the controller over the WHOLE filtered set — one
 * bounded aggregate over work_hours alone — and ships them as the `summary` and
 * `trend` props. This pins that they are (a) whole-set, (b) identical on every
 * page, (c) narrowed by the same filters as the table, and (d) count DISTINCT
 * clients without folding the client-less rows into a phantom client.
 *
 * Before the fix there is no `summary` prop at all, so every test here fails on
 * the first assertion.
 */
class ReportSummaryScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** @var array<int,User> */
    private array $workers = [];

    /** @var array<int,Client> */
    private array $clients = [];

    private WorkCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'super_admin', 'name' => 'Zoe Owner']);

        // Four people who log work; the admin logs none, so DISTINCT user_id
        // over work_hours must resolve to 4, never 5.
        foreach (['Ann', 'Ben', 'Cy', 'Di'] as $name) {
            $this->workers[] = User::factory()->create(['role' => 'member', 'name' => $name]);
        }

        foreach (['Acme', 'Bravo', 'Corp'] as $name) {
            $this->clients[] = Client::create(['name' => $name, 'is_active' => true]);
        }

        WorkCategory::ensureSeeded();
        $this->category = WorkCategory::query()->orderBy('id')->firstOrFail();
    }

    /**
     * 8 days × 4 people × 3 clients = 96 client-bearing groups at 1.5h each
     * (144h), plus two client-LESS entries at 2h on days already in the set
     * (+4h). Whole set: 148h, 4 people, 3 clients, 98 merged groups across 8
     * calendar days.
     */
    private function seedReport(): void
    {
        foreach (range(1, 8) as $day) {
            $date = sprintf('2026-07-%02d', $day);

            foreach ($this->workers as $worker) {
                foreach ($this->clients as $client) {
                    WorkHour::create([
                        'user_id' => $worker->id,
                        'date' => $date,
                        'hours' => 1.5,
                        'description' => 'Client work',
                        'work_type' => 'office_work',
                        'work_category_id' => $this->category->id,
                        'is_billable' => true,
                        'client_id' => $client->id,
                        'source' => 'manual',
                    ]);
                }
            }
        }

        // Client-less time for the first worker on two of those days. These must
        // count toward Total hours and People, but NOT invent a fourth client.
        foreach (['2026-07-01', '2026-07-02'] as $date) {
            WorkHour::create([
                'user_id' => $this->workers[0]->id,
                'date' => $date,
                'hours' => 2.0,
                'description' => 'Internal admin',
                'work_type' => 'office_work',
                'work_category_id' => $this->category->id,
                'is_billable' => false,
                'client_id' => null,
                'source' => 'manual',
            ]);
        }
    }

    /** @return array<string,mixed> the full props bag for /report. */
    private function fetch(array $query = []): array
    {
        $props = null;
        // filter=all so the 90-day default window can never truncate the July
        // fixture as the calendar moves.
        $query = array_merge(['filter' => 'all'], $query);

        $this->actingAs($this->admin)
            ->get(route('work-hours.report', $query))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        $this->assertIsArray($props);

        return $props;
    }

    public function test_the_headline_summary_covers_the_whole_filtered_set_not_the_page(): void
    {
        $this->seedReport();

        $page1 = $this->fetch(['perPage' => 15, 'page' => 1]);

        $this->assertArrayHasKey('summary', $page1, 'the report must ship a whole-set summary');
        $this->assertEqualsWithDelta(148.0, (float) $page1['summary']['total_hours'], 0.001, 'Total hours must be the whole filtered total');
        $this->assertSame(4, $page1['summary']['people'], 'People = DISTINCT user_id (the admin logs nothing)');
        $this->assertSame(3, $page1['summary']['clients'], 'Clients = DISTINCT client_id, excluding the client-less rows');

        // The page really is a small slice: 98 groups at 15/page is 7 pages.
        $this->assertSame(98, $page1['workHours']['total']);
        $this->assertCount(15, $page1['workHours']['data']);
    }

    public function test_the_summary_is_identical_on_every_page(): void
    {
        $this->seedReport();

        $page1 = $this->fetch(['perPage' => 15, 'page' => 1])['summary'];
        $page5 = $this->fetch(['perPage' => 15, 'page' => 5])['summary'];

        $this->assertSame($page1, $page5, 'the KPIs must not move as the user pages');
    }

    public function test_the_trend_spans_every_day_in_the_window_not_just_the_page(): void
    {
        $this->seedReport();

        $props = $this->fetch(['perPage' => 15, 'page' => 1]);

        $this->assertArrayHasKey('trend', $props);
        $this->assertCount(8, $props['trend'], 'one point per calendar day in the fixture, regardless of the 15-row page');

        $trendTotal = array_sum(array_map(fn ($p) => (float) $p['hours'], $props['trend']));
        $this->assertEqualsWithDelta(148.0, $trendTotal, 0.001, 'the daily trend must sum to the whole-set total');

        // First two days carry the +2h client-less entry; the rest are a flat 18h.
        $byDay = collect($props['trend'])->keyBy('day');
        $this->assertEqualsWithDelta(20.0, (float) $byDay['2026-07-01']['hours'], 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $byDay['2026-07-02']['hours'], 0.001);
        $this->assertEqualsWithDelta(18.0, (float) $byDay['2026-07-03']['hours'], 0.001);
    }

    public function test_the_summary_follows_the_filters(): void
    {
        $this->seedReport();

        // Just the first worker: 3 clients × 8 days × 1.5h = 36h, plus their two
        // 2h client-less entries = 40h. One person, three clients.
        $props = $this->fetch(['userIds' => [$this->workers[0]->id], 'perPage' => 15]);

        $this->assertEqualsWithDelta(40.0, (float) $props['summary']['total_hours'], 0.001);
        $this->assertSame(1, $props['summary']['people']);
        $this->assertSame(3, $props['summary']['clients']);
    }
}
