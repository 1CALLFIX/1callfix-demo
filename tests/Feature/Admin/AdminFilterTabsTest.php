<?php

namespace Tests\Feature\Admin;

use App\Livewire\Payments\Index as PaymentsIndex;
use App\Livewire\Providers\Index as ProvidersIndex;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-ADMIN-TABS-001 — every admin list opens on "All" with tab rows.
 */
class AdminFilterTabsTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    private function provider(string $name, $franchise, $zone, string $kyc): Provider
    {
        $user = User::create([
            'uuid' => (string) Str::uuid(), 'name' => $name, 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active', 'franchise_id' => $franchise->id,
        ]);

        return Provider::create([
            'user_id' => $user->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'provider_type' => 'independent', 'kyc_status' => $kyc, 'is_active' => true,
        ]);
    }

    public function test_providers_open_on_all_with_counts_and_each_tab_filters(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->provider('Pia Pending', $franchise, $zone, 'pending');
        $this->provider('Ari Approved', $franchise, $zone, 'approved');
        $this->provider('Ravi Rejected', $franchise, $zone, 'rejected');

        $c = Livewire::actingAs($this->makeSuperAdmin())->test(ProvidersIndex::class)
            ->assertSet('statusFilter', '')
            ->assertSee('Pia Pending')->assertSee('Ari Approved')->assertSee('Ravi Rejected')
            ->assertSeeHtml('aria-selected="true"');

        $this->assertSame(3, $c->viewData('counts')['']);

        $c->set('statusFilter', 'pending')->assertSee('Pia Pending')->assertDontSee('Ari Approved')->assertDontSee('Ravi Rejected');
        $c->set('statusFilter', 'approved')->assertSee('Ari Approved')->assertDontSee('Pia Pending');
        $c->set('statusFilter', 'rejected')->assertSee('Ravi Rejected')->assertDontSee('Ari Approved');
    }

    public function test_payments_method_tabs_split_online_wallet_and_cash(): void
    {
        $online = $this->makeBookingScenario();
        $wallet = $this->makeBookingScenario();
        $cash = $this->makeBookingScenario();
        $cash['booking']->update(['payment_method' => 'cash']);

        $pOnline = Payment::create(['booking_id' => $online['booking']->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'captured']);
        $pWallet = Payment::create(['booking_id' => $wallet['booking']->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'wallet', 'status' => 'captured']);

        $c = Livewire::actingAs($this->makeSuperAdmin())->test(PaymentsIndex::class)->assertSet('methodFilter', '');
        $ids = fn () => $c->viewData('payments')->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$pOnline->id, $pWallet->id], $ids());

        $c->set('methodFilter', 'online');
        $this->assertSame([$pOnline->id], $ids());

        $c->set('methodFilter', 'wallet');
        $this->assertSame([$pWallet->id], $ids());

        $c->set('methodFilter', 'cash');
        $this->assertSame([], $ids());
        $c->assertSee($cash['booking']->code)->assertDontSee($online['booking']->code);
    }

    public function test_payments_status_tabs_filter(): void
    {
        $a = $this->makeBookingScenario();
        $b = $this->makeBookingScenario();
        $pending = Payment::create(['booking_id' => $a['booking']->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'pending']);
        $captured = Payment::create(['booking_id' => $b['booking']->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'captured']);

        $c = Livewire::actingAs($this->makeSuperAdmin())->test(PaymentsIndex::class)->set('statusFilter', 'captured');

        $this->assertSame([$captured->id], $c->viewData('payments')->pluck('id')->all());
        $this->assertNotContains($pending->id, $c->viewData('payments')->pluck('id')->all());
    }
}
