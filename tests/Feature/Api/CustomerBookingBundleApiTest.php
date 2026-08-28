<?php

namespace Tests\Feature\Api;

use App\Jobs\ServiceMatchingJob;
use App\Models\Booking;
use App\Models\BookingBundle;
use App\Models\Payment;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase E2 (Multi-Service Booking — Creation). POST /api/booking-bundles:
 * one BookingBundle + one child Booking per service, priced by the existing
 * authoritative engine, paid by ONE aggregate wallet debit, dispatched by
 * the existing per-booking ServiceMatchingJob, atomic end to end, protected
 * by a customer-scoped idempotency key and by ownership.
 *
 * Pure-pricing scenarios (client price manipulation, flash sale, membership,
 * franchise override) live in Tests\Feature\Pricing\BundlePricingAuthorityTest.
 */
class CustomerBookingBundleApiTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    private static int $franchiseCodeSeq = 0;

    /**
     * Franchise/zone (with a real code so the bundle-code observer runs),
     * one category, two priced services, a customer and one address.
     */
    private function world(float $priceA = 1000, float $priceB = 500): array
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $franchise->update(['code' => 'BDL'.str_pad((string) self::$franchiseCodeSeq++, 3, '0', STR_PAD_LEFT)]);

        $category = $this->makeCategory();
        $serviceA = $this->makeService($category, ['name' => 'Deep Clean', 'base_price' => $priceA]);
        $serviceB = $this->makeService($category, ['name' => 'Pest Control', 'base_price' => $priceB]);

        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        return compact('country', 'city', 'franchise', 'zone', 'category', 'serviceA', 'serviceB', 'customer', 'address');
    }

    private function payload(array $world, array $overrides = []): array
    {
        return array_merge([
            'payment_method' => 'cash',
            'services' => [
                ['service_id' => $world['serviceA']->id, 'address_id' => $world['address']->id],
                ['service_id' => $world['serviceB']->id, 'address_id' => $world['address']->id],
            ],
        ], $overrides);
    }

    // ============================== auth / validation ==============================

    public function test_bundle_creation_requires_authentication(): void
    {
        $world = $this->world();

        $this->postJson('/api/booking-bundles', $this->payload($world))->assertStatus(401);
        $this->assertSame(0, BookingBundle::count());
    }

    public function test_a_bundle_needs_at_least_two_services(): void
    {
        $world = $this->world();

        $this->actingAs($world['customer'], 'sanctum')
            ->postJson('/api/booking-bundles', [
                'services' => [['service_id' => $world['serviceA']->id, 'address_id' => $world['address']->id]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['services']);

        $this->assertSame(0, BookingBundle::count());
        $this->assertSame(0, Booking::count());
    }

    public function test_the_bundle_request_does_not_accept_any_price_field(): void
    {
        $rules = (new \App\Http\Requests\Customer\StoreBookingBundleRequest)->rules();

        foreach (['price', 'price_quoted', 'amount', 'total', 'total_price_quoted', 'discount'] as $field) {
            $this->assertArrayNotHasKey($field, $rules);
            $this->assertArrayNotHasKey('services.*.'.$field, $rules);
        }
        foreach (['customer_id', 'franchise_id', 'zone_id'] as $field) {
            $this->assertArrayNotHasKey($field, $rules);
        }
    }

    // ============================== A. happy path ==============================

    public function test_happy_path_creates_one_bundle_two_children_one_aggregate_debit_and_two_dispatch_jobs(): void
    {
        Queue::fake();
        $world = $this->world(1000, 500);
        Wallet::create(['user_id' => $world['customer']->id, 'balance' => 5000]);

        $response = $this->actingAs($world['customer'], 'sanctum')
            ->postJson('/api/booking-bundles', $this->payload($world, ['payment_method' => 'wallet']))
            ->assertStatus(201)
            ->assertJson(['success' => true]);

        $this->assertSame(1, BookingBundle::count());
        $bundle = BookingBundle::firstOrFail();

        $children = Booking::where('booking_bundle_id', $bundle->id)->orderBy('id')->get();
        $this->assertCount(2, $children);
        $this->assertEqualsCanonicalizing(
            [$world['serviceA']->id, $world['serviceB']->id],
            $children->pluck('service_id')->all()
        );
        $this->assertTrue($children->every(fn ($c) => $c->booking_bundle_id === $bundle->id));

        // total = SUM(server-computed child price_quoted)
        $this->assertEqualsWithDelta(1000.0, (float) $children[0]->price_quoted, 0.001);
        $this->assertEqualsWithDelta(500.0, (float) $children[1]->price_quoted, 0.001);
        $this->assertEqualsWithDelta(1500.0, (float) $bundle->total_price_quoted, 0.001);
        $response->assertJsonPath('data.total_price_quoted', fn ($v) => (float) $v === 1500.0);
        $response->assertJsonPath('data.id', $bundle->id);
        $this->assertCount(2, $response->json('data.children'));

        // exactly ONE aggregate wallet debit, not one per child
        $debits = WalletTransaction::where('is_credit', false)->get();
        $this->assertCount(1, $debits);
        $this->assertEqualsWithDelta(1500.0, (float) $debits->first()->amount, 0.001);
        $this->assertEqualsWithDelta(3500.0, (float) $world['customer']->wallet->fresh()->balance, 0.001);

        $bundlePayments = Payment::where('purpose', 'booking_bundle')->get();
        $this->assertCount(1, $bundlePayments);
        $this->assertEqualsWithDelta(1500.0, (float) $bundlePayments->first()->amount, 0.001);
        $this->assertSame($bundle->id, (int) $bundlePayments->first()->booking_bundle_id);
        $this->assertSame('paid', $bundle->fresh()->payment_status);

        // one ServiceMatchingJob per child, queued (after commit)
        Queue::assertPushed(ServiceMatchingJob::class, 2);
    }

    public function test_cash_bundle_creates_no_wallet_movement(): void
    {
        $world = $this->world(1000, 500);

        $this->actingAs($world['customer'], 'sanctum')
            ->postJson('/api/booking-bundles', $this->payload($world, ['payment_method' => 'cash']))
            ->assertStatus(201);

        $bundle = BookingBundle::firstOrFail();
        $this->assertEqualsWithDelta(1500.0, (float) $bundle->total_price_quoted, 0.001);
        $this->assertSame('pending', $bundle->payment_status);
        $this->assertSame(0, WalletTransaction::count());
        $this->assertSame(0, Payment::where('purpose', 'booking_bundle')->count());
    }

    // ============================== B. insufficient wallet ==============================

    public function test_insufficient_wallet_rolls_the_entire_bundle_back(): void
    {
        Queue::fake();
        $world = $this->world(1000, 500);              // bundle total = 1500
        Wallet::create(['user_id' => $world['customer']->id, 'balance' => 1200]);

        $this->actingAs($world['customer'], 'sanctum')
            ->postJson('/api/booking-bundles', $this->payload($world, ['payment_method' => 'wallet']))
            ->assertStatus(409);

        $this->assertSame(0, BookingBundle::count());
        $this->assertSame(0, Booking::count());
        $this->assertSame(0, WalletTransaction::count());
        $this->assertSame(0, Payment::count());
        $this->assertEqualsWithDelta(1200.0, (float) $world['customer']->wallet->fresh()->balance, 0.001);
        Queue::assertNotPushed(ServiceMatchingJob::class);
    }

    // ============================== I. unauthorized customer context ==============================

    public function test_a_client_supplied_customer_id_is_ignored(): void
    {
        $world = $this->world(1000, 500);
        $otherCustomer = $this->makeCustomer();

        $this->actingAs($world['customer'], 'sanctum')
            ->postJson('/api/booking-bundles', $this->payload($world, [
                'customer_id' => $otherCustomer->id,
                'franchise_id' => 999999,
                'zone_id' => 999999,
            ]))
            ->assertStatus(201);

        $bundle = BookingBundle::firstOrFail();
        $this->assertSame($world['customer']->id, $bundle->customer_id);
        $this->assertSame($world['franchise']->id, $bundle->franchise_id);
        $this->assertSame($world['zone']->id, $bundle->zone_id);
        $this->assertTrue(Booking::where('booking_bundle_id', $bundle->id)->get()->every(
            fn ($c) => $c->customer_id === $world['customer']->id
        ));
        $this->assertSame(0, Booking::where('customer_id', $otherCustomer->id)->count());
    }

    // ============================== H. unauthorized address ==============================

    public function test_a_customer_cannot_bundle_using_another_customers_address(): void
    {
        $world = $this->world(1000, 500);
        $otherCustomer = $this->makeCustomer();
        $othersAddress = $this->makeAddress($otherCustomer, $world['franchise'], $world['zone']);

        $this->actingAs($world['customer'], 'sanctum')
            ->postJson('/api/booking-bundles', [
                'payment_method' => 'cash',
                'services' => [
                    ['service_id' => $world['serviceA']->id, 'address_id' => $world['address']->id],
                    ['service_id' => $world['serviceB']->id, 'address_id' => $othersAddress->id],
                ],
            ])
            ->assertStatus(404);

        $this->assertSame(0, BookingBundle::count());
        $this->assertSame(0, Booking::count());
    }

    public function test_an_inactive_service_in_the_bundle_is_rejected(): void
    {
        $world = $this->world(1000, 500);
        $world['serviceB']->update(['is_active' => false]);

        $this->actingAs($world['customer'], 'sanctum')
            ->postJson('/api/booking-bundles', $this->payload($world))
            ->assertStatus(404);

        $this->assertSame(0, BookingBundle::count());
        $this->assertSame(0, Booking::count());
    }

    // ============================== J. bundle IDOR ==============================

    public function test_a_customer_cannot_view_another_customers_bundle(): void
    {
        $world = $this->world(1000, 500);
        $this->actingAs($world['customer'], 'sanctum')
            ->postJson('/api/booking-bundles', $this->payload($world))
            ->assertStatus(201);
        $bundle = BookingBundle::firstOrFail();

        $this->actingAs($world['customer'], 'sanctum')
            ->getJson("/api/booking-bundles/{$bundle->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $bundle->id);

        $otherCustomer = $this->makeCustomer();
        $this->actingAs($otherCustomer, 'sanctum')
            ->getJson("/api/booking-bundles/{$bundle->id}")
            ->assertStatus(404);
    }

    // ============================== K. idempotent retry ==============================

    public function test_an_exact_retry_with_the_same_idempotency_key_returns_the_original_bundle(): void
    {
        $world = $this->world(1000, 500);
        Wallet::create(['user_id' => $world['customer']->id, 'balance' => 1500]); // enough for ONE bundle only
        $key = (string) Str::uuid();
        $body = $this->payload($world, ['payment_method' => 'wallet']);

        $first = $this->actingAs($world['customer'], 'sanctum')
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/booking-bundles', $body)
            ->assertStatus(201);

        $second = $this->actingAs($world['customer'], 'sanctum')
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/booking-bundles', $body)
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, BookingBundle::count());
        $this->assertSame(2, Booking::count());
        $this->assertSame(1, WalletTransaction::where('is_credit', false)->count());
        $this->assertSame(1, Payment::where('purpose', 'booking_bundle')->count());
        // second call did NOT double-debit — balance is exactly 0, not negative / not rejected
        $this->assertEqualsWithDelta(0.0, (float) $world['customer']->wallet->fresh()->balance, 0.001);
    }

    public function test_the_idempotency_key_can_also_be_supplied_in_the_request_body(): void
    {
        $world = $this->world(1000, 500);
        $key = (string) Str::uuid();
        $body = $this->payload($world, ['idempotency_key' => $key]);

        $first = $this->actingAs($world['customer'], 'sanctum')
            ->postJson('/api/booking-bundles', $body)->assertStatus(201);
        $second = $this->actingAs($world['customer'], 'sanctum')
            ->postJson('/api/booking-bundles', $body)->assertStatus(200);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, BookingBundle::count());
        $this->assertSame(2, Booking::count());
    }

    public function test_the_same_idempotency_key_from_another_customer_cannot_reuse_the_first_customers_bundle(): void
    {
        $world = $this->world(1000, 500);
        $key = 'shared-key-123';

        $this->actingAs($world['customer'], 'sanctum')
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/booking-bundles', $this->payload($world))
            ->assertStatus(201);
        $firstBundleId = BookingBundle::firstOrFail()->id;

        // A second customer with their own address + the SAME key gets their
        // OWN new bundle, never the first customer's.
        $customerB = $this->makeCustomer();
        $addressB = $this->makeAddress($customerB, $world['franchise'], $world['zone']);

        $response = $this->actingAs($customerB, 'sanctum')
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/booking-bundles', [
                'payment_method' => 'cash',
                'services' => [
                    ['service_id' => $world['serviceA']->id, 'address_id' => $addressB->id],
                    ['service_id' => $world['serviceB']->id, 'address_id' => $addressB->id],
                ],
            ])
            ->assertStatus(201);

        $this->assertNotSame($firstBundleId, $response->json('data.id'));
        $this->assertSame(2, BookingBundle::count());
        $this->assertSame($customerB->id, BookingBundle::findOrFail($response->json('data.id'))->customer_id);
    }

    // ============================== L. idempotency key mismatch ==============================

    public function test_reusing_a_key_with_a_materially_different_body_is_rejected(): void
    {
        $world = $this->world(1000, 500);
        $serviceC = $this->makeService($world['category'], ['name' => 'Extra', 'base_price' => 250]);
        $key = (string) Str::uuid();

        $this->actingAs($world['customer'], 'sanctum')
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/booking-bundles', $this->payload($world))
            ->assertStatus(201);
        $original = BookingBundle::firstOrFail();
        $originalChildServiceIds = Booking::where('booking_bundle_id', $original->id)->pluck('service_id')->sort()->values()->all();

        // same key, different service set
        $this->actingAs($world['customer'], 'sanctum')
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/booking-bundles', [
                'payment_method' => 'cash',
                'services' => [
                    ['service_id' => $world['serviceA']->id, 'address_id' => $world['address']->id],
                    ['service_id' => $serviceC->id, 'address_id' => $world['address']->id],
                ],
            ])
            ->assertStatus(409);

        $this->assertSame(1, BookingBundle::count());
        $this->assertSame(2, Booking::count());
        $this->assertSame(
            $originalChildServiceIds,
            Booking::where('booking_bundle_id', $original->id)->pluck('service_id')->sort()->values()->all(),
            'The original bundle must be untouched.'
        );
    }

    // ============================== M. single-service regression ==============================

    public function test_existing_single_service_booking_is_unchanged_and_has_no_bundle_id(): void
    {
        Queue::fake();
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $franchise->update(['code' => 'REG'.str_pad((string) self::$franchiseCodeSeq++, 3, '0', STR_PAD_LEFT)]);
        [, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        Wallet::create(['user_id' => $customer->id, 'balance' => 1000]);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/bookings', [
                'service_id' => $service->id,
                'address_id' => $address->id,
                'payment_method' => 'wallet',
            ])
            ->assertStatus(201);

        $booking = Booking::firstOrFail();
        $this->assertNull($booking->booking_bundle_id);
        $this->assertEqualsWithDelta(500.0, (float) $booking->price_quoted, 0.001);
        $this->assertSame('paid', $booking->payment_status);
        $this->assertEqualsWithDelta(500.0, (float) $customer->wallet->fresh()->balance, 0.001);
        $this->assertSame(0, BookingBundle::count());
        Queue::assertPushed(ServiceMatchingJob::class, 1);
    }
}
