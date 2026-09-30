<?php

namespace Tests\Feature\Earnings;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\EarningsSettingsFixture;
use Tests\TestCase;

/**
 * REF 1CF-HEADER-HAMBURGER-001 — Earnings lives in the header menu (signed in
 * and signed out) and no longer adds a sixth icon to the mobile bottom bar.
 */
class HeaderMenuEarningsTest extends TestCase
{
    use BookingFixtureHelpers;
    use EarningsSettingsFixture;
    use RbacTestHelpers;
    use RefreshDatabase;

    private function earningsOn(): void
    {
        Setting::set('earnings.enabled', '1');
        Setting::set('earnings.wallet_tab', '1');
    }

    public function test_signed_in_menu_has_earnings_and_bottom_bar_stays_at_five_icons(): void
    {
        $this->earningsOn();
        $this->actingAs($this->makeCustomer());

        $header = Blade::render('<x-customer.header />');
        $this->assertStringContainsString(route('customer.earnings.wallet'), $header);

        $bottom = Blade::render('<x-customer.bottom-nav />');
        $this->assertStringNotContainsString('Earnings', $bottom);
        $this->assertSame(5, substr_count($bottom, 'min-h-16'));
    }

    public function test_guest_menu_offers_earnings_when_switched_on_and_hides_it_when_off(): void
    {
        $this->assertStringNotContainsString(route('customer.earnings.wallet'), Blade::render('<x-customer.header />'));

        $this->earningsOn();
        $this->assertStringContainsString(route('customer.earnings.wallet'), Blade::render('<x-customer.header />'));
    }
}
