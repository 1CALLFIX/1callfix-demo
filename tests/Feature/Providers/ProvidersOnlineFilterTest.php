<?php

namespace Tests\Feature\Providers;

use App\Livewire\Providers\Index;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Providers\Index::$onlineOnly — the filter the dashboard's "Providers
 * Online" card links to. Query-string bindable, narrows to is_online = true.
 */
class ProvidersOnlineFilterTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    public function test_online_only_narrows_to_currently_online_providers(): void
    {
        $scenario = $this->makeBookingScenario('searching_provider');
        $franchise = $scenario['franchise'];
        $zone = $scenario['zone'];

        $onlineProvider = $scenario['provider'];
        $onlineProvider->update(['kyc_status' => 'approved', 'is_online' => true]);
        $onlineProvider->user->update(['name' => 'Onlinesearch Person']);

        $offlineUser = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Offlinesearch Person',
            'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active', 'franchise_id' => $franchise->id,
        ]);
        Provider::create([
            'user_id' => $offlineUser->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'provider_type' => 'independent', 'kyc_status' => 'approved',
            'is_active' => true, 'is_online' => false,
        ]);

        $actor = $this->makeUserWithPermission('providers.view', 'global');

        // Default (no filter): both are visible under the approved tab.
        Livewire::actingAs($actor)->test(Index::class)
            ->set('statusFilter', 'approved')
            ->assertSee('Onlinesearch Person')
            ->assertSee('Offlinesearch Person');

        // onlineOnly: only the online one.
        Livewire::actingAs($actor)->test(Index::class)
            ->set('statusFilter', 'approved')
            ->set('onlineOnly', true)
            ->assertSee('Onlinesearch Person')
            ->assertDontSee('Offlinesearch Person');
    }

    public function test_online_only_is_query_string_bindable(): void
    {
        $actor = $this->makeUserWithPermission('providers.view', 'global');

        Livewire::actingAs($actor)->withQueryParams(['onlineOnly' => '1', 'statusFilter' => ''])
            ->test(Index::class)
            ->assertSet('onlineOnly', true);
    }
}
