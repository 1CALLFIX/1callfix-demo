<?php

namespace App\Actions;

use App\Exceptions\AccountAlreadyExistsException;
use App\Models\FieldWorker;
use App\Models\FieldWorkerCapability;
use App\Models\User;
use App\Models\Zone;
use App\Services\ActivityLogger;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Admin → Workers → "Add parcel rider". Until now nothing in the app could create a FieldWorker (only the QA
 * seeder did), so no real rider could exist. Creates the login account (or links an existing account with
 * that phone that is not yet a worker), the FieldWorker in the chosen zone, and the `parcel_rider`
 * capability.
 *
 * KYC: riders have no document-upload path, so the admin confirms they checked ID and licence in person and
 * the rider is created approved. That confirmation and who gave it are audit-logged.
 */
class CreateParcelRiderAction
{
    public function execute(User $admin, Zone $zone, string $name, string $phone, ?string $email, ?string $password): FieldWorker
    {
        $national = PhoneNumber::national($phone);
        if ($national === '') {
            throw new \RuntimeException('Enter a valid mobile number.');
        }

        return DB::transaction(function () use ($admin, $zone, $name, $national, $email, $password) {
            $user = User::where('phone', $national)->lockForUpdate()->first();

            if ($user) {
                if ($user->fieldWorkerProfile()->exists()) {
                    throw new \RuntimeException('This mobile number already belongs to a worker. Add the parcel rider capability on their worker page instead.');
                }
                if ($user->status === 'suspended') {
                    throw new \RuntimeException('This account is suspended.');
                }
                // Existing account: it keeps its own password (we never overwrite one).
            } else {
                if (blank($password) || mb_strlen($password) < 8) {
                    throw new \RuntimeException('Set a password of at least 8 characters for the new rider.');
                }
                if ($email && User::whereRaw('LOWER(email) = ?', [Str::lower($email)])->exists()) {
                    throw new \RuntimeException('That email already belongs to another account.');
                }

                $user = User::create([
                    'uuid' => (string) Str::uuid(), 'name' => $name, 'phone' => $national, 'email' => $email ?: null,
                    'password' => Hash::make($password), 'role' => 'provider', 'status' => 'active',
                    'franchise_id' => $zone->franchise_id, 'zone_id' => $zone->id,
                ]);
            }

            $worker = FieldWorker::create([
                'user_id' => $user->id, 'franchise_id' => $zone->franchise_id, 'zone_id' => $zone->id,
                'kyc_status' => 'approved', 'is_active' => true, 'is_online' => false,
            ]);
            FieldWorkerCapability::create(['field_worker_id' => $worker->id, 'capability_type' => 'parcel_rider']);

            ActivityLogger::logModel($admin, $worker, 'Parcel rider added; ID and licence verified in person by admin', [
                'user_id' => $user->id, 'zone_id' => $zone->id, 'existing_account' => $user->wasRecentlyCreated === false,
            ]);

            return $worker;
        });
    }

    /**
     * Self sign-up from /partners → the partner sign-up page (owner rule 2026-10-25: every partner joins through
     * /partners with OTP and documents). Same rider as execute() but KYC stays 'pending': the rider gets no offers
     * until an admin approves the uploaded documents in Workers → Review. A number that already has an account is
     * refused, exactly like the provider sign-up (sign in instead).
     *
     * @param  array{address?:?string, lat?:?float, lng?:?float, out_of_coverage?:bool}  $registration
     *
     * @throws AccountAlreadyExistsException
     */
    public function registerApplicant(Zone $zone, string $name, string $nationalPhone, ?string $email, string $plainPassword, array $registration = []): FieldWorker
    {
        return DB::transaction(function () use ($zone, $name, $nationalPhone, $email, $plainPassword, $registration) {
            if (User::where('phone', $nationalPhone)->lockForUpdate()->exists()) {
                throw new AccountAlreadyExistsException('mobile number');
            }

            $user = User::create([
                'uuid' => (string) Str::uuid(), 'name' => $name, 'phone' => $nationalPhone,
                'email' => filled($email) ? Str::lower(trim($email)) : null,
                'password' => Hash::make($plainPassword), 'role' => 'provider', 'status' => 'active',
                'franchise_id' => $zone->franchise_id, 'zone_id' => $zone->id,
                'preferred_language' => 'en', 'phone_verified_at' => now(),
            ]);

            $worker = FieldWorker::create([
                'user_id' => $user->id, 'franchise_id' => $zone->franchise_id, 'zone_id' => $zone->id,
                'kyc_status' => 'pending', 'is_active' => true, 'is_online' => false,
            ]);
            FieldWorkerCapability::create(['field_worker_id' => $worker->id, 'capability_type' => 'parcel_rider']);

            // field_workers has no registration-address columns; the typed address and pin go in the log so the
            // reviewing admin can see where the rider works (and move an out-of-coverage rider to the right zone).
            ActivityLogger::logModel($user, $worker, 'Parcel rider applied (partner sign-up); awaiting document review', array_filter([
                'zone_id' => $zone->id, 'address' => $registration['address'] ?? null,
                'lat' => $registration['lat'] ?? null, 'lng' => $registration['lng'] ?? null,
                'out_of_coverage' => ($registration['out_of_coverage'] ?? false) ?: null,
            ], fn ($v) => $v !== null));

            return $worker;
        });
    }
}
