<?php

namespace Tests\Feature\Settings;

use App\Livewire\Banners\Manage as BannersManage;
use App\Livewire\Customer\Booking\Wizard;
use App\Livewire\Customer\Orders\Show as CustomerOrderShow;
use App\Models\Banner;
use App\Models\Booking;
use App\Models\Country;
use App\Services\TimezoneResolver;
use App\Support\BookingSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Timezone fix pass -- the INPUT half, sibling to TimezoneDisplayTest's
 * display half. app.timezone is UTC by design; the server clock is IST.
 * Before this pass, a `datetime-local` value the admin or customer typed
 * (a naive wall clock, no offset) was written to an Eloquent `datetime`
 * cast as-is -- i.e. stored as that wall clock in UTC, a 5.5h error in the
 * wrong direction. These tests prove every fixed input boundary now
 * interprets the typed value in the resolved timezone (franchise ->
 * country -> default_timezone, or the single-country platform timezone,
 * Asia/Kolkata today) and stores a correct UTC instant.
 *
 * The fixture country is makeFranchiseTree()'s real Asia/Kolkata (UTC+5:30)
 * and every moment is chosen so UTC and IST fall on different clock hours
 * (and, at the midnight case, different calendar days) -- an un-converted
 * write fails these assertions, it does not pass by luck.
 */
class TimezoneInputConversionTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RbacTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /** 2026-01-15 19:00 IST  ==  2026-01-15 13:30:00 UTC. */
    private const IST_WALL_CLOCK = '2026-01-15T19:00';
    private const EXPECTED_UTC = '2026-01-15 13:30:00';

    // ===================== TimezoneResolver primitives =====================

    public function test_to_utc_interprets_a_naive_wall_clock_in_the_platform_timezone_and_converts(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        $resolver = app(TimezoneResolver::class);

        $this->assertSame(self::EXPECTED_UTC, $resolver->toUtc(self::IST_WALL_CLOCK, null)->toDateTimeString());
        $this->assertSame('UTC', $resolver->toUtc(self::IST_WALL_CLOCK, null)->tzName);
        // Same answer whether the zone is resolved from a franchise or the platform default.
        $this->assertSame(self::EXPECTED_UTC, $resolver->toUtc(self::IST_WALL_CLOCK, $franchise)->toDateTimeString());
    }

    public function test_to_utc_returns_null_for_an_empty_value(): void
    {
        $resolver = app(TimezoneResolver::class);

        $this->assertNull($resolver->toUtc(null, null));
        $this->assertNull($resolver->toUtc('', null));
        $this->assertNull($resolver->toUtc('   ', null));
    }

    public function test_to_local_input_is_the_exact_inverse_of_to_utc(): void
    {
        $this->makeFranchiseTree();
        $resolver = app(TimezoneResolver::class);

        $utc = $resolver->toUtc(self::IST_WALL_CLOCK, null);

        $this->assertSame(self::IST_WALL_CLOCK, $resolver->toLocalInput($utc, null));
    }

    public function test_platform_timezone_is_the_sole_country_timezone_when_there_is_one_country(): void
    {
        $this->makeFranchiseTree(); // one country, Asia/Kolkata

        $this->assertSame('Asia/Kolkata', (new TimezoneResolver())->platformTimezone());
    }

    public function test_platform_timezone_falls_back_to_app_timezone_when_the_platform_is_multi_country(): void
    {
        $this->makeFranchiseTree(); // Asia/Kolkata
        Country::create(['name' => 'Elsewhere', 'code' => 'ZZ', 'currency_code' => 'USD', 'default_timezone' => 'America/New_York', 'is_active' => true]);

        // Two genuine, different platform timezones -> no single wall clock; degrade to config.
        $this->assertSame(config('app.timezone'), (new TimezoneResolver())->platformTimezone());
        $this->assertSame('UTC', (new TimezoneResolver())->platformTimezone());
    }

    // ===================== Banner admin form (Step 1) =====================

    public function test_creating_a_banner_converts_the_typed_window_from_ist_to_utc(): void
    {
        $this->makeFranchiseTree(); // the platform's single Asia/Kolkata country
        $admin = $this->makeUserWithPermission('banners.manage', 'global');

        Livewire::actingAs($admin)->test(BannersManage::class)
            ->set('title', 'Timed Promo')
            ->set('placement', 'top')
            ->set('imageFile', UploadedFile::fake()->image('promo.jpg', 1600, 560))
            ->set('startsAt', self::IST_WALL_CLOCK)
            ->set('expiresAt', '2026-01-20T19:00')
            ->call('save')
            ->assertHasNoErrors();

        // Read the raw DB column, not the Eloquent accessor -- prove the stored instant itself is UTC.
        $row = DB::table('banners')->where('title', 'Timed Promo')->first();
        $this->assertSame(self::EXPECTED_UTC, $row->starts_at);
        $this->assertSame('2026-01-20 13:30:00', $row->expires_at);
    }

    public function test_editing_a_banner_converts_on_save_and_repopulates_the_form_in_ist(): void
    {
        $this->makeFranchiseTree();
        $admin = $this->makeUserWithPermission('banners.manage', 'global');
        $banner = Banner::create([
            'title' => 'Editable Window', 'image' => 'x.jpg', 'placement' => 'top',
            'starts_at' => Carbon::parse(self::EXPECTED_UTC, 'UTC'),
            'sort_order' => 1, 'is_active' => true,
        ]);

        $component = Livewire::actingAs($admin)->test(BannersManage::class)->call('edit', $banner->id);

        // Repopulated datetime-local field shows the IST wall clock, not raw UTC.
        $component->assertSet('editStartsAt', self::IST_WALL_CLOCK);

        $component->set('editStartsAt', '2026-01-15T21:30')->call('update')->assertHasNoErrors();

        // 21:30 IST -> 16:00 UTC.
        $this->assertSame('2026-01-15 16:00:00', DB::table('banners')->where('id', $banner->id)->value('starts_at'));
    }

    // ===================== Customer booking input (Step 2) =====================

    public function test_booking_schedule_parse_converts_a_customer_wall_clock_to_utc(): void
    {
        $this->makeFranchiseTree();

        $this->assertSame(self::EXPECTED_UTC, BookingSchedule::parse(self::IST_WALL_CLOCK)->toDateTimeString());
        $this->assertNull(BookingSchedule::parse(''));
        $this->assertNull(BookingSchedule::parse(null));
    }

    public function test_booking_schedule_validate_judges_past_and_window_on_the_customer_clock(): void
    {
        $this->makeFranchiseTree();

        // ~2h ahead on the IST clock is genuinely in the future -- must be accepted
        // (under the old UTC parse this instant read 5.5h earlier and risked "past").
        $soon = now('Asia/Kolkata')->addHours(2)->format('Y-m-d\TH:i');
        $this->assertNull(BookingSchedule::validate($soon));

        $past = now('Asia/Kolkata')->subHours(2)->format('Y-m-d\TH:i');
        $this->assertSame('Pick a time in the future.', BookingSchedule::validate($past));
    }

    public function test_the_wizard_stores_the_scheduled_time_as_a_utc_instant_of_the_customers_wall_clock(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $category = $this->makeCategory(['module' => 'service']);
        $service = $this->makeService($category, ['base_price' => 500]);
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        $this->makeProviderIn($franchise, $zone);

        $istWallClock = now('Asia/Kolkata')->addDays(2)->setTime(10, 0)->format('Y-m-d\TH:i');

        Livewire::actingAs($customer)
            ->test(Wizard::class, ['service' => $service])
            ->set('addressId', $address->id)
            ->set('scheduledAt', $istWallClock)
            ->call('next')->call('next')->call('next')
            ->assertSet('step', 'pay')
            ->set('paymentMethod', 'cash')
            ->call('placeBooking')
            ->assertRedirect();

        $stored = Booking::where('customer_id', $customer->id)->value('scheduled_at');

        $this->assertSame(
            Carbon::parse($istWallClock, 'Asia/Kolkata')->utc()->format('Y-m-d H:i'),
            $stored->format('Y-m-d H:i'),
            'stored as the UTC instant of the chosen IST wall clock',
        );
        $this->assertSame(
            $istWallClock,
            $stored->clone()->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i'),
            'reads back as exactly the wall clock the customer picked',
        );
    }

    // ===================== Customer display (Step 3) =====================

    public function test_customer_order_detail_renders_scheduled_at_in_ist(): void
    {
        $scenario = $this->makeBookingScenario();
        // 2026-01-15 19:00 UTC == 2026-01-16 00:30 IST -- the calendar day changes.
        $scenario['booking']->forceFill([
            'scheduled_at' => Carbon::create(2026, 1, 15, 19, 0, 0, 'UTC'),
            'created_at' => Carbon::create(2026, 1, 15, 19, 0, 0, 'UTC'),
        ])->save();

        Livewire::actingAs($scenario['customer'])
            ->test(CustomerOrderShow::class, ['booking' => $scenario['booking']->fresh()])
            ->assertSee('16 Jan 2026')
            ->assertSee('12:30 AM')
            ->assertDontSee('7:00 PM');
    }

    public function test_customer_display_conversion_never_mutates_the_stored_utc_value(): void
    {
        $scenario = $this->makeBookingScenario();
        $scenario['booking']->forceFill([
            'scheduled_at' => Carbon::create(2026, 1, 15, 19, 0, 0, 'UTC'),
            'created_at' => Carbon::create(2026, 1, 15, 19, 0, 0, 'UTC'),
        ])->save();

        Livewire::actingAs($scenario['customer'])
            ->test(CustomerOrderShow::class, ['booking' => $scenario['booking']->fresh()])
            ->assertSee('16 Jan 2026')   // "booked" line, format j M Y
            ->assertSee('16 Jan, 12:30 AM'); // "When" line, format D j M, g:i A

        $this->assertSame(
            '2026-01-15 19:00:00',
            DB::table('bookings')->where('id', $scenario['booking']->id)->value('scheduled_at'),
        );
    }
}
