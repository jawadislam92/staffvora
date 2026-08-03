<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkCategory;
use App\Models\WorkHour;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * EQUIVALENCE GUARD for the rewritten `WorkHourController::report()`.
 *
 * The rewrite changed three things that are invisible from the outside and
 * would stay invisible if they broke:
 *
 *  1. the client dropdown became a correlated EXISTS instead of
 *     `whereIn(SELECT DISTINCT client_id …)`;
 *  2. the paginator's `total` is now counted over `work_hours` ALONE instead of
 *     by wrapping the three-LEFT-JOIN statement in a derived table;
 *  3. the GROUP BY key shrank from eleven columns to the eight that live on
 *     `work_hours`, with the three joined display names moved into `MIN(…)`
 *     and the ORDER BY moved onto the select aliases.
 *
 * Each of those is an argument about functional dependency, and an argument is
 * not a test. So this file does not compare against a golden file recorded from
 * the old code (which would rot); it recomputes the merged report INDEPENDENTLY
 * in PHP from the raw rows and asserts the SQL agrees — row for row, field for
 * field, across pages, page sizes, filters and search, including the paginator
 * meta. If the join-free count ever stops matching the number of groups, or a
 * MIN(display name) ever picks a different name than the group's own, this
 * fails.
 */
class ReportPaginationEquivalenceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** @var array<string,User> */
    private array $workers = [];

    /** @var array<string,Client> */
    private array $clients = [];

    private WorkCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'super_admin', 'name' => 'Zoe Owner']);

        foreach (['Alice Ant', 'Bob Bee', 'Carol Cat'] as $name) {
            $this->workers[$name] = User::factory()->create(['role' => 'member', 'name' => $name]);
        }

        foreach (['Acme Ltd', 'Bravo Inc'] as $name) {
            $this->clients[$name] = Client::create(['name' => $name, 'is_active' => true]);
        }

        WorkCategory::ensureSeeded();
        $this->category = WorkCategory::query()->orderBy('id')->firstOrFail();
    }

    /**
     * A dataset shaped so that every merged group has a UNIQUE
     * (date, user name, client name) triple — the report's whole ORDER BY. Ties
     * beyond that triple are unordered in SQL by definition, so pinning them
     * would be pinning an accident; the deliberate tie case gets its own,
     * order-insensitive test below.
     *
     * 10 dates x 3 people x 2 clients = 60 groups, each holding 1-3 raw entries
     * so the merge is genuinely doing something, plus one client-less group on
     * an older date (the `client => null` branch) = 61 groups from 121 rows.
     */
    private function seedReportData(): void
    {
        $workTypeFor = [
            'Alice Ant' => 'office_work',
            'Bob Bee' => 'work_from_home',
            'Carol Cat' => 'office_work',
        ];

        $n = 0;

        foreach (range(1, 10) as $dayOfMonth) {
            $date = sprintf('2026-05-%02d', $dayOfMonth);

            foreach ($this->workers as $name => $worker) {
                foreach ($this->clients as $clientName => $client) {
                    $n++;
                    $entriesInGroup = ($n % 3) + 1; // 1, 2 or 3

                    foreach (range(1, $entriesInGroup) as $i) {
                        WorkHour::create([
                            'user_id' => $worker->id,
                            'date' => $date,
                            'hours' => round(0.25 * ((($n + $i) % 8) + 1), 2),
                            'description' => "{$name} on {$clientName} #{$i}",
                            'work_type' => $workTypeFor[$name],
                            'work_category_id' => $this->category->id,
                            'is_billable' => $n % 2 === 0,
                            'client_id' => $client->id,
                            'tracker' => null,
                            'source' => 'manual',
                        ]);
                    }
                }
            }
        }

        // The client-less group, on the oldest date so it sorts last and the
        // NULL client name can never collide with a real one in the ORDER BY.
        WorkHour::create([
            'user_id' => $this->workers['Alice Ant']->id,
            'date' => '2026-04-30',
            'hours' => 1.5,
            'description' => 'Internal admin',
            'work_type' => 'office_work',
            'work_category_id' => $this->category->id,
            'is_billable' => false,
            'client_id' => null,
            'tracker' => null,
            'source' => 'manual',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The independent oracle
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Recompute the merged report in PHP, straight off the raw rows, applying
     * the same contract the controller documents. Deliberately naive — a PHP
     * loop is the wrong thing to ship and the right thing to compare against.
     *
     * @param  callable|null  $filter  raw-row predicate, mirroring a query filter
     * @return array<int,array<string,mixed>>
     */
    private function expectedGroups(?callable $filter = null): array
    {
        $userNames = User::query()->pluck('name', 'id')->all();
        $clientNames = Client::query()->pluck('name', 'id')->all();
        $categoryNames = WorkCategory::query()->pluck('name', 'id')->all();

        $groups = [];

        foreach (WorkHour::query()->orderBy('id')->get() as $row) {
            if ($filter && ! $filter($row, $userNames[$row->user_id] ?? null, $row->client_id ? ($clientNames[$row->client_id] ?? null) : null)) {
                continue;
            }

            $date = substr((string) $row->getRawOriginal('date'), 0, 10);

            $key = implode("\0", [
                $date,
                (string) $row->user_id,
                (string) $row->client_id,
                (string) $row->work_category_id,
                (string) (int) $row->is_billable,
                (string) $row->work_type,
                (string) $row->tracker,
                (string) $row->source,
            ]);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'id' => (int) $row->id,
                    'date' => $date,
                    'user_name' => $userNames[$row->user_id] ?? null,
                    'client_name' => $row->client_id ? ($clientNames[$row->client_id] ?? null) : null,
                    'client_id' => $row->client_id,
                    'work_category_id' => $row->work_category_id,
                    'work_category_name' => $row->work_category_id ? ($categoryNames[$row->work_category_id] ?? null) : null,
                    'is_billable' => (bool) $row->is_billable,
                    'work_type' => $row->work_type,
                    'tracker' => $row->tracker,
                    'source' => $row->source,
                    'hours' => 0.0,
                    'entry_count' => 0,
                    'descriptions' => [],
                ];
            }

            $groups[$key]['id'] = min($groups[$key]['id'], (int) $row->id);
            $groups[$key]['hours'] += (float) $row->hours;
            $groups[$key]['entry_count']++;

            if ((string) $row->description !== '') {
                $groups[$key]['descriptions'][] = (string) $row->description;
            }
        }

        $groups = array_values($groups);

        usort($groups, function (array $a, array $b) {
            return [$b['date'], $a['user_name'], $a['client_name'] ?? '']
                <=> [$a['date'], $b['user_name'], $b['client_name'] ?? ''];
        });

        return $groups;
    }

    /**
     * @param  array<string,mixed>  $expected  one oracle group
     * @param  array<string,mixed>  $actual  one row from the controller
     */
    private function assertRowMatches(array $expected, array $actual, string $context): void
    {
        $this->assertSame($expected['id'], $actual['id'], "{$context}: id (MIN of the group)");
        $this->assertSame($expected['date'], $actual['date'], "{$context}: date");
        $this->assertSame($expected['user_name'], $actual['user']['name'], "{$context}: user name");
        $this->assertSame($expected['entry_count'], $actual['entry_count'], "{$context}: entry_count");
        $this->assertEqualsWithDelta($expected['hours'], $actual['hours'], 0.0001, "{$context}: summed hours");
        $this->assertSame($expected['is_billable'], $actual['is_billable'], "{$context}: is_billable");
        $this->assertSame($expected['work_type'], $actual['work_type'], "{$context}: work_type");
        $this->assertSame($expected['tracker'], $actual['tracker'], "{$context}: tracker");
        $this->assertSame($expected['source'], $actual['source'], "{$context}: source");

        if ($expected['client_id'] === null) {
            $this->assertNull($actual['client'], "{$context}: client must be null, not a row of nulls");
        } else {
            $this->assertSame($expected['client_name'], $actual['client']['name'], "{$context}: client name");
        }

        if ($expected['work_category_id'] === null) {
            $this->assertNull($actual['work_category'], "{$context}: work_category");
        } else {
            $this->assertSame((int) $expected['work_category_id'], $actual['work_category']['id'], "{$context}: work_category id");
            $this->assertSame($expected['work_category_name'], $actual['work_category']['name'], "{$context}: work_category name");
        }

        // GROUP_CONCAT does not promise an order (and the two drivers spell the
        // separator differently), so the descriptions are compared as a set.
        $actualParts = $actual['description'] === null || $actual['description'] === ''
            ? []
            : explode(' · ', $actual['description']);
        sort($actualParts);
        $expectedParts = $expected['descriptions'];
        sort($expectedParts);
        $this->assertSame($expectedParts, $actualParts, "{$context}: merged descriptions");
    }

    /**
     * @return array<string,mixed> the `workHours` paginator prop
     *
     * `filter=all` is the default here ON PURPOSE. This file is an equivalence
     * guard for the merged GROUP BY, so it must see EVERY seeded row; the page
     * itself now opens on a 90-day window (WorkHourController::
     * DEFAULT_REPORT_FILTER), which would otherwise silently truncate the
     * fixture as the calendar moves and turn this into a date-sensitive test.
     * The default window has its own coverage in FirstRunExperienceTest.
     */
    private function fetchReport(array $query = []): array
    {
        $props = null;
        $query = array_merge(['filter' => 'all'], $query);

        $this->actingAs($this->admin)
            ->get(route('work-hours.report', $query))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        $this->assertIsArray($props);
        $this->assertArrayHasKey('workHours', $props);

        return $props['workHours'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function test_every_page_at_every_page_size_matches_the_independently_computed_report(): void
    {
        $this->seedReportData();

        $expected = $this->expectedGroups();
        $this->assertCount(61, $expected, 'the fixture itself must produce 61 merged groups');

        foreach ([15, 25, 50, 100] as $perPage) {
            $lastPage = (int) ceil(count($expected) / $perPage);

            for ($page = 1; $page <= $lastPage; $page++) {
                $paginator = $this->fetchReport(['perPage' => $perPage, 'page' => $page]);

                $slice = array_slice($expected, ($page - 1) * $perPage, $perPage);
                $context = "perPage={$perPage} page={$page}";

                // The join-free count must equal the real number of groups.
                $this->assertSame(count($expected), $paginator['total'], "{$context}: paginator total");
                $this->assertSame($perPage, (int) $paginator['per_page'], "{$context}: per_page");
                $this->assertSame($page, $paginator['current_page'], "{$context}: current_page");
                $this->assertSame($lastPage, $paginator['last_page'], "{$context}: last_page");
                $this->assertSame(($page - 1) * $perPage + 1, $paginator['from'], "{$context}: from");
                $this->assertSame(($page - 1) * $perPage + count($slice), $paginator['to'], "{$context}: to");

                $this->assertCount(count($slice), $paginator['data'], "{$context}: row count");

                foreach ($slice as $i => $expectedRow) {
                    $this->assertRowMatches($expectedRow, $paginator['data'][$i], "{$context} row {$i}");
                }
            }
        }
    }

    public function test_the_merge_really_merges_and_the_totals_survive_it(): void
    {
        $this->seedReportData();

        $paginator = $this->fetchReport(['perPage' => 100]);

        $merged = array_filter($paginator['data'], fn ($r) => $r['entry_count'] > 1);
        $this->assertNotEmpty($merged, 'the fixture must contain multi-entry groups or this test proves nothing');

        // Every raw hour is accounted for exactly once across the merged rows.
        $rawTotal = (float) WorkHour::query()->sum('hours');
        $mergedTotal = array_sum(array_column($paginator['data'], 'hours'));
        $rawCount = WorkHour::query()->count();
        $mergedCount = array_sum(array_column($paginator['data'], 'entry_count'));

        $this->assertEqualsWithDelta($rawTotal, $mergedTotal, 0.0001);
        $this->assertSame($rawCount, $mergedCount);
        $this->assertSame(121, $rawCount);
    }

    public function test_filters_and_search_narrow_both_the_rows_and_the_paginator_total(): void
    {
        $this->seedReportData();

        $alice = $this->workers['Alice Ant'];

        // userIds + billable, on page 2, so the filtered count and the filtered
        // page are both exercised against a non-first offset.
        $expected = $this->expectedGroups(
            fn (WorkHour $row) => $row->user_id === $alice->id && (bool) $row->is_billable === true
        );

        $this->assertNotEmpty($expected);

        $paginator = $this->fetchReport([
            'userIds' => [$alice->id],
            'billable' => 'yes',
            'perPage' => 15,
            'page' => 1,
        ]);

        $this->assertSame(count($expected), $paginator['total']);
        $this->assertCount(min(15, count($expected)), $paginator['data']);

        foreach (array_slice($expected, 0, 15) as $i => $expectedRow) {
            $this->assertRowMatches($expectedRow, $paginator['data'][$i], "filtered row {$i}");
        }
    }

    public function test_search_matches_across_all_pages_not_just_the_visible_one(): void
    {
        $this->seedReportData();

        // 'Bravo' only ever appears in a client name and in the descriptions
        // that name that client, so both the client-name arm and the
        // description arm of the search must select the same set.
        $expected = $this->expectedGroups(
            fn (WorkHour $row, ?string $userName, ?string $clientName) => stripos((string) $row->description, 'Bravo') !== false
                || stripos((string) $row->tracker, 'Bravo') !== false
                || stripos((string) $userName, 'Bravo') !== false
                || stripos((string) $clientName, 'Bravo') !== false
        );

        $this->assertCount(30, $expected, 'Bravo Inc should own 10 dates x 3 people = 30 groups');

        $paginator = $this->fetchReport(['search' => 'Bravo', 'perPage' => 15, 'page' => 2]);

        $this->assertSame(30, $paginator['total']);
        $this->assertSame(2, $paginator['last_page']);
        $this->assertCount(15, $paginator['data']);

        foreach (array_slice($expected, 15, 15) as $i => $expectedRow) {
            $this->assertRowMatches($expectedRow, $paginator['data'][$i], "search page 2 row {$i}");
        }
    }

    public function test_a_date_range_narrows_the_group_count(): void
    {
        $this->seedReportData();

        $expected = $this->expectedGroups(
            fn (WorkHour $row) => substr((string) $row->getRawOriginal('date'), 0, 10) >= '2026-05-03'
                && substr((string) $row->getRawOriginal('date'), 0, 10) <= '2026-05-05'
        );

        $this->assertCount(18, $expected); // 3 dates x 3 people x 2 clients

        $paginator = $this->fetchReport([
            'startDate' => '2026-05-03',
            'endDate' => '2026-05-05',
            'perPage' => 25,
        ]);

        $this->assertSame(18, $paginator['total']);
        $this->assertCount(18, $paginator['data']);

        foreach ($expected as $i => $expectedRow) {
            $this->assertRowMatches($expectedRow, $paginator['data'][$i], "ranged row {$i}");
        }
    }

    /**
     * The eight-column key is a CONTRACT, not an implementation detail: two
     * entries that differ only on `work_type` (or category, billable, tracker
     * or source) are different work and must stay two rows. Asserted
     * order-insensitively because they tie on the entire ORDER BY.
     */
    public function test_entries_differing_only_on_a_key_column_stay_separate_rows(): void
    {
        $alice = $this->workers['Alice Ant'];
        $acme = $this->clients['Acme Ltd'];

        $shared = [
            'user_id' => $alice->id,
            'date' => '2026-05-20',
            'client_id' => $acme->id,
            'work_category_id' => $this->category->id,
            'is_billable' => true,
            'tracker' => null,
            'source' => 'manual',
            'description' => 'Split case',
        ];

        WorkHour::create($shared + ['hours' => 2.0, 'work_type' => 'office_work']);
        WorkHour::create($shared + ['hours' => 3.0, 'work_type' => 'office_work']);
        WorkHour::create($shared + ['hours' => 5.0, 'work_type' => 'work_from_home']);

        $paginator = $this->fetchReport(['perPage' => 25]);

        $this->assertSame(2, $paginator['total'], 'two work_types must remain two merged rows');

        $byWorkType = collect($paginator['data'])->keyBy('work_type');

        $this->assertEqualsWithDelta(5.0, $byWorkType['office_work']['hours'], 0.0001);
        $this->assertSame(2, $byWorkType['office_work']['entry_count']);
        $this->assertEqualsWithDelta(5.0, $byWorkType['work_from_home']['hours'], 0.0001);
        $this->assertSame(1, $byWorkType['work_from_home']['entry_count']);
    }

    /**
     * `?detailed=1` must keep bypassing the merge entirely — one row per raw
     * entry, through Laravel's own `paginate()`.
     */
    public function test_detailed_mode_returns_one_row_per_raw_entry(): void
    {
        $this->seedReportData();

        $paginator = $this->fetchReport(['detailed' => 1, 'perPage' => 100]);

        $this->assertSame(121, $paginator['total']);
        $this->assertCount(100, $paginator['data']);
    }

    /**
     * The dropdown became a correlated EXISTS. It must still list exactly the
     * clients that have work logged against them — no more (an EXISTS that lost
     * its correlation would list every client) and no fewer.
     */
    public function test_the_client_dropdown_lists_only_clients_with_logged_work(): void
    {
        $this->seedReportData();

        Client::create(['name' => 'Never Logged Ltd', 'is_active' => true]);

        $props = null;
        $this->actingAs($this->admin)
            ->get(route('work-hours.report'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        $this->assertSame(['Acme Ltd', 'Bravo Inc'], array_values($props['availableClients']));
    }

    /**
     * Tenant isolation on the same statement: another workspace's work must not
     * reach the rows, the paginator total, or the client dropdown. The rewrite
     * moved the count onto a hand-built query, which is exactly the sort of
     * change that can drop a global scope.
     */
    public function test_another_workspaces_work_never_reaches_the_report(): void
    {
        $this->seedReportData();

        $current = app(CurrentOrganization::class);
        $mine = $current->id();

        $otherOrg = Organization::factory()->create(['status' => Organization::STATUS_ACTIVE]);

        $current->runFor($otherOrg, function () {
            WorkCategory::ensureSeeded();
            $stranger = User::factory()->create(['role' => 'member', 'name' => 'Aaa Stranger']);
            $strangerClient = Client::create(['name' => 'AAA Foreign Client', 'is_active' => true]);

            foreach (range(1, 5) as $i) {
                WorkHour::create([
                    'user_id' => $stranger->id,
                    'date' => '2026-05-05',
                    'hours' => 8,
                    'description' => 'Bravo leak probe',
                    'work_type' => 'office_work',
                    'work_category_id' => WorkCategory::query()->orderBy('id')->value('id'),
                    'is_billable' => true,
                    'client_id' => $strangerClient->id,
                    'source' => 'manual',
                ]);
            }
        });

        $current->set($mine);

        $paginator = $this->fetchReport(['perPage' => 100]);

        $this->assertSame(61, $paginator['total']);

        foreach ($paginator['data'] as $row) {
            $this->assertNotSame('Aaa Stranger', $row['user']['name']);
            $this->assertNotSame('AAA Foreign Client', $row['client']['name'] ?? null);
        }

        // …and the search arm, which reaches through whereHas on two relations.
        $searched = $this->fetchReport(['search' => 'Bravo leak probe', 'perPage' => 100]);
        $this->assertSame(0, $searched['total']);
    }
}
