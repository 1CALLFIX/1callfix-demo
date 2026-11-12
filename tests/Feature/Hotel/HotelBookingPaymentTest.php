<?php

namespace Tests\Feature\Hotel;

use App\Contracts\PaymentGateway;
use App\Livewire\Customer\Hotels\Book;
use App\Models\Booking;
use App\Models\Franchise;
use App\Models\HotelReservation;
use App\Models\HotelRoomAvailability;
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
 * Hotels H2b: booking and paying. The customer pays room price + GST + fee from HotelPricing; an online booking holds
 * its rooms only until the unpaid hold ends (no payment, no booking); the webhook capture confirms it; a late or
 * second capture is refunded in full.
 */
class HotelBookingPaymentTest extends TestCase
{
    use RefreshDatabase;
    use LiveCity;
    use HotelFixtureHelpers;

    private $hotel;

    private $room;

    private $plan;

    private User $customer;

    public array $refunds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $city = $this->liveCity('Nellore');
        $franchise = Franchise::where('city_id', $city->id)->firstOrFail();
        $zone = Zone::where('franchise_id', $franchise->id)->firstOrFail();
        $this->activateHotelFor($franchise);
        Setting::set('hotel.gst_slabs', "1000 : 0\n7500 : 5\n* : 18");
        $this->hotel = $this->makeAccommodation($franchise, $zone, null, ['approval_status' => 'approved']);
        $this->room = $this->makeRoomType($this->hotel, ['total_inventory' => 2, 'max_adults' => 2]);
        $this->plan = $this->makeRatePlan($this->room, ['nightly_rate' => 2000]);
        $this->customer = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Asha', 'phone' => '9'.fake()->numerify('#########'), 'role' => 'customer', 'status' => 'active']);

