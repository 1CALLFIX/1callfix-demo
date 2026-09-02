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
 * (the caller surfaces "sign in instead"). Attaching a provider profile to
 * an EXISTING user with that phone — an existing customer becoming a
 * partner, discovery doc D10 — is deliberately NOT done here yet; it is a
 * scoped follow-up.
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
    ): Provider {
        return DB::transaction(function () use (
            $name, $phone, $franchiseId, $zoneId, $plainPassword, $email, $phoneVerified, $registration
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
            ]);
        });
    }
}
