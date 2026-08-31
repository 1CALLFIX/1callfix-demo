<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Auth\GoogleAuth;
use App\Livewire\Customer\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\RebuiltAuthHelpers;
use Tests\TestCase;

/**
 * Task 5 — Google sign-in with mandatory mobile verification.
 *
 *  • new Google identity → verify a mobile → account created + active
 *  • Google email matching an existing account → NOT auto-linked; the
 *    account's own mobile must be verified first
 *  • abandoned verification → no orphaned / partially-linked account
 */
class CustomerGoogleAuthTest extends TestCase
{
    use RebuiltAuthHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeFirebase();
    }

    /** Puts a server-verified Google identity in the session, the way Login::continueWithGoogle does. */
    private function stashGoogle(string $email, string $uid = 'guid-1', string $name = 'Google User'): void
    {
        session()->put('auth.google', [
            'uid' => $uid, 'email' => $email, 'name' => $name, 'picture' => null,
            'email_verified' => true, 'verified_at' => now()->timestamp,
        ]);
    }

    public function test_new_google_user_completes_signup_after_verifying_a_mobile(): void
    {
        $this->stashGoogle('newby@example.com', 'guid-new');
        $phone = $this->randomPhone();

        $c = Livewire::test(GoogleAuth::class)->assertSet('mode', 'new');
        $token = $this->firebase->issuePhoneToken($this->e164($phone));

        $c->set('phone', $phone)
            ->call('requestPhoneCode')
            ->call('phoneTokenReceived', $token)
            ->assertRedirect(route('customer.home'));

        $user = User::where('email', 'newby@example.com')->sole();
        $this->assertSame($phone, $user->phone);
        $this->assertSame('guid-new', $user->google_id);
        $this->assertSame('active', $user->status);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertAuthenticatedAs($user);
    }

    public function test_google_email_matching_an_existing_account_requires_that_accounts_mobile_before_linking(): void
    {
        $existing = $this->passwordCustomer('their-password', ['email' => 'known@example.com']);
        $this->assertNull($existing->google_id);

        $this->stashGoogle('known@example.com', 'guid-link');
        $c = Livewire::test(GoogleAuth::class)->assertSet('mode', 'link')->assertSet('linkUserId', $existing->id);

        // A DIFFERENT number must not link.
        $wrong = $this->firebase->issuePhoneToken($this->e164($this->randomPhone()));
        $c->call('phoneTokenReceived', $wrong)
            ->assertSet('error', fn ($e) => str_contains($e, 'does not match'));
        $this->assertNull($existing->fresh()->google_id, 'No link on a mismatched number.');
        $this->assertGuest();

        // The account's OWN number links it.
        $right = $this->firebase->issuePhoneToken($this->e164($existing->phone));
        $c->call('phoneTokenReceived', $right)->assertRedirect(route('customer.home'));

        $existing->refresh();
        $this->assertSame('guid-link', $existing->google_id);
        $this->assertSame('guid-link', $existing->firebase_uid);
        $this->assertAuthenticatedAs($existing);
    }

    public function test_an_abandoned_verification_leaves_no_account(): void
    {
        $this->stashGoogle('ghost@example.com', 'guid-ghost');

        Livewire::test(GoogleAuth::class); // mount only, user never verifies a phone

        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    public function test_a_google_token_that_is_not_a_google_provider_is_rejected_at_login(): void
    {
        // A phone-provider token fed into the Google entry point.
        $token = $this->firebase->issuePhoneToken($this->e164($this->randomPhone()));

        Livewire::test(Login::class)
            ->call('continueWithGoogle', $token)
            ->assertSet('googleError', 'That sign-in was not a Google account.');

        $this->assertGuest();
    }

    public function test_a_rejected_google_token_shows_an_error(): void
    {
        $bad = 'fake-google-bad';
        $this->firebase->rejectToken($bad);

        Livewire::test(Login::class)
            ->call('continueWithGoogle', $bad)
            ->assertSet('googleError', 'Could not verify that Google sign-in. Please try again.');
    }
}
