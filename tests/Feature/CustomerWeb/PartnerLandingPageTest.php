<?php

namespace Tests\Feature\CustomerWeb;

use App\Models\PartnerBenefit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public "For professionals" landing page (route customer.partners,
 * URL /coming-soon/partners). Replaces the old coming-soon placeholder for
 * that one key: the benefits grid is driven by the admin-managed
 * `partner_benefits` table, and every CTA points at /provider/register.
 */
class PartnerLandingPageTest extends TestCase
{
    use RefreshDatabase;

    /** Start from a known-empty table (the create migration seeds four rows). */
    protected function setUp(): void
    {
        parent::setUp();
        PartnerBenefit::query()->delete();
    }

    public function test_the_page_renders_for_a_guest(): void
    {
        $this->get(route('customer.partners'))
            ->assertOk()
            ->assertSeeText('For professionals')
            ->assertSee(route('provider.register'));
    }

    public function test_the_legacy_coming_soon_url_now_serves_the_real_page(): void
    {
        $this->get('/coming-soon/partners')
            ->assertOk()
            ->assertSeeText('How to get started');
    }

    public function test_partners_is_no_longer_a_coming_soon_placeholder_key(): void
    {
        $this->assertNotContains(
            'partners',
            \App\Http\Controllers\Customer\PageController::COMING_SOON_FEATURES,
        );
    }

    public function test_active_benefits_are_shown_and_inactive_are_hidden(): void
    {
        PartnerBenefit::create([
            'icon' => 'wallet', 'title' => 'Shown benefit',
            'description' => 'This one is active.', 'sort_order' => 1, 'is_active' => true,
        ]);
        PartnerBenefit::create([
            'icon' => 'clock', 'title' => 'Hidden benefit',
            'description' => 'This one is inactive.', 'sort_order' => 2, 'is_active' => false,
        ]);

        $this->get(route('customer.partners'))
            ->assertOk()
            ->assertSeeText('Shown benefit')
            ->assertDontSeeText('Hidden benefit');
    }

    public function test_benefits_render_in_sort_order(): void
    {
        PartnerBenefit::create(['icon' => 'wallet', 'title' => 'Second benefit', 'description' => 'b', 'sort_order' => 20, 'is_active' => true]);
        PartnerBenefit::create(['icon' => 'clock', 'title' => 'First benefit', 'description' => 'a', 'sort_order' => 10, 'is_active' => true]);

        $html = $this->get(route('customer.partners'))->assertOk()->getContent();

        $this->assertLessThan(
            strpos($html, 'Second benefit'),
            strpos($html, 'First benefit'),
        );
    }

    public function test_the_page_still_renders_with_no_benefit_rows(): void
    {
        PartnerBenefit::query()->delete();

        $this->get(route('customer.partners'))
            ->assertOk()
            ->assertSee(route('provider.register'));
    }

    public function test_the_footer_links_to_the_partner_page(): void
    {
        $this->get(route('customer.home'))
            ->assertOk()
            ->assertSee(route('customer.partners'))
            ->assertSeeText('Join as a Partner');
    }
}
