<?php

namespace Tests\Feature\Operations;

use App\Livewire\Bookings\Show as BookingShow;
use App\Livewire\MarketplaceOrders\Manage as MarketplaceOrdersManage;
use App\Livewire\ParcelOrders\Manage as ParcelOrdersManage;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\MarketplaceOrder;
use App\Models\ParcelOrder;
use App\Support\AdminOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Owner 2026-11-12: when a provider, rider, shop or restaurant cannot update a job or order, Super Admin or a
 * franchise admin with orders.emergency_override (own franchise only) moves it for them, with a written reason,
 * recorded in the order history (admin's name + reason) and the activity log. Services, parcel, food / shop.
 */
class EmergencyOverrideTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Notification::fake();
    }

    private function assertLogged(string $type, int $id, string $reason): void
    {
        $this->assertTrue(ActivityLog::where('subject_type', $type)->where('subject_id', $id)
            ->where('description', 'like', 'Emergency override:%')->get()
            ->contains(fn ($l) => ($l->properties['reason'] ?? null) === $reason));
    }

    // ---------------------------------------------------------------- services

    public function test_super_admin_starts_and_completes_a_job_for_the_provider_with_a_reason(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $admin = $this->makeSuperAdmin();

        $page = Livewire::actingAs($admin)->test(BookingShow::class, ['bookingId' => $s['booking']->id])
            ->assertSee('Emergency: update for the professional')
            ->call('emergencyStart');
        $this->assertSame('assigned', $s['booking']->fresh()->status, 'no reason, no change');

        $page->set('emergencyReason', 'Provider phone is dead')->call('emergencyStart');
        $this->assertSame('in_progress', $s['booking']->fresh()->status);

        $page->set('emergencyReason', 'Provider phone is dead')->call('emergencyComplete');
        $booking = $s['booking']->fresh();
        $this->assertSame('completed', $booking->status);

        $last = $booking->statusHistory()->where('status', 'completed')->first();
        $this->assertSame($admin->id, $last->changed_by);
        $this->assertStringContainsString('Provider phone is dead', $last->note);
        $this->assertStringContainsString('(emergency)', $last->note);
        $this->assertLogged(Booking::class, $booking->id, 'Provider phone is dead');
    }

    public function test_a_franchise_admin_needs_the_permission_for_their_own_franchise(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $other = $this->makeAssignedBookingScenario();

        $viewOnly = $this->makeUserWithPermission('bookings.view', 'franchise', $s['franchise']->id);
        Livewire::actingAs($viewOnly)->test(BookingShow::class, ['bookingId' => $s['booking']->id])
            ->assertDontSee('Emergency: update for the professional')
            ->set('emergencyReason', 'Provider phone is dead')->call('emergencyStart');
        $this->assertSame('assigned', $s['booking']->fresh()->status);

        $holder = $this->makeUserWithPermission(AdminOverride::PERMISSION, 'franchise', $s['franchise']->id);
        $this->assertTrue($holder->hasPermission(AdminOverride::PERMISSION, ['franchise_id' => $s['franchise']->id]));
        $this->assertFalse($holder->hasPermission(AdminOverride::PERMISSION, ['franchise_id' => $other['franchise']->id]));
    }

    // ---------------------------------------------------------------- parcel

    public function test_super_admin_marks_a_parcel_picked_up_and_delivered_for_the_rider(): void
    {
        $s = $this->makeAssignedParcelOrderScenario();
        $admin = $this->makeSuperAdmin();

        $page = Livewire::actingAs($admin)->test(ParcelOrdersManage::class)->call('viewOrder', $s['order']->id)
            ->assertSee('Emergency: update for the rider')
            ->call('emergencyPickedUp')->assertHasErrors('emergency');
        $this->assertSame('assigned', $s['order']->fresh()->status);

        $page->set('emergencyReason', 'Rider app crashed')->call('emergencyPickedUp')->assertHasNoErrors();
        $this->assertSame('picked_up', $s['order']->fresh()->status);

        $page->set('emergencyReason', 'Rider app crashed')->call('emergencyDelivered')->assertHasNoErrors();
        $order = $s['order']->fresh();
        $this->assertSame('delivered', $order->status);

        $h = $order->statusHistory()->where('status', 'delivered')->first();
        $this->assertSame($admin->id, $h->changed_by);
        $this->assertStringContainsString('Rider app crashed', $h->note);
        $this->assertLogged(ParcelOrder::class, $order->id, 'Rider app crashed');
    }

    public function test_a_parcel_assigned_by_an_admin_names_the_admin_in_the_history(): void
    {
        $s = $this->makeParcelOrderScenario('searching_worker');
        $admin = $this->makeSuperAdmin();

        app(\App\Actions\AdminAssignParcelRiderAction::class)->execute($s['order']->id, $s['rider']->id, $admin);

        $this->assertTrue($s['order']->statusHistory()->where('changed_by', $admin->id)
            ->where('note', 'like', '%assigned by admin%')->exists());
    }

    // ---------------------------------------------------------------- food / shop

    private function foodOrder(): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateMarketplaceModuleFor($franchise, \App\Support\Modules::FOOD);
        $customer = $this->makeCustomer();
        $store = $this->makeStore($franchise, $zone, null, [
            'module' => \App\Support\Modules::FOOD, 'name' => 'Annapurna Mess', 'fssai_number' => '12345678901234',
            'delivery_fee_flat' => 30, 'prep_minutes' => 20,
        ]);
        $dish = $this->makeProduct($store, ['name' => 'Veg Meals', 'price' => 100, 'stock' => 9999, 'food_type' => 'veg']);
        app(\App\Services\CartService::class)->add($customer, $dish, 1);
        $address = $this->makeAddress($customer, $franchise, $zone);
        $address->forceFill(['lat' => 1.01, 'lng' => 1.0])->save();
        $order = app(\App\Actions\CreateMarketplaceOrderAction::class)->execute([
            'store_id' => $store->id, 'customer_id' => $customer->id,
            'order_type' => 'delivery', 'payment_method' => 'cash', 'delivery_address_id' => $address->id,
        ]);
        $rider = $this->makeDeliveryRiderIn($franchise, $zone, 'parcel_rider');

        return [$order->fresh(), $franchise, $rider];
    }

    public function test_food_status_changes_need_the_permission_and_a_reason_and_name_the_admin(): void
    {
        [$order, $franchise] = $this->foodOrder();

        // "View orders" alone used to be enough to change an order; it no longer is.
        $viewer = $this->makeUserWithPermission('marketplace_orders.view', 'franchise', $franchise->id);
        Livewire::actingAs($viewer)->test(MarketplaceOrdersManage::class)->call('viewOrder', $order->id)
            ->set('emergencyReason', 'Tablet broken')->call('advance', 'accepted')->assertForbidden();
        $this->assertSame('pending', $order->fresh()->status);

        $admin = $this->makeSuperAdmin();
        $page = Livewire::actingAs($admin)->test(MarketplaceOrdersManage::class)->call('viewOrder', $order->id)
            ->call('advance', 'accepted')->assertHasErrors('action');
        $this->assertSame('pending', $order->fresh()->status);

        $page->set('emergencyReason', 'Restaurant tablet broken')->call('advance', 'accepted')->assertHasNoErrors();
        $h = $order->statusHistory()->where('status', 'accepted')->first();
        $this->assertSame($admin->id, $h->changed_by);
        $this->assertStringContainsString('Restaurant tablet broken', $h->note);
        $this->assertLogged(MarketplaceOrder::class, $order->id, 'Restaurant tablet broken');
    }

    public function test_admin_assigns_a_rider_directly_and_marks_the_order_delivered(): void
    {
        [$order, , $rider] = $this->foodOrder();
        foreach (['accepted', 'preparing', 'ready'] as $to) {
            app(\App\Actions\AdvanceMarketplaceOrderAction::class)->execute($order->id, $to, $to === 'accepted' ? 15 : null);
        }
        $admin = $this->makeSuperAdmin();

        $page = Livewire::actingAs($admin)->test(MarketplaceOrdersManage::class)->call('viewOrder', $order->id)
            ->assertSee('Assign now')
            ->set('emergencyReason', 'No rider accepting, rush hour')->call('assignRiderNow', $rider->id)->assertHasNoErrors();
        $this->assertSame($rider->id, $order->fresh()->assigned_worker_id, 'assigned without the rider accepting');

        $page->set('emergencyReason', 'Rider phone switched off')->call('emergencyDelivered')->assertHasNoErrors();
        $order = $order->fresh();
        $this->assertSame('completed', $order->status);
        $this->assertSame($admin->id, $order->statusHistory()->where('status', 'completed')->first()->changed_by);
        $this->assertLogged(MarketplaceOrder::class, $order->id, 'No rider accepting, rush hour');
        $this->assertLogged(MarketplaceOrder::class, $order->id, 'Rider phone switched off');
    }
}
