<?php

namespace Tests\Feature\Pricing;

use App\Actions\CreateBookingAction;
use App\Livewire\FranchisePricing\Manage as FranchisePricingManage;
use App\Models\Commission;
use App\Models\FranchiseServicePricing;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase D — the franchise-override / commission boundary, as it actually is.
 *
 * ── The finding this file records ─────────────────────────────────────────
 * The brief asked for the boundary of the rule governing franchise override
 * price, provider payout, commission, minimum commission and minimum booking
 * price. A full read of the repository found that FOUR of those five rules do
 * not exist, so there is no boundary of theirs to test:
 *
 *   - No minimum commission. Nothing in `app/`, `config/`, `database/` or the
 *     `settings` table names one. `grep -ri 'min_commission|minimum_commission'`
 *     returns nothing.
 *   - No minimum booking price. The only `min_*` price concepts anywhere are
 *     `flash_sales.min_final_price` (a per-sale floor, admin-set, tested in
 *     FlashSaleEngineTest) and `coupons.min_order_value` (a qualification
 *     threshold on a table with no booking-path writer).
 *   - No floor on the franchise override beyond `min:0`
 *     (FranchisePricing\Manage::saveRow()).
 *   - No constraint that platform_fee_percent + commission_value <= 100.
 *     Franchises\Manage validates them independently: platform fee
 *     `min:0,max:100`, commission value `min:0` with no maximum at all.
 *
 * What IS testable is the behaviour at the edges those absent rules leave
 * open, and that is what this file pins — so the gap is recorded as executed
 * fact rather than as a claim in a document. Two of the cases below are
 * reported to the business rather than "fixed": inventing a minimum
 * commission or a minimum booking price would be inventing pricing policy,
 * which is a product decision, not a refactor.
 */
class FranchisePricingBoundaryTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    private function bookAt(array $overrides = []): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        if ($overrides) {
            $franchise->update($overrides);
        }
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        $provider = $this->makeProviderIn($franchise, $zone);

        return compact('franchise', 'zone', 'service', 'customer', 'address', 'provider');
    }

    private function completedBookingFor(array $world): \App\Models\Booking
    {
        $booking = app(CreateBookingAction::class)->execute([
            'franchise_id' => $world['franchise']->id,
            'zone_id' => $world['zone']->id,
            'customer_id' => $world['customer']->id,
            'service_id' => $world['service']->id,
            'address_id' => $world['address']->id,
            'payment_method' => 'cash',
        ]);

        $booking->update([
            'provider_id' => $world['provider']->id,
            'status' => 'assigned',
            'completion_otp' => '5678',
        ]);

        $this->actingAs($world['provider']->user, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/complete", ['otp' => '5678'])
            ->assertOk();

        return $booking->fresh();
    }

    // ==================== The one floor that does exist: zero ====================

    public function test_the_admin_screen_rejects_a_negative_override_and_accepts_zero(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();
        $actor = $this->makeUserWithPermission('franchise_pricing.manage', 'global');

        Livewire::actingAs($actor)
            ->test(FranchisePricingManage::class, ['franchiseId' => $franchise->id])
            ->set("rows.{$service->id}.is_offered", true)
            ->set("rows.{$service->id}.price_override", '-1')
            ->call('saveRow', $service->id)
            ->assertHasErrors("rows.{$service->id}.price_override");

        $this->assertSame(0, FranchiseServicePricing::count(), 'A negative override must not be stored.');

        Livewire::actingAs($actor)
            ->test(FranchisePricingManage::class, ['franchiseId' => $franchise->id])
            ->set("rows.{$service->id}.is_offered", true)
            ->set("rows.{$service->id}.price_override", '0')
            ->call('saveRow', $service->id)
            ->assertHasNoErrors();

        $this->assertEquals(0, FranchiseServicePricing::firstOrFail()->price_override,
            'Zero IS a permitted override — there is no minimum booking price rule above it.');
    }

    /**
     * REPORTED GAP, not a defect being fixed here: a franchise may price a
     * service at zero, and the platform then earns zero commission on a real,
     * completed job. Nothing refuses it, because no minimum-commission or
     * minimum-price rule exists to refuse it with. Recorded so the business
     * can decide whether it wants one.
     */
    public function test_a_zero_priced_override_produces_a_completed_booking_that_pays_the_platform_nothing(): void
    {
        $world = $this->bookAt();
        FranchiseServicePricing::create([
            'franchise_id' => $world['franchise']->id, 'service_id' => $world['service']->id,
            'price_override' => 0, 'is_offered' => true,
        ]);

        $booking = $this->completedBookingFor($world);

        $this->assertEquals(0.00, (float) $booking->price_quoted);
        $this->assertEquals(0.00, (float) $booking->price_final);

        $commission = Commission::where('booking_id', $booking->id)->firstOrFail();
        $this->assertEquals(0.00, (float) $commission->platform_commission);
        $this->assertEquals(0.00, (float) $commission->franchise_commission);
        $this->assertEquals(0.00, (float) $commission->provider_commission);

        $this->assertSame(0, WalletTransaction::where('is_credit', true)->count(),
            'A zero split credits nobody — the > 0 guard in CommissionService holds.');
    }

    // ==================== The split, at the edges of the rates ====================

    public function test_a_normal_split_leaves_the_provider_the_remainder(): void
    {
        $world = $this->bookAt(); // platform 5%, revenue_share 10%, on 500
        $booking = $this->completedBookingFor($world);

        $commission = Commission::where('booking_id', $booking->id)->firstOrFail();
        $this->assertEquals(25.00, (float) $commission->platform_commission);
        $this->assertEquals(50.00, (float) $commission->franchise_commission);
        $this->assertEquals(425.00, (float) $commission->provider_commission);
        $this->assertEquals(500.00,
            (float) $commission->platform_commission + (float) $commission->franchise_commission + (float) $commission->provider_commission,
            'The three shares must always add back up to the booking total.');
    }

    public function test_a_flat_fee_franchise_takes_no_per_booking_share(): void
    {
        $world = $this->bookAt(['commission_model' => 'flat_fee', 'commission_value' => 10]);
        $booking = $this->completedBookingFor($world);

        $commission = Commission::where('booking_id', $booking->id)->firstOrFail();
        $this->assertEquals(0.00, (float) $commission->franchise_commission,
            'Only revenue_share franchises take a per-booking cut.');
        $this->assertEquals(475.00, (float) $commission->provider_commission);
    }

    public function test_a_platform_fee_of_one_hundred_percent_leaves_the_provider_nothing_but_never_less(): void
    {
        $world = $this->bookAt(['platform_fee_percent' => 100, 'commission_model' => 'flat_fee']);
        $booking = $this->completedBookingFor($world);

        $commission = Commission::where('booking_id', $booking->id)->firstOrFail();
        $this->assertEquals(500.00, (float) $commission->platform_commission);
        $this->assertEquals(0.00, (float) $commission->provider_commission);
        $this->assertSame(0, WalletTransaction::where('is_credit', true)->count());
    }

    /**
     * REPORTED GAP, deliberately not "fixed" here.
     *
     * `platform_fee_percent` is capped at 100 and `commission_value` is not
     * capped at all, and nothing checks them against each other — so an admin
     * can configure a franchise whose two cuts exceed the booking total. The
     * recorded provider share then goes NEGATIVE.
     *
     * No money is taken from anyone: `CommissionService` only credits a wallet
     * when the share is `> 0`, which this asserts. But the `commissions` row
     * is wrong, and every report built on it is wrong with it.
     *
     * The fix is a validation rule saying the two rates may not exceed 100%
     * together. That is a pricing-policy decision about what a franchise is
     * allowed to charge, and it belongs to the business, not to this refactor.
     * Pinned here as executed behaviour so the decision is made against facts.
     */
    public function test_over_100_percent_of_combined_rates_records_a_negative_provider_share_and_credits_nobody(): void
    {
        $world = $this->bookAt([
            'platform_fee_percent' => 60,
            'commission_model' => 'revenue_share',
            'commission_value' => 60,
        ]);

        $booking = $this->completedBookingFor($world);

        $commission = Commission::where('booking_id', $booking->id)->firstOrFail();
        $this->assertEquals(300.00, (float) $commission->platform_commission);
        $this->assertEquals(300.00, (float) $commission->franchise_commission);
        $this->assertEquals(-100.00, (float) $commission->provider_commission,
            'Documented gap: nothing validates that the two rates sum to at most 100%.');

        $this->assertSame(0, WalletTransaction::where('is_credit', true)->count(),
            'No wallet is credited from a negative share — the money itself is safe.');
        $this->assertEquals(0.00, (float) $world['provider']->user->wallet()->firstOrCreate(['user_id' => $world['provider']->user_id], ['balance' => 0])->balance);
    }

    /**
     * A franchise override is a local PRICE, not a discount, and the customer
     * is charged it even when it is higher than the base price — pinning that
     * the cascade has no hidden "never above base_price" ceiling either.
     */
    public function test_an_override_above_the_base_price_is_charged_as_given(): void
    {
        $world = $this->bookAt();
        FranchiseServicePricing::create([
            'franchise_id' => $world['franchise']->id, 'service_id' => $world['service']->id,
            'price_override' => 900, 'is_offered' => true,
        ]);

        $booking = $this->completedBookingFor($world);

        $this->assertEquals(900.00, (float) $booking->price_quoted);
        $this->assertEquals(45.00, (float) Commission::where('booking_id', $booking->id)->value('platform_commission'));
    }
}
