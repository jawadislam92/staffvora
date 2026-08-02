<?php

namespace Tests\Feature;

use App\Models\Feedback;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SlackBotService;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slack alerts for the feedback inbox are BEST-EFFORT. Slack being down, slow,
 * misconfigured or throwing must never stop somebody filing a request or a
 * manager answering one — the request is the product, the notification is a
 * convenience. These tests pin that guarantee, because the failure mode is
 * silent and would otherwise only show up as "the feedback button is broken"
 * on the day Slack has an outage.
 */
class FeedbackSlackNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function member(): User
    {
        Plan::ensureSeeded();
        $organization = Organization::factory()->create([
            'status' => Organization::STATUS_ACTIVE,
        ]);

        app(CurrentOrganization::class)->runFor($organization, fn () => Subscription::create([
            'plan_id' => Plan::where('key', 'free')->firstOrFail()->id,
            'status' => 'active',
            'current_period_end' => now()->addMonth(),
        ]));

        return User::factory()->create(['organization_id' => $organization->id]);
    }

    private function payload(): array
    {
        return [
            'type' => 'question',
            'subject' => 'How do I export last month?',
            'message' => 'I need the March hours for payroll.',
        ];
    }

    public function test_a_request_is_still_filed_when_slack_throws(): void
    {
        $this->mock(SlackBotService::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('dmByEmail')->andThrow(new \RuntimeException('Slack is down'));
            $mock->shouldReceive('postToChannel')->andThrow(new \RuntimeException('Slack is down'));
        });

        $this->actingAs($this->member())
            ->post(route('feedback.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('feedback_items', 1);
    }

    public function test_a_request_is_filed_when_slack_is_not_configured(): void
    {
        $this->mock(SlackBotService::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(false);
            // No send should even be attempted when Slack isn't connected.
            $mock->shouldNotReceive('dmByEmail');
            $mock->shouldNotReceive('postToChannel');
        });

        $this->actingAs($this->member())
            ->post(route('feedback.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('feedback_items', 1);
    }

    public function test_a_manager_reply_still_saves_when_slack_throws(): void
    {
        $submitter = $this->member();
        $manager = User::factory()->create([
            'organization_id' => $submitter->organization_id,
            'role' => 'super_admin',
        ]);

        $item = app(CurrentOrganization::class)->runFor(
            $submitter->organization_id,
            fn () => Feedback::create([
                'user_id' => $submitter->id,
                'type' => 'question',
                'subject' => 'How do I export last month?',
                'message' => 'I need the March hours for payroll.',
                'status' => 'new',
            ])
        );

        $this->mock(SlackBotService::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('dmByEmail')->andThrow(new \RuntimeException('Slack is down'));
            $mock->shouldReceive('postToChannel')->andThrow(new \RuntimeException('Slack is down'));
        });

        $this->actingAs($manager)
            ->post(route('feedback.reply', $item), ['body' => 'Use Reports → Export.'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('feedback_messages', 1);
    }
}
