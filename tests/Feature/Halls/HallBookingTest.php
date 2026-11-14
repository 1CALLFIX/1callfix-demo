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
        app(ModuleActivationService::class)->setActive(Modules::RENTAL, 'franchise', $this->franchise->id, true);

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
        app(ModuleActivationService::class)->setActive(Modules::RENTAL, 'franchise', $this->franchise->id, false);
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
        app(ModuleActivationService::class)->setActive(Modules::RENTAL, 'franchise', $this->franchise->id, false);
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

    // ── R3: balance, cancellation, after the event ──

    private function confirmed(HallSlot $slot, int $daysAhead, string $method = 'online'): HallBooking
    {
        $booking = $this->book($slot, $this->day($daysAhead), $method);
        if ($method === 'online') {
            $this->capture($booking);
        }

        return app(HallBookingService::class)->accept($booking->fresh(), $this->owner);
    }

    private function payBalanceOnline(HallBooking $booking): Payment
    {
        $checkout = app(HallBookingService::class)->startBalancePayment($booking->fresh());
        $payment = Payment::findOrFail($checkout['payment_id']);
        $payment->forceFill(['status' => 'captured', 'gateway_payment_id' => 'pay_'.Str::random(6), 'captured_at' => now()])->save();
        app(HallBookingService::class)->markPaid($payment->fresh());

        return $payment->fresh();
    }

    public function test_balance_and_deposit_are_paid_online_from_the_booking_page(): void
    {
        $booking = $this->confirmed($this->fullDay, 60);
        $this->assertTrue($booking->balanceOpen());
        $this->assertEquals(45000 + 10000, $booking->balanceLater(), 'The rest of ₹60,000 plus the ₹10,000 deposit.');
        $this->actingAs($this->customer)->get(route('customer.halls.booking', $booking->code))->assertSee('Pay balance ₹55,000.00');

        $payment = $this->payBalanceOnline($booking);
        $this->assertEquals(55000.0, (float) $payment->amount);
        $booking->refresh();
        $this->assertNotNull($booking->balance_paid_at);
        $this->assertEquals(70000.0, (float) $booking->paid_amount);
        $this->assertSame('held', $booking->deposit_status);
        $this->assertFalse($booking->balanceOpen());
        $this->actingAs($this->customer)->get(route('customer.halls.booking', $booking->code))->assertSee('comes back to you after the function');
    }

    public function test_balance_from_the_wallet(): void
    {
        Setting::set('payment.wallet_enabled', '1');
        app(WalletService::class)->credit($this->customer, 100000, reason: 'top-up', ref: 'test-topup-'.Str::random(6));
        $booking = $this->confirmed($this->fullDay, 60, 'wallet');
        app(HallBookingService::class)->payBalanceFromWallet($booking, $this->customer);
        $this->assertEquals(100000 - 70000, app(WalletService::class)->balance($this->customer));
        $this->assertSame('held', $booking->fresh()->deposit_status);
    }

    public function test_customer_cancellation_follows_the_hall_settings_rule(): void
    {
        // 45 days before: everything back less ₹500.
        $far = $this->confirmed($this->fullDay, 45);
        $this->assertEquals(14500.0, HallBookingService::cancelQuote($far)['refund']);
        $this->actingAs($this->customer)->get(route('customer.halls.booking', $far->code))->assertSee('If you cancel now, you get back ₹14,500.00');
        $this->assertEquals(14500.0, app(HallBookingService::class)->customerCancel($far, $this->customer));
        $this->assertSame('cancelled', $far->fresh()->status);
        $this->assertEquals(0.0, (float) $far->fresh()->kept_amount);
        $this->assertTrue(HallBookingService::isFree($this->hall, $this->fullDay, $this->day(45)), 'The date is open again.');

        // 10 days before: half the advance kept for the owner.
        $mid = $this->confirmed($this->morning, 10);
        $this->assertEquals(8750.0, (float) $mid->advance_amount);
        $q = HallBookingService::cancelQuote($mid);
        $this->assertEquals(4375.0, $q['kept']);
        $this->assertEquals(4375.0, $q['refund']);

        // 3 days before (paid in full with the deposit, the date was close): the advance is kept, the rest and the deposit come back.
        $close = $this->confirmed($this->evening, 3);
        $this->assertTrue($close->paid_in_full);
        $this->assertSame('held', $close->deposit_status);
        $q = HallBookingService::cancelQuote($close);
        $this->assertEquals(10000.0, $q['kept'], '25% of ₹40,000.');
        $this->assertEquals(40000 + 10000 - 10000, $q['refund']);
    }

    public function test_cancelling_before_the_hall_answers_gives_everything_back(): void
    {
        $booking = $this->book($this->morning, $this->day(5));
        $this->capture($booking);
        $this->assertEquals((float) $booking->fresh()->paid_amount, app(HallBookingService::class)->customerCancel($booking->fresh(), $this->customer));
    }

    public function test_the_owner_cancels_and_the_customer_gets_everything_back(): void
    {
        $booking = $this->confirmed($this->fullDay, 60);
        $this->payBalanceOnline($booking);
        $this->refunds = [];

        Livewire::actingAs($this->owner)->test(VenueBookings::class)->assertSee('Fully paid')->call('startCancel', $booking->id)
            ->call('cancelBooking')->assertHasErrors('reason')->set('reason', 'Hall under repair')->call('cancelBooking')->assertSee('gets all their money back');
        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);
        $this->assertEquals(70000.0, (float) $booking->refunded_amount);
        $this->assertEqualsCanonicalizing([55000.0, 15000.0], $this->refunds, 'Each payment refunded back to where it came from.');
    }

    public function test_an_unpaid_balance_is_reminded_then_cancelled_and_the_owner_keeps_the_advance(): void
    {
        $booking = $this->confirmed($this->fullDay, 30);
        $this->travelTo(now('Asia/Kolkata')->addDays(21)->setTime(10, 0));
        $this->artisan('halls:after-events')->assertSuccessful();
        $this->assertNotNull($booking->fresh()->balance_reminded_on, '3 days before the due date: reminded.');
        $this->assertSame('confirmed', $booking->fresh()->status);

        $this->travelTo(now('Asia/Kolkata')->addDays(5)->setTime(10, 0)); // 26 days on: 3 days after the due date
        $this->artisan('halls:after-events')->assertSuccessful();
        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);
        $this->assertSame('Balance not paid in time', $booking->cancellation_reason);
        $this->assertEquals(15000.0, (float) $booking->kept_amount);

        $this->travelTo($booking->ends_at->copy()->addHours(25));
        $this->artisan('halls:after-events')->assertSuccessful();
        $commission = \App\Models\Commission::where('hall_booking_id', $booking->id)->firstOrFail();
        $this->assertEquals(1500.0, (float) $commission->platform_commission, '10% of the ₹15,000 kept.');
    }

    public function test_after_the_event_the_owner_is_paid_and_the_deposit_comes_back_unless_damage_is_reported(): void
    {
        $paid = $this->confirmed($this->fullDay, 60);
        $this->payBalanceOnline($paid);
        $claimed = $this->confirmed($this->morning, 61);
        $this->payBalanceOnline($claimed);
        $this->refunds = [];

        $this->travelTo($claimed->fresh()->ends_at->copy()->addHours(2));
        Livewire::actingAs($this->owner)->test(VenueBookings::class)->set('tab', 'past')->assertSee('Hold the deposit')
            ->call('startDamage', $claimed->id)->set('damageNote', '4 chairs broken and the stage curtain torn')->call('reportDamage')->assertSee('Damage reported');
        $this->assertSame('claimed', $claimed->fresh()->deposit_status);

        $this->travelTo($claimed->fresh()->ends_at->copy()->addHours(49));
        $this->artisan('halls:after-events')->assertSuccessful();
        $paid->refresh();
        $this->assertSame('completed', $paid->status);
        $this->assertSame('returned', $paid->deposit_status);
        $this->assertContains(10000.0, $this->refunds);
        $commission = \App\Models\Commission::where('hall_booking_id', $paid->id)->firstOrFail();
        $this->assertEquals(6000.0, (float) $commission->platform_commission, '10% of ₹60,000.');

        $claimed->refresh();
        $this->assertSame('completed', $claimed->status);
        $this->assertSame('claimed', $claimed->deposit_status, 'Damage reported: the deposit waits for the team.');
        $this->assertCount(1, $this->refunds, 'Only one deposit went back.');
        Livewire::actingAs($this->makeSuperAdmin())->test(\App\Livewire\HallBookings\Index::class)->set('statusFilter', 'damage')->assertSee('4 chairs broken');
    }

    // ── R4: damage claims under the money approval model ──

    /** A finished booking with its deposit held and damage reported (asking ₹4,000 of the ₹10,000 deposit). */
    private function claimed(float $asked = 4000): \App\Models\HallDamageClaim
    {
        $booking = $this->confirmed($this->morning, 40);
        $this->payBalanceOnline($booking);
        $this->travelTo($booking->fresh()->ends_at->copy()->addHours(2));
        Livewire::actingAs($this->owner)->test(VenueBookings::class)->set('tab', 'past')->call('startDamage', $booking->id)
            ->assertSet('damageAmount', '10000')->set('damageNote', '4 chairs broken and the stage curtain torn')->set('damageAmount', (string) $asked)
            ->call('reportDamage')->assertHasNoErrors();
        $this->refunds = [];

        return \App\Models\HallDamageClaim::where('hall_booking_id', $booking->id)->firstOrFail();
    }

    private function claims(): \App\Services\Halls\HallDamageClaimService
    {
        return app(\App\Services\Halls\HallDamageClaimService::class);
    }

    public function test_a_damage_report_opens_a_claim_and_keeps_the_deposit_held(): void
    {
        $claim = $this->claimed();
        $this->assertSame('open', $claim->status);
        $this->assertEquals(4000.0, (float) $claim->asked_amount);
        $this->assertEquals(10000.0, (float) $claim->deposit_amount);

        $this->travelTo($claim->booking->ends_at->copy()->addHours(60));
        $this->artisan('halls:after-events')->assertSuccessful();
        $this->assertSame('claimed', $claim->booking->fresh()->deposit_status, 'Never returned by itself while a claim waits.');
        $this->assertSame([], $this->refunds);
    }

    public function test_super_admin_decides_and_it_settles_at_once_when_no_second_approval_is_set(): void
    {
        $claim = $this->claimed();
        $admin = $this->makeSuperAdmin();

        $this->assertFalse($this->claims()->decide($claim, $admin, 4000, '')['ok'], 'A reason is required.');
        $this->assertFalse($this->claims()->decide($claim, $admin, 12000, 'too much')['ok'], 'At most the deposit.');

        Livewire::actingAs($admin)->test(\App\Livewire\Halls\DamageClaims::class)->assertSee('4 chairs broken')
            ->set('amounts.'.$claim->id, '3000')->assertSee('Customer gets back')->assertSee('₹7,000.00')
            ->set('reasons.'.$claim->id, 'Checked the photos with the owner')->call('decide', $claim->id);

        $claim->refresh();
        $this->assertSame('settled', $claim->status);
        $this->assertEquals(3000.0, (float) $claim->hall_amount);
        $this->assertSame([7000.0], $this->refunds, 'The customer gets the rest back to where they paid.');
        $this->assertEquals(3000.0, app(WalletService::class)->balance($this->owner), 'The hall gets its part in its wallet.');
        $this->assertSame('settled', $claim->booking->fresh()->deposit_status);

        // Idempotent: nothing moves twice.
        $this->assertFalse($this->claims()->decide($claim->fresh(), $admin, 3000, 'again')['ok']);
        $this->assertEquals(3000.0, app(WalletService::class)->balance($this->owner));
    }

    public function test_above_the_threshold_a_different_person_must_approve(): void
    {
        Setting::set(\App\Services\Halls\HallDamageClaimService::DUAL_APPROVAL_KEY, '2000');
        $claim = $this->claimed();
        $maker = $this->makeSuperAdmin();
        $checker = $this->makeSuperAdmin();

        $this->assertTrue($this->claims()->decide($claim, $maker, 4000, 'Damage confirmed')['ok']);
        $this->assertSame('awaiting_approval', $claim->fresh()->status);
        $this->assertSame([], $this->refunds, 'Nothing moves before the second approval.');

        $this->assertFalse($this->claims()->approve($claim->fresh(), $maker, 'self')['ok'], 'Never the same person.');
        $this->assertTrue($this->claims()->reject($claim->fresh(), $checker, 'Ask the owner for photos first')['ok']);
        $this->assertSame('open', $claim->fresh()->status);

        $this->claims()->decide($claim->fresh(), $maker, 4000, 'Photos received');
        $this->assertTrue($this->claims()->approve($claim->fresh(), $checker, 'Photos look right')['ok']);
        $this->assertSame('settled', $claim->fresh()->status);
        $this->assertSame([6000.0], $this->refunds);
    }

    public function test_franchise_limits_and_scope_are_checked_server_side(): void
    {
        $claim = $this->claimed();
        $local = $this->makeUserWithPermission('hall_damage.payout', 'franchise', $this->franchise->id);
        $outsider = $this->makeUserWithNoPermissions();

        // No franchise limit set: a franchise user can decide, but it waits for someone whose limit covers it.
        $this->assertTrue($this->claims()->decide($claim, $local, 4000, 'Damage confirmed')['ok']);
        $this->assertSame('awaiting_approval', $claim->fresh()->status);

        Setting::set(\App\Services\Halls\HallDamageClaimService::FRANCHISE_LIMIT_KEY, '5000');
        $other = $this->makeUserWithPermission('hall_damage.payout', 'franchise', $this->franchise->id);
        $this->assertTrue($this->claims()->approve($claim->fresh(), $other, 'Within our limit')['ok']);
        $this->assertSame('settled', $claim->fresh()->status);

        Livewire::actingAs($outsider)->test(\App\Livewire\Halls\DamageClaims::class)->assertForbidden();
    }

    public function test_nothing_for_the_hall_returns_the_whole_deposit(): void
    {
        $claim = $this->claimed();
        $this->assertTrue($this->claims()->decide($claim, $this->makeSuperAdmin(), 0, 'No damage seen on the photos')['ok']);
        $this->assertSame([10000.0], $this->refunds);
        $this->assertEquals(0.0, app(WalletService::class)->balance($this->owner));
    }

    public function test_waiting_claims_escalate_after_the_set_hours(): void
    {
        Setting::set(\App\Services\Halls\HallDamageClaimService::FRANCHISE_LIMIT_KEY, '5000');
        Setting::set(\App\Services\Halls\HallDamageClaimService::HQ_LIMIT_KEY, '20000');
        $claim = $this->claimed();
        $this->artisan('refunds:escalate-hall-damage')->assertSuccessful();
        $this->assertSame(0, $claim->fresh()->escalation_level, 'Escalation off until hours are set.');

        Setting::set(\App\Services\Halls\HallDamageClaimService::ESCALATE_HOURS_KEY, '4');
        $this->travel(9)->hours();
        $this->artisan('refunds:escalate-hall-damage')->assertSuccessful();
        $this->assertSame(3, $claim->fresh()->escalation_level, 'Franchise → HQ → Super Admin after 2 × 4 hours.');
    }

    // ── several days, availability, facilities (owner 2026-11-14) ──

    public function test_a_two_day_booking_charges_each_day_and_holds_the_hall_for_both(): void
    {
        $q = HallBookingService::quote($this->hall, $this->fullDay, $this->day(60), 2);
        $this->assertSame(120000.0, $q['price']);
        $this->assertSame(60000.0, $q['per_day']);

        $booking = app(HallBookingService::class)->book($this->customer, $this->hall->fresh(), $this->fullDay, $this->day(60), 'online', 'Asha', '9876543210', ['days' => 2]);
        $this->assertSame(2, $booking->days);
        $this->assertSame($this->day(61), $booking->last_date->toDateString());
        $this->assertSame(120000.0, (float) $booking->hall_price);
        $this->assertStringContainsString('(2 days)', $booking->dateLabel());

        // Both days are held; the day after is free.
        $this->assertFalse(HallBookingService::isFree($this->hall, $this->morning, $this->day(61)));
        $this->assertFalse(HallBookingService::isFree($this->hall, $this->fullDay, $this->day(59), null, 2));
        $this->assertTrue(HallBookingService::isFree($this->hall, $this->morning, $this->day(62)));
    }

    public function test_several_days_are_refused_when_one_day_is_taken_or_over_the_limit(): void
    {
        HallBlockedDate::create(['hall_id' => $this->hall->id, 'date' => $this->day(41)]);
        try {
            app(HallBookingService::class)->book($this->customer, $this->hall->fresh(), $this->fullDay, $this->day(40), 'online', 'Asha', '9876543210', ['days' => 2]);
            $this->fail('A blocked second day must stop the booking.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('just taken', $e->getMessage());
        }

        Setting::set('hall.max_days', '1');
        $this->expectExceptionMessage('Choose from 1 to 1 days.');
        app(HallBookingService::class)->book($this->customer, $this->hall->fresh(), $this->fullDay, $this->day(50), 'online', 'Asha', '9876543210', ['days' => 2]);
    }

    public function test_the_book_page_offers_day_chips_and_bills_each_day(): void
    {
        Livewire::actingAs($this->customer)->withQueryParams(['hall' => $this->hall->id, 'slot' => $this->morning->id, 'date' => $this->day(30)])
            ->test(Book::class)->assertSee('How many days?')->assertSee('3 days')
            ->set('days', '2')->assertSee('× 2')->assertSee('₹70,000.00')->call('pay', 'online');
        $this->assertSame(2, HallBooking::firstOrFail()->days);
    }

    public function test_availability_shows_free_some_and_booked_days(): void
    {
        $this->capture($this->book($this->morning, $this->day(20)));
        $this->capture($this->book($this->fullDay, $this->day(21)));
        $states = HallBookingService::availability($this->hall->fresh(), $this->day(19), 4);

        $this->assertSame('free', $states[$this->day(19)]);
        $this->assertSame('part', $states[$this->day(20)], 'Morning taken, evening still free.');
        $this->assertSame('full', $states[$this->day(21)]);
        $this->assertSame('closed', HallBookingService::availability($this->hall->fresh(), $this->day(0), 1)[$this->day(0)], 'Before the first bookable day.');

        Livewire::withoutLazyLoading()->test(\App\Livewire\Customer\Halls\Availability::class, ['hallId' => $this->hall->id, 'citySlug' => $this->city->slug])
            ->assertSee('Availability')
            ->set('month', \Illuminate\Support\Carbon::parse($this->day(21))->startOfMonth()->toDateString())
            ->assertSee('data-state="full"', false)->assertSee('date='.$this->day(22), false);
    }

    public function test_hall_page_shows_every_photo_facility_sections_free_or_paid_outside_answers_and_rules(): void
    {
        $this->hall->update([
            'photos' => ['https://example.com/1.jpg', 'https://example.com/2.jpg', 'https://example.com/3.jpg'],
            'amenities' => ['Car parking', 'Fire extinguishers', 'Generator / power backup', 'Old facility'],
            'facility_charges' => ['Generator / power backup' => 1500],
            'outside_policy' => ['Outside food' => 'no', 'Vessels and utensils from outside' => 'fee'],
            'rule_list' => ['No smoking inside'],
        ]);
        $this->get(route('customer.halls.show', [$this->city->slug, 'sri-lakshmi-function-hall']))->assertOk()
            ->assertSee('See all 4 photos')->assertSee('data-testid="photo-thumbs"', false)->assertSee('https://example.com/3.jpg')
            ->assertSeeInOrder(['Parking and arrival', 'Car parking', 'Safety', 'Fire extinguishers'])
            ->assertSee('Paid · ₹1,500')->assertSee('Old facility')
            ->assertSeeInOrder(['Bringing things from outside', 'Outside food', 'Not allowed', 'Vessels and utensils from outside', 'Allowed with a fee'])
            ->assertSee('No smoking inside')->assertSee('Book up to 3 days in a row');
    }

    // ── one Rental (owner 2026-11-14) ──

    public function test_one_rentals_door_and_page_list_function_halls(): void
    {
        $this->get(route('customer.rentals.home', $this->city->slug))->assertOk()
            ->assertSee('What do you want on rent?')->assertSee('data-kind="halls"', false)->assertSee('Sri Lakshmi Function Hall')
            ->assertSee(route('customer.halls.home', $this->city->slug));
        $this->assertSame(1, \App\Livewire\Customer\Rentals\Home::liveCountIn($this->city));
        $this->get('/')->assertOk()->assertSee(route('customer.rentals.home', $this->city->slug))->assertSee('Rentals');
    }

    public function test_switching_function_halls_off_in_rental_settings_hides_and_stops_them(): void
    {
        Setting::set('rental.kind.halls', '0');
        $this->get(route('customer.rentals.home', $this->city->slug))->assertOk()->assertDontSee('data-kind="halls"', false); // Property for rent is still on
        Setting::set('rental.kind.properties', '0');
        $this->get(route('customer.rentals.home', $this->city->slug))->assertNotFound();
        $this->get(route('customer.halls.home', $this->city->slug))->assertNotFound();
        $this->assertFalse(HallBookingService::bookable($this->hall->fresh()));
        $this->expectException(\App\Exceptions\ModuleNotActiveException::class);
        $this->book($this->fullDay, $this->day(30));
    }
}
