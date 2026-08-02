<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * [medium-9]: tenant:run fans every scheduled job out over every organization in
 * one process. At 500 tenants the failure mode that matters is not slowness but
 * SILENCE — one tenant throwing, or the process being hard-killed past its own
 * interval, stopping the job for everybody (and wedging withoutOverlapping()'s
 * 24h mutex).
 */
class TenantRunIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        for ($i = 1; $i <= 3; $i++) {
            Organization::create([
                'name' => 'Org '.$i,
                'slug' => 'org-'.$i.'-'.Str::random(5),
                'status' => Organization::STATUS_ACTIVE,
            ]);
        }
    }

    public function test_one_tenants_failure_does_not_stop_the_others(): void
    {
        $seen = [];
        $current = app(CurrentOrganization::class);
        $orgs = Organization::orderBy('id')->pluck('id')->all();

        \Illuminate\Support\Facades\Artisan::command('test:tenant-probe', function () use (&$seen, $current, $orgs) {
            $id = $current->id();
            $seen[] = $id;

            if ($id === $orgs[1]) {
                throw new \RuntimeException('this tenant is broken');
            }
        })->purpose('probe');

        $this->artisan('tenant:run', ['target' => 'test:tenant-probe'])->assertExitCode(1);

        $this->assertSame($orgs, $seen, 'every organization must still be visited after one throws');
    }

    public function test_each_organization_runs_bound_to_itself_and_the_binding_is_released(): void
    {
        $bound = [];
        $current = app(CurrentOrganization::class);

        \Illuminate\Support\Facades\Artisan::command('test:tenant-bound', function () use (&$bound, $current) {
            $bound[] = $current->id();
        })->purpose('probe');

        $this->artisan('tenant:run', ['target' => 'test:tenant-bound'])->assertSuccessful();

        $this->assertSame(Organization::orderBy('id')->pluck('id')->all(), $bound);
        $this->assertNull($current->id(), 'the tenant binding must not leak past the loop');
    }

    public function test_a_time_budget_stops_the_pass_cleanly_instead_of_being_killed_mid_flight(): void
    {
        $ran = 0;

        \Illuminate\Support\Facades\Artisan::command('test:tenant-slow', function () use (&$ran) {
            $ran++;
            usleep(120_000);
        })->purpose('probe');

        $this->artisan('tenant:run', [
            'target' => 'test:tenant-slow',
            '--time-budget' => 1,
            '--slow-org-seconds' => 1,
        ])->assertSuccessful();

        $this->assertGreaterThanOrEqual(1, $ran);
        $this->assertLessThanOrEqual(3, $ran);
    }
}
