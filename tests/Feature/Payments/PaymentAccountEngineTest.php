<?php

namespace Tests\Feature\Payments;

use App\Livewire\Payouts\Manage as PayoutsManage;
use App\Models\PaymentAccount;
use App\Models\Payout;
use App\Services\PaymentAccountService;
use App\Services\PayoutService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Payment Admin completion (mission Phase 9). payment_accounts already
 * existed and was already READ by PayoutService/Payouts\Manage, but had
 * ZERO write path anywhere -- confirmed by direct search before building
 * this. payment_methods (the other table named in risk register item 11)
 * stays deliberately untouched -- it genuinely duplicates the existing
 * payment.*_enabled Settings toggles and remains blocked on that
 * consolidation decision, not built around here.
 */
class PaymentAccountEngineTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    // ============================== PaymentAccountService ==============================

    public function test_create_bank_account_requires_all_bank_fields(): void
    {
        $customer = $this->makeCustomer();

        $this->expectException(\InvalidArgumentException::class);
        app(PaymentAccountService::class)->create($customer, ['account_type' => 'bank', 'account_number' => '12345']);
    }

    public function test_create_upi_account_requires_upi_id(): void
    {
        $customer = $this->makeCustomer();

        $this->expectException(\InvalidArgumentException::class);
        app(PaymentAccountService::class)->create($customer, ['account_type' => 'upi']);
    }

    public function test_create_valid_bank_account_succeeds_unverified(): void
    {
        $customer = $this->makeCustomer();

        $account = app(PaymentAccountService::class)->create($customer, [
            'account_type' => 'bank', 'account_holder_name' => 'Test User', 'account_number' => '12345', 'ifsc' => 'ABCD0123456',
        ]);

        $this->assertFalse($account->is_verified);
    }

    public function test_first_account_is_automatically_default(): void
    {
        $customer = $this->makeCustomer();

        $account = app(PaymentAccountService::class)->create($customer, ['account_type' => 'upi', 'upi_id' => 'test@upi']);

        $this->assertTrue($account->is_default);
    }

    public function test_second_account_not_default_unless_requested(): void
    {
        $customer = $this->makeCustomer();
        $service = app(PaymentAccountService::class);
        $service->create($customer, ['account_type' => 'upi', 'upi_id' => 'first@upi']);

        $second = $service->create($customer, ['account_type' => 'upi', 'upi_id' => 'second@upi']);

        $this->assertFalse($second->is_default);
    }

    public function test_setting_default_unsets_previous_default(): void
    {
        $customer = $this->makeCustomer();
        $service = app(PaymentAccountService::class);
        $first = $service->create($customer, ['account_type' => 'upi', 'upi_id' => 'first@upi']);
        $second = $service->create($customer, ['account_type' => 'upi', 'upi_id' => 'second@upi']);

        $service->setDefault($second);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
    }

    public function test_update_changing_account_number_resets_verification(): void
    {
        $customer = $this->makeCustomer();
        $service = app(PaymentAccountService::class);
        $account = $service->create($customer, ['account_type' => 'bank', 'account_holder_name' => 'A', 'account_number' => '111', 'ifsc' => 'ABCD0123456']);
        $service->verify($account);

        $service->update($account->fresh(), ['account_number' => '222']);

        $this->assertFalse($account->fresh()->is_verified);
    }

    public function test_update_changing_only_holder_name_keeps_verification(): void
    {
        $customer = $this->makeCustomer();
        $service = app(PaymentAccountService::class);
        $account = $service->create($customer, ['account_type' => 'bank', 'account_holder_name' => 'A', 'account_number' => '111', 'ifsc' => 'ABCD0123456']);
        $service->verify($account);

        $service->update($account->fresh(), ['account_holder_name' => 'A Updated']);

        $this->assertTrue($account->fresh()->is_verified);
    }

    public function test_delete_blocked_when_referenced_by_in_flight_payout(): void
    {
        $customer = $this->makeCustomer();
        $account = app(PaymentAccountService::class)->create($customer, ['account_type' => 'upi', 'upi_id' => 'x@upi']);
        Payout::create(['payee_type' => 'franchise_owner', 'payee_id' => $customer->id, 'payment_account_id' => $account->id, 'amount' => 100, 'period_start' => now(), 'period_end' => now(), 'status' => 'pending']);

        $this->expectException(\RuntimeException::class);
        app(PaymentAccountService::class)->delete($account);
    }

    public function test_delete_allowed_when_no_in_flight_payout(): void
    {
        $customer = $this->makeCustomer();
        $account = app(PaymentAccountService::class)->create($customer, ['account_type' => 'upi', 'upi_id' => 'x@upi']);

        app(PaymentAccountService::class)->delete($account);

        $this->assertSame(0, PaymentAccount::count());
    }

    // ============================== PayoutService integrity ==============================

    public function test_payout_rejects_payment_account_belonging_to_someone_else(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $otherUser = $this->makeCustomer();
        $account = app(PaymentAccountService::class)->create($otherUser, ['account_type' => 'upi', 'upi_id' => 'x@upi']);
        app(WalletService::class)->credit($provider->user, 500, 'seed');

        $this->expectException(\RuntimeException::class);
        app(PayoutService::class)->request('provider', $provider->id, 100, $account->id);
    }

    public function test_payout_succeeds_with_own_payment_account(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $account = app(PaymentAccountService::class)->create($provider->user, ['account_type' => 'upi', 'upi_id' => 'x@upi']);
        app(WalletService::class)->credit($provider->user, 500, 'seed');

        $payout = app(PayoutService::class)->request('provider', $provider->id, 100, $account->id);

        $this->assertSame($account->id, $payout->payment_account_id);
    }

    public function test_payout_succeeds_with_no_payment_account(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        app(WalletService::class)->credit($provider->user, 500, 'seed');

        $payout = app(PayoutService::class)->request('provider', $provider->id, 100, null);

        $this->assertNull($payout->payment_account_id);
    }

    // ============================== API self-service ==============================

    public function test_unauthenticated_cannot_create_payment_account(): void
    {
        $this->postJson('/api/payment-accounts', ['account_type' => 'upi', 'upi_id' => 'x@upi'])->assertUnauthorized();
    }

    public function test_user_can_create_and_list_own_accounts(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')->postJson('/api/payment-accounts', ['account_type' => 'upi', 'upi_id' => 'x@upi'])->assertCreated();

        $response = $this->actingAs($customer, 'sanctum')->getJson('/api/payment-accounts');
        $response->assertOk();
        $this->assertCount(1, $response->json());
    }

    public function test_user_cannot_update_another_users_account(): void
    {
        $owner = $this->makeCustomer();
        $stranger = $this->makeCustomer();
        $account = app(PaymentAccountService::class)->create($owner, ['account_type' => 'upi', 'upi_id' => 'x@upi']);

        $this->actingAs($stranger, 'sanctum')->putJson("/api/payment-accounts/{$account->id}", ['upi_id' => 'hacked@upi'])->assertNotFound();
        $this->assertSame('x@upi', $account->fresh()->upi_id);
    }

    public function test_user_cannot_delete_another_users_account(): void
    {
        $owner = $this->makeCustomer();
        $stranger = $this->makeCustomer();
        $account = app(PaymentAccountService::class)->create($owner, ['account_type' => 'upi', 'upi_id' => 'x@upi']);

        $this->actingAs($stranger, 'sanctum')->deleteJson("/api/payment-accounts/{$account->id}")->assertNotFound();
        $this->assertSame(1, PaymentAccount::count());
    }

    public function test_user_can_set_default_on_own_account(): void
    {
        $customer = $this->makeCustomer();
        $service = app(PaymentAccountService::class);
        $service->create($customer, ['account_type' => 'upi', 'upi_id' => 'first@upi']);
        $second = $service->create($customer, ['account_type' => 'upi', 'upi_id' => 'second@upi']);

        $this->actingAs($customer, 'sanctum')->postJson("/api/payment-accounts/{$second->id}/set-default")->assertOk();

        $this->assertTrue($second->fresh()->is_default);
    }

    public function test_invalid_account_type_returns_422(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')->postJson('/api/payment-accounts', ['account_type' => 'bank'])->assertStatus(422);
    }

    // ============================== Admin verification ==============================

    public function test_livewire_verify_requires_payouts_manage_permission(): void
    {
        $customer = $this->makeCustomer();
        $account = app(PaymentAccountService::class)->create($customer, ['account_type' => 'upi', 'upi_id' => 'x@upi']);
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(PayoutsManage::class)->call('verifyPaymentAccount', $account->id);

        $this->assertFalse($account->fresh()->is_verified);
    }

    public function test_livewire_verify_succeeds_with_permission(): void
    {
        $customer = $this->makeCustomer();
        $account = app(PaymentAccountService::class)->create($customer, ['account_type' => 'upi', 'upi_id' => 'x@upi']);
        $actor = $this->makeUserWithPermission('payouts.manage', 'global');

        Livewire::actingAs($actor)->test(PayoutsManage::class)->call('verifyPaymentAccount', $account->id);

        $this->assertTrue($account->fresh()->is_verified);
    }

    public function test_livewire_unverify_succeeds_with_permission(): void
    {
        $customer = $this->makeCustomer();
        $account = app(PaymentAccountService::class)->create($customer, ['account_type' => 'upi', 'upi_id' => 'x@upi']);
        app(PaymentAccountService::class)->verify($account);
        $actor = $this->makeUserWithPermission('payouts.manage', 'global');

        Livewire::actingAs($actor)->test(PayoutsManage::class)->call('unverifyPaymentAccount', $account->id);

        $this->assertFalse($account->fresh()->is_verified);
    }
}
