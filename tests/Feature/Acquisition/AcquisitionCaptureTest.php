<?php

namespace Tests\Feature\Acquisition;

use App\Actions\CreateBookingAction;
use App\Http\Middleware\CaptureAcquisition;
use App\Livewire\Bookings\Show as BookingsShow;
use App\Models\Booking;
use App\Support\Acquisition\AcquisitionSanitizer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * F1 — first-touch UTM capture (web session/cookie + API body), one write path, display only.
 */
class AcquisitionCaptureTest extends TestCase
{
    use \Tests\Feature\Support\WithLegacyWalletPayments;
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    private function bookingData(array $world, array $extra = []): array
    {
        return $extra + [
            'franchise_id' => $world['franchise']->id,
            'zone_id' => $world['zone']->id,
            'customer_id' => $world['customer']->id,
            'service_id' => $world['serviceA']->id,
            'address_id' => $world['address']->id,
            'payment_method' => 'cash',
        ];
    }

    private function world(): array
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $franchise->update(['code' => 'ACQ001']);
        $category = $this->makeCategory();
        $serviceA = $this->makeService($category, ['name' => 'Deep Clean', 'base_price' => 1000]);
        $serviceB = $this->makeService($category, ['name' => 'Pest Control', 'base_price' => 500]);
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        return compact('franchise', 'zone', 'serviceA', 'serviceB', 'customer', 'address');
    }

    // ============================== web ==============================

    public function test_first_visit_with_utm_is_captured_in_session_and_cookie(): void
    {
        $this->get('/?utm_source=google&utm_medium=cpc&utm_campaign=ac-repair&gclid=abc123&junk=1')
            ->assertCookie(CaptureAcquisition::COOKIE);

        $acq = session(CaptureAcquisition::SESSION_KEY);
        $this->assertSame('google', $acq['utm_source']);
        $this->assertSame('cpc', $acq['utm_medium']);
        $this->assertSame('abc123', $acq['gclid']);
        $this->assertSame('/', $acq['landing_path']);
        $this->assertArrayHasKey('captured_at', $acq);
        $this->assertArrayNotHasKey('junk', $acq);
    }

    public function test_later_visit_does_not_overwrite_first_touch(): void
    {
        $this->get('/?utm_source=google&utm_campaign=first');
        $this->get('/?utm_source=facebook&utm_campaign=second');

        $this->assertSame('google', session(CaptureAcquisition::SESSION_KEY)['utm_source']);
        $this->assertSame('first', session(CaptureAcquisition::SESSION_KEY)['utm_campaign']);
    }

    public function test_cookie_restores_first_touch_in_a_new_session(): void
    {
        $cookie = json_encode(['utm_source' => 'google', 'utm_campaign' => 'old']);

        $this->withCookie(CaptureAcquisition::COOKIE, $cookie)->get('/?utm_source=other');

        $this->assertSame('old', session(CaptureAcquisition::SESSION_KEY)['utm_campaign']);
    }

    public function test_visit_without_tracking_params_stores_nothing(): void
    {
        $this->get('/?page=2');

        $this->assertNull(session(CaptureAcquisition::SESSION_KEY));
    }

    public function test_web_booking_copies_session_attribution(): void
    {
        $world = $this->world();
        $this->get('/?utm_source=google&utm_medium=cpc&utm_campaign=ac-repair');

        $booking = app(CreateBookingAction::class)->execute($this->bookingData($world));

        $this->assertSame('google', $booking->fresh()->acquisition['utm_source']);
        $this->assertSame('ac-repair', $booking->fresh()->acquisition['utm_campaign']);
    }

    public function test_booking_without_attribution_has_null_acquisition_and_same_price(): void
    {
        $world = $this->world();

        $booking = app(CreateBookingAction::class)->execute($this->bookingData($world));

        $this->assertNull($booking->fresh()->acquisition);
        $this->assertEquals(1000, $booking->fresh()->price_quoted);
    }

    // ============================== API ==============================

    public function test_api_booking_stores_sanitised_acquisition(): void
    {
        $world = $this->world();

        $this->actingAs($world['customer'], 'sanctum')->postJson('/api/bookings', [
            'service_id' => $world['serviceA']->id,
            'address_id' => $world['address']->id,
            'payment_method' => 'cash',
            'acquisition' => [
                'utm_source' => "  play\x00store ",
                'utm_campaign' => 'launch',
                'utm_medium' => 'install_referrer',
            ],
        ])->assertStatus(201);

        $acq = Booking::first()->acquisition;
        $this->assertSame('playstore', $acq['utm_source']);
        $this->assertSame('launch', $acq['utm_campaign']);
    }

    public function test_api_ignores_unknown_keys_and_overlong_values(): void
    {
        $world = $this->world();

        $this->actingAs($world['customer'], 'sanctum')->postJson('/api/bookings', [
            'service_id' => $world['serviceA']->id,
            'address_id' => $world['address']->id,
            'payment_method' => 'cash',
            'acquisition' => [
                'utm_source' => 'meta',
                'utm_campaign' => str_repeat('x', 256),
                'price_override' => '1',
                'is_admin' => 'true',
            ],
        ])->assertStatus(201);

        $acq = Booking::first()->acquisition;
        $this->assertSame(['utm_source' => 'meta'], $acq);
        $this->assertEquals(1000, Booking::first()->price_quoted);
    }

    public function test_api_bundle_children_inherit_acquisition(): void
    {
        $world = $this->world();

        $this->actingAs($world['customer'], 'sanctum')->postJson('/api/booking-bundles', [
            'payment_method' => 'cash',
            'services' => [
                ['service_id' => $world['serviceA']->id, 'address_id' => $world['address']->id],
                ['service_id' => $world['serviceB']->id, 'address_id' => $world['address']->id],
            ],
            'acquisition' => ['utm_source' => 'google', 'gclid' => 'g1'],
        ])->assertStatus(201);

        $this->assertSame(2, Booking::count());
        foreach (Booking::all() as $b) {
            $this->assertSame('google', $b->acquisition['utm_source']);
        }
    }

    public function test_sanitizer_returns_null_without_a_tracking_key(): void
    {
        $this->assertNull(AcquisitionSanitizer::clean(['landing_path' => '/x']));
        $this->assertNull(AcquisitionSanitizer::clean('nope'));
    }

    // ============================== admin ==============================

    private function makeAdmin(): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Super Admin',
            'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    public function test_admin_booking_detail_shows_the_source(): void
    {
        $scenario = $this->makeBookingScenario();
        $scenario['booking']->update(['acquisition' => [
            'utm_source' => 'google', 'utm_campaign' => 'ac-repair', 'landing_path' => '/categories/ac',
            'captured_at' => '2026-10-04T10:00:00+00:00',
        ]]);

        Livewire::actingAs($this->makeAdmin())
            ->test(BookingsShow::class, ['bookingId' => $scenario['booking']->id])
            ->assertSee('Acquisition')
            ->assertSee('google')
            ->assertSee('ac-repair')
            ->assertSee('/categories/ac');
    }
}
