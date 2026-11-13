<?php

namespace Tests\Feature\Events;

use App\Contracts\PaymentGateway;
use App\Livewire\Customer\Events\Book;
use App\Livewire\Customer\Events\Order;
use App\Models\Booking;
use App\Models\Event;
use App\Models\EventOrder;
use App\Models\EventOrganiser;
use App\Models\EventTicketType;
use App\Models\Franchise;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zone;
use App\Services\Events\EventTicketService;
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
 * Events E2 (owner 2026-11-13): customers buy tickets online or from the wallet — never cash. No payment, no ticket:
 * online orders hold their tickets until paid; the capture webhook issues the tickets (one QR code each); unpaid
 * orders expire and put the tickets back on sale; a late payment is refunded in full. GST from the Event Settings
 * bands (blank = no sale), booking fee per ticket.
 */
class TicketPurchaseTest extends TestCase
{
    use RefreshDatabase;
    use LiveCity;
    use RbacTestHelpers;

    private Event $event;
    private EventTicketType $early;
    private EventTicketType $couple;
    private User $customer;
    private $city;
    public array $refunds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->city = $this->liveCity('Nellore');
        $franchise = Franchise::where('city_id', $this->city->id)->firstOrFail();
        $zone = Zone::where('franchise_id', $franchise->id)->firstOrFail();
        app(ModuleActivationService::class)->setActive(Modules::EVENTS, 'franchise', $franchise->id, true);
        Setting::set('event.gst_slabs', "500 : 0\n* : 18");

        $owner = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Ravi', 'phone' => '9'.fake()->numerify('#########'), 'role' => 'provider', 'status' => 'active']);
        $provider = \App\Models\Provider::create(['user_id' => $owner->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'provider_type' => 'independent', 'kyc_status' => 'approved', 'is_active' => true]);
        $organiser = EventOrganiser::create(['provider_id' => $provider->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'name' => 'Nellore Nights', 'approval_status' => 'approved', 'is_active' => true]);
        $this->event = Event::create([
            'event_organiser_id' => $organiser->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'title' => 'Year End Bash',
            'slug' => 'year-end-bash', 'category' => 'New Year', 'venue_name' => 'Grand Hall', 'venue_address' => 'Trunk Road',
            'starts_at' => now()->addDays(20), 'ends_at' => now()->addDays(20)->addHours(5), 'status' => 'approved', 'cover_image' => 'https://example.com/c.jpg',
        ]);
        $this->early = $this->event->ticketTypes()->create(['name' => 'Early Bird', 'price' => 499, 'quantity' => 3, 'admits' => 1, 'max_per_order' => 10]);
        $this->couple = $this->event->ticketTypes()->create(['name' => 'Couple', 'price' => 1000, 'quantity' => 50, 'admits' => 2, 'max_per_order' => 4]);
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

    private function buy(array $qty, string $method = 'online'): EventOrder
    {
        return app(EventTicketService::class)->buy($this->customer, $this->event->fresh(), $qty, $method, 'Asha', '9876543210');
    }

    private function capture(EventOrder $order): Payment
    {
        $checkout = app(EventTicketService::class)->startOnlinePayment($order);
        $payment = Payment::findOrFail($checkout['payment_id']);
        $payment->forceFill(['status' => 'captured', 'gateway_payment_id' => 'pay_'.Str::random(6), 'captured_at' => now()])->save();
        app(EventTicketService::class)->markPaid($payment->fresh());

        return $payment->fresh();
    }

