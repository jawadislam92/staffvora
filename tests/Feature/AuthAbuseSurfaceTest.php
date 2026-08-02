<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Desktop\AuthController;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WorkHour;
use App\Services\SlackBotService;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Regression cover for the auth + abuse surface: what a malicious employee, an
 * ex-employee or a plain script can do to this system from the outside.
 *
 * Every case here maps to a defect that was live in the product — a token that
 * never died, a bulk endpoint that would run one query per submitted id, a
 * feedback button that fanned out unbounded blocking Slack calls, and a
 * directory page that re-queried work_hours once per row. They are grouped in
 * one file because they share the same threat model: the caller is
 * authenticated, low-privilege, and hostile.
 */
class AuthAbuseSurfaceTest extends TestCase
{
    use RefreshDatabase;

    /** A real provisioned tenant, bound, with its owner returned. */
    private function tenantOwner(string $name, string $email): User
    {
        $owner = app(TenantProvisioner::class)->provision($name, 'Owner', $email, 'secret-pass-123')['owner'];
        app(CurrentOrganization::class)->set($owner->organization_id);

        return $owner->fresh();
    }

    /** A bare workspace on the free plan (no provisioned owner user). */
    private function workspace(): Organization
    {
        Plan::ensureSeeded();
        $organization = Organization::factory()->create(['status' => Organization::STATUS_ACTIVE]);

        app(CurrentOrganization::class)->runFor($organization, fn () => Subscription::create([
            'plan_id' => Plan::where('key', 'free')->firstOrFail()->id,
            'status' => 'active',
            'current_period_end' => now()->addMonth(),
        ]));

        return $organization;
    }

    // ------------------------------------------------- desktop token lifetime

    public function test_desktop_token_is_issued_with_a_bounded_expiry(): void
    {
        $this->tenantOwner('Expiry Co', 'owner@expiry.test');

        $response = $this->postJson('/api/desktop/login', [
            'email' => 'owner@expiry.test',
            'password' => 'secret-pass-123',
            'device_name' => 'Work Laptop',
        ])->assertOk();

        $this->assertNotNull($response->json('expires_at'), 'The client must be told when its token dies.');

        $token = PersonalAccessToken::firstOrFail();

        $this->assertNotNull(
            $token->expires_at,
            'A desktop token with no expires_at is valid forever — a lost laptop then keeps clocking in indefinitely.'
        );
        $this->assertEqualsWithDelta(
            AuthController::TOKEN_LIFETIME_DAYS * 24 * 60,
            now()->diffInMinutes($token->expires_at),
            5,
            'Token lifetime drifted from the documented budget.'
        );
    }

    public function test_an_expired_desktop_token_stops_authenticating(): void
    {
        $owner = $this->tenantOwner('Stale Co', 'owner@stale.test');

        $plain = $this->postJson('/api/desktop/login', [
            'email' => 'owner@stale.test',
            'password' => 'secret-pass-123',
            'device_name' => 'Work Laptop',
        ])->assertOk()->json('token');

        $headers = ['Authorization' => 'Bearer '.$plain];

        $this->withHeaders($headers)->getJson('/api/desktop/me')->assertOk();

        // Past the lifetime, the same bearer token must be worthless — this is
        // the whole point of bounding it.
        $this->travel(AuthController::TOKEN_LIFETIME_DAYS + 1)->days();
        $this->app['auth']->forgetGuards();

        $this->withHeaders($headers)->getJson('/api/desktop/me')->assertStatus(401);

        $this->travelBack();
        $this->assertSame(1, $owner->tokens()->count());
    }

    public function test_single_device_login_revokes_every_previous_token(): void
    {
        $owner = $this->tenantOwner('One Device Co', 'owner@onedevice.test');
        $owner->forceFill(['allow_multiple_devices' => false])->save();

        // device_name is attacker-chosen, so matching only on it let one
        // account hoard live tokens under invented device names.
        $owner->createToken('Invented Device A', ['desktop-tracker']);
        $owner->createToken('Invented Device B', ['desktop-tracker']);

        $this->postJson('/api/desktop/login', [
            'email' => 'owner@onedevice.test',
            'password' => 'secret-pass-123',
            'device_name' => 'Real Laptop',
        ])->assertOk();

        $this->assertSame(1, $owner->tokens()->count());
        $this->assertSame('Real Laptop', $owner->tokens()->first()->name);
    }

