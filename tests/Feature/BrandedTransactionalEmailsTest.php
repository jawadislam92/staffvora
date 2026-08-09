<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\TeamInvitationNotification;
use App\Notifications\WorkspaceWelcomeNotification;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Covers the branded transactional-mail build: the reset/welcome/invite
 * notifications render correctly, the invite reuses the real password-reset
 * broker token end-to-end, and a broken mail transport can never turn a
 * successful signup or invite into a failure (or leak a token into the log).
 *
 * MAIL_MAILER=array and QUEUE_CONNECTION=sync in phpunit.xml — nothing here
 * touches a real transport, and the ShouldQueue notifications still run
 * inline, which is exactly what these tests rely on to assert synchronously.
 */
class BrandedTransactionalEmailsTest extends TestCase
{
    use RefreshDatabase;

    /** Provision a tenant and bind it as the current org for the test. */
    private function makeOwner(string $orgName = 'Acme Co'): User
    {
        $result = app(TenantProvisioner::class)->provision(
            orgName: $orgName,
            ownerName: 'Owner',
            email: strtolower(str_replace(' ', '', $orgName)).'-owner@example.test',
            plainPassword: 'super-secret-pw-1',
        );
        app(CurrentOrganization::class)->set($result['organization']);

        return $result['owner'];
    }

    // =========================================================================
    // (2) Password reset
    // =========================================================================

    public function test_reset_password_email_contains_the_reset_url_and_the_brand_name(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user) {
            $mail = $notification->toMail($user);
            $rendered = $mail->render();

            $this->assertStringContainsString('reset-password', (string) $mail->actionUrl);
            $this->assertStringContainsString((string) $mail->actionUrl, $rendered);
            $this->assertStringContainsString(config('brand.name'), $rendered);

            return true;
        });
    }

    // =========================================================================
    // (3) Welcome / workspace created
    // =========================================================================

    public function test_registering_sends_a_branded_welcome_email(): void
    {
        Notification::fake();

        $this->post(route('register'), [
            'company' => 'Newco Widgets',
            'name' => 'Nadia Owner',
            'email' => 'nadia@newco-widgets.test',
            'password' => 'super-secret-pw-1',
            'password_confirmation' => 'super-secret-pw-1',
        ])->assertRedirect(route('onboarding.show'));

        $owner = User::where('email', 'nadia@newco-widgets.test')->first();
        $this->assertNotNull($owner, 'signup did not create the owner account');

        Notification::assertSentTo($owner, WorkspaceWelcomeNotification::class, function (WorkspaceWelcomeNotification $notification) use ($owner) {
            $rendered = $notification->toMail($owner)->render();

            $this->assertStringContainsString('Newco Widgets', $rendered);
            $this->assertStringContainsString(config('brand.name'), $rendered);
            $this->assertStringContainsString(route('onboarding.show'), $rendered);

            return true;
        });
    }

    public function test_a_broken_mail_transport_does_not_fail_signup(): void
    {
        // Intercepts the underlying dispatch every ->notify() call goes
        // through (Notifiable::notify() -> app(Dispatcher::class)->send()),
        // simulating a real transport failure without touching the network.
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('smtp is down'));

        $response = $this->post(route('register'), [
            'company' => 'Fragile Co',
            'name' => 'Owner',
            'email' => 'owner@fragile-co.test',
            'password' => 'super-secret-pw-1',
            'password_confirmation' => 'super-secret-pw-1',
        ]);

        // The signup itself must still fully succeed.
        $response->assertRedirect(route('onboarding.show'));
        $this->assertNotNull(User::where('email', 'owner@fragile-co.test')->first());
    }

    // =========================================================================
    // (4) Team invitation
    // =========================================================================

    public function test_inviting_a_teammate_sends_a_branded_email_with_a_working_set_password_link(): void
    {
        Notification::fake();
        $owner = $this->makeOwner();

        $this->actingAs($owner)
            ->post(route('onboarding.team'), ['name' => 'Jane Teammate', 'email' => 'jane@acme.test'])
            ->assertRedirect()
            // Additive: the existing temp-password fallback flash must still
            // be there, unchanged, regardless of whether the email sends.
            ->assertSessionHas('invited');

        $invitee = User::where('email', 'jane@acme.test')->first();
        $this->assertNotNull($invitee);

        Notification::assertSentTo(
            $invitee,
            TeamInvitationNotification::class,
            function (TeamInvitationNotification $notification) use ($invitee, $owner) {
                $mail = $notification->toMail($invitee);
                $rendered = $mail->render();
                $url = (string) $mail->actionUrl;

                $this->assertStringContainsString($owner->name, $rendered);
                $this->assertStringContainsString('Acme Co', $rendered);
                $this->assertStringContainsString(config('brand.name'), $rendered);
                $this->assertStringContainsString('reset-password', $url);

                // The headline claim: this is a REAL password-reset broker
                // token, not a bespoke surface — walk it through the actual
                // reset endpoint end-to-end and confirm it sets a working
                // password.
                $query = [];
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $path = parse_url($url, PHP_URL_PATH);
                $token = trim((string) substr($path, strrpos($path, '/') + 1));

                // The real invitee follows this link anonymously, not while
                // logged in as the inviting owner — log the owner out first
                // so the `guest` middleware on this route doesn't bounce the
                // request to the dashboard before the controller even runs.
                $this->app['auth']->guard()->logout();

                $this->post('/reset-password', [
                    'token' => $token,
                    'email' => $query['email'] ?? $invitee->email,
                    'password' => 'brand-new-pw-1',
                    'password_confirmation' => 'brand-new-pw-1',
                ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

                $this->assertTrue(\Illuminate\Support\Facades\Hash::check(
                    'brand-new-pw-1',
                    $invitee->fresh()->password
                ));

                return true;
            }
        );
    }

    public function test_a_broken_mail_transport_does_not_fail_the_invite(): void
    {
        $owner = $this->makeOwner();

        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('smtp is down'));

        $this->actingAs($owner)
            ->post(route('onboarding.team'), ['name' => 'Resilient Jane', 'email' => 'resilient-jane@acme.test'])
            ->assertRedirect()
            ->assertSessionHas('invited');

        // The account still exists — the admin still has the on-screen
        // temp-password fallback either way.
        $this->assertNotNull(User::where('email', 'resilient-jane@acme.test')->first());
    }

    public function test_invite_mail_failure_is_logged_without_leaking_the_token_or_url(): void
    {
        $owner = $this->makeOwner();

        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('smtp is down'));

        Log::shouldReceive('warning')
            ->once()
            ->with(
                'Team invitation email failed to send.',
                \Mockery::on(function ($context) {
                    // Exactly {user_id, error} — nothing resembling a token
                    // or a reset URL is ever passed to the logger.
                    return is_array($context)
                        && array_keys($context) === ['user_id', 'error']
                        && ! str_contains((string) $context['error'], 'reset-password');
                })
            );

        $this->actingAs($owner)
            ->post(route('onboarding.team'), ['name' => 'Logged Jane', 'email' => 'logged-jane@acme.test'])
            ->assertRedirect();
    }
}
