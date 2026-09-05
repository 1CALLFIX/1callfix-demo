<?php

namespace Tests\Feature\Providers;

use App\Actions\SetProviderCommissionAgreementAction;
use App\Models\ActivityLog;
use App\Models\ProviderCommissionAgreement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Provider Commercial Rate Resolver phase — Step 10 B. The only writer of
 * provider_commission_agreements (tier 1 of ProviderCommercialRateResolver).
 */
class SetProviderCommissionAgreementActionTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    private function makeAdmin(): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Admin', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'super_admin', 'status' => 'active',
        ]);
    }

    public function test_set_creates_an_agreement_and_logs_activity(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $admin = $this->makeAdmin();

        app(SetProviderCommissionAgreementAction::class)->set($provider, 18.5, 'Volume discount', $admin);

        $agreement = ProviderCommissionAgreement::where('provider_id', $provider->id)->first();
        $this->assertNotNull($agreement);
        $this->assertEquals(18.5, $agreement->platform_fee_percent);
        $this->assertSame('Volume discount', $agreement->notes);
        $this->assertSame($admin->id, $agreement->set_by_user_id);

        $this->assertTrue(
            ActivityLog::where('subject_type', get_class($provider))->where('subject_id', $provider->id)->exists(),
            'Setting a commercial rate must be audited.'
        );
    }

    public function test_set_upserts_a_second_time_rather_than_creating_a_duplicate_row(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $admin = $this->makeAdmin();
        $action = app(SetProviderCommissionAgreementAction::class);

        $action->set($provider, 10, 'first', $admin);
        $action->set($provider, 25, 'revised', $admin);

        $this->assertSame(1, ProviderCommissionAgreement::where('provider_id', $provider->id)->count());
        $this->assertEquals(25.0, $provider->commissionAgreement()->first()->platform_fee_percent);
    }

    public function test_set_rejects_a_percent_outside_zero_to_one_hundred(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $admin = $this->makeAdmin();

        $this->expectException(ValidationException::class);

        app(SetProviderCommissionAgreementAction::class)->set($provider, 150, null, $admin);
    }

    public function test_clear_deletes_the_agreement_and_logs_activity(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $admin = $this->makeAdmin();
        $action = app(SetProviderCommissionAgreementAction::class);
        $action->set($provider, 15, null, $admin);

        $action->clear($provider, $admin);

        $this->assertFalse(ProviderCommissionAgreement::where('provider_id', $provider->id)->exists());
        $this->assertTrue(
            ActivityLog::where('subject_type', get_class($provider))
                ->where('subject_id', $provider->id)
                ->where('description', 'like', '%cleared%')
                ->exists()
        );
    }

    public function test_clear_is_a_noop_when_no_agreement_exists(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $admin = $this->makeAdmin();

        // Must not throw, must not create anything.
        app(SetProviderCommissionAgreementAction::class)->clear($provider, $admin);

        $this->assertFalse(ProviderCommissionAgreement::where('provider_id', $provider->id)->exists());
    }
}
