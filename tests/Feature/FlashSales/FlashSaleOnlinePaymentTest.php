<?php

namespace Tests\Feature\FlashSales;

use App\Actions\CreateBookingAction;
use App\Actions\CreateBookingBundleAction;
use App\Exceptions\OnlinePaymentRequiredException;
use App\Models\Booking;
use App\Models\FlashSaleRedemption;
use App\Models\Setting;
use App\Models\Wallet;
use App\Services\Payments\OnlinePaymentGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * D5 (docs/COUPON_HARDENING_FINAL.md) — THUMB RULE: a flash-sale discount is a benefit, so it only exists
 * on an online payment (Razorpay, wallet, or wallet + Razorpay). A cash or cash+online booking is never
 * priced from the sale and never redeems it: the customer simply pays the normal price.
 */
class FlashSaleOnlinePaymentTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    private function world(): array
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $service = $this->makeService($this->makeCategory());
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        Setting::set('payment.wallet_enabled', '1');
        $this->makeFlashSale([$service], ['discount_type' => 'percent', 'discount_value' => 20]);

        return compact('franchise', 'zone', 'service', 'customer', 'address');
    }

    private function book(array $w, string $method): Booking
    {
        Queue::fake();

        return app(CreateBookingAction::class)->execute([
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $w['customer']->id,
            'service_id' => $w['service']->id, 'address_id' => $w['address']->id, 'payment_method' => $method,
        ]);
    }

    public function test_cash_booking_gets_no_flash_sale_price_and_redeems_nothing(): void
    {
        $w = $this->world();
        $booking = $this->book($w, 'cash');

        $this->assertEquals(500.00, (float) $booking->price_quoted, 'Cash pays the full price, never the sale price.');
        $this->assertSame(0, FlashSaleRedemption::count());
    }

    public function test_cash_plus_online_style_methods_get_no_flash_sale_price(): void
    {
        foreach (['cash+online', 'split', 'cash_online'] as $method) {
            $w = $this->world();
            $booking = $this->book($w, $method);
            $this->assertEquals(500.00, (float) $booking->price_quoted, "{$method} must not unlock the sale price.");
        }
        $this->assertSame(0, FlashSaleRedemption::count());
        $this->assertFalse(OnlinePaymentGuard::legsAreOnline([['method' => 'online', 'amount' => 1], ['method' => 'cash', 'amount' => 399]]));
    }

    public function test_online_booking_gets_the_flash_sale_price(): void
    {
        $w = $this->world();
        $booking = $this->book($w, 'online');

        $this->assertEquals(400.00, (float) $booking->price_quoted);
        $this->assertSame(1, FlashSaleRedemption::where('booking_id', $booking->id)->count());
    }

    public function test_wallet_booking_gets_the_flash_sale_price(): void
    {
        $w = $this->world();
        Wallet::create(['user_id' => $w['customer']->id, 'balance' => 1000]);
        $booking = $this->book($w, 'wallet');

        $this->assertEquals(400.00, (float) $booking->price_quoted);
        $this->assertSame(1, FlashSaleRedemption::where('booking_id', $booking->id)->count());
    }

    public function test_a_flash_sale_booking_can_never_switch_to_cash_afterwards(): void
    {
        $w = $this->world();
        $booking = $this->book($w, 'online');

        $this->expectException(OnlinePaymentRequiredException::class);
        $booking->update(['payment_method' => 'cash']);
    }

    public function test_an_existing_cash_booking_with_a_flash_sale_price_is_unchanged(): void
    {
        $w = $this->world();
        $booking = $this->book($w, 'online');
        // Legacy row: placed before the rule, cash, at the sale price (written below the model guard).
        Booking::withoutEvents(fn () => $booking->forceFill(['payment_method' => 'cash'])->save());

        $booking->refresh();
        $booking->update(['status' => 'confirmed']);

        $this->assertEquals(400.00, (float) $booking->fresh()->price_quoted);
        $this->assertSame('cash', $booking->fresh()->payment_method);
    }

    public function test_a_cash_bundle_children_get_no_flash_sale_price(): void
    {
        $w = $this->world();
        Queue::fake();
        $bundle = app(CreateBookingBundleAction::class)->execute([
            'customer_id' => $w['customer']->id,
            'payment_method' => 'cash', 'idempotency_key' => null, 'request_fingerprint' => 'fp-flash',
            'children' => [['service_id' => $w['service']->id, 'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'address_id' => $w['address']->id]],
        ]);

        $this->assertEquals(500.00, (float) $bundle->children()->first()->price_quoted);
        $this->assertSame(0, FlashSaleRedemption::count());
    }
}
