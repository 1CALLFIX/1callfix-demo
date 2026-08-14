<?php

namespace Tests\Feature\Cms;

use App\Livewire\Cms\Manage;
use App\Models\ContentPage;
use App\Models\Faq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * Functional coverage for Cms\Manage (mission Phase 12 — CMS/content
 * audit). CmsAuthorizationTest already covers the permission-gate paths;
 * this file covers the write flows themselves, which had zero coverage
 * before this session: updatePage/editFaq/updateFaq/toggleFaqActive, the
 * successful (permitted) delete paths for both pages and FAQs, slug
 * uniqueness/validation, and the new is_active/published toggle on pages
 * added alongside the public GET /api/pages/{slug} read path (see
 * ContentApiTest) — a page must be able to exist as a draft without being
 * publicly reachable the instant it's saved.
 */
class CmsManageTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    private function actor()
    {
        return $this->makeUserWithPermission('cms.manage', 'global');
    }

    public function test_new_page_defaults_to_published(): void
    {
        Livewire::actingAs($this->actor())->test(Manage::class)
            ->set('pageSlug', 'about-us')
            ->set('pageTitle', 'About Us')
            ->set('pageContent', 'We fix things.')
            ->call('savePage')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('content_pages', ['slug' => 'about-us', 'is_active' => 1]);
    }

    public function test_page_can_be_created_as_a_draft(): void
    {
        Livewire::actingAs($this->actor())->test(Manage::class)
            ->set('pageSlug', 'draft-page')
            ->set('pageTitle', 'Draft Page')
            ->set('pageIsActive', false)
            ->call('savePage')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('content_pages', ['slug' => 'draft-page', 'is_active' => 0]);
    }

    public function test_duplicate_slug_is_rejected(): void
    {
        ContentPage::create(['slug' => 'faq-page', 'title' => 'Existing']);

        Livewire::actingAs($this->actor())->test(Manage::class)
            ->set('pageSlug', 'faq-page')
            ->set('pageTitle', 'New Page')
            ->call('savePage')
            ->assertHasErrors(['pageSlug']);

        $this->assertSame(1, ContentPage::where('slug', 'faq-page')->count());
    }

    public function test_slug_must_be_alpha_dash(): void
    {
        Livewire::actingAs($this->actor())->test(Manage::class)
            ->set('pageSlug', 'not a valid slug!')
            ->set('pageTitle', 'Bad Slug Page')
            ->call('savePage')
            ->assertHasErrors(['pageSlug']);

        $this->assertDatabaseMissing('content_pages', ['title' => 'Bad Slug Page']);
    }

    public function test_update_page_changes_fields_and_allows_reusing_its_own_slug(): void
    {
        $page = ContentPage::create(['slug' => 'terms', 'title' => 'Terms', 'is_active' => true]);

        Livewire::actingAs($this->actor())->test(Manage::class)
            ->call('editPage', $page->id)
            ->set('editPageSlug', 'terms') // unchanged — must not trip the unique rule against itself
            ->set('editPageTitle', 'Terms & Conditions')
            ->set('editPageContent', 'Updated body.')
            ->set('editPageIsActive', false)
            ->call('updatePage')
            ->assertHasNoErrors();

        $page->refresh();
        $this->assertSame('Terms & Conditions', $page->title);
        $this->assertSame('Updated body.', $page->content);
        $this->assertFalse($page->is_active);
    }

    public function test_toggle_page_active_flips_published_state(): void
    {
        $page = ContentPage::create(['slug' => 'toggle-me', 'title' => 'T', 'is_active' => true]);

        Livewire::actingAs($this->actor())->test(Manage::class)->call('togglePageActive', $page->id);

        $this->assertFalse($page->fresh()->is_active);
    }

    public function test_delete_page_removes_it_when_permitted(): void
    {
        $page = ContentPage::create(['slug' => 'delete-me', 'title' => 'D']);

        Livewire::actingAs($this->actor())->test(Manage::class)
            ->set('confirmingDeletePageId', $page->id)
            ->call('deletePage');

        $this->assertDatabaseMissing('content_pages', ['id' => $page->id]);
    }

    public function test_update_faq_changes_fields(): void
    {
        $faq = Faq::create(['question' => 'Q1', 'answer' => 'A1', 'sort_order' => 1, 'is_active' => true]);

        Livewire::actingAs($this->actor())->test(Manage::class)
            ->call('editFaq', $faq->id)
            ->set('editFaqQuestion', 'Updated question?')
            ->set('editFaqAnswer', 'Updated answer.')
            ->set('editFaqCategory', 'Billing')
            ->call('updateFaq')
            ->assertHasNoErrors();

        $faq->refresh();
        $this->assertSame('Updated question?', $faq->question);
        $this->assertSame('Billing', $faq->category);
    }

    public function test_toggle_faq_active_flips_state(): void
    {
        $faq = Faq::create(['question' => 'Q', 'answer' => 'A', 'sort_order' => 1, 'is_active' => true]);

        Livewire::actingAs($this->actor())->test(Manage::class)->call('toggleFaqActive', $faq->id);

        $this->assertFalse($faq->fresh()->is_active);
    }

    public function test_delete_faq_removes_it_when_permitted(): void
    {
        $faq = Faq::create(['question' => 'Q', 'answer' => 'A', 'sort_order' => 1, 'is_active' => true]);

        Livewire::actingAs($this->actor())->test(Manage::class)
            ->set('confirmingDeleteFaqId', $faq->id)
            ->call('deleteFaq');

        $this->assertDatabaseMissing('faqs', ['id' => $faq->id]);
    }
}
