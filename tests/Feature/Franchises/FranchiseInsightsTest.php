<?php

namespace Tests\Feature\Franchises;

use App\Livewire\Franchises\Insights;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Setting;
use App\Models\User;
use App\Support\InfoPages\FranchisePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/** Owner 2026-11-17: the franchise owner's live Insights dashboard, from real bookings only. */
class FranchiseInsightsTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private function owner($franchise): User
    {
        $owner = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Owner', 'phone' => '8'.fake()->unique()->numerify('#########'), 'role' => 'franchise_owner', 'status' => 'active']);
        $franchise->update(['owner_user_id' => $owner->id]);
        RoleAssignment::create(['user_id' => $owner->id, 'role_id' => Role::where('slug', 'franchise_owner')->value('id'), 'scope_type' => 'franchise', 'scope_id' => $franchise->id]);

        return $owner;
    }

    public function test_the_owner_sees_their_own_bookings_and_never_another_franchises(): void
    {
        ['booking' => $mine, 'franchise' => $franchise] = $this->makeBookingScenario('completed');
        ['booking' => $other, 'franchise' => $otherFranchise] = $this->makeBookingScenario('completed');
        $mine->update(['completed_at' => now()]);
        $other->update(['completed_at' => now()]);
        $owner = $this->owner($franchise);

        $c = Livewire::actingAs($owner)->test(Insights::class)->assertSet('franchiseId', $franchise->id)
            ->assertSee('Bookings placed')->assertSee('Completed jobs');
        $this->assertSame(1, $c->viewData('now')['completed']);

        Livewire::actingAs($owner)->withQueryParams(['franchiseId' => $otherFranchise->id])->test(Insights::class)
            ->assertSet('franchiseId', $franchise->id);
    }

    public function test_blocks_the_super_admin_switched_off_are_hidden_from_owners_only(): void
    {
        ['franchise' => $franchise] = $this->makeBookingScenario('completed');
        $owner = $this->owner($franchise);
        Setting::set(FranchisePage::CONTENT, json_encode(['insights_customers' => false, 'insights_services' => false] + FranchisePage::defaults()));

        Livewire::actingAs($owner)->test(Insights::class)
            ->assertSee('data-insights-funnel', false)
            ->assertDontSee('data-insights-customers', false)
            ->assertDontSee('data-insights-services', false);

        $super = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Admin', 'phone' => '8'.fake()->unique()->numerify('#########'), 'role' => 'super_admin', 'status' => 'active']);
        Livewire::actingAs($super)->test(Insights::class)->assertSee('data-insights-customers', false);
    }

    public function test_someone_without_a_franchise_is_refused(): void
    {
        $this->makeBookingScenario('completed');
        $operator = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Op', 'phone' => '8'.fake()->unique()->numerify('#########'), 'role' => 'operator', 'status' => 'active']);

        Livewire::actingAs($operator)->test(Insights::class)->assertForbidden();
    }
}
