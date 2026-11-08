<?php

namespace Tests\Feature\Seo;

use App\Models\CityPageContent;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/** Owner 2026-11-08: rank for "<service> in <city>" — city in every title, city-aware rich results, questions. */
class CityRankingSeoTest extends TestCase
{
    use LiveCity;
    use RefreshDatabase;

    private function catalog(): array
    {
        $category = ServiceCategory::create([
            'module' => 'service', 'name' => 'Laptop| CCTV | IT Services', 'slug' => 'laptop-cctv-it',
            'image' => 'categories/x.png', 'sort_order' => 1, 'is_active' => true,
        ]);
        $service = Service::create([
            'category_id' => $category->id, 'name' => 'Laptop Repair', 'slug' => 'laptop-repair',
            'description' => 'Screen, keyboard and battery fixes at home.',
            'base_price' => 499, 'price_type' => 'fixed', 'duration_estimate_mins' => 60,
            'is_active' => true, 'location_required' => true, 'age_restriction' => false, 'sort_order' => 1,
        ]);

        return [$category, $service];
    }

    public function test_category_and_service_titles_name_the_city_and_carry_breadcrumbs(): void
    {
        $city = $this->liveCity();
        [$category, $service] = $this->catalog();
        $this->providerFor($city, [$category->id]);

        $this->get('/nellore/services/laptop-cctv-it')->assertOk()
            ->assertSee('<title>Laptop, CCTV, IT Services in Nellore', false)
            ->assertSee('"@type":"BreadcrumbList"', false)
            ->assertSee('"name":"Services in Nellore"', false);

        $this->get('/nellore/services/laptop-repair')->assertOk()
            ->assertSee('<title>Laptop Repair in Nellore', false)
            ->assertSee('content="Laptop Repair in Nellore: Screen, keyboard and battery fixes at home."', false)
            ->assertSee('"areaServed":{"@type":"City","name":"Nellore"}', false)
            ->assertSee('"name":"Laptop| CCTV | IT Services","item":"https://1callfix.com/nellore/services/laptop-cctv-it"', false);
    }

    public function test_a_franchise_written_title_still_wins(): void
    {
        $city = $this->liveCity();
        [, $service] = $this->catalog();
        CityPageContent::create([
            'city_id' => $city->id, 'subject_type' => CityPageContent::SUBJECT_SERVICE, 'subject_id' => $service->id,
            'title' => 'Doorstep laptop repair, Nellore',
        ]);

        $this->get('/nellore/services/laptop-repair')->assertOk()
            ->assertSee('<title>Doorstep laptop repair, Nellore', false)
            ->assertDontSee('Doorstep laptop repair, Nellore in Nellore', false);
    }

    public function test_the_services_page_answers_questions_with_faq_data(): void
    {
        $this->liveCity();

        $this->get('/nellore/services')->assertOk()
            ->assertSee('data-testid="faq"', false)
            ->assertSee('How do I book a home service in Nellore?')
            ->assertSee('"@type":"FAQPage"', false);
    }
}
