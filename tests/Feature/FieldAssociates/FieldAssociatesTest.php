<?php

namespace Tests\Feature\FieldAssociates;

use App\Actions\CompleteMarketplaceOrderAction;
use App\Actions\CreateMarketplaceOrderAction;
use App\Actions\RegisterSellerAction;
use App\Http\Controllers\FieldAssociateLinkController;
use App\Livewire\Customer\Account\FieldWork;
use App\Livewire\FieldAssociates\Manage;
use App\Livewire\Partner\ApplyForm;
use App\Livewire\Provider\Auth\Register;
use App\Livewire\Stores\Manage as StoresManage;
use App\Models\FieldAssociate;
use App\Models\FieldAssociateReward;
use App\Models\MarketplaceOrder;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Services\CartService;
use App\Services\FieldAssociates\FieldAssociateTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\Feature\Support\RebuiltAuthHelpers;
use Tests\TestCase;

/**
 * Field associates (owner 2026-11-23): people who visit shops and restaurants and sign them up. Proven here: the link /
 * QR credits the next shop signed up in that browser (and signs out whoever was signed in); an associate is never
 * credited for their own shop; each shop's 5-step progress; "shop live" and "first order" rewards written once, with
 * the amount at that time, never for the owner's or the associate's own order; the admin screen (add, pause, fix a
 * shop's credit, monthly sheet, Super-Admin-only rewards, franchise scope) and the associate's own page.
 */
