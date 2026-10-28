<?php

namespace App\Actions;

use App\Exceptions\AccountAlreadyExistsException;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * PHASE PSR — the single definition of "make a pending provider account
 * shell": one `users` row (role = provider, status = active) + one
 * `providers` row (provider_type = independent, a 30-day kyc_deadline_at,
 * kyc_status LEFT at the table's own column default 'pending'). Extracted
 * verbatim from ProviderPreRegisterImporter::commit()'s inner create so
 * the CSV bulk-pre-register path and the public self-registration form
 * (App\Livewire\Provider\Auth\Register) write EXACTLY the same shape
 * through one code path.
 *
 * What each caller varies:
 *   - CSV importer — no password, no email, phone unverified, no address;
 *     franchise / zone come from the spreadsheet columns.
 *   - Self-registration — a hashed password, an optional (unverified,
 *     decision D4) email, phone Firebase-verified before this point, the
 *     typed address + resolved pin; franchise / zone are derived from that
 *     pin server-side, never sent by the client.
 *
 * kyc_status is NEVER written here — nothing in this Action can produce an
 * 'approved' provider, the same invariant the importer has always guarded.
 *
 * A phone that already belongs to a user → AccountAlreadyExistsException
 * (the caller surfaces "sign in instead"). An existing customer becoming a
 * partner (D10, owner 2026-10-27) — or a rider adding services — goes
 * through forExistingCustomer(): the same providers row on their own
 * account, which keeps its password, role and bookings.
 */
class RegisterProviderAction
{
    /**
     * @param  array{address?: string|null, lat?: float|string|null, lng?: float|string|null}  $registration
     *
     * @throws AccountAlreadyExistsException when a user already holds this phone
     */
    public function execute(
        string $name,
        string $phone,
        int $franchiseId,
        ?int $zoneId = null,
        ?string $plainPassword = null,
        ?string $email = null,
        bool $phoneVerified = false,
        array $registration = [],
        array $skills = [],
    ): Provider {
        return DB::transaction(function () use (
            $name, $phone, $franchiseId, $zoneId, $plainPassword, $email, $phoneVerified, $registration, $skills
        ) {
            if (User::where('phone', $phone)->lockForUpdate()->exists()) {
                throw new AccountAlreadyExistsException('mobile number');
            }

            $user = User::create([
                'uuid' => (string) Str::uuid(),
                'name' => $name,
                'phone' => $phone,
                'email' => $email !== null ? Str::lower(trim($email)) : null,
                'password' => $plainPassword !== null ? Hash::make($plainPassword) : null,
                'role' => 'provider',
                'status' => 'active',
                'franchise_id' => $franchiseId,
                'zone_id' => $zoneId,
                'preferred_language' => 'en',
                'phone_verified_at' => $phoneVerified ? now() : null,
            ]);

            return $this->createProfile($user, $franchiseId, $zoneId, $registration, $skills);
        });
    }

    /**
     * Self sign-up by a number that already has a customer account: the phone was just proven by OTP, so the
     * pending provider profile is added to that account instead of a second one. Re-checked under a lock.
     *
     * @throws AccountAlreadyExistsException when the account can no longer apply (already a partner, suspended, staff)
     */
    public function forExistingCustomer(User $user, int $franchiseId, ?int $zoneId = null, array $registration = [], array $skills = []): Provider
    {
        return DB::transaction(function () use ($user, $franchiseId, $zoneId, $registration, $skills) {
            $user = User::whereKey($user->id)->lockForUpdate()->first();
            if (! $user?->canApplyAsPartner('service')) {
                throw new AccountAlreadyExistsException('mobile number');
            }
            if ($user->phone_verified_at === null) {
                $user->forceFill(['phone_verified_at' => now()])->save();
            }

            return $this->createProfile($user, $franchiseId, $zoneId, $registration, $skills);
        });
    }

    private function createProfile(User $user, int $franchiseId, ?int $zoneId, array $registration, array $skills): Provider
    {
        // kyc_status deliberately not set — the providers table's own
        // column default ('pending') applies, same as every other
        // column this Action doesn't touch.
        return Provider::create([
            'user_id' => $user->id,
            'franchise_id' => $franchiseId,
            'zone_id' => $zoneId,
            'provider_type' => 'independent',
            'kyc_deadline_at' => now()->addDays(30),
            'registration_address' => $registration['address'] ?? null,
            'registration_lat' => $registration['lat'] ?? null,
            'registration_lng' => $registration['lng'] ?? null,
            // Category ids the applicant offers (self sign-up "Services you offer"); the same
            // `skills` column DispatchService::hasSkill() matches and admins edit on the provider screen.
            'skills' => $skills !== [] ? array_values(array_unique(array_map('intval', $skills))) : null,
        ]);
    }
}
