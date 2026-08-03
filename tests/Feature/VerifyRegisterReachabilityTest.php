<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * REACHABILITY VERIFICATION ONLY (adversarial verifier scratch test).
 * Proves the anonymous HTTP path POST /register actually provisions a tenant,
 * and that repeating the same company name walks the uniqueSlug chain.
 */
class VerifyRegisterReachabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_http_post_register_provisions_tenants_and_walks_slug_chain(): void
    {
        // 1. Confirm the route's middleware is exactly what the finding claims.
        $route = collect(Route::getRoutes()->getRoutes())->first(
            fn ($r) => $r->uri() === 'register' && in_array('POST', $r->methods(), true)
        );
        $this->assertNotNull($route, 'POST /register is not registered');
        $mw = $route->gatherMiddleware();
        $this->assertContains('guest', $mw);
        $this->assertContains('throttle:6,1', $mw);
        $this->assertNotContains('auth', $mw);
        $this->assertNotContains('verified', $mw);
        $this->assertNull($route->getDomain(), 'register is host-constrained');

        // 2. Drive it anonymously over HTTP three times with the SAME company.
        for ($i = 1; $i <= 3; $i++) {
            $res = $this->post('/register', [
                'company' => 'Acme',
                'name' => 'Attacker',
                'email' => "flood{$i}@throwaway.test",
                'password' => 'Aaaaaaaaaaaa1!',
                'password_confirmation' => 'Aaaaaaaaaaaa1!',
            ]);
            $res->assertRedirect(route('onboarding.show'));
            $this->post('/logout');
        }

        $slugs = Organization::where('name', 'Acme')->pluck('slug')->sort()->values()->all();
        $this->assertSame(['acme', 'acme-2', 'acme-3'], $slugs);

        // 3. Every one of them is a live TRIAL workspace with a subscription.
        $this->assertSame(3, Organization::where('name', 'Acme')
            ->where('status', Organization::STATUS_TRIAL)->count());
    }
}
