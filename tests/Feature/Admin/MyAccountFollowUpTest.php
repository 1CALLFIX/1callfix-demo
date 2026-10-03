<?php

namespace Tests\Feature\Admin;

use App\Livewire\Account\MyAccount;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AdminLoginChangedNotification;
use App\Support\AdminLoginNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * Step 0e — My account follow-up: (1) a password change signs out the user's
 * other sessions, this one stays; (2) an email or password change emails a
 * notice to the OLD address, with Super Admin-editable, audit-logged copy;
 * (3) changing the login email means typing the new one twice.
 */
class MyAccountFollowUpTest extends TestCase
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
        ])->save();

        RateLimiter::clear('admin-account:'.$admin->id);

        return $admin->fresh();
    }

    private function sessionHashFor(string $passwordHash): string
    {
        $guard = Auth::guard('web');

        return method_exists($guard, 'hashPasswordForCookie') ? $guard->hashPasswordForCookie($passwordHash) : $passwordHash;
    }

    // ============================== 1. other sessions signed out ==============================

    public function test_the_admin_routes_and_livewire_updates_run_the_session_password_check(): void
    {
        $this->assertContains(AuthenticateSession::class, Route::getRoutes()->getByName('admin.dashboard')->gatherMiddleware());
        $this->assertContains(AuthenticateSession::class, Route::getRoutes()->getByName('admin.account')->gatherMiddleware());
        $this->assertContains(AuthenticateSession::class, Livewire::getPersistentMiddleware());
    }

    public function test_after_a_password_change_an_older_session_is_signed_out_and_the_current_one_is_not(): void
    {
        $admin = $this->admin();
        $oldHash = $admin->password;

        // Both "devices" were signed in before the change.
        $this->actingAs($admin)->withSession(['password_hash_web' => $this->sessionHashFor($oldHash)])
            ->get(route('admin.account'))->assertOk();

        Livewire::actingAs($admin)->test(MyAccount::class)
            ->set('currentPassword', 'old-password-1')
            ->set('newPassword', 'brand-new-pass-9')
            ->set('newPasswordConfirmation', 'brand-new-pass-9')
            ->call('changePassword')
            ->assertHasNoErrors();

        $newHash = $admin->fresh()->password;
        $this->assertNotSame($oldHash, $newHash);
        $this->assertTrue(Hash::check('brand-new-pass-9', $newHash));

        // Another device still holding the OLD password hash is signed out on its next request.
        $this->flushSession();
        Auth::forgetGuards();
        $this->actingAs($admin->fresh())->withSession(['password_hash_web' => $this->sessionHashFor($oldHash)])
            ->get(route('admin.account'))->assertRedirect();
        $this->assertGuest();

        // The session that made the change carries the NEW hash and keeps working.
        $this->flushSession();
        Auth::forgetGuards();
        $this->actingAs($admin->fresh())->withSession(['password_hash_web' => $this->sessionHashFor($newHash)])
            ->get(route('admin.account'))->assertOk();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_a_session_with_no_stored_hash_adopts_the_current_one_so_existing_logins_survive_the_deploy(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.account'))->assertOk();

        $this->assertSame($this->sessionHashFor($admin->password), session('password_hash_web'));
    }

    // ============================== 2. notice to the old email ==============================

    public function test_changing_the_email_notifies_the_old_address_only(): void
    {
        Notification::fake();
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(MyAccount::class)
            ->set('email', 'new@example.com')
            ->set('emailConfirmation', 'new@example.com')
            ->set('profileCurrentPassword', 'old-password-1')
            ->call('saveProfile')
            ->assertHasNoErrors();

        Notification::assertSentOnDemand(AdminLoginChangedNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'old@example.com');
        Notification::assertSentOnDemandTimes(AdminLoginChangedNotification::class, 1);
    }

    public function test_changing_the_password_notifies_the_current_email(): void
    {
        Notification::fake();
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(MyAccount::class)
            ->set('currentPassword', 'old-password-1')
            ->set('newPassword', 'brand-new-pass-9')
            ->set('newPasswordConfirmation', 'brand-new-pass-9')
            ->call('changePassword')
            ->assertHasNoErrors();

        Notification::assertSentOnDemand(AdminLoginChangedNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'old@example.com');
    }

    public function test_no_notice_for_a_name_or_phone_only_change_or_a_rejected_attempt(): void
    {
        Notification::fake();
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(MyAccount::class)
            ->set('name', 'Renamed')
            ->set('phone', '9111111111')
            ->set('profileCurrentPassword', 'old-password-1')
            ->call('saveProfile')
            ->assertHasNoErrors();

        Livewire::actingAs($admin)->test(MyAccount::class)
            ->set('email', 'sneaky@example.com')
            ->set('emailConfirmation', 'sneaky@example.com')
            ->set('profileCurrentPassword', 'WRONG')
            ->call('saveProfile')
            ->assertHasErrors('profileCurrentPassword');

        Notification::assertSentOnDemandTimes(AdminLoginChangedNotification::class, 0);
        $this->assertSame('old@example.com', $admin->fresh()->email);
    }

    public function test_the_notice_carries_the_exact_default_wording_what_changed_and_never_a_password(): void
    {
        $mail = (new AdminLoginChangedNotification(AdminLoginNotice::message(), 'password', '03 Oct 2026, 10:42 AM IST'))->toMail(new \Illuminate\Notifications\AnonymousNotifiable);

        $this->assertSame('Your 1CallFix admin login details were changed', $mail->subject);
        $this->assertSame("Your 1CallFix admin login details were changed. If this wasn't you, contact support immediately.", $mail->introLines[0]);
        $this->assertSame(['What changed: password', 'When: 03 Oct 2026, 10:42 AM IST'], array_slice($mail->introLines, 1));
    }

    public function test_a_mail_failure_never_blocks_the_change(): void
    {
        $admin = $this->admin();
        Notification::shouldReceive('route')->andThrow(new \RuntimeException('smtp down'));

        Livewire::actingAs($admin)->test(MyAccount::class)
            ->set('currentPassword', 'old-password-1')
            ->set('newPassword', 'brand-new-pass-9')
            ->set('newPasswordConfirmation', 'brand-new-pass-9')
            ->call('changePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('brand-new-pass-9', $admin->fresh()->password));
    }

    // ============================== 2b. editable copy ==============================

    public function test_super_admin_edits_the_notice_text_it_is_audit_logged_and_used(): void
    {
        $admin = $this->admin();
        $text = "Your admin sign-in details changed. If that wasn't you, call support now.";

        Livewire::actingAs($admin)->test(MyAccount::class)
            ->assertSet('noticeText', AdminLoginNotice::DEFAULT)
            ->set('noticeText', $text)
            ->call('saveNotice')
            ->assertHasNoErrors();

        $this->assertSame($text, AdminLoginNotice::message());
        $this->assertNotNull(ActivityLog::where('description', 'Setting security.admin_login_change_notice changed')->first());

        Notification::fake();
        Livewire::actingAs($admin)->test(MyAccount::class)
            ->set('currentPassword', 'old-password-1')->set('newPassword', 'brand-new-pass-9')->set('newPasswordConfirmation', 'brand-new-pass-9')
            ->call('changePassword');

        Notification::assertSentOnDemand(AdminLoginChangedNotification::class, function ($n, $channels, $notifiable) use ($text) {
            return $n->toMail($notifiable)->introLines[0] === $text;
        });
    }

    public function test_blank_restores_the_default_and_a_too_short_text_is_rejected(): void
    {
        $component = Livewire::actingAs($this->admin())->test(MyAccount::class);

        $component->set('noticeText', 'too short')->call('saveNotice')->assertHasErrors('noticeText');

        $component->set('noticeText', 'A perfectly reasonable replacement notice text.')->call('saveNotice');
        $component->set('noticeText', '')->call('saveNotice')->assertHasNoErrors();

        $this->assertNull(Setting::get(AdminLoginNotice::KEY));
        $this->assertSame(AdminLoginNotice::DEFAULT, AdminLoginNotice::message());
    }

    public function test_only_a_super_admin_can_see_or_save_the_notice_text(): void
    {
        $other = $this->makeUserWithPermission('operations.view', 'global');

        Livewire::actingAs($other)->test(MyAccount::class)
            ->assertDontSee('Login-change notice')
            ->set('noticeText', 'Attempted edit by someone who is not a Super Admin.')
            ->call('saveNotice')
            ->assertForbidden();

        $this->assertNull(Setting::get(AdminLoginNotice::KEY));
        Livewire::actingAs($this->admin())->test(MyAccount::class)->assertSee('Login-change notice');
    }

    // ============================== 3. new email typed twice ==============================

    public function test_changing_the_email_needs_the_confirmation_to_match(): void
    {
        $admin = $this->admin();
        $component = Livewire::actingAs($admin)->test(MyAccount::class)
            ->set('email', 'new@example.com')
            ->set('profileCurrentPassword', 'old-password-1');

        $component->set('emailConfirmation', '')->call('saveProfile')->assertHasErrors('emailConfirmation');
        $component->set('emailConfirmation', 'typo@example.com')->call('saveProfile')->assertHasErrors('emailConfirmation');
        $this->assertSame('old@example.com', $admin->fresh()->email);

        $component->set('emailConfirmation', 'new@example.com')->call('saveProfile')->assertHasNoErrors();
        $this->assertSame('new@example.com', $admin->fresh()->email);
    }

    public function test_the_confirmation_is_not_needed_when_the_email_is_unchanged_even_in_other_letter_case(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(MyAccount::class)
            ->set('name', 'Same Email')
            ->set('email', 'OLD@example.com')
            ->set('profileCurrentPassword', 'old-password-1')
            ->call('saveProfile')
            ->assertHasNoErrors();

        $this->assertSame('Same Email', $admin->fresh()->name);
    }
}
