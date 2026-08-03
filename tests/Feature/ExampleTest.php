<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    // The home route now renders the builder-driven marketing site, which reads
    // the site_pages/site_themes tables — so this smoke test needs a migrated DB.
    use RefreshDatabase;

    /**
     * A basic test example.
     *
     * `site:sync` stands in for the deploy step. The home controller used to
     * seed itself on every anonymous request; that write logic moved off the
     * public read path (see PublicSiteIsReadOnlyTest), so the content now has
     * to be put there the way production puts it there.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->artisan('site:sync')->assertSuccessful();

        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
