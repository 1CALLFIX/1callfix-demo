<?php

namespace App\Livewire\Customer\Auth\Concerns;

use App\Models\User;

/**
 * Shared suspension check for the customer web auth screens.
 *
 * Enable/Disable Audit — users.status is written by Customers\Show::
 * toggleSuspended() (and, as of the same fix, Roles\Manage::
 * toggleUserSuspended() for staff) but was never checked anywhere,
 * including here: a suspended customer could still complete any of this
 * app's four ways of establishing a session (Login::login() by password,
 * Login::continueWithGoogle() and GoogleAuth::mount()/phoneTokenReceived()
 * for the two Google-linked-account paths) untouched. This is the one
 * check all four share, rather than four separate inline copies —
 * App\Livewire\Auth\Login (admin) gets the identical check inline since it
 * only has the one call site.
 *
 * Deliberately does NOT invalidate an already-established session — this
 * only blocks a NEW login/link from completing. An admin suspending a
 * customer mid-session doesn't force that session closed by itself; that
 * would be a separate, further feature (real-time session revocation),
 * not part of what was asked here.
 */
trait ChecksAccountSuspension
{
    /**
     * Returns true (and sets $this->error) when the account is suspended.
     * Call AFTER credentials/identity are verified but BEFORE
     * Auth::guard('web')->login($user) — never before verification, so
     * this can't be used to probe which accounts exist.
     */
    protected function blockIfSuspended(User $user): bool
    {
        if ($user->status === 'suspended') {
            $this->error = 'This account has been suspended. Contact support for help.';

            return true;
        }

        return false;
    }
}
