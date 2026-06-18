<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PasswordResetCodeTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSlack(): void
    {
        config(['services.slack_bot.token' => 'xoxb-test', 'services.slack_bot.resets_channel' => '#password-resets']);

        Http::fake([
            'slack.com/api/users.lookupByEmail*' => Http::response(['ok' => true, 'user' => ['id' => 'U123']]),
            'slack.com/api/conversations.open' => Http::response(['ok' => true, 'channel' => ['id' => 'D123']]),
            'slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
        ]);
    }

    public function test_requesting_a_code_stores_token_and_dms_slack(): void
    {
        $this->fakeSlack();
        $user = User::factory()->create(['email' => 'worker@example.com']);

        $this->post(route('password.code.request'), ['email' => 'worker@example.com'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'worker@example.com']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat.postMessage'));
    }

    public function test_unknown_email_returns_neutral_message_without_slack_call(): void
    {
        $this->fakeSlack();

        $this->post(route('password.code.request'), ['email' => 'nobody@example.com'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'nobody@example.com']);
        Http::assertNothingSent();
    }

    public function test_valid_code_resets_the_password_and_is_single_use(): void
    {
        $user = User::factory()->create(['email' => 'worker@example.com']);

        DB::table('password_reset_tokens')->insert([
            'email' => 'worker@example.com',
            'token' => Hash::make('123456'),
            'created_at' => now(),
        ]);

        $this->post(route('password.code.reset'), [
            'email' => 'worker@example.com',
            'code' => '123456',
            'password' => 'brand-new-password-1',
            'password_confirmation' => 'brand-new-password-1',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('brand-new-password-1', $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'worker@example.com']);
    }

    public function test_wrong_or_expired_code_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'worker@example.com']);

        DB::table('password_reset_tokens')->insert([
            'email' => 'worker@example.com',
            'token' => Hash::make('123456'),
            'created_at' => now()->subMinutes(11), // expired
        ]);

        $this->from(route('password.request'))->post(route('password.code.reset'), [
            'email' => 'worker@example.com',
            'code' => '123456',
            'password' => 'brand-new-password-1',
            'password_confirmation' => 'brand-new-password-1',
        ])->assertSessionHasErrors('code');

        DB::table('password_reset_tokens')->where('email', 'worker@example.com')->update(['created_at' => now()]);

        $this->from(route('password.request'))->post(route('password.code.reset'), [
            'email' => 'worker@example.com',
            'code' => '999999',
            'password' => 'brand-new-password-1',
            'password_confirmation' => 'brand-new-password-1',
        ])->assertSessionHasErrors('code');

        $this->assertFalse(Hash::check('brand-new-password-1', $user->fresh()->password));
    }
}
