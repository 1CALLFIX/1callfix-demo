<?php

namespace Tests\Feature\Housekeeping;

use App\Livewire\Auth\Login as AdminLogin;
use App\Livewire\Rider\Auth\Login as RiderLogin;
use App\Livewire\Seller\Auth\Login as SellerLogin;
use App\Models\FieldWorker;
use App\Models\Product;
use App\Models\Provider;
use App\Models\RoleAssignment;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/** Owner request 2026-10-21: one test login per account type, one shared password, switched off before launch. */
class TestAccountsCommandTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private const PASSWORD = 'test-pass-123';

    public function test_create_makes_one_working_login_per_type_and_is_safe_to_rerun(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();

        $this->artisan('test-accounts', ['action' => 'create', '--password' => self::PASSWORD, '--franchise' => $franchise->id])->assertSuccessful();
        $this->artisan('test-accounts', ['action' => 'create', '--password' => self::PASSWORD, '--franchise' => $franchise->id])->assertSuccessful();

        $this->assertSame(10, User::where('email', 'like', '%@1callfix.test')->count());
        $operator = User::where('email', 'test.operator@1callfix.test')->first();
        $this->assertSame(1, RoleAssignment::where('user_id', $operator->id)->count());
        $this->assertTrue($operator->hasPermission('bookings.reassign', ['franchise_id' => $franchise->id]));
        $this->assertFalse($operator->hasPermission('settings.manage'));
        $this->assertTrue(Provider::where('user_id', User::where('email', 'test.provider@1callfix.test')->value('id'))->where('kyc_status', 'approved')->exists());
        $this->assertFalse(User::where('email', 'like', '%@1callfix.test')->where('role', 'super_admin')->exists());

        Livewire::test(AdminLogin::class)
            ->set('email', 'test.operator@1callfix.test')
            ->set('password', self::PASSWORD)
            ->call('submit')
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_disable_shuts_every_test_login(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        $this->artisan('test-accounts', ['action' => 'create', '--password' => self::PASSWORD, '--franchise' => $franchise->id])->assertSuccessful();

        $this->artisan('test-accounts', ['action' => 'disable'])->assertSuccessful();

        $this->assertSame(0, User::where('email', 'like', '%@1callfix.test')->where('status', '!=', 'suspended')->count());
        $this->assertSame(0, RoleAssignment::whereIn('user_id', User::where('email', 'like', '%@1callfix.test')->pluck('id'))->count());

        auth()->logout();
        Livewire::test(AdminLogin::class)
            ->set('email', 'test.operator@1callfix.test')
            ->set('password', self::PASSWORD)
            ->call('submit')
            ->assertNoRedirect();
    }

    /** Owner thumb rule 2026-10-26: every module gets a test login for a real walk-through; parcel's is the rider. */
    public function test_create_makes_an_approved_test_rider_who_can_sign_in_and_disable_turns_it_off(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        $this->artisan('test-accounts', ['action' => 'create', '--password' => self::PASSWORD, '--franchise' => $franchise->id])->assertSuccessful();
        $this->artisan('test-accounts', ['action' => 'create', '--password' => self::PASSWORD, '--franchise' => $franchise->id])->assertSuccessful();

        $rider = FieldWorker::whereHas('user', fn ($q) => $q->where('email', 'test.rider@1callfix.test'))->sole();
        $this->assertSame('approved', $rider->kyc_status);
        $this->assertTrue($rider->is_active);
        $this->assertSame($franchise->id, $rider->franchise_id);
        $this->assertSame(1, $rider->capabilities()->where('capability_type', 'parcel_rider')->count());
        $this->assertFalse(Provider::where('user_id', $rider->user_id)->exists(), 'a rider is never a provider');
        $this->assertSame(1, app(\App\Services\Parcel\ParcelReadiness::class)->riderCount('franchise', $franchise->id));

        Livewire::test(RiderLogin::class)->set('identifier', 'test.rider@1callfix.test')->set('password', self::PASSWORD)
            ->call('login')->assertRedirect(route('rider.dashboard'));

        auth()->logout();
        $this->artisan('test-accounts', ['action' => 'disable'])->assertSuccessful();
        $this->assertFalse($rider->fresh()->is_active);
        $this->assertSame(0, app(\App\Services\Parcel\ParcelReadiness::class)->riderCount('franchise', $franchise->id));
    }

    /** Thumb rule for E-commerce Phase 1: the seller login owns an approved, open shop with a product; disable shuts it. */
    public function test_create_makes_a_test_seller_with_an_open_shop_who_can_sign_in_and_disable_turns_it_off(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        $this->artisan('test-accounts', ['action' => 'create', '--password' => self::PASSWORD, '--franchise' => $franchise->id])->assertSuccessful();
        $this->artisan('test-accounts', ['action' => 'create', '--password' => self::PASSWORD, '--franchise' => $franchise->id])->assertSuccessful();

        $seller = User::where('email', 'test.seller@1callfix.test')->sole();
        $store = Store::whereHas('provider', fn ($q) => $q->where('user_id', $seller->id))->sole();
        $this->assertTrue($store->isSellable());
        $this->assertTrue($store->is_open);
        $this->assertSame('test-shop', $store->slug, 'clean slug, no random suffix');
        $this->assertSame(8, Product::where('store_id', $store->id)->count(), 'one product per shop option; safe to re-run');
        $this->assertSame(6, Product::where('store_id', $store->id)->where('is_active', true)->where('is_approved', true)->count());
        $this->assertEquals([99, 30, 499], [(float) $store->minimum_order_amount, (float) $store->delivery_fee_flat, (float) $store->free_delivery_above_amount]);
        $byName = Product::where('store_id', $store->id)->get()->keyBy('name');
        $this->assertEquals(20, (float) $byName['Test Discount Item']->discount_percent);
        $this->assertSame(0, (int) $byName['Test Out of Stock Item']->stock);
        $this->assertSame(2, (int) $byName['Test Limit 2 Item']->maximum_cart_quantity);
        $this->assertEquals(18, (float) $byName['Test 18% GST Item']->tax_percent);
        $this->assertFalse($byName['Test Awaiting Approval Item']->is_approved);
        $this->assertSame('test-discount-item', $byName['Test Discount Item']->slug, 'clean product slug');

        // After field test orders, re-running create refills the stock; a product the seller added is left alone.
        $byName['Test Low Stock Item']->update(['stock' => 0]);
        Product::create(['store_id' => $store->id, 'name' => 'Seller Own Item', 'price' => 10, 'stock' => 1, 'is_active' => true, 'is_approved' => true]);
        $this->artisan('test-accounts', ['action' => 'create', '--password' => self::PASSWORD, '--franchise' => $franchise->id])->assertSuccessful();
        $this->assertSame(3, (int) $byName['Test Low Stock Item']->fresh()->stock);
        $this->assertSame(9, Product::where('store_id', $store->id)->count());
        $this->assertTrue($seller->holdsPartnerRole('commerce'));
        $this->assertFalse($seller->holdsPartnerRole('service'), 'a shop-only seller is not offered service jobs');

        $rider = FieldWorker::whereHas('user', fn ($q) => $q->where('email', 'test.rider@1callfix.test'))->sole();
        $this->assertTrue($rider->hasCapability('commerce_delivery_rider'), 'the test rider takes shop deliveries too');

        Livewire::test(SellerLogin::class)->set('identifier', 'test.seller@1callfix.test')->set('password', self::PASSWORD)
            ->call('login')->assertRedirect(route('seller.dashboard'));
        $this->actingAs($seller)->get(route('seller.dashboard'))->assertOk()->assertSee('Test Shop');

        auth()->logout();
        $this->artisan('test-accounts', ['action' => 'disable'])->assertSuccessful();
        $this->assertFalse($store->fresh()->is_active);
        Livewire::test(SellerLogin::class)->set('identifier', 'test.seller@1callfix.test')->set('password', self::PASSWORD)
            ->call('login')->assertNoRedirect()->assertSet('error', 'This account has been suspended. Contact support for help.');
    }

    /** Thumb rule walk-through for Admin → Partners: the franchise login reviews its own city's partners. */
    public function test_the_franchise_test_login_can_review_its_own_partners_and_the_operator_cannot(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        $this->artisan('test-accounts', ['action' => 'create', '--password' => self::PASSWORD, '--franchise' => $franchise->id])->assertSuccessful();
        $rider = FieldWorker::whereHas('user', fn ($q) => $q->where('email', 'test.rider@1callfix.test'))->sole();

        $owner = User::where('email', 'test.franchise@1callfix.test')->sole();
        $this->assertTrue($owner->hasPermission('workers.review_kyc', ['franchise_id' => $franchise->id]));
        $this->assertTrue($owner->hasPermission('providers.review_kyc', ['franchise_id' => $franchise->id]));
        $this->actingAs($owner)->get(route('admin.partners.index', ['tab' => 'people', 'statusFilter' => 'approved']))
            ->assertOk()->assertSee('Test Rider');
        $this->get(route('admin.workers.show', $rider->id))->assertOk();

        $operator = User::where('email', 'test.operator@1callfix.test')->sole();
        $this->assertFalse($operator->hasPermission('workers.review_kyc', ['franchise_id' => $franchise->id]), 'franchise owner only');
    }

    public function test_create_refuses_a_missing_or_short_password(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();

        $this->artisan('test-accounts', ['action' => 'create', '--password' => 'short', '--franchise' => $franchise->id])->assertFailed();
        $this->assertSame(0, User::where('email', 'like', '%@1callfix.test')->count());
    }
}
