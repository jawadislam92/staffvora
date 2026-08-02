<?php

namespace Tests\Feature;

use App\Models\SitePage;
use App\Support\DefaultSiteContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicSiteReleaseGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_placeholder_pages_are_created_as_private_drafts(): void
    {
        DefaultSiteContent::ensure();

        $this->assertDatabaseHas('site_pages', [
            'slug' => 'home',
            'status' => 'published',
        ]);
        $this->assertDatabaseHas('site_pages', [
            'slug' => 'terms',
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('site_pages', [
            'slug' => 'privacy',
            'status' => 'draft',
        ]);

        $this->get('/terms')->assertNotFound();
        $this->get('/privacy')->assertNotFound();
    }

    public function test_an_old_published_placeholder_page_is_taken_offline_without_losing_its_draft(): void
    {
        DefaultSiteContent::ensure();

        $page = SitePage::where('slug', 'terms')->firstOrFail();
        $placeholderBlocks = [[
            'id' => 'legacy-terms',
            'type' => 'rich_text',
            'data' => ['html' => '<p>[REPLACE: legal review required]</p>'],
        ]];
        $page->update([
            'status' => 'published',
            'blocks' => $placeholderBlocks,
            'published_blocks' => $placeholderBlocks,
        ]);

        DefaultSiteContent::ensure();

        $page->refresh();
        $this->assertSame('draft', $page->status);
        $this->assertSame($placeholderBlocks, $page->blocks);
        $this->assertSame($placeholderBlocks, $page->published_blocks);
    }
}
