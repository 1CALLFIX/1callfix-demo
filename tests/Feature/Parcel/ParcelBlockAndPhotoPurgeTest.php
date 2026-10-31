<?php

namespace Tests\Feature\Parcel;

use App\Actions\CreateParcelOrderAction;
use App\Livewire\Customers\Show as AdminCustomerShow;
use App\Models\ParcelOrder;
use App\Models\ParcelProtectionClaim;
use App\Models\ParcelSafetyPhoto;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/** Parcel Stage 2 — admin "Block" on the customer page stops parcel sending; old safety photos are deleted. */
class ParcelBlockAndPhotoPurgeTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function world(): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateParcelFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);
        $rider = $this->makeParcelRiderIn($franchise, $zone);

        return compact('franchise', 'zone', 'customer', 'pickup', 'dropoff', 'rider');
    }

    private function create(array $w): ParcelOrder
    {
        return app(CreateParcelOrderAction::class)->execute([
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $w['customer']->id,
            'pickup_address_id' => $w['pickup']->id, 'dropoff_address_id' => $w['dropoff']->id,
            'recipient_phone' => '9876543210', 'payment_method' => 'cash', 'restricted_items_confirmed' => true,
        ]);
    }

    public function test_admin_blocks_a_sender_and_allows_them_again(): void
    {
        $w = $this->world();
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(AdminCustomerShow::class, ['customerId' => $w['customer']->id])
            ->assertSee('Send parcels')->call('toggleParcelBlock')->assertSet('flashMessage', 'Customer can no longer send parcels.');
        $this->assertNotNull($w['customer']->fresh()->parcel_blocked_at);
        $this->assertSame('active', $w['customer']->fresh()->status, 'Only parcel sending is blocked, not the account.');

        try {
            $this->create($w);
            $this->fail('A blocked sender must not create a parcel order.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not available on your account', $e->getMessage());
        }
        $this->assertSame(0, ParcelOrder::count());

        Livewire::actingAs($admin)->test(AdminCustomerShow::class, ['customerId' => $w['customer']->id])->call('toggleParcelBlock');
        $this->assertNull($w['customer']->fresh()->parcel_blocked_at);
        $this->create($w);
        $this->assertSame(1, ParcelOrder::count());
    }

    public function test_blocked_sender_is_refused_through_the_api_too(): void
    {
        $w = $this->world();
        $w['customer']->forceFill(['parcel_blocked_at' => now()])->save();

        $res = $this->actingAs($w['customer'], 'sanctum')->postJson('/api/parcel-orders', [
            'pickup_address_id' => $w['pickup']->id, 'dropoff_address_id' => $w['dropoff']->id,
            'recipient_phone' => '9876543210', 'payment_method' => 'cash', 'restricted_items_confirmed' => true,
            'package_size' => 'small',
        ]);
        $this->assertGreaterThanOrEqual(400, $res->status());
        $this->assertSame(0, ParcelOrder::count());
    }

    private function photo(ParcelOrder $order, $rider): ParcelSafetyPhoto
    {
        $path = "parcel-safety/{$order->id}/p.jpg";
        Storage::disk('local')->put($path, 'x');

        return ParcelSafetyPhoto::create(['parcel_order_id' => $order->id, 'field_worker_id' => $rider->id, 'stage' => 'pickup', 'concerns' => ['other'], 'disk_path' => $path, 'taken_at' => now()]);
    }

    public function test_old_safety_photos_are_deleted_only_when_the_setting_is_on_and_no_claim_is_open(): void
    {
        Storage::fake('local');
        $w = $this->world();
        $old = $this->create($w);
        $claimed = $this->create($w);
        $recent = $this->create($w);
        foreach ([$old, $claimed, $recent] as $o) {
            $this->photo($o, $w['rider']);
        }
        ParcelOrder::whereKey([$old->id, $claimed->id])->update(['status' => 'delivered', 'updated_at' => now()->subDays(40)]);
        ParcelOrder::whereKey($recent->id)->update(['status' => 'delivered', 'updated_at' => now()->subDays(2)]);
        ParcelProtectionClaim::unguarded(fn () => ParcelProtectionClaim::create([
            'parcel_order_id' => $claimed->id, 'customer_id' => $w['customer']->id, 'reason' => 'damaged',
            'description' => 'broken', 'amount_claimed' => 100, 'photos' => [], 'status' => 'requested',
        ]));

        // Unset: nothing deleted.
        $this->artisan('parcel:purge-safety-photos')->assertSuccessful();
        $this->assertSame(3, ParcelSafetyPhoto::count());

        Setting::set('parcel.safety_photo_keep_days', '30');
        $this->artisan('parcel:purge-safety-photos')->assertSuccessful();

        $this->assertFalse(ParcelSafetyPhoto::where('parcel_order_id', $old->id)->exists());
        Storage::disk('local')->assertMissing("parcel-safety/{$old->id}/p.jpg");
        $this->assertTrue(ParcelSafetyPhoto::where('parcel_order_id', $claimed->id)->exists(), 'Kept while a claim is open.');
        $this->assertTrue(ParcelSafetyPhoto::where('parcel_order_id', $recent->id)->exists(), 'Kept until the days pass.');
    }
}
