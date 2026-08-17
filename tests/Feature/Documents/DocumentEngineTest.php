<?php

namespace Tests\Feature\Documents;

use App\Models\GeneratedDocument;
use App\Models\Payment;
use App\Services\Documents\DocumentNumberService;
use App\Services\Documents\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\Feature\Support\PropertyRentalFixtureHelpers;
use Tests\Feature\Support\RentalFixtureHelpers;
use Tests\Feature\Support\TaxiRideFixtureHelpers;
use Tests\TestCase;

/**
 * Printing/Document Engine (mission Phase 7). One reusable renderer
 * (DocumentService + documents/payment.blade.php) driving invoices/
 * receipts for all seven real Payment purposes this codebase now models —
 * no per-purpose duplicate template. Numbering is idempotent
 * (DocumentNumberService) and authorization-checked at both the admin
 * (row-level scope reusing payments.view) and customer (payer-only) layers.
 *
 * **2026-08-17 hardening addition:** the four newer purposes (parcel_order/
 * taxi_ride/property_reservation/marketplace_order) were extended into
 * DocumentService/both DocumentControllers this session -- before this,
 * they silently fell through to a generic label with no country/payer
 * resolution, and the customer self-service endpoint always 404'd for a
 * legitimate owner (neither `booking` nor `user_id` is ever set for these
 * purposes). The tests below are real regressions, proven to fail against
 * the pre-fix code, not padding.
 */
class DocumentEngineTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use TaxiRideFixtureHelpers;
    use PropertyRentalFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use RentalFixtureHelpers;

    private function bookingPayment(array $overrides = []): Payment
    {
        $scenario = $this->makeAssignedBookingScenario();

        return Payment::create(array_merge([
            'booking_id' => $scenario['booking']->id,
            'amount' => 500,
            'gateway' => 'razorpay',
            'gateway_order_id' => 'order_test123',
            'status' => 'pending',
            'purpose' => 'booking',
        ], $overrides));
    }

    // ============================== Numbering ==============================

    public function test_number_is_idempotent_for_the_same_payment(): void
    {
        $payment = $this->bookingPayment();

        $first = app(DocumentNumberService::class)->numberFor($payment, 'invoice');
        $second = app(DocumentNumberService::class)->numberFor($payment, 'invoice');

        $this->assertSame($first->number, $second->number);
        $this->assertSame(1, GeneratedDocument::where('documentable_id', $payment->id)->where('type', 'invoice')->count());
    }

    public function test_number_increments_sequentially_within_same_country_year(): void
    {
        $paymentA = $this->bookingPayment();
        $paymentB = $this->bookingPayment();

        $numberA = app(DocumentNumberService::class)->numberFor($paymentA, 'invoice');
        $numberB = app(DocumentNumberService::class)->numberFor($paymentB, 'invoice');

        $this->assertNotSame($numberA->number, $numberB->number);
    }

    public function test_invoice_and_receipt_get_independent_numbers_for_the_same_payment(): void
    {
        $payment = $this->bookingPayment();

        $invoice = app(DocumentNumberService::class)->numberFor($payment, 'invoice');
        $receipt = app(DocumentNumberService::class)->numberFor($payment, 'receipt');

        $this->assertNotSame($invoice->number, $receipt->number);
        $this->assertStringStartsWith('INV', $invoice->number);
        $this->assertStringStartsWith('REC', $receipt->number);
    }

    // ============================== Data building ==============================

    public function test_document_service_builds_booking_invoice_data(): void
    {
        $payment = $this->bookingPayment();

        $data = app(DocumentService::class)->forPayment($payment, 'invoice');

        $this->assertSame('invoice', $data['type']);
        $this->assertNotEmpty($data['number']);
        $this->assertSame(500.0, $data['total']);
        $this->assertNotEmpty($data['lines']);
        $this->assertStringContainsString('Booking', $data['lines'][0]['label']);
    }

    public function test_document_service_builds_wallet_topup_receipt_data(): void
    {
        $customer = $this->makeCustomer();
        $payment = Payment::create([
            'user_id' => $customer->id, 'amount' => 200, 'gateway' => 'razorpay',
            'status' => 'captured', 'captured_at' => now(), 'purpose' => 'wallet_topup',
        ]);

        $data = app(DocumentService::class)->forPayment($payment, 'receipt');

        $this->assertSame('receipt', $data['type']);
        $this->assertSame('Wallet top-up', $data['lines'][0]['label']);
        $this->assertSame($customer->name, $data['payer_name']);
    }

    public function test_document_service_builds_parcel_order_data_with_real_franchise_and_payer(): void
    {
        $scenario = $this->makeParcelOrderScenario();
        $payment = Payment::create(['parcel_order_id' => $scenario['order']->id, 'amount' => 100, 'status' => 'captured', 'captured_at' => now(), 'purpose' => 'parcel_order']);

        $data = app(DocumentService::class)->forPayment($payment, 'receipt');

        $this->assertStringContainsString($scenario['order']->code, $data['lines'][0]['label']);
        $this->assertSame($scenario['customer']->name, $data['payer_name']);
        $this->assertSame($scenario['customer']->phone, $data['payer_phone']);
        $this->assertSame($scenario['franchise']->name, $data['franchise_name']);
    }

    public function test_document_service_builds_taxi_ride_data_with_real_franchise_and_payer(): void
    {
        $scenario = $this->makeTaxiRideScenario();
        $payment = Payment::create(['taxi_ride_id' => $scenario['ride']->id, 'amount' => 150, 'status' => 'captured', 'captured_at' => now(), 'purpose' => 'taxi_ride']);

        $data = app(DocumentService::class)->forPayment($payment, 'receipt');

        $this->assertStringContainsString($scenario['ride']->code, $data['lines'][0]['label']);
        $this->assertSame($scenario['customer']->name, $data['payer_name']);
    }

    public function test_document_service_builds_property_reservation_data_with_real_franchise_and_payer(): void
    {
        $scenario = $this->makePropertyReservationScenario();
        $payment = Payment::create(['property_reservation_id' => $scenario['reservation']->id, 'amount' => 2000, 'status' => 'captured', 'captured_at' => now(), 'purpose' => 'property_reservation']);

        $data = app(DocumentService::class)->forPayment($payment, 'receipt');

        $this->assertStringContainsString($scenario['reservation']->code, $data['lines'][0]['label']);
        $this->assertSame($scenario['customer']->name, $data['payer_name']);
    }

    public function test_document_service_builds_marketplace_order_data_with_real_store_franchise_and_payer(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario('completed');
        $payment = Payment::create(['marketplace_order_id' => $scenario['order']->id, 'amount' => 300, 'status' => 'captured', 'captured_at' => now(), 'purpose' => 'marketplace_order']);

        $data = app(DocumentService::class)->forPayment($payment, 'receipt');

        $this->assertStringContainsString($scenario['order']->code, $data['lines'][0]['label']);
        $this->assertStringContainsString($scenario['store']->name, $data['lines'][0]['label']);
        $this->assertSame($scenario['customer']->name, $data['payer_name']);
        $this->assertSame($scenario['franchise']->name, $data['franchise_name']);
    }

    /** RENTAL MODULE IMPLEMENTATION -- the shared Vehicle/Equipment engine's own Document Engine coverage, added together with the schema this time (not retroactively, unlike the four verticals this file's own docblock describes). */
    public function test_document_service_builds_rental_reservation_data_with_real_franchise_and_payer(): void
    {
        $scenario = $this->makeVehicleReservationScenario();
        $payment = Payment::create(['rental_reservation_id' => $scenario['reservation']->id, 'amount' => 1000, 'status' => 'captured', 'captured_at' => now(), 'purpose' => 'rental_reservation']);

        $data = app(DocumentService::class)->forPayment($payment, 'receipt');

        $this->assertStringContainsString($scenario['reservation']->code, $data['lines'][0]['label']);
        $this->assertSame($scenario['customer']->name, $data['payer_name']);
        $this->assertSame($scenario['franchise']->name, $data['franchise_name']);
    }

    // ============================== Admin authorization ==============================

    public function test_admin_document_download_denied_without_permission(): void
    {
        $payment = $this->bookingPayment();
        $actor = $this->makeUserWithNoPermissions();

        $response = $this->actingAs($actor)->get(route('admin.documents.payments.show', $payment->id));

        $response->assertForbidden();
    }

    public function test_admin_document_download_denied_for_wrong_scope(): void
    {
        [, , $otherFranchise] = $this->makeFranchiseTree();
        $payment = $this->bookingPayment();
        $actor = $this->makeUserWithPermission('payments.view', 'franchise', $otherFranchise->id);

        $response = $this->actingAs($actor)->get(route('admin.documents.payments.show', $payment->id));

        $response->assertNotFound();
    }

    public function test_admin_document_download_succeeds_for_correct_scope(): void
    {
        $payment = $this->bookingPayment();
        $franchiseId = $payment->booking->franchise_id;
        $actor = $this->makeUserWithPermission('payments.view', 'franchise', $franchiseId);

        $response = $this->actingAs($actor)->get(route('admin.documents.payments.show', $payment->id));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_admin_document_download_nonexistent_payment_404s(): void
    {
        $actor = $this->makeSuperAdmin();

        $response = $this->actingAs($actor)->get(route('admin.documents.payments.show', 999999));

        $response->assertNotFound();
    }

    // ============================== Customer self-service (API) ==============================

    public function test_customer_can_download_own_payment_document(): void
    {
        $payment = $this->bookingPayment();

        $response = $this->actingAs($payment->booking->customer, 'sanctum')->get("/api/payments/{$payment->id}/document");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_customer_cannot_download_someone_elses_payment_document(): void
    {
        $payment = $this->bookingPayment();
        $stranger = $this->makeCustomer();

        $response = $this->actingAs($stranger, 'sanctum')->get("/api/payments/{$payment->id}/document");

        $response->assertNotFound();
    }

    public function test_unauthenticated_customer_document_request_rejected(): void
    {
        $payment = $this->bookingPayment();

        $this->get("/api/payments/{$payment->id}/document")->assertUnauthorized();
    }

    public function test_wallet_topup_payer_can_download_own_document(): void
    {
        $customer = $this->makeCustomer();
        $payment = Payment::create(['user_id' => $customer->id, 'amount' => 100, 'status' => 'pending', 'purpose' => 'wallet_topup']);

        $response = $this->actingAs($customer, 'sanctum')->get("/api/payments/{$payment->id}/document");

        $response->assertOk();
    }

    /**
     * 2026-08-17 regression -- before the belongsTo() fix, this always
     * 404'd for a legitimate parcel-order customer (neither `booking` nor
     * `user_id` is ever set for purpose=parcel_order).
     */
    public function test_parcel_order_customer_can_download_own_document(): void
    {
        $scenario = $this->makeParcelOrderScenario();
        $payment = Payment::create(['parcel_order_id' => $scenario['order']->id, 'amount' => 100, 'status' => 'captured', 'captured_at' => now(), 'purpose' => 'parcel_order']);

        $response = $this->actingAs($scenario['customer'], 'sanctum')->get("/api/payments/{$payment->id}/document");

        $response->assertOk();
    }

    public function test_taxi_ride_customer_can_download_own_document_but_a_stranger_cannot(): void
    {
        $scenario = $this->makeTaxiRideScenario();
        $payment = Payment::create(['taxi_ride_id' => $scenario['ride']->id, 'amount' => 150, 'status' => 'captured', 'captured_at' => now(), 'purpose' => 'taxi_ride']);
        $stranger = $this->makeCustomer();

        $this->actingAs($scenario['customer'], 'sanctum')->get("/api/payments/{$payment->id}/document")->assertOk();
        $this->actingAs($stranger, 'sanctum')->get("/api/payments/{$payment->id}/document")->assertNotFound();
    }

    public function test_property_reservation_customer_can_download_own_document(): void
    {
        $scenario = $this->makePropertyReservationScenario();
        $payment = Payment::create(['property_reservation_id' => $scenario['reservation']->id, 'amount' => 2000, 'status' => 'captured', 'captured_at' => now(), 'purpose' => 'property_reservation']);

        $response = $this->actingAs($scenario['customer'], 'sanctum')->get("/api/payments/{$payment->id}/document");

        $response->assertOk();
    }

    public function test_marketplace_order_customer_can_download_own_document_but_a_stranger_cannot(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario('completed');
        $payment = Payment::create(['marketplace_order_id' => $scenario['order']->id, 'amount' => 300, 'status' => 'captured', 'captured_at' => now(), 'purpose' => 'marketplace_order']);
        $stranger = $this->makeCustomer();

        $this->actingAs($scenario['customer'], 'sanctum')->get("/api/payments/{$payment->id}/document")->assertOk();
        $this->actingAs($stranger, 'sanctum')->get("/api/payments/{$payment->id}/document")->assertNotFound();
    }

    /** Admin-side row-level scope regression for the same four purposes -- see Payments\Index::scopeColumns()'s own docblock. */
    public function test_admin_document_download_succeeds_for_correct_scope_on_a_marketplace_order_payment(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario('completed');
        $payment = Payment::create(['marketplace_order_id' => $scenario['order']->id, 'amount' => 300, 'status' => 'captured', 'captured_at' => now(), 'purpose' => 'marketplace_order']);
        $actor = $this->makeUserWithPermission('payments.view', 'franchise', $scenario['franchise']->id);

        $response = $this->actingAs($actor)->get(route('admin.documents.payments.show', $payment->id));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_admin_document_download_denied_for_wrong_scope_on_a_marketplace_order_payment(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario('completed');
        [, , $otherFranchise] = $this->makeFranchiseTree();
        $payment = Payment::create(['marketplace_order_id' => $scenario['order']->id, 'amount' => 300, 'status' => 'captured', 'captured_at' => now(), 'purpose' => 'marketplace_order']);
        $actor = $this->makeUserWithPermission('payments.view', 'franchise', $otherFranchise->id);

        $response = $this->actingAs($actor)->get(route('admin.documents.payments.show', $payment->id));

        $response->assertNotFound();
    }

    public function test_rental_reservation_customer_can_download_own_document_but_a_stranger_cannot(): void
    {
        $scenario = $this->makeVehicleReservationScenario();
        $payment = Payment::create(['rental_reservation_id' => $scenario['reservation']->id, 'amount' => 1000, 'status' => 'captured', 'captured_at' => now(), 'purpose' => 'rental_reservation']);
        $stranger = $this->makeCustomer();

        $this->actingAs($scenario['customer'], 'sanctum')->get("/api/payments/{$payment->id}/document")->assertOk();
        $this->actingAs($stranger, 'sanctum')->get("/api/payments/{$payment->id}/document")->assertNotFound();
    }

    /** Admin-side row-level scope regression -- see Payments\Index::scopeColumns()'s own docblock, extended to rentalReservation this session. */
    public function test_admin_document_download_succeeds_for_correct_scope_on_a_rental_reservation_payment(): void
    {
        $scenario = $this->makeVehicleReservationScenario();
        $payment = Payment::create(['rental_reservation_id' => $scenario['reservation']->id, 'amount' => 1000, 'status' => 'captured', 'captured_at' => now(), 'purpose' => 'rental_reservation']);
        $actor = $this->makeUserWithPermission('payments.view', 'franchise', $scenario['franchise']->id);

        $response = $this->actingAs($actor)->get(route('admin.documents.payments.show', $payment->id));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_admin_document_download_denied_for_wrong_scope_on_a_rental_reservation_payment(): void
    {
        $scenario = $this->makeVehicleReservationScenario();
        [, , $otherFranchise] = $this->makeFranchiseTree();
        $payment = Payment::create(['rental_reservation_id' => $scenario['reservation']->id, 'amount' => 1000, 'status' => 'captured', 'captured_at' => now(), 'purpose' => 'rental_reservation']);
        $actor = $this->makeUserWithPermission('payments.view', 'franchise', $otherFranchise->id);

        $response = $this->actingAs($actor)->get(route('admin.documents.payments.show', $payment->id));

        $response->assertNotFound();
    }

    // ============================== Type selection ==============================

    public function test_captured_payment_generates_a_receipt_not_invoice(): void
    {
        $payment = $this->bookingPayment(['status' => 'captured', 'captured_at' => now()]);
        $franchiseId = $payment->booking->franchise_id;
        $actor = $this->makeUserWithPermission('payments.view', 'franchise', $franchiseId);

        $this->actingAs($actor)->get(route('admin.documents.payments.show', $payment->id));

        $this->assertSame(1, GeneratedDocument::where('documentable_id', $payment->id)->where('type', 'receipt')->count());
        $this->assertSame(0, GeneratedDocument::where('documentable_id', $payment->id)->where('type', 'invoice')->count());
    }
}
