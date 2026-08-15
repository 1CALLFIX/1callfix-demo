<?php

namespace Tests\Feature\Operations;

use App\Exports\CommissionsExport;
use App\Exports\PayoutsExport;
use App\Models\Commission;
use App\Models\PaymentAccount;
use App\Models\Payout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Mission Phase 14 (Operations Import/Export completeness). The historical
 * reference product's "Earnings"/"Payouts" data-export screens map onto
 * 1CallFix's own commissions ledger and Payout model — real, already-
 * computed source-of-truth data, never recalculated. Row-level scope and
 * sensitive-field exclusion are the two things this suite exists to prove.
 */
class DataExportTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    public function test_commissions_export_only_includes_the_viewers_own_franchise(): void
    {
        $ownScenario = $this->makeBookingScenario('completed');
        $otherScenario = $this->makeBookingScenario('completed');

        Commission::create(['booking_id' => $ownScenario['booking']->id, 'provider_commission' => 80, 'franchise_commission' => 10, 'platform_commission' => 10]);
        Commission::create(['booking_id' => $otherScenario['booking']->id, 'provider_commission' => 80, 'franchise_commission' => 10, 'platform_commission' => 10]);

        $viewer = $this->makeUserWithPermission('commissions.view', 'franchise', $ownScenario['franchise']->id);

        $rows = (new CommissionsExport($viewer))->collection();

        $this->assertCount(1, $rows);
        $this->assertSame($ownScenario['booking']->code, $rows->first()->booking->code);
    }

    public function test_commissions_export_shows_everything_for_a_global_grant(): void
    {
        $scenarioA = $this->makeBookingScenario('completed');
        $scenarioB = $this->makeBookingScenario('completed');
        Commission::create(['booking_id' => $scenarioA['booking']->id, 'provider_commission' => 80, 'franchise_commission' => 10, 'platform_commission' => 10]);
        Commission::create(['booking_id' => $scenarioB['booking']->id, 'provider_commission' => 80, 'franchise_commission' => 10, 'platform_commission' => 10]);

        $viewer = $this->makeUserWithPermission('commissions.view', 'global');

        $this->assertCount(2, (new CommissionsExport($viewer))->collection());
    }

    public function test_commissions_export_never_recalculates_values(): void
    {
        $scenario = $this->makeBookingScenario('completed');
        Commission::create(['booking_id' => $scenario['booking']->id, 'provider_commission' => 123.45, 'franchise_commission' => 6.78, 'platform_commission' => 9.01]);
        $viewer = $this->makeUserWithPermission('commissions.view', 'global');

        $row = (new CommissionsExport($viewer))->collection()->first();
        $mapped = (new CommissionsExport($viewer))->map($row);

        $this->assertEquals(123.45, $mapped[3]);
        $this->assertEquals(6.78, $mapped[4]);
        $this->assertEquals(9.01, $mapped[5]);
    }

    public function test_payouts_export_never_includes_raw_account_number_or_ifsc(): void
    {
        $scenario = $this->makeBookingScenario();
        $account = PaymentAccount::create([
            'user_id' => $scenario['customer']->id, 'account_type' => 'bank',
            'account_holder_name' => 'Test Holder', 'account_number' => '1234567890123456', 'ifsc' => 'HDFC0001234',
            'is_verified' => true, 'is_default' => true,
        ]);
        $payout = Payout::create([
            'payee_type' => 'franchise_owner', 'payee_id' => $scenario['customer']->id, 'payment_account_id' => $account->id,
            'amount' => 500, 'status' => 'pending', 'period_start' => now()->subDays(7), 'period_end' => now(),
        ]);
        $viewer = $this->makeUserWithPermission('payouts.manage', 'global');

        // map() is a pure per-row transform, independent of collection()'s
        // own scope filtering -- any viewer works here, same as before this
        // phase's TECH-1 fix (only the constructor gained a required arg).
        $mapped = (new PayoutsExport($viewer))->map($payout->fresh(['paymentAccount']));
        $settlementColumn = $mapped[6];

        $this->assertStringNotContainsString('1234567890123456', $settlementColumn);
        $this->assertStringNotContainsString('HDFC0001234', $settlementColumn);
        $this->assertStringContainsString('3456', $settlementColumn); // last 4 digits only
    }

    public function test_payouts_export_amount_matches_the_real_payout_record(): void
    {
        $scenario = $this->makeBookingScenario();
        $payout = Payout::create(['payee_type' => 'franchise_owner', 'payee_id' => $scenario['customer']->id, 'amount' => 777.50, 'status' => 'paid', 'period_start' => now()->subDays(7), 'period_end' => now()]);
        $viewer = $this->makeUserWithPermission('payouts.manage', 'global');

        $mapped = (new PayoutsExport($viewer))->map($payout);

        $this->assertEquals(777.50, $mapped[2]);
        $this->assertSame('paid', $mapped[5]);
    }

    /**
     * Phase 21 item TECH-1: PayoutsExport previously deliberately matched
     * Payouts\Manage's own then-unscoped render() -- both are now scoped
     * together in the same pass, mirroring CommissionsExport's own
     * constructor-injection + AuthorizationService pattern exactly.
     */
    public function test_payouts_export_only_includes_the_viewers_own_franchise(): void
    {
        $ownScenario = $this->makeBookingScenario();
        $otherScenario = $this->makeBookingScenario();
        $myPayout = Payout::create(['payee_type' => 'provider', 'payee_id' => $ownScenario['provider']->id, 'amount' => 100, 'status' => 'pending', 'period_start' => now()->subDays(7), 'period_end' => now()]);
        $otherPayout = Payout::create(['payee_type' => 'provider', 'payee_id' => $otherScenario['provider']->id, 'amount' => 200, 'status' => 'pending', 'period_start' => now()->subDays(7), 'period_end' => now()]);

        $viewer = $this->makeUserWithPermission('payouts.manage', 'franchise', $ownScenario['franchise']->id);

        $rows = (new PayoutsExport($viewer))->collection();

        $this->assertTrue($rows->contains('id', $myPayout->id));
        $this->assertFalse($rows->contains('id', $otherPayout->id));
    }

    public function test_payouts_export_shows_everything_for_a_global_grant(): void
    {
        $scenarioA = $this->makeBookingScenario();
        $scenarioB = $this->makeBookingScenario();
        $payoutA = Payout::create(['payee_type' => 'provider', 'payee_id' => $scenarioA['provider']->id, 'amount' => 100, 'status' => 'pending', 'period_start' => now()->subDays(7), 'period_end' => now()]);
        $payoutB = Payout::create(['payee_type' => 'provider', 'payee_id' => $scenarioB['provider']->id, 'amount' => 200, 'status' => 'pending', 'period_start' => now()->subDays(7), 'period_end' => now()]);

        $viewer = $this->makeUserWithPermission('payouts.manage', 'global');

        $rows = (new PayoutsExport($viewer))->collection();

        $this->assertTrue($rows->contains('id', $payoutA->id));
        $this->assertTrue($rows->contains('id', $payoutB->id));
    }
}
