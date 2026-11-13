<?php

namespace Tests\Feature\Halls;

use App\Contracts\PaymentGateway;
use App\Livewire\Customer\Halls\Book;
use App\Livewire\Venue\Bookings as VenueBookings;
use App\Models\Booking;
use App\Models\Franchise;
use App\Models\Hall;
use App\Models\HallBlockedDate;
use App\Models\HallBooking;
use App\Models\HallSlot;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zone;
use App\Services\Halls\HallBookingService;
use App\Services\ModuleActivationService;
use App\Services\WalletService;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * Function halls R2 (owner 2026-11-13): customers book a hall online or from the wallet — never cash. No payment, no
 * booking: online bookings hold their slot until paid; the capture webhook marks them paid; the owner accepts within
 * Hall Settings' hours, or the booking is declined and refunded in full. Advance 25% now, the rest by 7 days before
 * (everything now when the date is closer). Two bookings never overlap in time, and blocked dates cannot be booked.
 */
class HallBookingTest extends TestCase
{
    use RefreshDatabase;
    use LiveCity;
    use RbacTestHelpers;

    private Hall $hall;
    private HallSlot $fullDay;
    private HallSlot $morning;
    private HallSlot $evening;
    private User $owner;
    private User $customer;
    private $city;
    private Franchise $franchise;
    public array $refunds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->city = $this->liveCity('Nellore');
        $this->franchise = Franchise::where('city_id', $this->city->id)->firstOrFail();
        $zone = Zone::where('franchise_id', $this->franchise->id)->firstOrFail();
        app(ModuleActivationService::class)->setActive(Modules::HALLS, 'franchise', $this->franchise->id, true);

        $this->owner = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Ravi', 'phone' => '9'.fake()->numerify('#########'), 'role' => 'provider', 'status' => 'active']);
        $provider = \App\Models\Provider::create(['user_id' => $this->owner->id, 'franchise_id' => $this->franchise->id, 'zone_id' => $zone->id, 'provider_type' => 'independent', 'kyc_status' => 'approved', 'is_active' => true]);
        $this->hall = Hall::create([
            'provider_id' => $provider->id, 'franchise_id' => $this->franchise->id, 'zone_id' => $zone->id, 'name' => 'Sri Lakshmi Function Hall',
            'slug' => 'sri-lakshmi-function-hall', 'hall_type' => 'Function hall', 'seating_capacity' => 400, 'is_ac' => true, 'address_line' => 'Trunk Road',
            'deposit_amount' => 10000, 'status' => 'approved', 'is_active' => true, 'cover_image' => 'https://example.com/c.jpg', 'photos' => ['https://example.com/1.jpg'],
            'contact_phone' => '9000000001',
        ]);
        $this->fullDay = $this->hall->slots()->create(['name' => 'Full day', 'start_time' => '06:00:00', 'end_time' => '23:00:00', 'price' => 60000, 'sort_order' => 0]);
        $this->morning = $this->hall->slots()->create(['name' => 'Morning', 'start_time' => '06:00:00', 'end_time' => '14:00:00', 'price' => 35000, 'sort_order' => 1]);
        $this->evening = $this->hall->slots()->create(['name' => 'Evening', 'start_time' => '18:00:00', 'end_time' => '02:00:00', 'price' => 40000, 'sort_order' => 2]);
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

    private function day(int $daysAhead): string
    {
        return now('Asia/Kolkata')->addDays($daysAhead)->toDateString();
    }

    private function book(HallSlot $slot, string $date, string $method = 'online', ?User $who = null): HallBooking
    {
        return app(HallBookingService::class)->book($who ?? $this->customer, $this->hall->fresh(), $slot, $date, $method, 'Asha', '9876543210', ['occasion' => 'Wedding', 'guests' => 300]);
    }

    private function capture(HallBooking $booking): Payment
    {
        $checkout = app(HallBookingService::class)->startOnlinePayment($booking);
        $payment = Payment::findOrFail($checkout['payment_id']);
        $payment->forceFill(['status' => 'captured', 'gateway_payment_id' => 'pay_'.Str::random(6), 'captured_at' => now()])->save();
        app(HallBookingService::class)->markPaid($payment->fresh());

        return $payment->fresh();
    }

    // ── money ──

