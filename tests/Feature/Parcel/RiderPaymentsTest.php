<?php

namespace Tests\Feature\Parcel;

use App\Livewire\Rider\PaymentAccounts as RiderPaymentAccounts;
use App\Livewire\Rider\RequestPayout as RiderRequestPayout;
use App\Models\FieldWorker;
use App\Models\PaymentAccount;
use App\Models\Payout;
use App\Services\WalletService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Riders get the partner Payment Accounts and Request Payout screens (reused, not rebuilt), plus a sign-up link to
 * /partners and Forgot password on the rider sign-in.
 */
class RiderPaymentsTest extends TestCase
{
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use RefreshDatabase;

    private FieldWorker $rider;

    protected function setUp(): void
    {
        parent::setUp();
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->rider = $this->makeParcelRiderIn($franchise, $zone);
    }

    private function verifiedUpi(?int $userId = null): PaymentAccount
    {
        return PaymentAccount::create([
            'user_id' => $userId ?? $this->rider->user_id, 'account_type' => 'upi', 'upi_id' => 'rider@upi',
            'is_default' => true, 'is_verified' => true,
        ]);
    }

    public function test_rider_pages_open_with_the_rider_tabs(): void
    {
        $this->actingAs($this->rider->user);

        foreach (['rider.earnings', 'rider.payment-accounts', 'rider.request-payout'] as $page) {
            $this->get(route($page))->assertOk()
                ->assertSee(route('rider.payment-accounts'))->assertSee(route('rider.request-payout'))
                ->assertDontSee(route('provider.wallet'));
        }
    }

    public function test_a_rider_adds_an_account_that_waits_for_admin_verification(): void
    {
        Livewire::actingAs($this->rider->user)->test(RiderPaymentAccounts::class)
            ->call('startCreate')->set('accountType', 'upi')->set('upiId', 'ravi@okaxis')->call('save')
            ->assertSet('flashType', 'success');

        $account = PaymentAccount::where('user_id', $this->rider->user_id)->sole();
        $this->assertFalse($account->is_verified);
    }

    public function test_a_rider_cannot_open_someone_elses_account(): void
    {
        $other = $this->makeCustomer();
        $foreign = $this->verifiedUpi($other->id);

        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($this->rider->user)->test(RiderPaymentAccounts::class)->call('startEdit', $foreign->id);
    }

    public function test_without_a_verified_account_there_is_nothing_to_request(): void
    {
        Livewire::actingAs($this->rider->user)->test(RiderRequestPayout::class)
            ->assertSee('verified payment account yet')->assertSeeHtml('bg-slate-900 text-white">Request Payout')
            ->call('request')->assertHasErrors(['paymentAccountId']);
        $this->assertSame(0, Payout::count());
    }

    public function test_a_rider_requests_a_payout_to_their_verified_account(): void
    {
        $account = $this->verifiedUpi();
        app(WalletService::class)->credit($this->rider->user, 300, 'test_parcel_earning');

        Livewire::actingAs($this->rider->user)->test(RiderRequestPayout::class)
            ->assertSet('paymentAccountId', $account->id)
            ->set('amount', '200')->call('request')
            ->assertSet('flashType', 'success');

        $payout = Payout::sole();
        $this->assertSame('field_worker', $payout->payee_type);
        $this->assertSame($this->rider->id, (int) $payout->payee_id);
        $this->assertSame($account->id, (int) $payout->payment_account_id);
        $this->assertEqualsWithDelta(100.0, app(WalletService::class)->balance($this->rider->user->fresh()), 0.001);
    }

    public function test_a_rider_cannot_pay_out_to_someone_elses_account(): void
    {
        $foreign = $this->verifiedUpi($this->makeCustomer()->id);
        app(WalletService::class)->credit($this->rider->user, 300, 'test_parcel_earning');

        Livewire::actingAs($this->rider->user)->test(RiderRequestPayout::class)
            ->set('paymentAccountId', $foreign->id)->set('amount', '100')->call('request')
            ->assertSet('flashType', 'error');
        $this->assertSame(0, Payout::count());
    }

    public function test_rider_sign_in_links_to_the_partner_page_and_forgot_password(): void
    {
        $this->get(route('rider.login'))->assertOk()
            ->assertSee(route('customer.partners').'#join')
            ->assertSee(route('customer.password.forgot'))
            ->assertDontSee('Ask your local');
    }
}
