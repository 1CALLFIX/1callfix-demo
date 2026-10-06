<?php

namespace Tests\Feature\Coupons;

use App\Livewire\Customer\Booking\Wizard;
use App\Livewire\Customer\Checkout;
use App\Models\Coupon;
use App\Models\CouponTarget;
use App\Models\Setting;
use App\Services\Customer\ServiceCartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * C3 fixes items 2, 4 and 5 — once a coupon is applied the totals the customer is asked to pay say so: the checkout
 * "You pay" row and Confirm button use the coupon payable, the wizard "Estimated total" includes the discount, and the
 * online-only sentence appears once, not twice.
 */
class CouponPayableDisplayTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    private const ONLINE_ONLY = 'Offers apply on online payment only.';

    private function world(): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $category = $this->makeCategory(['module' => 'service']);
        $a = $this->makeService($category, ['base_price' => 499]);
        $b = $this->makeService($category, ['base_price' => 299]);
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        $this->makeProviderIn($franchise, $zone);
        Setting::set('coupons.enabled', '1');
        Setting::set('coupons.unpaid_hold_minutes', '30');
        Setting::set('payment.wallet_enabled', '1');
        $coupon = Coupon::create([
            'code' => 'SAVE100', 'name' => 'Save 100', 'status' => 'active', 'is_active' => true, 'module' => 'service',
            'discount_type' => 'flat', 'value' => 100, 'min_order_value' => 0, 'per_user_limit' => 5,
        ]);
        CouponTarget::create(['coupon_id' => $coupon->id, 'target_type' => 'global', 'operator' => 'include']);

        return compact('a', 'b', 'customer', 'address');
    }

    private function checkoutAtPay(array $w)
    {
        $cart = app(ServiceCartService::class);
        $cart->add($w['customer'], $w['a']);
        $cart->add($w['customer'], $w['b']);

        return Livewire::actingAs($w['customer'])->test(Checkout::class)
            ->set('addressId', $w['address']->id)->call('next')->call('next')->call('next');
    }

    private function wizardAtPay(array $w)
    {
        return Livewire::actingAs($w['customer'])->test(Wizard::class, ['service' => $w['a']])
            ->set('addressId', $w['address']->id)->call('next')->call('next')->call('next');
    }

    // ---------------------------------------------------------------- item 2: checkout

    public function test_checkout_you_pay_and_confirm_use_the_coupon_payable(): void
    {
        $w = $this->world();

        $this->checkoutAtPay($w)->set('paymentMethod', 'online')->set('couponCode', 'SAVE100')->call('applyCoupon')
            ->assertSee('Confirm &amp; book · ₹698.00', false)
            ->assertSeeHtmlInOrder(['You pay', '₹698.00', 'Confirm &amp; book', '₹698.00'])
            ->assertDontSee('Confirm &amp; book · ₹798.00', false);
    }

    public function test_checkout_without_an_applied_coupon_still_shows_the_full_amount(): void
    {
        $w = $this->world();

        $this->checkoutAtPay($w)->set('paymentMethod', 'online')
            ->assertSee('Confirm &amp; book · ₹798.00', false);
    }

    public function test_checkout_on_cash_with_a_code_shows_the_full_amount_not_a_discount(): void
    {
        $w = $this->world();

        $this->checkoutAtPay($w)->set('paymentMethod', 'cash')->set('couponCode', 'SAVE100')->call('applyCoupon')
            ->assertSee('Confirm &amp; book · ₹798.00', false);
    }

    // ---------------------------------------------------------------- item 4: wizard

    public function test_wizard_estimated_total_includes_the_applied_coupon(): void
    {
        $w = $this->world();

        $this->wizardAtPay($w)->set('paymentMethod', 'online')->set('couponCode', 'SAVE100')->call('applyCoupon')
            ->assertSeeHtmlInOrder(['Coupon discount', '₹100.00', 'Estimated total', '₹399.00']);
    }

    public function test_wizard_estimated_total_is_unchanged_without_a_coupon_or_on_cash(): void
    {
        $w = $this->world();

        $this->wizardAtPay($w)->set('paymentMethod', 'online')->assertSeeHtmlInOrder(['Estimated total', '₹499.00'])->assertDontSee('Coupon discount');
        $this->wizardAtPay($w)->set('paymentMethod', 'cash')->set('couponCode', 'SAVE100')->call('applyCoupon')
            ->assertSeeHtmlInOrder(['Estimated total', '₹499.00'])->assertDontSee('Coupon discount');
    }

    // ---------------------------------------------------------------- item 5: one message

    public function test_the_online_only_sentence_is_shown_once_on_the_wizard_and_checkout(): void
    {
        $w = $this->world();

        $wizard = $this->wizardAtPay($w)->set('paymentMethod', 'cash')->set('couponCode', 'SAVE100')->call('applyCoupon');
        $this->assertSame(1, substr_count($wizard->html(), self::ONLINE_ONLY));

        $checkout = $this->checkoutAtPay($w)->set('paymentMethod', 'cash')->set('couponCode', 'SAVE100')->call('applyCoupon');
        $this->assertSame(1, substr_count($checkout->html(), self::ONLINE_ONLY));
    }

    public function test_before_apply_a_typed_code_on_cash_still_shows_the_sentence_once(): void
    {
        $w = $this->world();

        $wizard = $this->wizardAtPay($w)->set('paymentMethod', 'cash')->set('couponCode', 'SAVE100');
        $this->assertSame(1, substr_count($wizard->html(), self::ONLINE_ONLY));
    }
}
