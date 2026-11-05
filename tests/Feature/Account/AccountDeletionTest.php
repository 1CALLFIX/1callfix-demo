<?php

namespace Tests\Feature\Account;

use App\Livewire\AccountDeletions\Index;
use App\Models\Address;
use App\Models\Booking;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Account\AccountDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/** Google Play account deletion (owner 2026-11-05): public steps page, customer self-delete, partner request, 30-day erase. */
class AccountDeletionTest extends TestCase
{
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    public function test_the_public_page_shows_the_steps_for_every_app_without_signing_in(): void
    {
        $this->get('/delete-account')->assertOk()
            ->assertSee('Delete your 1CallFix account')->assertSee('Account → Delete my account', false)
            ->assertSee('More at the bottom → Delete my account', false)->assertSee('erased after 30 days')
            ->assertSee('Sign in to delete');
        $this->get('/account/delete')->assertRedirect('/delete-account');
        $this->post('/delete-account', ['confirm' => 1])->assertRedirect();
    }

    public function test_a_customer_deletes_themselves_and_details_are_erased_after_30_days(): void
    {
        $s = $this->makeBookingScenario('completed');
        $customer = $s['customer'];
        $s['address']->forceFill(['address_line' => '1 Main Rd', 'lat' => 14.43219, 'lng' => 79.98765])->save();
        $customer->createToken('app');

        $this->actingAs($customer)->get(route('customer.account'))->assertSee('data-testid="delete-account-link"', false);
        $this->actingAs($customer)->get('/delete-account')->assertSee('data-testid="delete-submit"', false);

        // The tick box is required.
        $this->actingAs($customer)->post('/delete-account')->assertSessionHasErrors('confirm');
        $this->assertNull($customer->fresh()->closed_at);

        $this->actingAs($customer)->post('/delete-account', ['confirm' => 1])->assertRedirect(route('account.delete'));
        $this->assertGuest();
        $fresh = $customer->fresh();
        $this->assertSame('suspended', $fresh->status);
        $this->assertNotNull($fresh->closed_at);
        $this->assertSame(0, $fresh->tokens()->count());

        // Not yet erased before 30 days.
        $this->travel(29)->days();
        $this->artisan('accounts:erase-closed')->assertSuccessful();
        $this->assertNull($customer->fresh()->erased_at);

        $this->travel(2)->days();
        $this->artisan('accounts:erase-closed')->expectsOutput('Erased: 1')->assertSuccessful();
        $erased = $customer->fresh();
        $this->assertSame('Deleted user', $erased->name);
        $this->assertSame('deleted-'.$customer->id, $erased->phone);
        $this->assertNull($erased->email);
        $address = Address::findOrFail($s['address']->id);
        $this->assertSame('Address removed', $address->address_line);
        $this->assertEqualsWithDelta(14.43, (float) $address->lat, 0.0001);
        // The past booking is kept (tax records), never cascaded away.
        $this->assertNotNull(Booking::find($s['booking']->id));
    }

    public function test_wallet_money_or_an_open_booking_blocks_deleting(): void
    {
        $s = $this->makeBookingScenario('searching_provider');
        $customer = $s['customer'];
        $booking = $s['booking'];
        $booking->forceFill(['status' => 'completed'])->save();
        Wallet::create(['user_id' => $customer->id, 'balance' => 150]);
        $this->actingAs($customer)->get('/delete-account')->assertSee('data-testid="delete-blocked"', false)
            ->assertSee('150.00')->assertDontSee('data-testid="delete-submit"', false);
        $this->actingAs($customer)->post('/delete-account', ['confirm' => 1]);
        $this->assertNull($customer->fresh()->closed_at);

        Wallet::where('user_id', $customer->id)->update(['balance' => 0]);
        $booking->forceFill(['status' => 'in_progress'])->save();
        $this->actingAs($customer)->get('/delete-account')->assertSee('still open');

        $booking->forceFill(['status' => 'completed'])->save();
        $this->actingAs($customer)->get('/delete-account')->assertSee('data-testid="delete-submit"', false);
    }

    public function test_a_partner_only_asks_and_a_super_admin_closes_and_can_reopen(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $rider = $this->makeParcelRiderIn($franchise, $zone);

        $this->actingAs($rider->user)->get(route('rider.more'))->assertSee('data-testid="partner-delete-account"', false);
        $this->actingAs($rider->user)->get('/delete-account')->assertSee('Send delete request');
        $this->actingAs($rider->user)->post('/delete-account', ['confirm' => 1])->assertRedirect(route('account.delete'));
        $user = $rider->user->fresh();
        $this->assertNotNull($user->deletion_requested_at);
        $this->assertNull($user->closed_at, 'a partner is never closed by their own tap');
        $this->assertSame('active', $user->status);
        $this->actingAs($user)->get('/delete-account')->assertSee('data-testid="delete-pending"', false);

        $this->actingAs($this->makeUserWithNoPermissions());
        Livewire::test(Index::class)->assertForbidden();

        $this->actingAs($this->makeSuperAdmin());
        Livewire::test(Index::class)->assertSee($user->phone)->call('close', $user->id)->assertSee('Closed');
        $this->assertSame('suspended', $user->fresh()->status);

        Livewire::test(Index::class)->call('reopen', $user->id)->assertSee('Reopened');
        $back = $user->fresh();
        $this->assertSame('active', $back->status);
        $this->assertNull($back->closed_at);
        $this->assertNull($back->deletion_requested_at);
    }

    public function test_an_admin_reopened_account_is_never_erased(): void
    {
        $customer = $this->makeCustomer();
        app(AccountDeletionService::class)->close($customer, null, 'customer');
        $customer->fresh()->forceFill(['status' => 'active'])->save();
        $this->travel(40)->days();
        $this->artisan('accounts:erase-closed')->expectsOutput('Erased: 0');
        $this->assertNotSame('Deleted user', User::find($customer->id)->name);
    }

    public function test_the_erase_job_is_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('accounts:erase-closed');
    }
}
