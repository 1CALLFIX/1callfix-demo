<?php

namespace Tests\Feature\Parcel;

use App\Actions\CreateParcelOrderAction;
use App\Actions\RecordParcelSafetyPhotoAction;
use App\Actions\RefuseParcelAtPickupAction;
use App\Livewire\Customer\Parcel\Create;
use App\Livewire\ParcelOrders\Manage;
use App\Livewire\ParcelOrders\Settings;
use App\Livewire\Rider\Dashboard as RiderDashboard;
use App\Models\ParcelOrder;
use App\Models\ParcelSafetyPhoto;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AdminOpsAlertNotification;
use App\Notifications\ParcelOrderStatusNotification;
use App\Services\Parcel\ParcelSettings;
use App\Services\WalletService;
use App\Support\WalletSourceLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Parcel pickup safety (owner go-ahead 2026-10-29, "drugs and banned items in parcels"): photos are optional —
 * taken only when something looks unusual — the rider can refuse the parcel at the pickup point, the banned items
 * list is edited on Parcel Settings, and the refusal fee / trip pay are settings that do nothing while unset.
 */
class ParcelPickupSafetyTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
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

    private function assignedOrder(array $w, string $paymentMethod = 'cash'): ParcelOrder
    {
        if ($paymentMethod === 'wallet') {
            Setting::set('payment.wallet_enabled', '1');
            app(WalletService::class)->credit($w['customer'], 500, 'test top-up');
        }

        $order = app(CreateParcelOrderAction::class)->execute([
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $w['customer']->id,
            'pickup_address_id' => $w['pickup']->id, 'dropoff_address_id' => $w['dropoff']->id,
            'recipient_name' => 'Ravi', 'recipient_phone' => '9876543210',
            'payment_method' => $paymentMethod, 'restricted_items_confirmed' => true,
        ]);
        $order->update(['status' => 'assigned', 'assigned_worker_id' => $w['rider']->id, 'pickup_otp' => '1111', 'delivery_otp' => '2222']);

        return $order->fresh();
    }

    private function refuse(array $w, ParcelOrder $order, string $reason = 'restricted_item', ?string $note = null, float $lat = 1.0, float $lng = 1.0): ParcelOrder
    {
        return app(RefuseParcelAtPickupAction::class)->execute($order->id, $w['rider'], $reason, $note, $lat, $lng);
    }

    private function adminWithEmail(): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Ops', 'email' => 'ops@1callfix.test',
            'phone' => '9'.fake()->unique()->numerify('#########'), 'role' => 'super_admin', 'status' => 'active',
        ]);
    }

    // ═══════════════════════ safety photos (optional) ═══════════════════════

    public function test_a_rider_can_save_a_safety_photo_which_goes_to_the_private_disk_and_flags_the_order(): void
    {
        Storage::fake('local');
        Notification::fake();
        $w = $this->world();
        $order = $this->assignedOrder($w);

        Livewire::actingAs($w['rider']->user)->test(RiderDashboard::class)
            ->assertSee('Something not right?')
            ->set('safetyConcerns', ['sender', 'packing'])
            ->set('safetyStage', 'before_packing')
            ->set('safetyNote', 'Sender would not say what is inside')
            ->set('safetyPhoto', UploadedFile::fake()->image('item.jpg'))
            ->call('saveSafetyPhoto', $order->id, 1.0, 1.0)
            ->assertSet('error', '')
            ->assertHasNoErrors();

        $photo = ParcelSafetyPhoto::sole();
        $this->assertSame($order->id, $photo->parcel_order_id);
        $this->assertSame(['sender', 'packing'], $photo->concerns);
        $this->assertSame('before_packing', $photo->stage);
        $this->assertStringStartsWith("parcel-safety/{$order->id}/", $photo->disk_path);
        Storage::disk('local')->assertExists($photo->disk_path);
        $this->assertNotNull($order->fresh()->safety_flagged_at);
        $this->assertSame('assigned', $order->fresh()->status, 'A photo never changes the order');
        Notification::assertNotSentTo($w['customer'], ParcelOrderStatusNotification::class, fn ($n) => $n->eventKey() !== 'parcel_order.created');
    }

    public function test_a_photo_needs_a_concern_and_is_refused_for_another_riders_order_or_after_delivery(): void
    {
        Storage::fake('local');
        $w = $this->world();
        $order = $this->assignedOrder($w);
        $action = app(RecordParcelSafetyPhotoAction::class);

        try {
            $action->execute($order->id, $w['rider'], UploadedFile::fake()->image('a.jpg'), 'before_packing', [], null, null, null);
            $this->fail('A photo without a concern must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('unusual', $e->getMessage());
        }

        $other = $this->makeParcelRiderIn($w['franchise'], $w['zone']);
        try {
            $action->execute($order->id, $other, UploadedFile::fake()->image('a.jpg'), 'before_packing', ['item'], null, null, null);
            $this->fail("Another rider's order must be refused");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not assigned to you', $e->getMessage());
        }

        $order->update(['status' => 'delivered']);
        try {
            $action->execute($order->id, $w['rider'], UploadedFile::fake()->image('a.jpg'), 'before_packing', ['item'], null, null, null);
            $this->fail('No photo after delivery');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('while you have this parcel', $e->getMessage());
        }

        $this->assertSame(0, ParcelSafetyPhoto::count());
        $this->assertNull($order->fresh()->safety_flagged_at);
    }

    // ═══════════════════════ refusing at pickup ═══════════════════════

    public function test_refusing_a_banned_item_cancels_keeps_the_fee_from_the_wallet_refund_pays_the_rider_and_alerts_admins(): void
    {
        Notification::fake();
        $w = $this->world();
        $admin = $this->adminWithEmail();
        Setting::set('parcel.refusal_fee', '15');
        Setting::set('parcel.refusal_rider_pay', '25');
        $order = $this->assignedOrder($w, 'wallet');
        $price = (float) $order->price_quoted;
        $wallet = app(WalletService::class);
        $this->assertSame(500.0 - $price, $wallet->balance($w['customer']));

        $order = $this->refuse($w, $order, 'restricted_item', 'Smelled of ganja');

        $this->assertSame('cancelled', $order->status);
        $this->assertSame('restricted_item', $order->refusal_reason);
        $this->assertSame('Smelled of ganja', $order->refusal_note);
        $this->assertNotNull($order->refused_at);
        $this->assertNotNull($order->safety_flagged_at);
        $this->assertSame(15.0, (float) $order->cancellation_fee);
        $this->assertSame(500.0 - 15.0, $wallet->balance($w['customer']), 'Refunded to the wallet, minus the refusal fee');
        $this->assertSame(25.0, $wallet->balance($w['rider']->user), 'Trip pay credited to the rider');
        $this->assertSame(25.0, (float) $order->refusal_rider_pay);
        $this->assertDatabaseHas('wallet_transactions', ['ref' => "parcel_order:{$order->id}:refusal-pay"]);
        $this->assertSame('refused_trip_pay', WalletSourceLabel::keyFor("parcel_order:{$order->id}:refusal-pay"));

        Notification::assertSentTo($w['customer'], ParcelOrderStatusNotification::class, fn ($n) => $n->eventKey() === 'parcel_order.refused'
            && str_contains($n->toSms($w['customer']), 'cannot carry') && str_contains($n->toSms($w['customer']), '15.00'));
        Notification::assertSentTo($admin, AdminOpsAlertNotification::class, fn ($n) => $n->eventKey() === 'admin.ops_parcel_refused');

        // The rider is free for the next job, and cannot refuse twice.
        $this->expectException(\RuntimeException::class);
        $this->refuse($w, $order);
    }

    public function test_something_else_needs_a_note_and_never_charges_the_sender(): void
    {
        Notification::fake();
        $w = $this->world();
        Setting::set('parcel.refusal_fee', '15');
        $order = $this->assignedOrder($w, 'wallet');

        try {
            $this->refuse($w, $order, 'suspicious', '');
            $this->fail('"Something else" needs a note');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('what is not right', $e->getMessage());
        }
        $this->assertSame('assigned', $order->fresh()->status);

        $order = $this->refuse($w, $order, 'suspicious', 'Asked me to hide it under my seat');
        $this->assertSame(0.0, (float) $order->cancellation_fee);
        $this->assertSame(500.0, app(WalletService::class)->balance($w['customer']), 'Full refund');
    }

    public function test_the_rider_must_be_at_the_pickup_point_and_cannot_refuse_after_pickup(): void
    {
        Notification::fake();
        $w = $this->world();
        $order = $this->assignedOrder($w);

        try {
            $this->refuse($w, $order, 'restricted_item', null, 1.2, 1.2);
            $this->fail('Refusing from far away must be blocked');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('km from the pickup point', $e->getMessage());
        }
        $this->assertSame('assigned', $order->fresh()->status);

        $order->update(['status' => 'picked_up']);
        try {
            $this->refuse($w, $order);
            $this->fail('No refusal after pickup');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('call support', $e->getMessage());
        }
        $this->assertNull($order->fresh()->refused_at);
    }

    public function test_a_cash_order_has_no_fee_and_unset_settings_pay_nobody(): void
    {
        Notification::fake();
        $w = $this->world();
        $order = $this->refuse($w, $this->assignedOrder($w), 'refused_check');

        $this->assertSame('cancelled', $order->status);
        $this->assertSame(0.0, (float) $order->cancellation_fee);
        $this->assertNull($order->refusal_rider_pay);
        $this->assertSame(0.0, app(WalletService::class)->balance($w['rider']->user));

        // Fee set, but a cash order has nothing to keep it from.
        Setting::set('parcel.refusal_fee', '15');
        $order2 = $this->refuse($w, $this->assignedOrder($w), 'restricted_item');
        $this->assertSame(0.0, (float) $order2->cancellation_fee);
    }

    public function test_the_rider_screen_refuses_from_the_live_location(): void
    {
        Notification::fake();
        $w = $this->world();
        $order = $this->assignedOrder($w);

        Livewire::actingAs($w['rider']->user)->test(RiderDashboard::class)
            ->assertSee('Refuse this parcel')
            ->call('refuseParcel', $order->id, 1.0, 1.0)
            ->assertHasErrors(['refuseReason'])
            ->set('refuseReason', 'refused_check')
            ->call('refuseParcel', $order->id, null, null)
            ->assertSet('error', "We couldn't read your location. Allow location access and try again, or call support.")
            ->call('refuseParcel', $order->id, 1.0, 1.0)
            ->assertSet('error', '')
            ->assertSee('Parcel refused');

        $this->assertSame('refused_check', $order->fresh()->refusal_reason);
    }

    // ═══════════════════════ banned items list ═══════════════════════

    public function test_the_banned_items_list_is_edited_on_parcel_settings_and_shown_to_the_sender(): void
    {
        $w = $this->world();
        $this->assertSame(ParcelSettings::DEFAULT_RESTRICTED_ITEMS, ParcelSettings::restrictedItems(), 'Never saved = the standard list');

        Livewire::actingAs($this->makeSuperAdmin())->test(Settings::class)
            ->set('restrictedItemsText', implode("\n", array_map(fn ($i) => "Item {$i}", range(1, 21))))
            ->call('save')
            ->assertHasErrors(['restrictedItemsText'])
            ->set('restrictedItemsText', "- Drugs or ganja\n\nWeapons\nWeapons\n")
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Drugs or ganja');

        $this->assertSame(['Drugs or ganja', 'Weapons'], ParcelSettings::restrictedItems());
        $this->assertDatabaseHas('activity_log', ['description' => 'Setting parcel.restricted_items changed']);

        Livewire::actingAs($w['customer'])->test(Create::class)
            ->assertSee('Drugs or ganja')
            ->assertSee('the rider can refuse it.')
            ->assertDontSee('is kept from your online refund');

        // With a refusal fee set, the sender is told before booking.
        Setting::set('parcel.refusal_fee', '15');
        Livewire::actingAs($w['customer'])->test(Create::class)
            ->assertSee('the rider can refuse it and ₹15.00 is kept from your online refund.')
            ->assertDontSee('@if', false);

        // Cleared = the standard list again.
        Livewire::actingAs($this->makeSuperAdmin())->test(Settings::class)->set('restrictedItemsText', '')->call('save');
        $this->assertSame(ParcelSettings::DEFAULT_RESTRICTED_ITEMS, ParcelSettings::restrictedItems());
    }

    // ═══════════════════════ admin ═══════════════════════

    public function test_admins_see_flagged_orders_and_open_photos_only_within_their_scope(): void
    {
        Storage::fake('local');
        Notification::fake();
        $w = $this->world();
        $flagged = $this->assignedOrder($w);
        $plain = $this->assignedOrder($w);
        $photo = app(RecordParcelSafetyPhotoAction::class)->execute($flagged->id, $w['rider'], UploadedFile::fake()->image('p.jpg'), 'after_packing', ['packing'], 'Taped all over', 1.0, 1.0);

        $super = $this->makeSuperAdmin();
        Livewire::actingAs($super)->test(Manage::class)
            ->set('statusFilter', 'flagged')
            ->assertSee($flagged->code)
            ->assertDontSee($plain->code)
            ->call('viewOrder', $flagged->id)
            ->assertSee('Pickup safety')
            ->assertSee('Parcel, after packing')
            ->assertSee('Taped all over');

        $this->actingAs($super)->get(route('admin.parcel-orders.safety-photos.show', $photo->id))->assertOk();

        [, , $otherFranchise] = $this->makeFranchiseTree();
        $outsider = $this->makeUserWithPermission('parcel_orders.view', 'franchise', $otherFranchise->id);
        $this->actingAs($outsider)->get(route('admin.parcel-orders.safety-photos.show', $photo->id))->assertNotFound();
    }

    // ═══════════════════════ rider API ═══════════════════════

    public function test_the_rider_api_can_add_a_photo_and_refuse(): void
    {
        Storage::fake('local');
        Notification::fake();
        $w = $this->world();
        $order = $this->assignedOrder($w);

        $this->actingAs($w['rider']->user, 'sanctum')
            ->post("/api/worker/parcel-orders/{$order->id}/safety-photos", [
                'photo' => UploadedFile::fake()->image('x.jpg'), 'stage' => 'before_packing', 'concerns' => ['item'],
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->actingAs($w['rider']->user, 'sanctum')
            ->postJson("/api/worker/parcel-orders/{$order->id}/refuse", ['reason' => 'restricted_item'])
            ->assertJsonValidationErrors(['lat', 'lng']);

        $this->actingAs($w['rider']->user, 'sanctum')
            ->postJson("/api/worker/parcel-orders/{$order->id}/refuse", ['reason' => 'restricted_item', 'lat' => 1.0, 'lng' => 1.0])
            ->assertOk()
            ->assertJsonPath('parcel_order.status', 'cancelled');

        $this->actingAs($w['customer'], 'sanctum')
            ->getJson("/api/parcel-orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.refused.reason', 'it looks like it holds an item we cannot carry');
    }
}