    public function test_online_order_holds_tickets_with_gst_and_fee_and_the_capture_issues_them(): void
    {
        Setting::set('event.booking_fee_per_ticket', '20');
        $order = $this->buy([$this->early->id => 2, $this->couple->id => 1]);

        // 2 × ₹499 (0% GST) + 1 × ₹1,000 (18% = ₹180) + 3 × ₹20 fee = ₹2,238.
        $this->assertEquals(1998.0, (float) $order->subtotal);
        $this->assertEquals(180.0, (float) $order->tax_amount);
        $this->assertEquals(60.0, (float) $order->booking_fee);
        $this->assertEquals(2238.0, (float) $order->total);
        $this->assertSame('pending', $order->status);
        $this->assertSame(2, $this->early->fresh()->sold, 'Held while paying.');
        $this->assertSame(0, $order->tickets()->count(), 'No payment, no ticket.');

        $payment = $this->capture($order);
        $this->assertEquals(2238.0, (float) $payment->amount);
        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(3, $order->tickets()->count());
        $this->assertSame(2, $order->tickets()->where('event_ticket_type_id', $this->couple->id)->value('admits'));
        $this->assertSame(3, $order->tickets()->distinct('code')->count('code'));
    }

    public function test_wallet_pays_at_once_and_cash_is_never_taken(): void
    {
        Setting::set('payment.wallet_enabled', '1');
        app(WalletService::class)->credit($this->customer, 5000, reason: 'top-up', ref: 'test-topup-'.Str::random(6));

        $order = $this->buy([$this->early->id => 1], 'wallet');
        $this->assertSame('confirmed', $order->status);
        $this->assertSame(1, $order->tickets()->count());
        $this->assertEquals(4501.0, app(WalletService::class)->balance($this->customer));

        $this->expectException(\RuntimeException::class);
        $this->buy([$this->early->id => 1], 'cash');
    }

    public function test_limits_sold_out_and_closed_sales(): void
    {
        $this->assertThrows(fn () => $this->buy([$this->early->id => 4]), \RuntimeException::class, 'Only 3 Early Bird tickets left.');
        $this->assertThrows(fn () => $this->buy([$this->couple->id => 5]), \RuntimeException::class, 'At most 4 Couple tickets in one order.');
        Setting::set('event.max_tickets_per_order', '3');
        $this->assertThrows(fn () => $this->buy([$this->early->id => 2, $this->couple->id => 2]), \RuntimeException::class, 'At most 3 tickets in one order.');
        $this->assertThrows(fn () => $this->buy([]), \RuntimeException::class, 'Choose at least one ticket.');

        $this->early->update(['sale_ends_at' => now()->subMinute()]);
        $this->assertThrows(fn () => $this->buy([$this->early->id => 1]), \RuntimeException::class, 'no longer on sale');

        $this->event->update(['starts_at' => now()->addMinutes(30)]);
        $this->assertThrows(fn () => $this->buy([$this->couple->id => 1]), \RuntimeException::class, 'not on sale');
        $this->assertSame(0, EventOrder::count());
    }

    public function test_gst_bands_blank_means_nothing_can_be_sold(): void
    {
        Setting::set('event.gst_slabs', '');
        $this->assertThrows(fn () => $this->buy([$this->couple->id => 1]), \RuntimeException::class, 'Ticket sales open soon');
    }

    public function test_events_switched_off_for_the_city_refuses_and_hides_the_pages(): void
    {
        $this->get(route('customer.events.home', $this->city->slug))->assertOk()->assertSee('Year End Bash');
        $this->get(route('customer.events.show', [$this->city->slug, 'year-end-bash']))->assertOk()->assertSee('Early Bird')->assertSee('₹1,180');

        app(ModuleActivationService::class)->setActive(Modules::EVENTS, 'franchise', $this->event->franchise_id, false);
        $this->get(route('customer.events.home', $this->city->slug))->assertNotFound();
        $this->assertThrows(fn () => $this->buy([$this->couple->id => 1]), \App\Exceptions\ModuleNotActiveException::class);
    }

    public function test_an_event_not_approved_is_not_public(): void
    {
        $this->event->update(['status' => 'in_review']);
        $this->get(route('customer.events.show', [$this->city->slug, 'year-end-bash']))->assertNotFound();
    }

