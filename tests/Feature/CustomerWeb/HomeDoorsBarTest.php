<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\HomeSections\Manage;
use App\Models\Franchise;
use App\Models\Zone;
use App\Services\Customer\CustomerLocationContext;
use App\Support\HomeDoors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Owner 2026-11-22 (the Flipkart pattern): once the doors scroll away, a slim bar of the same businesses stays under
 * the top bar. Same doors, same order, same links; Super Admin switch in Homepage sections, on by default.
 */
class HomeDoorsBarTest extends TestCase
{
    use BookingFixtureHelpers;
    use LiveCity;
    use MarketplaceFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    private Franchise $franchise;

    protected function setUp(): void
    {
        parent::setUp();
        $this->liveCity();
        $this->makeCategoryAndService();
        $this->franchise = Franchise::firstOrFail();
        app(CustomerLocationContext::class)->setZone(Zone::where('franchise_id', $this->franchise->id)->firstOrFail()->id);
    }

    /** @return array{0: list<string>, 1: list<string>} doors, bar */
    private function rows(string $html): array
    {
        preg_match_all('/data-door="([a-z]+)" style="[^"]*"\s+class/', $html, $doors);
        preg_match_all('/data-door-bar="([a-z]+)"/', $html, $bar);

        return [$doors[1], $bar[1]];
    }

    public function test_the_bar_repeats_the_doors_in_the_same_order_hidden_until_scrolled(): void
    {
        $this->activateParcelFor($this->franchise);
        $this->activateMarketplaceModuleFor($this->franchise);

        $html = $this->get('/')->assertOk()
            ->assertSee('data-testid="doors-bar"', false)
            ->getContent();

        $this->assertStringContainsString('style="display:none;--line:', $html, 'hidden until the visitor scrolls');
        $this->assertStringContainsString("document.querySelector('[data-testid=home-doors-grid]')", $html, 'shows by where the door grid is');
        $this->assertStringContainsString('data-testid="home-doors-grid"', $html);
        $this->assertStringContainsString('--door-cols:3;--door-cols-phone:3', $html, 'every door fits, no sideways scrolling');
        $this->assertMatchesRegularExpression('/<ul class="cf-doors [^"]*"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<ul class="cf-doors [^"]*overflow-x-auto/', $html, 'the door row never scrolls sideways');
        [$doors, $bar] = $this->rows($html);
        $this->assertSame(['services', 'parcel', 'shop'], $doors);
        $this->assertSame($doors, $bar);
    }

    public function test_one_business_needs_no_bar(): void
    {
        $this->get('/')->assertOk()->assertSee('data-testid="home-doors"', false)->assertDontSee('data-testid="doors-bar"', false);
    }

    public function test_the_super_admin_switches_it_off(): void
    {
        $this->activateParcelFor($this->franchise);
        $this->assertTrue(HomeDoors::sticky());

        Livewire::actingAs($this->makeSuperAdmin())->test(Manage::class)
            ->assertSee('Keep a slim bar of the businesses')
            ->set('doorsSticky', false)->call('save')->assertHasNoErrors();

        $this->assertFalse(HomeDoors::sticky());
        $this->get('/')->assertOk()->assertSee('data-testid="home-doors"', false)->assertDontSee('data-testid="doors-bar"', false);
    }
}
