<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PROVISIONING A TENANT IS ALL-OR-NOTHING, AND CHEAP.
 *
 * Two anonymous-reachable defects, both on the public signup path:
 *
 *  1. provision() wrote the Organization, then the User, then stamped the org's
 *     owner_user_id. Anything that threw in between COMMITTED the organization
 *     and abandoned it — a tenant with owner_user_id = NULL that the operator
 *     console then listed as a real customer, created by an unauthenticated
 *     request that returned a 500.
 *
 *  2. uniqueSlug() walked `base-2`, `base-3`, `base-4`… one EXISTS query per
 *     step. Flooding signups with a single company name therefore cost the
 *     database O(n²) work in total.
 */
class TenantProvisioningIsAtomicTest extends TestCase
{
    use RefreshDatabase;

    private function provisioner(): TenantProvisioner
    {
        return app(TenantProvisioner::class);
    }

    /**
     * The model guard on the reserved demo namespace throws from deep inside
     * provisioning — after the organization row exists. This is the exact shape
     * the audit reproduced through an anonymous POST /register.
     */
    public function test_a_guard_firing_midway_leaves_no_orphan_organization(): void
    {
        try {
            $this->provisioner()->provision(
                'Orphan Co', 'Attacker', 'demo-1-1@'.User::DEMO_EMAIL_DOMAIN, 'a-very-long-password-1',
            );
            $this->fail('provisioning into the reserved demo namespace should have thrown');
        } catch (\Throwable) {
            // expected — the point is what is left behind.
        }

        $this->assertSame(0, Organization::count(), 'a half-provisioned organization was committed');
        $this->assertSame(0, User::withoutGlobalScopes()->count(), 'a half-provisioned user was committed');
    }

    /** The same shape from a plain database failure rather than a guard. */
    public function test_a_duplicate_owner_email_leaves_no_orphan_organization(): void
    {
        $this->provisioner()->provision('First Co', 'Owner', 'owner@example.test', 'a-very-long-password-1');

        $this->assertSame(1, Organization::count());

        try {
            $this->provisioner()->provision('Second Co', 'Other', 'owner@example.test', 'a-very-long-password-2');
            $this->fail('a duplicate owner e-mail should have thrown');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(1, Organization::count(), 'the failed signup committed an organization anyway');
        $this->assertSame(
            0,
            Organization::whereNull('owner_user_id')->count(),
            'an owner-less tenant survived a failed signup',
        );
    }

    /** The happy path is untouched: a successful signup still commits. */
    public function test_a_successful_signup_still_commits_everything(): void
    {
        $result = $this->provisioner()->provision(
            'Acme Co', 'Owner', 'owner@acme.test', 'a-very-long-password-1', null, 14, 'general',
        );

        $org = $result['organization'];

        $this->assertSame(1, Organization::count());
        $this->assertSame($result['owner']->id, $org->owner_user_id);
        $this->assertSame('acme-co', $org->slug);
        $this->assertDatabaseHas('users', ['email' => 'owner@acme.test', 'role' => 'super_admin']);
    }

    /**
     * The flood: one company name, many signups. The slug resolver must not
     * grow a query per existing workspace.
     */
    public function test_unique_slug_does_not_grow_a_query_per_existing_workspace(): void
    {
        foreach (array_merge(['acme'], array_map(fn ($i) => 'acme-'.$i, range(2, 60))) as $slug) {
            Organization::create([
                'name' => 'Acme',
                'slug' => $slug,
                'status' => Organization::STATUS_TRIAL,
            ]);
        }

        DB::enableQueryLog();
        $slug = $this->provisioner()->uniqueSlug('Acme');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            4,
            count($queries),
            'uniqueSlug() is still walking one query per existing workspace: '
                .implode(' | ', array_column($queries, 'query')),
        );

        $this->assertSame(0, Organization::where('slug', $slug)->count(), "'{$slug}' is already taken");
        $this->assertStringStartsWith('acme-', $slug);
    }

    /** Correctness is not traded away for speed: repeated names stay unique. */
    public function test_repeated_company_names_still_get_unique_slugs(): void
    {
        $slugs = [];

        for ($i = 0; $i < 12; $i++) {
            $slugs[] = $this->provisioner()->provision(
                'Acme', 'Owner '.$i, "owner{$i}@acme.test", 'a-very-long-password-1',
            )['organization']->slug;
        }

        $this->assertSame($slugs, array_values(array_unique($slugs)), 'two workspaces share a slug');
        $this->assertSame('acme', $slugs[0]);
    }

    /** A slug prefix that merely collides must not break provisioning. */
    public function test_unrelated_prefix_collisions_do_not_break_the_slug_resolver(): void
    {
        foreach (['acme', 'acme-holdings', 'acme-2', 'acme-widgets'] as $slug) {
            Organization::create(['name' => 'x', 'slug' => $slug, 'status' => Organization::STATUS_TRIAL]);
        }

        $slug = $this->provisioner()->uniqueSlug('Acme');

        $this->assertSame(0, Organization::where('slug', $slug)->count());
    }

    /** A very long company name cannot produce a slug the column will not hold. */
    public function test_a_very_long_company_name_produces_a_storable_slug(): void
    {
        $slug = $this->provisioner()->uniqueSlug(str_repeat('Extremely Long Company Name ', 40));

        $this->assertLessThanOrEqual(255, strlen($slug));
        $this->assertNotSame('', $slug);
    }
}
