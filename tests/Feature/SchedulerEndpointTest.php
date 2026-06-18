<?php

namespace Tests\Feature;

use Tests\TestCase;

class SchedulerEndpointTest extends TestCase
{
    public function test_runs_scheduler_with_valid_token(): void
    {
        config(['services.scheduler.token' => str_repeat('a', 40)]);

        $this->get('/cron/run/'.str_repeat('a', 40))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function test_rejects_wrong_token(): void
    {
        config(['services.scheduler.token' => str_repeat('a', 40)]);

        $this->get('/cron/run/'.str_repeat('b', 40))->assertNotFound();
    }

    public function test_disabled_when_no_token_configured(): void
    {
        config(['services.scheduler.token' => null]);

        $this->get('/cron/run/'.str_repeat('a', 40))->assertNotFound();
    }
}