    public function test_advance_now_and_the_rest_with_the_deposit_before_the_date_with_gst_and_fee(): void
    {
        Setting::set('hall.gst_slabs', "* : 18");
        Setting::set('hall.booking_fee', '200');
        $q = HallBookingService::quote($this->hall, $this->fullDay, $this->day(60));

        // ₹60,000 + 18% GST ₹10,800 + ₹200 fee = ₹71,000; advance 25% of ₹70,800 = ₹17,700 + ₹200 fee = ₹17,900.
        $this->assertEquals(10800.0, $q['gst']);
        $this->assertEquals(71000.0, $q['total']);
        $this->assertEquals(17900.0, $q['pay_now']);
        $this->assertEquals(71000.0 + 10000 - 17900, $q['later'], 'The rest plus the ₹10,000 deposit.');
        $this->assertFalse($q['full_now']);
        $this->assertSame(now('Asia/Kolkata')->addDays(53)->toDateString(), $q['balance_due_on']);

        $close = HallBookingService::quote($this->hall, $this->fullDay, $this->day(5));
        $this->assertTrue($close['full_now'], 'Closer than 7 days: everything now.');
        $this->assertEquals(81000.0, $close['pay_now'], 'Total + deposit.');
        $this->assertEquals(0.0, $close['later']);
    }

    public function test_gst_is_optional_and_not_charged_when_the_hall_pays_its_own(): void
    {
        $this->assertEquals(0.0, HallBookingService::quote($this->hall, $this->fullDay, $this->day(60))['gst'], 'Blank bands: no GST.');
        Setting::set('hall.gst_slabs', "* : 18");
        Setting::set('hall.platform_collects_gst', '0');
        $q = HallBookingService::quote($this->hall, $this->fullDay, $this->day(60));
        $this->assertEquals(0.0, $q['gst']);
        $this->assertTrue($q['taxes_known']);
    }

    // ── online: hold, capture, owner ──

    public function test_online_booking_holds_the_slot_until_paid_and_then_waits_for_the_owner(): void
    {
        $date = $this->day(60);
        $booking = $this->book($this->fullDay, $date);
        $this->assertSame('pending_payment', $booking->status);
        $this->assertEquals(15000.0, (float) $booking->pay_now, '25% of ₹60,000.');
        $this->assertNotNull($booking->payment_expires_at);
        $this->assertFalse(HallBookingService::isFree($this->hall, $this->fullDay, $date), 'Held while paying.');

        $payment = $this->capture($booking);
        $this->assertEquals(15000.0, (float) $payment->amount);
        $booking->refresh();
        $this->assertSame('awaiting_owner', $booking->status);
        $this->assertSame('paid', $booking->payment_status);
        $this->assertEquals(15000.0, (float) $booking->paid_amount);
        $this->assertTrue($booking->accept_by->between(now()->addMinutes(119), now()->addMinutes(121)), 'Owner answers within 2 hours.');

        Livewire::actingAs($this->owner)->test(VenueBookings::class)->assertSee('Waiting for your answer')->assertSee('Wedding')
            ->call('accept', $booking->id)->assertSee('Accepted');
        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->actingAs($this->customer)->get(route('customer.halls.booking', $booking->code))->assertOk()->assertSee('Your hall is booked.')->assertSee('9000000001');
    }

    public function test_owner_declines_and_the_customer_gets_everything_back(): void
    {
        $booking = $this->book($this->morning, $this->day(30));
        $this->capture($booking);

        Livewire::actingAs($this->owner)->test(VenueBookings::class)->call('startDecline', $booking->id)->call('decline')->assertHasErrors('reason')
            ->set('reason', 'Already booked that day')->call('decline');
        $booking->refresh();
        $this->assertSame('declined', $booking->status);
        $this->assertEquals(8750.0, (float) $booking->refunded_amount);
        $this->assertSame([8750.0], $this->refunds);
        $this->assertTrue(HallBookingService::isFree($this->hall, $this->morning, $this->day(30)), 'The slot is free again.');
    }

    public function test_not_answered_in_time_is_declined_and_refunded_by_itself(): void
    {
        $booking = $this->book($this->morning, $this->day(30));
        $this->capture($booking);
        $this->travel(121)->minutes();

        $this->artisan('halls:expire')->assertSuccessful();
        $booking->refresh();
        $this->assertSame('declined', $booking->status);
        $this->assertSame('The hall did not answer in time', $booking->decline_reason);
        $this->assertSame([8750.0], $this->refunds);

        $this->assertThrows(fn () => app(HallBookingService::class)->accept($booking, $this->owner), \RuntimeException::class);
    }

