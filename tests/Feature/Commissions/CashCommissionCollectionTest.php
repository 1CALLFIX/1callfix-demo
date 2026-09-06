<?php

namespace Tests\Feature\Commissions;

use App\Actions\CompleteBookingAction;
use App\Models\Commission;
use App\Models\Payout;
use App\Models\ProviderCommissionReceivable;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\CommissionService;
use App\Services\PayoutService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Cash-paid Service bookings: the provider collects the whole price in
 * cash, so platform + franchise commission never reaches the gateway.
 * CommissionService records what the provider owes as a
 * ProviderCommissionReceivable instead of crediting wallets, and
 * PayoutService recovers it from the wallet at payout-request time —
 * senior to the provider's own withdrawal, without ever loosening
 * WalletService's no-negative guard.
 *
 * Fixture franchise (makeFranchiseTree): platform_fee_percent = 5,
 * commission_value = 10, revenue_share. On a ₹500 booking that is
 * platform 25, franchise 50, provider 425.
 */
class CashCommissionCollectionTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private function completeCashBooking(): array
    {
        $scenario = $this->makeAssignedBookingScenario();
        $scenario['booking']->update(['payment_method' => 'cash']);
        $scenario['booking']->refresh();

        app(CompleteBookingAction::class)->execute($scenario['booking']->id, $scenario['provider'], '5678');

        $scenario['booking']->refresh();

        return $scenario;
    }

    public function test_a_completed_cash_booking_records_a_receivable_and_credits_no_wallets(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->completeCashBooking();

        // The commissions row is still written, exactly as for a digital booking.
        $commission = Commission::where('booking_id', $booking->id)->firstOrFail();
        $this->assertEquals(25.00, (float) $commission->platform_commission);
        $this->assertEquals(50.00, (float) $commission->franchise_commission);
        $this->assertEquals(425.00, (float) $commission->provider_commission);

        // No wallet movement for the provider's own share...
        $this->assertSame(0, WalletTransaction::where('ref', "booking:{$booking->id}:provider-earning")->count());
        $this->assertEquals(0.0, app(WalletService::class)->balance($provider->user));

        // ...and a receivable for platform + franchise portions.
        $receivable = ProviderCommissionReceivable::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame($provider->id, $receivable->provider_id);
        $this->assertSame($commission->id, $receivable->commission_id);
        $this->assertEquals(25.00, (float) $receivable->platform_portion);
        $this->assertEquals(50.00, (float) $receivable->franchise_portion);
        $this->assertEquals(75.00, (float) $receivable->amount_owed);
        $this->assertEquals(0.0, (float) $receivable->amount_settled);
        $this->assertSame('outstanding', $receivable->status);
    }

    public function test_a_completed_digital_booking_is_unchanged_no_receivable(): void
    {
        $scenario = $this->makeAssignedBookingScenario(); // payment_method = 'online'
        app(CompleteBookingAction::class)->execute($scenario['booking']->id, $scenario['provider'], '5678');

        $this->assertSame(0, ProviderCommissionReceivable::where('booking_id', $scenario['booking']->id)->count());
        $this->assertEquals(425.00, app(WalletService::class)->balance($scenario['provider']->user));
        $this->assertSame(1, WalletTransaction::where('ref', "booking:{$scenario['booking']->id}:provider-earning")->count());
    }

    public function test_applying_commission_twice_for_a_cash_booking_creates_one_receivable(): void
    {
        ['booking' => $booking] = $this->completeCashBooking();

        app(CommissionService::class)->applyForBooking($booking->fresh());

        $this->assertSame(1, ProviderCommissionReceivable::where('booking_id', $booking->id)->count());
        $this->assertSame(1, Commission::where('booking_id', $booking->id)->count());
    }

    public function test_payout_request_settles_the_cash_debt_first_then_pays_the_franchise_owner(): void
    {
        ['booking' => $booking, 'provider' => $provider, 'franchise' => $franchise] = $this->completeCashBooking();

        $owner = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Franchise Owner',
            'phone' => '9'.substr((string) time(), -9), 'role' => 'customer', 'status' => 'active',
        ]);
        $franchise->update(['owner_user_id' => $owner->id]);

        // Provider has ₹200 sitting in wallet from prior digital jobs.
        app(WalletService::class)->credit($provider->user, 200, reason: 'Prior digital earnings');

        // Request a ₹100 payout — the ₹75 cash debt is swept first.
        $payout = app(PayoutService::class)->request('provider', $provider->id, 100);

        $receivable = ProviderCommissionReceivable::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame('settled', $receivable->status);
        $this->assertEquals(75.00, (float) $receivable->amount_settled);
        $this->assertNotNull($receivable->settled_at);

        // 200 - 75 (settlement) - 100 (payout) = 25
        $this->assertEquals(25.00, app(WalletService::class)->balance($provider->user));
        $this->assertEquals(100, $payout->amount);

        // Franchise owner credited their portion, once the row was fully settled.
        $this->assertEquals(50.00, app(WalletService::class)->balance($owner));
        $this->assertSame(1, WalletTransaction::where('ref', "cash-commission:{$receivable->id}:franchise-earning")->count());
    }

    public function test_payout_is_blocked_when_the_wallet_cannot_cover_the_cash_debt(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->completeCashBooking();

        // Only ₹30 in wallet against a ₹75 debt.
        app(WalletService::class)->credit($provider->user, 30, reason: 'Partial digital earnings');

        try {
            app(PayoutService::class)->request('provider', $provider->id, 10);
            $this->fail('Expected the payout to be blocked by outstanding cash debt.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('unsettled cash-commission', $e->getMessage());
        }

        // Partial settlement still happened: wallet drained, receivable advanced but still outstanding.
        $receivable = ProviderCommissionReceivable::where('booking_id', $booking->id)->firstOrFail();
        $this->assertEquals(30.00, (float) $receivable->amount_settled);
        $this->assertSame('outstanding', $receivable->status);
        $this->assertEquals(0.0, app(WalletService::class)->balance($provider->user));
        $this->assertSame(0, Payout::where('payee_id', $provider->id)->where('payee_type', 'provider')->count());
    }

    public function test_a_provider_with_no_cash_debt_requests_a_payout_normally(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        app(WalletService::class)->credit($provider->user, 500, reason: 'Digital earnings');

        $payout = app(PayoutService::class)->request('provider', $provider->id, 300);

        $this->assertSame('pending', $payout->status);
        $this->assertEquals(200, app(WalletService::class)->balance($provider->user));
    }

    public function test_outstanding_cash_commission_helper_reports_the_running_total(): void
    {
        ['provider' => $provider] = $this->completeCashBooking();

        $this->assertEquals(75.00, app(PayoutService::class)->outstandingCashCommission($provider->id));

        // Cover part of it and re-check.
        app(WalletService::class)->credit($provider->user, 40, reason: 'Digital earnings');
        try {
            app(PayoutService::class)->request('provider', $provider->id, 1);
        } catch (\RuntimeException) {
            // blocked — still owes 35
        }

        $this->assertEquals(35.00, app(PayoutService::class)->outstandingCashCommission($provider->id));
    }
}
