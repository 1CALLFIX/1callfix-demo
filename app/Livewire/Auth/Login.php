<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Component;

class Login extends Component
{
    public string $email = '';
    public string $password = '';
    public string $error = '';

    /**
     * Production-hardening session, Part 2 — this screen had ZERO rate
     * limiting: Auth::attempt() could be called at unlimited volume against
     * any admin account (the highest-value credential in this system —
     * every RBAC-elevated role logs in here), unlike the API's own OTP/QR
     * endpoints, which are all throttle:-protected. Livewire component
     * actions don't go through routes/api.php's throttle: middleware (they
     * all share the one /livewire/update endpoint), so this uses the same
     * RateLimiter-facade pattern Laravel's own Breeze scaffolding uses for
     * session-based login. Keyed per email+IP (not IP alone) so one bad
     * actor can't lock out a real admin sharing that IP, and not email
     * alone so a distributed attempt against many emails from one IP still
     * gets slowed. 5 attempts/minute matches this codebase's own existing
     * OTP-request throttle (routes/api.php: throttle:5,1) rather than an
     * invented number.
     */
    private function throttleKey(): string
    {
        return Str::lower($this->email).'|'.request()->ip();
    }

    public function submit()
    {
        $this->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            $seconds = RateLimiter::availableIn($this->throttleKey());
            $this->error = "Too many login attempts. Please try again in {$seconds} seconds.";
            return;
        }

        if (!Auth::attempt(['email' => $this->email, 'password' => $this->password])) {
            RateLimiter::hit($this->throttleKey(), 60);
            $this->error = 'Invalid email or password.';
            return;
        }

        RateLimiter::clear($this->throttleKey());

        // Enable/Disable Audit — status was written by Customers\Show::
        // toggleSuspended() but checked NOWHERE, including here: a
        // suspended account (customer, provider, or staff) could still
        // pass Auth::attempt() above and reach the admin-access check
        // below untouched. Checked BEFORE that check, not after — a
        // suspended admin should see "your account is suspended", not the
        // less accurate "this account does not have admin access."
        // Mirrored in EnsureHasAdminAccess below as defense in depth (this
        // check only runs at the moment of login; that middleware re-runs
        // it on every subsequent /admin request, so an account suspended
        // mid-session is blocked on its very next request too).
        $user = Auth::user();

        if ($user->status === 'suspended') {
            Auth::logout();
            $this->error = 'This account has been suspended. Contact your administrator.';
            return;
        }

        // Mirrors EnsureHasAdminAccess exactly — that middleware already
        // replaced the old super_admin-only gate for every /admin route
        // (see its own docblock: "anyone holding at least one
        // role_assignment gets into the panel shell"), but this screen's
        // own inline check was never updated to match, so a real Country/
        // City/Zone Admin, Franchise Owner, Operator, or Support user could
        // never even reach the middleware — they were logged back out
        // right here, before the RBAC layer that's meant to gate them ever
        // got a chance to run. Which screens/actions they can actually use
        // once inside is still enforced by AuthorizationService::can() at
        // every individual action, unchanged by this fix.
        if ($user->role !== 'super_admin' && !$user->roleAssignments()->exists()) {
            Auth::logout();
            $this->error = 'This account does not have admin access.';
            return;
        }

        session()->regenerate();

        return redirect()->route('admin.dashboard');
    }

    public function render()
    {
        return view('livewire.auth.login')
            ->layout('layouts.admin', ['title' => 'Admin Login']);
    }
}