class FieldAssociatesTest extends TestCase
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
        Storage::fake('local');
        Storage::fake('public');
        $this->bindFakeFirebase();
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $zone->update(['center_lat' => 14.44, 'center_lng' => 79.98]);
        $this->activateMarketplaceModuleFor($franchise);
        $this->w = ['franchise' => $franchise, 'zone' => $zone->fresh()];
    }

    private function associate(string $name = 'Ravi Kumar', ?int $franchiseId = null): FieldAssociate
    {
        $user = User::create(['uuid' => (string) Str::uuid(), 'name' => $name, 'phone' => '9'.fake()->unique()->numerify('#########'), 'role' => 'customer', 'status' => 'active']);
        $a = FieldAssociate::create(['user_id' => $user->id, 'franchise_id' => $franchiseId ?? $this->w['franchise']->id, 'code' => 'TMP'.Str::random(6), 'is_active' => true]);
        $a->update(['code' => FieldAssociate::codeFor($a->id)]);

        return $a->fresh();
    }

    /** A shop the associate signed up: pending, KYC pending, no products yet. */
    private function creditedShop(FieldAssociate $a): Store
    {
        $owner = $this->makeStoreOwner($this->w['franchise'], $this->w['zone']);
        $owner->update(['kyc_status' => 'pending']);

        return $this->makeStore($this->w['franchise'], $this->w['zone'], $owner, [
            'field_associate_id' => $a->id, 'approval_status' => 'pending', 'is_active' => false, 'approved_at' => null,
        ]);
    }

    private function completedOrder(Store $store, User $customer): MarketplaceOrder
    {
        app(CartService::class)->add($customer, $store->products()->first(), 1);
        $order = app(CreateMarketplaceOrderAction::class)->execute(['store_id' => $store->id, 'customer_id' => $customer->id, 'order_type' => 'pickup', 'payment_method' => 'cash']);
        foreach (['accepted', 'preparing', 'ready'] as $to) {
            app(\App\Actions\AdvanceMarketplaceOrderAction::class)->execute($order->id, $to);
        }

        return app(CompleteMarketplaceOrderAction::class)->execute($order->id, null, $order->fresh()->delivery_otp, $store->provider->user);
    }

    private function setRewards(float $live, float $first, int $target = 0): void
    {
        Setting::set(FieldAssociateTracker::REWARD_SHOP_LIVE, (string) $live);
        Setting::set(FieldAssociateTracker::REWARD_FIRST_ORDER, (string) $first);
        Setting::set(FieldAssociateTracker::MONTHLY_TARGET, (string) $target);
    }

    // ── The link / QR ───────────────────────────────────────────────────

    public function test_the_link_remembers_the_associate_and_opens_the_shop_sign_up(): void
    {
        $a = $this->associate();

        $this->get('/fa/'.strtolower($a->code))
            ->assertRedirect(route('customer.partners', ['role' => 'commerce']).'#formCard')
            ->assertSessionHas(FieldAssociateLinkController::SESSION_KEY, $a->id);
    }

    public function test_the_link_signs_out_whoever_is_signed_in_so_the_shop_gets_its_own_account(): void
    {
        $a = $this->associate();
        $this->actingAs($a->user);

        $this->get('/fa/'.$a->code)->assertRedirect();
        $this->assertGuest();
        $this->assertSame($a->id, session(FieldAssociateLinkController::SESSION_KEY));
    }

    public function test_a_paused_or_unknown_code_opens_the_partner_page_without_credit(): void
    {
        $a = $this->associate();
        $a->update(['is_active' => false]);

        $this->get('/fa/'.$a->code)->assertRedirect()->assertSessionMissing(FieldAssociateLinkController::SESSION_KEY);
        $this->get('/fa/NOPE99')->assertRedirect()->assertSessionMissing(FieldAssociateLinkController::SESSION_KEY);
    }

    public function test_a_shop_signed_up_through_the_link_is_credited_and_the_form_names_the_associate(): void
    {
        Setting::set('marketplace.recruiting_sellers', '1');
        $a = $this->associate('Ravi Kumar');
        $this->get('/fa/'.$a->code);

        Livewire::test(ApplyForm::class)->set('role', 'commerce')->set('name', 'Lakshmi')->set('phone', '9876500013')
            ->set('cityChoice', '__other')->set('city', 'Nellore')->set('consent', true)->call('submit')->assertSet('outcome', 'handoff');

        Livewire::test(Register::class)->assertSet('partnerRole', 'commerce')
            ->assertSet('fieldAssociateLabel', "Ravi Kumar ({$a->code})")->assertSeeHtml('data-testid="field-associate-badge"')
            ->set('phone', '9876500013')->call('requestPhoneCode')
            ->call('phoneTokenReceived', $this->firebase->issuePhoneToken($this->e164('9876500013')))
            ->call('useCurrentLocationForNewAddress', 14.44, 79.98)
            ->set('name', 'Lakshmi')->set('password', 'shop-pass-1')->set('password_confirmation', 'shop-pass-1')
            ->set('address', '12 Main Bazaar, Nellore')->set('terms', true)->set('storeName', 'Lakshmi General Stores')
            ->set('documents.id_proof', UploadedFile::fake()->image('id.jpg'))
            ->set('documents.bank_details', UploadedFile::fake()->create('bank.pdf', 120, 'application/pdf'))
            ->call('submitApplication')->assertHasNoErrors()->assertSet('submitted', true);

        $this->assertSame($a->id, Store::where('name', 'Lakshmi General Stores')->sole()->field_associate_id);
        $this->assertNull(session(FieldAssociateLinkController::SESSION_KEY), 'one link visit credits one shop');
    }

    public function test_a_sign_up_without_the_link_is_not_credited(): void
    {
        $store = app(RegisterSellerAction::class)->execute($this->w['zone'], 'Plain Shop', null, ['address' => '1 Road'], 'Owner', '9876500020');

        $this->assertNull($store->field_associate_id);
    }

    public function test_an_associate_is_never_credited_for_their_own_shop_or_while_paused(): void
    {
        $a = $this->associate();
        $own = app(RegisterSellerAction::class)->execute($this->w['zone'], 'My Own Shop', $a->user, ['address' => '1 Road'], fieldAssociateId: $a->id);
        $this->assertNull($own->field_associate_id);

        $a->update(['is_active' => false]);
        $paused = app(RegisterSellerAction::class)->execute($this->w['zone'], 'Paused Shop', null, ['address' => '2 Road'], 'Owner', '9876500021', fieldAssociateId: $a->id);
        $this->assertNull($paused->field_associate_id);
    }

    // ── Progress and rewards ────────────────────────────────────────────

    public function test_the_progress_bar_and_rewards_follow_the_shop_from_sign_up_to_its_first_real_order(): void
    {
        $this->setRewards(200, 300);
        $a = $this->associate();
        $store = $this->creditedShop($a);
        $tracker = app(FieldAssociateTracker::class);
        $progress = fn () => $tracker->shops($a->fresh())->first()['progress'];

        $this->assertSame(20, $progress()['percent']);
        $this->assertSame('Next: add products', $progress()['next']);

        $this->makeProduct($store, ['price' => 150, 'stock' => 10]);
        $this->assertSame(40, $progress()['percent']);
        $this->assertSame('Next: KYC approval by the office', $progress()['next']);

        $store->provider->update(['kyc_status' => 'approved']);
        $this->assertSame(60, $progress()['percent']);

        Livewire::actingAs($this->makeSuperAdmin())->test(StoresManage::class)->call('approveStore', $store->id);
        $live = FieldAssociateReward::where('store_id', $store->id)->where('kind', 'shop_live')->sole();
        $this->assertEquals(200, $live->amount);
        $this->assertSame(80, $progress()['percent']);
        $this->assertSame('Next: first order from a customer', $progress()['next']);

        // The shop owner's own order and the associate's own order never count as the first order.
        $this->completedOrder($store->fresh(), $store->provider->user);
        $this->completedOrder($store->fresh(), $a->user);
        $this->assertFalse(FieldAssociateReward::where('store_id', $store->id)->where('kind', 'first_order')->exists());

        $this->completedOrder($store->fresh(), $this->makeCustomer());
        $this->assertEquals(300, FieldAssociateReward::where('store_id', $store->id)->where('kind', 'first_order')->sole()->amount);
        $this->assertSame(100, $progress()['percent']);
        $this->assertSame('All done 🎉', $progress()['next']);

        $month = $tracker->month($a, now());
        $this->assertSame(['signed_up' => 1, 'live' => 1, 'live_amount' => 200.0, 'first_orders' => 1, 'first_order_amount' => 300.0, 'earned' => 500.0], $month);

        // A second order never pays again.
        $this->completedOrder($store->fresh(), $this->makeCustomer());
        $this->assertSame(2, FieldAssociateReward::where('store_id', $store->id)->count());
    }

    public function test_a_reward_keeps_the_amount_it_was_earned_at_when_the_amount_changes_later(): void
    {
        $this->setRewards(200, 300);
        $a = $this->associate();
        $store = $this->creditedShop($a);
        $store->update(['approval_status' => 'approved', 'approved_at' => now(), 'is_active' => true]);
        app(FieldAssociateTracker::class)->syncAssociate($a);

        $this->setRewards(999, 999);
        app(FieldAssociateTracker::class)->syncAssociate($a);

        $this->assertEquals(200, FieldAssociateReward::where('store_id', $store->id)->sole()->amount);
    }

    public function test_a_rejected_shop_says_so_and_earns_nothing(): void
    {
        $this->setRewards(200, 300);
        $a = $this->associate();
        $store = $this->creditedShop($a);
        $store->update(['approval_status' => 'rejected', 'approval_note' => 'Shop photo is blurred']);

        $row = app(FieldAssociateTracker::class)->shops($a)->first();
        $this->assertTrue($row['progress']['rejected']);
        $this->assertSame('Not approved: Shop photo is blurred', $row['progress']['next']);
        $this->assertSame(0, FieldAssociateReward::count());
    }

    // ── Admin screen ────────────────────────────────────────────────────

    public function test_super_admin_sets_rewards_and_adds_associates_with_a_short_code(): void
    {
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('rewardShopLive', '150')->set('rewardFirstOrder', '250')->set('monthlyTarget', '20')->call('saveRewards')->assertHasNoErrors();
        $this->assertSame(150.0, FieldAssociateTracker::rewardShopLive());
        $this->assertSame(250.0, FieldAssociateTracker::rewardFirstOrder());
        $this->assertSame(20, FieldAssociateTracker::monthlyTarget());

        $c = Livewire::actingAs($admin)->test(Manage::class)
            ->set('name', 'Suresh')->set('phone', '98765 43210')->set('franchiseId', $this->w['franchise']->id)->call('addAssociate')->assertHasNoErrors();
        $a = FieldAssociate::with('user')->sole();
        $this->assertSame(FieldAssociate::codeFor($a->id), $a->code);
        $this->assertSame('9876543210', $a->user->phone);
        $this->assertSame('customer', $a->user->role);
        $c->assertSee($a->code);

        // The same number twice, and a staff number, are refused.
        Livewire::actingAs($admin)->test(Manage::class)
            ->set('name', 'Suresh')->set('phone', '9876543210')->set('franchiseId', $this->w['franchise']->id)->call('addAssociate')->assertHasErrors('phone');
        Livewire::actingAs($admin)->test(Manage::class)
            ->set('name', 'Boss')->set('phone', $admin->phone ?? '9000000001')->set('franchiseId', $this->w['franchise']->id)->call('addAssociate');
        $this->assertSame(1, FieldAssociate::count());
    }

    public function test_a_franchise_admin_sees_only_their_own_associates_and_cannot_change_rewards(): void
    {
        [, , $other] = $this->makeFranchiseTree();
        $mine = $this->associate('Mine');
        $theirs = $this->associate('Theirs', $other->id);
        $admin = $this->makeUserWithPermission('field_associates.manage', 'franchise', $this->w['franchise']->id);

        Livewire::actingAs($admin)->test(Manage::class)->assertSee($mine->code)->assertDontSee($theirs->code)
            ->assertDontSeeHtml('data-testid="fa-rewards"')
            ->call('saveRewards')->assertForbidden();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::actingAs($admin)->test(Manage::class)->call('toggleActive', $theirs->id);
    }

    public function test_the_screen_needs_the_permission(): void
    {
        Livewire::actingAs($this->makeCustomer())->test(Manage::class)->assertForbidden();
    }

    public function test_admin_pauses_an_associate_and_fixes_a_shops_credit(): void
    {
        $this->setRewards(200, 0);
        $admin = $this->makeSuperAdmin();
        $a = $this->associate();
        $forgot = $this->makeStore($this->w['franchise'], $this->w['zone'], null, ['approved_at' => now()]);

        $c = Livewire::actingAs($admin)->test(Manage::class)->call('toggleActive', $a->id);
        $this->assertFalse($a->fresh()->is_active);

        $c->call('open', $a->id)->set('assignStoreId', $forgot->id)->call('assignStore')->assertHasNoErrors();
        $this->assertSame($a->id, $forgot->fresh()->field_associate_id);
        $this->assertSame(1, FieldAssociateReward::where('store_id', $forgot->id)->count());

        $c->call('removeStore', $forgot->id);
        $this->assertNull($forgot->fresh()->field_associate_id);
        $this->assertSame(0, FieldAssociateReward::count());
    }

    public function test_the_month_sheet_downloads_with_what_to_pay(): void
    {
        $this->setRewards(200, 300);
        $a = $this->associate();
        $store = $this->creditedShop($a);
        $store->update(['approval_status' => 'approved', 'approved_at' => now(), 'is_active' => true]);

        Livewire::actingAs($this->makeSuperAdmin())->test(Manage::class)
            ->assertSeeHtml('data-testid="fa-row-'.$a->code.'"')->assertSee('₹200')
            ->call('exportCsv')->assertFileDownloaded('field-associates-'.now()->format('Y-m').'.csv');
    }

    // ── The associate's own page ────────────────────────────────────────

    public function test_the_associate_sees_their_code_qr_target_and_shops(): void
    {
        $this->setRewards(200, 300, 10);
        $a = $this->associate();
        $store = $this->creditedShop($a);
        $store->update(['approval_status' => 'approved', 'approved_at' => now(), 'is_active' => true]);

        $this->actingAs($a->user)->get('/account/field-work')->assertOk()
            ->assertSee($a->code)->assertSee($store->name)->assertSee('data-qr="'.$a->link().'"', false)
            ->assertSee('1 of 10 done')->assertSee('₹200');

        Livewire::actingAs($a->user)->test(FieldWork::class)->call('showMonth', 1)->assertSet('monthsAgo', 1)->assertSee('₹0');
        $this->get('/account')->assertSee('My field work');
    }

    public function test_the_page_is_only_for_associates_and_a_guest_signs_in_first(): void
    {
        $this->get('/account/field-work')->assertRedirect(route('customer.login', ['intended' => '/account/field-work']));
        $this->actingAs($this->makeCustomer())->get('/account/field-work')->assertForbidden();
    }
}
