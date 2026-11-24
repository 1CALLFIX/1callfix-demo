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
        // Early in a month, so a first order's waiting days never cross into the next month mid-test.
        $this->travelTo(now()->startOfMonth()->addDay()->setTime(10, 0));
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
        app(CartService::class)->add($customer, $store->products()->where('is_approved', true)->first(), 1);
        $order = app(CreateMarketplaceOrderAction::class)->execute(['store_id' => $store->id, 'customer_id' => $customer->id, 'order_type' => 'pickup', 'payment_method' => 'cash']);
        foreach (['accepted', 'preparing', 'ready'] as $to) {
            app(\App\Actions\AdvanceMarketplaceOrderAction::class)->execute($order->id, $to);
        }

        return app(CompleteMarketplaceOrderAction::class)->execute($order->id, null, $order->fresh()->delivery_otp, $store->provider->user);
    }

    /** Amounts for a test; no per-shop limit, no minimum order and no bonus unless given. */
    private function setRewards(float $live, float $first, int $target = 0, float $products = 0, float $max = 0, float $minOrder = 0, array $levels = []): void
    {
        Setting::set(FieldAssociateTracker::REWARD_SHOP_LIVE, (string) $live);
        Setting::set(FieldAssociateTracker::REWARD_PRODUCTS, (string) $products);
        Setting::set(FieldAssociateTracker::REWARD_FIRST_ORDER, (string) $first);
        Setting::set(FieldAssociateTracker::MAX_PER_SHOP, (string) $max);
        Setting::set(FieldAssociateTracker::FIRST_ORDER_MIN, (string) $minOrder);
        Setting::set(FieldAssociateTracker::BONUS_LEVELS, json_encode($levels));
        Setting::set(FieldAssociateTracker::MONTHLY_TARGET, (string) $target);
    }

    /** A credited shop that is live (KYC + shop approved) with $products approved products. */
    private function liveShop(FieldAssociate $a, int $products = 10): Store
    {
        $store = $this->creditedShop($a);
        $store->provider->update(['kyc_status' => 'approved']);
        $store->update(['approval_status' => 'approved', 'approved_at' => now(), 'is_active' => true, 'is_open' => true]);
        for ($i = 0; $i < $products; $i++) {
            $this->makeProduct($store, ['price' => 150, 'stock' => 20]);
        }

        return $store->fresh();
    }

    /** Past the return window, then the daily sync. */
    private function afterReturnWindow(): void
    {
        $this->travel(FieldAssociateTracker::holdDays())->days();
        app(FieldAssociateTracker::class)->syncAll();
    }

    private function testLogin(string $key): User
    {
        return User::create(['uuid' => (string) Str::uuid(), 'name' => 'Test '.$key, 'email' => "test.{$key}@1callfix.test",
            'phone' => '6'.fake()->unique()->numerify('#########'), 'role' => 'customer', 'status' => 'active']);
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
        $this->setRewards(200, 300, products: 25);
        $a = $this->associate();
        $store = $this->creditedShop($a);
        $tracker = app(FieldAssociateTracker::class);
        $progress = fn () => $tracker->shops($a->fresh())->first()['progress'];

        $this->assertSame(20, $progress()['percent']);
        $this->assertSame('Next: add 10 products (0 approved so far)', $progress()['next']);

        // Products waiting for approval do not count; 10 approved ones do.
        $this->makeProduct($store, ['is_approved' => false]);
        for ($i = 0; $i < 9; $i++) {
            $this->makeProduct($store, ['price' => 150, 'stock' => 10]);
        }
        $this->assertSame('Next: add 10 products (9 approved so far)', $progress()['next']);
        $this->makeProduct($store, ['price' => 150, 'stock' => 10]);
        $this->assertSame(40, $progress()['percent']);
        $this->assertSame('Next: the owner uploads their KYC, then the office approves it', $progress()['next']);
        $this->assertFalse(FieldAssociateReward::where('kind', 'products')->exists(), 'products pay only once the shop is live');

        $store->provider->update(['kyc_status' => 'approved']);
        $this->assertSame(60, $progress()['percent']);

        Livewire::actingAs($this->makeSuperAdmin())->test(StoresManage::class)->call('approveStore', $store->id);
        $this->assertEquals(200, FieldAssociateReward::where('store_id', $store->id)->where('kind', 'shop_live')->sole()->amount);
        $this->assertEquals(25, FieldAssociateReward::where('store_id', $store->id)->where('kind', 'products')->sole()->amount);
        $this->assertSame(80, $progress()['percent']);
        $this->assertStringStartsWith('Next: first order from a customer', $progress()['next']);

        // The shop owner's own order and the associate's own order never count as the first order.
        $this->completedOrder($store->fresh(), $store->provider->user);
        $this->completedOrder($store->fresh(), $a->user);
        $this->afterReturnWindow();
        $this->assertFalse(FieldAssociateReward::where('store_id', $store->id)->where('kind', 'first_order')->exists());

        // A real customer's order pays only after the return window.
        $customer = $this->makeCustomer();
        $order = $this->completedOrder($store->fresh(), $customer);
        $this->assertFalse(FieldAssociateReward::where('kind', 'first_order')->exists(), 'waits for the return window');
        $this->afterReturnWindow();
        $first = FieldAssociateReward::where('store_id', $store->id)->where('kind', 'first_order')->sole();
        $this->assertEquals(300, $first->amount);
        $this->assertSame([$order->id, $customer->id], [$first->order_id, $first->customer_id]);
        $this->assertSame(100, $progress()['percent']);
        $this->assertSame('All done 🎉', $progress()['next']);

        $month = $tracker->month($a, now());
        $this->assertSame([1, 1, 0, 1, 200.0, 1, 25.0, 1, 300.0, 525.0], [$month['signed_up'], $month['signed_up_shops'], $month['signed_up_restaurants'],
            $month['live_shops'], $month['live_amount'], $month['products'], $month['products_amount'], $month['first_orders'], $month['first_order_amount'], $month['earned']]);

        // A second order never pays again.
        $this->completedOrder($store->fresh(), $this->makeCustomer());
        $this->afterReturnWindow();
        $this->assertSame(3, FieldAssociateReward::where('store_id', $store->id)->count());
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

        // The owner's plan is there from the start (migration), and Super Admin changes it.
        $this->assertSame([50.0, 50.0, 100.0, 200.0, 100.0], [FieldAssociateTracker::rewardShopLive(), FieldAssociateTracker::rewardProducts(),
            FieldAssociateTracker::rewardFirstOrder(), FieldAssociateTracker::maxPerShop(), FieldAssociateTracker::firstOrderMin()]);
        $this->assertSame([[10, 500.0], [20, 1000.0], [30, 1500.0], [40, 2500.0]], FieldAssociateTracker::bonusLevels());

        Livewire::actingAs($admin)->test(Manage::class)
            ->assertSet('bonusLevels.1', ['shops' => '20', 'amount' => '1000'])->assertSet('bonusLevels.3', ['shops' => '40', 'amount' => '2500'])
            ->set('rewardShopLive', '30')->set('rewardProducts', '20')->set('rewardFirstOrder', '60')->set('maxPerShop', '110')->set('firstOrderMin', '150')
            ->set('bonusLevels.2', ['shops' => '', 'amount' => ''])->set('bonusLevels.0', ['shops' => '12', 'amount' => '600'])
            ->call('saveRewards')->assertHasNoErrors();
        $this->assertSame([30.0, 20.0, 60.0, 110.0, 150.0], [FieldAssociateTracker::rewardShopLive(), FieldAssociateTracker::rewardProducts(),
            FieldAssociateTracker::rewardFirstOrder(), FieldAssociateTracker::maxPerShop(), FieldAssociateTracker::firstOrderMin()]);
        $this->assertSame([[12, 600.0], [20, 1000.0], [40, 2500.0]], FieldAssociateTracker::bonusLevels());

        // A half-filled level is refused.
        Livewire::actingAs($admin)->test(Manage::class)->set('bonusLevels.3', ['shops' => '50', 'amount' => ''])->call('saveRewards')->assertHasErrors('bonusLevels.3.amount');

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

        // A reason is required.
        $c->call('open', $a->id)->set('assignStoreId', $forgot->id)->call('assignStore')->assertHasErrors('assignReason');
        $c->set('assignReason', 'Owner says Ravi visited')->call('assignStore')->assertHasNoErrors();
        $this->assertSame($a->id, $forgot->fresh()->field_associate_id);
        $this->assertSame(1, FieldAssociateReward::where('store_id', $forgot->id)->count());

        $c->call('startChange', $forgot->id, 'remove')->call('saveChange')->assertHasErrors('changeReason');
        $c->set('changeReason', 'Signed up by the office')->call('saveChange')->assertHasNoErrors();
        $this->assertNull($forgot->fresh()->field_associate_id);
        $this->assertSame(0, FieldAssociateReward::count());
        $this->assertSame(['Owner says Ravi visited', 'Signed up by the office'],
            \App\Models\FieldAssociateCreditChange::where('store_id', $forgot->id)->orderBy('id')->pluck('reason')->all());
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
        $this->setRewards(200, 300, products: 25, levels: [[10, 500], [20, 1000]]);
        $a = $this->associate();
        $store = $this->liveShop($a);

        $this->actingAs($a->user)->get('/account/field-work')->assertOk()
            ->assertSee($a->code)->assertSee($store->name)->assertSee('data-qr="'.$a->link().'"', false)
            ->assertSee('1 of 10 shops for the ₹500 bonus')->assertSee('₹225')
            ->assertSee(route('customer.field-work.add', 'shop'), false)->assertSee(route('customer.field-work.add', 'restaurant'), false)
            ->assertDontSee('you will be signed out');

        Livewire::actingAs($a->user)->test(FieldWork::class)->call('showMonth', 1)->assertSet('monthsAgo', 1)->assertSee('₹0');
        $this->get('/account')->assertSee('My field work');
    }

    public function test_the_page_is_only_for_associates_and_a_guest_signs_in_first(): void
    {
        $this->get('/account/field-work')->assertRedirect(route('customer.login', ['intended' => '/account/field-work']));
        $this->actingAs($this->makeCustomer())->get('/account/field-work')->assertForbidden();
    }

    // ── Release A (owner's plan 2026-11-24) ─────────────────────────────

    public function test_the_three_rewards_never_pass_the_per_shop_maximum(): void
    {
        $this->setRewards(60, 60, products: 60, max: 100);
        $a = $this->associate();
        $store = $this->liveShop($a);
        app(FieldAssociateTracker::class)->syncAssociate($a);
        $this->completedOrder($store, $this->makeCustomer());
        $this->afterReturnWindow();

        $this->assertSame(['first_order' => '0.00', 'products' => '40.00', 'shop_live' => '60.00'],
            FieldAssociateReward::where('store_id', $store->id)->orderBy('kind')->pluck('amount', 'kind')->map(fn ($v) => number_format((float) $v, 2, '.', ''))->all());
        $this->assertEquals(100, FieldAssociateReward::where('store_id', $store->id)->sum('amount'));
    }

    public function test_a_first_order_needs_the_minimum_value_and_a_customer_not_used_for_another_shop(): void
    {
        $this->setRewards(25, 50, minOrder: 1000);
        $a = $this->associate();
        $one = $this->liveShop($a, 1);
        $two = $this->liveShop($a, 1);
        $friend = $this->makeCustomer();

        $this->completedOrder($one, $friend);
        $this->afterReturnWindow();
        $this->assertFalse(FieldAssociateReward::where('kind', 'first_order')->exists(), 'below the minimum order value');

        $this->setRewards(25, 50);
        $this->completedOrder($two, $friend);
        $this->afterReturnWindow();
        // The same friend counts for one of this associate's shops only.
        $this->assertSame([$one->id], FieldAssociateReward::where('kind', 'first_order')->pluck('store_id')->all());

        // A test login never makes a first order for a real associate; a new real customer does.
        $this->completedOrder($two->fresh(), $this->testLogin('customer'));
        $this->afterReturnWindow();
        $this->assertSame(1, FieldAssociateReward::where('kind', 'first_order')->count());
        $this->completedOrder($two->fresh(), $this->makeCustomer());
        $this->afterReturnWindow();
        $this->assertSame(2, FieldAssociateReward::where('kind', 'first_order')->count());
    }

    public function test_a_refunded_first_order_cancels_its_reward_and_the_next_genuine_order_earns_it(): void
    {
        $this->setRewards(25, 50);
        $a = $this->associate();
        $store = $this->liveShop($a, 1);
        $order = $this->completedOrder($store, $this->makeCustomer());
        $this->afterReturnWindow();
        $this->assertSame($order->id, FieldAssociateReward::where('kind', 'first_order')->sole()->order_id);

        $order->forceFill(['payment_status' => 'refunded'])->save();
        app(FieldAssociateTracker::class)->syncAll();
        $this->assertFalse(FieldAssociateReward::where('kind', 'first_order')->exists());

        $next = $this->completedOrder($store->fresh(), $this->makeCustomer());
        $this->afterReturnWindow();
        $this->assertSame($next->id, FieldAssociateReward::where('kind', 'first_order')->sole()->order_id);
    }

    public function test_the_monthly_bonus_is_the_highest_level_reached_and_restaurants_are_counted_apart(): void
    {
        $this->setRewards(25, 50, products: 25, levels: [[2, 500], [3, 1000], [4, 2500]]); // like 10 / 20 / 40 shops
        $a = $this->associate();
        $tracker = app(FieldAssociateTracker::class);

        $this->liveShop($a);
        $this->liveShop($a, 5); // live, but not 10 products: not counted for the bonus
        $tracker->syncAssociate($a);
        $this->assertSame(0.0, $tracker->month($a, now())['bonus']);

        $this->liveShop($a)->update(['module' => \App\Support\Modules::FOOD]);
        $tracker->syncAssociate($a);
        $this->assertEquals(500, \App\Models\FieldAssociateBonus::where('field_associate_id', $a->id)->sole()->amount);

        $this->liveShop($a);
        $tracker->syncAssociate($a);
        $month = $tracker->month($a, now());
        $this->assertSame([1000.0, 3, 3, 1, 4], [$month['bonus'], $month['complete'], $month['live_shops'], $month['live_restaurants'], $month['live']]);
        $this->assertSame(4 * 25 + 3 * 25 + 1000.0, $month['earned'], 'highest level only, not 500 + 1000');
        $this->assertSame(1, \App\Models\FieldAssociateBonus::count());
        $this->assertSame('3 of 4 shops for the ₹2,500 bonus', FieldAssociateTracker::goal(3)['text']);

        // The fourth level (the owner's 40 shops) replaces the third: 2,500, not 500 + 1,000 + 2,500.
        $this->liveShop($a);
        $tracker->syncAssociate($a);
        $month = $tracker->month($a, now());
        $this->assertSame([2500.0, 4, 4], [$month['bonus'], $month['bonus_level'], $month['complete']]);
        $this->assertEquals(2500, \App\Models\FieldAssociateBonus::sole()->amount);
        $this->assertStringContainsString('Top bonus reached', FieldAssociateTracker::goal(4)['text']);
    }

    public function test_test_shops_and_real_associates_never_mix(): void
    {
        $this->setRewards(25, 50);
        $real = $this->associate();
        $testShop = $this->liveShop($real, 0);
        $testShop->provider->user->update(['email' => 'test.seller@1callfix.test']);
        app(FieldAssociateTracker::class)->syncAll();
        $this->assertFalse(FieldAssociateReward::where('store_id', $testShop->id)->exists());

        // The test associate with a test shop still earns, so the walk-through works; the sheet leaves it out.
        $testAssociate = FieldAssociate::create(['user_id' => $this->testLogin('associate')->id, 'franchise_id' => $this->w['franchise']->id, 'code' => 'FA900', 'is_active' => true]);
        $testShop->update(['field_associate_id' => $testAssociate->id]);
        app(FieldAssociateTracker::class)->syncAll();
        $this->assertTrue(FieldAssociateReward::where('store_id', $testShop->id)->where('field_associate_id', $testAssociate->id)->exists());
        Livewire::actingAs($this->makeSuperAdmin())->test(Manage::class)->assertSeeHtml('title="Test login: never on the pay sheet"');
    }

    public function test_admin_moves_a_shop_to_the_associate_who_really_signed_it_up_with_its_rewards_and_history(): void
    {
        $this->setRewards(25, 50, products: 25);
        $ravi = $this->associate('Ravi');
        $sita = $this->associate('Sita');
        $store = $this->liveShop($ravi);
        app(FieldAssociateTracker::class)->syncAssociate($ravi);

        Livewire::actingAs($this->makeSuperAdmin())->test(Manage::class)->call('open', $ravi->id)
            ->call('startChange', $store->id)->set('moveToId', $sita->id)->call('saveChange')->assertHasErrors('changeReason')
            ->set('changeReason', 'Owner confirmed Sita visited')->call('saveChange')->assertHasNoErrors()
            ->assertSet('changeStoreId', null);

        $this->assertSame($sita->id, $store->fresh()->field_associate_id);
        $this->assertSame([$sita->id], FieldAssociateReward::where('store_id', $store->id)->pluck('field_associate_id')->unique()->values()->all());
        $change = \App\Models\FieldAssociateCreditChange::where('store_id', $store->id)->latest('id')->first();
        $this->assertSame([$ravi->id, $sita->id, 'Owner confirmed Sita visited'], [$change->from_associate_id, $change->to_associate_id, $change->reason]);

        // Rewards from an earlier month may already be paid: a city admin cannot move that shop, Super Admin can.
        FieldAssociateReward::where('store_id', $store->id)->update(['earned_at' => now()->subMonthNoOverflow()]);
        $cityAdmin = $this->makeUserWithPermission('field_associates.manage', 'franchise', $this->w['franchise']->id);
        Livewire::actingAs($cityAdmin)->test(Manage::class)->call('open', $sita->id)
            ->call('startChange', $store->id, 'remove')->set('changeReason', 'Not hers after all')->call('saveChange')->assertHasErrors('changeReason');
        $this->assertSame($sita->id, $store->fresh()->field_associate_id);
    }

    public function test_the_associate_signs_up_a_shop_on_their_own_phone_and_stays_signed_in(): void
    {
        Setting::set('marketplace.recruiting_sellers', '1');
        $a = $this->associate('Ravi Kumar');
        $this->actingAs($a->user);

        $this->get(route('customer.field-work.add', 'shop'))->assertRedirect(route('provider.register'))
            ->assertSessionHas(\App\Http\Controllers\FieldAssociateAssistController::SESSION_KEY, ['associate_id' => $a->id, 'role' => 'commerce']);

        $c = Livewire::test(Register::class)->assertSet('assisted', true)->assertSet('partnerRole', 'commerce')
            ->assertSet('fieldAssociateLabel', "Ravi Kumar ({$a->code})")->assertSee('docs-by-owner', false)->assertDontSee('pr-password', false);

        // The associate's own number is refused: the account must be the owner's.
        $c->set('phone', $a->user->phone)->call('requestPhoneCode')->assertHasErrors('phone');

        $c->set('phone', '9876500031')->call('requestPhoneCode')->assertHasNoErrors()
            ->call('phoneTokenReceived', $this->firebase->issuePhoneToken($this->e164('9876500031')))
            ->call('useCurrentLocationForNewAddress', 14.44, 79.98)
            ->set('name', 'Lakshmi')->set('address', '12 Main Bazaar, Nellore')->set('storeName', 'Lakshmi Kirana')->set('terms', true)
            ->call('submitApplication')->assertHasNoErrors()->assertSet('submitted', true)->assertSee('assisted-whatsapp', false);

        $store = Store::with('provider.user')->where('name', 'Lakshmi Kirana')->sole();
        $this->assertSame($a->id, $store->field_associate_id);
        $this->assertSame('9876500031', $store->provider->user->phone, 'the account is the owner\'s');
        $this->assertNull($store->provider->user->password, 'the owner signs in with a code and sets their own password');
        $this->assertSame($a->user->id, auth()->id(), 'the associate stays signed in');
        $this->assertStringContainsString('owner verified their own mobile', \App\Models\FieldAssociateCreditChange::where('store_id', $store->id)->sole()->reason);
        $this->assertNull(session(\App\Http\Controllers\FieldAssociateAssistController::SESSION_KEY), 'one visit, one shop');
    }

    public function test_only_an_active_associate_can_use_assisted_sign_up(): void
    {
        $this->actingAs($this->makeCustomer())->get(route('customer.field-work.add', 'shop'))->assertForbidden();

        $a = $this->associate();
        $a->update(['is_active' => false]);
        $this->actingAs($a->user)->get(route('customer.field-work.add', 'restaurant'))->assertRedirect(route('customer.field-work'))
            ->assertSessionMissing(\App\Http\Controllers\FieldAssociateAssistController::SESSION_KEY);
    }
}
