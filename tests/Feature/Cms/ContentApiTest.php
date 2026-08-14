<?php

namespace Tests\Feature\Cms;

use App\Models\Banner;
use App\Models\ContentPage;
use App\Models\Faq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * Coverage for the public content read API added in mission Phase 12
 * (CMS/content audit) — GET /api/pages/{slug}, GET /api/faqs,
 * GET /api/banners. Before this, content_pages/faqs/banners were fully
 * admin-manageable but had zero consumer anywhere in the codebase
 * (confirmed by a full-codebase read-site grep). Unauthenticated by
 * design, same as /auth/otp/*.
 */
class ContentApiTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    public function test_published_page_is_publicly_readable(): void
    {
        ContentPage::create(['slug' => 'privacy-policy', 'title' => 'Privacy Policy', 'content' => 'We respect your data.', 'is_active' => true]);

        $this->getJson('/api/pages/privacy-policy')
            ->assertOk()
            ->assertJsonPath('slug', 'privacy-policy')
            ->assertJsonPath('title', 'Privacy Policy')
            ->assertJsonPath('content', 'We respect your data.');
    }

    public function test_draft_page_returns_404_not_reachable_publicly(): void
    {
        ContentPage::create(['slug' => 'draft-page', 'title' => 'Draft', 'is_active' => false]);

        $this->getJson('/api/pages/draft-page')->assertStatus(404);
    }

    public function test_nonexistent_page_returns_404(): void
    {
        $this->getJson('/api/pages/does-not-exist')->assertStatus(404);
    }

    public function test_faqs_endpoint_returns_only_active_ones_in_order(): void
    {
        Faq::create(['question' => 'Second', 'answer' => 'A2', 'sort_order' => 2, 'is_active' => true]);
        Faq::create(['question' => 'First', 'answer' => 'A1', 'sort_order' => 1, 'is_active' => true]);
        Faq::create(['question' => 'Hidden', 'answer' => 'A3', 'sort_order' => 0, 'is_active' => false]);

        $response = $this->getJson('/api/faqs')->assertOk();
        $questions = collect($response->json('faqs'))->pluck('question')->all();

        $this->assertSame(['First', 'Second'], $questions);
    }

    public function test_faqs_endpoint_filters_by_category(): void
    {
        Faq::create(['category' => 'Billing', 'question' => 'Billing Q', 'answer' => 'A', 'sort_order' => 1, 'is_active' => true]);
        Faq::create(['category' => 'Bookings', 'question' => 'Booking Q', 'answer' => 'A', 'sort_order' => 2, 'is_active' => true]);

        $response = $this->getJson('/api/faqs?category=Billing')->assertOk();

        $this->assertCount(1, $response->json('faqs'));
        $this->assertSame('Billing Q', $response->json('faqs.0.question'));
    }

    public function test_banners_endpoint_requires_placement(): void
    {
        $this->getJson('/api/banners')->assertStatus(422);
    }

    public function test_banners_endpoint_returns_only_currently_live_banners_for_the_requested_placement(): void
    {
        Banner::create(['title' => 'Live Top', 'image' => 'banners/a.png', 'placement' => 'top', 'is_active' => true, 'sort_order' => 1]);
        Banner::create(['title' => 'Inactive Top', 'image' => 'banners/b.png', 'placement' => 'top', 'is_active' => false, 'sort_order' => 1]);
        Banner::create(['title' => 'Live Mid', 'image' => 'banners/c.png', 'placement' => 'mid', 'is_active' => true, 'sort_order' => 1]);
        Banner::create([
            'title' => 'Expired Top', 'image' => 'banners/d.png', 'placement' => 'top', 'is_active' => true, 'sort_order' => 1,
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->getJson('/api/banners?placement=top')->assertOk();
        $titles = collect($response->json('banners'))->pluck('title')->all();

        $this->assertSame(['Live Top'], $titles);
    }

    public function test_banners_endpoint_respects_franchise_targeting(): void
    {
        $franchise = $this->makeFranchise();
        Banner::create(['title' => 'Targeted', 'image' => 'banners/e.png', 'placement' => 'top', 'is_active' => true, 'sort_order' => 1, 'franchise_id' => $franchise->id]);
        Banner::create(['title' => 'Global', 'image' => 'banners/f.png', 'placement' => 'top', 'is_active' => true, 'sort_order' => 2]);

        $response = $this->getJson("/api/banners?placement=top&franchise_id={$franchise->id}")->assertOk();
        $titles = collect($response->json('banners'))->pluck('title')->all();

        // Both should show — the targeted one (matches) and the global one
        // (wildcard) — but a DIFFERENT franchise's request must not see the
        // targeted banner.
        sort($titles);
        $this->assertSame(['Global', 'Targeted'], $titles);

        $otherFranchise = $this->makeFranchise();
        $otherResponse = $this->getJson("/api/banners?placement=top&franchise_id={$otherFranchise->id}")->assertOk();
        $this->assertSame(['Global'], collect($otherResponse->json('banners'))->pluck('title')->all());
    }
}
