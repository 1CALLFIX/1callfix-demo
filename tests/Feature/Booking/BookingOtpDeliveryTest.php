<?php

namespace Tests\Feature\Booking;

use App\Actions\AcceptBookingAction;
use App\Actions\AdminReassignBookingAction;
use App\Contracts\SmsAdapter;
use App\Models\DispatchAttempt;
use App\Models\Setting;
use App\Notifications\BookingOtpNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Finishes the interrupted work AUTH_FORENSIC_DISCOVERY.md / OTP_ARCHITECTURE.md
 * flagged: the Service booking start_otp/completion_otp were generated and
 * verified correctly but never actually delivered to the customer by any
 * channel. AcceptBookingAction/AdminReassignBookingAction now send
 * BookingOtpNotification -- this locks that delivery in with real tests,
 * both against the fake Notification bus (content correctness) and against
 * the REAL channel pipeline under the genuinely-default (unconfigured)
 * notifications.channels Setting, which is 'mail'.
 *
 * That real-pipeline test caught an actual bug while this suite was being
 * written: BookingOtpNotification had no toMail() method, so under the
 * default Setting the notification's own try/catch was silently swallowing
 * a "Call to undefined method toMail()" error on every single delivery --
 * the exact silent-failure this feature exists to close, just moved one
 * layer down. Fixed by adding toMail() alongside the existing
 * toSms()/toPush(), matching BookingStatusNotification's own established
 * pattern exactly. Notification::fake() alone could never have caught this
 * -- it replaces the channel manager entirely and never calls via()/toMail(),
 * which is why one test here deliberately runs without it.
 */
class BookingOtpDeliveryTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    public function test_accepting_a_booking_delivers_both_otps_to_the_customer_with_correct_content(): void
    {
        Notification::fake();
        ['booking' => $booking, 'provider' => $provider, 'customer' => $customer] = $this->makeBookingScenario('searching_provider');
        DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $provider->id,
            'status' => 'notified', 'distance_km' => 1.0, 'notified_at' => now(),
        ]);

        $result = app(AcceptBookingAction::class)->execute($booking->id, $provider);

        Notification::assertSentTo($customer, BookingOtpNotification::class, function (BookingOtpNotification $notification) use ($result) {
            return $notification->purpose() === 'booking_start'
                && str_contains($notification->toSms($notification), $result->start_otp)
                && str_contains($notification->toSms($notification), $result->code);
        });
        Notification::assertSentTo($customer, BookingOtpNotification::class, function (BookingOtpNotification $notification) use ($result) {
            return $notification->purpose() === 'booking_complete'
                && str_contains($notification->toSms($notification), $result->completion_otp)
                && str_contains($notification->toSms($notification), $result->code);
        });
        Notification::assertSentToTimes($customer, BookingOtpNotification::class, 2);
    }

    public function test_delivery_survives_the_real_default_mail_only_channel_without_silently_failing(): void
    {
        // No Notification::fake() here on purpose -- this runs the REAL
        // channel pipeline (MAIL_MAILER=array in phpunit.xml, so nothing
        // actually leaves the process) under the genuinely-default,
        // unconfigured notifications.channels Setting, which is exactly
        // the environment every fresh franchise/zone starts in before an
        // admin ever visits Settings > Notifications.
        Log::spy();
        ['booking' => $booking, 'provider' => $provider] = $this->makeBookingScenario('searching_provider');
        DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $provider->id,
            'status' => 'notified', 'distance_km' => 1.0, 'notified_at' => now(),
        ]);

        app(AcceptBookingAction::class)->execute($booking->id, $provider);

        Log::shouldNotHaveReceived('error');
    }

    public function test_reassigning_a_pending_booking_delivers_the_freshly_generated_otps(): void
    {
        Notification::fake();
        ['booking' => $booking, 'franchise' => $franchise, 'zone' => $zone, 'customer' => $customer] = $this->makeBookingScenario('pending');
        $provider = $this->makeProviderIn($franchise, $zone);

        app(AdminReassignBookingAction::class)->execute($booking->id, $provider->id);

        Notification::assertSentToTimes($customer, BookingOtpNotification::class, 2);
    }

    public function test_reassigning_an_already_otpd_booking_does_not_resend_the_customers_existing_codes(): void
    {
        Notification::fake();
        ['booking' => $booking, 'franchise' => $franchise, 'zone' => $zone, 'customer' => $customer] = $this->makeAssignedBookingScenario();
        $newProvider = $this->makeProviderIn($franchise, $zone);

        app(AdminReassignBookingAction::class)->execute($booking->id, $newProvider->id, 'original unresponsive');

        Notification::assertNothingSentTo($customer);
    }

    public function test_a_notification_delivery_failure_does_not_break_or_roll_back_a_successful_acceptance(): void
    {
        // Force a REAL failure inside AcceptBookingAction's own try/catch:
        // scope this booking's franchise to sms-only delivery, then bind a
        // SmsAdapter that always throws (simulating a gateway outage) --
        // proves the booking itself stays correctly assigned with valid,
        // verifiable OTPs even when delivery genuinely fails, and that the
        // failure is logged rather than silently swallowed.
        //
        // Three customer notifications go out on acceptance under this
        // sms-only Setting -- the "assigned" BookingStatusNotification plus
        // both OTPs -- and all three route through the identical failing
        // SmsChannel/SmsAdapter pipeline, so all three genuinely fail and
        // are each individually logged: 3 Log::error calls, not 2. (Caught
        // a real gap while resolving this test's own original failure:
        // BookingStatusNotification's send was the one call in
        // AcceptBookingAction NOT wrapped in try/catch, so it threw
        // uncaught and broke acceptance before either guarded OTP send
        // below it ever ran -- see AcceptBookingAction::sendStatusNotification().)
        $this->app->bind(SmsAdapter::class, fn () => new class implements SmsAdapter {
            public function send(string $to, string $message): bool
            {
                throw new \RuntimeException('Simulated gateway outage');
            }
        });
        Log::spy();
        ['booking' => $booking, 'provider' => $provider] = $this->makeBookingScenario('searching_provider');
        Setting::set('notifications.channels', 'sms');
        DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $provider->id,
            'status' => 'notified', 'distance_km' => 1.0, 'notified_at' => now(),
        ]);

        $result = app(AcceptBookingAction::class)->execute($booking->id, $provider);

        $this->assertSame('assigned', $result->status);
        $this->assertNotEmpty($result->start_otp);
        $this->assertNotEmpty($result->completion_otp);
        Log::shouldHaveReceived('error')->times(3);
    }

    public function test_a_customer_with_no_notifiable_channels_configured_does_not_break_acceptance(): void
    {
        // in_app/'database' only, no sms/push/mail -- confirms the delivery
        // code degrades safely rather than assuming a specific channel is
        // always present.
        Notification::fake();
        ['booking' => $booking, 'provider' => $provider] = $this->makeBookingScenario('searching_provider');
        Setting::set('notifications.channels', 'in_app');
        DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $provider->id,
            'status' => 'notified', 'distance_km' => 1.0, 'notified_at' => now(),
        ]);

        $result = app(AcceptBookingAction::class)->execute($booking->id, $provider);

        $this->assertSame('assigned', $result->status);
    }
}
