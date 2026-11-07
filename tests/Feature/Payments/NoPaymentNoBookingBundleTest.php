<?php

namespace Tests\Feature\Payments;

use App\Models\Booking;
use App\Models\BookingBundle;
use App\Models\Payment;
use App\Notifications\BookingStatusNotification;
use App\Services\Payments\RazorpayWebhookHandler;
use App\Services\Payments\UnpaidAttemptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Support\BundleConsolidationHelpers;
use Tests\TestCase;

/** NO PAYMENT, NO BOOKING (owner 2026-11-07) for the services cart: an unpaid online bundle is only a payment attempt. */
class NoPaymentNoBookingBundleTest extends TestCase
{
    use BundleConsolidationHelpers;
    use RefreshDatabase;

    private function onlineBundle(): array
    {
        Queue::fake();
        $ctx = $this->makeWorld();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $ctx['franchise'], $ctx['zone']);
        $services = [$this->makeService($ctx['category'], 60, ['base_price' => 300]), $this->makeService($ctx['category'], 60, ['base_price' => 200])];

        $this->actingAs($customer, 'sanctum')->postJson('/api/booking-bundles', [
            'payment_method' => 'online',
            'services' => array_map(fn ($s) => ['service_id' => $s->id, 'address_id' => $address->id], $services),
        ])->assertStatus(201);

        return [BookingBundle::latest('id')->firstOrFail(), $customer];
    }

    private function capture(BookingBundle $bundle): void
    {
        $p = Payment::create(['booking_bundle_id' => $bundle->id, 'purpose' => 'booking_bundle', 'amount' => $bundle->amountPayable(),
            'gateway' => 'razorpay', 'gateway_order_id' => 'order_'.Str::random(10), 'status' => 'pending']);
        app(RazorpayWebhookHandler::class)->handleCaptured(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => [
            'order_id' => $p->gateway_order_id, 'id' => 'pay_'.Str::random(8), 'currency' => 'INR', 'amount' => (int) round($bundle->amountPayable() * 100),
        ]]]]);
    }

    public function test_an_unpaid_bundle_sends_no_booking_messages_until_paid(): void
    {
        Notification::fake();
        [$bundle, $customer] = $this->onlineBundle();

        Notification::assertNotSentTo($customer, BookingStatusNotification::class);
        $this->assertSame(0, Booking::booked()->where('booking_bundle_id', $bundle->id)->count());

        $this->capture($bundle);

        Notification::assertSentToTimes($customer, BookingStatusNotification::class, 2); // "created" for both services, now
        $this->assertSame(2, Booking::booked()->where('booking_bundle_id', $bundle->id)->count());
    }

    public function test_an_unpaid_bundle_ends_quietly_and_a_late_payment_is_refunded_not_revived(): void
    {
        Notification::fake();
        Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'rfnd_1', 'status' => 'processed', 'amount' => 1], 200)]);
        [$bundle, $customer] = $this->onlineBundle();
        $this->travel(UnpaidAttemptService::DEFAULT_MINUTES + 1)->minutes();

        app(UnpaidAttemptService::class)->expire();

        $this->assertSame('cancelled', $bundle->fresh()->status);
        $this->assertSame(2, Booking::where('booking_bundle_id', $bundle->id)->where('status', 'cancelled')->count());
        Notification::assertNotSentTo($customer, BookingStatusNotification::class);

        $this->capture($bundle->fresh());

        $this->assertSame(0, Booking::where('booking_bundle_id', $bundle->id)->where('status', '!=', 'cancelled')->count(), 'never revived');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/refund'));
    }
}
