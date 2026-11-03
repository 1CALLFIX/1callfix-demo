<?php

namespace Tests\Feature\Marketplace;

use App\Livewire\Customer\Shop\ProductShow;
use App\Livewire\MarketplaceOrders\ChangeApprovals;
use App\Livewire\Seller\Products;
use App\Livewire\Seller\Shop;
use App\Models\ActivityLog;
use App\Models\PendingChange;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\Feature\Support\RebuiltAuthHelpers;
use Tests\TestCase;

/**
 * Owner rule set 2026-11-03 (after comparing Amazon, Flipkart, Swiggy, Zomato), for every item and every shop:
 *  - price, discount, stock, on/off change at once; a NEW item waits for its first approval;
 *  - a live item's name, photos, description, category wait for approval while customers keep seeing the old ones;
 *  - a shop's address, map pin, GSTIN, PAN wait for approval; the shop name is admin-only; the mobile number changes
 *    only after an OTP to the new number;
 *  - approve = the new details replace the old; reject (reason required) = nothing changes; scoped, audit-logged.
 */
class PendingChangesTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use RbacTestHelpers;
    use RebuiltAuthHelpers;
    use RefreshDatabase;

    private array $w;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->bindFakeFirebase();
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $city->update(['slug' => 'nellore']);
        $this->activateMarketplaceModuleFor($franchise);
        $owner = $this->makeStoreOwner($franchise, $zone);
        $store = $this->makeStore($franchise, $zone, $owner, ['name' => 'Lakshmi Stores', 'address_line' => 'Old street 1', 'phone' => '9876500001']);
        Storage::disk('public')->put('products/'.$store->id.'/old.jpg', 'x');
        $product = $this->makeProduct($store, ['name' => 'Steel Bottle', 'price' => 200, 'stock' => 5, 'images' => ['products/'.$store->id.'/old.jpg']]);
        $this->w = compact('franchise', 'zone', 'owner', 'store', 'product');
    }

    private function seller()
    {
        return Livewire::actingAs($this->w['owner']->user)->test(Products::class);
    }

    private function admin()
    {
        return Livewire::actingAs($this->makeSuperAdmin())->test(ChangeApprovals::class);
    }

    /** Seller renames, adds a photo, drops the old one, and changes price and stock in one save. */
    private function bigEdit()
    {
        return $this->seller()->call('edit', $this->w['product']->id)
            ->set('name', 'Steel Water Bottle 1L')->set('price', '220')->set('stock', '9')
            ->call('removePhoto', 0)->set('newUploads', [UploadedFile::fake()->image('new.jpg', 300, 300)])
            ->call('save')->assertHasNoErrors();
    }

    public function test_price_and_stock_go_live_at_once_and_the_new_name_and_photo_wait_while_the_item_stays_on_sale(): void
    {
        $this->bigEdit()->assertSee('Price and stock are live now. The new name, photos show to customers once approved');

        $p = $this->w['product']->fresh();
        $this->assertEquals(220, $p->price);
        $this->assertSame(9, (int) $p->stock);
        $this->assertSame('Steel Bottle', $p->name, 'customers keep the old name');
        $this->assertSame(['products/'.$this->w['store']->id.'/old.jpg'], $p->images, 'and the old photo');
        $this->assertTrue($p->is_approved, 'never off sale');
        Storage::disk('public')->assertExists('products/'.$this->w['store']->id.'/old.jpg');

        $change = PendingChange::sole();
        $this->assertSame(['name', 'images'], array_keys($change->new_values));
        $this->assertSame('Steel Bottle', $change->old_values['name']);

        // The shop page still shows it, with the old name.
        Livewire::test(ProductShow::class, ['city' => 'nellore', 'storeSlug' => $this->w['store']->slug, 'productSlug' => $p->slug])
            ->assertSee('Steel Bottle')->assertDontSee('Steel Water Bottle 1L');

        // The seller's list says so, and reopening the form shows what they asked for.
        $this->seller()->assertSee('New name, photos waiting for approval')
            ->call('edit', $p->id)->assertSet('name', 'Steel Water Bottle 1L')->assertSee('waiting for approval (shown below)');
    }

    public function test_approve_puts_the_new_details_live_and_removes_the_old_photo(): void
    {
        $this->bigEdit();
        $change = PendingChange::sole();
        $newPhoto = $change->new_values['images'][0];

        $this->admin()->assertSee('Changes to approve')->assertSee('Steel Water Bottle 1L')->assertSee('Waiting (1)')
            ->call('approve', $change->id)->assertSee('Approved.');

        $p = $this->w['product']->fresh();
        $this->assertSame('Steel Water Bottle 1L', $p->name);
        $this->assertSame([$newPhoto], $p->images);
        Storage::disk('public')->assertMissing('products/'.$this->w['store']->id.'/old.jpg');
        $this->assertSame(PendingChange::APPROVED, $change->fresh()->status);
        $this->assertTrue(ActivityLog::where('description', 'Approved change')->exists());

        // Decided once: a second approve is refused.
        $this->admin()->set('tab', 'waiting')->call('approve', $change->id)->assertSee('already decided');
    }

    public function test_reject_needs_a_reason_changes_nothing_and_the_seller_sees_why(): void
    {
        $this->bigEdit();
        $change = PendingChange::sole();
        $newPhoto = $change->new_values['images'][0];

        $this->admin()->call('reject', $change->id)->assertSee('Write a short reason');
        $this->assertSame(PendingChange::PENDING, $change->fresh()->status);

        $this->admin()->set('notes.'.$change->id, 'Photo is blurry')->call('reject', $change->id)->assertSee('Rejected.');
        $p = $this->w['product']->fresh();
        $this->assertSame('Steel Bottle', $p->name);
        $this->assertEquals(220, $p->price, 'the price change stays: it never waited');
        Storage::disk('public')->assertMissing($newPhoto);
        Storage::disk('public')->assertExists('products/'.$this->w['store']->id.'/old.jpg');

        $this->seller()->assertSee('Change not approved: Photo is blurry');
    }

    public function test_a_new_save_replaces_the_waiting_change_and_going_back_withdraws_it(): void
    {
        $this->seller()->call('edit', $this->w['product']->id)->set('name', 'Bottle A')->call('save');
        $this->seller()->call('edit', $this->w['product']->id)->set('name', 'Bottle B')->call('save');
        $this->assertSame(1, PendingChange::pending()->count());
        $this->assertSame('Bottle B', PendingChange::pending()->sole()->new_values['name']);

        $this->seller()->call('edit', $this->w['product']->id)->set('name', 'Steel Bottle')->call('save')->assertSee('Saved.');
        $this->assertSame(0, PendingChange::pending()->count(), 'back to the live name: nothing left to approve');
    }

    public function test_a_new_item_and_a_shop_without_approval_switch_are_edited_freely(): void
    {
        $this->w['product']->update(['is_approved' => false]);
        $this->seller()->call('edit', $this->w['product']->id)->set('name', 'Brand new name')->call('save');
        $this->assertSame('Brand new name', $this->w['product']->fresh()->name);
        $this->assertSame(0, PendingChange::count());

        $this->w['product']->update(['is_approved' => true]);
        Setting::updateOrCreate(['key' => 'marketplace.seller_products_need_approval', 'scope_type' => 'global', 'scope_id' => null], ['value' => '0']);
        $this->seller()->call('edit', $this->w['product']->id)->set('name', 'Switch off name')->call('save');
        $this->assertSame('Switch off name', $this->w['product']->fresh()->name);
        $this->assertSame(0, PendingChange::count());
    }

    public function test_shop_address_and_gstin_wait_while_fees_change_at_once(): void
    {
        $shop = Livewire::actingAs($this->w['owner']->user)->test(Shop::class)
            ->set('addressLine', 'New street 22, Nellore')->set('gstin', '37ABCDE1234F1Z5')->set('deliveryFee', '25')->call('save')
            ->assertHasNoErrors()->assertSee('will be used once 1CallFix approves it');

        $store = $this->w['store']->fresh();
        $this->assertEquals(25, $store->delivery_fee_flat);
        $this->assertSame('Old street 1', $store->address_line, 'riders keep the old address');
        $this->assertNull($store->gstin);
        $change = PendingChange::sole();
        $this->assertEqualsCanonicalizing(['address_line', 'gstin'], array_keys($change->new_values));

        // Saving the form again (another fee) keeps the waiting change: the form shows it.
        Livewire::actingAs($this->w['owner']->user)->test(Shop::class)->assertSet('addressLine', 'New street 22, Nellore')
            ->assertSee('waiting for approval')->set('deliveryFee', '20')->call('save');
        $this->assertSame(1, PendingChange::pending()->count());

        $this->admin()->call('approve', PendingChange::pending()->sole()->id);
        $this->assertSame('New street 22, Nellore', $this->w['store']->fresh()->address_line);
        $this->assertSame('37ABCDE1234F1Z5', $this->w['store']->fresh()->gstin);
    }

    public function test_the_shop_mobile_number_changes_only_after_an_otp_to_the_new_number(): void
    {
        $shop = Livewire::actingAs($this->w['owner']->user)->test(Shop::class)->assertSee('Change number')
            ->set('phoneStep', 'number')->set('newPhone', '9876500099')->call('sendPhoneCode')
            ->assertDispatched('firebase-send-phone-otp')->assertSet('phoneStep', 'code');
        $this->assertSame('9876500001', $this->w['store']->fresh()->phone, 'nothing changes before the code');

        // A code for some other number is refused.
        $shop->dispatch('firebase-phone-token', idToken: $this->firebase->issuePhoneToken('+919876511111'))
            ->assertSee('does not match the new number');
        $this->assertSame('9876500001', $this->w['store']->fresh()->phone);

        $shop->dispatch('firebase-phone-token', idToken: $this->firebase->issuePhoneToken('+919876500099'))
            ->assertSee('Shop mobile number changed to 9876500099');
        $this->assertSame('9876500099', $this->w['store']->fresh()->phone);
        $this->assertTrue(ActivityLog::where('description', 'like', 'Seller changed the shop mobile number%')->exists());
    }

    public function test_an_admin_of_another_franchise_cannot_see_or_decide_the_change(): void
    {
        $this->bigEdit();
        $change = PendingChange::sole();
        [, , $otherFranchise] = $this->makeFranchiseTree();
        $other = $this->makeUserWithPermission('products.manage', 'franchise', $otherFranchise->id);

        Livewire::actingAs($other)->test(ChangeApprovals::class)->assertDontSee('Steel Water Bottle 1L')->assertSee('Waiting (0)')
            ->call('approve', $change->id)->assertNotFound();
        $this->assertSame(PendingChange::PENDING, $change->fresh()->status);

        $own = $this->makeUserWithPermission('products.manage', 'franchise', $this->w['franchise']->id);
        Livewire::actingAs($own)->test(ChangeApprovals::class)->assertSee('Steel Water Bottle 1L')->call('approve', $change->id);
        $this->assertSame(PendingChange::APPROVED, $change->fresh()->status);

        $this->actingAs($this->makeUserWithNoPermissions())->get(route('admin.change-approvals.index'))->assertForbidden();
    }
}
