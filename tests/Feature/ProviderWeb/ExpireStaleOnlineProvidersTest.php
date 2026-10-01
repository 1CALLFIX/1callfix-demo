<?php

namespace Tests\Feature\ProviderWeb;

use App\Models\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

class ExpireStaleOnlineProvidersTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private function online(?\DateTimeInterface $fixAt): Provider
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $p = $this->makeProviderIn($franchise, $zone);
        $p->forceFill(['is_online' => true, 'location_updated_at' => $fixAt])->save();

        return $p;
    }

    public function test_silent_provider_goes_offline_and_fresh_one_stays(): void
    {
        $stale = $this->online(now()->subMinutes(45));
        $fresh = $this->online(now()->subMinutes(2));

        $this->artisan('providers:expire-stale-online')->assertSuccessful();

        $this->assertFalse((bool) $stale->fresh()->is_online);
        $this->assertTrue((bool) $fresh->fresh()->is_online);
    }

    public function test_offline_keeps_last_known_coordinates(): void
    {
        $stale = $this->online(now()->subMinutes(45));
        $stale->forceFill(['current_lat' => 12.5, 'current_lng' => 77.5])->save();

        $this->artisan('providers:expire-stale-online')->assertSuccessful();

        $this->assertEquals(12.5, (float) $stale->fresh()->current_lat);
    }
}
