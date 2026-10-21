<?php

namespace App\Console\Commands;

use App\Models\Franchise;
use App\Models\Provider;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Models\Zone;
use App\Services\AuthorizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owner request 2026-10-21: one test login per account type, all sharing one password, so every panel can be
 * tried out. The password is passed on the command line and never stored in git.
 *
 *   php artisan test-accounts create --password=... [--franchise=ID]
 *   php artisan test-accounts list
 *   php artisan test-accounts disable      (before launch: suspends them and removes their roles)
 *
 * Every test account uses an @1callfix.test email (that domain can never receive mail), which is also how list and
 * disable find them. Re-running create resets the password and roles; nothing is duplicated. No test Super Admin is
 * made on purpose: the owner already has one, and a second full-power login with a shared password only adds risk.
 */
class TestAccountsCommand extends Command
{
    public const DOMAIN = '@1callfix.test';

    protected $signature = 'test-accounts {action=list : create|list|disable} {--password= : the one password for every test account (min 8)} {--franchise= : franchise id (default: first active franchise)}';

    protected $description = 'Create, list or disable one test login per account type (customer, provider, staff roles)';

    /** key => [name, users.role, role slug or null, scope type or null, phone] */
    private const ACCOUNTS = [
        'country-admin' => ['Test Country Admin', 'country_admin', 'country_admin', 'country', '6000000101'],
        'city-admin' => ['Test City Admin', 'city_admin', 'city_admin', 'city', '6000000102'],
        'zone-admin' => ['Test Zone Admin', 'zone_manager', 'zone_admin', 'zone', '6000000103'],
        'franchise' => ['Test Franchise Owner', 'franchise_owner', 'franchise_owner', 'franchise', '6000000104'],
        'operator' => ['Test Operator', 'operator', 'operator', 'franchise', '6000000105'],
        'support' => ['Test Support', 'support', 'support', 'franchise', '6000000106'],
        'customer' => ['Test Customer', 'customer', null, null, '6000000107'],
        'provider' => ['Test Provider', 'provider', null, null, '6000000108'],
    ];

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'create' => $this->create(),
            'disable' => $this->disable(),
            'list' => $this->list(),
            default => $this->fail('Action must be create, list or disable.'),
        };
    }

    private function create(): int
    {
        $password = (string) $this->option('password');
        if (strlen($password) < 8) {
            $this->error('Pass --password=... (at least 8 characters). It is the one password for every test account.');

            return self::FAILURE;
        }

        $franchise = $this->option('franchise')
            ? Franchise::find($this->option('franchise'))
            : Franchise::where('status', 'active')->orderBy('id')->first();
        $zone = $franchise ? Zone::where('franchise_id', $franchise->id)->orderBy('id')->first() : null;
        if (! $franchise || ! $zone) {
            $this->error('No franchise with a zone found. Pass --franchise=ID of a franchise that has at least one zone.');

            return self::FAILURE;
        }

        if (app()->environment('production')) {
            $this->warn('PRODUCTION: these logins open the admin panel for '.$franchise->name.'. Use a password nobody can guess, and run "test-accounts disable" before launch.');
        }

        $scopeIds = [
            'country' => $franchise->country_id,
            'city' => $franchise->city_id,
            'zone' => $zone->id,
            'franchise' => $franchise->id,
        ];

        $rows = [];
        foreach (self::ACCOUNTS as $key => [$name, $userRole, $roleSlug, $scopeType, $phone]) {
            $email = "test.{$key}".self::DOMAIN;

            $phoneOwner = User::where('phone', $phone)->first();
            if ($phoneOwner && $phoneOwner->email !== $email) {
                $rows[] = [$key, $email, 'SKIPPED: phone '.$phone.' belongs to another user'];

                continue;
            }

            $rows[] = DB::transaction(function () use ($key, $name, $userRole, $roleSlug, $scopeType, $phone, $email, $password, $franchise, $zone, $scopeIds) {
                $user = User::firstOrNew(['email' => $email]);
                $user->forceFill([
                    'uuid' => $user->uuid ?: (string) Str::uuid(),
                    'name' => $name,
                    'phone' => $phone,
                    'password' => bcrypt($password),
                    'role' => $userRole,
                    'status' => 'active',
                    'franchise_id' => in_array($scopeType, ['zone', 'franchise', null], true) ? $franchise->id : null,
                    'zone_id' => $scopeType === 'zone' || $userRole === 'provider' ? $zone->id : null,
                    'phone_verified_at' => $user->phone_verified_at ?? now(),
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();

                if ($roleSlug) {
                    $role = Role::with('permissions')->where('slug', $roleSlug)->first();
                    if (! $role) {
                        return [$key, $email, "SKIPPED: role {$roleSlug} does not exist"];
                    }
                    if ($bad = app(AuthorizationService::class)->disallowedRestrictedPermission($role, $scopeType)) {
                        return [$key, $email, "SKIPPED: {$roleSlug} cannot be granted at {$scopeType} scope ({$bad})"];
                    }
                    RoleAssignment::where('user_id', $user->id)->delete();
                    RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => $scopeType, 'scope_id' => $scopeIds[$scopeType]]);
                }

                if ($userRole === 'provider') {
                    Provider::updateOrCreate(['user_id' => $user->id], [
                        'franchise_id' => $franchise->id,
                        'zone_id' => $zone->id,
                        'provider_type' => 'independent',
                        'kyc_status' => 'approved',
                        'is_active' => true,
                        'is_online' => false, // goes online from the provider panel, which also sends its location
                        'skills' => ServiceCategory::where('is_active', true)->pluck('id')->all(),
                    ]);
                }

                return [$key, $email, $roleSlug ? "{$roleSlug} @ {$scopeType} #{$scopeIds[$scopeType]}" : $userRole];
            });
        }

        $this->info("Test accounts for franchise #{$franchise->id} {$franchise->name}, zone #{$zone->id} {$zone->name}:");
        $this->table(['Account', 'Login email', 'Access'], $rows);
        $this->line('Staff log in at /admin/login, the provider at /provider/login, the customer at /login.');

        return self::SUCCESS;
    }

    private function list(): int
    {
        $users = User::where('email', 'like', '%'.self::DOMAIN)->with('roleAssignments.role')->orderBy('id')->get();
        $this->table(['Email', 'Type', 'Status', 'Roles'], $users->map(fn (User $u) => [
            $u->email, $u->role, $u->status,
            $u->roleAssignments->map(fn ($a) => ($a->role->slug ?? '?')." @ {$a->scope_type} #{$a->scope_id}")->implode(', ') ?: '—',
        ])->all());

        return self::SUCCESS;
    }

    private function disable(): int
    {
        $users = User::where('email', 'like', '%'.self::DOMAIN)->get();
        foreach ($users as $user) {
            DB::transaction(function () use ($user) {
                RoleAssignment::where('user_id', $user->id)->delete();
                Provider::where('user_id', $user->id)->update(['is_active' => false, 'is_online' => false]);
                $user->forceFill(['status' => 'suspended'])->save();
                $user->tokens()->delete();
            });
        }
        $this->info("Disabled {$users->count()} test account(s): suspended, roles removed, app tokens revoked.");

        return self::SUCCESS;
    }
}
