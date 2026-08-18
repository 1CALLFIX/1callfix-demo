<?php

namespace Tests\Feature\Payouts;

use App\Livewire\Payouts\Manage as PayoutsManage;
use App\Models\Payout;
use App\Services\PayoutService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Admin Command Center mission (Finance audit finding) — CommissionService
 * has credited a FieldWorker's wallet identically to a Provider's since
 * Parcel/Taxi/Marketplace shipped (applyForFieldWorkerOrder()), but
 * PayoutService::request() only ever recognized 'provider'/'franchise_owner'
 * payees -- there was no way to turn that real, accruing balance into a
 * payout request. This suite covers the new 'field_worker' payee type:
 * service-level request/scope, the admin screen's payee search + create
 * flow, and row-level scope resolution (payoutScope() -> authorizationScopeHint()).
 */
class FieldWorkerPayoutTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;

    public function test_payout_service_requests_a_payout_for_a_field_worker(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $worker = $this->makeParcelRiderIn($franchise, $zone);
        app(WalletService::class)->credit($worker->user, 1000, reason: 'Test earnings');

        $payout = app(PayoutService::class)->request('field_worker', $worker->id, 300);

        $this->assertSame('field_worker', $payout->payee_type);
        $this->assertSame($worker->id, $payout->payee_id);
        $this->assertEquals(300, $payout->amount);
        $this->assertSame('pending', $payout->status);
        $this->assertEquals(700, app(WalletService::class)->balance($worker->user));
    }

    public function test_payout_service_resolves_the_field_workers_own_user_as_payee(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $worker = $this->makeParcelRiderIn($franchise, $zone);

        $resolved = app(PayoutService::class)->resolvePayeeUser('field_worker', $worker->id);

        $this->assertSame($worker->user_id, $resolved->id);
    }

    public function test_payout_scope_resolves_from_the_field_workers_own_zone_and_franchise(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $worker = $this->makeParcelRiderIn($franchise, $zone);

        $scope = app(PayoutService::class)->payoutScope('field_worker', $worker->id);

        $this->assertSame($zone->id, $scope['zone_id']);
        $this->assertSame($franchise->id, $scope['franchise_id']);
        $this->assertSame($city->id, $scope['city_id']);
        $this->assertSame($country->id, $scope['country_id']);
    }

    public function test_admin_screen_can_search_and_request_a_field_worker_payout(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $worker = $this->makeParcelRiderIn($franchise, $zone);
        app(WalletService::class)->credit($worker->user, 500, reason: 'Test earnings');

        $actor = $this->makeUserWithPermission('payouts.manage', 'global');

        Livewire::actingAs($actor)->test(PayoutsManage::class)
            ->set('payeeType', 'field_worker')
            ->set('payeeSearch', $worker->user->name)
            ->assertSee($worker->user->name)
            ->call('selectPayee', $worker->id, $worker->user->name)
            ->set('amount', '200')
            ->call('request')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('payouts', ['payee_type' => 'field_worker', 'payee_id' => $worker->id, 'amount' => 200]);
    }

    public function test_row_level_scope_filters_field_worker_payouts_by_zone(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $mine = $this->makeParcelRiderIn($franchise, $zone);
        [$c2, $ci2, $f2, $z2] = $this->makeFranchiseTree();
        $other = $this->makeParcelRiderIn($f2, $z2);

        $myPayout = Payout::create(['payee_type' => 'field_worker', 'payee_id' => $mine->id, 'amount' => 100, 'status' => 'pending', 'period_start' => now()->subDays(7), 'period_end' => now()]);
        $otherPayout = Payout::create(['payee_type' => 'field_worker', 'payee_id' => $other->id, 'amount' => 100, 'status' => 'pending', 'period_start' => now()->subDays(7), 'period_end' => now()]);

        $actor = $this->makeUserWithPermission('payouts.manage', 'zone', $zone->id);

        $ids = Livewire::actingAs($actor)->test(PayoutsManage::class)
            ->viewData('payouts')->pluck('id')->all();

        $this->assertContains($myPayout->id, $ids);
        $this->assertNotContains($otherPayout->id, $ids);
    }
}
