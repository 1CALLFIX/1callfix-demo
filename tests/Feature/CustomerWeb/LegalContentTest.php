<?php

namespace Tests\Feature\CustomerWeb;

use App\Models\ContentPage;
use App\Models\Faq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public legal/help content in the customer web app (Phase B).
 *
 * These pages render the SAME `content_pages` / `faqs` rows the existing
 * public API serves, under the same active-only rule. The important
 * assertions are the two the customer app must never get wrong: a draft
 * page must 404 exactly like a missing one (never leaking that it exists),
 * and admin-editable content must never be able to execute as HTML on a
 * public, unauthenticated page.
 */
class LegalContentTest extends TestCase
{
    use RefreshDatabase;

    private function seedPage(string $slug, string $title, string $content, bool $active = true): ContentPage
    {
        return ContentPage::create([
            'slug' => $slug,
            'title' => $title,
            'content' => $content,
            'is_active' => $active,
        ]);
    }

    public function test_the_privacy_policy_renders_the_real_stored_content(): void
    {
        $this->seedPage('privacy-policy', 'Privacy Policy', "# Privacy Policy\n\nWe value your privacy.");

        $this->get(route('customer.privacy'))
            ->assertOk()
            ->assertSeeText('Privacy Policy')
            ->assertSeeText('We value your privacy.');
    }

    public function test_the_terms_page_renders_the_real_stored_content(): void
    {
        $this->seedPage('terms-and-conditions', 'Terms of Use', "# Terms of Use\n\nThese are the terms.");

        $this->get(route('customer.terms'))
            ->assertOk()
            ->assertSeeText('Terms of Use')
            ->assertSeeText('These are the terms.');
    }

    public function test_markdown_structure_is_rendered_as_html(): void
    {
        $this->seedPage('privacy-policy', 'Privacy Policy', "## Section A\n\n- First point\n- Second point");

        $this->get(route('customer.privacy'))
            ->assertOk()
            // Demoted one level — the page template already owns the <h1>.
            ->assertSee('<h3>Section A</h3>', escape: false)
            ->assertSee('<li>First point</li>', escape: false);
    }

    /**
     * The real seeded documents repeat their own title as a markdown `#`
     * heading, which produced two <h1> elements on one page. Exactly one
     * top-level heading must survive.
     */
    public function test_the_page_has_exactly_one_top_level_heading(): void
    {
        $this->seedPage('privacy-policy', 'Privacy Policy', "# Privacy Policy\n\n## Section A\n\nBody text.");

        $html = $this->get(route('customer.privacy'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<h1'), 'A page must have exactly one <h1>.');
        // The document's own headings are still present, one level down.
        $this->assertStringContainsString('<h2>Privacy Policy</h2>', $html);
        $this->assertStringContainsString('<h3>Section A</h3>', $html);
    }

    /** Demotion must not silently lose the deepest heading level. */
    public function test_h6_headings_are_preserved(): void
    {
        $this->seedPage('privacy-policy', 'Privacy Policy', '###### Deepest');

        $this->get(route('customer.privacy'))
            ->assertOk()
            ->assertSee('<h6>Deepest</h6>', escape: false);
    }

    /**
     * `content_pages` is admin-editable through Cms\Manage and these pages
     * are public and unauthenticated, so raw HTML in the stored content
     * must render as visible text, never execute.
     */
    public function test_raw_html_in_stored_content_is_escaped_not_executed(): void
    {
        $this->seedPage('privacy-policy', 'Privacy Policy', 'Hello <script>alert(1)</script> world.');

        $response = $this->get(route('customer.privacy'))->assertOk();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $response->getContent());
        $response->assertSee('&lt;script&gt;', escape: false);
    }

    public function test_javascript_links_in_stored_content_are_not_emitted(): void
    {
        $this->seedPage('privacy-policy', 'Privacy Policy', '[click me](javascript:alert(1))');

        $response = $this->get(route('customer.privacy'))->assertOk();

        $this->assertStringNotContainsString('href="javascript:', $response->getContent());
    }

    /** A draft page must be indistinguishable from a missing one. */
    public function test_an_inactive_page_is_a_404(): void
    {
        $this->seedPage('privacy-policy', 'Privacy Policy', 'Draft content.', active: false);

        $this->get(route('customer.privacy'))->assertNotFound();
    }

    public function test_a_missing_page_is_a_404(): void
    {
        $this->get(route('customer.terms'))->assertNotFound();
    }

    // ============================ Help centre ============================

    public function test_the_help_centre_lists_active_faqs_grouped_by_category(): void
    {
        Faq::create(['category' => 'Payments', 'question' => 'How do I pay?', 'answer' => 'At the end of the job.', 'sort_order' => 1, 'is_active' => true]);
        Faq::create(['category' => 'Bookings', 'question' => 'Can I reschedule?', 'answer' => 'Yes, contact support.', 'sort_order' => 2, 'is_active' => true]);

        $this->get(route('customer.help'))
            ->assertOk()
            ->assertSeeText('Payments')
            ->assertSeeText('How do I pay?')
            ->assertSeeText('Bookings')
            ->assertSeeText('Can I reschedule?');
    }

    public function test_inactive_faqs_are_hidden(): void
    {
        Faq::create(['category' => 'General', 'question' => 'Retired question', 'answer' => 'Old answer.', 'sort_order' => 1, 'is_active' => false]);

        $this->get(route('customer.help'))
            ->assertOk()
            ->assertDontSeeText('Retired question');
    }

    public function test_the_help_centre_renders_with_no_faqs_at_all(): void
    {
        $this->get(route('customer.help'))
            ->assertOk()
            ->assertSeeText('No help articles yet');
    }

    /**
     * The real seeded documents (storage/legal-content/*.md) must actually
     * render through this page — the seeder deliberately refuses to
     * substitute a placeholder, so a missing source file surfaces here.
     */
    public function test_the_real_seeded_legal_documents_render(): void
    {
        $this->seed(\Database\Seeders\LegalContentSeeder::class);

        $this->get(route('customer.privacy'))->assertOk()->assertSeeText('Privacy Policy');
        $this->get(route('customer.terms'))->assertOk()->assertSeeText('Terms of Use');
    }
}
