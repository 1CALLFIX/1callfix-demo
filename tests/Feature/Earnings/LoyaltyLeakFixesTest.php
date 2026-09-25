<?php

namespace Tests\Feature\Earnings;

use App\Models\LoyaltyPoint;
use App\Models\Referral;
use App\Models\Setting;
use App\Services\LoyaltyService;
use App\Services\ReferralService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — D1 (providers cannot turn points into
 * cash) and D2 (FIFO expiry: the balance can never go negative).
 */
class LoyaltyLeakFixesTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Explicit program configuration — nothing here relies on a fallback.
        Setting::set('loyalty.points_expiry_days', '10');
        Setting::set('loyalty.min_redemption_points', '1');
        Setting::set('loyalty.points_per_rupee_redemption', '10');
        Setting::set('loyalty.redeem_enabled', '1');
    }

    private function loyalty(): LoyaltyService
    {
        return app(LoyaltyService::class);
    }

    // ───────────────────────────── D1 ─────────────────────────────

    public function test_a_provider_cannot_redeem_points_over_the_api(): void
    {
        ['provider' => $provider] = $this->makeBookingScenario();
        $user = $provider->user;
        $this->loyalty()->earn($user, 500, 'booking_completed');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/loyalty/redeem', ['points' => 100])
            ->assertForbidden();

        $this->assertSame(500, $this->loyalty()->balance($user), 'points untouched');
        $this->assertSame(0.0, (float) app(WalletService::class)->balance($user), 'no cash created');
    }

    public function test_the_service_itself_refuses_a_provider_redemption(): void
    {
        ['provider' => $provider] = $this->makeBookingScenario();
        $this->loyalty()->earn($provider->user, 500, 'booking_completed');

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->loyalty()->redeem($provider->user, 100);
    }

    public function test_a_customer_can_still_redeem_over_the_api(): void
    {
        $customer = $this->makeCustomer();
        $this->loyalty()->earn($customer, 200, 'promo');

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/loyalty/redeem', ['points' => 100])
            ->assertOk()
            ->assertJsonPath('points_redeemed', 100)
            ->assertJsonPath('new_balance', 100);

        $this->assertEqualsWithDelta(10.0, (float) app(WalletService::class)->balance($customer), 0.001);
    }

    // ───────────────────────────── D2 ─────────────────────────────

    public function test_old_negative_scenario_earn_redeem_expire_leaves_zero_not_minus(): void
    {
        $customer = $this->makeCustomer();
        $this->loyalty()->earn($customer, 200, 'promo');
        $this->loyalty()->redeem($customer, 200);

        $this->travel(11)->days();

        $this->assertSame(0, $this->loyalty()->balance($customer), 'before the expiry job');
        $this->artisan('loyalty:expire-points')->assertExitCode(0);
        $this->assertSame(0, $this->loyalty()->balance($customer), 'after the expiry job');
        $this->assertSame(0, (int) LoyaltyPoint::where('user_id', $customer->id)->sum('points'));
    }

    public function test_live_balance_is_correct_before_the_expiry_job_has_run(): void
    {
        $customer = $this->makeCustomer();
        $this->loyalty()->earn($customer, 100, 'promo');        // lot A, expires day 10
        $this->travel(5)->days();
        $this->loyalty()->earn($customer, 50, 'promo');         // lot B, expires day 15
        $this->loyalty()->redeem($customer, 30);                // FIFO: consumes 30 of A

        $this->travel(6)->days();                               // day 11: A (70 left) has lapsed

        $this->assertSame(50, $this->loyalty()->balance($customer), 'only lot B is live');

        $this->artisan('loyalty:expire-points')->assertExitCode(0);
        $expiry = LoyaltyPoint::where('user_id', $customer->id)->where('reason', 'expired')->sole();
        $this->assertSame(-70, (int) $expiry->points, 'expires only the unconsumed part of the oldest lot');
        $this->assertSame(50, $this->loyalty()->balance($customer));
        $this->assertSame(50, (int) LoyaltyPoint::where('user_id', $customer->id)->sum('points'), 'ledger sum agrees once materialised');
    }

    public function test_redemption_cannot_exceed_the_live_fifo_balance(): void
    {
        $customer = $this->makeCustomer();
        $this->loyalty()->earn($customer, 100, 'promo');
        $this->travel(5)->days();
        $this->loyalty()->earn($customer, 50, 'promo');
        $this->loyalty()->redeem($customer, 30);
        $this->travel(6)->days();

        try {
            $this->loyalty()->redeem($customer, 60);
            $this->fail('redeemed more than the live FIFO balance');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Insufficient points balance', $e->getMessage());
        }

        $this->loyalty()->redeem($customer, 50);
        $this->assertSame(0, $this->loyalty()->balance($customer));
    }

    public function test_expiry_job_is_idempotent_via_unique_ref(): void
    {
        $customer = $this->makeCustomer();
        $earn = $this->loyalty()->earn($customer, 80, 'promo');
        $this->travel(11)->days();

        $this->artisan('loyalty:expire-points')->assertExitCode(0);
        $this->artisan('loyalty:expire-points')->assertExitCode(0);

        $rows = LoyaltyPoint::where('user_id', $customer->id)->where('reason', 'expired')->get();
        $this->assertCount(1, $rows);
        $this->assertSame("loyalty-expire:{$earn->id}", $rows[0]->ref);
        $this->assertSame(-80, (int) $rows[0]->points);
        $this->assertSame(0, $this->loyalty()->balance($customer));
    }

    public function test_expiry_consumes_the_oldest_unconsumed_points_first(): void
    {
        $customer = $this->makeCustomer();
        $a = $this->loyalty()->earn($customer, 100, 'promo');   // oldest
        $this->travel(3)->days();
        $b = $this->loyalty()->earn($customer, 100, 'promo');
        $this->loyalty()->redeem($customer, 120);               // A fully, 20 of B

        $this->travel(8)->days();                               // day 11: A lapsed (0 left), B live
        $this->assertSame(80, $this->loyalty()->balance($customer));
        $this->artisan('loyalty:expire-points')->assertExitCode(0);
        $this->assertSame(0, LoyaltyPoint::where('ref', "loyalty-expire:{$a->id}")->count(), 'nothing left in A to expire');

        $this->travel(3)->days();                               // day 14: B lapsed with 80 left
        $this->artisan('loyalty:expire-points')->assertExitCode(0);
        $this->assertSame(-80, (int) LoyaltyPoint::where('ref', "loyalty-expire:{$b->id}")->value('points'));
        $this->assertSame(0, $this->loyalty()->balance($customer));
    }

    public function test_materialised_expiry_rows_are_not_flagged_by_reconciliation_or_the_audit(): void
    {
        $customer = $this->makeCustomer();
        $this->loyalty()->earn($customer, 80, 'promo');
        $this->travel(11)->days();
        $this->artisan('loyalty:expire-points')->assertExitCode(0);

        $flagged = (new \App\Services\Operations\ReconciliationService)->detect($this->makeSuperAdmin())['negative_loyalty_balances'];
        $this->assertFalse($flagged->contains('user_id', $customer->id));
        $this->artisan('loyalty:balance-audit')->expectsOutputToContain('No loyalty balance discrepancies found.')->assertExitCode(0);
    }

    public function test_balance_is_never_negative_at_any_clock_time(): void
    {
        $customer = $this->makeCustomer();
        $this->loyalty()->earn($customer, 60, 'promo');
        $this->travel(2)->days();
        $this->loyalty()->earn($customer, 40, 'promo');
        $this->loyalty()->redeem($customer, 90);

        foreach ([0, 5, 8, 9, 10, 11, 12, 20, 400] as $day) {
            $this->assertGreaterThanOrEqual(0, $this->loyalty()->balanceAt($customer, now()->addDays($day)), "day +{$day}");
        }
    }

    public function test_a_legacy_negative_ledger_still_reads_zero_not_negative(): void
    {
        $customer = $this->makeCustomer();
        // Exactly the rows the OLD code produced: an earn, a full redemption.
        LoyaltyPoint::create(['user_id' => $customer->id, 'points' => 200, 'reason' => 'promo', 'expires_at' => now()->addDays(1)]);
        LoyaltyPoint::create(['user_id' => $customer->id, 'points' => -200, 'reason' => 'redeemed']);
        $this->travel(2)->days();

        $this->assertSame(0, $this->loyalty()->balance($customer));

        $this->artisan('loyalty:balance-audit')
            ->expectsOutputToContain("user #{$customer->id}")
            ->assertExitCode(1);
    }

    public function test_points_referral_clawback_takes_only_what_is_available_and_records_the_shortfall(): void
    {
        ['booking' => $booking] = $this->makeBookingScenario();
        $referrer = $this->makeCustomer();
        $referral = Referral::create([
            'referrer_id' => $referrer->id, 'referred_id' => $this->makeCustomer()->id,
            'status' => 'rewarded', 'reward_amount' => 0, 'qualifying_booking_id' => $booking->id,
        ]);
        $this->loyalty()->earn($referrer, 100, 'referral_reward', $booking);
        $this->loyalty()->redeem($referrer, 80);                // only 20 left

        $flagged = app(ReferralService::class)->flagAsFraud($referral, $this->makeSuperAdmin(), 'farming');

        $claw = LoyaltyPoint::where('ref', "referral:{$referral->id}:points-clawback")->sole();
        $this->assertSame(-20, (int) $claw->points);
        $this->assertSame(0, $this->loyalty()->balance($referrer), 'never pushed negative');
        $this->assertStringContainsString('shortfall 80', $flagged->reversal_note);
    }
}
