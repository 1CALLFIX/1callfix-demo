<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Auth\Login as WebLogin;
use App\Livewire\Customer\Auth\Signup;
use App\Models\User;
use App\Services\Auth\CustomerAccountResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Support\RebuiltAuthHelpers;
use Tests\TestCase;

/**
 * Post-rebuild equivalence guarantee: the API token path
 * (POST /api/auth/{register,password,firebase}) and the customer WEB
 * session path (Livewire Customer\Auth\{Signup,Login}) resolve a given
 * identity to the IDENTICAL users row — both go through the one shared
 * App\Services\Auth\CustomerAccountResolver, so a provisioning rule
 * changed in that single place is observed by both surfaces.
 *
 * The row comparison reads the persisted record with the query builder,
 * independent of whatever object either code path returned.
 */
class AuthPathEquivalenceTest extends TestCase
{
    use RebuiltAuthHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeFirebase();
        Notification::fake();
    }

    private function rawRow(string $phone): array
    {
        $rows = DB::table('users')->where('phone', $phone)->get()->all();
        $this->assertCount(1, $rows, "Expected exactly one users row for {$phone}.");

        return (array) $rows[0];
    }

    public function test_a_customer_registered_via_the_api_is_the_same_row_the_web_login_resolves(): void
    {
        $phone = $this->randomPhone();

        // --- API register ------------------------------------------------
        $apiUserId = (int) $this->postJson('/api/auth/register', [
            'id_token' => $this->firebase->issuePhoneToken($this->e164($phone)),
            'name' => 'Path A', 'password' => 'shared-pass-1',
        ])->assertCreated()->json('user.id');

        $rowAfterApi = $this->rawRow($phone);
        $this->assertSame($rowAfterApi['id'], $apiUserId);

        // --- Web password login, same phone ----------------------------
        Livewire::test(WebLogin::class)
            ->set('identifier', $phone)
            ->set('password', 'shared-pass-1')
            ->call('login')
            ->assertRedirect(route('customer.home'));

        $webUserId = (int) Auth::guard('web')->id();
        $rowAfterWeb = $this->rawRow($phone);

        $this->assertSame($apiUserId, $webUserId, 'Web login resolved a different row than the API register.');
        $this->assertSame($rowAfterApi, $rowAfterWeb, 'Web login mutated or replaced the API-created row.');
        $this->assertSame(1, User::where('phone', $phone)->count());
    }

    public function test_a_customer_who_signed_up_on_the_web_authenticates_identically_via_the_api(): void
    {
        $phone = $this->randomPhone();

        // --- Web signup ------------------------------------------------
        $c = Livewire::test(Signup::class)->set('phone', $phone);
        $c->call('requestPhoneCode')
            ->call('phoneTokenReceived', $this->firebase->issuePhoneToken($this->e164($phone)))
            ->set('name', 'Path B')
            ->set('password', 'shared-pass-2')
            ->set('password_confirmation', 'shared-pass-2')
            ->call('completeSignup')
            ->assertRedirect(route('customer.home'));

        $webUserId = (int) Auth::guard('web')->id();
        $rowAfterWeb = $this->rawRow($phone);
        Auth::guard('web')->logout();

        // --- API password login, same phone --------------------------
        $apiUserId = (int) $this->postJson('/api/auth/password', [
            'identifier' => $phone, 'password' => 'shared-pass-2', 'actor_type' => 'customer',
        ])->assertOk()->json('user.id');

        $this->assertSame($webUserId, $apiUserId);
        $this->assertSame($rowAfterWeb, $this->rawRow($phone), 'API login mutated or replaced the web-created row.');
        $this->assertSame(1, User::where('phone', $phone)->count());
    }

    public function test_a_firebase_phone_login_lands_on_the_same_row_as_the_password_login(): void
    {
        $user = $this->passwordCustomer('shared-pass-3');

        // API firebase-phone login.
        $apiId = (int) $this->postJson('/api/auth/firebase', [
            'id_token' => $this->firebase->issuePhoneToken($this->e164($user->phone)),
            'actor_type' => 'customer',
        ])->assertOk()->json('user.id');

        // Web password login.
        Livewire::test(WebLogin::class)
            ->set('identifier', $user->phone)->set('password', 'shared-pass-3')->call('login')
            ->assertRedirect(route('customer.home'));

        $this->assertSame($user->id, $apiId);
        $this->assertSame($user->id, (int) Auth::guard('web')->id());
        $this->assertSame(1, User::where('phone', $user->phone)->count());
    }

    public function test_both_surfaces_provision_through_the_one_shared_resolver(): void
    {
        $spy = new class extends CustomerAccountResolver
        {
            public array $completeSignupCalls = [];

            public function completeSignup(\App\Services\Auth\FirebaseIdentity $phoneIdentity, string $plainPassword, ?string $name = null, ?string $verifiedEmail = null): array
            {
                $this->completeSignupCalls[] = $phoneIdentity->phoneNumber;

                return parent::completeSignup($phoneIdentity, $plainPassword, $name, $verifiedEmail);
            }
        };
        $this->app->instance(CustomerAccountResolver::class, $spy);

        $apiPhone = $this->randomPhone();
        $this->postJson('/api/auth/register', [
            'id_token' => $this->firebase->issuePhoneToken($this->e164($apiPhone)),
            'name' => 'Api Person', 'password' => 'password-aaa',
        ])->assertCreated();

        $webPhone = $this->randomPhone();
        Livewire::test(Signup::class)
            ->set('phone', $webPhone)
            ->call('requestPhoneCode')
            ->call('phoneTokenReceived', $this->firebase->issuePhoneToken($this->e164($webPhone)))
            ->set('name', 'Web Person')->set('password', 'password-bbb')->set('password_confirmation', 'password-bbb')
            ->call('completeSignup')
            ->assertHasNoErrors();

        $this->assertSame(
            [$this->e164($apiPhone), $this->e164($webPhone)],
            $spy->completeSignupCalls,
            'Both surfaces must provision through the shared CustomerAccountResolver::completeSignup().'
        );
    }
}
