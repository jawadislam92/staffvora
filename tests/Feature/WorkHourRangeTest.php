<?php

namespace Tests\Feature;

use App\Models\MonitoringSetting;
use App\Models\WorkHour;
use App\Services\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkHourRangeTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): \App\Models\User
    {
        return app(TenantProvisioner::class)->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw')['owner'];
    }

    private function setPolicy(\App\Models\User $owner, string $policy): void
    {
        // Bind the owner's org so we update THEIR settings row (the same one the
        // request will resolve), not the null-org default.
        app(\App\Tenancy\CurrentOrganization::class)->set($owner->organization_id);
        MonitoringSetting::current()->update(['manual_time_overlap_policy' => $policy]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'date' => '2026-06-21',
            'entry_mode' => 'range',
            'start_time' => '14:08',
            'end_time' => '15:05',
            'description' => 'Range work',
            'work_type' => 'office_work',
        ], $overrides);
    }

    public function test_range_entry_computes_hours_and_stores_endpoints(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('work-hours.store'), $this->payload())
            ->assertRedirect(route('work-hours.index'));

        $entry = WorkHour::where('user_id', $owner->id)->first();
        $this->assertNotNull($entry);
        $this->assertEqualsWithDelta(57 / 60, (float) $entry->hours, 0.001); // 14:08–15:05 = 57 min
        $this->assertNotNull($entry->started_at);
        $this->assertNotNull($entry->ended_at);
    }

    public function test_overnight_range_rolls_into_the_next_day(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('work-hours.store'), $this->payload([
            'start_time' => '23:30', 'end_time' => '01:00',
        ]))->assertRedirect();

        $entry = WorkHour::where('user_id', $owner->id)->first();
        $this->assertEqualsWithDelta(1.5, (float) $entry->hours, 0.001); // 23:30 -> 01:00 = 90 min
        $this->assertTrue($entry->ended_at->greaterThan($entry->started_at));
        $this->assertSame('2026-06-22', $entry->ended_at->toDateString()); // rolled to next day
    }

    public function test_duration_mode_still_works(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('work-hours.store'), [
            'date' => '2026-06-21',
            'entry_mode' => 'duration',
            'hours' => 2,
            'minutes' => 30,
            'description' => 'Duration work',
            'work_type' => 'office_work',
        ])->assertRedirect();

        $entry = WorkHour::where('user_id', $owner->id)->first();
        $this->assertEqualsWithDelta(2.5, (float) $entry->hours, 0.001);
        $this->assertNull($entry->started_at);
    }

    public function test_overlap_policy_blocks_when_set_to_block(): void
    {
        $owner = $this->owner();
        $this->setPolicy($owner, 'block');

        $this->actingAs($owner)->post(route('work-hours.store'), $this->payload())->assertRedirect();

        // A second entry that overlaps the first is rejected.
        $this->actingAs($owner)->from(route('work-hours.create'))
            ->post(route('work-hours.store'), $this->payload(['start_time' => '14:30', 'end_time' => '15:30']))
            ->assertSessionHasErrors('start_time');

        $this->assertSame(1, WorkHour::where('user_id', $owner->id)->count());
    }

    public function test_overlap_policy_allows_silently_when_set_to_allow(): void
    {
        $owner = $this->owner();
        $this->setPolicy($owner, 'allow');

        $this->actingAs($owner)->post(route('work-hours.store'), $this->payload())->assertRedirect();
        $this->actingAs($owner)->post(route('work-hours.store'), $this->payload(['start_time' => '14:30', 'end_time' => '15:30']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, WorkHour::where('user_id', $owner->id)->count());
    }
}
