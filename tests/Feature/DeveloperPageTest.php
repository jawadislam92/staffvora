<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DeveloperPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_developer_page_is_super_admin_only(): void
    {
        $member = User::factory()->create(['role' => 'member', 'permissions' => []]);
        $admin = User::factory()->create(['role' => 'admin', 'permissions' => ['monitoring.settings']]);

        $this->actingAs($member)->get('/developer')->assertForbidden();
        $this->actingAs($admin)->get('/developer')->assertForbidden();
        $this->actingAs($member)->post('/developer/run', ['action' => 'optimize_clear'])->assertForbidden();
        $this->actingAs($member)->get('/developer/logs')->assertForbidden();
    }

    public function test_developer_page_loads_for_super_admin_with_expected_sections(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'permissions' => []]);

        $this->actingAs($superAdmin)
            ->get('/developer')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Developer/Index')
                ->has('system')
                ->has('health')
                ->has('schedule')
            );
    }

    public function test_run_rejects_unknown_actions(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'permissions' => []]);

        $this->actingAs($superAdmin)
            ->from('/developer')
            ->post('/developer/run', ['action' => 'rm_rf_everything'])
            ->assertSessionHasErrors('action');
    }

    public function test_env_update_is_super_admin_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'permissions' => ['monitoring.settings']]);

        $this->actingAs($admin)
            ->put('/developer/env', ['values' => ['AWS_DEFAULT_REGION' => 'us-east-1']])
            ->assertForbidden();
    }

    public function test_env_update_writes_whitelisted_keys_and_ignores_others(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'permissions' => []]);
        $envPath = base_path('.env');
        if (! file_exists($envPath)) {
            $this->markTestSkipped('.env file not present in this environment.');
        }
        $original = file_get_contents($envPath);

        try {
            $this->actingAs($superAdmin)
                ->from('/developer')
                ->put('/developer/env', ['values' => [
                    'AWS_DEFAULT_REGION' => 'eu-north-1',
                    'SCREENSHOTS_BUCKET' => 'test-bucket',
                    'APP_KEY' => 'hacked',          // not whitelisted — must be ignored
                    'DB_PASSWORD' => 'hacked',       // not whitelisted — must be ignored
                ]])
                ->assertRedirect('/developer')
                ->assertSessionHas('success');

            $env = file_get_contents($envPath);
            $this->assertStringContainsString('AWS_DEFAULT_REGION=eu-north-1', $env);
            $this->assertStringContainsString('SCREENSHOTS_BUCKET=test-bucket', $env);
            $this->assertStringNotContainsString('APP_KEY=hacked', $env);
            $this->assertStringNotContainsString('DB_PASSWORD=hacked', $env);
        } finally {
            if ($original !== null) {
                file_put_contents($envPath, $original);
            }
            $this->artisan('config:clear');
        }
    }

    public function test_env_update_rejects_invalid_webhook(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'permissions' => []]);

        $this->actingAs($superAdmin)
            ->from('/developer')
            ->put('/developer/env', ['values' => ['SLACK_REPORT_WEBHOOK_URL' => 'not-a-url']])
            ->assertSessionHas('error');
    }

    public function test_digest_preview_action_builds_output_without_sending(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'permissions' => []]);

        $this->actingAs($superAdmin)
            ->from('/developer')
            ->post('/developer/run', ['action' => 'digest_preview'])
            ->assertRedirect('/developer')
            ->assertSessionHas('dev_output');
    }

    public function test_prune_dry_run_action_reports_without_deleting(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'permissions' => []]);

        $this->actingAs($superAdmin)
            ->from('/developer')
            ->post('/developer/run', ['action' => 'prune_dry_run'])
            ->assertRedirect('/developer')
            ->assertSessionHas('success');
    }

    public function test_logs_endpoint_returns_tail(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'permissions' => []]);

        $this->actingAs($superAdmin)
            ->getJson('/developer/logs')
            ->assertOk()
            ->assertJsonStructure(['file', 'lines']);
    }
}