    public function test_unpaid_orders_expire_and_a_late_payment_is_refunded_in_full(): void
    {
        $order = $this->buy([$this->early->id => 2]);
        $checkout = app(EventTicketService::class)->startOnlinePayment($order);

        $this->travel(11)->minutes();
        $this->artisan('events:expire-unpaid')->assertSuccessful();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(0, $this->early->fresh()->sold, 'Back on sale.');

        $payment = Payment::findOrFail($checkout['payment_id']);
        $payment->forceFill(['status' => 'captured', 'gateway_payment_id' => 'pay_late', 'captured_at' => now()])->save();
        app(EventTicketService::class)->markPaid($payment->fresh());
        $this->assertSame([998.0], $this->refunds);
        $this->assertSame(0, $order->tickets()->count());
    }

    public function test_a_second_capture_is_refunded(): void
    {
        $order = $this->buy([$this->early->id => 1]);
        $this->capture($order);
        $checkout = app(EventTicketService::class)->startOnlinePayment(EventOrder::find($order->id)->forceFill(['status' => 'pending', 'payment_status' => 'pending'])) ;
        EventOrder::whereKey($order->id)->update(['status' => 'confirmed', 'payment_status' => 'paid']);
        $payment = Payment::findOrFail($checkout['payment_id']);
        $payment->forceFill(['status' => 'captured', 'gateway_payment_id' => 'pay_twice', 'captured_at' => now()])->save();
        app(EventTicketService::class)->markPaid($payment->fresh());

        $this->assertSame([499.0], $this->refunds);
        $this->assertSame(1, $order->tickets()->count());
    }

    public function test_buy_page_charges_the_bill_shown_and_the_tickets_page_is_the_buyers_own(): void
    {
        Setting::set('payment.wallet_enabled', '1');
        app(WalletService::class)->credit($this->customer, 5000, reason: 'top-up', ref: 'test-topup-'.Str::random(6));

        $this->actingAs($this->customer)->get(route('customer.events.book', ['event' => $this->event->id, 't' => [$this->couple->id => 2]]))
            ->assertOk()->assertSee('2 × Couple')->assertSee('₹2,360.00');

        Livewire::actingAs($this->customer)->withQueryParams(['event' => $this->event->id, 't' => [$this->couple->id => 2]])
            ->test(Book::class)->call('pay', 'wallet');
        $order = EventOrder::firstOrFail();
        $this->assertEquals(2360.0, (float) $order->total);

        $this->actingAs($this->customer)->get(route('customer.events.order', $order->code))->assertOk()
            ->assertSee($order->tickets()->first()->code)->assertSee('data-qr=', false);
        $stranger = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Other', 'phone' => '9'.fake()->numerify('#########'), 'role' => 'customer', 'status' => 'active']);
        $this->actingAs($stranger)->get(route('customer.events.order', $order->code))->assertNotFound();
        $this->actingAs($this->customer)->get(route('customer.events.tickets'))->assertOk()->assertSee('Year End Bash');
    }

    // ── E3: gate, cancelling, buyer cancellation, organiser payout ──

    private function organiserUser(): User
    {
        return $this->event->organiser->provider->user;
    }

