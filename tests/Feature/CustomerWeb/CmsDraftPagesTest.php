<?php

namespace Tests\Feature\CustomerWeb;

use App\Models\ContentPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The About, Contact and Refund policy pages exist as empty drafts: invisible (404) until the owner writes content and
 * switches them on, then served at the site root and linked from the footer.
 */
class CmsDraftPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_three_drafts_exist_but_are_not_public(): void
    {
        foreach (['about', 'contact', 'refund-policy'] as $slug) {
            $page = ContentPage::where('slug', $slug)->first();
            $this->assertNotNull($page, "{$slug} draft is missing");
            $this->assertFalse($page->is_active);
            $this->assertTrue($page->show_in_footer);
            $this->get('/'.$slug)->assertNotFound();
        }
    }

    public function test_switching_a_draft_on_publishes_it(): void
    {
        ContentPage::where('slug', 'about')->update(['is_active' => true, 'content' => 'We are 1CallFix.']);

        $this->get('/about')->assertOk()->assertSee('We are 1CallFix.');
    }
}
