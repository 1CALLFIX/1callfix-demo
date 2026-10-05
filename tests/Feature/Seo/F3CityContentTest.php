<?php

namespace Tests\Feature\Seo;

use App\Livewire\Seo\CityContent;
use App\Models\ActivityLog;
use App\Models\CityPageContent;
use App\Models\Franchise;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * F3 - franchise city content: title / meta description / intro for a city's pages, behind
 * seo.edit_city_content (franchise-scoped holders: own city only; HQ and Super Admin: any). Never a slug.
 */
class F3CityContentTest extends TestCase
{
    use CatalogFixtures;
    use LiveCity;
    use RbacTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        \App\Models\Setting::set('seo.min_providers_to_index', '0');
    }

    public function test_saved_content_shows_on_the_city_category_and_service_pages(): void
    {
        $city = $this->liveCity('Nellore');
        $category = $this->makeCategory(['name' => 'AC Repair', 'slug' => null]);
        $service = $this->makeService($category, ['name' => 'Gas Refill', 'slug' => null]);
        $admin = $this->makeSuperAdmin();

        foreach ([
            ['city', null, 'Nellore Hub', 'Meta for the city', 'Welcome to Nellore.'],
            ['category', $category->id, 'AC Repair in Nellore', 'Meta for AC', 'Cool homes since 2020.'],
            ['service', $service->id, 'Gas Refill Nellore', 'Meta for gas', 'We refill all brands.'],
        ] as [$type, $id, $title, $meta, $intro]) {
            Livewire::actingAs($admin)->test(CityContent::class)
                ->set('cityId', $city->id)->set('subjectType', $type)->set('subjectId', $id)
                ->set('title', $title)->set('metaDescription', $meta)->set('intro', $intro)
                ->call('save')->assertHasNoErrors();
        }

        foreach ([
            '/nellore' => ['Nellore Hub', 'Meta for the city', 'Welcome to Nellore.'],
            '/nellore/ac-repair' => ['AC Repair in Nellore', 'Meta for AC', 'Cool homes since 2020.'],
            '/nellore/gas-refill' => ['Gas Refill Nellore', 'Meta for gas', 'We refill all brands.'],
        ] as $path => [$title, $meta, $intro]) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertStringContainsString('<title>'.$title.' ', $html, $path);
            $this->assertStringContainsString('<meta name="description" content="'.$meta.'">', $html, $path);
            $this->assertStringContainsString($intro, $html, $path);
        }

        $this->assertNotNull(ActivityLog::where('subject_type', 'city_page_content')->first());
    }

    public function test_content_is_per_city(): void
    {
        $nellore = $this->liveCity('Nellore');
        $this->liveCity('Guntur');
        $this->makeCategory(['name' => 'AC Repair', 'slug' => null]);

        Livewire::actingAs($this->makeSuperAdmin())->test(CityContent::class)
            ->set('cityId', $nellore->id)->set('subjectType', 'category')->set('subjectId', \App\Models\ServiceCategory::first()->id)
            ->set('intro', 'Nellore only.')->call('save');

        $this->get('/nellore/ac-repair')->assertSee('Nellore only.');
        $this->get('/guntur/ac-repair')->assertDontSee('Nellore only.');
    }

    public function test_a_franchise_scoped_holder_edits_only_their_own_city(): void
    {
        $nellore = $this->liveCity('Nellore');
        $guntur = $this->liveCity('Guntur');
        $franchise = Franchise::where('city_id', $nellore->id)->first();
        $editor = $this->makeUserWithPermission('seo.edit_city_content', 'franchise', $franchise->id);

        Livewire::actingAs($editor)->test(CityContent::class)
            ->set('cityId', $nellore->id)->set('intro', 'Mine.')->call('save')->assertHasNoErrors();
        $this->assertSame('Mine.', CityPageContent::where('city_id', $nellore->id)->value('intro'));

        Livewire::actingAs($editor)->test(CityContent::class)
            ->set('cityId', $guntur->id)->assertForbidden();
        $this->assertSame(0, CityPageContent::where('city_id', $guntur->id)->count());

        // Forging the city id after loading the form is refused as well.
        Livewire::actingAs($editor)->test(CityContent::class)
            ->set('cityId', $nellore->id)->set('intro', 'x')
            ->set('cityId', $guntur->id);
        $this->assertSame(0, CityPageContent::where('city_id', $guntur->id)->count());
    }

    public function test_an_hq_holder_edits_any_city_and_everyone_without_the_permission_is_refused(): void
    {
        $nellore = $this->liveCity('Nellore');
        $guntur = $this->liveCity('Guntur');
        $hq = $this->makeUserWithPermission('seo.edit_city_content', 'global');

        foreach ([$nellore, $guntur] as $city) {
            Livewire::actingAs($hq)->test(CityContent::class)->set('cityId', $city->id)->set('intro', 'HQ wrote this.')->call('save');
            $this->assertSame('HQ wrote this.', CityPageContent::where('city_id', $city->id)->value('intro'));
        }

        Livewire::actingAs($this->makeUserWithNoPermissions())->test(CityContent::class)->assertForbidden();
        $this->assertContains($this->get(route('admin.seo.city-content'))->getStatusCode(), [302, 403], 'guests never see the screen');
    }

    public function test_the_content_screen_has_no_slug_field_and_save_never_touches_a_slug(): void
    {
        $city = $this->liveCity('Nellore');
        $category = $this->makeCategory(['name' => 'AC Repair', 'slug' => null]);

        Livewire::actingAs($this->makeSuperAdmin())->test(CityContent::class)
            ->assertDontSee('wire:model="editSlug"', false)
            ->assertDontSee('wire:model="slug"', false)
            ->set('cityId', $city->id)->set('subjectType', 'category')->set('subjectId', $category->id)
            ->set('title', 'T')->call('save');

        $this->assertSame('ac-repair', $category->fresh()->slug);
        $this->assertSame('nellore', $city->fresh()->slug);
    }

    public function test_lengths_are_validated_and_blank_fields_fall_back_to_the_defaults(): void
    {
        $city = $this->liveCity('Nellore');
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(CityContent::class)
            ->set('cityId', $city->id)->set('title', str_repeat('a', 161))->set('metaDescription', str_repeat('b', 321))
            ->call('save')->assertHasErrors(['title', 'metaDescription']);

        Livewire::actingAs($admin)->test(CityContent::class)
            ->set('cityId', $city->id)->set('title', '')->set('intro', '')->call('save')->assertHasNoErrors();

        $this->get('/nellore')->assertSeeText('Home services in Nellore');
    }
}
