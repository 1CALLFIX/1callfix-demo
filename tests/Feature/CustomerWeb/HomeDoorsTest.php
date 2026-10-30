<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\HomeSections\Manage;
use App\Models\ActivityLog;
use App\Models\Franchise;
use App\Models\Setting;
use App\Models\Zone;
use App\Services\Customer\CustomerLocationContext;
use App\Support\HomeDoors;
use App\Support\SiteText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/** Owner mockup 2026-10-30: "Explore 1CallFix" doors, one per live business, all controlled by the Super Admin. */
class HomeDoorsTest extends TestCase
{
    use BookingFixtureHelpers;
    use LiveCity;
    use MarketplaceFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    private Franchise $franchise;
    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->liveCity();
        $this->makeCategoryAndService();
        $this->franchise = Franchise::firstOrFail();
        $this->zone = Zone::where('franchise_id', $this->franchise->id)->firstOrFail();
        app(CustomerLocationContext::class)->setZone($this->zone->id);
    }

    private function doorsOrder(string $html): array
    {
        preg_match_all('/data-door="([a-z]+)"/', $html, $m);

        return $m[1];
    }

    public function test_only_services_shows_while_parcel_and_shop_are_off(): void
    {
        $html = $this->get('/')->assertOk()->assertSee('data-testid="home-doors"', false)
            ->assertSee('Explore 1CallFix')->assertSee('1 service to book')->getContent();

        $this->assertSame(['services'], $this->doorsOrder($html));
    }

    public function test_parcel_and_shop_doors_appear_where_they_are_on_with_real_live_numbers(): void
    {
        $this->activateParcelFor($this->franchise);
        $this->activateMarketplaceModuleFor($this->franchise);
        $this->makeStore($this->franchise, $this->zone, null, ['name' => 'Open Shop', 'is_open' => true]);
        $this->makeStore($this->franchise, $this->zone, null, ['name' => 'Shut Shop', 'is_open' => false]);

        $html = $this->get('/')->assertOk()->assertSee('1 shop open now')->assertSee('From ₹')->getContent();
        $this->assertSame(['services', 'parcel', 'shop'], $this->doorsOrder($html));

        Setting::set(HomeDoors::LIVE_INFO, '0');
        $this->get('/')->assertOk()->assertSee('Local shops, fast delivery')->assertDontSee('1 shop open now');
    }

    public function test_the_super_admin_orders_hides_moves_and_pictures_the_doors(): void
    {
        Storage::fake('public');
        $this->activateParcelFor($this->franchise);
        $this->actingAs($this->makeSuperAdmin());

        Livewire::test(Manage::class)
            ->set('doorOrder.parcel', '1')->set('doorOrder.services', '2')
            ->set('showPromise', false)
            ->set('position', 'after_categories')
            ->set('doorImage.parcel', UploadedFile::fake()->image('door.png', 200, 200))
            ->call('save')->assertSee('Saved. The homepage shows it now.');

        $this->assertTrue(ActivityLog::where('description', 'Setting home.door.parcel.order changed')->exists());
        $path = Setting::get(HomeDoors::imageKey('parcel'));
        Storage::disk('public')->assertExists($path);

        auth()->logout();
        $html = $this->get('/')->assertOk()->assertDontSee(SiteText::get('home.promise'))->assertSee($path)->getContent();
        $this->assertSame(['parcel', 'services'], $this->doorsOrder($html));

        Setting::set('home.door.parcel.show', '0');
        $this->assertSame(['services'], $this->doorsOrder($this->get('/')->getContent()));
        Setting::set(HomeDoors::SHOW, '0');
        $this->get('/')->assertOk()->assertDontSee('data-testid="home-doors"', false);
    }

    public function test_the_doors_read_in_telugu_and_only_a_super_admin_edits_them(): void
    {
        Setting::set(SiteText::TELUGU_ENABLED, '1');
        $this->withCookie(SiteText::COOKIE, 'te')->get('/')->assertOk()->assertSee('1CallFix లో చూడండి')->assertSee('సేవలు');

        $this->actingAs($this->makeUserWithNoPermissions())->get(route('admin.home-sections'))->assertForbidden();
        $this->actingAs($this->makeSuperAdmin())->get(route('admin.home-sections'))->assertOk()->assertSee('Homepage sections');
    }
}
