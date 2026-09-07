<?php

namespace Tests\Feature\Operations;

use App\Models\Review;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Operations\DataClearService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\DataClearTestHelpers;
use Tests\TestCase;

/**
 * Clear Data tool — the real clear-and-verify test explicitly required
 * before this tool can be trusted: seed known data across both a table
 * that SHOULD be cleared and one that should NOT be, run the actual
 * DataClearService::run() (with only the mysqldump shell-out faked — see
 * DataClearTestHelpers), and confirm from the database itself that
 * exactly the selected module's tables emptied and nothing else changed.
 */
class DataClearRealClearTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use DataClearTestHelpers;

    private function makeSuperAdmin(): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Super Admin',
            'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    public function test_clearing_bookings_empties_exactly_that_modules_tables_and_nothing_else(): void
    {
        // ---- Seed: two real bookings with a real cascading child (reviews) ----
        $scenario1 = $this->makeAssignedBookingScenario();
        $scenario2 = $this->makeAssignedBookingScenario();

        Review::create(['booking_id' => $scenario1['booking']->id, 'customer_id' => $scenario1['customer']->id, 'provider_id' => $scenario1['provider']->id, 'rating' => 5]);
        Review::create(['booking_id' => $scenario2['booking']->id, 'customer_id' => $scenario2['customer']->id, 'provider_id' => $scenario2['provider']->id, 'rating' => 4]);

        $this->assertSame(2, \App\Models\Booking::count());
        $this->assertSame(2, Review::count());

        // ---- Seed: an UNRELATED table this run must NOT touch ----
        app(WalletService::class)->credit($scenario1['customer'], 100, reason: 'control group -- must survive');
        app(WalletService::class)->credit($scenario2['customer'], 50, reason: 'control group -- must survive');
        $walletTxCountBefore = WalletTransaction::count();
        $this->assertGreaterThanOrEqual(2, $walletTxCountBefore);

        // ---- Run the tool against ONLY the "bookings" module ----
        $this->bindWorkingDataClearBackup();
        $actor = $this->makeSuperAdmin();

        // Captured AFTER the actor exists -- it's a real (non-customer)
        // user too, and must be part of the "before" baseline so the
        // "after" comparison below reflects only what the CLEAR did, not
        // this test's own fixture setup.
        $staffCountBefore = User::where('role', '!=', 'customer')->count();

        $expected = app(DataClearService::class)->generateConfirmationPhrase(['bookings']);

        $result = app(DataClearService::class)->run($actor, ['bookings'], $expected, $expected, '127.0.0.1');

        $this->assertFalse($result->refused, $result->reason ?? '');

        // ---- Verify: exactly the bookings module's tables are empty ----
        $this->assertSame(0, \App\Models\Booking::count());
        $this->assertSame(0, Review::count());
        $this->assertSame(0, \App\Models\ChatMessage::count());
        $this->assertSame(0, \App\Models\BookingCompensation::count());
        $this->assertSame(0, \App\Models\DispatchAttempt::count());
        $this->assertSame(0, \App\Models\ProviderCommissionReceivable::count());

        // ---- Verify: nothing else changed ----
        $this->assertSame($walletTxCountBefore, WalletTransaction::count(), 'wallet_transactions was not selected and must be untouched.');
        $this->assertEquals(100.0, app(WalletService::class)->balance($scenario1['customer']->fresh()), 'Wallet balances (a separate stored column) must be unaffected.');
        $this->assertSame($staffCountBefore, User::where('role', '!=', 'customer')->count(), 'users must never be touched by this tool at all.');
        $this->assertGreaterThan(0, User::count(), 'The actor and every customer/provider account must still exist.');
        $this->assertTrue($actor->fresh()->exists());
    }

    /** The reverse case, proving the tool is genuinely scoped by selection, not just "clears everything it knows about". */
    public function test_clearing_wallet_transactions_leaves_bookings_completely_untouched(): void
    {
        $scenario = $this->makeAssignedBookingScenario();
        Review::create(['booking_id' => $scenario['booking']->id, 'customer_id' => $scenario['customer']->id, 'provider_id' => $scenario['provider']->id, 'rating' => 5]);
        app(WalletService::class)->credit($scenario['customer'], 100, reason: 'to be cleared');

        $this->bindWorkingDataClearBackup();
        $actor = $this->makeSuperAdmin();
        $expected = app(DataClearService::class)->generateConfirmationPhrase(['wallet_transactions']);

        $result = app(DataClearService::class)->run($actor, ['wallet_transactions'], $expected, $expected, '127.0.0.1');

        $this->assertFalse($result->refused, $result->reason ?? '');
        $this->assertSame(0, WalletTransaction::count());

        // Untouched.
        $this->assertSame(1, \App\Models\Booking::count());
        $this->assertSame(1, Review::count());
    }
}
