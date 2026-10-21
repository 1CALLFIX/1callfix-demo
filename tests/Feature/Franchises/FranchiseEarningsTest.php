<?php

namespace Tests\Feature\Franchises;

use App\Livewire\Franchises\Earnings;
use App\Models\Commission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/** Owner request 2026-10-21: a franchise owner sees what their franchise earned. */
class FranchiseEarningsTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private function owner(): User
    {
        return User::create(['uuid' => (string) Str::uuid(), 'name' => 'Owner', 'phone' => '8'.fake()->unique()->numerify('#########'), 'role' => 'franchise_owner', 'status' => 'active']);
    }

    public function test_the_owner_sees_their_franchise_share_and_wallet_but_not_another_franchise(): void
    {
        ['booking' => $booking, 'franchise' => $franchise] = $this->makeBookingScenario('completed');
        ['booking' => $otherBooking, 'franchise' => $otherFranchise] = $this->makeBookingScenario('completed');
        $owner = $this->owner();
        $franchise->update(['owner_user_id' => $owner->id]);
        RoleAssignment::create(['user_id' => $owner->id, 'role_id' => Role::where('slug', 'franchise_owner')->value('id'), 'scope_type' => 'franchise', 'scope_id' => $franchise->id]);
        Commission::create(['booking_id' => $booking->id, 'provider_commission' => 700, 'franchise_commission' => 100, 'platform_commission' => 200]);
        Commission::create(['booking_id' => $otherBooking->id, 'provider_commission' => 700, 'franchise_commission' => 999, 'platform_commission' => 200]);
        app(WalletService::class)->credit($owner, 100, 'Franchise share', 'test:franchise-share:1');

        Livewire::actingAs($owner)->test(Earnings::class)
            ->assertSet('franchiseId', $franchise->id)
            ->assertSee('₹100.00')
            ->assertSee('₹1,000.00')
            ->assertSee($booking->code)
            ->assertDontSee($otherBooking->code)
            ->assertDontSee('₹999.00')
            ->assertSee("Franchise owner's wallet", false);

        // asking for another franchise by URL falls back to their own
        Livewire::actingAs($owner)->withQueryParams(['franchiseId' => $otherFranchise->id])->test(Earnings::class)
            ->assertSet('franchiseId', $franchise->id);
    }

    public function test_someone_without_a_franchise_or_commissions_view_is_refused(): void
    {
        $this->makeBookingScenario('completed');
        $operator = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Op', 'phone' => '8'.fake()->unique()->numerify('#########'), 'role' => 'operator', 'status' => 'active']);

        Livewire::actingAs($operator)->test(Earnings::class)->assertForbidden();
    }

    public function test_the_franchise_owner_role_now_includes_commissions_view(): void
    {
        $this->assertTrue(Role::where('slug', 'franchise_owner')->first()->permissions->contains('slug', 'commissions.view'));
    }
}
