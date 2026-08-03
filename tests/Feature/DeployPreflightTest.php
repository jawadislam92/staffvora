<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The pre-flight check is the last thing standing between "the code deployed"
 * and "a customer hit a 500". These tests pin the two behaviours that matter:
 * a real problem must FAIL the command (non-zero exit, so a deploy script can
 * gate on it), and a healthy box must not be reported as broken.
 */
class DeployPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_runs_and_reports_in_plain_english(): void
    {
        $this->artisan('deploy:preflight')
            ->expectsOutputToContain('pre-flight check')
            ->expectsOutputToContain('Application key')
            ->expectsOutputToContain('Database updates (migrations)');
    }

    public function test_a_user_with_no_workspace_fails_the_check(): void
    {
        // The single worst production state: an account with no organization
        // reads and writes every customer's data.
        $user = User::factory()->create();
        User::withoutOrganizationScope()->whereKey($user->id)->update(['organization_id' => null]);

        $this->artisan('deploy:preflight')
            ->expectsOutputToContain('belong to NO workspace')
            ->assertExitCode(1);
    }

    public function test_it_flags_a_placeholder_mail_from_address(): void
    {
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp.host', 'smtp.example.net');
        config()->set('mail.from.address', 'hello@example.com');

        $this->artisan('deploy:preflight')
            ->expectsOutputToContain('placeholder')
            ->assertExitCode(1);
    }

    public function test_it_flags_a_leftover_pre_rebrand_product_name(): void
    {
        config()->set('app.name', 'Tracksuite');

        $this->artisan('deploy:preflight')
            ->expectsOutputToContain('old product name')
            ->assertExitCode(1);
    }

    public function test_it_flags_debug_mode_left_on_in_production(): void
    {
        config()->set('app.env', 'production');
        config()->set('app.debug', true);

        $this->artisan('deploy:preflight')
            ->expectsOutputToContain('Debug mode is ON')
            ->assertExitCode(1);
    }

    public function test_it_flags_a_missing_application_key(): void
    {
        config()->set('app.key', '');

        $this->artisan('deploy:preflight')
            ->expectsOutputToContain('APP_KEY is empty')
            ->assertExitCode(1);
    }

    public function test_a_healthy_server_passes(): void
    {
        Organization::query()->delete();
        User::withoutOrganizationScope()->delete();

        config()->set('app.env', 'production');
        config()->set('app.debug', false);
        config()->set('app.name', 'Staffvora');
        config()->set('app.url', 'https://staffvora.com');
        config()->set('brand.name', 'Staffvora');
        config()->set('brand.legal_name', 'Staffvora (Private) Limited');
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp.host', 'smtp.staffvora.com');
        config()->set('mail.from.address', 'hello@staffvora.com');
        config()->set('session.secure', true);
        config()->set('queue.default', 'database');

        // The two filesystem facts the command reads: the public/storage link
        // and a fresh scheduler heartbeat. Create them only if missing, and put
        // back exactly what we found.
        $link = public_path('storage');
        $createdLink = false;

        if (! is_link($link) && ! is_dir($link)) {
            File::ensureDirectoryExists($link);
            $createdLink = true;
        }

        $heartbeat = storage_path('framework/schedule-heartbeat');
        $hadHeartbeat = file_exists($heartbeat);
        $previousMtime = $hadHeartbeat ? filemtime($heartbeat) : null;
        touch($heartbeat);

        // Installer feed: point at a temp dir holding a latest.yml.
        $installers = storage_path('framework/testing/preflight-installers');
        File::ensureDirectoryExists($installers);
        File::put($installers.'/latest.yml', "version: 0.4.6\n");
        config()->set('desktop.installers_path', $installers);

        try {
            $this->artisan('deploy:preflight')
                ->expectsOutputToContain('ALL CLEAR')
                ->assertExitCode(0);
        } finally {
            File::deleteDirectory($installers);

            if ($createdLink) {
                File::deleteDirectory($link);
            }

            if (! $hadHeartbeat) {
                @unlink($heartbeat);
            } elseif ($previousMtime !== null) {
                @touch($heartbeat, $previousMtime);
            }
        }
    }
}