    public function test_unpaid_bookings_free_their_slot_and_a_late_payment_is_refunded_if_the_slot_was_taken(): void
    {
        $date = $this->day(40);
        $booking = $this->book($this->fullDay, $date);
        $checkout = app(HallBookingService::class)->startOnlinePayment($booking);
        $this->travel(16)->minutes();

        $this->artisan('halls:expire')->assertSuccessful();
        $this->assertSame('expired', $booking->fresh()->status);
        $this->assertTrue(HallBookingService::isFree($this->hall, $this->fullDay, $date));

        $other = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Ravi', 'phone' => '9'.fake()->numerify('#########'), 'role' => 'customer', 'status' => 'active']);
        $this->book($this->morning, $date, 'online', $other);

        $payment = Payment::findOrFail($checkout['payment_id']);
        $payment->forceFill(['status' => 'captured', 'gateway_payment_id' => 'pay_late', 'captured_at' => now()])->save();
        app(HallBookingService::class)->markPaid($payment->fresh());
        $this->assertSame('expired', $booking->fresh()->status);
        $this->assertSame([15000.0], $this->refunds, 'Paid after the hold ran out: refunded in full.');
    }

    // ── availability ──

    public function test_overlapping_slots_and_blocked_dates_cannot_be_booked(): void
    {
        $date = $this->day(45);
        $this->capture($this->book($this->morning, $date));

        $this->assertFalse(HallBookingService::isFree($this->hall, $this->fullDay, $date), 'Full day overlaps the morning.');
        $this->assertTrue(HallBookingService::isFree($this->hall, $this->evening, $date), 'Evening (6 PM – 2 AM) does not.');
        $this->assertThrows(fn () => $this->book($this->fullDay, $date), \RuntimeException::class, 'just taken');

        // An evening past midnight blocks the next morning's early hours only if they overlap: 6 AM is after 2 AM.
        $this->capture($this->book($this->evening, $date));
        $next = \Illuminate\Support\Carbon::parse($date)->addDay()->toDateString();
        $this->assertTrue(HallBookingService::isFree($this->hall, $this->morning, $next));

        HallBlockedDate::create(['hall_id' => $this->hall->id, 'date' => $this->day(50), 'hall_slot_id' => null]);
        $this->assertFalse(HallBookingService::isFree($this->hall, $this->morning, $this->day(50)));
        HallBlockedDate::create(['hall_id' => $this->hall->id, 'date' => $this->day(51), 'hall_slot_id' => $this->evening->id]);
        $this->assertFalse(HallBookingService::isFree($this->hall, $this->fullDay, $this->day(51)), 'Full day overlaps the blocked evening.');
        $this->assertTrue(HallBookingService::isFree($this->hall, $this->morning, $this->day(51)));
    }

    public function test_dates_outside_the_window_halls_switched_off_and_cash_are_refused(): void
    {
        $this->assertThrows(fn () => $this->book($this->fullDay, now('Asia/Kolkata')->toDateString()), \RuntimeException::class, 'Choose a date');
        $this->assertThrows(fn () => $this->book($this->fullDay, $this->day(400)), \RuntimeException::class, 'Choose a date');
        $this->assertThrows(fn () => $this->book($this->fullDay, $this->day(30), 'cash'), \RuntimeException::class, 'Choose how to pay');

        $this->hall->update(['is_active' => false]);
        $this->assertThrows(fn () => $this->book($this->fullDay, $this->day(30)), \RuntimeException::class, 'not taking bookings');

        $this->hall->update(['is_active' => true]);
        app(ModuleActivationService::class)->setActive(Modules::HALLS, 'franchise', $this->franchise->id, false);
        $this->assertThrows(fn () => $this->book($this->fullDay, $this->day(30)), \App\Exceptions\ModuleNotActiveException::class);
        $this->assertSame(0, HallBooking::count());
    }

