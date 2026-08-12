<?php

namespace Tests\Feature\Rbac;

use App\Livewire\Cms\Manage;
use App\Models\ContentPage;
use App\Models\Faq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * cms.manage was seeded but never checked anywhere (Slice 2 finding #5).
 * content_pages/faqs carry no franchise column — global-only.
 */
class CmsAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    public function test_user_without_permission_cannot_create_page(): void
    {
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('pageSlug', 'blocked-page')
            ->set('pageTitle', 'Blocked Page')
            ->call('savePage')
            ->assertHasErrors(['permission']);

        $this->assertDatabaseMissing('content_pages', ['slug' => 'blocked-page']);
    }

    public function test_global_scoped_grant_can_create_page(): void
    {
        $actor = $this->makeUserWithPermission('cms.manage', 'global');

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('pageSlug', 'allowed-page')
            ->set('pageTitle', 'Allowed Page')
            ->call('savePage')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('content_pages', ['slug' => 'allowed-page']);
    }

    public function test_franchise_scoped_grant_does_not_cover_cms(): void
    {
        $franchise = $this->makeFranchise();
        $actor = $this->makeUserWithPermission('cms.manage', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('pageSlug', 'franchise-page')
            ->set('pageTitle', 'Franchise Page')
            ->call('savePage')
            ->assertHasErrors(['permission']);
    }

    public function test_faq_create_denied_without_permission(): void
    {
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('faqQuestion', 'Blocked question?')
            ->set('faqAnswer', 'Blocked answer.')
            ->call('saveFaq')
            ->assertHasErrors(['permission']);

        $this->assertDatabaseMissing('faqs', ['question' => 'Blocked question?']);
    }

    public function test_faq_delete_denied_without_permission(): void
    {
        $faq = Faq::create(['question' => 'Q', 'answer' => 'A', 'sort_order' => 1, 'is_active' => true]);
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('confirmingDeleteFaqId', $faq->id)
            ->call('deleteFaq')
            ->assertHasErrors(['permission']);

        $this->assertDatabaseHas('faqs', ['id' => $faq->id]);
    }

    public function test_page_delete_denied_without_permission(): void
    {
        $page = ContentPage::create(['slug' => 'existing-page', 'title' => 'Existing Page']);
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(Manage::class)
            ->set('confirmingDeletePageId', $page->id)
            ->call('deletePage')
            ->assertHasErrors(['permission']);

        $this->assertDatabaseHas('content_pages', ['id' => $page->id]);
    }
}
