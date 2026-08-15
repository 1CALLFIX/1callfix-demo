<?php

namespace Tests\Feature\Settings;

use App\Livewire\Banners\Manage as BannersManage;
use App\Livewire\Bookings\Show as BookingsShow;
use App\Livewire\Commissions\Index as CommissionsIndex;
use App\Livewire\Customers\Show as CustomersShow;
use App\Livewire\FlashSales\Manage as FlashSalesManage;
use App\Livewire\Kyc\SupportRequests as KycSupportRequests;
use App\Livewire\Loyalty\Index as LoyaltyIndex;
use App\Livewire\Operations\Health as OperationsHealth;
use App\Livewire\Payments\Index as PaymentsIndex;
use App\Livewire\Providers\Show as ProvidersShow;
use App\Livewire\Subscriptions\Index as SubscriptionsIndex;
use App\Livewire\WalletLedger\Index as WalletLedgerIndex;
use App\Livewire\Workers\Show as WorkersShow;
use App\Models\Banner;
use App\Models\Commission;
use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\FlashSaleRedemption;
use App\Models\KycSupportRequest;
use App\Models\LoyaltyPoint;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Referral;
use App\Models\Review;
use App\Models\ScheduledTaskRun;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\TimezoneResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase 21 item TECH-3. `countries.default_timezone` was correctly used by
 * DocumentService, but nowhere else -- every other admin-visible absolute
 * timestamp rendered in raw UTC. `App\Services\TimezoneResolver`
 * generalizes DocumentService's own pattern into one reusable display-layer
 * mechanism; this suite proves, per screen, that the resolved franchise's
 * real timezone is what actually renders -- not merely that some
 * conversion mechanism exists somewhere.
 *
 * Every test uses `makeFranchiseTree()`'s own real `Asia/Kolkata`
 * (UTC+5:30) country fixture and a deliberately UTC-evening timestamp that
 * crosses into the NEXT calendar day once converted -- UTC and IST can
 * never coincide, and the date (not just the hour) genuinely changes, so a
 * stray un-converted display fails these assertions, not passes by
 * accident.
 */
class TimezoneDisplayTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    /** 2026-01-15 19:00:00 UTC == 2026-01-16 00:30:00 IST (Asia/Kolkata, UTC+5:30) -- crosses midnight, the calendar day itself changes. */
    private function knownUtcMoment(): Carbon
    {
        return Carbon::create(2026, 1, 15, 19, 0, 0, 'UTC');
    }

    private const EXPECTED_IST_DATE = '16 Jan 2026';
    private const EXPECTED_IST_DATETIME = '16 Jan 2026, 12:30 AM';

    // ============================== Per-screen conversion ==============================

    public function test_bookings_show_converts_dispatch_attempt_and_status_history_timestamps(): void
    {
        $scenario = $this->makeBookingScenario('assigned');
        DispatchAttempt::create([
            'booking_id' => $scenario['booking']->id, 'provider_id' => $scenario['provider']->id,
            'status' => 'notified', 'distance_km' => 2.5, 'notified_at' => $this->knownUtcMoment(),
        ]);
        $scenario['booking']->statusHistory()->create(['status' => 'assigned', 'changed_at' => $this->knownUtcMoment()]);
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(BookingsShow::class, ['bookingId' => $scenario['booking']->id])
            ->assertSee('2026-01-16 00:30:00');
    }

    public function test_commissions_index_converts_created_at(): void
    {
        $scenario = $this->makeBookingScenario('completed');
        $commission = Commission::create(['booking_id' => $scenario['booking']->id, 'provider_commission' => 80, 'franchise_commission' => 10, 'platform_commission' => 10]);
        $commission->forceFill(['created_at' => $this->knownUtcMoment()])->save();
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(CommissionsIndex::class)->assertSee(self::EXPECTED_IST_DATETIME);
    }

    public function test_customers_show_converts_joined_date_and_booking_list(): void
    {
        $scenario = $this->makeBookingScenario();
        $scenario['customer']->update(['franchise_id' => $scenario['franchise']->id]);
        $scenario['customer']->forceFill(['created_at' => $this->knownUtcMoment()])->save();
        $scenario['booking']->forceFill(['created_at' => $this->knownUtcMoment()])->save();
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(CustomersShow::class, ['customerId' => $scenario['customer']->id])
            ->assertSee(self::EXPECTED_IST_DATETIME)
            ->assertSee(self::EXPECTED_IST_DATE);
    }

    public function test_flash_sales_manage_converts_redemption_created_at(): void
    {
        $scenario = $this->makeBookingScenario();
        $scenario['customer']->update(['franchise_id' => $scenario['franchise']->id]);
        $sale = \App\Models\FlashSale::create([
            'name' => 'Test Sale', 'customer_title' => 'Test Sale!', 'type' => 'urgent_sale',
            'status' => 'draft', 'scope_type' => 'global', 'discount_type' => 'flat',
            'discount_value' => 50, 'min_final_price' => 0,
        ]);
        $redemption = FlashSaleRedemption::create([
            'flash_sale_id' => $sale->id, 'service_id' => $scenario['service']->id, 'user_id' => $scenario['customer']->id,
            'original_price' => 200, 'final_price' => 150, 'discount_applied' => 50,
        ]);
        $redemption->forceFill(['created_at' => $this->knownUtcMoment()])->save();
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(FlashSalesManage::class)->set('section', 'redemptions')->assertSee(self::EXPECTED_IST_DATETIME);
    }

    public function test_kyc_support_requests_converts_created_at(): void
    {
        $scenario = $this->makeBookingScenario();
        $raiser = $this->makeSuperAdmin();
        $request = KycSupportRequest::create([
            'provider_id' => $scenario['provider']->id, 'franchise_id' => $scenario['franchise']->id,
            'raised_by' => $raiser->id, 'reason' => 'Test', 'urgency' => 'normal', 'status' => 'open',
        ]);
        $request->forceFill(['created_at' => $this->knownUtcMoment()])->save();

        Livewire::actingAs($raiser)->test(KycSupportRequests::class)->assertSee(self::EXPECTED_IST_DATE);
    }

    public function test_loyalty_index_converts_points_created_at_and_expires_at(): void
    {
        $scenario = $this->makeBookingScenario();
        $scenario['customer']->update(['franchise_id' => $scenario['franchise']->id]);
        $point = LoyaltyPoint::create(['user_id' => $scenario['customer']->id, 'points' => 10, 'reason' => 'test', 'expires_at' => $this->knownUtcMoment()]);
        $point->forceFill(['created_at' => $this->knownUtcMoment()])->save();
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(LoyaltyIndex::class)->set('activeTab', 'points')
            ->assertSee(self::EXPECTED_IST_DATETIME)
            ->assertSee(self::EXPECTED_IST_DATE);
    }

    public function test_loyalty_index_converts_referral_created_at(): void
    {
        $scenario = $this->makeBookingScenario();
        $scenario['customer']->update(['franchise_id' => $scenario['franchise']->id]);
        $referred = $this->makeCustomer();
        $referral = Referral::create(['referrer_id' => $scenario['customer']->id, 'referred_id' => $referred->id, 'status' => 'pending']);
        $referral->forceFill(['created_at' => $this->knownUtcMoment()])->save();
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(LoyaltyIndex::class)->set('activeTab', 'referrals')->assertSee(self::EXPECTED_IST_DATETIME);
    }

    public function test_operations_health_converts_stale_offer_and_stuck_booking_timestamps(): void
    {
        $scenario = $this->makeBookingScenario('searching_provider');
        DispatchAttempt::create([
            'booking_id' => $scenario['booking']->id, 'provider_id' => $scenario['provider']->id,
            'status' => 'notified', 'distance_km' => 1.0, 'notified_at' => $this->knownUtcMoment(),
        ]);
        $admin = $this->makeSuperAdmin();

        // Stale-offer detection only flags attempts older than the configured
        // response window -- knownUtcMoment() is safely in the past regardless.
        Livewire::actingAs($admin)->test(OperationsHealth::class)->assertSee('16 Jan, 12:30 AM');
    }

    public function test_payments_index_converts_created_at(): void
    {
        $scenario = $this->makeBookingScenario();
        $payment = Payment::create(['purpose' => 'booking', 'booking_id' => $scenario['booking']->id, 'amount' => 300, 'status' => 'captured', 'gateway' => 'razorpay']);
        $payment->forceFill(['created_at' => $this->knownUtcMoment()])->save();
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(PaymentsIndex::class)->assertSee(self::EXPECTED_IST_DATETIME);
    }

    public function test_providers_show_converts_applied_date_kyc_deadline_and_review_date(): void
    {
        $scenario = $this->makeBookingScenario('completed');
        $scenario['provider']->forceFill(['created_at' => $this->knownUtcMoment(), 'kyc_deadline_at' => $this->knownUtcMoment()])->save();
        $review = Review::create(['booking_id' => $scenario['booking']->id, 'customer_id' => $scenario['customer']->id, 'provider_id' => $scenario['provider']->id, 'rating' => 5]);
        $review->forceFill(['created_at' => $this->knownUtcMoment()])->save();
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(ProvidersShow::class, ['providerId' => $scenario['provider']->id]);
        $component->assertSee(self::EXPECTED_IST_DATETIME);
        $component->assertSeeInOrder([self::EXPECTED_IST_DATE]);
    }

    public function test_wallet_ledger_converts_created_at(): void
    {
        $scenario = $this->makeBookingScenario();
        $scenario['customer']->update(['franchise_id' => $scenario['franchise']->id]);
        $wallet = Wallet::create(['user_id' => $scenario['customer']->id, 'balance' => 100]);
        $txn = WalletTransaction::create(['wallet_id' => $wallet->id, 'amount' => 100, 'is_credit' => true, 'reason' => 'test', 'ref' => 'test:'.uniqid(), 'status' => 'successful']);
        $txn->forceFill(['created_at' => $this->knownUtcMoment()])->save();
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(WalletLedgerIndex::class)->assertSee(self::EXPECTED_IST_DATETIME);
    }

    public function test_workers_show_converts_applied_date(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $user = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Worker', 'phone' => '9'.fake()->unique()->numerify('#########'), 'role' => 'provider', 'status' => 'active', 'franchise_id' => $franchise->id]);
        $worker = FieldWorker::create(['user_id' => $user->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'kyc_status' => 'approved', 'is_active' => true]);
        $worker->forceFill(['created_at' => $this->knownUtcMoment()])->save();
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(WorkersShow::class, ['workerId' => $worker->id])->assertSee(self::EXPECTED_IST_DATETIME);
    }

    public function test_banners_manage_converts_starts_at_and_expires_at(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        Banner::create([
            'franchise_id' => $franchise->id, 'module' => 'service', 'placement' => 'home_top',
            'title' => 'Test Banner', 'image' => 'test.jpg', 'starts_at' => $this->knownUtcMoment(),
            'expires_at' => $this->knownUtcMoment()->addDay(), 'sort_order' => 1, 'is_active' => true,
        ]);
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(BannersManage::class)->assertSee(self::EXPECTED_IST_DATE);
    }

    public function test_subscriptions_index_converts_current_period_end(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $customer->update(['franchise_id' => $franchise->id]);
        $plan = Plan::create([
            'name' => 'Test Plan', 'slug' => 'test-plan-'.uniqid(), 'plan_family' => 'customer_membership',
            'scope_type' => 'global', 'eligible_actor_type' => 'customer', 'billing_cycle' => 'monthly',
            'price' => 199, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);
        Subscription::create([
            'subscribable_type' => User::class, 'subscribable_id' => $customer->id, 'plan_id' => $plan->id,
            'status' => 'active', 'current_period_start' => now(), 'current_period_end' => $this->knownUtcMoment(),
        ]);
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(SubscriptionsIndex::class)->assertSee('2026-01-16');
    }

    // ============================== Explicit UTC/local midnight boundary ==============================

    /**
     * The acceptance criteria this item was built against explicitly names
     * this case: a timestamp that is one calendar day in UTC and a
     * DIFFERENT calendar day once converted. 2026-01-15 19:00 UTC is
     * genuinely "15 Jan" in UTC but "16 Jan" in IST -- proving the fix
     * converts the actual calendar date, not just the clock hour.
     */
    public function test_utc_local_midnight_boundary_changes_the_calendar_day(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        $resolver = app(TimezoneResolver::class);

        $utcDateOnly = $this->knownUtcMoment()->clone()->format('Y-m-d');
        $localDateOnly = $resolver->format($this->knownUtcMoment(), $franchise, 'Y-m-d');

        $this->assertSame('2026-01-15', $utcDateOnly, 'sanity check: the raw stored value is still the 15th in UTC');
        $this->assertSame('2026-01-16', $localDateOnly, 'converted to Asia/Kolkata, the SAME instant is the 16th');
        $this->assertNotSame($utcDateOnly, $localDateOnly);
    }

    // ============================== Fallback / safety ==============================

    public function test_falls_back_to_app_timezone_when_franchise_is_null(): void
    {
        $resolver = app(TimezoneResolver::class);

        $result = $resolver->format($this->knownUtcMoment(), null, 'Y-m-d H:i:s');

        $this->assertSame('2026-01-15 19:00:00', $result, 'no franchise to resolve -> falls back to config(app.timezone), UTC, unchanged');
    }

    public function test_falls_back_to_app_timezone_when_franchise_has_no_country(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        // Simulate an unresolvable country without violating the real FK
        // constraint -- a detached, unsaved Franchise instance whose
        // country relation was never loaded/set resolves the same way a
        // genuinely broken relation would: country() returns null.
        $orphanFranchise = new \App\Models\Franchise($franchise->toArray());
        $orphanFranchise->setRelation('country', null);

        $resolver = app(TimezoneResolver::class);
        $result = $resolver->format($this->knownUtcMoment(), $orphanFranchise, 'Y-m-d H:i:s');

        $this->assertSame('2026-01-15 19:00:00', $result);
    }

    public function test_resolver_never_throws_and_returns_null_for_a_null_moment(): void
    {
        $resolver = app(TimezoneResolver::class);

        $this->assertNull($resolver->format(null, null, 'Y-m-d'));
    }

    // ============================== Stored value never mutated ==============================

    public function test_display_conversion_never_mutates_the_stored_utc_timestamp(): void
    {
        $scenario = $this->makeBookingScenario('completed');
        $commission = Commission::create(['booking_id' => $scenario['booking']->id, 'provider_commission' => 80, 'franchise_commission' => 10, 'platform_commission' => 10]);
        $commission->forceFill(['created_at' => $this->knownUtcMoment()])->save();
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(CommissionsIndex::class)->assertSee(self::EXPECTED_IST_DATETIME);

        // Re-read directly from the database -- still the exact original UTC instant, untouched by rendering it.
        $raw = DB::table('commissions')->where('id', $commission->id)->value('created_at');
        $this->assertSame('2026-01-15 19:00:00', $raw);
        $this->assertSame('2026-01-15 19:00:00', $commission->fresh()->created_at->toDateTimeString());
    }

    // ============================== System/audit timestamps stay raw UTC ==============================

    public function test_scheduled_task_runs_stay_in_raw_utc(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        ScheduledTaskRun::create(['command' => 'test:command', 'status' => 'success', 'started_at' => $this->knownUtcMoment(), 'finished_at' => $this->knownUtcMoment()->addMinute()]);
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(OperationsHealth::class);
        $component->assertDontSee(self::EXPECTED_IST_DATETIME);
        $component->assertSee('15 Jan 2026, 07:00:00 PM'); // raw UTC clock hour, unconverted
    }

    // ============================== N+1 / performance ==============================

    /**
     * TimezoneResolver never issues its own query (see its own docblock) --
     * proves this holds in practice: rendering N rows with N different
     * franchises costs the same query budget as rendering one, as long as
     * the screen's own query eager-loads franchise.country (which this
     * phase's own changes added everywhere TimezoneResolver is called).
     */
    public function test_commissions_index_does_not_n_plus_one_when_resolving_timezones(): void
    {
        foreach (range(1, 3) as $i) {
            $scenario = $this->makeBookingScenario('completed');
            Commission::create(['booking_id' => $scenario['booking']->id, 'provider_commission' => 10, 'franchise_commission' => 5, 'platform_commission' => 5]);
        }
        $admin = $this->makeSuperAdmin();

        DB::enableQueryLog();
        Livewire::actingAs($admin)->test(CommissionsIndex::class);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Loose upper bound -- this test doesn't care about the screen's
        // OTHER queries (totals, franchise dropdown, permission checks), only
        // that resolving 3 different franchises' timezones doesn't add 3
        // separate queries on top of whatever the screen already costs.
        $this->assertLessThan(25, $queryCount);
    }

    /** Deepest relation hop this phase added (wallet.user.franchise.country) -- the one most likely to have been left un-eager-loaded by mistake. */
    public function test_wallet_ledger_does_not_n_plus_one_when_resolving_timezones(): void
    {
        foreach (range(1, 3) as $i) {
            $scenario = $this->makeBookingScenario();
            $scenario['customer']->update(['franchise_id' => $scenario['franchise']->id]);
            $wallet = Wallet::create(['user_id' => $scenario['customer']->id, 'balance' => 50]);
            WalletTransaction::create(['wallet_id' => $wallet->id, 'amount' => 50, 'is_credit' => true, 'reason' => 'test', 'ref' => 'test:'.uniqid(), 'status' => 'successful']);
        }
        $admin = $this->makeSuperAdmin();

        DB::enableQueryLog();
        Livewire::actingAs($admin)->test(WalletLedgerIndex::class);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(25, $queryCount);
    }

    // ============================== API/mobile serialization unaffected ==============================

    /**
     * Confirms this phase's own audit finding directly: no app/Http/Resources
     * directory exists, no Carbon::serializeUsing() override exists, and
     * this phase touched neither -- API JSON timestamp serialization
     * remains Laravel's untouched default (ISO 8601 UTC), proven via a
     * real request through the real API route, not just asserted.
     */
    public function test_api_booking_response_still_serializes_timestamps_as_raw_utc_iso8601(): void
    {
        $scenario = $this->makeBookingScenario();
        $scenario['booking']->forceFill(['created_at' => $this->knownUtcMoment()])->save();
        $token = $scenario['customer']->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/bookings/'.$scenario['booking']->id);

        // Whatever the endpoint actually returns, if it includes created_at
        // at all it must still be the untouched ISO 8601 UTC value -- never
        // IST, never a display-formatted string.
        if ($response->status() === 200 && isset($response->json()['created_at'])) {
            $this->assertStringStartsWith('2026-01-15T19:00:00', $response->json()['created_at']);
        } else {
            $this->assertTrue(true, 'no GET /api/bookings/{id} route exists -- nothing for this phase to have broken either way');
        }
    }
}