    public function test_gate_lets_each_ticket_in_once_and_only_at_its_own_event(): void
    {
        Setting::set('payment.wallet_enabled', '1');
        app(WalletService::class)->credit($this->customer, 5000, reason: 'top-up', ref: 'test-topup-'.Str::random(6));
        $order = $this->buy([$this->couple->id => 1], 'wallet');
        $code = $order->tickets()->first()->code;
        $service = app(EventTicketService::class);

        $first = $service->checkIn($this->event, strtolower($code), $this->organiserUser());
        $this->assertTrue($first['ok']);
        $this->assertStringContainsString('let 2 people in', $first['message']);
        $this->assertFalse($service->checkIn($this->event, $code, $this->organiserUser())['ok'], 'Used once.');
        $this->assertStringContainsString('Already used', $service->checkIn($this->event, $code, $this->organiserUser())['message']);
        $this->assertFalse($service->checkIn($this->event, 'TNOTAREALONE', $this->organiserUser())['ok']);

        $other = $this->event->replicate(['slug'])->fill(['slug' => 'other-night']);
        $other->save();
        $this->assertSame('Not a ticket for this event.', $service->checkIn($other, $order->tickets()->latest('id')->first()->code, $this->organiserUser())['message']);

        // The organiser's own gate page; another organiser cannot open it.
        Livewire::actingAs($this->organiserUser())->test(\App\Livewire\Organiser\Gate::class, ['eventId' => $this->event->id])
            ->assertSee('2 / 2 in');
        $this->actingAs($this->customer)->get(route('organiser.events.gate', $this->event->id))->assertForbidden();
    }

    public function test_cancelling_the_event_refunds_every_buyer_in_full_once(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        Setting::set('event.booking_fee_per_ticket', '20');
        Setting::set('payment.wallet_enabled', '1');
        app(WalletService::class)->credit($this->customer, 5000, reason: 'top-up', ref: 'test-topup-'.Str::random(6));
        $wallet = $this->buy([$this->early->id => 1], 'wallet');           // ₹499 + ₹20 = ₹519 from the wallet
        $online = $this->buy([$this->couple->id => 1]);                     // ₹1,000 + ₹180 GST + ₹20 = ₹1,200 online
        $this->capture($online);
        $unpaid = $this->buy([$this->early->id => 1]);

        Livewire::actingAs($this->organiserUser())->test(\App\Livewire\Organiser\EventEditor::class, ['eventId' => $this->event->id])
            ->call('cancelEvent')->assertHasErrors('cancelReason')
            ->set('cancelReason', 'Venue not available')->call('cancelEvent')->assertHasNoErrors();

        $this->assertSame('cancelled', $this->event->fresh()->status);
        $this->assertEquals(5000.0, app(WalletService::class)->balance($this->customer), 'Wallet order back in full, fee included.');
        $this->assertSame([1200.0], $this->refunds, 'Online order back in full through Razorpay.');
        foreach ([$wallet, $online] as $o) {
            $this->assertSame('cancelled', $o->fresh()->status);
            $this->assertSame('refunded', $o->fresh()->payment_status);
            $this->assertSame(0, $o->tickets()->where('status', 'valid')->count());
        }
        $this->assertSame('cancelled', $unpaid->fresh()->status);
        \Illuminate\Support\Facades\Notification::assertSentTo($this->customer, \App\Notifications\EventOrderStatusNotification::class);

        // Again: nothing more is refunded.
        $this->assertThrows(fn () => app(EventTicketService::class)->cancelEvent($this->event->fresh(), $this->organiserUser(), 'Again please'), \RuntimeException::class);
        $this->assertSame([1200.0], $this->refunds);
        $this->assertFalse(app(EventTicketService::class)->checkIn($this->event, $online->tickets()->first()->code, $this->organiserUser())['ok']);
    }

    public function test_admin_can_cancel_an_event_too(): void
    {
        Livewire::actingAs($this->makeSuperAdmin())->test(\App\Livewire\Events\Manage::class)
            ->call('viewEvent', $this->event->id)->assertSee('Cancel the event')
            ->set('cancelReason', 'Organiser not reachable')->call('cancelEvent', $this->event->id);
        $this->assertSame('cancelled', $this->event->fresh()->status);
        $this->assertSame('Organiser not reachable', $this->event->fresh()->cancellation_reason);
    }

