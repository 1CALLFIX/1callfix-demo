<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Cms\Manage;
use App\Models\ContentPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * REF 1CF-CMS-ROOT-PAGES-001 — CMS pages are served at 1callfix.com/{slug},
 * the admin controls footer links, and a page can never shadow a real route.
 */
class CmsRootPagesTest extends TestCase
{
    use RbacTestHelpers;
    use RefreshDatabase;

    private function page(string $slug, array $extra = []): ContentPage
    {
        return ContentPage::create($extra + ['slug' => $slug, 'title' => ucfirst($slug), 'content' => "Body of {$slug}.", 'is_active' => true]);
    }

    public function test_an_active_cms_page_is_served_at_the_site_root_with_its_search_description(): void
    {
        $this->page('franchise', ['title' => 'Franchise', 'meta_description' => 'Own a 1CallFix franchise in your city.']);

        $this->get('/franchise')
            ->assertOk()
            ->assertSeeText('Body of franchise.')
            ->assertSee('<meta name="description" content="Own a 1CallFix franchise in your city.">', false);
    }

    public function test_inactive_unknown_and_nested_addresses_are_404(): void
    {
        $this->page('draft-page', ['is_active' => false]);
        $this->page('franchise');

        $this->get('/draft-page')->assertNotFound();
        $this->get('/no-such-page')->assertNotFound();
        $this->get('/franchise/extra')->assertNotFound();
    }

    public function test_a_cms_page_can_never_shadow_a_real_route(): void
    {
        $this->page('services', ['content' => 'SHADOW ATTEMPT']);

        $this->get('/services')->assertOk()->assertDontSeeText('SHADOW ATTEMPT');
    }

    public function test_a_get_to_a_post_only_address_is_still_405_not_404(): void
    {
        $this->get('/logout')->assertStatus(405);
        $this->get('/api/webhooks/razorpay')->assertStatus(405);
        $this->get('/definitely-not-a-page')->assertNotFound();
    }

    public function test_admin_cannot_save_a_page_on_an_address_the_site_already_uses_but_can_on_a_free_one(): void
    {
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('pageSlug', 'services')->set('pageTitle', 'Services')
            ->call('savePage')
            ->assertHasErrors('pageSlug');
        $this->assertDatabaseMissing('content_pages', ['slug' => 'services']);

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('pageSlug', 'careers')->set('pageTitle', 'Careers')->set('pageContent', 'Call us.')
            ->set('pageShowInFooter', true)->set('pageFooterOrder', 3)->set('pageMetaDescription', 'Reach 1CallFix support.')
            ->call('savePage')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('content_pages', [
            'slug' => 'careers', 'show_in_footer' => 1, 'footer_order' => 3, 'meta_description' => 'Reach 1CallFix support.',
        ]);
        $this->get('/careers')->assertOk()->assertSeeText('Call us.');
    }

    public function test_editing_an_existing_page_that_sits_on_a_site_owned_address_still_works(): void
    {
        $privacy = $this->page('privacy', ['title' => 'Privacy Policy']);

        Livewire::actingAs($this->makeSuperAdmin())->test(Manage::class)
            ->call('editPage', $privacy->id)
            ->set('editPageTitle', 'Privacy Policy v2')
            ->call('updatePage')
            ->assertHasNoErrors();

        $this->assertSame('Privacy Policy v2', $privacy->fresh()->title);
    }

    public function test_footer_lists_only_active_pages_ticked_for_the_footer_in_order(): void
    {
        $this->page('second', ['title' => 'Second Page', 'show_in_footer' => true, 'footer_order' => 2]);
        $this->page('first', ['title' => 'First Page', 'show_in_footer' => true, 'footer_order' => 1]);
        $this->page('hidden-one', ['title' => 'Not Ticked', 'show_in_footer' => false]);
        $this->page('draft-one', ['title' => 'Draft Ticked', 'show_in_footer' => true, 'is_active' => false]);

        $footer = Blade::render('<x-customer.footer />');

        $this->assertStringContainsString('First Page', $footer);
        $this->assertStringContainsString('Second Page', $footer);
        $this->assertLessThan(strpos($footer, 'Second Page'), strpos($footer, 'First Page'));
        $this->assertStringNotContainsString('Not Ticked', $footer);
        $this->assertStringNotContainsString('Draft Ticked', $footer);
        $this->assertStringContainsString(url('/first'), $footer);
    }
}
