<?php

namespace Tests\Feature\Parcel;

use App\Actions\CreateParcelOrderAction;
use App\Actions\MarkParcelDeliveredAction;
use App\Actions\MarkParcelPickedUpAction;
use App\Actions\ParcelSenderPaidAtDoorAction;
use App\Jobs\ParcelDispatchJob;
use App\Livewire\Customer\Parcel\Create;
use App\Livewire\Rider\Dashboard as RiderDashboard;
use App\Models\FieldWorkerCashDue;
use App\Models\ParcelOrder;
use App\Models\Payment;
use App\Models\Wallet;
use App\Notifications\ParcelOrderStatusNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Parcel "Pay at: Pickup | Drop" (owner decision 2026-10-24). Cash only: drop = the recipient pays the rider at the
 * door. Nothing waits for the recipient — the order goes to riders at once. If the recipient refuses at the door,
 * the sender pays the rider on the spot (UPI or cash) and the parcel is delivered as normal: no return, no second charge.
 */
class ParcelPayAtDropTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;

    private function world(): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
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
            'payment_method' => 'cash', 'cash_collect_at' => 'drop',
        ], $extra));
    }

    /** Assigned to the rider and picked up — the parcel is on its way to the recipient. */
    private function pickedUp(array $w, ParcelOrder $order): ParcelOrder
    {
        $order->update(['status' => 'assigned', 'assigned_worker_id' => $w['rider']->id, 'pickup_otp' => '1111', 'delivery_otp' => '2222']);
        app(MarkParcelPickedUpAction::class)->execute($order->id, $w['rider'], '1111');

        return $order->fresh();
    }

    // ═══════════════════════ booking ═══════════════════════

    public function test_pay_at_drop_goes_to_riders_at_once_without_waiting_for_the_recipient(): void
    {
        Queue::fake();
        $w = $this->world();

        $order = $this->create($w);

        $this->assertSame('drop', $order->cash_collect_at);
        $this->assertTrue($order->collectsCashAtDelivery());
        $this->assertFalse($order->isAwaitingOnlinePayment());
        Queue::assertPushed(ParcelDispatchJob::class, fn ($job) => $job->parcelOrderId === $order->id);
    }

    public function test_pay_at_drop_only_applies_to_cash(): void
    {
        $w = $this->world();

        $online = $this->create($w, ['payment_method' => 'online']);
        $default = $this->create($w, ['cash_collect_at' => null]);

        $this->assertSame('pickup', $online->cash_collect_at, 'Online is paid at booking; "drop" is ignored.');
        $this->assertFalse($online->collectsCashAtDelivery());
        $this->assertSame('pickup', $default->cash_collect_at, 'Cash defaults to pickup.');
    }

    public function test_pay_at_drop_needs_the_recipients_phone(): void
    {
        $w = $this->world();

        $this->expectExceptionMessage("Enter the recipient's phone number");
        $this->create($w, ['recipient_phone' => null]);
    }

    public function test_a_coupon_is_still_refused_with_pay_at_drop(): void
    {
        $w = $this->world();

        $this->expectExceptionMessage('Coupons and offers are not available on parcel delivery.');
        $this->create($w, ['coupon_code' => 'SAVE10']);
    }

    // ═══════════════════════ cash at the door ═══════════════════════

    public function test_nothing_is_collected_at_pickup_and_the_cash_is_recorded_at_delivery(): void
    {
        $w = $this->world();
        $order = $this->pickedUp($w, $this->create($w, ['price_quoted' => 200]));

        $this->assertSame('pending', $order->payment_status, 'The sender pays nothing at pickup.');
        $this->assertNull($order->cash_collected_at);
        $this->assertSame(0, Payment::where('parcel_order_id', $order->id)->count());

        app(MarkParcelDeliveredAction::class)->execute($order->id, $w['rider'], '2222');

        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertSame('paid', $order->payment_status);
        $this->assertNotNull($order->cash_collected_at);
        $this->assertSame(1, Payment::where('parcel_order_id', $order->id)->where('gateway', 'cash')->where('status', 'captured')->count());
    }

    public function test_cash_at_drop_is_a_rider_cash_due_like_cash_at_pickup(): void
    {
        $w = $this->world();
        $order = $this->pickedUp($w, $this->create($w, ['price_quoted' => 200]));

        app(MarkParcelDeliveredAction::class)->execute($order->id, $w['rider'], '2222');

        $this->assertSame(0.0, (float) (Wallet::where('user_id', $w['rider']->user_id)->value('balance') ?? 0), 'The rider holds the cash — no wallet credit.');
        $due = FieldWorkerCashDue::where('parcel_order_id', $order->id)->firstOrFail();
        $this->assertGreaterThan(0, (float) $due->amount_owed);
        $this->assertSame('outstanding', $due->status);
    }

    public function test_cash_at_pickup_still_collects_at_pickup(): void
    {
        $w = $this->world();

        $order = $this->pickedUp($w, $this->create($w, ['cash_collect_at' => 'pickup']));

        $this->assertSame('paid', $order->payment_status);
        $this->assertNotNull($order->cash_collected_at);
    }

    // ═══════════════════════ recipient refuses: the sender pays on the spot ═══════════════════════

    public function test_recipient_refuses_the_sender_pays_the_rider_and_the_parcel_is_delivered_once_charged(): void
    {
        Notification::fake();
        $w = $this->world();
        $order = $this->pickedUp($w, $this->create($w, ['price_quoted' => 200]));

        $paid = app(ParcelSenderPaidAtDoorAction::class)->execute($order->id, $w['rider']);

        $this->assertSame('picked_up', $paid->status, 'Still on its way: no return trip.');
        $this->assertSame('paid', $paid->payment_status);
        $this->assertNotNull($paid->recipient_refused_at);
        $this->assertNotNull($paid->cash_collected_at);
        Notification::assertSentTo($w['customer'], ParcelOrderStatusNotification::class);

        app(MarkParcelDeliveredAction::class)->execute($order->id, $w['rider'], '2222');

        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertSame(1, Payment::where('parcel_order_id', $order->id)->count(), 'One charge only — never a second one at delivery.');
        $this->assertEquals((float) $order->price_quoted, (float) Payment::where('parcel_order_id', $order->id)->value('amount'));
        $this->assertSame('outstanding', FieldWorkerCashDue::where('parcel_order_id', $order->id)->value('status'), 'The rider holds the money and owes the platform share.');
        $this->assertTrue($order->statusHistory()->where('note', 'like', '%sender paid the rider%')->exists());
    }

    public function test_sender_paid_is_only_for_an_unpaid_pay_at_drop_parcel_on_its_way(): void
    {
        $w = $this->world();

        $atPickup = $this->pickedUp($w, $this->create($w, ['cash_collect_at' => 'pickup']));
        try {
            app(ParcelSenderPaidAtDoorAction::class)->execute($atPickup->id, $w['rider']);
            $this->fail('Cash at pickup is already paid.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('pay-at-drop', $e->getMessage());
        }

        $notPickedUp = $this->create($w);
        $notPickedUp->update(['status' => 'assigned', 'assigned_worker_id' => $w['rider']->id]);
        try {
            app(ParcelSenderPaidAtDoorAction::class)->execute($notPickedUp->id, $w['rider']);
            $this->fail('Not on its way yet.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('pay-at-drop', $e->getMessage());
        }
    }

    public function test_a_double_tap_never_charges_twice(): void
    {
        $w = $this->world();
        $order = $this->pickedUp($w, $this->create($w));
        app(ParcelSenderPaidAtDoorAction::class)->execute($order->id, $w['rider']);

        try {
            app(ParcelSenderPaidAtDoorAction::class)->execute($order->id, $w['rider']);
            $this->fail('Second tap must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('pay-at-drop', $e->getMessage());
        }

        $this->assertSame(1, Payment::where('parcel_order_id', $order->id)->count());
    }

    public function test_another_rider_cannot_record_the_sender_paid(): void
    {
        $w = $this->world();
        $order = $this->pickedUp($w, $this->create($w));
        $other = $this->makeParcelRiderIn($w['franchise'], $w['zone']);

        $this->expectExceptionMessage('not assigned to you');
        app(ParcelSenderPaidAtDoorAction::class)->execute($order->id, $other);
    }

    // ═══════════════════════ web ═══════════════════════

    public function test_customer_books_pay_at_drop_from_the_send_page(): void
    {
        Queue::fake();
        $w = $this->world();

        Livewire::actingAs($w['customer'])->test(Create::class)->set('restrictedConfirmed', true)
            ->set('pickupAddressId', $w['pickup']->id)
            ->call('setDropPin', 1.01, 1.01, 'Lake View Road')
            ->set('recipientPhone', '9876543210')
            ->set('paymentMethod', 'cash')
            ->assertSee('Pay at')
            ->set('cashCollectAt', 'drop')
            ->assertSee('Cash at drop')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect();

        $order = ParcelOrder::firstOrFail();
        $this->assertSame('cash', $order->payment_method);
        $this->assertSame('drop', $order->cash_collect_at);
        Queue::assertPushed(ParcelDispatchJob::class);
    }

    public function test_pay_at_toggle_shows_only_for_cash_and_is_remembered(): void
    {
        $w = $this->world();

        Livewire::actingAs($w['customer'])->test(Create::class)->set('restrictedConfirmed', true)
            ->set('pickupAddressId', $w['pickup']->id)
            ->call('setDropPin', 1.01, 1.01, 'Lake View Road')
            ->set('paymentMethod', 'online')
            ->assertDontSee('Pay at');

        $this->create($w);

        Livewire::actingAs($w['customer'])->test(Create::class)->set('restrictedConfirmed', true)
            ->assertSet('paymentMethod', 'cash')
            ->assertSet('cashCollectAt', 'drop');
    }

    public function test_tracking_page_says_the_recipient_pays_at_drop(): void
    {
        $w = $this->world();
        $order = $this->create($w);

        $this->actingAs($w['customer'])->get('/parcels/'.$order->id)
            ->assertOk()
            ->assertSee('Cash at drop')
            ->assertSee('The recipient pays the rider at drop');
    }

    public function test_rider_screen_collects_at_the_door_and_records_the_sender_paying(): void
    {
        $w = $this->world();
        $order = $this->pickedUp($w, $this->create($w, ['price_quoted' => 150]));

        Livewire::actingAs($w['rider']->user)->test(RiderDashboard::class)
            ->assertSee('cash from the recipient')
            ->assertSee('Recipient not paying?')
            ->assertSee('Sender paid me')
            ->call('senderPaid', $order->id)
            ->assertSet('error', '')
            ->assertDontSee('Recipient not paying?')
            ->set('deliveryOtp', '2222')
            ->call('confirmDelivery', $order->id)
            ->assertSet('error', '');

        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame(1, Payment::where('parcel_order_id', $order->id)->count());

        $this->actingAs($w['customer'])->get('/parcels/'.$order->id)->assertOk()->assertSee('You paid the rider at the door');
    }

    // ═══════════════════════ customer API ═══════════════════════

    public function test_api_books_pay_at_drop_and_returns_it(): void
    {
        Queue::fake();
        $w = $this->world();

        $this->actingAs($w['customer'], 'sanctum')
            ->postJson('/api/parcel-orders', ['restricted_items_confirmed' => true,
                'pickup_address_id' => $w['pickup']->id, 'dropoff_address_id' => $w['dropoff']->id,
                'recipient_phone' => '9876543210', 'payment_method' => 'cash', 'cash_collect_at' => 'drop',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.payment_method', 'cash')
            ->assertJsonPath('data.cash_collect_at', 'drop')
            ->assertJsonPath('data.awaiting_payment', false);

        $this->assertSame('drop', ParcelOrder::firstOrFail()->cash_collect_at);
        Queue::assertPushed(ParcelDispatchJob::class);
    }

    public function test_api_rejects_an_unknown_pay_at_value(): void
    {
        $w = $this->world();

        $this->actingAs($w['customer'], 'sanctum')
            ->postJson('/api/parcel-orders', ['restricted_items_confirmed' => true,
                'pickup_address_id' => $w['pickup']->id, 'dropoff_address_id' => $w['dropoff']->id,
                'recipient_phone' => '9876543210', 'payment_method' => 'cash', 'cash_collect_at' => 'door',
            ])
            ->assertStatus(422);

        $this->assertSame(0, ParcelOrder::count());
    }
}
