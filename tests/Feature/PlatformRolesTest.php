<?php

namespace Tests\Feature;

use App\Models\PlatformInvitation;
use App\Models\PlatformUser;
use App\Services\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformRolesTest extends TestCase
{
    use RefreshDatabase;

    private function operator(string $role, string $email = null): PlatformUser
    {
        return PlatformUser::create([
            'name' => ucfirst($role),
            'email' => $email ?: $role.'@platform.test',
            'password' => 'operator-secret-pw',
            'role' => $role,
        ]);
    }

    private function tenant(): \App\Models\Organization
    {
        return app(TenantProvisioner::class)->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw')['organization'];
    }

    public function test_support_can_view_tenants_and_resume_but_not_manage(): void
    {
        $org = $this->tenant();
        $support = $this->operator('support');

        $this->actingAs($support, 'platform')->get(route('platform.index'))->assertOk();
        $this->actingAs($support, 'platform')->get(route('platform.show', $org->id))->assertOk();
        $this->actingAs($support, 'platform')->post(route('platform.resume', $org->id))->assertRedirect();

        // Management surfaces are forbidden for Support.
        $this->actingAs($support, 'platform')->get(route('platform.plans'))->assertForbidden();
        $this->actingAs($support, 'platform')->get(route('developer.index'))->assertForbidden();
        $this->actingAs($support, 'platform')->get(route('platform.site.index'))->assertForbidden();
        $this->actingAs($support, 'platform')->post(route('platform.suspend', $org->id))->assertForbidden();
        $this->actingAs($support, 'platform')->get(route('platform.staff'))->assertForbidden();
    }

    public function test_admin_can_manage_but_cannot_touch_staff(): void
    {
        $org = $this->tenant();
        $admin = $this->operator('admin');

        $this->actingAs($admin, 'platform')->get(route('platform.plans'))->assertOk();
        $this->actingAs($admin, 'platform')->get(route('developer.index'))->assertOk();
        $this->actingAs($admin, 'platform')->post(route('platform.suspend', $org->id))->assertRedirect();

        $this->actingAs($admin, 'platform')->get(route('platform.staff'))->assertForbidden();
        $this->actingAs($admin, 'platform')->post(route('platform.staff.invite'), ['email' => 'x@y.com', 'role' => 'admin'])->assertForbidden();
    }

    public function test_owner_can_invite_staff(): void
    {
        $owner = $this->operator('owner');

        $this->actingAs($owner, 'platform')->get(route('platform.staff'))->assertOk();

        $this->actingAs($owner, 'platform')
            ->post(route('platform.staff.invite'), ['email' => 'newadmin@platform.test', 'role' => 'admin'])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('platform_invitations', ['email' => 'newadmin@platform.test', 'role' => 'admin', 'accepted_at' => null]);
    }

    public function test_invite_rejects_an_existing_staff_email(): void
    {
        $owner = $this->operator('owner');
        $this->operator('admin', 'taken@platform.test');

        $this->actingAs($owner, 'platform')
            ->post(route('platform.staff.invite'), ['email' => 'taken@platform.test', 'role' => 'support'])
            ->assertSessionHasErrors('email');
    }

    public function test_invitation_can_be_accepted_to_create_a_staff_account(): void
    {
        $owner = $this->operator('owner');
        $invite = PlatformInvitation::mint('support@new.test', 'support', $owner->id);

        $this->get(route('platform.invite.show', $invite->token))->assertOk();

        $this->post(route('platform.invite.accept', $invite->token), [
            'name' => 'New Support',
            'password' => 'brand-new-strong-pw',
            'password_confirmation' => 'brand-new-strong-pw',
        ])->assertRedirect(route('platform.dashboard'));

        $this->assertDatabaseHas('platform_users', ['email' => 'support@new.test', 'role' => 'support']);
        $this->assertNotNull($invite->fresh()->accepted_at);
        $this->assertTrue(auth('platform')->check());
    }

    public function test_used_invitation_is_rejected(): void
    {
        $invite = PlatformInvitation::mint('once@new.test', 'admin', null);
        $invite->forceFill(['accepted_at' => now()])->save();

        $this->get(route('platform.invite.show', $invite->token))->assertRedirect(route('platform.login'));
        $this->post(route('platform.invite.accept', $invite->token), [
            'name' => 'X', 'password' => 'brand-new-strong-pw', 'password_confirmation' => 'brand-new-strong-pw',
        ])->assertRedirect(route('platform.login'));

        $this->assertDatabaseMissing('platform_users', ['email' => 'once@new.test']);
    }

    public function test_owner_cannot_revoke_self_or_another_owner(): void
    {
        $owner = $this->operator('owner');
        $owner2 = $this->operator('owner', 'owner2@platform.test');
        $admin = $this->operator('admin');

        $this->actingAs($owner, 'platform')->delete(route('platform.staff.revoke', $owner->id))->assertForbidden();
        $this->actingAs($owner, 'platform')->delete(route('platform.staff.revoke', $owner2->id))->assertForbidden();
        $this->actingAs($owner, 'platform')->delete(route('platform.staff.revoke', $admin->id))->assertRedirect();
        $this->assertDatabaseMissing('platform_users', ['id' => $admin->id]);
    }

    public function test_operator_can_change_their_password(): void
    {
        $admin = $this->operator('admin');

        // Wrong current password is rejected.
        $this->actingAs($admin, 'platform')->put(route('platform.account.update'), [
            'name' => 'Admin', 'current_password' => 'wrong', 'password' => 'a-new-strong-pw-12', 'password_confirmation' => 'a-new-strong-pw-12',
        ])->assertSessionHasErrors('current_password');

        // Correct current password updates it.
        $this->actingAs($admin, 'platform')->put(route('platform.account.update'), [
            'name' => 'Admin Renamed', 'current_password' => 'operator-secret-pw', 'password' => 'a-new-strong-pw-12', 'password_confirmation' => 'a-new-strong-pw-12',
        ])->assertRedirect()->assertSessionHas('success');

        $admin->refresh();
        $this->assertSame('Admin Renamed', $admin->name);
        $this->assertTrue(Hash::check('a-new-strong-pw-12', $admin->password));
    }
}
