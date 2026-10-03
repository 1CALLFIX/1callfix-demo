<?php

namespace App\Livewire\Account;

use App\Services\ActivityLogger;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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

    public string $phone = '';

    public string $profileCurrentPassword = '';

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public string $flashType = '';

    public string $flashMessage = '';

    public function mount(): void
    {
        $user = Auth::user();
        $this->name = (string) $user->name;
        $this->email = (string) ($user->email ?? '');
        $this->phone = (string) ($user->phone ?? '');
    }

    public function saveProfile(): void
    {
        $user = Auth::user();

        $this->phone = PhoneNumber::national($this->phone);

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['required', 'digits:10', Rule::unique('users', 'phone')->ignore($user->id)],
            'profileCurrentPassword' => ['required', 'string'],
        ], [], ['profileCurrentPassword' => 'current password']);

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

        // Fresh session id after a credential change.
        if (request()->hasSession()) {
            request()->session()->regenerate();
        }

        $this->currentPassword = $this->newPassword = $this->newPasswordConfirmation = '';
        $this->flash('success', 'Password changed.');
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
        return view('livewire.account.my-account')
            ->layout('layouts.admin', ['title' => 'My account']);
    }
}
