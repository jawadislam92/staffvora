<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Every real user belongs to a workspace — a NULL organization_id is
            // a cross-tenant hole (EnsureTenantBound refuses such accounts), so
            // factory users must be tenanted too. Prefer the bound tenant, then
            // the existing workspace, and only create one as a last resort so a
            // test that makes several users keeps them in the same workspace.
            'organization_id' => fn () => app(CurrentOrganization::class)->id()
                ?? Organization::query()->orderBy('id')->value('id')
                ?? $this->makeUsableOrganization(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'role' => 'member',
            'permissions' => [],
            'include_in_slack_reports' => true,
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Bind the tenant once a user exists, the same way SetCurrentOrganization
     * does for a real request. Without this, rows built in a test *before* the
     * first actingAs() would be stamped with a NULL organization_id and then be
     * invisible to the very user the test just created.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            $current = app(CurrentOrganization::class);

            if ($user->organization_id && ! $current->check()) {
                $current->set($user->organization_id);
            }
        });
    }

    /**
     * A usable workspace (active + subscribed), mirroring TenantProvisioner but
     * without provisioning an owner user, so tests that count users are not
     * surprised by an extra row.
     */
    private function makeUsableOrganization(): int
    {
        $org = Organization::factory()->create([
            'status' => Organization::STATUS_ACTIVE,
        ]);

        Plan::ensureSeeded();
        $plan = Plan::where('key', 'pro')->first() ?? Plan::orderBy('sort')->first();

        app(CurrentOrganization::class)->runFor($org, function () use ($plan) {
            if ($plan) {
                Subscription::create([
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'current_period_end' => now()->addMonth(),
                ]);
            }
        });

        return $org->id;
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
