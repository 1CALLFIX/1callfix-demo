<?php

namespace Tests\Feature\CustomerWeb;

use App\Contracts\SmsAdapter;
use App\Livewire\Customer\Auth\Login;
use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\CustomerAccountResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\CapturingSmsAdapter;
use Tests\TestCase;

/**
 * Phase C close-out: proves the API auth path (token, POST /api/auth/otp/*)
 * and the customer WEB auth path (session, Livewire Customer\Auth\Login)
 * resolve a given phone number to the IDENTICAL users row after the
 * CustomerAccountResolver extraction — not merely to "a user with the same
 * phone number".
 *
 * Both directions are exercised (API-first then web, and web-first then
 * API). No duplicate account is created to satisfy either test: the second
 * leg of each test is a LOGIN against the account the first leg produced,
 * which is exactly the case that would break if the two paths had drifted.
 *
 * Nothing about OTP verification is mocked — only the outbound SMS is
 * captured, using the same CapturingSmsAdapter that
 * tests/Feature/Auth/AuthOtpTest.php uses. The row comparison is done with
 * the DB query builder rather than through Eloquent, so it reads the
 * persisted row itself independently of whatever object either code path
 * happened to return.
 */
class AuthPathEquivalenceTest extends TestCase
{
    use RefreshDatabase;

    private CapturingSmsAdapter $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sms = new CapturingSmsAdapter;
        $this->app->instance(SmsAdapter::class, $this->sms);

        // OtpService refuses a second generate() inside the resend cooldown.
        // Both tests deliberately request a code twice for the same phone
        // (once per auth surface), so the cooldown is disabled here. Nothing
        // else about OtpService's behaviour is altered.
        Setting::set('auth.otp_resend_cooldown_seconds', '0');
    }

    private function phone(): string
    {
        return '9'.fake()->unique()->numerify('#########');
    }

    /** Reads the persisted row directly, bypassing Eloquent entirely. */
    private function rawRow(string $phone): array
    {
        $rows = DB::table('users')->where('phone', $phone)->get()->all();
        $this->assertCount(1, $rows, "Expected exactly one users row for {$phone}, found ".count($rows).'.');

        return (array) $rows[0];
    }

    private function loginViaApi(string $phone): int
    {
        $this->postJson('/api/auth/otp/request', ['phone' => $phone, 'actor_type' => 'customer'])
            ->assertOk();

        $code = $this->sms->lastCodeTo($phone);
        $this->assertNotNull($code, 'The real OtpService must have sent a real code for the API path.');

        $response = $this->postJson('/api/auth/otp/verify', [
            'phone' => $phone,
            'actor_type' => 'customer',
            'code' => $code,
        ])->assertOk();

        return (int) $response->json('user.id');
    }

    private function loginViaWeb(string $phone): int
    {
        $component = Livewire::test(Login::class)
            ->set('phone', $phone)
            ->call('requestCode')
            ->assertSet('step', 'code')
            ->assertSet('error', '');

        $code = $this->sms->lastCodeTo($phone);
        $this->assertNotNull($code, 'The real OtpService must have sent a real code for the web path.');

        $component->set('code', $code)
            ->call('verifyCode')
            ->assertSet('error', '')
            ->assertRedirect(route('customer.home'));

        $this->assertAuthenticated();

        return (int) Auth::guard('web')->id();
    }

    /**
     * Path A creates the account through the API; Path B then logs the SAME
     * phone in through the web screen. The web login must land on the row
     * the API created — it must not provision a second account, and it must
     * not mutate the row it found.
     */
    public function test_api_created_customer_is_the_same_database_row_the_web_login_resolves(): void
    {
        $phone = $this->phone();

        // --- Path A: API -------------------------------------------------
        $apiUserId = $this->loginViaApi($phone);
        $rowAfterApi = $this->rawRow($phone);

        $this->assertSame($rowAfterApi['id'], $apiUserId, 'API response id must match the persisted row.');
        $this->assertSame('customer', $rowAfterApi['role']);

        // --- Path B: web, same phone -------------------------------------
        $webUserId = $this->loginViaWeb($phone);
        $rowAfterWeb = $this->rawRow($phone);

        // Identical ID and identical row — not merely the same phone number.
        $this->assertSame($apiUserId, $webUserId, 'Web login resolved a DIFFERENT user id than the API path.');
        $this->assertSame($rowAfterApi, $rowAfterWeb, 'Web login mutated or replaced the row the API path created.');
        $this->assertSame(1, User::where('phone', $phone)->count(), 'Web login must not duplicate the API-created account.');
        $this->assertAuthenticatedAs(User::findOrFail($apiUserId));
    }

    /**
     * The reverse direction: the account is created through the web screen,
     * then authenticated through the API. Same id, same row, no duplicate.
     */
    public function test_web_created_customer_is_the_same_database_row_the_api_login_resolves(): void
    {
        $phone = $this->phone();

        // --- Path A: web -------------------------------------------------
        $webUserId = $this->loginViaWeb($phone);
        $rowAfterWeb = $this->rawRow($phone);

        $this->assertSame($rowAfterWeb['id'], $webUserId, 'Session user id must match the persisted row.');
        $this->assertSame('customer', $rowAfterWeb['role']);

        // Drop the session so the API leg authenticates on its own merits.
        Auth::guard('web')->logout();
        $this->assertGuest();

        // --- Path B: API, same phone -------------------------------------
        $apiUserId = $this->loginViaApi($phone);
        $rowAfterApi = $this->rawRow($phone);

        $this->assertSame($webUserId, $apiUserId, 'API login resolved a DIFFERENT user id than the web path.');
        $this->assertSame($rowAfterWeb, $rowAfterApi, 'API login mutated or replaced the row the web path created.');
        $this->assertSame(1, User::where('phone', $phone)->count(), 'API login must not duplicate the web-created account.');
    }

    /**
     * Guards the extraction itself. Both surfaces must reach the ONE shared
     * CustomerAccountResolver, so that a provisioning rule changed in that
     * single place is observed by both. A drifting second copy inside either
     * surface would not be affected by this container rebinding, and the
     * assertion below would fail. The spy still calls parent::resolve(), so
     * the real provisioning behaviour is what actually runs.
     */
    public function test_both_surfaces_provision_through_the_one_shared_resolver(): void
    {
        $spy = new class extends CustomerAccountResolver
        {
            /** @var array<int, string> */
            public array $calls = [];

            public function resolve(string $phone): User
            {
                $this->calls[] = $phone;

                return parent::resolve($phone);
            }
        };

        $this->app->instance(CustomerAccountResolver::class, $spy);

        $apiPhone = $this->phone();
        $apiUserId = $this->loginViaApi($apiPhone);

        $webPhone = $this->phone();
        $webUserId = $this->loginViaWeb($webPhone);

        $this->assertSame([$apiPhone, $webPhone], $spy->calls, 'Both auth surfaces must provision through CustomerAccountResolver.');
        $this->assertSame($apiUserId, (int) User::where('phone', $apiPhone)->value('id'));
        $this->assertSame($webUserId, (int) User::where('phone', $webPhone)->value('id'));
    }
}