        $test = $this;
        $this->app->instance(PaymentGateway::class, new class($test) implements PaymentGateway
        {
            public function __construct(private $test) {}
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
            public function refund(string $gatewayPaymentId, float $amountRupees, string $reason = ''): array { $this->test->refunds[] = $amountRupees; return []; }
        });
    }

    private function dates(): array
    {
        $in = now()->addDays(10)->next(Carbon::TUESDAY)->toDateString();

        return [$in, Carbon::parse($in)->addDays(2)->toDateString()];
    }

    private function book(string $method = 'online', int $rooms = 1): HotelReservation
    {
        [$in, $out] = $this->dates();

        return app(HotelBookingService::class)->book($this->customer, $this->hotel, $this->room, $this->plan, $in, $out, $rooms, 2, ['guest_name' => 'Asha'], $method);
    }

    private function capture(HotelReservation $r): Payment
    {
        $checkout = app(HotelBookingService::class)->startOnlinePayment($r);
        $payment = Payment::findOrFail($checkout['payment_id']);
        $payment->forceFill(['status' => 'captured', 'gateway_payment_id' => 'pay_'.Str::random(6), 'captured_at' => now()])->save();
        app(HotelBookingService::class)->markPaid($payment->fresh());

        return $payment->fresh();
    }

    public function test_online_booking_holds_rooms_with_taxes_and_the_capture_confirms_it(): void
    {
        $r = $this->book();
        // 2 nights × ₹2,000 = ₹4,000 + 5% GST ₹200 = ₹4,200.
        $this->assertEquals(4000.0, (float) $r->price_quoted);
        $this->assertEquals(200.0, (float) $r->tax_amount);
        $this->assertEquals(4200.0, $r->amountDue());
        $this->assertSame('pending', $r->status);
        $this->assertNotNull($r->payment_expires_at);
        $this->assertSame(1, (int) HotelRoomAvailability::where('hotel_room_type_id', $this->room->id)->value('rooms_booked'), 'Rooms are held while paying.');

        $payment = $this->capture($r);
        $this->assertEquals(4200.0, (float) $payment->amount);
        $fresh = $r->fresh();
        $this->assertSame('paid', $fresh->payment_status);
        $this->assertSame('confirmed', $fresh->status);
    }

    public function test_a_hotel_that_must_accept_keeps_a_paid_booking_waiting(): void
    {
        $this->hotel->update(['needs_acceptance' => true]);
        $r = $this->book();
        $this->capture($r);

        $this->assertSame('paid', $r->fresh()->payment_status);
        $this->assertSame('pending', $r->fresh()->status);
    }

    public function test_wallet_pays_the_full_total_at_once(): void
    {
        Setting::set('payment.wallet_enabled', '1');
        app(WalletService::class)->credit($this->customer, 5000, reason: 'top-up', ref: 'test-topup-'.Str::random(6));

        $r = $this->book('wallet');

        $this->assertSame('confirmed', $r->status);
        $this->assertSame('paid', $r->payment_status);
        $this->assertEquals(800.0, app(WalletService::class)->balance($this->customer->fresh()));
    }

    /** 2026-11-12 walk-through: the mail server was unreachable and the wallet-paid booking stayed unconfirmed. */
    public function test_a_message_that_cannot_be_sent_never_stops_the_booking(): void
    {
        Setting::set('payment.wallet_enabled', '1');
        app(WalletService::class)->credit($this->customer, 5000, reason: 'top-up', ref: 'test-topup-'.Str::random(6));
        \Illuminate\Support\Facades\Notification::shouldReceive('send')->andThrow(new \RuntimeException('Connection to mail server timed out.'));

        $r = $this->book('wallet');

        $this->assertSame('confirmed', $r->status);
        $this->assertNotNull($r->check_in_code);
    }

    public function test_unpaid_booking_expires_frees_the_rooms_and_a_late_payment_is_refunded_in_full(): void
    {
        $r = $this->book();
        $checkout = app(HotelBookingService::class)->startOnlinePayment($r);
        $this->travel(20)->minutes();

        $this->artisan('hotels:expire-unpaid')->assertSuccessful();
        $this->assertSame('cancelled', $r->fresh()->status);
        $this->assertSame(0, (int) HotelRoomAvailability::where('hotel_room_type_id', $this->room->id)->value('rooms_booked'));

        $payment = Payment::findOrFail($checkout['payment_id']);
        $payment->forceFill(['status' => 'captured', 'gateway_payment_id' => 'pay_late'])->save();
        app(HotelBookingService::class)->markPaid($payment->fresh());
        $this->assertSame([4200.0], $this->refunds);
        $this->assertSame('cancelled', $r->fresh()->status, 'Never revived by a late payment.');
    }

    public function test_cannot_book_more_rooms_than_are_free_or_without_gst_bands(): void
    {
        $this->book('online', 2);
        try {
            $this->book();
            $this->fail('Booked a room that was not free.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('just taken', $e->getMessage());
        }

        Setting::set('hotel.gst_slabs', null);
        $this->expectExceptionMessage('Booking opens soon');
        app(HotelBookingService::class)->book($this->customer, $this->hotel, $this->room, $this->plan, now()->addDays(40)->toDateString(), now()->addDays(41)->toDateString(), 1, 2, ['guest_name' => 'Asha'], 'online');
    }

    public function test_book_page_opens_razorpay_and_the_booking_page_is_the_customers_own(): void
    {
        [$in, $out] = $this->dates();
        $component = Livewire::actingAs($this->customer)->withQueryParams(['hotel' => $this->hotel->id, 'room' => $this->room->id, 'plan' => $this->plan->id, 'in' => $in, 'out' => $out, 'rooms' => 1, 'adults' => 2])
            ->test(Book::class)
            ->assertSee('₹4,200.00')
            ->call('pay', 'online')
            ->assertSet('error', '')
            ->assertDispatched('razorpay-open');

        $r = HotelReservation::firstOrFail();
        $this->actingAs($this->customer)->get(route('customer.hotels.booking', $r->code))->assertOk()->assertSee('Waiting for your payment');

        $other = User::create(['uuid' => (string) Str::uuid(), 'name' => 'B', 'phone' => '9'.fake()->numerify('#########'), 'role' => 'customer', 'status' => 'active']);
        $this->actingAs($other)->get(route('customer.hotels.booking', $r->code))->assertNotFound();
    }

    public function test_hotel_page_shows_the_book_button_when_bookable(): void
    {
        [$in, $out] = $this->dates();
        $this->get('/nellore/hotels/'.$this->hotel->slug.'?in='.$in.'&out='.$out)->assertOk()->assertSee('data-testid="hotel-book"', false);
    }
}
