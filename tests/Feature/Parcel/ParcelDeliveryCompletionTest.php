<?php

namespace Tests\Feature\Parcel;

use App\Actions\AdminAssignParcelRiderAction;
use App\Actions\CreateParcelOrderAction;
use App\Actions\CustomerCancelParcelOrderAction;
use App\Actions\DeclineParcelOfferAction;
use App\Actions\MarkParcelDeliveredAction;
use App\Actions\MarkParcelPickedUpAction;
use App\Contracts\PaymentGateway;
use App\Contracts\SmsAdapter;
use App\Exceptions\ParcelQuoteException;
use App\Jobs\ParcelDispatchJob;
use App\Models\Address;
use App\Models\Commission;
use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\FieldWorkerCashDue;
use App\Models\Module;
use App\Models\ParcelOrder;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Wallet;
use App\Services\DispatchService;
use App\Services\ModuleActivationService;
use App\Services\Parcel\FieldWorkerCashDueService;
use App\Services\Parcel\ParcelPaymentService;
use App\Services\Parcel\ParcelPricing;
use App\Services\Payments\RazorpayWebhookHandler;
use App\Services\PayoutService;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\Support\CapturingSmsAdapter;
use Tests\TestCase;

/**
 * Parcel delivery completion (owner decision 2026-10-23): distance + weight + size pricing, online payment
 * held until capture, cash collected at pickup with rider cash dues (no double pay), customer cancel rules,
 * manual assignment, recipient SMS, parcel-only addresses, and the Modules switch.
 */
class ParcelDeliveryCompletionTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;

    private function scope($franchise, $zone): array
    {
        return ['zone_id' => $zone->id, 'franchise_id' => $franchise->id, 'city_id' => $franchise->city_id, 'country_id' => $franchise->country_id];
    }

    /** An active parcel franchise, a customer with pickup/drop addresses, and one online rider. */
    private function world(): array
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateParcelFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);
        $rider = $this->makeParcelRiderIn($franchise, $zone);

        return compact('franchise', 'zone', 'customer', 'pickup', 'dropoff', 'rider');
    }

    private function create(array $w, array $extra = []): ParcelOrder
    {
        return app(CreateParcelOrderAction::class)->execute(array_merge(['restricted_items_confirmed' => true, 
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $w['customer']->id,
            'pickup_address_id' => $w['pickup']->id, 'dropoff_address_id' => $w['dropoff']->id,
            'recipient_name' => 'Ravi', 'recipient_phone' => '9876543210',
        ], $extra));
    }

    private function captureWebhook(Payment $payment, ?int $paise = null): void
    {
        app(RazorpayWebhookHandler::class)->handleCaptured(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => [
            'order_id' => $payment->gateway_order_id, 'id' => 'pay_'.Str::random(8), 'currency' => 'INR',
            'amount' => $paise ?? (int) round((float) $payment->amount * 100),
        ]]]]);
    }

    private function pendingOnlinePayment(ParcelOrder $order): Payment
    {
        return Payment::create([
            'parcel_order_id' => $order->id, 'purpose' => 'parcel_order', 'amount' => $order->price_quoted,
            'gateway' => 'razorpay', 'gateway_order_id' => 'order_'.Str::random(10), 'status' => 'pending',
        ]);
    }

    // ═══════════════════════ module switch ═══════════════════════

    public function test_parcel_ships_implemented_but_inactive_until_an_admin_activates_it(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();

        $this->assertTrue((bool) Module::where('code', Modules::PARCEL)->value('is_implemented'), 'The migration marks parcel as built.');
        $this->assertFalse(app(ModuleActivationService::class)->isActive(Modules::PARCEL, $this->scope($franchise, $zone)));
        $this->assertFalse(app(ModuleActivationService::class)->isActiveAnywhere(Modules::PARCEL));

        app(ModuleActivationService::class)->setActive(Modules::PARCEL, 'franchise', $franchise->id, true);
        $this->assertTrue(app(ModuleActivationService::class)->isActive(Modules::PARCEL, $this->scope($franchise, $zone)));
        $this->assertTrue(app(ModuleActivationService::class)->isActiveAnywhere(Modules::PARCEL));

        app(ModuleActivationService::class)->setActive(Modules::PARCEL, 'franchise', $franchise->id, false);
        $this->assertFalse(app(ModuleActivationService::class)->isActiveAnywhere(Modules::PARCEL));
    }

    // ═══════════════════════ pricing ═══════════════════════

    public function test_price_is_base_plus_distance_plus_weight_plus_size(): void
    {
        $this->configureParcelPricing([
            'parcel.base_fare' => '40', 'parcel.base_km' => '2', 'parcel.per_km_rate' => '10',
            'parcel.free_weight_kg' => '1', 'parcel.per_kg_rate' => '5', 'parcel.size_surcharge_large' => '30',
        ]);

        // (0,0) → (0, 0.0449) is ~5 km in a straight line.
        $quote = app(ParcelPricing::class)->quote(0, 0, 0, 0.0449, 3, 'large', []);

        $this->assertEqualsWithDelta(4.99, $quote['distance_km'], 0.02);
        // 40 base + (4.99 - 2) × 10 + (3 - 1) × 5 + 30 large
        $this->assertEqualsWithDelta(40 + 29.9 + 10 + 30, $quote['total'], 0.25);
        $this->assertSame(['Base fare (first 2 km)', 'Distance (2.99 km)', 'Weight (2 kg over free limit)', 'Large package'], array_column($quote['lines'], 'label'));
    }

    public function test_road_factor_scales_the_distance(): void
    {
        $this->configureParcelPricing(['parcel.road_distance_factor' => '1.5', 'parcel.base_km' => '0']);

        $quote = app(ParcelPricing::class)->quote(0, 0, 0, 0.0449, null, 'small', []);

        $this->assertEqualsWithDelta(7.49, $quote['distance_km'], 0.03);
    }

    public function test_quote_refuses_beyond_the_distance_and_weight_limits(): void
    {
        $this->configureParcelPricing(['parcel.max_distance_km' => '3', 'parcel.max_weight_kg' => '5']);

        try {
            app(ParcelPricing::class)->quote(0, 0, 0, 0.0449, 1, 'small', []);
            $this->fail('A 5 km parcel must be refused at a 3 km limit.');
        } catch (ParcelQuoteException $e) {
            $this->assertStringContainsString('up to 3', $e->getMessage());
        }

        $this->expectException(ParcelQuoteException::class);
        app(ParcelPricing::class)->quote(0, 0, 0, 0.001, 6, 'small', []);
    }

    public function test_a_franchise_override_beats_the_global_price(): void
    {
        $w = $this->world();
        Setting::set('parcel.base_fare', '99', 'franchise', $w['franchise']->id);

        $order = $this->create($w, ['payment_method' => 'cash']);

        $this->assertEquals(99, $order->price_quoted); // the ~1.6 km fixture trip is inside the 2 km base
        $this->assertNotNull($order->distance_km);
        $this->assertSame('Base fare (first 2 km)', $order->price_breakdown[0]['label']);
    }

    // ═══════════════════════ thumb rule: no benefits ═══════════════════════

    public function test_a_coupon_is_refused_on_parcel_for_every_payment_method(): void
    {
        $w = $this->world();

        foreach (['cash', 'online'] as $method) {
            try {
                $this->create($w, ['payment_method' => $method, 'coupon_code' => 'WELCOME50']);
                $this->fail("A coupon must be refused on a {$method} parcel.");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('not available on parcel', $e->getMessage());
            }
        }

        $this->assertSame(0, ParcelOrder::count());
    }

    // ═══════════════════════ online payment ═══════════════════════

    public function test_an_online_order_is_held_and_never_dispatched_before_payment(): void
    {
        Queue::fake();
        $w = $this->world();

        $order = $this->create($w, ['payment_method' => 'online']);

        $this->assertTrue($order->isAwaitingOnlinePayment());
        Queue::assertNotPushed(ParcelDispatchJob::class);

        // Even if a dispatch job ran for it, it must not search.
        (new ParcelDispatchJob($order->id))->handle(app(DispatchService::class));
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, DispatchAttempt::where('dispatchable_id', $order->id)->count());
    }

    public function test_capture_webhook_marks_paid_and_starts_the_rider_search(): void
    {
        Queue::fake();
        $w = $this->world();
        $order = $this->create($w, ['payment_method' => 'online']);
        $payment = $this->pendingOnlinePayment($order);

        $this->captureWebhook($payment);

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('captured', $payment->fresh()->status);
        Queue::assertPushed(ParcelDispatchJob::class, fn ($job) => $job->parcelOrderId === $order->id);
    }

    public function test_a_wrong_amount_capture_never_marks_the_parcel_paid(): void
    {
        Queue::fake();
        $w = $this->world();
        $order = $this->create($w, ['payment_method' => 'online']);
        $payment = $this->pendingOnlinePayment($order);

        $this->captureWebhook($payment, 100); // ₹1 instead of the price

        $this->assertSame('pending', $order->fresh()->payment_status);
        Queue::assertNotPushed(ParcelDispatchJob::class);
    }

    public function test_a_payment_captured_after_the_order_was_cancelled_is_refunded(): void
    {
        Queue::fake();
        $gateway = $this->mock(PaymentGateway::class);
        $gateway->shouldReceive('refund')->once()->withArgs(fn ($id, $amount) => (float) $amount === 80.0)->andReturn(['id' => 'rfnd_1']);
        $gateway->shouldReceive('identifier')->andReturn('razorpay');

        $w = $this->world();
        $order = $this->create($w, ['payment_method' => 'online', 'price_quoted' => 80]);
        $payment = $this->pendingOnlinePayment($order);
        app(CustomerCancelParcelOrderAction::class)->execute($order, $w['customer'], 'Changed my mind');

        $this->captureWebhook($payment);

        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('refunded', $order->fresh()->payment_status);
        Queue::assertNotPushed(ParcelDispatchJob::class);
    }

    public function test_start_online_payment_opens_a_gateway_order_and_a_pending_payment_row(): void
    {
        $gateway = $this->mock(PaymentGateway::class);
        $gateway->shouldReceive('isConfigured')->andReturnTrue();
        $gateway->shouldReceive('identifier')->andReturn('razorpay');
        $gateway->shouldReceive('createRawOrder')->once()->andReturn(['razorpay_order_id' => 'order_X1', 'amount' => 8000, 'currency' => 'INR', 'key_id' => 'rzp_test']);

        $w = $this->world();
        $order = $this->create($w, ['payment_method' => 'online', 'price_quoted' => 80]);

        $checkout = app(ParcelPaymentService::class)->startOnlinePayment($order);

        $this->assertSame('order_X1', $checkout['razorpay_order_id']);
        $this->assertDatabaseHas('payments', ['parcel_order_id' => $order->id, 'purpose' => 'parcel_order', 'gateway_order_id' => 'order_X1', 'status' => 'pending']);
    }

    public function test_unpaid_online_orders_expire_after_the_configured_minutes(): void
    {
        $w = $this->world();
        Setting::set('parcel.unpaid_expiry_minutes', '30');
        $old = $this->create($w, ['payment_method' => 'online']);
        $fresh = $this->create($w, ['payment_method' => 'online']);
        $cash = $this->create($w, ['payment_method' => 'cash']);
        ParcelOrder::whereKey([$old->id, $cash->id])->update(['created_at' => now()->subMinutes(31)]);
        ParcelOrder::whereKey($fresh->id)->update(['created_at' => now()->subMinutes(10)]);

        $this->artisan('parcel:expire-unpaid')->assertSuccessful();

        $this->assertSame('cancelled', $old->fresh()->status);
        $this->assertSame(0.0, (float) $old->fresh()->cancellation_fee);
        $this->assertSame('pending', $fresh->fresh()->status);
        $this->assertNotSame('cancelled', $cash->fresh()->status, 'Cash orders are never expired for non-payment.');
    }

    // ═══════════════════════ cash + rider dues ═══════════════════════

    public function test_cash_order_dispatches_at_once_and_records_the_cash_at_pickup(): void
    {
        Queue::fake();
        $w = $this->world();
        $order = $this->create($w, ['payment_method' => 'cash']);

        Queue::assertPushed(ParcelDispatchJob::class);

        $order->update(['status' => 'assigned', 'assigned_worker_id' => $w['rider']->id, 'pickup_otp' => '1111', 'delivery_otp' => '2222']);
        app(MarkParcelPickedUpAction::class)->execute($order->id, $w['rider'], '1111');

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertNotNull($order->cash_collected_at);
        $this->assertDatabaseHas('payments', ['parcel_order_id' => $order->id, 'gateway' => 'cash', 'status' => 'captured']);
    }

    public function test_cash_delivery_credits_the_rider_nothing_and_records_what_they_owe(): void
    {
        $w = $this->world();
        $order = $this->create($w, ['payment_method' => 'cash', 'price_quoted' => 200]);
        $order->update(['status' => 'assigned', 'assigned_worker_id' => $w['rider']->id, 'pickup_otp' => '1111', 'delivery_otp' => '2222']);
        app(MarkParcelPickedUpAction::class)->execute($order->id, $w['rider'], '1111');

        app(MarkParcelDeliveredAction::class)->execute($order->id, $w['rider'], '2222');

        $commission = Commission::where('parcel_order_id', $order->id)->firstOrFail();
        $this->assertSame(0.0, (float) (Wallet::where('user_id', $w['rider']->user_id)->value('balance') ?? 0), 'The rider holds the cash — no wallet credit (no double pay).');

        $due = FieldWorkerCashDue::where('parcel_order_id', $order->id)->firstOrFail();
        $this->assertEquals(round((float) $commission->platform_commission + (float) $commission->franchise_commission, 2), (float) $due->amount_owed);
        $this->assertGreaterThan(0, (float) $due->amount_owed);
        $this->assertSame('outstanding', $due->status);
    }

    public function test_online_earnings_clear_older_cash_dues_and_pay_the_franchise_its_share(): void
    {
        $w = $this->world();
        $owner = $this->makeCustomer();
        $w['franchise']->update(['owner_user_id' => $owner->id]);

        $cash = $this->create($w, ['payment_method' => 'cash', 'price_quoted' => 200]);
        $cash->update(['status' => 'assigned', 'assigned_worker_id' => $w['rider']->id, 'pickup_otp' => '1', 'delivery_otp' => '2']);
        app(MarkParcelPickedUpAction::class)->execute($cash->id, $w['rider'], '1');
        app(MarkParcelDeliveredAction::class)->execute($cash->id, $w['rider'], '2');
        $owed = (float) FieldWorkerCashDue::where('parcel_order_id', $cash->id)->value('amount_owed');

        $online = $this->create($w, ['payment_method' => 'cash', 'price_quoted' => 500]);
        $online->update(['payment_method' => 'online', 'payment_status' => 'paid', 'status' => 'picked_up', 'assigned_worker_id' => $w['rider']->id, 'delivery_otp' => '3']);
        app(MarkParcelDeliveredAction::class)->execute($online->id, $w['rider'], '3');

        $riderShare = (float) Commission::where('parcel_order_id', $online->id)->value('provider_commission');
        $this->assertEqualsWithDelta($riderShare - $owed, (float) Wallet::where('user_id', $w['rider']->user_id)->value('balance'), 0.01);
        $this->assertSame('settled', FieldWorkerCashDue::where('parcel_order_id', $cash->id)->value('status'));

        $franchiseCashShare = (float) Commission::where('parcel_order_id', $cash->id)->value('franchise_commission');
        $franchiseOnlineShare = (float) Commission::where('parcel_order_id', $online->id)->value('franchise_commission');
        $this->assertEqualsWithDelta($franchiseCashShare + $franchiseOnlineShare, (float) Wallet::where('user_id', $owner->id)->value('balance'), 0.01);
    }

    public function test_a_field_worker_payout_is_refused_while_cash_dues_remain(): void
    {
        $w = $this->world();
        FieldWorkerCashDue::create([
            'field_worker_id' => $w['rider']->id, 'parcel_order_id' => $this->create($w, ['payment_method' => 'cash'])->id,
            'commission_id' => Commission::create(['provider_commission' => 0, 'franchise_commission' => 0, 'platform_commission' => 50])->id,
            'platform_portion' => 50, 'franchise_portion' => 0, 'amount_owed' => 50, 'status' => 'outstanding',
        ]);
        Wallet::create(['user_id' => $w['rider']->user_id, 'balance' => 30]);

        try {
            app(PayoutService::class)->request('field_worker', $w['rider']->id, 10);
            $this->fail('A payout must be refused while dues remain.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cash dues', $e->getMessage());
        }

        // The sweep still took what the wallet had.
        $this->assertEquals(0, (float) Wallet::where('user_id', $w['rider']->user_id)->value('balance'));
        $this->assertEquals(20, app(FieldWorkerCashDueService::class)->outstanding($w['rider']));
    }

    public function test_a_rider_at_the_cash_dues_limit_gets_no_cash_offers_but_still_gets_prepaid_ones(): void
    {
        Queue::fake(); // keep the creation-time dispatch from offering these orders before the assertions
        $w = $this->world();
        Setting::set('parcel.rider_cash_due_limit', '40');
        $seed = $this->create($w, ['payment_method' => 'cash']);
        FieldWorkerCashDue::create([
            'field_worker_id' => $w['rider']->id, 'parcel_order_id' => $seed->id,
            'commission_id' => Commission::create(['provider_commission' => 0, 'franchise_commission' => 0, 'platform_commission' => 40])->id,
            'platform_portion' => 40, 'franchise_portion' => 0, 'amount_owed' => 40, 'status' => 'outstanding',
        ]);

        $cashOrder = $this->create($w, ['payment_method' => 'cash']);
        $paidOrder = $this->create($w, ['payment_method' => 'cash']);
        $paidOrder->update(['payment_method' => 'online', 'payment_status' => 'paid']);

        $this->assertTrue(app(DispatchService::class)->findWorkerCandidates($cashOrder)->isEmpty());
        $this->assertSame($w['rider']->id, app(DispatchService::class)->findWorkerCandidates($paidOrder->fresh())->first()['provider']->id);
    }

    public function test_a_rider_with_a_stale_location_is_not_offered_parcels(): void
    {
        $w = $this->world();
        $w['rider']->update(['location_updated_at' => now()->subHours(3)]);
        $order = $this->create($w, ['payment_method' => 'cash']);

        $this->assertTrue(app(DispatchService::class)->findWorkerCandidates($order)->isEmpty());
    }

    // ═══════════════════════ cancel / assign / decline ═══════════════════════

    public function test_customer_can_cancel_free_before_assignment_and_never_after_pickup(): void
    {
        $w = $this->world();
        $order = $this->create($w, ['payment_method' => 'cash']);
        $this->assertSame(0.0, app(CustomerCancelParcelOrderAction::class)->fee($order));

        app(CustomerCancelParcelOrderAction::class)->execute($order, $w['customer'], 'Not needed');
        $this->assertSame('cancelled', $order->fresh()->status);

        $picked = $this->create($w, ['payment_method' => 'cash']);
        $picked->update(['status' => 'picked_up', 'assigned_worker_id' => $w['rider']->id]);
        $this->expectExceptionMessage('already been picked up');
        app(CustomerCancelParcelOrderAction::class)->execute($picked, $w['customer'], 'Too late');
    }

    public function test_a_customer_cannot_cancel_someone_elses_parcel(): void
    {
        $w = $this->world();
        $order = $this->create($w, ['payment_method' => 'cash']);

        $this->expectException(\RuntimeException::class);
        app(CustomerCancelParcelOrderAction::class)->execute($order, $this->makeCustomer(), 'x');
    }

    public function test_cancelling_withdraws_open_offers(): void
    {
        $w = $this->world();
        $order = $this->create($w, ['payment_method' => 'cash']);
        DispatchAttempt::create(['dispatchable_type' => ParcelOrder::class, 'dispatchable_id' => $order->id, 'notifiable_type' => FieldWorker::class, 'notifiable_id' => $w['rider']->id, 'status' => 'notified', 'notified_at' => now()]);

        app(CustomerCancelParcelOrderAction::class)->execute($order, $w['customer'], 'x');

        $this->assertSame(0, DispatchAttempt::where('dispatchable_id', $order->id)->where('status', 'notified')->count());
    }

    public function test_admin_can_assign_a_free_rider_and_the_rider_gets_the_job_with_otps(): void
    {
        $w = $this->world();
        $order = $this->create($w, ['payment_method' => 'cash']);

        $assigned = app(AdminAssignParcelRiderAction::class)->execute($order->id, $w['rider']->id);

        $this->assertSame('assigned', $assigned->status);
        $this->assertSame($w['rider']->id, $assigned->assigned_worker_id);
        $this->assertNotEmpty($assigned->pickup_otp);
        $this->assertNotEmpty($assigned->delivery_otp);
    }

    public function test_admin_cannot_assign_an_unpaid_online_order_or_a_busy_rider(): void
    {
        $w = $this->world();
        $unpaid = $this->create($w, ['payment_method' => 'online']);

        try {
            app(AdminAssignParcelRiderAction::class)->execute($unpaid->id, $w['rider']->id);
            $this->fail('Unpaid online order must not be assignable.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not been paid', $e->getMessage());
        }

        $busy = $this->create($w, ['payment_method' => 'cash']);
        $busy->update(['status' => 'picked_up', 'assigned_worker_id' => $w['rider']->id]);
        $next = $this->create($w, ['payment_method' => 'cash']);

        $this->expectExceptionMessage('already on another parcel');
        app(AdminAssignParcelRiderAction::class)->execute($next->id, $w['rider']->id);
    }

    public function test_a_rider_can_decline_their_offer(): void
    {
        $w = $this->world();
        $order = $this->create($w, ['payment_method' => 'cash']);
        DispatchAttempt::create(['dispatchable_type' => ParcelOrder::class, 'dispatchable_id' => $order->id, 'notifiable_type' => FieldWorker::class, 'notifiable_id' => $w['rider']->id, 'status' => 'notified', 'notified_at' => now()]);

        app(DeclineParcelOfferAction::class)->execute($order->id, $w['rider']);

        $this->assertSame('rejected', DispatchAttempt::where('dispatchable_id', $order->id)->value('status'));
        $this->expectException(\RuntimeException::class);
        app(DeclineParcelOfferAction::class)->execute($order->id, $w['rider']);
    }

    // ═══════════════════════ recipient SMS ═══════════════════════

    public function test_the_recipient_gets_the_delivery_otp_by_sms_when_sms_is_on(): void
    {
        $sms = new CapturingSmsAdapter;
        $this->app->instance(SmsAdapter::class, $sms);
        Setting::set('notifications.channels', 'sms');

        $w = $this->world();
        $order = $this->create($w, ['payment_method' => 'cash']);
        DispatchAttempt::create(['dispatchable_type' => ParcelOrder::class, 'dispatchable_id' => $order->id, 'notifiable_type' => FieldWorker::class, 'notifiable_id' => $w['rider']->id, 'status' => 'notified', 'notified_at' => now()]);

        $accepted = app(\App\Actions\AcceptParcelOfferAction::class)->execute($order->id, $w['rider']);

        $message = $sms->lastMessageTo('9876543210');
        $this->assertNotNull($message);
        $this->assertStringContainsString($accepted->delivery_otp, $message);
    }

    public function test_no_recipient_sms_when_sms_is_not_a_channel(): void
    {
        $sms = new CapturingSmsAdapter;
        $this->app->instance(SmsAdapter::class, $sms);
        Setting::set('notifications.channels', 'mail');

        $w = $this->world();
        $order = $this->create($w, ['payment_method' => 'cash']);
        app(AdminAssignParcelRiderAction::class)->execute($order->id, $w['rider']->id);

        $this->assertNull($sms->lastMessageTo('9876543210'));
    }

    // ═══════════════════════ addresses ═══════════════════════

    public function test_an_address_used_by_a_parcel_cannot_be_deleted(): void
    {
        $w = $this->world();
        $this->create($w, ['payment_method' => 'cash']);

        $this->actingAs($w['customer'], 'sanctum')
            ->deleteJson('/api/addresses/'.$w['dropoff']->id)
            ->assertStatus(409);

        $this->assertNotNull(Address::find($w['dropoff']->id));
    }

    public function test_parcel_only_addresses_stay_out_of_the_address_book(): void
    {
        $w = $this->world();
        Address::create([
            'user_id' => $w['customer']->id, 'label' => 'Parcel drop — Ravi', 'lat' => 1, 'lng' => 1,
            'address_line' => 'Somewhere', 'is_parcel_only' => true,
        ]);

        $labels = collect($this->actingAs($w['customer'], 'sanctum')->getJson('/api/addresses')->assertOk()->json('data'))->pluck('label');

        $this->assertContains('Pickup', $labels);
        $this->assertNotContains('Parcel drop — Ravi', $labels);
    }
}
