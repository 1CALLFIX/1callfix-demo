<?php

namespace Tests\Feature\Payments;

use App\Actions\CreateBookingAction;
use App\Jobs\ServiceMatchingJob;
use App\Livewire\Bookings\Index as AdminBookings;
use App\Livewire\Customer\Orders\Index as MyBookings;
use App\Livewire\Customer\Orders\Show as OrderShow;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Setting;
use App\Notifications\BookingStatusNotification;
use App\Services\Payments\RazorpayWebhookHandler;
use App\Services\Payments\UnpaidAttemptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Owner rule 2026-11-07: NO PAYMENT, NO BOOKING. An online service booking is only a payment attempt until Razorpay
 * captures it: not listed, not counted, no "booking created" message, nothing dispatched; it ends quietly when time
 * runs out or when the customer books again; the capture is what makes it a booking.
 */
class NoPaymentNoBookingTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    private function world(): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $service = $this->makeService($this->makeCategory());
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        Setting::set('payment.cash_enabled', '1');

        return compact('franchise', 'zone', 'service', 'customer', 'address');
    }

    private function book(array $w, string $method = 'online'): Booking
    {
        return app(CreateBookingAction::class)->execute([
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $w['customer']->id,
            'service_id' => $w['service']->id, 'address_id' => $w['address']->id, 'payment_method' => $method,
        ]);
    }

    private function capture(Booking $booking, string $order = 'order_A'): void
    {
        Payment::create([
            'booking_id' => $booking->id, 'purpose' => 'booking', 'amount' => $booking->amountPayable(),
            'gateway' => 'razorpay', 'gateway_order_id' => $order, 'status' => 'pending',
        ]);
        app(RazorpayWebhookHandler::class)->handleCaptured(['payload' => ['payment' => ['entity' => [
            'id' => 'pay_'.$order, 'order_id' => $order, 'amount' => (int) round($booking->amountPayable() * 100), 'currency' => 'INR',
        ]]]]);
    }

    public function test_an_unpaid_online_booking_is_not_a_booking_anywhere(): void
    {
        Queue::fake();
        Notification::fake();
        $w = $this->world();

        $attempt = $this->book($w);

        $this->assertTrue($attempt->isPaymentAttempt());
        Queue::assertNotPushed(ServiceMatchingJob::class);
        Notification::assertNotSentTo($w['customer'], BookingStatusNotification::class);
        $this->assertSame(0, Booking::booked()->count());
        $this->assertSame(1, Booking::paymentAttempts()->count());

        Livewire::actingAs($w['customer'])->test(MyBookings::class)->assertDontSee($attempt->code);
        $admin = \App\Models\User::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Super Admin', 'phone' => '9'.fake()->unique()->numerify('#########'), 'role' => 'super_admin', 'status' => 'active']);
        Livewire::actingAs($admin)->test(AdminBookings::class)
            ->assertDontSee($attempt->code)
            ->assertSee('Payment attempts (not booked)')
            ->set('attempts', true)
            ->assertSee($attempt->code);
    }

    public function test_the_payment_makes_it_a_booking_and_only_then_the_customer_is_told(): void
    {
        Queue::fake();
        Notification::fake();
        $w = $this->world();
        $attempt = $this->book($w);

        $this->capture($attempt);

        $booking = $attempt->fresh();
        $this->assertFalse($booking->isPaymentAttempt());
        $this->assertSame('paid', $booking->payment_status);
        Queue::assertPushed(ServiceMatchingJob::class, 1);
        Notification::assertSentTo($w['customer'], BookingStatusNotification::class, fn ($n) => $n->eventKey() === 'booking.created');
        Livewire::actingAs($w['customer'])->test(MyBookings::class)->assertSee($booking->code);
    }

    public function test_booking_again_replaces_the_unpaid_attempt_so_there_are_never_two(): void
    {
        Queue::fake();
        Notification::fake();
        $w = $this->world();
        $first = $this->book($w);

        $second = $this->book($w, 'cash'); // back from the payment window, booked again with cash

        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame(1, Booking::booked()->where('customer_id', $w['customer']->id)->count());
        $this->assertSame($second->id, Booking::booked()->sole()->id);
        // Quietly: for the customer the first one never was a booking.
        Notification::assertNotSentTo($w['customer'], BookingStatusNotification::class, fn ($n) => $n->eventKey() === 'booking.cancelled');
    }

    public function test_an_unpaid_attempt_ends_quietly_after_the_set_minutes_with_no_fee(): void
    {
        Queue::fake();
        Notification::fake();
        $w = $this->world();
        $attempt = $this->book($w);

        $this->travel(UnpaidAttemptService::DEFAULT_MINUTES - 1)->minutes();
        $this->assertSame(0, app(UnpaidAttemptService::class)->expire());

        $this->travel(2)->minutes();
        $this->artisan('bookings:expire-unpaid-attempts')->assertSuccessful();

        $ended = $attempt->fresh();
        $this->assertSame('cancelled', $ended->status);
        $this->assertSame(0.0, (float) $ended->cancellation_fee, 'a late payment is refunded in full');
        Notification::assertNotSentTo($w['customer'], BookingStatusNotification::class);
    }

    public function test_super_admin_sets_the_minutes(): void
    {
        Setting::set(UnpaidAttemptService::MINUTES, '30');
        $this->assertSame(30, UnpaidAttemptService::minutes());
        Setting::set(UnpaidAttemptService::MINUTES, '2'); // below 5: ignored
        $this->assertSame(UnpaidAttemptService::DEFAULT_MINUTES, UnpaidAttemptService::minutes());
    }

    public function test_the_page_shows_a_payment_step_not_a_booking_and_can_switch_to_cash(): void
    {
        Queue::fake();
        $w = $this->world();
        $attempt = $this->book($w);

        Livewire::actingAs($w['customer'])->test(OrderShow::class, ['booking' => $attempt])
            ->assertSee('Complete your payment')
            ->assertSee('Your booking is made only after the payment')
            ->assertDontSee('Finding you a professional')
            ->assertSeeHtml('data-testid="attempt-cash"')
            ->call('bookWithCashInstead')
            ->assertRedirect(route('customer.book', ['service' => $w['service']->id, 'pay' => 'cash']));

        $this->assertSame('cancelled', $attempt->fresh()->status);
    }

    public function test_an_ended_attempt_says_no_booking_was_made_and_cannot_be_paid(): void
    {
        Queue::fake();
        $w = $this->world();
        $attempt = $this->book($w);
        $this->travel(UnpaidAttemptService::DEFAULT_MINUTES + 1)->minutes();

        Livewire::actingAs($w['customer'])->test(OrderShow::class, ['booking' => $attempt])
            ->assertSee('Payment not completed')
            ->assertSee('No booking was made')
            ->assertDontSeeHtml('data-testid="attempt-pay"');
        $this->assertSame('cancelled', $attempt->fresh()->status, 'ended on view even before the sweep ran');

        $this->actingAs($w['customer'], 'sanctum')
            ->postJson("/api/bookings/{$attempt->id}/pay/create-order")
            ->assertStatus(409);
    }

    public function test_after_paying_the_page_waits_for_confirmation_instead_of_offering_pay_again(): void
    {
        Queue::fake();
        $w = $this->world();
        $attempt = $this->book($w);

        Livewire::withQueryParams(['paid' => '1'])->actingAs($w['customer'])->test(OrderShow::class, ['booking' => $attempt])
            ->assertSee('Confirming your payment')
            ->assertDontSeeHtml('data-testid="attempt-pay"');
    }

    public function test_cash_and_wallet_bookings_are_bookings_straight_away(): void
    {
        Queue::fake();
        Notification::fake();
        $w = $this->world();

        $cash = $this->book($w, 'cash');

        $this->assertFalse($cash->isPaymentAttempt());
        Queue::assertPushed(ServiceMatchingJob::class);
        Notification::assertSentTo($w['customer'], BookingStatusNotification::class, fn ($n) => $n->eventKey() === 'booking.created');
    }
}