    public function test_buyers_cancel_only_when_event_settings_allow_it_and_keep_no_fee(): void
    {
        Setting::set('event.booking_fee_per_ticket', '20');
        Setting::set('payment.wallet_enabled', '1');
        app(WalletService::class)->credit($this->customer, 5000, reason: 'top-up', ref: 'test-topup-'.Str::random(6));
        $order = $this->buy([$this->early->id => 2], 'wallet'); // 2 × ₹499 + ₹40 fee = ₹1,038
        $this->assertFalse(EventTicketService::buyerCanCancel($order), 'Off by default.');
        $this->assertThrows(fn () => app(EventTicketService::class)->buyerCancel($order, $this->customer), \RuntimeException::class);

        Setting::set('event.buyer_cancel_allowed', '1');
        $this->get(route('customer.events.show', [$this->city->slug, 'year-end-bash']))->assertSee('48 hours before the start');
        $page = Livewire::actingAs($this->customer)->test(Order::class, ['code' => $order->code])->assertSee('Cancel these tickets')->call('cancelTickets');
        $page->assertSee('₹998.00 refunded');
        $this->assertEquals(5000.0 - 40.0, app(WalletService::class)->balance($this->customer), 'Booking fee kept.');
        $this->assertSame(0, $this->early->fresh()->sold, 'Back on sale.');

        $late = $this->buy([$this->early->id => 1], 'wallet');
        $this->event->update(['starts_at' => now()->addHours(10)]);
        $this->assertFalse(EventTicketService::buyerCanCancel($late->fresh()), 'Too close to the start.');
    }

    public function test_after_the_event_and_the_payout_hold_the_organiser_is_paid_once(): void
    {
        Setting::set('event.booking_fee_per_ticket', '20');
        $order = $this->buy([$this->couple->id => 2]); // ticket sales ₹2,000 (+ GST ₹360 + fee ₹40)
        $this->capture($order);
        $this->event->update(['starts_at' => now()->subHours(30), 'ends_at' => now()->subHours(25)]);

        $this->artisan('events:complete')->assertSuccessful();
        $this->assertSame('approved', $this->event->fresh()->status, 'Within the 48-hour hold.');

        $this->travel(24)->hours();
        $this->artisan('events:complete')->assertSuccessful();
        $this->assertSame('completed', $this->event->fresh()->status);
        $commission = \App\Models\Commission::where('event_order_id', $order->id)->firstOrFail();
        $this->assertEquals(200.0, (float) $commission->platform_commission, '10% of the ticket prices.');
        $this->assertEquals(2000.0 - 200.0 - (float) $commission->franchise_commission, (float) $commission->provider_commission,
            'Ticket prices only: GST stays with 1CallFix (it pays it), the fee is 1CallFix\'s; the franchise takes its usual share.');
        $this->assertEquals((float) $commission->provider_commission, app(WalletService::class)->balance($this->organiserUser()));

        $this->event->update(['status' => 'approved']);
        $this->artisan('events:complete')->assertSuccessful();
        $this->assertSame(1, \App\Models\Commission::where('event_order_id', $order->id)->count());
        $this->assertEquals((float) $commission->provider_commission, app(WalletService::class)->balance($this->organiserUser()));
    }

    public function test_dashboard_counts_paid_ticket_orders_only(): void
    {
        $paid = $this->buy([$this->couple->id => 1]);
        $this->capture($paid);
        $this->buy([$this->early->id => 1]); // unpaid attempt

        $row = \App\Support\ModuleSales::summary($this->makeSuperAdmin(), now()->subDay())['events'];
        $this->assertSame(1, $row['orders']);
        $this->assertEquals(1180.0, $row['sales']);
    }

    public function test_two_buyers_never_get_the_last_ticket_twice(): void
    {
        $this->buy([$this->early->id => 2]);
        $other = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Ravi', 'phone' => '9'.fake()->numerify('#########'), 'role' => 'customer', 'status' => 'active']);
        app(EventTicketService::class)->buy($other, $this->event->fresh(), [$this->early->id => 1], 'online', 'Ravi');

        $this->assertThrows(fn () => $this->buy([$this->early->id => 1]), \RuntimeException::class, 'sold out');
        $this->assertSame(3, $this->early->fresh()->sold);
    }
}
