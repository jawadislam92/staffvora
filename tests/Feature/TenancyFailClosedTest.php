<?php

namespace Tests\Feature;

use App\Console\Commands\TenancyAudit;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationAccess;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression cover for the tenancy fail-OPEN hole.
 *
 * OrganizationScope emits no predicate when no tenant is bound — deliberately,
 * because login, password reset, TenantProvisioner and console commands all run
 * unbound. The consequence was that a User row with organization_id = NULL
 * (created by the old `user:create-admin` / AdminUserSeeder bootstrap) could log
 * in on the ordinary login page and then read and WRITE every other customer's
 * data, because every query ran with no organization filter.
 *
 * EnsureTenantBound closes that at the request boundary. These tests prove the
 * hole is shut, that ordinary tenants are unaffected, and that the unbound
 * paths (login, password reset) still work.
 */
class TenancyFailClosedTest extends TestCase
{
    use RefreshDatabase;

    private function organization(string $name = 'Acme'): Organization
    {
        return Organization::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'status' => Organization::STATUS_ACTIVE,
        ]);
    }

    /** A user deliberately created with NO organization — the dangerous shape. */
    private function orphanUser(string $email = 'ghost@example.com'): User
    {
        $user = app(CurrentOrganization::class)->runUnscoped(fn () => User::create([
            'name' => 'Ghost Admin',
            'email' => $email,
            'password' => Hash::make('password-that-is-long'),
            'role' => 'super_admin',
            'email_verified_at' => now(),
        ]));

        $user->forceFill(['organization_id' => null])->saveQuietly();
        app(CurrentOrganization::class)->forget();

        return $user->refresh();
    }

    // ---------------------------------------------------------------- the hole

    public function test_a_null_organization_user_is_refused_on_the_customer_surface(): void
    {
        $victim = $this->organization('Victim Co');
        app(CurrentOrganization::class)->runFor($victim, fn () => Client::create([
            'name' => 'Victim Client',
            'work_type' => 'fixed',
        ]));
        app(CurrentOrganization::class)->forget();

        $ghost = $this->orphanUser();

        $this->actingAs($ghost)
            ->get(route('dashboard'))
            ->assertForbidden();

        $this->actingAs($ghost)
            ->get(route('users.index'))
            ->assertForbidden();

        $this->actingAs($ghost)
            ->get(route('clients.index'))
            ->assertForbidden();
    }

    public function test_a_null_organization_user_cannot_write_across_tenants(): void
    {
        $victim = $this->organization('Victim Co');

        $victimUser = app(CurrentOrganization::class)->runFor($victim, fn () => User::create([
            'name' => 'Victim',
            'email' => 'victim@example.com',
            'password' => Hash::make('password-that-is-long'),
            'role' => 'member',
        ]));
        app(CurrentOrganization::class)->forget();

        $ghost = $this->orphanUser();

        $this->actingAs($ghost)
            ->patch(route('users.update', $victimUser), [
                'name' => 'Pwned',
                'email' => $victimUser->email,
                'role' => 'super_admin',
                'permissions' => [],
            ])
            ->assertForbidden();

        $this->assertSame('Victim', $victimUser->fresh()->name);
        $this->assertSame('member', $victimUser->fresh()->role);
    }

    public function test_a_null_organization_user_is_logged_out_by_the_guard(): void
    {
        $ghost = $this->orphanUser();

        $this->actingAs($ghost)->get(route('dashboard'))->assertForbidden();

        $this->assertGuest();
    }

    public function test_a_null_organization_user_is_refused_on_the_desktop_api(): void
    {
        $ghost = $this->orphanUser();

        Sanctum::actingAs($ghost, ['desktop-tracker']);

        $this->getJson(route('desktop.me'))
            ->assertStatus(403)
            ->assertJsonPath('code', 'no_workspace');
    }

    // ------------------------------------------------------- no collateral damage

    public function test_an_ordinary_tenant_user_still_works(): void
    {
        $user = User::factory()->create(['role' => 'super_admin']);

        $this->assertNotNull($user->organization_id);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_login_still_works_for_a_tenant_user(): void
    {
        $user = User::factory()->create();

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_is_refused_for_a_null_organization_user(): void
    {
        $ghost = $this->orphanUser('ghost-login@example.com');

        // The credentials themselves resolve (the scope must stay a no-op for
        // auth), but the very first authenticated request is refused.
        $this->post(route('login'), [
            'email' => $ghost->email,
            'password' => 'password-that-is-long',
        ]);

        $this->get(route('dashboard'))->assertForbidden();
        $this->assertGuest();
    }

    public function test_password_reset_request_still_works_unbound(): void
    {
        $user = User::factory()->create();

        // Logged out, no tenant bound: the user lookup must still resolve.
        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHasNoErrors();
    }

    public function test_guest_pages_are_untouched(): void
    {
        $this->get(route('login'))->assertOk();
        $this->get(route('password.request'))->assertOk();
    }

    // -------------------------------------------------------- supporting pieces

    public function test_organization_access_denies_a_user_without_a_workspace(): void
    {
        $ghost = $this->orphanUser();

        $denial = OrganizationAccess::denialFor($ghost);

        $this->assertNotNull($denial);
        $this->assertSame('workspace_missing', $denial['code']);
        $this->assertSame(403, $denial['status']);
    }

    public function test_tenancy_audit_command_flags_a_null_organization_user(): void
    {
        User::factory()->create();

        $this->artisan('tenancy:audit')->assertExitCode(0);

        $this->orphanUser();

        $this->artisan('tenancy:audit')->assertExitCode(1);
    }

    public function test_tenancy_audit_covers_every_tenant_owned_model(): void
    {
        $models = app(TenancyAudit::class)->tenantModels();

        $this->assertContains(User::class, $models);
        $this->assertContains(Client::class, $models);
        $this->assertNotContains(Organization::class, $models);
    }

    public function test_create_admin_command_refuses_without_an_organization(): void
    {
        $this->artisan('user:create-admin', [
            '--name' => 'Root',
            '--email' => 'root@example.com',
            '--password' => 'a-very-long-password',
            '--org' => 'no-such-org',
        ])->assertExitCode(1);

        $this->assertSame(0, User::withoutOrganizationScope()->where('email', 'root@example.com')->count());
    }

    public function test_create_admin_command_stamps_the_organization(): void
    {
        $org = $this->organization('Bootstrap Co');

        $this->artisan('user:create-admin', [
            '--name' => 'Root',
            '--email' => 'root@example.com',
            '--password' => 'a-very-long-password',
            '--org' => $org->slug,
        ])->expectsConfirmation("Create admin user 'Root' with email 'root@example.com' in 'Bootstrap Co'?", 'yes')
            ->assertExitCode(0);

        $created = User::withoutOrganizationScope()->where('email', 'root@example.com')->firstOrFail();

        $this->assertSame($org->id, $created->organization_id);
        $this->assertSame('super_admin', $created->role);
    }

    public function test_admin_user_seeder_refuses_without_an_organization(): void
    {
        putenv('ADMIN_EMAIL=seeded@example.com');
        putenv('ADMIN_PASSWORD=a-very-long-password');
        putenv('ADMIN_ORG');

        try {
            $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\AdminUserSeeder'])->run();

            $this->assertSame(0, User::withoutOrganizationScope()->where('email', 'seeded@example.com')->count());
        } finally {
            putenv('ADMIN_EMAIL');
            putenv('ADMIN_PASSWORD');
        }
    }
}
