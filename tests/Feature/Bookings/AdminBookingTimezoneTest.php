<?php

namespace Tests\Feature\Bookings;

use App\Livewire\Bookings\Index;
use App\Models\Booking;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Zone;
use App\Services\TimezoneResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * The admin call-centre booking form wrote its `datetime-local` value into
 * `bookings.scheduled_at` verbatim. `config('app.timezone')` is UTC and the
 * column is `datetime`-cast, so a naive wall clock the operator typed was
 * stored as if it were already UTC — putting every admin-created scheduled
 * booking 5h30m late on an Asia/Kolkata platform, and skewing the
 * "after:now / within max_schedule_days_ahead" validation window by the
 * same offset.
 *
 * The customer wizard never had this bug: it routes through
 * App\Support\BookingSchedule (parse/validate -> TimezoneResolver). These
 * tests pin the admin form onto that same shared helper, so the two paths
 * cannot drift apart again.
 */
class AdminBookingTimezoneTest extends TestCase
{
    use RbacTestHelpers;
    use RefreshDatabase;

    private function makeZone(): Zone
    {
        return Zone::create([
            'franchise_id' => $this->makeFranchise()->id,
            'name' => 'TZ Zone',
            'boundary_polygon' => [['lat' => 1, 'lng' => 1], ['lat' => 2, 'lng' => 2], ['lat' => 3, 'lng' => 3]],
            'is_active' => true,
        ]);
    }

    private function makeService(): Service
    {
        $category = ServiceCategory::create([
            'module' => 'service', 'name' => 'TZ Cat', 'slug' => 'tz-cat',
            'image' => 'categories/x.png', 'sort_order' => 1, 'is_active' => true,
        ]);

        return Service::create([
            'category_id' => $category->id, 'name' => 'TZ Svc', 'slug' => 'tz-svc',
            'base_price' => 200, 'price_type' => 'fixed', 'duration_estimate_mins' => 30,
            'is_active' => true, 'location_required' => true, 'age_restriction' => false, 'sort_order' => 1,
        ]);
    }

    /**
     * Drives the real form to the point where createBooking() can succeed,
     * mirroring BookingCreationAuthorizationTest's own happy path.
     */
    private function formFor(Zone $zone, Service $service, string $phone)
    {
        $actor = $this->makeUserWithPermission('bookings.create', 'zone', $zone->id);
        $this->grantPermission($actor, 'bookings.view');

        return Livewire::actingAs($actor)->test(Index::class)
            ->set('newCustomerName', 'TZ Customer')
            ->set('newCustomerPhone', $phone)
            ->call('createCustomer')
            ->assertHasNoErrors()
            ->set('selectedZoneId', $zone->id)
            ->set('newAddressLine', 'TZ Address')
            ->set('newAddressLat', 1.0)
            ->set('newAddressLng', 1.0)
            ->call('addNewAddress')
            ->assertHasNoErrors()
            ->set('selectedServiceId', $service->id)
            ->set('priceQuoted', '200');
    }

    public function test_admin_scheduled_booking_is_stored_as_the_correct_utc_instant(): void
    {
        $zone = $this->makeZone();
        $service = $this->makeService();

        // The operator types a naive wall clock in the platform timezone —
        // exactly what an <input type="datetime-local"> submits.
        $tz = app(TimezoneResolver::class)->platformTimezone();
        $this->assertSame('Asia/Kolkata', $tz, 'Fixture country should make Asia/Kolkata the platform timezone.');

        $localWallClock = Carbon::now($tz)->addDays(2)->setTime(15, 0)->format('Y-m-d\TH:i');

        $this->formFor($zone, $service, '9111111111')
            ->set('bookingType', 'scheduled')
            ->set('scheduledAt', $localWallClock)
            ->call('createBooking')
            ->assertHasNoErrors();

        $booking = Booking::latest('id')->firstOrFail();

        $expectedUtc = Carbon::createFromFormat('Y-m-d\TH:i', $localWallClock, $tz)->utc();

        $this->assertSame(
            $expectedUtc->format('Y-m-d H:i'),
            $booking->scheduled_at->utc()->format('Y-m-d H:i'),
            '3pm IST must persist as 09:30 UTC, not 15:00 UTC.'
        );

        // The specific regression: the naive string must NOT have been taken
        // as a UTC wall clock. On Asia/Kolkata those differ by 5h30m, so this
        // fails loudly if the raw-string write ever comes back.
        $this->assertNotSame(
            str_replace('T', ' ', $localWallClock),
            $booking->scheduled_at->utc()->format('Y-m-d H:i'),
            'scheduled_at was stored as the literal typed wall clock — the timezone conversion was skipped.'
        );
    }

    public function test_asap_booking_still_stores_null_scheduled_at(): void
    {
        $zone = $this->makeZone();
        $service = $this->makeService();

        $this->formFor($zone, $service, '9222222222')
            ->set('bookingType', 'asap')
            ->call('createBooking')
            ->assertHasNoErrors();

        $this->assertNull(Booking::latest('id')->firstOrFail()->scheduled_at);
    }

    public function test_a_past_wall_clock_is_rejected(): void
    {
        $zone = $this->makeZone();
        $service = $this->makeService();

        $tz = app(TimezoneResolver::class)->platformTimezone();
        $past = Carbon::now($tz)->subDay()->format('Y-m-d\TH:i');

        $this->formFor($zone, $service, '9333333333')
            ->set('bookingType', 'scheduled')
            ->set('scheduledAt', $past)
            ->call('createBooking')
            ->assertHasErrors(['scheduledAt']);
    }

    public function test_beyond_the_max_schedule_window_is_rejected(): void
    {
        $zone = $this->makeZone();
        $service = $this->makeService();

        $tz = app(TimezoneResolver::class)->platformTimezone();
        // Default booking.max_schedule_days_ahead is 14.
        $tooFar = Carbon::now($tz)->addDays(30)->format('Y-m-d\TH:i');

        $this->formFor($zone, $service, '9444444444')
            ->set('bookingType', 'scheduled')
            ->set('scheduledAt', $tooFar)
            ->call('createBooking')
            ->assertHasErrors(['scheduledAt']);
    }

    /**
     * The window check must be judged against the operator's clock, not a
     * UTC reading of the same digits. A wall clock 2 hours from now is
     * comfortably valid in IST; under the old UTC misparse the same string
     * read as 3h30m in the PAST and was rejected. This is the half of the
     * bug that silently blocked legitimate same-day call-centre bookings.
     */
    public function test_a_near_future_wall_clock_within_the_utc_offset_is_accepted(): void
    {
        $zone = $this->makeZone();
        $service = $this->makeService();

        $tz = app(TimezoneResolver::class)->platformTimezone();
        $soon = Carbon::now($tz)->addHours(2)->format('Y-m-d\TH:i');

        $this->formFor($zone, $service, '9555555555')
            ->set('bookingType', 'scheduled')
            ->set('scheduledAt', $soon)
            ->call('createBooking')
            ->assertHasNoErrors();

        $this->assertNotNull(Booking::latest('id')->firstOrFail()->scheduled_at);
    }
}
