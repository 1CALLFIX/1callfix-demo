<?php

namespace Tests\Feature\ProviderWeb;

use App\Livewire\Provider\Dashboard;
use App\Models\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * PHASE PW1 §3 — the online/offline toggle and the eligibility panel that
 * must name every reason dispatch will or won't reach this partner
 * (decision 2: never silently invisible).
 */
class ProviderDashboardTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    /** @return array{0: Provider, 1: int} provider + its service category id */
    private function readyProvider(): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [$category] = $this->makeCategoryAndService();
        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->update([
            'is_online' => false, 'current_lat' => null, 'current_lng' => null, 'location_updated_at' => null,
            'skills' => [$category->id],
        ]);

        return [$provider->fresh(), $category->id];
    }

    private function actingAsProvider(Provider $provider): void
    {
        $this->actingAs($provider->user);
    }

    public function test_going_online_with_a_fix_marks_the_provider_online_and_stores_coordinates(): void
    {
        [$provider] = $this->readyProvider();
        $this->actingAsProvider($provider);

        Livewire::test(Dashboard::class)
            ->call('goOnline', 12.9716, 77.5946)
            ->assertSee('online');

        $provider->refresh();
        $this->assertTrue($provider->is_online);
        $this->assertSame(12.9716, (float) $provider->current_lat);
        $this->assertNotNull($provider->location_updated_at);
    }

    public function test_going_online_without_a_fix_shows_the_hard_no_location_warning(): void
    {
        [$provider] = $this->readyProvider();
        $this->actingAsProvider($provider);

        Livewire::test(Dashboard::class)
            ->call('goOnline')
            ->assertSee('you will NOT receive jobs', false)
            ->assertSee('will not be offered jobs');

        $this->assertTrue($provider->fresh()->is_online);
        $this->assertNull($provider->fresh()->current_lat);
    }

    public function test_going_offline_flips_the_flag(): void
    {
        [$provider] = $this->readyProvider();
        $provider->update(['is_online' => true]);
        $this->actingAsProvider($provider);

        Livewire::test(Dashboard::class)->call('goOffline');

        $this->assertFalse($provider->fresh()->is_online);
    }

    public function test_eligibility_panel_flags_unapproved_kyc(): void
    {
        [$provider] = $this->readyProvider();
        $provider->update(['kyc_status' => 'pending']);
        $this->actingAsProvider($provider);

        Livewire::test(Dashboard::class)
            ->assertSee('Your KYC is pending')
            ->assertSee('will not be offered jobs');
    }

    public function test_eligibility_panel_flags_missing_skills(): void
    {
        [$provider] = $this->readyProvider();
        $provider->update(['skills' => []]);
        $this->actingAsProvider($provider);

        Livewire::test(Dashboard::class)->assertSee('No service categories on your profile');
    }

    public function test_eligibility_panel_is_all_green_when_ready(): void
    {
        [$provider] = $this->readyProvider();
        $this->actingAsProvider($provider);

        Livewire::test(Dashboard::class)
            ->call('goOnline', 12.9716, 77.5946)
            ->assertSee("You're eligible for dispatch.", false);
    }

    /** Dispatch skips a stale fix (DispatchService::eligibleQuery), so the panel must not say "eligible". */
    public function test_a_stale_location_blocks_the_panel_instead_of_showing_eligible(): void
    {
        [$provider] = $this->readyProvider();
        $provider->update([
            'is_online' => true, 'current_lat' => 12.9716, 'current_lng' => 77.5946,
            'location_updated_at' => now()->subMinutes(31),
        ]);
        $this->actingAsProvider($provider);

        Livewire::test(Dashboard::class)
            ->assertSee('You will not be offered jobs right now', false)
            ->assertSee("Your location hasn't updated for over 30 min")
            ->assertDontSee("You're eligible for dispatch.", false)
            ->assertSee('Refresh location');
    }

    public function test_refresh_location_makes_a_stale_online_provider_eligible_again(): void
    {
        [$provider] = $this->readyProvider();
        $provider->update([
            'is_online' => true, 'current_lat' => 12.9716, 'current_lng' => 77.5946,
            'location_updated_at' => now()->subMinutes(31),
        ]);
        $this->actingAsProvider($provider);

        Livewire::test(Dashboard::class)
            ->call('refreshLocation', 12.98, 77.60)
            ->assertSet('notice', 'Location updated.')
            ->assertSee("You're eligible for dispatch.", false);

        $this->assertTrue($provider->fresh()->location_updated_at->greaterThan(now()->subMinute()));
    }

    public function test_refresh_location_without_a_fix_explains_and_changes_nothing(): void
    {
        [$provider] = $this->readyProvider();
        $provider->update(['is_online' => true]);
        $this->actingAsProvider($provider);

        Livewire::test(Dashboard::class)
            ->call('refreshLocation', null, null)
            ->assertSee("We couldn't read your location");

        $this->assertNull($provider->fresh()->location_updated_at);
    }

    /** The heartbeat must never undo going offline (by hand or providers:expire-stale-online). */
    public function test_refresh_location_never_puts_an_offline_provider_back_online(): void
    {
        [$provider] = $this->readyProvider();
        $this->actingAsProvider($provider);

        Livewire::test(Dashboard::class)->call('refreshLocation', 12.98, 77.60);
        Livewire::test(\App\Livewire\Provider\OnlineToggle::class)->call('refreshLocation', 12.98, 77.60);

        $fresh = $provider->fresh();
        $this->assertFalse((bool) $fresh->is_online);
        $this->assertNull($fresh->location_updated_at);
    }
}
