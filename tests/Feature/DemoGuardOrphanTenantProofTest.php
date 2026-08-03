<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * INVERTED AUDIT PROOF — an anonymous signup on a reserved demo address must be
 * a clean refusal that leaves nothing behind.
 *
 * The finding: the demo-namespace model guard fires INSIDE
 * TenantProvisioner::provision(), which was not transactional. Public signup
 * with a reserved demo address therefore committed the Organization row and
 * then threw — a 500 from an anonymous request that left an owner-less tenant
 * the operator console listed as a real customer. Register also had no
 * reservedEmailRule (UserController / Onboarding both did), so validation never
 * caught it first.
 *
 * Both halves are now closed, and this test pins the OUTCOME rather than either
 * mechanism, so it keeps its meaning whichever layer answers first:
 *   - RegisteredUserController carries User::reservedEmailRule(), so the
 *     visitor gets a form error instead of an exception; and
 *   - provision() is wrapped in a transaction, so ANY mid-flight failure —
 *     this guard, a unique-key clash, anything future — rolls the whole tenant
 *     back. That guarantee is asserted directly at the provisioner in
 *     tests/Feature/TenantProvisioningIsAtomicTest.php.
 *
 * The AUTHENTICATED half of this finding (the profile e-mail form, which had
 * the same missing rule and the same 500) is fixed: ProfileUpdateRequest now
 * carries User::reservedEmailRule(), and the regression lives in
 * DemoIdentitiesAreImmutableTest::test_every_self_service_email_field_refuses_the_reserved_namespace.
 */
class DemoGuardOrphanTenantProofTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_anonymous_signup_with_a_reserved_demo_address_leaves_nothing_behind(): void
    {
        $this->assertSame(0, Organization::count());

        $response = $this->post(route('register'), [
            'company' => 'Orphan Co',
            'name' => 'Attacker',
            'email' => 'demo-1-1@demo.staffvora.invalid',
            'password' => 'a-very-long-password-1',
            'password_confirmation' => 'a-very-long-password-1',
        ]);

        $this->assertLessThan(500, $response->getStatusCode(), 'an anonymous signup produced a server error');

        // The damage the audit found — none of it may survive.
        $this->assertSame(0, Organization::count(), 'a half-provisioned organization was committed');
        $this->assertSame(0, User::withoutGlobalScopes()->count(), 'a half-provisioned user was committed');
    }
}
