<?php

namespace App\Console\Commands;

use App\Models\FieldWorker;
use App\Models\FieldWorkerCapability;
use App\Models\Franchise;
use App\Models\MarketplaceCategory;
use App\Models\Product;
use App\Models\Provider;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\ServiceCategory;
use App\Models\Store;
use App\Models\User;
use App\Models\Zone;
use App\Services\AuthorizationService;
use App\Support\Modules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owner request 2026-10-21: one test login per account type, all sharing one password, so every panel can be
 * tried out. The password is passed on the command line and never stored in git.
 *
 * Owner thumb rule 2026-10-26: every module or partner app gets its test login while it is being built, so it can
 * be walked through for real. The parcel rider (test.rider, approved, /rider/login, also takes shop deliveries) and
 * the seller (test.seller, /seller/login, an approved open "Test Shop" with one product per shop option, TEST_SHOP_PRODUCTS) are here; add each new
 * module's account (taxi driver, hotel, rental, grocery, ...) to ACCOUNTS the same way.
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
        // users.role stays 'provider' for riders (CreateParcelRiderAction); the rider is a field worker + parcel_rider.
        'rider' => ['Test Rider', 'provider', null, null, '6000000109'],
        // E-commerce Phase 1: a seller is a provider account that owns an approved, open shop (/seller/login).
        'seller' => ['Test Seller', 'provider', null, null, '6000000110'],
    ];

    /**
     * The test shop's products, one per option to try (name => [what it tests, column values]). The shop itself has a
     * ₹99 minimum order, ₹30 delivery fee and free delivery above ₹499 (online payment only).
     */
    private const TEST_SHOP_PRODUCTS = [
        'Test Product' => ['Plain item, ₹100.', []],
        'Test Discount Item' => ['20% off. A discount is a benefit, so a bag with this item can be paid online or from the wallet only, never cash.', ['price' => 500, 'discount_percent' => 20]],
        'Test Low Stock Item' => ['Only 3 in stock: the page says "Only 3 left" and the bag stops at 3.', ['price' => 150, 'stock' => 3]],
        'Test Out of Stock Item' => ['Stock 0: shows "Out of stock" and cannot be added to the bag.', ['price' => 200, 'stock' => 0]],
        'Test Limit 2 Item' => ['At most 2 per order. ₹80, so one alone is below the ₹99 minimum order.', ['price' => 80, 'maximum_cart_quantity' => 2]],
        'Test 18% GST Item' => ['18% GST on the invoice. ₹1000, above the free-delivery amount.', ['price' => 1000, 'tax_percent' => 18, 'stock' => 20]],
        'Test Hidden Item' => ['Switched off by the seller: seen in the seller portal, never in the shop.', ['price' => 120, 'is_active' => false]],
        'Test Awaiting Approval Item' => ['Not approved yet: waits in Admin → Products and is not in the shop.', ['price' => 130, 'is_approved' => false]],
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

                if ($key === 'rider') {
                    // Approved, so it counts as a ready rider and Admin → Modules can switch Parcel on.
                    $worker = FieldWorker::updateOrCreate(['user_id' => $user->id], [
                        'franchise_id' => $franchise->id,
                        'zone_id' => $zone->id,
                        'kyc_status' => 'approved',
                        'is_active' => true,
                        'is_online' => false,
                    ]);
                    FieldWorkerCapability::firstOrCreate(['field_worker_id' => $worker->id, 'capability_type' => 'parcel_rider']);
                    // ...and shop deliveries, so the e-commerce walk-through has a rider.
                    FieldWorkerCapability::firstOrCreate(['field_worker_id' => $worker->id, 'capability_type' => 'commerce_delivery_rider']);
                } elseif ($key === 'seller') {
                    $this->makeTestShop($user, $franchise, $zone);
                } elseif ($userRole === 'provider') {
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

                return [$key, $email, $roleSlug ? "{$roleSlug} @ {$scopeType} #{$scopeIds[$scopeType]}" : ($key === 'rider' ? 'parcel + shop rider (approved)' : ($key === 'seller' ? 'seller, shop approved' : $userRole))];
            });
        }

        $this->info("Test accounts for franchise #{$franchise->id} {$franchise->name}, zone #{$zone->id} {$zone->name}:");
        $this->table(['Account', 'Login email', 'Access'], $rows);
        $this->line('Staff log in at /admin/login, the provider at /provider/login, the rider at /rider/login, the seller at /seller/login, the customer at /login.');

        return self::SUCCESS;
    }

    /** The test seller's shop: approved, active and open, with the TEST_SHOP_PRODUCTS, so real orders can be placed. */
    private function makeTestShop(User $user, Franchise $franchise, Zone $zone): void
    {
        $provider = Provider::updateOrCreate(['user_id' => $user->id], [
            'franchise_id' => $franchise->id,
            'zone_id' => $zone->id,
            'provider_type' => 'independent',
            'kyc_status' => 'approved',
            'is_active' => true,
            'is_online' => false,
            'skills' => null,
        ]);
        $store = Store::withTrashed()->firstOrNew(['provider_id' => $provider->id]);
        $store->fill([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'module' => Modules::COMMERCE,
            'name' => $store->name ?: 'Test Shop', 'address_line' => $store->address_line ?: 'Test address, '.$zone->name,
            'lat' => $store->lat ?? $zone->center_lat ?? 0, 'lng' => $store->lng ?? $zone->center_lng ?? 0,
            'phone' => $user->phone, 'is_active' => true, 'is_open' => true,
            'approval_status' => 'approved', 'approved_at' => $store->approved_at ?? now(),
        ])->save();
        if ($store->trashed()) {
            $store->restore();
        }
        // Owner request 2026-10-30: one shop that shows every option, for field testing. Re-running create resets the
        // shop's delivery rules and these products (stock refilled after test orders); other products are left alone.
        $store->forceFill(['minimum_order_amount' => 99, 'delivery_fee_flat' => 30, 'free_delivery_above_amount' => 499])->save();
        $categoryId = MarketplaceCategory::where('module', Modules::COMMERCE)->where('is_active', true)->orderBy('position')->value('id');
        foreach (self::TEST_SHOP_PRODUCTS as $name => [$description, $attributes]) {
            Product::updateOrCreate(['store_id' => $store->id, 'name' => $name], $attributes + [
                'description' => $description, 'marketplace_category_id' => $categoryId,
                'price' => 100, 'tax_percent' => 0, 'discount_percent' => null, 'stock' => 50,
                'maximum_cart_quantity' => null, 'is_active' => true, 'is_approved' => true,
            ]);
        }
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
                FieldWorker::where('user_id', $user->id)->update(['is_active' => false, 'is_online' => false]);
                Store::whereIn('provider_id', Provider::where('user_id', $user->id)->select('id'))->update(['is_active' => false, 'is_open' => false]);
                $user->forceFill(['status' => 'suspended'])->save();
                $user->tokens()->delete();
            });
        }
        $this->info("Disabled {$users->count()} test account(s): suspended, roles removed, app tokens revoked.");

        return self::SUCCESS;
    }
}
