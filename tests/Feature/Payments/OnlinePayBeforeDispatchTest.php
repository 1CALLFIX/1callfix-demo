<?php

namespace Tests\Feature\Payments;

use App\Actions\CreateBookingAction;
use App\Jobs\ServiceMatchingJob;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\CommissionService;
use App\Services\Coupons\CouponHoldSweepService;
use App\Services\Payments\RazorpayWebhookHandler;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Audit 2026-10-15 L4 — pay before dispatch. An online booking sends no provider offers until its Razorpay payment
 * is captured; a replayed capture dispatches once; unpaid instant bookings expire on the existing hold; and
 * completing an unpaid online booking credits nobody until the payment actually arrives.
 */
class OnlinePayBeforeDispatchTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    private array $w;

    protected function setUp(): void
    {
        parent::setUp();
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $service = $this->makeService($this->makeCategory(['module' => 'service']), ['base_price' => 500]);
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        $this->w = compact('franchise', 'zone', 'service', 'customer', 'address');
        Queue::fake();
    }

    private function book(string $method, array $extra = []): Booking
    {
        return app(CreateBookingAction::class)->execute([
            'franchise_id' => $this->w['franchise']->id, 'zone_id' => $this->w['zone']->id,
            'customer_id' => $this->w['customer']->id, 'service_id' => $this->w['service']->id,
            'address_id' => $this->w['address']->id, 'payment_method' => $method,
        ] + $extra);
    }

    private function capture(Booking $booking, string $suffix = '1'): void
    {
        Payment::firstOrCreate(['gateway_order_id' => 'order_L4_'.$booking->id], [
            'booking_id' => $booking->id, 'purpose' => 'booking', 'amount' => $booking->amountPayable(),
            'gateway' => 'razorpay', 'status' => 'pending',
        ]);

        app(RazorpayWebhookHandler::class)->handleCaptured(['payload' => ['payment' => ['entity' => [
            'id' => 'pay_L4_'.$booking->id.'_'.$suffix, 'order_id' => 'order_L4_'.$booking->id,
            'amount' => (int) round($booking->amountPayable() * 100), 'currency' => 'INR',
        ]]]]);
    }

    public function test_an_unpaid_online_booking_sends_no_offers(): void
    {
        $booking = $this->book('online');

        Queue::assertNotPushed(ServiceMatchingJob::class);
        $this->assertSame('pending', $booking->fresh()->status);
        $this->assertSame('pending', $booking->fresh()->payment_status);
    }

    public function test_capture_dispatches_once_even_when_the_webhook_is_replayed(): void
    {
        $booking = $this->book('online');

        $this->capture($booking);
        $this->capture($booking);

        $this->assertSame('paid', $booking->fresh()->payment_status);
        Queue::assertPushed(ServiceMatchingJob::class, 1);
    }

    public function test_wallet_bookings_still_dispatch_at_creation(): void
    {
        Setting::set('payment.wallet_enabled', '1');
        app(WalletService::class)->credit($this->w['customer'], 1000, 'test top-up', 'test-topup-l4');

        $this->book('wallet');

        Queue::assertPushed(ServiceMatchingJob::class, 1);
    }

    public function test_an_unpaid_instant_online_booking_expires_on_the_existing_hold(): void
    {
        Setting::set('coupons.unpaid_hold_minutes', '30');
        $booking = $this->book('online');

        $this->travel(29)->minutes();
        $this->assertSame(0, app(CouponHoldSweepService::class)->sweep());

        $this->travel(2)->minutes();
        $this->assertSame(1, app(CouponHoldSweepService::class)->sweep());
        $this->assertSame('cancelled', $booking->fresh()->status);
    }

    public function test_no_hold_setting_means_no_expiry(): void
    {
        $booking = $this->book('online');

        $this->travel(3)->hours();
        $this->assertSame(0, app(CouponHoldSweepService::class)->sweep());
        $this->assertSame('pending', $booking->fresh()->status);
    }

    public function test_completing_an_unpaid_online_booking_credits_nobody_until_the_payment_arrives(): void
    {
        $booking = $this->book('online');
        $provider = $this->makeProviderIn($this->w['franchise'], $this->w['zone']);
        $booking->forceFill(['provider_id' => $provider->id, 'status' => 'completed'])->save();

        $this->assertNull(app(CommissionService::class)->applyForBooking($booking->fresh()));
        $this->assertSame(0, Commission::count());
        $this->assertEquals(0.0, app(WalletService::class)->balance($provider->user));
        $this->assertDatabaseHas('activity_log', ['subject_id' => $booking->id, 'description' => 'Completed while online payment is unpaid — provider not credited']);

        // The late payment arrives: the split happens now, exactly once, and no offers go out for a finished job.
        $this->capture($booking);
        $this->capture($booking);

        $this->assertSame(1, Commission::where('booking_id', $booking->id)->count());
        $this->assertGreaterThan(0, app(WalletService::class)->balance($provider->user));
        Queue::assertNotPushed(ServiceMatchingJob::class);
    }
}