    public function test_multi_device_accounts_keep_their_other_devices(): void
    {
        $owner = $this->tenantOwner('Many Devices Co', 'owner@manydevices.test');
        $owner->forceFill(['allow_multiple_devices' => true])->save();

        $owner->createToken('Home Desktop', ['desktop-tracker']);

        $this->postJson('/api/desktop/login', [
            'email' => 'owner@manydevices.test',
            'password' => 'secret-pass-123',
            'device_name' => 'Office Laptop',
        ])->assertOk();

        $this->assertSame(2, $owner->tokens()->count());
    }

    // ------------------------------------------------------- bulk id payloads

    public function test_work_hour_bulk_delete_refuses_an_oversized_id_array(): void
    {
        $owner = $this->tenantOwner('Bulk Co', 'owner@bulk.test');

        $this->actingAs($owner)
            ->post(route('work-hours.bulk-delete'), ['ids' => range(1, 5000)])
            ->assertSessionHasErrors('ids');
    }

    public function test_user_bulk_status_refuses_an_oversized_id_array(): void
    {
        $owner = $this->tenantOwner('Bulk Status Co', 'owner@bulkstatus.test');

        $this->actingAs($owner)
            ->post(route('users.bulk-status'), ['is_active' => false, 'ids' => range(1, 5000)])
            ->assertSessionHasErrors('ids');
    }

    public function test_bulk_delete_cannot_reach_or_probe_another_tenants_rows(): void
    {
        $victim = $this->tenantOwner('Victim Co', 'owner@victim.test');
        $victimRow = WorkHour::create([
            'user_id' => $victim->id,
            'date' => now()->toDateString(),
            'hours' => 4,
            'description' => 'Victim work',
        ]);

        $attacker = $this->tenantOwner('Attacker Co', 'owner@attacker.test');
        $member = User::factory()->create([
            'organization_id' => $attacker->organization_id,
            'role' => 'member',
            'permissions' => [],
        ]);

        $this->actingAs($member)
            ->post(route('work-hours.bulk-delete'), ['ids' => [$victimRow->id]])
            ->assertSessionHasErrors('ids');

        // Same refusal a non-existent id gets, so the response cannot be used
        // to enumerate which row ids exist in someone else's workspace...
        $this->actingAs($member)
            ->post(route('work-hours.bulk-delete'), ['ids' => [$victimRow->id + 9999]])
            ->assertSessionHasErrors('ids');

        // ...and nothing crossed the tenant boundary.
        $this->assertDatabaseHas('work_hours', ['id' => $victimRow->id]);
    }

    public function test_bulk_status_deactivates_in_bulk_and_revokes_desktop_tokens(): void
    {
        $owner = $this->tenantOwner('Cleanup Co', 'owner@cleanup.test');

        $leavers = User::factory()->count(3)->create([
            'organization_id' => $owner->organization_id,
            'role' => 'member',
            'is_active' => true,
        ]);

        foreach ($leavers as $leaver) {
            $leaver->createToken('Their Laptop', ['desktop-tracker']);
        }

        $this->actingAs($owner)->post(route('users.bulk-status'), [
            'is_active' => false,
            'ids' => $leavers->pluck('id')->all(),
        ])->assertSessionHasNoErrors();

        foreach ($leavers as $leaver) {
            $this->assertFalse((bool) $leaver->fresh()->is_active);
            $this->assertSame(0, $leaver->tokens()->count(), 'Deactivation must kill desktop access.');
        }

        // The actor is never flipped by their own bulk action.
        $this->assertTrue((bool) $owner->fresh()->is_active);
    }

    // --------------------------------------------------- feedback Slack fanout