    public function test_wallet_pays_the_advance_at_once_and_a_decline_returns_it_to_the_wallet(): void
    {
        Setting::set('payment.wallet_enabled', '1');
        app(WalletService::class)->credit($this->customer, 20000, reason: 'top-up', ref: 'test-topup-'.Str::random(6));

        $booking = $this->book($this->morning, $this->day(30), 'wallet');
        $this->assertSame('awaiting_owner', $booking->status);
        $this->assertEquals(20000 - 8750, app(WalletService::class)->balance($this->customer));

        app(HallBookingService::class)->decline($booking, $this->owner, 'Under repair that week');
        $this->assertEquals(20000.0, app(WalletService::class)->balance($this->customer));
        $this->assertSame([], $this->refunds, 'Wallet money goes back to the wallet, not the bank.');
    }

    // ── pages ──

    public function test_customer_pages_list_the_hall_show_slots_and_book(): void
    {
        $this->get(route('customer.halls.home', $this->city->slug))->assertOk()->assertSee('Sri Lakshmi Function Hall')->assertSee('From ₹35,000')->assertSee('400 seats');
        $this->get(route('customer.halls.show', [$this->city->slug, 'sri-lakshmi-function-hall']))->assertOk()
            ->assertSee('Evening')->assertSee('6:00 PM – 2:00 AM (next day)')->assertSee('₹40,000')->assertSee('Refundable deposit ₹10,000')
            ->assertSee('"@type":"EventVenue"', false)->assertSee('data-testid="hall-continue"', false);

        $this->get(route('customer.halls.book', ['hall' => $this->hall->id]))->assertRedirect();
        $page = Livewire::actingAs($this->customer)->withQueryParams(['hall' => $this->hall->id, 'slot' => $this->morning->id, 'date' => $this->day(30)])
            ->test(Book::class)->assertSee('Free on this date')->assertSee('₹8,750.00')->call('pay', 'online');
        $booking = HallBooking::firstOrFail();
        $page->assertDispatched('razorpay-open');
        $this->assertSame('Asha', $booking->customer_name);

        Livewire::actingAs($this->customer)->withQueryParams(['hall' => $this->hall->id, 'slot' => $this->fullDay->id, 'date' => $this->day(30)])
            ->test(Book::class)->assertSee('Already booked on this date')->assertDontSee('data-testid="hall-pay-online"', false);

        $this->actingAs($this->customer)->get(route('customer.halls.bookings'))->assertOk()->assertSee('Sri Lakshmi Function Hall');
        $stranger = User::create(['uuid' => (string) Str::uuid(), 'name' => 'X', 'phone' => '9'.fake()->numerify('#########'), 'role' => 'customer', 'status' => 'active']);
        $this->actingAs($stranger)->get(route('customer.halls.booking', $booking->code))->assertNotFound();
    }

    public function test_pages_are_hidden_while_halls_are_off_for_the_city(): void
    {
        app(ModuleActivationService::class)->setActive(Modules::HALLS, 'franchise', $this->franchise->id, false);
        $this->get(route('customer.halls.home', $this->city->slug))->assertNotFound();
        $this->get(route('customer.halls.show', [$this->city->slug, 'sri-lakshmi-function-hall']))->assertNotFound();
    }

    public function test_owner_never_sees_another_owners_booking_and_admin_lists_bookings_in_scope(): void
    {
        $booking = $this->book($this->morning, $this->day(30));
        $this->capture($booking);

        $other = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Other', 'phone' => '9'.fake()->numerify('#########'), 'role' => 'provider', 'status' => 'active']);
        $p = \App\Models\Provider::create(['user_id' => $other->id, 'franchise_id' => $this->franchise->id, 'provider_type' => 'independent', 'kyc_status' => 'approved', 'is_active' => true]);
        Hall::create(['provider_id' => $p->id, 'franchise_id' => $this->franchise->id, 'name' => 'Other Hall', 'slug' => 'other-hall', 'status' => 'approved', 'is_active' => true]);
        Livewire::actingAs($other)->test(VenueBookings::class)->assertDontSee('Asha')->call('accept', $booking->id)->assertNotFound();

        Livewire::actingAs($this->makeSuperAdmin())->test(\App\Livewire\HallBookings\Index::class)->assertSee($booking->code)->assertSee('Waiting for the hall');
        Livewire::actingAs($this->makeUserWithNoPermissions())->test(\App\Livewire\HallBookings\Index::class)->assertForbidden();
    }
}
