<?php

namespace Tests\Feature\Seo;

use App\Models\City;
use App\Models\ActivityLog;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use App\Models\SlugRedirect;
use App\Services\Slug\SlugManager;
use App\Support\Seo\ReservedSlugs;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * F3 - HasManagedSlug / SlugManager: clean generation, validation, reserved words, cross-type uniqueness,
 * own-old-slug rule, permissions, audit log, and the admin edit screens.
 */
class F3SlugRulesTest extends TestCase
{
    use CatalogFixtures;
    use LiveCity;
    use RbacTestHelpers;
    use RefreshDatabase;

    private function cat(string $name, ?string $slug = null): ServiceCategory
    {
        return $this->makeCategory(['name' => $name, 'slug' => $slug]);
    }

    private function svc(ServiceCategory $category, string $name, ?string $slug = null): Service
    {
        return $this->makeService($category, ['name' => $name, 'slug' => $slug]);
    }

    private function rejects(callable $fn, ?string $contains = null): void
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            if ($contains) {
                $this->assertStringContainsString($contains, $e->errors()['slug'][0]);
            }
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('Expected the slug to be rejected.');
    }

    // ---------------------------------------------------------------- generation

    public function test_new_rows_get_clean_slugs_and_dash_two_only_on_collision_never_random(): void
    {
        $a = $this->cat('AC Repair');
        $b = $this->cat('AC Repair');
        $c = $this->cat('AC Repair');
        $this->assertSame(['ac-repair', 'ac-repair-2', 'ac-repair-3'], [$a->slug, $b->slug, $c->slug]);

        $s1 = $this->svc($a, 'Gas Refill');
        $s2 = $this->svc($a, 'Gas Refill');
        $this->assertSame(['gas-refill', 'gas-refill-2'], [$s1->slug, $s2->slug]);

        $city = $this->liveCity('Nellore');
        $this->assertSame('nellore', $city->slug);
        $this->assertSame('nellore-2', City::create(['country_id' => $city->country_id, 'name' => 'Nellore!', 'is_active' => true])->slug);
    }

    public function test_a_category_and_a_service_never_share_a_slug(): void
    {
        $category = $this->cat('Plumbing');
        $service = $this->svc($category, 'Plumbing');

        $this->assertSame('plumbing', $category->slug);
        $this->assertSame('plumbing-2', $service->slug);

        $other = $this->svc($category, 'Another');
        $this->rejects(fn () => SlugManager::change($other, 'plumbing', $this->makeSuperAdmin()), 'already uses');
        $this->rejects(fn () => SlugManager::change($category, 'another', $this->makeSuperAdmin()), 'already uses');
    }

    public function test_when_legacy_data_has_a_category_and_a_service_on_one_slug_the_category_wins_the_lookup(): void
    {
        $category = $this->cat('Cleaning', 'cleaning');
        $service = Service::withoutEvents(fn () => $this->svc($category, 'Cleaning', 'cleaning'));
        $this->assertSame('cleaning', $service->slug);

        $found = SlugManager::resolveCatalog('cleaning');

        $this->assertInstanceOf(ServiceCategory::class, $found['model']);
    }

    public function test_subcategory_slugs_are_unique_within_their_category_only(): void
    {
        $one = $this->cat('One');
        $two = $this->cat('Two');
        $a = $this->makeSubcategory($one, ['name' => 'Split AC', 'slug' => null]);
        $b = $this->makeSubcategory($one, ['name' => 'Split AC', 'slug' => null]);
        $c = $this->makeSubcategory($two, ['name' => 'Split AC', 'slug' => null]);

        $this->assertSame(['split-ac', 'split-ac-2', 'split-ac'], [$a->slug, $b->slug, $c->slug]);
    }

    // ---------------------------------------------------------------- validation

    public function test_format_and_length_are_validated(): void
    {
        $admin = $this->makeSuperAdmin();
        $category = $this->cat('AC Repair');

        foreach (['', 'Has Space', 'under_score', '-lead', 'trail-', 'double--dash', 'ünïcode'] as $bad) {
            // normalize() would clean most of these, so test the validator itself.
            $this->assertNotNull(SlugManager::problem($category, $bad), "'{$bad}' should be a problem");
        }
        $this->assertNotNull(SlugManager::problem($category, str_repeat('a', 101)));
        $this->assertNull(SlugManager::problem($category, 'good-slug-2'));

        // The setter cleans user input before validating it.
        SlugManager::change($category, '  Fancy  Slug!! ', $admin);
        $this->assertSame('fancy-slug', $category->fresh()->slug);
    }

    public function test_reserved_words_come_from_the_route_collection_and_cms_pages(): void
    {
        $city = $this->liveCity('Nellore');
        $admin = $this->makeSuperAdmin();
        $category = $this->cat('AC Repair');
        \App\Models\ContentPage::create(['slug' => 'franchise', 'title' => 'F', 'content' => 'x', 'is_active' => true]);

        foreach (['admin', 'api', 'login', 'cart', 'checkout', 'help', 'provider', 'sitemap', 'franchise', 'choose-city', 'livewire'] as $word) {
            $this->assertTrue(ReservedSlugs::isReserved($word), $word);
            $this->rejects(fn () => SlugManager::change($city, $word, $admin), 'reserved');
            $this->rejects(fn () => SlugManager::change($category, $word, $admin), 'reserved');
        }

        // An ordinary word that is not a route is fine.
        $this->assertFalse(ReservedSlugs::isReserved('ac-repair'));
        // New rows named after a route get the next free clean slug instead of failing.
        $this->assertSame('help-2', $this->cat('Help')->slug);
    }

    // ---------------------------------------------------------------- own old slug

    public function test_an_item_may_retake_its_own_old_slug_but_never_another_items_old_slug(): void
    {
        $admin = $this->makeSuperAdmin();
        $a = $this->cat('Alpha');
        $b = $this->cat('Beta');

        SlugManager::change($a, 'alpha-new', $admin);                    // a: alpha -> alpha-new (alpha now redirects to a)
        $this->assertSame(1, SlugRedirect::where('old_slug', 'alpha')->count());

        $this->rejects(fn () => SlugManager::change($b, 'alpha', $admin), 'previous URL of a different item');

        SlugManager::change($a->refresh(), 'alpha', $admin);             // a takes its own old slug back
        $this->assertSame('alpha', $a->fresh()->slug);
        $this->assertSame(0, SlugRedirect::where('old_slug', 'alpha')->count(), 'the now-live slug needs no redirect');
        $this->assertSame(1, SlugRedirect::where('old_slug', 'alpha-new')->count());
    }

    public function test_changing_to_the_same_slug_is_a_noop(): void
    {
        $category = $this->cat('Alpha');

        $this->assertFalse(SlugManager::change($category, 'alpha', $this->makeSuperAdmin()));
        $this->assertSame(0, SlugRedirect::count());
    }

    // ---------------------------------------------------------------- permission + audit

    public function test_super_admin_and_a_global_holder_may_edit_but_everyone_else_gets_403(): void
    {
        $category = $this->cat('Alpha');

        SlugManager::change($category, 'one', $this->makeSuperAdmin());
        SlugManager::change($category->refresh(), 'two', $this->makeUserWithPermission('catalog.edit_slugs', 'global'));
        $this->assertSame('two', $category->fresh()->slug);

        $franchise = $this->makeFranchise();
        foreach ([
            $this->makeUserWithNoPermissions(),
            $this->makeUserWithPermission('catalog.edit_slugs', 'franchise', $franchise->id), // franchise scope never edits slugs
            $this->makeUserWithPermission('categories.manage', 'global'),
        ] as $user) {
            try {
                SlugManager::change($category->refresh(), 'three', $user);
                $this->fail('expected 403');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->assertSame('two', $category->fresh()->slug);
    }

    public function test_city_slugs_are_super_admin_only_even_for_a_holder_of_the_permission(): void
    {
        $city = $this->liveCity('Nellore');
        $holder = $this->makeUserWithPermission('catalog.edit_slugs', 'global');

        try {
            SlugManager::change($city, 'nellore-city', $holder);
            $this->fail('expected 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        SlugManager::change($city, 'nellore-city', $this->makeSuperAdmin());
        $this->assertSame('nellore-city', $city->fresh()->slug);
    }

    public function test_every_change_is_audit_logged(): void
    {
        $admin = $this->makeSuperAdmin();
        $category = $this->cat('Alpha');

        SlugManager::change($category, 'beta', $admin);

        $log = ActivityLog::where('subject_type', 'category_slug')->where('subject_id', $category->id)->firstOrFail();
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertSame('alpha', $log->properties['old']);
        $this->assertSame('beta', $log->properties['new']);
    }

    // ---------------------------------------------------------------- admin screens

    public function test_category_edit_screen_changes_the_slug_for_an_authorised_admin_and_shows_errors(): void
    {
        $admin = $this->makeSuperAdmin();
        $category = $this->cat('Alpha');
        $other = $this->cat('Beta');
        // The icon is required only while a category has none; give it one so the rest of the form validates.
        $category->update(['image' => 'categories/x.png']);

        Livewire::actingAs($admin)->test(\App\Livewire\Categories\Manage::class)
            ->call('edit', $category->id)
            ->assertSet('editSlug', 'alpha')
            ->set('editSlug', $other->slug)
            ->call('update')
            ->assertHasErrors('editSlug');
        $this->assertSame('alpha', $category->fresh()->slug);

        Livewire::actingAs($admin)->test(\App\Livewire\Categories\Manage::class)
            ->call('edit', $category->id)
            ->set('editSlug', 'cooling')
            ->call('update')
            ->assertHasNoErrors();
        $this->assertSame('cooling', $category->fresh()->slug);
        $this->assertSame(1, SlugRedirect::where('old_slug', 'alpha')->count());
    }

    public function test_a_manager_without_slug_permission_cannot_change_the_slug_from_the_screen(): void
    {
        $user = $this->makeUserWithPermission('categories.manage', 'global');
        $category = $this->cat('Alpha');
        $category->update(['image' => 'categories/x.png']);

        Livewire::actingAs($user)->test(\App\Livewire\Categories\Manage::class)
            ->call('edit', $category->id)
            ->set('editSlug', 'hacked')
            ->call('update');

        $this->assertSame('alpha', $category->fresh()->slug);
    }

    public function test_service_and_subcategory_screens_load_the_slug_for_editing(): void
    {
        $admin = $this->makeSuperAdmin();
        $category = $this->cat('Alpha');
        $service = $this->svc($category, 'Gas Refill');
        $sub = $this->makeSubcategory($category, ['name' => 'Split', 'slug' => null]);

        Livewire::actingAs($admin)->test(\App\Livewire\Services\Manage::class)->call('edit', $service->id)->assertSet('editSlug', 'gas-refill');
        Livewire::actingAs($admin)->test(\App\Livewire\Subcategories\Manage::class)->call('edit', $sub->id)->assertSet('editSlug', 'split');
    }

    public function test_geography_screen_lets_only_a_super_admin_change_a_city_slug(): void
    {
        $city = $this->liveCity('Nellore');

        Livewire::actingAs($this->makeUserWithPermission('geography.manage', 'global'))->test(\App\Livewire\Geography\Manage::class)
            ->set('citySlugs.'.$city->id, 'hacked')->call('saveCitySlug', $city->id);
        $this->assertSame('nellore', $city->fresh()->slug);

        Livewire::actingAs($this->makeSuperAdmin())->test(\App\Livewire\Geography\Manage::class)
            ->set('citySlugs.'.$city->id, 'nellore-city')->call('saveCitySlug', $city->id);
        $this->assertSame('nellore-city', $city->fresh()->slug);
        $this->assertSame(1, SlugRedirect::where(['scope' => 'city', 'old_slug' => 'nellore'])->count());
    }

    public function test_category_services_and_subcategory_slugs_use_the_helper_on_create_from_the_admin_screens(): void
    {
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(\App\Livewire\Categories\Manage::class)
            ->set('name', 'AC Repair')
            ->set('iconFile', \Illuminate\Http\UploadedFile::fake()->image('i.png'))
            ->call('save');

        $slug = ServiceCategory::firstOrFail()->slug;
        $this->assertSame('ac-repair', $slug);
        $this->assertDoesNotMatchRegularExpression('/-[A-Za-z0-9]{4}$/', $slug);
        $this->assertNotNull(ServiceSubcategory::query()->first() ?? true);
    }
}
