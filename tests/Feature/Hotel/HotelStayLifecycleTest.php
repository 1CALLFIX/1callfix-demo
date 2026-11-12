<?php

namespace Tests\Feature\Hotel;

use App\Contracts\PaymentGateway;
use App\Livewire\Hotel\Bookings;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\Franchise;
use App\Models\HotelReservation;
use App\Models\HotelRoomAvailability;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zone;
use App\Notifications\HotelNewBookingNotification;
use App\Services\Hotels\HotelBookingService;
use App\Services\Hotels\HotelStayService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Support\HotelFixtureHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * Hotels H3: the stay. Paid → confirmed with a check-in code (or the hotel accepts in time); check-in only with the
 * code from check-in day; completed after the payout hold with Hotel Settings' commission; cancellations and
 * no-shows charge by Hotel Settings and refund the rest.
 */
class HotelStayLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use LiveCity;
    use HotelFixtureHelpers;

    private $hotel;

    private $room;

    private $plan;

    private User $customer;

    public array $refunds = [];

    private string $in;

    protected function setUp(): void
    {
        parent::setUp();
        $city = $this->liveCity('Nellore');
        $franchise = Franchise::where('city_id', $city->id)->firstOrFail();
        $franchise->update(['commission_model' => 'none', 'commission_value' => 0]);
        $zone = Zone::where('franchise_id', $franchise->id)->firstOrFail();
        $this->activateHotelFor($franchise);
        Setting::set('hotel.gst_slabs', "* : 0");
        $this->hotel = $this->makeAccommodation($franchise, $zone, null, ['approval_status' => 'approved']);
        $this->room = $this->makeRoomType($this->hotel, ['total_inventory' => 2, 'max_adults' => 2]);
        $this->plan = $this->makeRatePlan($this->room, ['nightly_rate' => 1000]);
        $this->customer = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Asha', 'phone' => '9'.fake()->numerify('#########'), 'role' => 'customer', 'status' => 'active']);
        $this->in = now()->addDays(10)->toDateString();

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

    /** A paid 2-night stay: ₹2,000 room price, no GST. */
    private function paidBooking(): HotelReservation
    {
        $r = app(HotelBookingService::class)->book($this->customer, $this->hotel, $this->room, $this->plan, $this->in, Carbon::parse($this->in)->addDays(2)->toDateString(), 1, 2, ['guest_name' => 'Asha'], 'online');
        $checkout = app(HotelBookingService::class)->startOnlinePayment($r);
        $payment = Payment::findOrFail($checkout['payment_id']);
        $payment->forceFill(['status' => 'captured', 'gateway_payment_id' => 'pay_'.Str::random(6), 'captured_at' => now()])->save();
        app(HotelBookingService::class)->markPaid($payment->fresh());

        return $r->fresh();
    }

    public function test_paid_booking_is_confirmed_with_a_check_in_code_and_the_hotel_is_told(): void
    {
        Notification::fake();
        $r = $this->paidBooking();

        $this->assertSame('confirmed', $r->status);
        $this->assertMatchesRegularExpression('/^\d{4}$/', (string) $r->check_in_code);
        Notification::assertSentTo($this->hotel->provider->user, HotelNewBookingNotification::class);
        $this->actingAs($this->customer)->get(route('customer.hotels.booking', $r->code))->assertOk()->assertSee($r->check_in_code)->assertSee('Your check-in code');
    }

    public function test_hotel_that_must_accept_accepts_or_the_booking_is_cancelled_and_refunded_in_full(): void
    {
        $this->hotel->update(['needs_acceptance' => true]);
        $r = $this->paidBooking();
        $this->assertSame('pending', $r->status);
        $this->assertNotNull($r->accept_by);

        $this->travel(16)->minutes();
        $this->artisan('hotels:expire-unaccepted')->assertSuccessful();
        $this->assertSame('cancelled', $r->fresh()->status);
        $this->assertSame([2000.0], $this->refunds);
        $this->assertSame(0, (int) HotelRoomAvailability::where('hotel_room_type_id', $this->room->id)->sum('rooms_booked'));

        $second = $this->paidBooking();
        Livewire::actingAs($this->hotel->provider->user)->test(Bookings::class)->call('accept', $second->id)->assertSet('error', '');
        $this->assertSame('confirmed', $second->fresh()->status);
        $this->assertNotNull($second->fresh()->check_in_code);
    }

    public function test_check_in_needs_the_right_code_on_check_in_day_then_check_out_and_the_hotel_is_paid_after_the_hold(): void
    {
        $r = $this->paidBooking();
        $owner = $this->hotel->provider->user;

        Livewire::actingAs($owner)->test(Bookings::class)->set('codes.'.$r->id, $r->check_in_code)->call('checkIn', $r->id)->assertSet('error', 'Check-in opens on '.$r->check_in_date->format('j M').'.');

        $this->travelTo(Carbon::parse($this->in)->setTime(9, 0));
        Livewire::actingAs($owner)->test(Bookings::class)->set('codes.'.$r->id, '0000' === $r->check_in_code ? '1111' : '0000')->call('checkIn', $r->id)->assertSet('error', 'Wrong code. Ask the guest for the 4-digit code on their booking.');
        Livewire::actingAs($owner)->test(Bookings::class)->set('codes.'.$r->id, $r->check_in_code)->call('checkIn', $r->id)->assertSet('error', '');
        $this->assertSame('checked_in', $r->fresh()->status);

        Livewire::actingAs($owner)->test(Bookings::class)->call('checkOut', $r->id)->assertSet('error', '');
        $this->artisan('hotels:complete-stays')->assertSuccessful();
        $this->assertSame('checked_out', $r->fresh()->status, 'Not before the payout hold.');

        $this->travel(25)->hours();
        $this->artisan('hotels:complete-stays')->assertSuccessful();
        $this->assertSame('completed', $r->fresh()->status);
        $commission = Commission::where('hotel_reservation_id', $r->id)->firstOrFail();
        $this->assertEquals(300.0, (float) $commission->platform_commission, '15% of ₹2,000 (Hotel Settings).');
        $this->assertEquals(1700.0, (float) $commission->provider_commission);
        $this->assertEquals(1700.0, app(WalletService::class)->balance($owner->fresh()));
    }

    public function test_customer_cancels_free_before_the_deadline_and_pays_one_night_after(): void
    {
        $free = $this->paidBooking();
        app(HotelStayService::class)->customerCancel($free);
        $this->assertSame([2000.0], $this->refunds);
        $this->assertSame('customer', $free->fresh()->cancelled_by);

        $late = $this->paidBooking();
        $this->travelTo(Carbon::parse($this->in)->setTime(1, 0)); // check-in 2 PM: within 24 h
        [$charge] = app(HotelStayService::class)->chargeFor($late->fresh());
        $this->assertEquals(1000.0, $charge, 'One night kept.');
        app(HotelStayService::class)->customerCancel($late->fresh());
        $this->assertSame([2000.0, 1000.0], $this->refunds);
        $this->assertEquals(850.0, (float) Commission::where('hotel_reservation_id', $late->id)->value('provider_commission'), 'The hotel earns its share of the kept night.');
    }

    public function test_no_show_keeps_the_no_show_nights_and_refunds_the_rest(): void
    {
        $r = $this->paidBooking();
        $this->travelTo(Carbon::parse($this->in)->setTime(23, 0));

        Livewire::actingAs($this->hotel->provider->user)->test(Bookings::class)->call('noShow', $r->id)->assertSet('error', '');
        $fresh = $r->fresh();
        $this->assertTrue($fresh->no_show);
        $this->assertSame('cancelled', $fresh->status);
        $this->assertEquals(1000.0, (float) $fresh->cancellation_fee);
        $this->assertSame([1000.0], $this->refunds);
    }

    public function test_a_hotel_cannot_act_on_another_hotels_booking(): void
    {
        $r = $this->paidBooking();
        [, , $franchise, $zone] = [null, null, $this->hotel->franchise, $this->hotel->zone];
        $other = $this->makeAccommodation($franchise, $zone, null, ['approval_status' => 'approved']);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::actingAs($other->provider->user)->test(Bookings::class)->call('noShow', $r->id);
    }
}
