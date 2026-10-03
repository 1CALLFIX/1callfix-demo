<?php

namespace Tests\Feature\Admin;

use App\Livewire\Account\MyAccount;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/** Admin "My account" — own name/email/phone/password, always behind the current password. */
class MyAccountTest extends TestCase
{
    use RbacTestHelpers;
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = $this->makeSuperAdmin();
        $admin->forceFill([
            'email' => 'old@example.com',
            'phone' => '9000000001',
            'password' => Hash::make('old-password-1'),
            'phone_verified_at' => now(),
        ])->save();

        RateLimiter::clear('admin-account:'.$admin->id);

        return $admin->fresh();
    }

    public function test_page_renders_for_a_signed_in_admin_and_redirects_guests(): void
    {
        $this->get(route('admin.account'))->assertRedirect();

        $this->actingAs($this->admin())->get(route('admin.account'))->assertOk()->assertSee('My account');
    }

    public function test_details_change_with_the_right_password_and_are_audit_logged(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(MyAccount::class)
            ->set('name', 'New Name')
            ->set('email', 'new@example.com')
            ->set('emailConfirmation', 'new@example.com')
            ->set('phone', '+91 98765 43210')
            ->set('profileCurrentPassword', 'old-password-1')
            ->call('saveProfile')
            ->assertHasNoErrors();

        $admin->refresh();
        $this->assertSame('New Name', $admin->name);
        $this->assertSame('new@example.com', $admin->email);
        $this->assertSame('9876543210', $admin->phone);
        $this->assertNull($admin->phone_verified_at);

        $log = ActivityLog::where('description', 'admin account details changed')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertStringNotContainsString('old-password-1', json_encode($log->properties));
    }

    public function test_details_are_rejected_with_a_wrong_current_password(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(MyAccount::class)
            ->set('email', 'new@example.com')
            ->set('emailConfirmation', 'new@example.com')
            ->set('profileCurrentPassword', 'wrong')
            ->call('saveProfile')
            ->assertHasErrors('profileCurrentPassword');

        $this->assertSame('old@example.com', $admin->fresh()->email);
    }

    public function test_email_and_phone_must_be_unique_to_other_accounts(): void
    {
        $admin = $this->admin();
        User::factory()->create(['uuid' => (string) Str::uuid(), 'email' => 'taken@example.com', 'phone' => '9111111111']);

        Livewire::actingAs($admin)->test(MyAccount::class)
            ->set('email', 'taken@example.com')
            ->set('phone', '9111111111')
            ->set('profileCurrentPassword', 'old-password-1')
            ->call('saveProfile')
            ->assertHasErrors(['email', 'phone']);

        $this->assertSame('old@example.com', $admin->fresh()->email);
    }

    public function test_keeping_own_email_and_phone_is_not_a_uniqueness_clash(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(MyAccount::class)
            ->set('name', 'Renamed')
            ->set('profileCurrentPassword', 'old-password-1')
            ->call('saveProfile')
            ->assertHasNoErrors();

        $this->assertSame('Renamed', $admin->fresh()->name);
    }

    public function test_phone_must_be_ten_digits(): void
    {
        Livewire::actingAs($this->admin())->test(MyAccount::class)
            ->set('phone', '12345')
            ->set('profileCurrentPassword', 'old-password-1')
            ->call('saveProfile')
            ->assertHasErrors('phone');
    }

    public function test_password_changes_hashed_and_new_password_logs_in(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(MyAccount::class)
            ->set('currentPassword', 'old-password-1')
            ->set('newPassword', 'brand-new-pass-9')
            ->set('newPasswordConfirmation', 'brand-new-pass-9')
            ->call('changePassword')
            ->assertHasNoErrors();

        $admin->refresh();
        $this->assertNotSame('brand-new-pass-9', $admin->password);
        $this->assertTrue(Hash::check('brand-new-pass-9', $admin->password));
        $this->assertFalse(Hash::check('old-password-1', $admin->password));
        $this->assertNotNull(ActivityLog::where('description', 'admin password changed')->first());

        auth()->logout();
        $this->assertTrue(auth()->attempt(['email' => 'old@example.com', 'password' => 'brand-new-pass-9']));
    }

    public function test_password_change_rejects_wrong_current_short_mismatched_or_unchanged(): void
    {
        $admin = $this->admin();
        $c = Livewire::actingAs($admin)->test(MyAccount::class);

        $c->set('currentPassword', 'nope')->set('newPassword', 'brand-new-pass-9')->set('newPasswordConfirmation', 'brand-new-pass-9')
            ->call('changePassword')->assertHasErrors('currentPassword');

        $c->set('currentPassword', 'old-password-1')->set('newPassword', 'short')->set('newPasswordConfirmation', 'short')
            ->call('changePassword')->assertHasErrors('newPassword');

        $c->set('newPassword', 'brand-new-pass-9')->set('newPasswordConfirmation', 'different-pass-9')
            ->call('changePassword')->assertHasErrors('newPassword');

        $c->set('newPassword', 'old-password-1')->set('newPasswordConfirmation', 'old-password-1')
            ->call('changePassword')->assertHasErrors('newPassword');

        $this->assertTrue(Hash::check('old-password-1', $admin->fresh()->password));
    }

    public function test_wrong_password_attempts_are_throttled(): void
    {
        $admin = $this->admin();
        $c = Livewire::actingAs($admin)->test(MyAccount::class)->set('email', 'new@example.com')
            ->set('emailConfirmation', 'new@example.com');

        for ($i = 0; $i < 5; $i++) {
            $c->set('profileCurrentPassword', 'wrong')->call('saveProfile')->assertHasErrors('profileCurrentPassword');
        }

        // Even the correct password is refused while locked out.
        $c->set('profileCurrentPassword', 'old-password-1')->call('saveProfile')->assertHasErrors('profileCurrentPassword');
        $this->assertSame('old@example.com', $admin->fresh()->email);
    }

    public function test_component_only_ever_edits_the_signed_in_user(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create(['uuid' => (string) Str::uuid(), 'email' => 'other@example.com', 'phone' => '9222222222']);

        Livewire::actingAs($admin)->test(MyAccount::class)
            ->set('name', 'Mine')
            ->set('profileCurrentPassword', 'old-password-1')
            ->call('saveProfile');

        $this->assertSame('other@example.com', $other->fresh()->email);
        $this->assertNotSame('Mine', $other->fresh()->name);
    }
}
