<?php

namespace App\Livewire\Account;

use App\Notifications\AdminLoginChangedNotification;
use App\Services\ActivityLogger;
use App\Services\SettingsAuditor;
use App\Support\AdminLoginNotice;
use App\Support\SuperAdminGate;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Admin "My account" — lets the signed-in admin change their OWN name,
 * email, phone and password. Admin login is email + password
 * (Auth\Login), so email/password changes change how they sign in.
 *
 * Every change re-asks for the current password (a hijacked open session
 * must not be able to take the account over), failed attempts are
 * throttled, phone is stored in the same bare-national shape as every
 * other users.phone row, and each change is written to the activity log
 * (never the password itself). Only ever touches auth()->user().
 */
class MyAccount extends Component
{
    public string $name = '';

    public string $email = '';

    public string $emailConfirmation = '';

    public string $phone = '';

    public string $profileCurrentPassword = '';

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    /** Super Admin only: text of the notice emailed to the old address. */
    public string $noticeText = '';

    public string $flashType = '';

    public string $flashMessage = '';

    public function mount(): void
    {
        $user = Auth::user();
        $this->name = (string) $user->name;
        $this->email = (string) ($user->email ?? '');
        $this->phone = (string) ($user->phone ?? '');
        $this->noticeText = AdminLoginNotice::message();
    }

    public function saveProfile(): void
    {
        $user = Auth::user();

        $this->phone = PhoneNumber::national($this->phone);

        // Changing the login email means typing the new one twice.
        $emailChanging = strcasecmp(trim($this->email), (string) $user->email) !== 0;

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'emailConfirmation' => [Rule::requiredIf($emailChanging), $emailChanging ? 'same:email' : 'nullable'],
            'phone' => ['required', 'digits:10', Rule::unique('users', 'phone')->ignore($user->id)],
            'profileCurrentPassword' => ['required', 'string'],
        ], [], ['profileCurrentPassword' => 'current password', 'emailConfirmation' => 'email confirmation']);

        if (! $this->currentPasswordIsCorrect($this->profileCurrentPassword, 'profileCurrentPassword')) {
            return;
        }

        $before = $user->only(['name', 'email', 'phone']);

        $user->name = $this->name;
        $user->email = $this->email;
        if ($user->phone !== $this->phone) {
            $user->phone_verified_at = null; // the new number has not been verified
        }
        $user->phone = $this->phone;
        $user->save();

        ActivityLogger::logModel($user, $user, 'admin account details changed', [
            'before' => $before,
            'after' => $user->only(['name', 'email', 'phone']),
        ]);

        $this->profileCurrentPassword = '';
        $this->emailConfirmation = '';

        if (strcasecmp((string) $before['email'], (string) $user->email) !== 0) {
            $this->noticeOldEmail((string) $before['email'], 'email address');
        }

        $this->flash('success', 'Account details updated. Sign in with your new email next time.');
    }

    public function changePassword(): void
    {
        $user = Auth::user();

        $this->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'min:10', 'same:newPasswordConfirmation', 'different:currentPassword'],
            'newPasswordConfirmation' => ['required', 'string'],
        ], [], [
            'currentPassword' => 'current password',
            'newPassword' => 'new password',
            'newPasswordConfirmation' => 'password confirmation',
        ]);

        if (! $this->currentPasswordIsCorrect($this->currentPassword, 'currentPassword')) {
            return;
        }

        $user->password = Hash::make($this->newPassword);
        $user->setRememberToken(Str::random(60));
        $user->save();

        ActivityLogger::logModel($user, $user, 'admin password changed');

        // Every other session of this user is signed out (AuthenticateSession compares the stored password
        // hash on each request); this session keeps working because the hash is re-stored after this response.
        Auth::logoutOtherDevices($this->newPassword);

        $this->noticeOldEmail((string) $user->email, 'password');

        // Fresh session id after a credential change.
        if (request()->hasSession()) {
            request()->session()->regenerate();
        }

        $this->currentPassword = $this->newPassword = $this->newPasswordConfirmation = '';
        $this->flash('success', 'Password changed.');
    }

    /** Best-effort: a mail failure must never undo or block the credential change itself. */
    private function noticeOldEmail(string $oldEmail, string $what): void
    {
        if (trim($oldEmail) === '') {
            return;
        }

        try {
            Notification::route('mail', $oldEmail)->notify(new AdminLoginChangedNotification(
                AdminLoginNotice::message(),
                $what,
                now('Asia/Kolkata')->format('d M Y, h:i A').' IST',
            ));
        } catch (\Throwable $e) {
            Log::warning('MyAccount: could not send the login-change notice.', ['user_id' => Auth::id(), 'error' => $e->getMessage()]);
        }
    }

    /** Super Admin only. Blank restores the default wording. */
    public function saveNotice(): void
    {
        SuperAdminGate::authorize(auth()->user());

        $this->validate(['noticeText' => ['nullable', 'string', 'min:20', 'max:500']], [], ['noticeText' => 'notice text']);

        SettingsAuditor::put(auth()->user(), AdminLoginNotice::KEY, trim($this->noticeText));
        $this->noticeText = AdminLoginNotice::message();

        $this->flash('success', 'Notice text saved.');
    }

    private function currentPasswordIsCorrect(string $given, string $field): bool
    {
        $key = 'admin-account:'.Auth::id();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError($field, 'Too many wrong attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return false;
        }

        if (! Hash::check($given, (string) Auth::user()->password)) {
            RateLimiter::hit($key, 60);
            $this->addError($field, 'The current password is incorrect.');

            return false;
        }

        RateLimiter::clear($key);

        return true;
    }

    private function flash(string $type, string $message): void
    {
        $this->flashType = $type;
        $this->flashMessage = $message;
    }

    public function render()
    {
        return view('livewire.account.my-account', ['isSuperAdmin' => SuperAdminGate::allows(auth()->user())])
            ->layout('layouts.admin', ['title' => 'My account']);
    }
}
