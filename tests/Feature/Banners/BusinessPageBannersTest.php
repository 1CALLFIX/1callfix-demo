<?php

namespace Tests\Feature\Banners;

use App\Livewire\Banners\Manage;
use App\Models\Banner;
use App\Models\Franchise;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zone;
use App\Services\ModuleActivationService;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * Business page banners (owner 2026-11-19): every "Explore 1CallFix" door's own page has a top banner slot, picked in
 * Admin → Banners like Top / Mid. A banner shows only on its own page, in its own franchise's city, and never on the
 * homepage; nothing live = nothing rendered.
 */
class BusinessPageBannersTest extends TestCase
{
    use LiveCity;
    use RefreshDatabase;

    private $city;
    private Franchise $franchise;
    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->city = $this->liveCity('Nellore');
        $this->franchise = Franchise::where('city_id', $this->city->id)->firstOrFail();
        $this->zone = Zone::where('franchise_id', $this->franchise->id)->firstOrFail();
        Setting::set('secondhand.enabled', '1', 'zone', $this->zone->id);
        app(ModuleActivationService::class)->setActive(Modules::RENTAL, 'franchise', $this->franchise->id, true);
        Setting::set('rental.kind.items', '1');
    }

    private function banner(string $placement, string $title, array $extra = []): Banner
    {
        return Banner::create($extra + [
            'title' => $title, 'image' => 'https://example.test/'.Str::slug($title).'.jpg', 'link' => 'https://example.test/offer',
            'placement' => $placement, 'is_active' => true, 'sort_order' => 1,
        ]);
    }

    public function test_every_explore_door_has_its_own_page_slot(): void
    {
        foreach (\App\Support\HomeDoors::DOORS as $door => $_) {
            if ($door === 'services') {
                continue; // Services pages already carry the Mid slot.
            }
            $slot = $door === 'secondhand' ? 'page_secondhand' : 'page_'.$door;
            $this->assertArrayHasKey($slot, Banner::PLACEMENTS, "No banner slot for the {$door} door's page.");
            $this->assertArrayHasKey($slot, Banner::PAGE_MODULES);
            $this->assertContains(Banner::PAGE_MODULES[$slot], Modules::slugs());
        }
    }

    public function test_second_hand_page_shows_its_own_banner_only(): void
    {
        $this->banner('page_secondhand', 'Diwali used-phone mela');
        $this->banner('page_rentals', 'Party lights on rent');
        $this->banner('page_shop', 'Shop big offer');
        $this->banner('mid', 'Homepage strip');

        $this->get(route('customer.secondhand.home', $this->city->slug))->assertOk()
            ->assertSee('data-testid="page-banners-page_secondhand"', false)
            ->assertSee('Diwali used-phone mela')
            ->assertDontSee('Party lights on rent')->assertDontSee('Shop big offer')->assertDontSee('Homepage strip');

        $this->get(route('customer.rent.home', $this->city->slug))->assertOk()
            ->assertSee('Party lights on rent')->assertDontSee('Diwali used-phone mela');

        $this->get(route('customer.rentals.home', $this->city->slug))->assertOk()
            ->assertSee('Party lights on rent')->assertDontSee('Diwali used-phone mela');
    }

    public function test_nothing_live_renders_nothing_and_off_or_expired_banners_stay_hidden(): void
    {
        $this->banner('page_secondhand', 'Switched off banner', ['is_active' => false]);
        $this->banner('page_secondhand', 'Old banner', ['expires_at' => now()->subDay()]);

        $this->get(route('customer.secondhand.home', $this->city->slug))->assertOk()
            ->assertDontSee('page-banners-page_secondhand')->assertDontSee('Switched off banner')->assertDontSee('Old banner');
    }

    public function test_another_citys_franchise_banner_never_shows_here(): void
    {
        $other = $this->liveCity('Guntur');
        $otherFranchise = Franchise::where('city_id', $other->id)->firstOrFail();
        $this->banner('page_secondhand', 'Guntur only offer', ['franchise_id' => $otherFranchise->id]);
        $this->banner('page_secondhand', 'Nellore only offer', ['franchise_id' => $this->franchise->id]);

        $this->get(route('customer.secondhand.home', $this->city->slug))->assertOk()
            ->assertSee('Nellore only offer')->assertDontSee('Guntur only offer');
    }

    public function test_page_banners_never_appear_on_the_homepage(): void
    {
        $this->banner('page_secondhand', 'Second-hand page only');

        $this->get('/')->assertOk()->assertDontSee('Second-hand page only');
        $this->assertTrue(Banner::forPage('top')->isEmpty(), 'forPage() only serves business page slots.');
    }

    public function test_admin_can_create_a_business_page_banner(): void
    {
        $admin = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'HQ', 'email' => 'hq@example.test', 'phone' => '9000000001', 'password' => bcrypt('x'),
            'role' => 'super_admin', 'status' => 'active',
        ]);
        \Illuminate\Support\Facades\Storage::fake('public');

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('title', 'Food week')
            ->set('imageFile', \Illuminate\Http\UploadedFile::fake()->image('food.jpg', 1200, 400))
            ->set('placement', 'page_food')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('page_food', Banner::where('title', 'Food week')->value('placement'));
        $this->assertSame('Food page', Banner::where('title', 'Food week')->first()->placement_short);
    }
}
