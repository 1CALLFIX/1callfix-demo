<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Dashboard;
use App\Models\Franchise;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Dashboard stat cards drill into EXISTING admin screens pre-filtered via
 * their own query-string bindings — no dead numbers, no invented pages.
 * Also covers the new Commissions card and its "rates not set" note
 * (Part A finding surfaced on the dashboard).
 */
class DashboardCardLinksTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    private function fullyPermittedActor(): \App\Models\User
    {
        $actor = $this->makeUserWithPermission('dashboard.view', 'global');
        foreach (['bookings.view', 'providers.view', 'franchises.manage', 'commissions.view'] as $p) {
            $this->grantPermission($actor, $p, 'global');
        }

        return $actor;
    }

    public function test_pipeline_and_stat_cards_link_to_filtered_screens(): void
    {
        $this->makeBookingScenario('searching_provider');
        $actor = $this->fullyPermittedActor();

        $html = Livewire::actingAs($actor)->test(Dashboard::class)->assertOk()->html();

        // Pipeline stage cards -> Bookings filtered by that status.
        foreach (['searching_provider', 'assigned', 'in_progress', 'completed', 'cancelled', 'disputed'] as $status) {
            $this->assertStringContainsString('/admin/bookings?statusFilter='.$status, $html);
        }

        // Revenue Today -> Commissions filtered to today.
        $this->assertStringContainsString('/admin/commissions?fromDate='.now()->toDateString(), $html);

        // Providers Online -> Providers list narrowed to online-now.
        $this->assertStringContainsString('onlineOnly=1', $html);

        // Active Franchises -> Franchises filtered to active.
        $this->assertStringContainsString('/admin/franchises?filterStatus=active', $html);

        // Unassigned Bookings -> Bookings filtered to searching_provider.
        $this->assertStringContainsString('/admin/bookings?statusFilter=searching_provider', $html);
    }

    public function test_commissions_card_present_and_flags_unset_rates(): void
    {
        $actor = $this->fullyPermittedActor();

        Livewire::actingAs($actor)->test(Dashboard::class)
            ->assertOk()
            ->assertSee('Commissions')
            ->assertSee('Rates not set — 100% to providers');
    }

    public function test_commissions_card_note_clears_once_a_rate_is_configured(): void
    {
        $this->makeBookingScenario('completed');
        Franchise::query()->update(['status' => 'active', 'platform_fee_percent' => 10]);

        $actor = $this->fullyPermittedActor();

        Livewire::actingAs($actor)->test(Dashboard::class)
            ->assertOk()
            ->assertSee('Commissions')
            ->assertDontSee('Rates not set — 100% to providers');
    }

    public function test_cards_are_plain_text_without_the_underlying_permission(): void
    {
        // dashboard.view only — no bookings/providers/franchises/commissions.
        $actor = $this->makeUserWithPermission('dashboard.view', 'global');

        $html = Livewire::actingAs($actor)->test(Dashboard::class)->assertOk()->html();

        $this->assertStringNotContainsString('/admin/bookings?statusFilter=', $html);
        $this->assertStringNotContainsString('/admin/commissions', $html);
        $this->assertStringNotContainsString('onlineOnly=1', $html);
    }
}
