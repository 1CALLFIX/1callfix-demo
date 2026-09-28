<?php

namespace Tests\Feature\Dispatch;

use App\Actions\AcceptBookingAction;
use App\Actions\CreateBookingAction;
use App\Models\Booking;
use App\Models\DispatchAttempt;
use App\Models\Payment;
use App\Models\Setting;
use App\Notifications\ProviderJobOfferNotification;
use App\Services\DispatchService;
use App\Services\ScheduledDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-SCHEDULING-DISPATCH-001 (Part 2) — "scheduled dispatch = open
 * offer / future commitment". Tests 9-24 of the brief's own test plan.
 */
class ScheduledDispatchTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('notifications.channels', 'mail,push');
    }

    private function scheduledBooking(array $overrides = []): array
    {
        $scenario = $this->makeBookingScenario('pending');
        $scenario['booking']->update(array_merge([
            'scheduled_at' => now()->addDays(2)->setTime(10, 0),
            'payment_method' => 'online',
            'payment_status' => 'pending',
        ], $overrides));
        $scenario['booking'] = $scenario['booking']->fresh();

        return $scenario;
    }

    // -----------------------------------------------------------------
    // 9/10. Payment gate
    // -----------------------------------------------------------------

    public function test_a_paid_scheduled_booking_starts_provider_offers_immediately(): void
    {
        ['booking' => $booking, 'provider' => $provider, 'category' => $category] = $this->scheduledBooking(['payment_status' => 'paid']);
        $this->makeEligible($provider, $category->id);

        app(ScheduledDispatchService::class)->releaseIfEligible($booking);

        $booking->refresh();
        $this->assertSame('searching_provider', $booking->status);
        $this->assertNotNull($booking->scheduled_offers_sent_at);
        $this->assertSame(1, DispatchAttempt::where('booking_id', $booking->id)->where('status', 'notified')->count());
    }

    public function test_an_unpaid_scheduled_booking_sends_no_offers(): void
    {
        ['booking' => $booking, 'provider' => $provider, 'category' => $category] = $this->scheduledBooking(['payment_status' => 'pending']);
        $this->makeEligible($provider, $category->id);

        app(ScheduledDispatchService::class)->releaseIfEligible($booking);

        $booking->refresh();
        $this->assertSame('pending', $booking->status);
        $this->assertNull($booking->scheduled_offers_sent_at);
        $this->assertSame(0, DispatchAttempt::where('booking_id', $booking->id)->count());
    }

    public function test_a_scheduled_booking_cannot_be_created_with_cash_payment(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        [$category, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        $this->expectException(\RuntimeException::class);

        app(CreateBookingAction::class)->execute([
            'franchise_id' => $franchise->id,
            'zone_id' => $zone->id,
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'address_id' => $address->id,
            'scheduled_at' => now()->addDays(1),
            'payment_method' => 'cash',
        ]);
    }

    // -----------------------------------------------------------------
    // 11/12/13. Candidate selection checks availability AT scheduled_at
    // -----------------------------------------------------------------

    public function test_candidate_selection_checks_availability_at_scheduled_at(): void
    {
        ['booking' => $booking, 'provider' => $provider, 'category' => $category] = $this->scheduledBooking(['payment_status' => 'paid']);
        $this->makeEligible($provider, $category->id);

        // Provider has an existing ACCEPTED scheduled job overlapping this
        // booking's own scheduled window.
        $other = $this->scheduledBooking()['booking'];
        $other->update([
            'provider_id' => $provider->id,
            'status' => 'assigned',
            'scheduled_at' => $booking->scheduled_at,
        ]);

        $candidates = app(DispatchService::class)->findScheduledCandidates($booking);

        $this->assertTrue($candidates->isEmpty());
    }

    public function test_a_provider_free_now_but_unavailable_at_scheduled_at_is_excluded(): void
    {
        ['booking' => $booking, 'provider' => $provider, 'category' => $category] = $this->scheduledBooking(['payment_status' => 'paid']);
        $this->makeEligible($provider, $category->id);

        $conflicting = $this->makeBookingScenario('assigned');
        $conflicting['booking']->update([
            'provider_id' => $provider->id,
            'scheduled_at' => $booking->scheduled_at->copy()->addMinutes(15),
        ]);

        $candidates = app(DispatchService::class)->findScheduledCandidates($booking);

        $this->assertTrue($candidates->isEmpty());
    }

    public function test_a_provider_available_at_scheduled_at_receives_offer(): void
    {
        ['booking' => $booking, 'provider' => $provider, 'category' => $category] = $this->scheduledBooking(['payment_status' => 'paid']);
        $this->makeEligible($provider, $category->id);

        $candidates = app(DispatchService::class)->findScheduledCandidates($booking);

        $this->assertCount(1, $candidates);
        $this->assertSame($provider->id, $candidates->first()['provider']->id);
    }

    public function test_scheduled_candidates_do_not_require_the_provider_to_be_online(): void
    {
        ['booking' => $booking, 'provider' => $provider, 'category' => $category] = $this->scheduledBooking(['payment_status' => 'paid']);
        $this->makeEligible($provider, $category->id);
        $provider->update(['is_online' => false]);

        $candidates = app(DispatchService::class)->findScheduledCandidates($booking);

        $this->assertCount(1, $candidates);
    }

    // -----------------------------------------------------------------
    // 14. Offline-provider notification via existing FCM architecture
    // -----------------------------------------------------------------

    public function test_offline_provider_still_receives_the_push_offer_notification(): void
    {
        Notification::fake();

        ['booking' => $booking, 'provider' => $provider, 'category' => $category] = $this->scheduledBooking(['payment_status' => 'paid']);
        $this->makeEligible($provider, $category->id);
        $provider->update(['is_online' => false]);
        $provider->user->update(['fcm_token' => 'token-123']);

        app(ScheduledDispatchService::class)->releaseIfEligible($booking);

        Notification::assertSentTo($provider->user, ProviderJobOfferNotification::class);
    }

    // -----------------------------------------------------------------
    // 15. Scheduled offer does not expire at the ASAP 25s timeout
    // -----------------------------------------------------------------

    public function test_a_scheduled_offer_stays_acceptable_past_the_asap_offer_timeout(): void
    {
        Setting::set('dispatch.offer_timeout_seconds', '25');

        ['booking' => $booking, 'provider' => $provider, 'category' => $category] = $this->scheduledBooking(['payment_status' => 'paid']);
        $this->makeEligible($provider, $category->id);

        app(ScheduledDispatchService::class)->releaseIfEligible($booking);

        // Backdate the offer well past the ASAP window.
        DispatchAttempt::where('booking_id', $booking->id)->update(['notified_at' => now()->subMinutes(10)]);

        $accepted = app(AcceptBookingAction::class)->execute($booking->id, $provider->fresh());

        $this->assertSame('assigned', $accepted->status);
        $this->assertSame($provider->id, $accepted->provider_id);
    }

    // -----------------------------------------------------------------
    // 16/17/18. First acceptance wins; concurrent accepts; others superseded
    // -----------------------------------------------------------------

    public function test_first_provider_acceptance_wins_and_supersedes_other_offers(): void
    {
        ['booking' => $booking, 'provider' => $providerA, 'category' => $category, 'franchise' => $franchise, 'zone' => $zone] = $this->scheduledBooking(['payment_status' => 'paid']);
        $this->makeEligible($providerA, $category->id);
        $providerB = $this->makeProviderIn($franchise, $zone);
        $this->makeEligible($providerB, $category->id, 1.02, 1.02);

        app(ScheduledDispatchService::class)->releaseIfEligible($booking);
        $this->assertSame(2, DispatchAttempt::where('booking_id', $booking->id)->where('status', 'notified')->count());

        $accepted = app(AcceptBookingAction::class)->execute($booking->id, $providerA->fresh());
        $this->assertSame($providerA->id, $accepted->provider_id);

        $this->assertSame(1, DispatchAttempt::where('booking_id', $booking->id)->where('status', 'accepted')->count());
        $this->assertSame(1, DispatchAttempt::where('booking_id', $booking->id)->where('status', 'timeout')->count());

        // Provider B can no longer accept — booking is no longer searching_provider.
        $this->expectException(\RuntimeException::class);
        app(AcceptBookingAction::class)->execute($booking->id, $providerB->fresh());
    }

    public function test_a_declined_provider_is_not_re_offered(): void
    {
        ['booking' => $booking, 'provider' => $provider, 'category' => $category] = $this->scheduledBooking(['payment_status' => 'paid']);
        $this->makeEligible($provider, $category->id);

        app(ScheduledDispatchService::class)->releaseIfEligible($booking);

        DispatchAttempt::where('booking_id', $booking->id)->where('provider_id', $provider->id)
            ->update(['status' => 'rejected', 'responded_at' => now()]);

        $sent = app(ScheduledDispatchService::class)->sendOpenOffers($booking->fresh());

        $this->assertSame(0, $sent);
        $this->assertSame(0, DispatchAttempt::where('booking_id', $booking->id)->where('status', 'notified')->count());
    }

    // -----------------------------------------------------------------
    // 20/21/22. Reminder interval + newly-eligible catch-up, idempotent
    // -----------------------------------------------------------------

    public function test_reoffer_reminder_fires_after_the_configured_interval(): void
    {
        Notification::fake();
        Setting::set('dispatch.scheduled_reoffer_interval_hours', '2');

        ['booking' => $booking, 'provider' => $provider, 'category' => $category] = $this->scheduledBooking(['payment_status' => 'paid']);
        $this->makeEligible($provider, $category->id);
        $provider->user->update(['fcm_token' => 'token-abc']);

        app(ScheduledDispatchService::class)->releaseIfEligible($booking);

        $booking->refresh();
        $this->assertFalse(app(ScheduledDispatchService::class)->sendReminderIfDue($booking));

        $booking->update(['scheduled_last_offer_at' => now()->subHours(3)]);
        $this->assertTrue(app(ScheduledDispatchService::class)->sendReminderIfDue($booking->fresh()));

        Notification::assertSentTimes(ProviderJobOfferNotification::class, 2); // initial + reminder
    }

    public function test_scheduler_catch_up_offers_a_newly_eligible_provider_without_duplicating(): void
    {
        ['booking' => $booking, 'provider' => $providerA, 'category' => $category, 'franchise' => $franchise, 'zone' => $zone] = $this->scheduledBooking(['payment_status' => 'paid']);
        $this->makeEligible($providerA, $category->id);

        app(ScheduledDispatchService::class)->releaseIfEligible($booking);
        $this->assertSame(1, DispatchAttempt::where('booking_id', $booking->id)->count());

        // First catch-up run with the same pool changes nothing new.
        app(ScheduledDispatchService::class)->sendOpenOffers($booking->fresh());
        $this->assertSame(1, DispatchAttempt::where('booking_id', $booking->id)->count());

        // A second provider becomes eligible later.
        $providerB = $this->makeProviderIn($franchise, $zone);
        $this->makeEligible($providerB, $category->id, 1.03, 1.03);

        app(ScheduledDispatchService::class)->sendOpenOffers($booking->fresh());
        $this->assertSame(2, DispatchAttempt::where('booking_id', $booking->id)->count());

        // Idempotent — running again offers nobody new.
        app(ScheduledDispatchService::class)->sendOpenOffers($booking->fresh());
        $this->assertSame(2, DispatchAttempt::where('booking_id', $booking->id)->count());
    }

    // -----------------------------------------------------------------
    // 23/24. CRITICAL AVAILABILITY RULE
    // -----------------------------------------------------------------

    public function test_a_provider_assigned_a_future_scheduled_job_still_receives_a_non_overlapping_instant_job_offer(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        [$category, $service] = $this->makeCategoryAndService();
        $provider = $this->makeProviderIn($franchise, $zone);
        $this->makeEligible($provider, $category->id);

        // Provider is assigned a job scheduled for Wednesday 10:00-11:00.
        $futureJob = $this->makeBookingScenario('assigned');
        $futureJob['booking']->update([
            'provider_id' => $provider->id,
            'scheduled_at' => now()->addDays(2)->setTime(10, 0),
        ]);

        // A TODAY instant (ASAP) booking comes in — must still offer to this provider.
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        $asapBooking = Booking::create([
            'code' => 'TST-ASAP-'.random_int(1, 999999),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $customer->id, 'service_id' => $service->id, 'address_id' => $address->id,
            'status' => 'searching_provider', 'price_quoted' => 500, 'payment_status' => 'pending', 'payment_method' => 'online',
        ]);

        $candidates = app(DispatchService::class)->findCandidates($asapBooking);

        $this->assertCount(1, $candidates);
        $this->assertSame($provider->id, $candidates->first()['provider']->id);
    }

    public function test_an_instant_job_overlapping_the_providers_scheduled_window_is_rejected(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        [$category, $service] = $this->makeCategoryAndService();
        $provider = $this->makeProviderIn($franchise, $zone);
        $this->makeEligible($provider, $category->id);

        // "Now" is inside the provider's already-assigned scheduled window.
        $windowStart = now()->addMinutes(5);
        $futureJob = $this->makeBookingScenario('assigned');
        $futureJob['booking']->update([
            'provider_id' => $provider->id,
            'scheduled_at' => $windowStart,
        ]);

        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        $asapBooking = Booking::create([
            'code' => 'TST-ASAP2-'.random_int(1, 999999),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $customer->id, 'service_id' => $service->id, 'address_id' => $address->id,
            'status' => 'searching_provider', 'price_quoted' => 500, 'payment_status' => 'pending', 'payment_method' => 'online',
        ]);

        $this->travelTo($windowStart->copy()->addMinutes(5));
        $candidates = app(DispatchService::class)->findCandidates($asapBooking);

        $this->assertTrue($candidates->isEmpty());
    }
}
