<?php

namespace Tests\Feature\Documents;

use App\Models\GeneratedDocument;
use App\Models\Payment;
use App\Services\Documents\DocumentNumberService;
use App\Services\Documents\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Printing/Document Engine (mission Phase 7). One reusable renderer
 * (DocumentService + documents/payment.blade.php) driving invoices/
 * receipts for all three real Payment purposes this codebase already
 * models — no per-purpose duplicate template. Numbering is idempotent
 * (DocumentNumberService) and authorization-checked at both the admin
 * (row-level scope reusing payments.view) and customer (payer-only) layers.
 */
class DocumentEngineTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

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
