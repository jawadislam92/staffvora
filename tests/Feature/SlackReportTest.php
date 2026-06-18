<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\WorkHour;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SlackReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.slack_reports.webhook_url', 'https://hooks.slack.test/services/example');
        config()->set('services.slack_reports.timezone', 'Asia/Karachi');
    }

    public function test_super_admin_can_send_a_selected_date_range_to_slack(): void
    {
        Http::fake(['hooks.slack.test/*' => Http::response('ok')]);

        $admin = User::factory()->create(['role' => 'super_admin']);
        $member = User::factory()->create(['name' => 'Test Member', 'role' => 'member']);
        $client = Client::create([
            'name' => 'Example Client',
            'work_type' => 'tracker_manual',
        ]);

        WorkHour::create([
            'user_id' => $member->id,
            'client_id' => $client->id,
            'date' => '2026-06-01',
            'hours' => 7.5,
            'description' => 'Client work',
            'work_type' => 'tracker',
            'tracker' => 'Upwork Desktop',
        ]);

        WorkHour::create([
            'user_id' => $member->id,
            'client_id' => $client->id,
            'date' => '2026-06-02',
            'hours' => 2.5,
            'description' => 'Additional client work',
            'work_type' => 'manual',
            'tracker' => 'Manual Entry',
        ]);

        $response = $this->actingAs($admin)->post(route('work-hours.slack'), [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
            'user_ids' => [$member->id],
            'include_fields' => ['client', 'work_type', 'tracker', 'hours', 'user_total'],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');

        Http::assertSent(function ($request) {
            $table = collect($request['blocks'])->firstWhere('type', 'table');
            $tableJson = json_encode($table);

            return $table !== null
                && count($table['rows']) === 4
                && str_contains($tableJson, 'Test Member')
                && str_contains($tableJson, 'Example Client')
                && str_contains($tableJson, 'Tracker')
                && str_contains($tableJson, 'Upwork Desktop')
                && str_contains($tableJson, '7.5')
                && str_contains($tableJson, 'User Total')
                && $table['rows'][1][5]['text'] === '10'
                && $table['rows'][2][5]['text'] === '10'
                && $table['column_settings'][4]['align'] === 'right'
                && $table['column_settings'][5]['align'] === 'right'
                && str_contains($request['text'], 'Jun 1, 2026');
        });
    }

    public function test_manual_report_only_includes_the_selected_users_and_fields(): void
    {
        Http::fake(['hooks.slack.test/*' => Http::response('ok')]);

        $admin = User::factory()->create(['role' => 'super_admin']);
        $included = User::factory()->create(['name' => 'Included Member', 'role' => 'member']);
        $excluded = User::factory()->create(['name' => 'Excluded Member', 'role' => 'member']);
        $client = Client::create([
            'name' => 'Visible Client',
            'work_type' => 'tracker_manual',
        ]);

        foreach ([$included, $excluded] as $member) {
            WorkHour::create([
                'user_id' => $member->id,
                'client_id' => $client->id,
                'date' => '2026-06-02',
                'hours' => 4,
                'description' => 'Client work',
                'work_type' => 'manual',
                'tracker' => 'Hidden Tracker',
            ]);
        }

        $this->actingAs($admin)->post(route('work-hours.slack'), [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
            'user_ids' => [$included->id],
            'include_fields' => ['client', 'user_total'],
        ])->assertSessionHasNoErrors();

        Http::assertSent(function ($request) {
            $table = collect($request['blocks'])->firstWhere('type', 'table');
            $tableJson = json_encode($table);

            return $table !== null
                && str_contains($tableJson, 'Included Member')
                && str_contains($tableJson, 'Visible Client')
                && ! str_contains($tableJson, 'Excluded Member')
                && ! str_contains($tableJson, 'Hidden Tracker')
                && ! str_contains($tableJson, '"Hours"')
                && str_contains($tableJson, 'User Total')
                && $table['rows'][1][2]['text'] === '4';
        });
    }

    public function test_slack_table_respects_the_one_hundred_row_limit(): void
    {
        Http::fake(['hooks.slack.test/*' => Http::response('ok')]);

        $admin = User::factory()->create(['role' => 'super_admin']);
        $member = User::factory()->create(['name' => 'Busy Member', 'role' => 'member']);

        foreach (range(1, 101) as $index) {
            WorkHour::create([
                'user_id' => $member->id,
                'date' => '2026-06-02',
                'hours' => 1,
                'description' => 'Work item',
                'work_type' => 'tracker',
                'tracker' => 'Tracker '.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            ]);
        }

        $this->actingAs($admin)->post(route('work-hours.slack'), [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
            'user_ids' => [$member->id],
            'include_fields' => ['tracker', 'hours'],
        ])->assertSessionHasNoErrors();

        Http::assertSent(function ($request) {
            $table = collect($request['blocks'])->firstWhere('type', 'table');
            $context = collect($request['blocks'])->firstWhere('type', 'context');

            return count($table['rows']) === 100
                && str_contains($context['elements'][0]['text'], '3 additional table rows omitted');
        });
    }

    public function test_member_without_slack_permission_cannot_send_a_report(): void
    {
        Http::fake();
        $member = User::factory()->create(['role' => 'member', 'permissions' => []]);

        $this->actingAs($member)->post(route('work-hours.slack'), [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
            'user_ids' => [$member->id],
            'include_fields' => ['hours'],
        ])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_weekly_command_sends_the_last_seven_completed_days(): void
    {
        Http::fake(['hooks.slack.test/*' => Http::response('ok')]);
        Carbon::setTestNow(Carbon::parse('2026-06-07 10:00:00', 'Asia/Karachi'));

        $member = User::factory()->create(['name' => 'Weekly Member', 'role' => 'member']);
        WorkHour::create([
            'user_id' => $member->id,
            'date' => '2026-06-06',
            'hours' => 8,
            'description' => 'Weekly work',
            'work_type' => 'office_work',
        ]);

        $this->artisan('reports:send-weekly-slack')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request['text'], 'May 31, 2026')
            && str_contains($request['text'], 'Jun 6, 2026'));

        Carbon::setTestNow();
    }

    public function test_automatic_report_excludes_users_disabled_for_slack_reporting(): void
    {
        Http::fake(['hooks.slack.test/*' => Http::response('ok')]);

        $included = User::factory()->create([
            'name' => 'Included Staff',
            'include_in_slack_reports' => true,
        ]);
        $excluded = User::factory()->create([
            'name' => 'Excluded Manager',
            'include_in_slack_reports' => false,
        ]);

        foreach ([$included, $excluded] as $user) {
            WorkHour::create([
                'user_id' => $user->id,
                'date' => '2026-06-06',
                'hours' => 8,
                'description' => 'Weekly work',
                'work_type' => 'office_work',
            ]);
        }

        $this->artisan('reports:send-weekly-slack', [
            '--start' => '2026-06-01',
            '--end' => '2026-06-07',
        ])->assertSuccessful();

        Http::assertSent(function ($request) {
            $payload = json_encode($request->data());

            return str_contains($payload, 'Included Staff')
                && ! str_contains($payload, 'Excluded Manager')
                && str_contains($request['text'], 'Total hours: 8');
        });
    }

    public function test_manual_report_can_explicitly_include_a_user_disabled_by_default(): void
    {
        Http::fake(['hooks.slack.test/*' => Http::response('ok')]);

        $admin = User::factory()->create(['role' => 'super_admin']);
        $manager = User::factory()->create([
            'name' => 'Occasional Manager',
            'include_in_slack_reports' => false,
        ]);

        WorkHour::create([
            'user_id' => $manager->id,
            'date' => '2026-06-06',
            'hours' => 2,
            'description' => 'Occasional work',
            'work_type' => 'office_work',
        ]);

        $this->actingAs($admin)->post(route('work-hours.slack'), [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
            'user_ids' => [$manager->id],
            'include_fields' => ['hours'],
        ])->assertSessionHasNoErrors();

        Http::assertSent(fn ($request) => str_contains(json_encode($request->data()), 'Occasional Manager'));
    }
}
