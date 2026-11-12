<?php

namespace Tests\Feature\Hotel;

use App\Contracts\PaymentGateway;
use App\Livewire\Customer\Hotels\Book;
use App\Livewire\Customer\Hotels\Home;
use App\Models\Booking;
use App\Models\Coupon;
use App\Models\CouponTarget;
use App\Models\CouponUsage;
use App\Models\Franchise;
use App\Models\HotelReservation;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zone;
use App\Services\Hotels\HotelBookingService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Support\HotelFixtureHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * Hotels step 2 (owner 2026-11-12, "stand against the aggregators"): coupons on stays (online / wallet only, GST on
 * the price after the coupon, given back when the booking ends unused), children counted against each room, and the
 * booking voucher + tax invoice.
 */
class HotelOffersChildrenDocumentsTest extends TestCase
{
    use RefreshDatabase;
    use LiveCity;
    use HotelFixtureHelpers;

    private Franchise $franchise;

    private $hotel;

    private $room;

    private $plan;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $city = $this->liveCity('Nellore');
        $this->franchise = Franchise::where('city_id', $city->id)->firstOrFail();
        $zone = Zone::where('franchise_id', $this->franchise->id)->firstOrFail();
        $this->activateHotelFor($this->franchise);
        Setting::set('hotel.gst_slabs', "1000 : 0\n7500 : 5\n* : 18");
        Setting::set('coupons.enabled', '1');
        Setting::set('coupons.unpaid_hold_minutes', '30');
        $this->hotel = $this->makeAccommodation($this->franchise, $zone, null, ['approval_status' => 'approved', 'name' => 'Sea View Lodge']);
        $this->room = $this->makeRoomType($this->hotel, ['total_inventory' => 2, 'max_adults' => 2, 'max_children' => 1]);
        $this->plan = $this->makeRatePlan($this->room, ['nightly_rate' => 2000]);
        $this->customer = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Asha', 'phone' => '9'.fake()->numerify('#########'), 'role' => 'customer', 'status' => 'active']);

