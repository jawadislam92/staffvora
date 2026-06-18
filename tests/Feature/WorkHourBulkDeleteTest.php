<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkHourBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_delete_returns_inertia_redirect_not_json(): void
    {
        $user = User::factory()->create(['role' => 'member', 'permissions' => []]);
        $ids = collect(range(1, 3))->map(fn () => WorkHour::create([
            'user_id' => $user->id,
            'date' => now()->toDateString(),
            'hours' => 1,
            'description' => 'x',
            'work_type' => 'manual',
        ])->id)->all();

        $this->actingAs($user)
            ->from('/work-hours')
            ->post('/work-hours/bulk-delete', ['ids' => $ids])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(0, WorkHour::whereIn('id', $ids)->count());
    }

    public function test_bulk_delete_blocks_other_users_entries(): void
    {
        $user = User::factory()->create(['role' => 'member', 'permissions' => []]);
        $other = User::factory()->create(['role' => 'member']);
        $mine = WorkHour::create(['user_id' => $user->id, 'date' => now()->toDateString(), 'hours' => 1, 'description' => 'x', 'work_type' => 'manual']);
        $theirs = WorkHour::create(['user_id' => $other->id, 'date' => now()->toDateString(), 'hours' => 1, 'description' => 'x', 'work_type' => 'manual']);

        $this->actingAs($user)
            ->from('/work-hours')
            ->post('/work-hours/bulk-delete', ['ids' => [$mine->id, $theirs->id]])
            ->assertRedirect()
            ->assertSessionHasErrors('ids');

        // Nothing deleted when the batch includes someone else's entry.
        $this->assertSame(2, WorkHour::whereIn('id', [$mine->id, $theirs->id])->count());
    }
}
