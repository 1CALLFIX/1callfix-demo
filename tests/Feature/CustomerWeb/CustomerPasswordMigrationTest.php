<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Auth\Login;
use App\Livewire\Customer\Auth\PasswordMigration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Feature\Support\RebuiltAuthHelpers;
use Tests\TestCase;

/**
 * Task 6 — a pre-rebuild OTP-only account (no password) reaches the
 * one-time "verify to set your password" flow automatically from the
 * login screen, completes it, and then logs in by password like any
 * other account.
 */
class CustomerPasswordMigrationTest extends TestCase
{
    use RebuiltAuthHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeFirebase();
    }

    public function test_login_with_a_passwordless_account_redirects_into_the_migration_flow(): void
    {
        $legacy = $this->legacyPasswordlessCustomer();

        Livewire::test(Login::class)
            ->set('identifier', $legacy->phone)
            ->set('password', 'irrelevant1')
            ->call('login')
            ->assertRedirect(route('customer.auth.migrate', ['identifier' => $legacy->phone]));
    }

    public function test_the_full_migration_path_sets_a_password_and_signs_in(): void
    {
        $legacy = $this->legacyPasswordlessCustomer();

        $c = Livewire::test(PasswordMigration::class, ['identifier' => $legacy->phone])
            ->assertSet('step', 'verify_phone');

        $token = $this->firebase->issuePhoneToken($this->e164($legacy->phone));
        $c->call('phoneTokenReceived', $token)->assertSet('step', 'set_password');

        $c->set('password', 'my-new-pass-1')
            ->set('password_confirmation', 'my-new-pass-1')
            ->call('savePassword')
            ->assertRedirect(route('customer.home'));

        $legacy->refresh();
        $this->assertTrue(Hash::check('my-new-pass-1', $legacy->password));
        $this->assertNotNull($legacy->phone_verified_at);
        $this->assertAuthenticatedAs($legacy);

        // And now a normal password login works.
        auth()->logout();
        Livewire::test(Login::class)
            ->set('identifier', $legacy->phone)
            ->set('password', 'my-new-pass-1')
            ->call('login')
            ->assertRedirect(route('customer.home'));
        $this->assertAuthenticatedAs($legacy->fresh());
    }

    public function test_a_mismatched_phone_token_does_not_advance_the_flow(): void
    {
        $legacy = $this->legacyPasswordlessCustomer();

        $token = $this->firebase->issuePhoneToken($this->e164($this->randomPhone()));

        Livewire::test(PasswordMigration::class, ['identifier' => $legacy->phone])
            ->call('phoneTokenReceived', $token)
            ->assertSet('error', 'That verification does not match your account.')
            ->assertSet('step', 'verify_phone');
    }

    public function test_an_account_that_already_has_a_password_is_bounced_to_login(): void
    {
        $user = $this->passwordCustomer('already-set-1');

        Livewire::test(PasswordMigration::class, ['identifier' => $user->phone])
            ->assertRedirect(route('customer.login'));
    }
}
