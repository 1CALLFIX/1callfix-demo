<?php

namespace App\Support;

use App\Models\User;

/**
 * REF 1CF-PROMPT-20260925-EARN3 — Rule of Law #6. Earnings policy changes
 * (Wallet / Loyalty-Referral settings tabs, Earnings Control) are Super Admin
 * ONLY — the `super_admin` role itself, not the grantable `settings.manage`
 * permission. Called server-side at the top of every policy mutation, so it
 * holds against a direct Livewire call, not just a hidden button.
 */
final class SuperAdminGate
{
    public static function allows(?User $user): bool
    {
        return $user !== null && $user->role === 'super_admin';
    }

    public static function authorize(?User $user): void
    {
        abort_unless(self::allows($user), 403, 'Only a Super Admin can change earnings policy.');
    }
}
