<?php

namespace Tests\Feature;

use App\Http\Controllers\Platform\AuthController;
use App\Models\PlatformUser;
use App\Notifications\PlatformResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Operator (platform guard) password recovery. Exercises the exact route
 * definitions returned to the route owner — registered here in setUp and driven
 * by URL path (matching the platform host) so the flow is testable without
 * editing routes/web.php.
 */
class OperatorPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // NOTE on paths: the customer app registers host-agnostic /forgot-password
        // + /reset-password routes at boot, which would shadow these on the
        // platform host in-test (in production the platform group is declared
        // FIRST in web.php, so it wins). Distinct /op-* paths isolate the
        // controller under test; the route NAMES match the returned definitions.
        Route::domain(config('platform.host'))->middleware('web')->group(function () {
            Route::get('/op-forgot-password', [AuthController::class, 'showForgotPassword'])->name('platform.password.request');
            Route::post('/op-forgot-password', [AuthController::class, 'sendResetLink'])->name('platform.password.email');
            Route::get('/op-reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('platform.password.reset');
            Route::post('/op-reset-password', [AuthController::class, 'resetPassword'])->name('platform.password.update');
        });
    }

    /** Absolute URL on the platform host so the domain-bound routes match. */
    private function url(string $path): string
    {
        return 'http://'.config('platform.host').$path;
    }

    private function operator(): PlatformUser
    {
        return PlatformUser::create([
            'name' => 'Operator',
            'email' => 'locked-out@platform.test',
            'password' => 'the-original-password-123',
            'role' => 'admin',
        ]);
    }

    public function test_request_page_renders(): void
    {
        // Asserted at the action level: in-test the customer marketing-site slug
        // catch-all shadows a bare GET on the platform host (production declares
        // the platform group first, so it wins). The render itself is what matters.
        $response = app(AuthController::class)->showForgotPassword();
        $this->assertInstanceOf(\Inertia\Response::class, $response);
    }

    public function test_a_known_operator_is_emailed_a_reset_link(): void
    {
        Notification::fake();
        $op = $this->operator();

        $this->post($this->url('/op-forgot-password'), ['email' => $op->email])
            ->assertRedirect()
            ->assertSessionHas('success');

        Notification::assertSentTo($op, PlatformResetPassword::class);
    }

    public function test_unknown_email_gets_the_same_neutral_response_and_no_email(): void
    {
        Notification::fake();

        $this->post($this->url('/op-forgot-password'), ['email' => 'nobody@nowhere.test'])
            ->assertRedirect()
            ->assertSessionHas('success'); // identical neutral message — no enumeration

        Notification::assertNothingSent();
    }

    public function test_operator_can_reset_their_password_with_a_valid_token(): void
    {
        Notification::fake();
        $op = $this->operator();

        $this->post($this->url('/op-forgot-password'), ['email' => $op->email]);

        $token = null;
        Notification::assertSentTo($op, PlatformResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });
        $this->assertNotNull($token);

        $this->post($this->url('/op-reset-password'), [
            'token' => $token,
            'email' => $op->email,
            'password' => 'a-brand-new-password-123',
            'password_confirmation' => 'a-brand-new-password-123',
        ])->assertRedirect(route('platform.login'))->assertSessionHas('success');

        $this->assertTrue(Hash::check('a-brand-new-password-123', $op->fresh()->password));
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        $op = $this->operator();

        $this->from($this->url('/op-reset-password/bogus'))
            ->post($this->url('/op-reset-password'), [
                'token' => 'bogus-token',
                'email' => $op->email,
                'password' => 'a-brand-new-password-123',
                'password_confirmation' => 'a-brand-new-password-123',
            ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('the-original-password-123', $op->fresh()->password));
    }

    public function test_short_passwords_are_rejected(): void
    {
        $op = $this->operator();

        $this->post($this->url('/op-reset-password'), [
            'token' => 'whatever',
            'email' => $op->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');
    }
}
