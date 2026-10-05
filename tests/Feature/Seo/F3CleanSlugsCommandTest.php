<?php

namespace Tests\Feature\Seo;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use App\Models\SlugRedirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * F3 - catalog:clean-slugs. Dry run by default; --apply cleans random suffixes and resolves duplicate service
 * slugs without deleting anything; QA rows are left alone.
 */
class F3CleanSlugsCommandTest extends TestCase
{
    use CatalogFixtures;
    use LiveCity;
    use RefreshDatabase;

    /** Rows as they exist on production: created before the slug helper (random suffix, mixed case, duplicates). */
    private function legacyCategory(string $name, string $slug): ServiceCategory
    {
        return ServiceCategory::withoutEvents(fn () => $this->makeCategory(['name' => $name, 'slug' => $slug]));
    }

    private function legacyService(ServiceCategory $category, string $name, string $slug, array $extra = []): Service
    {
        return Service::withoutEvents(fn () => $this->makeService($category, ['name' => $name, 'slug' => $slug] + $extra));
    }

    private function clean(array $options = []): string
    {
        Artisan::call('catalog:clean-slugs', $options);

        return Artisan::output();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_dry_run_is_the_default_and_changes_nothing(): void
    {
        $category = $this->legacyCategory('Appliance | AC Repair', 'appliance-ac-repair-Bs7r');

        $out = $this->clean();

        $this->assertStringContainsString('DRY RUN', $out);
        $this->assertStringContainsString('appliance-ac-repair-Bs7r', $out);
        $this->assertStringContainsString('appliance-ac-repair', $out);
        $this->assertSame('appliance-ac-repair-Bs7r', $category->fresh()->slug);
        $this->assertSame(0, SlugRedirect::count());
    }

    public function test_apply_cleans_the_suffix_and_keeps_the_old_url_alive(): void
    {
        $this->liveCity('Nellore');
        $category = $this->legacyCategory('Appliance | AC Repair', 'appliance-ac-repair-Bs7r');
        $sub = ServiceSubcategory::withoutEvents(fn () => $this->makeSubcategory($category, ['name' => 'Split AC', 'slug' => 'split-ac-Qw3e']));

        $out = $this->clean(['--apply' => true]);

        $this->assertStringContainsString('APPLY', $out);
        $this->assertSame('appliance-ac-repair', $category->fresh()->slug);
        $this->assertSame('split-ac', $sub->fresh()->slug);
        $this->assertSame(1, SlugRedirect::where(['scope' => 'catalog', 'old_slug' => 'appliance-ac-repair-Bs7r'])->count());

        $this->get('/nellore/appliance-ac-repair-Bs7r?utm_source=meta')
            ->assertStatus(301)->assertRedirect(url('/nellore/appliance-ac-repair').'?utm_source=meta');
        $this->get('/categories/appliance-ac-repair-Bs7r')->assertStatus(301)->assertRedirect(url('/nellore/appliance-ac-repair'));
    }

    public function test_apply_is_idempotent(): void
    {
        $this->legacyCategory('Appliance | AC Repair', 'appliance-ac-repair-Bs7r');

        $this->clean(['--apply' => true]);
        $rows = SlugRedirect::count();
        $out = $this->clean(['--apply' => true]);

        $this->assertStringContainsString('No slug changes needed', $out);
        $this->assertSame($rows, SlugRedirect::count());
    }

    public function test_a_collision_gets_dash_two_not_a_random_suffix(): void
    {
        $this->legacyCategory('AC Repair', 'ac-repair');
        $suffixed = $this->legacyCategory('AC Repair', 'ac-repair-Zx9k');

        $this->clean(['--apply' => true]);

        $this->assertSame('ac-repair-2', $suffixed->fresh()->slug);
    }

    public function test_qa_rows_and_ordinary_four_letter_words_are_left_alone(): void
    {
        $qa = $this->legacyCategory('[QA] Plumbing', 'qa-plumbing-Ab12');
        $word = $this->legacyCategory('AC Repair Tips', 'ac-repair-tips');

        $this->clean(['--apply' => true]);

        $this->assertSame('qa-plumbing-Ab12', $qa->fresh()->slug);
        $this->assertSame('ac-repair-tips', $word->fresh()->slug);
    }

    public function test_a_suffix_that_does_not_match_the_name_is_reported_not_changed(): void
    {
        $renamed = $this->legacyCategory('Brand New Name', 'old-name-Ab12');

        $out = $this->clean(['--apply' => true]);

        $this->assertSame('old-name-Ab12', $renamed->fresh()->slug);
        $this->assertStringContainsString('REPORT (not changed)', $out);
        $this->assertStringContainsString('old-name-Ab12', $out);
    }

    // ---------------------------------------------------------------- the fridge duplicate

    public function test_duplicate_services_keep_one_slug_rename_the_other_and_redirect_it_to_the_kept_one_once_deactivated(): void
    {
        $this->liveCity('Nellore');
        $category = $this->legacyCategory('Appliance | AC Repair', 'appliance-ac-repair-Bs7r');
        $first = $this->legacyService($category, 'Refrigerator | Fridge Service', 'refrigerator-fridge-service');
        $second = $this->legacyService($category, 'Refrigerator | Fridge Service', 'refrigerator-fridge-service');

        $dry = $this->clean();
        $this->assertStringContainsString('refrigerator-fridge-service-2', $dry);
        $this->assertSame('refrigerator-fridge-service', $second->fresh()->slug, 'dry run changes nothing');

        $this->clean(['--apply' => true]);

        $this->assertSame('refrigerator-fridge-service', $first->fresh()->slug, 'lowest id keeps the slug by default');
        $this->assertSame('refrigerator-fridge-service-2', $second->fresh()->slug);
        $this->assertNull($second->fresh()->deleted_at, 'nothing is ever deleted');

        // The owner deactivates the duplicate from the admin panel: its URL now 301s to the kept service.
        $second->update(['is_active' => false]);
        $this->get('/nellore/refrigerator-fridge-service-2?utm_source=g')
            ->assertStatus(301)->assertRedirect(url('/nellore/refrigerator-fridge-service').'?utm_source=g');
        $this->get('/services/'.$second->id)->assertStatus(301)->assertRedirect(url('/nellore/refrigerator-fridge-service'));
        $this->get('/nellore/refrigerator-fridge-service')->assertOk();
    }

    public function test_keep_option_chooses_which_duplicate_keeps_the_slug(): void
    {
        $this->liveCity('Nellore');
        $category = $this->legacyCategory('Appliance', 'appliance');
        $first = $this->legacyService($category, 'Fridge', 'fridge');
        $second = $this->legacyService($category, 'Fridge', 'fridge');

        $this->clean(['--apply' => true, '--keep' => [$second->id]]);

        $this->assertSame('fridge', $second->fresh()->slug);
        $this->assertSame('fridge-2', $first->fresh()->slug);

        $first->update(['is_active' => false]);
        $this->get('/nellore/fridge-2')->assertStatus(301)->assertRedirect(url('/nellore/fridge'));
    }

    public function test_a_service_sharing_a_category_slug_is_reported_only(): void
    {
        $category = $this->legacyCategory('Plumbing', 'plumbing');
        $service = $this->legacyService($category, 'Plumbing', 'plumbing');

        $out = $this->clean(['--apply' => true]);

        $this->assertSame('plumbing', $service->fresh()->slug);
        $this->assertStringContainsString('shares the slug "plumbing" with a category', $out);
    }
}
