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

    public function test_two_buyers_never_get_the_last_ticket_twice(): void
    {
        $this->buy([$this->early->id => 2]);
        $other = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Ravi', 'phone' => '9'.fake()->numerify('#########'), 'role' => 'customer', 'status' => 'active']);
        app(EventTicketService::class)->buy($other, $this->event->fresh(), [$this->early->id => 1], 'online', 'Ravi');

        $this->assertThrows(fn () => $this->buy([$this->early->id => 1]), \RuntimeException::class, 'sold out');
        $this->assertSame(3, $this->early->fresh()->sold);
    }
}
