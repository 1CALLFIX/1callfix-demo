<?php

namespace Tests\Feature\Housekeeping;

use App\Livewire\Auth\Login as AdminLogin;
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

        $this->assertSame(8, User::where('email', 'like', '%@1callfix.test')->count());
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

    public function test_create_refuses_a_missing_or_short_password(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();

        $this->artisan('test-accounts', ['action' => 'create', '--password' => 'short', '--franchise' => $franchise->id])->assertFailed();
        $this->assertSame(0, User::where('email', 'like', '%@1callfix.test')->count());
    }
}
