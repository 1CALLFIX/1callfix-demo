<?php

namespace Tests\Feature\Catalog;

use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use App\Models\SlugRedirect;
use App\Models\User;
use App\Support\PartnerPage\PartnerFaq;
use App\Support\PartnerPage\PartnerPageData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Owner 2026-11-22: the combined categories from the previous app become separate ones. Runs the real migration on a
 * copy of the live layout: services and subcategories move, the kept rows keep their ids, old links redirect, and
 * every partner with the old skill still gets those jobs.
 */
class SplitCombinedCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private ?\App\Models\Franchise $franchise = null;

    private function category(string $name, string $slug, int $sort, array $subs): ServiceCategory
    {
        $c = ServiceCategory::create(['module' => 'service', 'name' => $name, 'slug' => $slug, 'image' => 'categories/'.$slug.'.png',
            'color' => '#123456', 'sort_order' => $sort, 'is_active' => true]);
        foreach ($subs as $i => [$subName, $subSlug, $services]) {
            $s = ServiceSubcategory::create(['category_id' => $c->id, 'name' => $subName, 'slug' => $subSlug, 'sort_order' => $i, 'is_active' => true]);
            for ($n = 0; $n < $services; $n++) {
                Service::create(['category_id' => $c->id, 'subcategory_id' => $s->id, 'name' => $subName.' '.$n, 'slug' => $subSlug.'-svc-'.$n,
                    'base_price' => 300, 'price_type' => 'fixed', 'duration_estimate_mins' => 30, 'is_active' => true,
                    'location_required' => true, 'age_restriction' => false, 'sort_order' => $n]);
            }
        }

        return $c;
    }

    private function provider(array $skills): Provider
    {
        $u = User::create(['uuid' => (string) Str::uuid(), 'name' => 'P', 'phone' => '9'.fake()->unique()->numerify('#########'), 'role' => 'provider', 'status' => 'active']);

        if ($this->franchise === null) {
            $country = \App\Models\Country::create(['name' => 'T', 'code' => 'TQ', 'currency_code' => 'INR', 'default_timezone' => 'Asia/Kolkata', 'is_active' => true]);
            $city = \App\Models\City::create(['country_id' => $country->id, 'name' => 'C '.Str::random(4), 'is_active' => true]);
            $this->franchise = \App\Models\Franchise::create(['name' => 'F', 'slug' => 'f-'.Str::random(6), 'city' => $city->name, 'status' => 'active',
                'country_id' => $country->id, 'city_id' => $city->id]);
        }

        return Provider::create(['user_id' => $u->id, 'franchise_id' => $this->franchise->id, 'provider_type' => 'independent', 'kyc_status' => 'approved', 'is_active' => true, 'skills' => $skills]);
    }

    private function migrate(): void
    {
        (require database_path('migrations/2026_11_22_210000_split_combined_service_categories.php'))->up();
    }

    public function test_the_live_layout_is_split_and_nothing_breaks(): void
    {
        $appliance = $this->category('Appliance | AC Repair', 'appliance-ac-repair', 1, [
            ['Split AC', 'split-ac', 2], ['Window AC', 'window-ac', 1], ['Other AC', 'other-ac', 1],
            ['Washing Machine', 'washing-machine', 2], ['Refrigerator/Fridge', 'refrigeratorfridge', 1], ['Chimney', 'chimney', 1],
        ]);
        $home = $this->category('Home Repairs', 'home-repairs', 2, [['Plumber', 'plumber', 0]]);
        $it = $this->category('Laptop| CCTV | IT Services', 'laptop-cctv-it-services', 3, [
            ['Desktop Repair', 'desktop-repair', 0], ['Laptop Repair', 'laptop-repair', 0], ['Printer Service', 'printer-service', 0],
            ['CCTV Service', 'cctv-service', 0], ['Networking', 'networking', 0], ['Wi-Fi Setup', 'wi-fi-setup', 0],
        ]);
        $beauty = $this->category('Beauty Care', 'beauty-care', 5, [['Mens Salon | Beauty Care', 'mens-salon-beauty-care', 0], ['Womens Salon | Beauty Care', 'womens-salon-beauty-care', 0]]);
        $events = $this->category('Events', 'events', 6, [['DJ | Lighting', 'dj-lighting', 0]]);
        $acTech = $this->provider([$appliance->id]);
        $itTech = $this->provider([(string) $it->id, $home->id]);
        $plumber = $this->provider([$home->id]);

        $this->migrate();

        // Kept rows: same id, new name and clean slug; the old slug redirects to them.
        $this->assertSame('AC Repair', $appliance->refresh()->name);
        $this->assertSame('ac-repair', $appliance->slug);
        $this->assertSame('Laptop Repair', $it->refresh()->name);
        $this->assertSame('laptop-repair', $it->slug);
        $this->assertTrue(SlugRedirect::where('old_slug', 'appliance-ac-repair')->where('target_type', 'category')->where('target_id', $appliance->id)->exists());
        $this->assertTrue(SlugRedirect::where('old_slug', 'laptop-cctv-it-services')->where('target_id', $it->id)->exists());

        // New categories took their subcategories and services, with the original's picture and colour.
        $appRepair = ServiceCategory::where('name', 'Appliance Repair')->firstOrFail();
        $this->assertSame('appliance-repair', $appRepair->slug);
        $this->assertSame('categories/appliance-ac-repair.png', $appRepair->image);
        $this->assertEqualsCanonicalizing(['Split AC', 'Window AC', 'Other AC'], $appliance->subcategories()->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['Washing Machine', 'Refrigerator/Fridge', 'Chimney'], $appRepair->subcategories()->pluck('name')->all());
        $this->assertSame(4, Service::where('category_id', $appliance->id)->count());
        $this->assertSame(4, Service::where('category_id', $appRepair->id)->count());
        $this->assertSame(0, Service::query()->whereHas('subcategory', fn ($q) => $q->whereColumn('service_subcategories.category_id', '!=', 'services.category_id'))->count(),
            'every service sits in its own subcategory\'s category');

        $computer = ServiceCategory::where('name', 'Computer Repair')->firstOrFail();
        $cctv = ServiceCategory::where('name', 'CCTV')->firstOrFail();
        $net = ServiceCategory::where('name', 'IT & Networking')->firstOrFail();
        $this->assertEqualsCanonicalizing(['Desktop Repair', 'Printer Service'], $computer->subcategories()->pluck('name')->all());
        $this->assertSame(['CCTV Service'], $cctv->subcategories()->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['Networking', 'Wi-Fi Setup'], $net->subcategories()->pluck('name')->all());
        $this->assertSame(['Laptop Repair'], $it->subcategories()->pluck('name')->all());

        // Partners keep every job they had.
        $this->assertEqualsCanonicalizing([$appliance->id, $appRepair->id], $acTech->refresh()->skills);
        $this->assertEqualsCanonicalizing([$it->id, $home->id, $computer->id, $cctv->id, $net->id], $itTech->refresh()->skills);
        $this->assertSame([$home->id], $plumber->refresh()->skills);

        // Each part sits right after the category it came from.
        $this->assertSame(['AC Repair', 'Appliance Repair', 'Home Repairs', 'Laptop Repair', 'Computer Repair', 'CCTV', 'IT & Networking', 'Beauty Care', 'Events'],
            ServiceCategory::where('module', 'service')->orderBy('sort_order')->pluck('name')->all());

        // "|" gone from the subcategory names; renamed slugs redirect.
        $this->assertEqualsCanonicalizing(["Men's Salon", "Women's Salon"], $beauty->subcategories()->pluck('name')->all());
        $this->assertSame('mens-salon', ServiceSubcategory::where('name', "Men's Salon")->value('slug'));
        $this->assertTrue(SlugRedirect::where('old_slug', 'mens-salon-beauty-care')->exists());
        $this->assertSame(['DJ & Lighting'], $events->subcategories()->pluck('name')->all());

        // No category name keeps a "|".
        $this->assertSame(0, ServiceCategory::where('name', 'like', '%|%')->count());

        // Running it again changes nothing.
        $this->migrate();
        $this->assertSame(9, ServiceCategory::where('module', 'service')->count());
        $this->assertEqualsCanonicalizing([$appliance->id, $appRepair->id], $acTech->refresh()->skills);

        // Partner FAQ lists only categories a customer can book (they have services).
        $offer = collect(PartnerFaq::live(PartnerPageData::roles()))->firstWhere('q', 'Which services can I offer?')['a'] ?? '';
        $this->assertStringContainsString('AC Repair and Appliance Repair', $offer);
        $this->assertStringNotContainsString('Beauty Care', $offer);
    }

    public function test_a_database_without_the_combined_categories_is_left_alone(): void
    {
        $c = ServiceCategory::create(['module' => 'service', 'name' => 'Plumbing', 'slug' => 'plumbing', 'sort_order' => 7, 'is_active' => true]);
        $this->migrate();
        $this->assertSame(7, $c->refresh()->sort_order);
        $this->assertSame(1, ServiceCategory::count());
    }
}