    public function test_feedback_notification_fanout_is_capped(): void
    {
        $organization = $this->workspace();

        // Far more triagers than any sane inbox has — the point is that the
        // fan-out does not grow with the size of the workspace.
        User::factory()->count(40)->create([
            'organization_id' => $organization->id,
            'role' => 'super_admin',
        ]);

        $submitter = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => 'member',
        ]);

        $dms = 0;
        $this->mock(SlackBotService::class, function ($mock) use (&$dms) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('postToChannel')->andReturn(true);
            $mock->shouldReceive('dmByEmail')->andReturnUsing(function () use (&$dms) {
                $dms++;

                return true;
            });
        });

        $this->actingAs($submitter)
            ->post(route('feedback.store'), [
                'type' => 'question',
                'subject' => 'Where is payroll?',
                'message' => 'Cannot find the export.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertGreaterThan(0, $dms, 'Managers must still be told.');
        $this->assertLessThanOrEqual(
            25,
            $dms,
            'An uncapped fan-out is 3 blocking Slack calls per manager — a DoS and a DM-spam amplifier.'
        );
    }

    public function test_a_burst_of_submissions_collapses_into_one_fanout(): void
    {
        $organization = $this->workspace();

        User::factory()->count(5)->create([
            'organization_id' => $organization->id,
            'role' => 'super_admin',
        ]);

        $submitter = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => 'member',
        ]);

        $dms = 0;
        $channelPosts = 0;
        $this->mock(SlackBotService::class, function ($mock) use (&$dms, &$channelPosts) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('dmByEmail')->andReturnUsing(function () use (&$dms) {
                $dms++;

                return true;
            });
            $mock->shouldReceive('postToChannel')->andReturnUsing(function () use (&$channelPosts) {
                $channelPosts++;

                return true;
            });
        });

        // There are TWO independent defences and this exercises both. The route
        // carries `throttle:feedback` (5/minute per user), and the notifier
        // collapses a burst into one DM fan-out per workspace per window. The
        // fan-out guard has to hold on its own, because the throttle still lets
        // five submissions through every single minute, indefinitely.
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($submitter)
                ->post(route('feedback.store'), [
                    'type' => 'question',
                    'subject' => 'Spam '.$i,
                    'message' => 'Flooding the inbox.',
                ])
                ->assertSessionHasNoErrors();
        }

        // The sixth inside the same minute is refused by the route limiter.
        $this->actingAs($submitter)
            ->post(route('feedback.store'), [
                'type' => 'question',
                'subject' => 'Spam 5',
                'message' => 'Flooding the inbox.',
            ])
            ->assertStatus(429);

        // Every accepted submission is still filed and still surfaced — but only
        // the first one re-DMs the whole manager list.
        $this->assertDatabaseCount('feedback_items', 5);
        $this->assertLessThanOrEqual(
            5,
            $dms,
            'A burst must not re-DM every manager per submission.'
        );
    }

    // ------------------------------------------------------ users list scaling

    public function test_users_directory_does_not_query_work_hours_per_row(): void
    {
        $owner = $this->tenantOwner('Directory Co', 'owner@directory.test');

        $countWorkHourQueries = function () use ($owner): int {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->actingAs($owner)->get(route('users.index', ['perPage' => 100]))->assertOk();

            $queries = collect(DB::getQueryLog())
                ->filter(fn (array $q) => str_contains($q['query'], 'work_hours'))
                ->count();

            DB::disableQueryLog();

            return $queries;
        };

        User::factory()->count(3)->create(['organization_id' => $owner->organization_id]);
        $small = $countWorkHourQueries();

        User::factory()->count(30)->create(['organization_id' => $owner->organization_id]);
        $large = $countWorkHourQueries();

        $this->assertSame(
            $small,
            $large,
            "Work-hour queries grew with the number of rows rendered ({$small} -> {$large}) — that is the N+1 back."
        );
    }

    public function test_users_directory_still_reports_this_weeks_hours(): void
    {
        $owner = $this->tenantOwner('Hours Co', 'owner@hours.test');

        WorkHour::create([
            'user_id' => $owner->id,
            'date' => now()->toDateString(),
            'hours' => 2.5,
            'description' => 'Some work',
        ]);

        $this->actingAs($owner)
            ->get(route('users.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('UsersList')
                ->where('users.data.0.weekly_hours_worked', '02:30')
            );
    }

    public function test_a_user_with_no_hours_this_week_reads_as_zero(): void
    {
        // withSum() returns NULL, not 0, for an empty window — feeding that
        // straight into floor() is an implicit null-to-float deprecation on
        // every empty row.
        $owner = $this->tenantOwner('Empty Co', 'owner@empty.test');

        $this->actingAs($owner)
            ->get(route('users.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('users.data.0.weekly_hours_worked', '00:00')
            );
    }
}
