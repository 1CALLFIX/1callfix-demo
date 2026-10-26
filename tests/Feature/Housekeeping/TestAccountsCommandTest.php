<?php

namespace Tests\Feature\Housekeeping;

use App\Livewire\Auth\Login as AdminLogin;
use App\Livewire\Rider\Auth\Login as RiderLogin;
use App\Models\FieldWorker;
use App\Models\Provider;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/** Owner request 2026-10-21: one test login per account type, one shared password, switched off before launch. */
class TestAccountsCommandTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private const PASSWORD = 'test-pass-123';

    public function test_create_makes_one_working_login_per_type_and_is_safe_to_rerun(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();

        $this->artisan('test-accounts', ['action' => 'create', '--password' => self::PASSWORD, '--franchise' => $franchise->id])->assertSuccessful();
        $this->artisan('test-accounts', ['action' => 'create', '--password' => self::PASSWORD, '--franchise' => $franchise->id])->assertSuccessful();

        $this->assertSame(9, User::where('email', 'like', '%@1callfix.test')->count());
        $operator = User::where('email', 'test.operator@1callfix.test')->first();
        $this->assertSame(1, RoleAssignment::where('user_id', $operator->id)->count());
        $this->assertTrue($operator->hasPermission('bookings.reassign', ['franchise_id' => $franchise->id]));
        $this->assertFalse($operator->hasPermission('settings.manage'));
        $this->assertTrue(Provider::where('user_id', User::where('email', 'test.provider@1callfix.test')->value('id'))->where('kyc_status', 'approved')->exists());
        $this->assertFalse(User::where('email', 'like', '%@1callfix.test')->where('role', 'super_admin')->exists());

        Livewire::test(AdminLogin::class)
            ->set('email', 'test.operator@1callfix.test')
            ->set('password', self::PASSWORD)
            ->call('submit')
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_disable_shuts_every_test_login(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        $this->artisan('test-accounts', ['action' => 'create', '--password' => self::PASSWORD, '--franchise' => $franchise->id])->assertSuccessful();

        $this->artisan('test-accounts', ['action' => 'disable'])->assertSuccessful();

        $this->assertSame(0, User::where('email', 'like', '%@1callfix.test')->where('status', '!=', 'suspended')->count());
        $this->assertSame(0, RoleAssignment::whereIn('user_id', User::where('email', 'like', '%@1callfix.test')->pluck('id'))->count());

        auth()->logout();
        Livewire::test(AdminLogin::class)
            ->set('email', 'test.operator@1callfix.test')
            ->set('password', self::PASSWORD)
            ->call('submit')
            ->assertNoRedirect();
    }

    /** Owner thumb rule 2026-10-26: every module gets a test login for a real walk-through; parcel's is the rider. */
    public function test_create_makes_an_approved_test_rider_who_can_sign_in_and_disable_turns_it_off(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        $this->artisan('test-accounts', ['action' => 'create', '--password' => self::PASSWORD, '--franchise' => $franchise->id])->assertSuccessful();
        $this->artisan('test-accounts', ['action' => 'create', '--password' => self::PASSWORD, '--franchise' => $franchise->id])->assertSuccessful();

        $rider = FieldWorker::whereHas('user', fn ($q) => $q->where('email', 'test.rider@1callfix.test'))->sole();
        $this->assertSame('approved', $rider->kyc_status);
        $this->assertTrue($rider->is_active);
        $this->assertSame($franchise->id, $rider->franchise_id);
        $this->assertSame(1, $rider->capabilities()->where('capability_type', 'parcel_rider')->count());
        $this->assertFalse(Provider::where('user_id', $rider->user_id)->exists(), 'a rider is never a provider');
        $this->assertSame(1, app(\App\Services\Parcel\ParcelReadiness::class)->riderCount('franchise', $franchise->id));

        Livewire::test(RiderLogin::class)->set('identifier', 'test.rider@1callfix.test')->set('password', self::PASSWORD)
            ->call('login')->assertRedirect(route('rider.dashboard'));

        auth()->logout();
        $this->artisan('test-accounts', ['action' => 'disable'])->assertSuccessful();
        $this->assertFalse($rider->fresh()->is_active);
        $this->assertSame(0, app(\App\Services\Parcel\ParcelReadiness::class)->riderCount('franchise', $franchise->id));
    }

    public function test_create_refuses_a_missing_or_short_password(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();

        $this->artisan('test-accounts', ['action' => 'create', '--password' => 'short', '--franchise' => $franchise->id])->assertFailed();
        $this->assertSame(0, User::where('email', 'like', '%@1callfix.test')->count());
    }
}
