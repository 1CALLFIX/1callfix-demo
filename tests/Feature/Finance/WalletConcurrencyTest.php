<?php

namespace Tests\Feature\Finance;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase D — the wallet cannot be spent twice.
 *
 * The invariant under test, stated once: the sum of successful debits can
 * never exceed what was in the wallet. Everything below is a way of trying
 * to break that.
 *
 * ── The honest limitation ─────────────────────────────────────────────────
 * Same one HotelAvailabilityConcurrencyTest and RentalAvailabilityConcurrency-
 * Test already document: PHPUnit is single-threaded and the suite runs on an
 * in-memory SQLite connection, so nothing here launches two real simultaneous
 * HTTP requests. What it does instead is attack the specific assumption a
 * double-spend depends on — that a balance READ can be reused later as if it
 * were still true. WalletService::applyTransaction() wraps every movement in
 * DB::transaction() and re-reads the wallet under lockForUpdate() rather than
 * trusting anything the caller already read; the tests below pin that
 * re-read, because it is the part that makes the row lock work in production
 * (where lockForUpdate() is a real MySQL/Postgres lock rather than the no-op
 * it compiles to on SQLite).
 *
 * No new locking mechanism was introduced for any of this. WalletService
 * already had one.
 */
class WalletConcurrencyTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    private function world(float $walletBalance, float $servicePrice): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $category = $this->makeCategory();
        $service = $this->makeService($category, ['base_price' => $servicePrice]);
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        // Funded through the real WalletService rather than by writing a
        // balance straight onto the row, so the opening balance has a ledger
        // row behind it and assertLedgerReconciles() is measuring the system
        // rather than a fixture shortcut.
        app(WalletService::class)->credit($customer, $walletBalance, 'opening balance', "test:opening:{$customer->id}");

        return compact('franchise', 'zone', 'service', 'customer', 'address');
    }

    private function attemptWalletBooking(array $world): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($world['customer'], 'sanctum')->postJson('/api/bookings', [
            'service_id' => $world['service']->id,
            'address_id' => $world['address']->id,
            'payment_method' => 'wallet',
        ]);
    }

    /** balance == credits - debits, read straight from the ledger. */
    private function assertLedgerReconciles(int $userId): void
    {
        $wallet = Wallet::where('user_id', $userId)->firstOrFail();
        $rows = WalletTransaction::where('wallet_id', $wallet->id)->where('status', 'successful')->get();

        $computed = $rows->sum(fn (WalletTransaction $t) => $t->is_credit ? (float) $t->amount : -(float) $t->amount);

        $this->assertEquals((float) $wallet->balance, $computed,
            'The stored balance must equal the sum of its own ledger rows.');
    }

    // ==================== The scenario named in the brief ====================

    /**
     * 500 in the wallet, two bookings needing 300 each. One must win, one
     * must be refused, and the wallet must end on 200 — never -100, and
     * never 200 with two debits recorded.
     */
    public function test_two_wallet_bookings_of_300_against_a_balance_of_500_cannot_both_succeed(): void
    {
        $world = $this->world(walletBalance: 500, servicePrice: 300);

        $first = $this->attemptWalletBooking($world);
        $second = $this->attemptWalletBooking($world);

        $first->assertStatus(201);
        $second->assertStatus(409);

        $this->assertEquals(200.00, (float) $world['customer']->wallet->fresh()->balance);
        $this->assertSame(1, Booking::count(), 'The refused attempt must not leave a booking behind.');
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, WalletTransaction::where('is_credit', false)->count());
        $this->assertLedgerReconciles($world['customer']->id);
    }

    /**
     * The same attack one level down, against WalletService itself, so the
     * guarantee is pinned at the engine rather than only at the endpoint
     * that happens to use it today.
     */
    public function test_a_second_debit_that_would_overdraw_is_refused_by_the_service(): void
    {
        $world = $this->world(walletBalance: 500, servicePrice: 300);
        $wallets = app(WalletService::class);

        $wallets->debit($world['customer'], 300, 'first', 'test:1');

        try {
            $wallets->debit($world['customer'], 300, 'second', 'test:2');
            $this->fail('A debit that would overdraw the wallet must throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Insufficient wallet balance', $e->getMessage());
        }

        $this->assertEquals(200.00, (float) $world['customer']->wallet->fresh()->balance);
        $this->assertSame(1, WalletTransaction::where('is_credit', false)->count());
        $this->assertLedgerReconciles($world['customer']->id);
    }

    /**
     * The actual mechanism of a double-spend: two callers that each decided
     * they could afford it from the SAME earlier read of the balance. The
     * second debit must still be refused, because the service re-reads the
     * wallet inside its own transaction instead of trusting that snapshot.
     */
    public function test_a_stale_balance_snapshot_does_not_authorise_a_second_debit(): void
    {
        $world = $this->world(walletBalance: 500, servicePrice: 300);
        $wallets = app(WalletService::class);

        // Both "requests" check affordability here, against 500, and both
        // conclude they may proceed.
        $snapshot = $wallets->balance($world['customer']);
        $this->assertEquals(500.00, $snapshot);
        $this->assertTrue($snapshot >= 300);

        $wallets->debit($world['customer'], 300, 'request A', 'test:a');

        $this->expectException(\RuntimeException::class);
        $wallets->debit($world['customer'], 300, 'request B', 'test:b');
    }

    public function test_a_refused_debit_writes_no_ledger_row_and_moves_no_money(): void
    {
        $world = $this->world(walletBalance: 100, servicePrice: 300);

        $this->attemptWalletBooking($world)->assertStatus(409);

        $this->assertEquals(100.00, (float) $world['customer']->wallet->fresh()->balance);
        $this->assertSame(0, WalletTransaction::where('is_credit', false)->count());
        $this->assertSame(0, Booking::count());
        $this->assertSame(0, Payment::count());
        $this->assertLedgerReconciles($world['customer']->id);
    }

    /** Exact-balance is affordable; one rupee more is not. The boundary, not an approximation of it. */
    public function test_the_boundary_is_the_balance_itself(): void
    {
        $exact = $this->world(walletBalance: 300, servicePrice: 300);
        $this->attemptWalletBooking($exact)->assertStatus(201);
        $this->assertEquals(0.00, (float) $exact['customer']->wallet->fresh()->balance);

        $short = $this->world(walletBalance: 299.99, servicePrice: 300);
        $this->attemptWalletBooking($short)->assertStatus(409);
        $this->assertEquals(299.99, (float) $short['customer']->wallet->fresh()->balance);
    }

    /**
     * Booking creation and the wallet debit share one transaction, so a
     * failure anywhere inside it must leave neither. Driven here through a
     * real rule rather than a simulated fault: a flash sale limited to one
     * redemption per customer refuses the customer's second booking, and
     * that refusal has to unwind the booking row as well.
     */
    public function test_a_booking_rejected_mid_transaction_leaves_no_booking_and_no_debit(): void
    {
        $world = $this->world(walletBalance: 5000, servicePrice: 500);
        $this->makeFlashSale([$world['service']], ['per_customer_limit' => 1]);

        $this->attemptWalletBooking($world)->assertStatus(201);
        $balanceAfterFirst = (float) $world['customer']->wallet->fresh()->balance;
        $this->assertEquals(4600.00, $balanceAfterFirst, 'The first booking pays the 20% sale price of 400.');

        $this->attemptWalletBooking($world)->assertStatus(409);

        $this->assertSame(1, Booking::count(), 'The rolled-back attempt must not leave a booking.');
        $this->assertSame(1, Payment::count());
        $this->assertEquals($balanceAfterFirst, (float) $world['customer']->wallet->fresh()->balance,
            'A rolled-back booking must not have moved any money.');
        $this->assertLedgerReconciles($world['customer']->id);
    }

    public function test_a_zero_or_negative_debit_is_rejected_outright(): void
    {
        $world = $this->world(walletBalance: 500, servicePrice: 300);
        $wallets = app(WalletService::class);

        foreach ([0, -50] as $amount) {
            try {
                $wallets->debit($world['customer'], $amount, 'nonsense', 'test:'.$amount);
                $this->fail("A debit of {$amount} must be rejected.");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('must be positive', $e->getMessage());
            }
        }

        $this->assertEquals(500.00, (float) $world['customer']->wallet->fresh()->balance);
        $this->assertSame(0, WalletTransaction::where('is_credit', false)->count());
    }
}
