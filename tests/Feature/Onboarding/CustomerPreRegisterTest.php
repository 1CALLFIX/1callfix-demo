<?php

namespace Tests\Feature\Onboarding;

use App\Contracts\SmsAdapter;
use App\Livewire\Customers\Index as CustomersIndex;
use App\Models\User;
use App\Services\Onboarding\CustomerPreRegisterImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Support\CapturingSmsAdapter;
use Tests\TestCase;

/**
 * Export Everywhere + Import Where It's Safe session, Part 3 — Bulk
 * Pre-Register (Customers). The mission's own hard requirement, proven
 * here through the REAL verification mechanism (AuthController::
 * verifyOtp() / OtpService), not a stand-in check invented for this test:
 * a pre-registered customer has no Sanctum token and no other way to
 * authenticate except by completing a genuine OTP request/verify —
 * exactly the same flow a brand-new, never-imported customer goes
 * through on their real first login.
 */
class CustomerPreRegisterTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;

    private CapturingSmsAdapter $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sms = new CapturingSmsAdapter;
        $this->app->instance(SmsAdapter::class, $this->sms);
    }

    private function rows(array $rows): Collection
    {
        return collect($rows)->map(fn ($r) => collect($r));
    }

    public function test_valid_row_creates_a_pending_customer_shell(): void
    {
        $phone = '9'.fake()->unique()->numerify('#########');

        $result = (new CustomerPreRegisterImporter)->validateRows($this->rows([
            ['name' => 'Pre-Reg Customer', 'phone' => $phone],
        ]));

        $this->assertEmpty($result['errors']);
        $run = (new CustomerPreRegisterImporter)->commit($result['previewRows'], null, 'customers.csv');

        $this->assertSame(1, $run->created_count);
        $user = User::where('phone', $phone)->sole();
        $this->assertSame('customer', $user->role);
        $this->assertSame('Pre-Reg Customer', $user->name);
        $this->assertNull($user->phone_verified_at, 'Must never be phone-verified via import, under any circumstance.');
    }

    public function test_missing_phone_is_rejected(): void
    {
        $result = (new CustomerPreRegisterImporter)->validateRows($this->rows([
            ['name' => 'No Phone'],
        ]));

        $this->assertNotEmpty($result['errors']);
        $this->assertSame('phone', $result['errors'][0]['field']);
    }

    public function test_phone_already_registered_as_a_provider_is_rejected_not_silently_converted(): void
    {
        $providerUser = User::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'A Provider', 'phone' => '9111111111', 'role' => 'provider', 'status' => 'active']);

        $result = (new CustomerPreRegisterImporter)->validateRows($this->rows([
            ['name' => 'Wants To Be Customer', 'phone' => $providerUser->phone],
        ]));

        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('already registered as a provider', $result['errors'][0]['message']);
    }

    public function test_reupload_of_an_already_registered_phone_is_skipped_not_duplicated(): void
    {
        $phone = '9'.fake()->unique()->numerify('#########');
        $importer = new CustomerPreRegisterImporter;
        $first = $importer->validateRows($this->rows([['name' => 'Original', 'phone' => $phone]]));
        $importer->commit($first['previewRows'], null, 'a.csv');
        $this->assertSame(1, User::where('phone', $phone)->count());

        $second = $importer->validateRows($this->rows([['name' => 'Original', 'phone' => $phone]]));
        $this->assertSame('skipped_existing', $second['previewRows'][0]['outcome']);
        $importer->commit($second['previewRows'], null, 'a.csv');

        $this->assertSame(1, User::where('phone', $phone)->count(), 'Re-uploading an already-registered phone must never create a second account.');
    }

    /**
     * THE regression test the mission spec asks for by name: "an imported
     * customer cannot book without completing real OTP first."
     */
    public function test_a_pre_registered_customer_has_no_token_and_cannot_call_authenticated_endpoints(): void
    {
        $phone = '9'.fake()->unique()->numerify('#########');
        $result = (new CustomerPreRegisterImporter)->validateRows($this->rows([['name' => 'Shell', 'phone' => $phone]]));
        (new CustomerPreRegisterImporter)->commit($result['previewRows'], null, 'customers.csv');

        $user = User::where('phone', $phone)->sole();
        $this->assertSame(0, $user->tokens()->count(), 'Import must never issue a Sanctum token.');

        // No Authorization header at all — the only way this customer
        // could have one is by completing a real OTP verify below.
        $this->postJson('/api/bookings', ['service_id' => 1, 'address_id' => 1])
            ->assertStatus(401);
    }

    /**
     * The flip side of the same guarantee: the real OTP flow is NOT
     * blocked by having been pre-registered — it correctly resolves to
     * the SAME shell (never a duplicate), exactly like an organic
     * first-login would for a phone that already has a row for any other
     * reason (see AuthOtpTest::test_customer_login_reuses_existing_account_by_phone).
     */
    public function test_completing_real_otp_verification_claims_the_pre_registered_shell_not_a_duplicate(): void
    {
        $phone = '9'.fake()->unique()->numerify('#########');
        $result = (new CustomerPreRegisterImporter)->validateRows($this->rows([['name' => 'Shell', 'phone' => $phone]]));
        (new CustomerPreRegisterImporter)->commit($result['previewRows'], null, 'customers.csv');
        $preRegisteredId = User::where('phone', $phone)->sole()->id;

        $this->postJson('/api/auth/otp/request', ['phone' => $phone, 'actor_type' => 'customer'])->assertOk();
        $code = $this->sms->lastCodeTo($phone);
        $this->assertNotNull($code);

        $response = $this->postJson('/api/auth/otp/verify', ['phone' => $phone, 'actor_type' => 'customer', 'code' => $code]);

        $response->assertOk()->assertJsonPath('user.id', $preRegisteredId);
        $this->assertNotEmpty($response->json('token'), 'A real token is only ever issued through the real OTP flow.');
        $this->assertSame(1, User::where('phone', $phone)->count(), 'Must claim the existing shell, never create a second row.');
    }

    public function test_livewire_screen_bulk_pre_registers_end_to_end_without_setting_phone_verified_at(): void
    {
        $actor = $this->makeUserWithPermission('customers.view', 'global');
        $this->grantPermission($actor, 'customers.manage');
        $phone = '9'.fake()->unique()->numerify('#########');

        // Quoted CSV fields — matches CatalogImportLivewireTest::csv()'s
        // helper exactly; Maatwebsite's CSV reader in this environment
        // does not reliably parse a bare unquoted value in the second
        // (data) row otherwise.
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent(
            'customers.csv',
            "\"name\",\"phone\"\n\"Screen Customer\",\"{$phone}\"\n"
        );

        $component = Livewire::actingAs($actor)->test(CustomersIndex::class)
            ->set('customersPreregFile', $file)
            ->call('validateCustomersPrereg')
            ->assertOk();

        $this->assertEmpty($component->get('customersPreregErrors'));
        $component->call('commitCustomersPrereg')->assertOk();

        $this->assertDatabaseHas('users', ['phone' => $phone, 'role' => 'customer']);
        $this->assertNull(User::where('phone', $phone)->sole()->phone_verified_at);
    }
}