        $this->app->instance(PaymentGateway::class, new class implements PaymentGateway
        {
            public function identifier(): string { return 'razorpay'; }
            public function displayName(): string { return 'Fake'; }
            public function isConfigured(): bool { return true; }
            public function maskedPublicIdentifier(): ?string { return null; }
            public function checkoutKeyId(): ?string { return 'rzp_test_fake'; }
            public function createOrder(Booking $booking): array { return $this->createRawOrder(1, 'b'); }
            public function createRawOrder(float $amountRupees, string $receipt, array $notes = []): array
            {
                return ['razorpay_order_id' => 'order_'.Str::random(8), 'key_id' => 'rzp_test_fake', 'amount' => (int) round($amountRupees * 100), 'currency' => 'INR'];
            }
            public function verifyWebhookSignature(string $rawPayload, string $signatureHeader): bool { return false; }
            public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool { return false; }
            public function refund(string $gatewayPaymentId, float $amountRupees, string $reason = ''): array { return []; }
        });
    }

    private function coupon(string $module = 'hotel', array $a = []): Coupon
    {
        $c = Coupon::create(array_merge(['code' => 'STAY500', 'name' => 'Stay 500', 'status' => 'active', 'is_active' => true, 'module' => $module,
            'discount_type' => 'flat', 'value' => 500, 'min_order_value' => 0, 'per_user_limit' => 5], $a));
        CouponTarget::create(['coupon_id' => $c->id, 'target_type' => 'global', 'operator' => 'include']);

        return $c;
    }

    private function dates(): array
    {
        $in = now()->addDays(10)->next(Carbon::TUESDAY)->toDateString();

        return [$in, Carbon::parse($in)->addDays(2)->toDateString()];
    }

    private function book(string $method = 'online', ?string $coupon = null, int $children = 0): HotelReservation
    {
        [$in, $out] = $this->dates();

        return app(HotelBookingService::class)->book($this->customer, $this->hotel, $this->room, $this->plan, $in, $out, 1, 2, ['guest_name' => 'Asha'], $method, $coupon, $children);
    }

    // ───────────────────────── coupons ─────────────────────────

    public function test_a_hotel_coupon_comes_off_the_room_price_and_gst_is_on_what_is_left(): void
    {
        $this->coupon();
        $r = $this->book('online', 'stay500');

        // 2 nights × ₹2,000 = ₹4,000 − ₹500 = ₹3,500; 5% GST on ₹3,500 = ₹175; total ₹3,675.
        $this->assertEquals(4000.0, (float) $r->price_quoted, 'The hotel keeps its room price (1CallFix funds the coupon).');
        $this->assertEquals(500.0, (float) $r->coupon_discount_amount);
        $this->assertSame('STAY500', $r->coupon_code);
        $this->assertEquals(175.0, (float) $r->tax_amount);
        $this->assertEquals(3675.0, $r->amountDue());

        $usage = CouponUsage::where('hotel_reservation_id', $r->id)->firstOrFail();
        $this->assertSame('reserved', $usage->status);
        $this->assertEquals(500.0, (float) $usage->discount_applied);

        $checkout = app(HotelBookingService::class)->startOnlinePayment($r);
        $this->assertSame(367500, $checkout['amount'], 'Razorpay asks for the price after the coupon.');
    }

    public function test_wallet_pays_the_price_after_the_coupon(): void
    {
        $this->coupon();
        Setting::set('payment.wallet_enabled', '1');
        app(WalletService::class)->credit($this->customer, 5000, reason: 'top-up', ref: 'test-topup-'.Str::random(6));

        $r = $this->book('wallet', 'STAY500');

        $this->assertSame('confirmed', $r->status);
        $this->assertEquals(5000 - 3675, app(WalletService::class)->balance($this->customer->fresh()));
    }

    /** THUMB RULE: online payment only for all benefits — cash (or pay at hotel) never gets a coupon. */
    public function test_cash_never_gets_a_coupon(): void
    {
        $this->coupon();

        try {
            $this->book('cash', 'STAY500');
            $this->fail('A cash booking with a coupon must be refused.');
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, HotelReservation::count());
        $this->assertSame(0, CouponUsage::count());

        [, $message] = app(HotelBookingService::class)->previewCoupon($this->customer, $this->hotel, 4000, 'cash', 'STAY500');
        $this->assertSame(\App\Exceptions\CouponException::ONLINE_ONLY_MESSAGE, $message);

        $this->actingAs($this->customer, 'sanctum')->postJson('/api/hotel-reservations', [
            'accommodation_id' => $this->hotel->id, 'check_in_date' => $this->dates()[0], 'check_out_date' => $this->dates()[1],
            'number_of_adults' => 2, 'payment_method' => 'cash', 'coupon_code' => 'STAY500',
            'rooms' => [['room_type_id' => $this->room->id, 'rate_plan_id' => $this->plan->id, 'room_count' => 1]],
        ])->assertStatus(422);
        $this->assertSame(0, CouponUsage::count());
    }

    public function test_the_app_books_with_a_coupon_too(): void
    {
        $this->coupon();
        [$in, $out] = $this->dates();

        $this->actingAs($this->customer, 'sanctum')->postJson('/api/hotel-reservations', [
            'accommodation_id' => $this->hotel->id, 'check_in_date' => $in, 'check_out_date' => $out,
            'number_of_adults' => 2, 'number_of_children' => 1, 'coupon_code' => 'STAY500',
            'rooms' => [['room_type_id' => $this->room->id, 'rate_plan_id' => $this->plan->id, 'room_count' => 1]],
        ])->assertCreated()
            ->assertJsonPath('reservation.coupon_code', 'STAY500')
            ->assertJsonPath('reservation.number_of_children', 1);
        $this->assertEquals(3675.0, HotelReservation::firstOrFail()->amountDue());
    }

    public function test_a_service_or_shop_coupon_does_not_work_on_a_hotel(): void
    {
        $this->coupon('service', ['code' => 'FIX100']);

        $this->expectException(\RuntimeException::class);
        $this->book('online', 'FIX100');
    }

    public function test_the_coupon_is_given_back_when_the_booking_is_not_paid_in_time(): void
    {
        $this->coupon();
        $r = $this->book('online', 'STAY500');
        $r->forceFill(['payment_expires_at' => now()->subMinute()])->save();

        app(HotelBookingService::class)->expireUnpaid();

        $this->assertSame('cancelled', $r->fresh()->status);
        $this->assertSame('released', CouponUsage::where('hotel_reservation_id', $r->id)->value('status'));
    }

    public function test_the_coupon_counts_as_used_when_the_stay_completes_or_the_guest_does_not_arrive(): void
    {
        $this->coupon();
        $done = $this->book('online', 'STAY500');
        $done->forceFill(['status' => 'completed'])->save();
        $this->assertSame('confirmed', CouponUsage::where('hotel_reservation_id', $done->id)->value('status'));

        $missed = $this->book('online', 'STAY500');
        $missed->forceFill(['no_show' => true])->save();
        $missed->forceFill(['status' => 'cancelled'])->save();
        $this->assertSame('consumed', CouponUsage::where('hotel_reservation_id', $missed->id)->value('status'));
    }

    public function test_a_late_cancellation_charges_the_nights_at_the_price_the_guest_paid(): void
    {
        $this->coupon();
        $r = $this->book('online', 'STAY500');
        $r->forceFill(['status' => 'confirmed', 'payment_status' => 'paid'])->save();
        Setting::set('hotel.free_cancel_hours', '720'); // free time already over for a check-in 10+ days away

        [$total, $roomPart] = app(\App\Services\Hotels\HotelStayService::class)->chargeFor($r->fresh());

        // 1 of 2 nights: the guest paid (₹4,000 − ₹500) / 2 = ₹1,750 + half the GST ₹87.50; the hotel's part stays ₹2,000.
        $this->assertEquals(1837.5, $total);
        $this->assertEquals(2000.0, $roomPart);
    }

    public function test_the_booking_page_applies_a_coupon_and_shows_the_new_total(): void
    {
        $this->coupon();
        [$in, $out] = $this->dates();

        Livewire::actingAs($this->customer)
            ->withQueryParams(['hotel' => $this->hotel->id, 'room' => $this->room->id, 'plan' => $this->plan->id, 'in' => $in, 'out' => $out, 'rooms' => 1, 'adults' => 2])
            ->test(Book::class)
            ->set('couponInput', 'stay500')
            ->call('applyCoupon')
            ->assertSet('appliedCoupon', 'STAY500')
            ->assertSee('−₹500.00')
            ->assertSee('₹3,675.00')
            ->set('couponInput', '')
            ->call('removeCoupon')
            ->assertSet('appliedCoupon', '')
            ->assertSee('₹4,200.00');
    }

    public function test_a_wrong_code_shows_a_message_and_changes_nothing(): void
    {
        [$in, $out] = $this->dates();

        Livewire::actingAs($this->customer)
            ->withQueryParams(['hotel' => $this->hotel->id, 'room' => $this->room->id, 'plan' => $this->plan->id, 'in' => $in, 'out' => $out, 'rooms' => 1, 'adults' => 2])
            ->test(Book::class)
            ->set('couponInput', 'NOPE')
            ->call('applyCoupon')
            ->assertSet('appliedCoupon', '')
            ->assertSee('₹4,200.00');
    }

    // ───────────────────────── children ─────────────────────────

    public function test_children_are_counted_against_each_room(): void
    {
        $r = $this->book('online', null, 1);
        $this->assertSame(1, (int) $r->number_of_children);

        $this->expectExceptionMessage('These rooms fit at most 1 child each. Add a room.');
        $this->book('online', null, 2);
    }

    public function test_the_search_leaves_out_hotels_whose_rooms_cannot_take_the_children(): void
    {
        [$in, $out] = $this->dates();
        Livewire::withQueryParams(['in' => $in, 'out' => $out, 'adults' => 2, 'children' => 1])
            ->test(Home::class, ['city' => 'nellore'])
            ->assertSee('Sea View Lodge')
            ->assertSee('₹2,100');

        $this->room->update(['max_children' => 0]);
        Livewire::withQueryParams(['in' => $in, 'out' => $out, 'adults' => 2, 'children' => 1])
            ->test(Home::class, ['city' => 'nellore'])
            ->assertDontSee('₹2,100');
    }

    // ───────────────────────── voucher + invoice ─────────────────────────

    public function test_the_guest_gets_a_voucher_once_confirmed_and_an_invoice_after_check_out(): void
    {
        $this->coupon();
        $r = $this->book('online', 'STAY500');
        $this->actingAs($this->customer);

        $this->get(route('customer.hotels.voucher', $r->code))->assertNotFound(); // not paid yet

        $r->forceFill(['status' => 'confirmed', 'payment_status' => 'paid', 'check_in_code' => '4821'])->save();
        $this->get(route('customer.hotels.voucher', $r->code))->assertOk()
            ->assertSee('Booking voucher')->assertSee('4821')->assertSee('Sea View Lodge')->assertSee('Coupon STAY500');
        $this->get(route('customer.hotels.invoice', $r->code))->assertNotFound();

        $r->forceFill(['status' => 'checked_out', 'checked_out_at' => now(), 'guest_gst_number' => '37ABCDE1234F1Z5', 'guest_business_name' => 'Asha Traders'])->save();
        $this->get(route('customer.hotels.invoice', $r->code))->assertOk()
            ->assertSee('Tax invoice')->assertSee('INV-'.$r->code)->assertSee('37ABCDE1234F1Z5')->assertSee('Taxable value')->assertSee('₹3,500.00');
    }

    public function test_nobody_else_can_open_a_guests_voucher(): void
    {
        $r = $this->book();
        $r->forceFill(['status' => 'confirmed', 'payment_status' => 'paid'])->save();
        $stranger = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Other', 'phone' => '9'.fake()->numerify('#########'), 'role' => 'customer', 'status' => 'active']);

        $this->actingAs($stranger)->get(route('customer.hotels.voucher', $r->code))->assertNotFound();
    }
}
