<?php

namespace Tests\Feature;

use App\Models\PlatformUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DeveloperPageTest extends TestCase
{
    use RefreshDatabase;

    /** The operator portal account — the only role allowed near Developer. */
    private function operator(): PlatformUser
    {
        return PlatformUser::create([
            'name' => 'Operator',
            'email' => 'op@platform.test',
            'password' => 'operator-secret-pw',
            'role' => 'owner',
        ]);
    }

    public function test_developer_page_is_operator_only(): void
    {
        // Customers — even a tenant owner (super_admin of their own org) — must
        // never reach a page that edits platform-wide secrets. They hit the
        // operator guard and are bounced to the operator login.
        $member = User::factory()->create(['role' => 'member', 'permissions' => []]);
        $tenantOwner = User::factory()->create(['role' => 'super_admin', 'permissions' => [], 'is_platform_admin' => true]);

        $this->get(route('developer.index'))->assertRedirect(route('platform.login'));
        $this->actingAs($member)->get(route('developer.index'))->assertRedirect(route('platform.login'));
        $this->actingAs($tenantOwner)->get(route('developer.index'))->assertRedirect(route('platform.login'));
        $this->actingAs($tenantOwner)->post(route('developer.run'), ['action' => 'optimize_clear'])->assertRedirect(route('platform.login'));
        $this->actingAs($tenantOwner)->put(route('developer.env.update'), ['values' => ['AWS_DEFAULT_REGION' => 'us-east-1']])->assertRedirect(route('platform.login'));
        $this->actingAs($tenantOwner)->get(route('developer.logs'))->assertRedirect(route('platform.login'));
    }

    public function test_developer_page_loads_for_operator_with_expected_sections(): void
    {
        $this->actingAs($this->operator(), 'platform')
            ->get(route('developer.index'))
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
        $this->actingAs($this->operator(), 'platform')
            ->from(route('developer.index'))
            ->post(route('developer.run'), ['action' => 'rm_rf_everything'])
            ->assertSessionHasErrors('action');
    }

    public function test_env_update_writes_whitelisted_keys_and_ignores_others(): void
    {
        $envPath = base_path('.env');
        if (! file_exists($envPath)) {
            $this->markTestSkipped('.env file not present in this environment.');
        }
        $original = file_get_contents($envPath);

        try {
            $this->actingAs($this->operator(), 'platform')
                ->from(route('developer.index'))
                ->put(route('developer.env.update'), ['values' => [
                    'AWS_DEFAULT_REGION' => 'eu-north-1',
                    'SCREENSHOTS_BUCKET' => 'test-bucket',
                    'APP_KEY' => 'hacked',          // not whitelisted — must be ignored
                    'DB_PASSWORD' => 'hacked',       // not whitelisted — must be ignored
                ]])
                ->assertRedirect(route('developer.index'))
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

    public function test_env_update_can_save_a_single_integration_group(): void
    {
        $envPath = base_path('.env');
        if (! file_exists($envPath)) {
            $this->markTestSkipped('.env file not present in this environment.');
        }
        $original = file_get_contents($envPath);

        try {
            // Scoped to the s3 group: an s3 field saves, a slack field in the
            // same payload is ignored because it isn't in this group.
            $this->actingAs($this->operator(), 'platform')
                ->from(route('developer.index'))
                ->put(route('developer.env.update'), [
                    'group' => 's3',
                    'values' => ['AWS_DEFAULT_REGION' => 'ap-south-1', 'SLACK_REPORT_TIMEZONE' => 'UTC'],
                ])
                ->assertRedirect(route('developer.index'))
                ->assertSessionHas('success');

            $env = file_get_contents($envPath);
            $this->assertStringContainsString('AWS_DEFAULT_REGION=ap-south-1', $env);
            $this->assertStringNotContainsString('SLACK_REPORT_TIMEZONE=UTC', $env);
        } finally {
            if ($original !== null) {
                file_put_contents($envPath, $original);
            }
            $this->artisan('config:clear');
        }
    }

    public function test_env_update_rejects_invalid_webhook(): void
    {
        $this->actingAs($this->operator(), 'platform')
            ->from(route('developer.index'))
            ->put(route('developer.env.update'), ['values' => ['SLACK_REPORT_WEBHOOK_URL' => 'not-a-url']])
            ->assertSessionHas('error');
    }

    public function test_digest_preview_action_builds_output_without_sending(): void
    {
        $this->actingAs($this->operator(), 'platform')
            ->from(route('developer.index'))
            ->post(route('developer.run'), ['action' => 'digest_preview'])
            ->assertRedirect(route('developer.index'))
            ->assertSessionHas('dev_output');
    }

    public function test_prune_dry_run_action_reports_without_deleting(): void
    {
        $this->actingAs($this->operator(), 'platform')
            ->from(route('developer.index'))
            ->post(route('developer.run'), ['action' => 'prune_dry_run'])
            ->assertRedirect(route('developer.index'))
            ->assertSessionHas('success');
    }

    public function test_logs_endpoint_returns_tail(): void
    {
        $this->actingAs($this->operator(), 'platform')
            ->getJson(route('developer.logs'))
            ->assertOk()
            ->assertJsonStructure(['file', 'lines']);
    }
}
