<?php

namespace Tests\Feature\Payments;

use App\Livewire\Provider\PaymentAccounts as ProviderPaymentAccounts;
use App\Models\PaymentAccount;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Provider self-service settlement accounts (App\Livewire\Provider\
 * PaymentAccounts) -- the provider-facing half of closing the
 * payment_accounts write-path gap; PaymentAccountEngineTest already covers
 * the service/API/admin-verification halves. Every scenario here is wired
 * to the real, previously-unused PaymentAccountService -- no new service
 * logic under test, only the new Livewire component's use of it.
 */
class ProviderPaymentAccountsTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    private function makeProvider()
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();

        return $this->makeProviderIn($franchise, $zone);
    }

    public function test_provider_with_no_accounts_sees_empty_state(): void
    {
        $provider = $this->makeProvider();

        Livewire::actingAs($provider->user)->test(ProviderPaymentAccounts::class)
            ->assertOk()
            ->assertSee('No payment accounts yet');
    }

    public function test_provider_can_create_a_upi_account(): void
    {
        $provider = $this->makeProvider();

        Livewire::actingAs($provider->user)->test(ProviderPaymentAccounts::class)
            ->call('startCreate')
            ->set('accountType', 'upi')
            ->set('upiId', 'provider@upi')
            ->call('save');

        $account = PaymentAccount::where('user_id', $provider->user->id)->sole();
        $this->assertSame('provider@upi', $account->upi_id);
        $this->assertFalse($account->is_verified);
        $this->assertTrue($account->is_default);
    }

    public function test_provider_bank_account_missing_required_fields_fails_validation(): void
    {
        $provider = $this->makeProvider();

        Livewire::actingAs($provider->user)->test(ProviderPaymentAccounts::class)
            ->call('startCreate')
            ->set('accountType', 'bank')
            ->call('save');

        $this->assertSame(0, PaymentAccount::where('user_id', $provider->user->id)->count());
    }

    public function test_provider_can_switch_the_default_account(): void
    {
        $provider = $this->makeProvider();
        $component = Livewire::actingAs($provider->user)->test(ProviderPaymentAccounts::class);

        $component->call('startCreate')->set('accountType', 'upi')->set('upiId', 'first@upi')->call('save');
        $first = PaymentAccount::where('user_id', $provider->user->id)->where('upi_id', 'first@upi')->sole();

        $component->call('startCreate')->set('accountType', 'upi')->set('upiId', 'second@upi')->call('save');
        $second = PaymentAccount::where('user_id', $provider->user->id)->where('upi_id', 'second@upi')->sole();

        $this->assertTrue($first->fresh()->is_default);
        $this->assertFalse($second->fresh()->is_default);

        $component->call('setDefault', $second->id);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
    }

    public function test_provider_can_delete_their_own_account(): void
    {
        $provider = $this->makeProvider();
        $component = Livewire::actingAs($provider->user)->test(ProviderPaymentAccounts::class);
        $component->call('startCreate')->set('accountType', 'upi')->set('upiId', 'x@upi')->call('save');
        $account = PaymentAccount::where('user_id', $provider->user->id)->sole();

        $component->call('delete', $account->id);

        $this->assertSame(0, PaymentAccount::count());
    }

    /**
     * Livewire::test()->call() invokes the component method directly rather
     * than round-tripping through the full HTTP exception-handler pipeline
     * (unlike a real ->get() request, or an abort_unless() thrown during
     * mount() -- see PaymentAccountEngineTest's own note on why THAT case
     * is asserted with assertForbidden() instead), so findOrFail()'s
     * ModelNotFoundException surfaces here as a raw PHP exception rather
     * than an assertable 404 response. expectException() is therefore the
     * correct assertion for this ownership guard, not assertNotFound().
     */
    public function test_provider_cannot_edit_another_providers_account(): void
    {
        $owner = $this->makeProvider();
        $stranger = $this->makeProvider();
        Livewire::actingAs($owner->user)->test(ProviderPaymentAccounts::class)
            ->call('startCreate')->set('accountType', 'upi')->set('upiId', 'owner@upi')->call('save');
        $account = PaymentAccount::where('user_id', $owner->user->id)->sole();

        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($stranger->user)->test(ProviderPaymentAccounts::class)->call('startEdit', $account->id);
    }

    public function test_provider_cannot_set_default_on_another_providers_account(): void
    {
        $owner = $this->makeProvider();
        $stranger = $this->makeProvider();
        Livewire::actingAs($owner->user)->test(ProviderPaymentAccounts::class)
            ->call('startCreate')->set('accountType', 'upi')->set('upiId', 'owner@upi')->call('save');
        $account = PaymentAccount::where('user_id', $owner->user->id)->sole();

        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($stranger->user)->test(ProviderPaymentAccounts::class)->call('setDefault', $account->id);
    }

    public function test_provider_cannot_delete_another_providers_account(): void
    {
        $owner = $this->makeProvider();
        $stranger = $this->makeProvider();
        Livewire::actingAs($owner->user)->test(ProviderPaymentAccounts::class)
            ->call('startCreate')->set('accountType', 'upi')->set('upiId', 'owner@upi')->call('save');
        $account = PaymentAccount::where('user_id', $owner->user->id)->sole();

        try {
            Livewire::actingAs($stranger->user)->test(ProviderPaymentAccounts::class)->call('delete', $account->id);
            $this->fail('Expected a ModelNotFoundException.');
        } catch (ModelNotFoundException $e) {
            // expected
        }

        $this->assertSame(1, PaymentAccount::count());
    }
}
