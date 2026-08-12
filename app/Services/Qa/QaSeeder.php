<?php

namespace App\Services\Qa;

use App\Actions\AcceptBookingAction;
use App\Actions\AdminCancelBookingAction;
use App\Actions\AdminReassignBookingAction;
use App\Actions\AssignBookingToWorkerAction;
use App\Actions\CompleteBookingAction;
use App\Actions\CreateBookingAction;
use App\Actions\PlaceBookingOnHoldAction;
use App\Actions\StartBookingAction;
use App\Jobs\ServiceMatchingJob;
use App\Models\Address;
use App\Models\Banner;
use App\Models\Booking;
use App\Models\BusinessAccount;
use App\Models\BusinessLocation;
use App\Models\City;
use App\Models\ContentPage;
use App\Models\Country;
use App\Models\Faq;
use App\Models\FieldWorker;
use App\Models\FieldWorkerCapability;
use App\Models\FieldWorkerDocument;
use App\Models\Franchise;
use App\Models\FranchiseModule;
use App\Models\Payment;
use App\Models\PartnerWorker;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Provider;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceSubcategory;
use App\Models\User;
use App\Models\Zone;
use App\Services\DispatchService;
use App\Services\LoyaltyService;
use App\Services\Plans\SubscriptionService;
use App\Services\ReferralService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Realistic, relationally-consistent QA dataset — every financial or
 * transactional record is created through the REAL Action/Service it would
 * go through in production (CreateBookingAction, AcceptBookingAction,
 * CompleteBookingAction, CommissionService via CompleteBookingAction,
 * WalletService via CommissionService/LoyaltyService, SubscriptionService,
 * ReferralService), never a raw financial-table insert. Every created
 * record's [table, id] is tracked in a QaManifest for exact, auditable
 * cleanup via `qa:clean` — not a naming-convention guess.
 *
 * Booking FSM note (a real finding, not a QA gap): 'provider_en_route' and
 * 'disputed' are valid bookings.status enum values but have NO implemented
 * Action anywhere in this codebase that ever transitions a booking INTO
 * them — confirmed by grep across app/Actions and app/Http/Controllers.
 * This seeder does not fabricate bookings in those two states via a raw
 * status write, since that would misrepresent a state as
 * "seen in QA data" that the real system never actually produces. See
 * QA_DATA_INTEGRITY_REPORT.md.
 */
class QaSeeder
{
    private QaManifest $manifest;

    /** @var array<string,mixed> */
    private array $counts = [];

    public function __construct()
    {
        $this->manifest = new QaManifest;
    }

    public function run(string $scale = 'default'): array
    {
        $n = $this->scaleCounts($scale);

        // The whole run is one transaction: if anything throws partway
        // through (a real risk with this much sequential Action-driven
        // logic), the DB rolls back to exactly its pre-run state instead
        // of leaving orphaned, manifest-untracked partial data behind —
        // the manifest is only ever saved (below) after a successful
        // commit, so it's never out of sync with what's really in the DB.
        [$bookingStats, $subscriptionStats] = DB::transaction(function () use ($n) {
            $geo = $this->seedGeography();
            $this->seedAdminUsers($geo);
            $catalog = $this->seedCatalog();
            $plans = $this->seedPlans();
            $customers = $this->seedCustomers($n['customers']);
            $providers = $this->seedProviders($geo, $catalog);
            $workers = $this->seedWorkers($geo, $providers, $n['workers']);
            $this->seedBusinessAccounts($geo);
            $bookingStats = $this->seedBookings($geo, $customers, $providers, $workers, $catalog, $n['bookings']);
            $subscriptionStats = $this->seedSubscriptions($customers, $providers, $plans, $n['subscriptions']);
            $this->seedBanners($geo);
            $this->seedCms();

            return [$bookingStats, $subscriptionStats];
        });

        $this->manifest->save();

        return [
            'manifest_counts' => $this->manifest->counts(),
            'total_records' => $this->manifest->totalRecords(),
            'booking_status_distribution' => $bookingStats,
            'subscription_status_distribution' => $subscriptionStats,
        ];
    }

    private function scaleCounts(string $scale): array
    {
        return match ($scale) {
            'small' => ['customers' => 8, 'workers' => 6, 'bookings' => 20, 'subscriptions' => 6],
            'default' => ['customers' => 50, 'workers' => 40, 'bookings' => 200, 'subscriptions' => 30],
            default => throw new \InvalidArgumentException("Unknown scale '{$scale}' — use 'small' or 'default'."),
        };
    }

    /** Accepts a single model, a single raw id, or an iterable of either mix. */
    private function track(string $table, $value): void
    {
        if (is_iterable($value)) {
            $ids = [];
            foreach ($value as $item) {
                $ids[] = is_object($item) ? $item->id : (int) $item;
            }
            $this->manifest->record($table, $ids);

            return;
        }

        $this->manifest->record($table, is_object($value) ? $value->id : (int) $value);
    }

    // ============================= Geography =============================

    private function seedGeography(): array
    {
        $countries = [];
        $cities = [];
        $franchises = [];
        $zones = [];

        foreach (['Q1' => 'QA Countryland One', 'Q2' => 'QA Countryland Two', 'Q3' => 'QA Countryland Three'] as $code => $name) {
            $country = Country::create([
                'name' => $name, 'code' => $code, 'currency_code' => 'INR',
                'default_timezone' => 'Asia/Kolkata', 'is_active' => true,
            ]);
            $this->track('countries', $country);
            $countries[] = $country;

            for ($c = 1; $c <= 3; $c++) {
                $city = City::create(['country_id' => $country->id, 'name' => "[QA] City {$code}-{$c}", 'is_active' => true]);
                $this->track('cities', $city);
                $cities[] = $city;

                // Mix of HQ-operated (owner_user_id left null, i.e. platform-run)
                // and franchise-operated (owner assigned once admins exist) —
                // owner assignment happens in seedAdminUsers() via update().
                $franchise = Franchise::create([
                    'name' => "[QA] Franchise {$code}-{$c}", 'slug' => Str::slug("qa-franchise-{$code}-{$c}-".Str::random(6)),
                    'city' => $city->name, 'country_id' => $country->id, 'city_id' => $city->id,
                    'commission_model' => 'revenue_share', 'commission_value' => 12, 'platform_fee_percent' => 6,
                    'status' => 'active',
                ]);
                $this->track('franchises', $franchise);
                $franchises[] = $franchise;

                $module = FranchiseModule::updateOrCreate(['franchise_id' => $franchise->id], ['service' => true]);
                $this->track('franchise_modules', $module);

                for ($z = 1; $z <= 3; $z++) {
                    $zone = Zone::create([
                        'franchise_id' => $franchise->id, 'name' => "[QA] Zone {$code}-{$c}-{$z}",
                        'boundary_polygon' => [
                            ['lat' => 12.90 + ($z * 0.05), 'lng' => 77.50 + ($z * 0.05)],
                            ['lat' => 12.95 + ($z * 0.05), 'lng' => 77.50 + ($z * 0.05)],
                            ['lat' => 12.95 + ($z * 0.05), 'lng' => 77.55 + ($z * 0.05)],
                        ],
                        'is_active' => true, 'default_dispatch_radius_km' => 15,
                    ]);
                    $this->track('zones', $zone);
                    $zones[] = $zone;
                }
            }
        }

        return compact('countries', 'cities', 'franchises', 'zones');
    }

    // ============================= Admin users (one per role) =============================

    private function seedAdminUsers(array $geo): void
    {
        $franchise = $geo['franchises'][0];
        $zone = $geo['zones'][0];

        $roleScopes = [
            'super_admin' => ['scope_type' => 'global', 'scope_id' => null],
            'country_admin' => ['scope_type' => 'country', 'scope_id' => $geo['countries'][0]->id],
            'city_admin' => ['scope_type' => 'city', 'scope_id' => $geo['cities'][0]->id],
            'zone_admin' => ['scope_type' => 'zone', 'scope_id' => $zone->id],
            'franchise_owner' => ['scope_type' => 'franchise', 'scope_id' => $franchise->id],
            'operator' => ['scope_type' => 'franchise', 'scope_id' => $franchise->id],
            'support' => ['scope_type' => 'franchise', 'scope_id' => $franchise->id],
        ];

        // users.role is the coarse legacy "what kind of person" tag and does
        // NOT have a 1:1 value for every RBAC role slug — zone_admin,
        // operator and support have no matching enum value (confirmed via
        // 2026_08_11_015000's enum list), so they fall back to
        // zone_manager, same as that migration's own docblock describes
        // ("franchise_owner and zone_manager already covered those two
        // levels"). The real access grant is the role_assignments row
        // below, not this column.
        $legacyRoleFallback = ['zone_admin' => 'zone_manager', 'operator' => 'zone_manager', 'support' => 'zone_manager'];

        foreach ($roleScopes as $roleSlug => $scope) {
            $user = User::create([
                'uuid' => (string) Str::uuid(), 'name' => "[QA] {$roleSlug}",
                'phone' => '9'.fake()->unique()->numerify('#########'),
                'role' => $legacyRoleFallback[$roleSlug] ?? $roleSlug,
                'status' => 'active',
            ]);
            $this->track('users', $user);

            $role = Role::where('slug', $roleSlug)->first();
            if ($role) {
                $assignment = RoleAssignment::create([
                    'user_id' => $user->id, 'role_id' => $role->id,
                    'scope_type' => $scope['scope_type'], 'scope_id' => $scope['scope_id'],
                ]);
                $this->track('role_assignments', $assignment);
            }

            if ($roleSlug === 'franchise_owner') {
                $franchise->update(['owner_user_id' => $user->id]);
            }
        }
    }

    // ============================= Catalog =============================

    private function seedCatalog(): array
    {
        $definitions = [
            'Home Cleaning' => ['Deep Cleaning', 'Bathroom Cleaning'],
            'Electrical Repair' => ['Wiring', 'Switchboard Repair'],
            'Plumbing' => ['Tap Repair', 'Pipe Leakage'],
        ];

        $categories = [];
        $services = [];

        $order = 1;
        foreach ($definitions as $categoryName => $subNames) {
            $category = ServiceCategory::create([
                'module' => 'service', 'name' => "[QA] {$categoryName}",
                'slug' => Str::slug('qa-'.$categoryName.'-'.Str::random(6)),
                'image' => 'categories/qa-placeholder.png', 'sort_order' => $order++, 'is_active' => true,
            ]);
            $this->track('service_categories', $category);
            $categories[] = $category;

            $subOrder = 1;
            foreach ($subNames as $subName) {
                $subcategory = ServiceSubcategory::create([
                    'category_id' => $category->id, 'name' => "[QA] {$subName}",
                    'slug' => Str::slug('qa-'.$subName.'-'.Str::random(6)),
                    'image' => 'subcategories/qa-placeholder.png', 'sort_order' => $subOrder++, 'is_active' => true,
                ]);
                $this->track('service_subcategories', $subcategory);

                $service = Service::create([
                    'category_id' => $category->id, 'subcategory_id' => $subcategory->id,
                    'name' => "[QA] {$subName} Service", 'slug' => Str::slug('qa-'.$subName.'-service-'.Str::random(6)),
                    'description' => "QA test service for {$subName}.",
                    'base_price' => fake()->randomElement([299, 499, 799, 1299]),
                    'price_type' => 'fixed', 'duration_estimate_mins' => 60,
                    'is_active' => true, 'location_required' => true, 'age_restriction' => false, 'sort_order' => 1,
                ]);
                $this->track('services', $service);
                $services[] = $service;
            }
        }

        return compact('categories', 'services');
    }

    // ============================= Plans =============================

    private function seedPlans(): array
    {
        $plans = [];

        // All QA plans are price=0 (free-tier activation path) deliberately
        // — a paid plan routes through RazorpayService, which needs real
        // gateway credentials this QA environment doesn't have and won't
        // fake (no mock payment success). The free-tier path is not a
        // shortcut around that: it's SubscriptionService's own real,
        // fully-implemented "price <= 0 activates immediately" branch, not
        // a QA-only code path. See QA_DATA_INTEGRITY_REPORT.md for the
        // explicit note that paid-plan purchase was not exercisable here.
        $quantity = Plan::create([
            'name' => '[QA] Quantity Plan', 'slug' => 'qa-quantity-plan-'.Str::random(6),
            'plan_family' => 'customer_membership', 'scope_type' => 'global',
            'eligible_actor_type' => 'customer', 'billing_cycle' => 'monthly',
            'price' => 0, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);
        $this->track('plans', $quantity);
        $ent = PlanEntitlement::create([
            'plan_id' => $quantity->id, 'entitlement_type' => 'quantity', 'quantity' => 5,
            'usage_period' => 'monthly', 'consumption_trigger' => 'booking_created',
            'rollover_policy' => 'partial', 'rollover_cap' => 2,
        ]);
        $this->track('plan_entitlements', $ent);
        $plans['quantity'] = $quantity;

        $percentDiscount = Plan::create([
            'name' => '[QA] Percentage Discount Plan', 'slug' => 'qa-percent-discount-plan-'.Str::random(6),
            'plan_family' => 'customer_membership', 'scope_type' => 'global',
            'eligible_actor_type' => 'customer', 'billing_cycle' => 'monthly',
            'price' => 0, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);
        $this->track('plans', $percentDiscount);
        $ent2 = PlanEntitlement::create([
            'plan_id' => $percentDiscount->id, 'entitlement_type' => 'percentage_discount',
            'percentage_value' => 15, 'usage_period' => 'per_transaction', 'consumption_trigger' => 'booking_created',
            'rollover_policy' => 'none',
        ]);
        $this->track('plan_entitlements', $ent2);
        $plans['percent_discount'] = $percentDiscount;

        $fixedDiscount = Plan::create([
            'name' => '[QA] Fixed Discount Plan', 'slug' => 'qa-fixed-discount-plan-'.Str::random(6),
            'plan_family' => 'customer_membership', 'scope_type' => 'global',
            'eligible_actor_type' => 'customer', 'billing_cycle' => 'monthly',
            'price' => 0, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);
        $this->track('plans', $fixedDiscount);
        $ent3 = PlanEntitlement::create([
            'plan_id' => $fixedDiscount->id, 'entitlement_type' => 'fixed_discount',
            'monetary_value' => 100, 'usage_period' => 'monthly', 'consumption_trigger' => 'booking_created',
            'rollover_policy' => 'none',
        ]);
        $this->track('plan_entitlements', $ent3);
        $plans['fixed_discount'] = $fixedDiscount;

        $providerPlan = Plan::create([
            'name' => '[QA] Provider Commission Plan', 'slug' => 'qa-provider-plan-'.Str::random(6),
            'plan_family' => 'provider_package', 'scope_type' => 'global',
            'eligible_actor_type' => 'provider', 'billing_cycle' => 'monthly',
            'price' => 0, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);
        $this->track('plans', $providerPlan);
        $ent4 = PlanEntitlement::create([
            'plan_id' => $providerPlan->id, 'entitlement_type' => 'commission_reduction',
            'percentage_value' => 2, 'usage_period' => 'monthly', 'consumption_trigger' => 'service_completed',
            'rollover_policy' => 'none',
        ]);
        $this->track('plan_entitlements', $ent4);
        $plans['provider'] = $providerPlan;

        return $plans;
    }

    // ============================= Customers =============================

    private function seedCustomers(int $count): array
    {
        $customers = [];
        for ($i = 1; $i <= $count; $i++) {
            $customer = User::create([
                'uuid' => (string) Str::uuid(), 'name' => "[QA] Customer {$i}",
                'phone' => '9'.fake()->unique()->numerify('#########'),
                'role' => 'customer', 'status' => 'active', 'preferred_language' => 'en',
            ]);
            $this->track('users', $customer);
            $customers[] = $customer;
        }

        // A handful of referral relationships, created through the real
        // signup path (UserObserver -> ReferralService::createFromSignup).
        for ($i = 1; $i < min(6, $count); $i++) {
            $customers[$i]->update(['referred_by' => $customers[0]->id]);
            $referral = app(ReferralService::class)->createFromSignup($customers[$i]->fresh());
            if ($referral) {
                $this->track('referrals', $referral);
            }
        }

        return $customers;
    }

    // ============================= Providers =============================

    private function seedProviders(array $geo, array $catalog): array
    {
        $providers = [];
        $count = min(20, count($geo['franchises']) * 3);

        // DispatchService::hasSkill() checks in_array($categoryId, $provider->skills)
        // — actual service_categories.id integers, not slugs/labels. Every
        // active/approved/online provider gets every seeded category so the
        // real dispatch flow (ServiceMatchingJob -> findCandidates()) has
        // real candidates to find, exercising that path for real rather
        // than it silently finding nobody.
        $allCategoryIds = collect($catalog['categories'])->pluck('id')->all();

        for ($i = 1; $i <= $count; $i++) {
            $franchise = $geo['franchises'][$i % count($geo['franchises'])];
            $zone = collect($geo['zones'])->firstWhere('franchise_id', $franchise->id) ?? $geo['zones'][0];

            $user = User::create([
                'uuid' => (string) Str::uuid(), 'name' => "[QA] Provider {$i}",
                'phone' => '9'.fake()->unique()->numerify('#########'),
                'role' => 'provider', 'status' => 'active', 'franchise_id' => $franchise->id,
            ]);
            $this->track('users', $user);

            $provider = Provider::create([
                'user_id' => $user->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
                'provider_type' => $i % 5 === 0 ? 'company' : 'independent',
                'skills' => $allCategoryIds,
                'kyc_status' => $i % 10 === 0 ? 'pending' : 'approved',
                'is_active' => $i % 15 !== 0, // a couple inactive, on purpose
                'is_online' => true, 'current_lat' => 12.921, 'current_lng' => 77.521,
                'location_updated_at' => now(),
            ]);
            $this->track('providers', $provider);
            $providers[] = $provider;
        }

        return $providers;
    }

    // ============================= Workers =============================

    private function seedWorkers(array $geo, array $providers, int $count): array
    {
        $workers = [];
        $capabilityTypes = ['service_technician', 'handyman', 'helper'];

        for ($i = 1; $i <= $count; $i++) {
            $franchise = $geo['franchises'][$i % count($geo['franchises'])];
            $zone = collect($geo['zones'])->firstWhere('franchise_id', $franchise->id) ?? $geo['zones'][0];

            $user = User::create([
                'uuid' => (string) Str::uuid(), 'name' => "[QA] Worker {$i}",
                'phone' => '9'.fake()->unique()->numerify('#########'),
                'role' => 'provider', 'status' => 'active', 'franchise_id' => $franchise->id,
            ]);
            $this->track('users', $user);

            $isInactive = $i % 12 === 0;
            $worker = FieldWorker::create([
                'user_id' => $user->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
                'kyc_status' => 'approved', 'is_active' => ! $isInactive,
                'is_online' => ! $isInactive, 'current_lat' => 12.92, 'current_lng' => 77.52,
                'location_updated_at' => now(),
            ]);
            $this->track('field_workers', $worker);
            $workers[] = $worker;

            $hasCapabilityMismatch = $i % 20 === 0; // deliberately no relevant capability
            if (! $hasCapabilityMismatch) {
                $isMultiCapability = $i % 4 === 0;
                $typesToGrant = $isMultiCapability ? $capabilityTypes : [$capabilityTypes[$i % 3]];
                foreach ($typesToGrant as $type) {
                    $cap = FieldWorkerCapability::create(['field_worker_id' => $worker->id, 'capability_type' => $type, 'service_category_id' => null]);
                    $this->track('field_worker_capabilities', $cap);
                }
            }

            $doc = FieldWorkerDocument::create([
                'field_worker_id' => $worker->id, 'type' => 'id_proof',
                'file_url' => 'documents/qa-placeholder.pdf', 'status' => 'approved',
            ]);
            $this->track('field_worker_documents', $doc);

            // Platform-direct: ~1 in 6 workers get NO partner link at all,
            // matching the approved architecture's requirement that a
            // Worker can exist without a Partner.
            if ($i % 6 !== 0) {
                $partner = $providers[array_rand($providers)];
                $link = PartnerWorker::create([
                    'provider_id' => $partner->id, 'field_worker_id' => $worker->id,
                    'status' => $isInactive ? 'suspended' : 'active',
                ]);
                $this->track('partner_workers', $link);

                // A "multi-partner worker" case: a second link for every 8th worker.
                if ($i % 8 === 0 && count($providers) > 1) {
                    $secondPartner = $providers[($i + 1) % count($providers)];
                    if ($secondPartner->id !== $partner->id) {
                        $link2 = PartnerWorker::create([
                            'provider_id' => $secondPartner->id, 'field_worker_id' => $worker->id, 'status' => 'active',
                        ]);
                        $this->track('partner_workers', $link2);
                    }
                }
            }
        }

        return $workers;
    }

    // ============================= Business accounts =============================

    private function seedBusinessAccounts(array $geo): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $owner = User::create([
                'uuid' => (string) Str::uuid(), 'name' => "[QA] Business Owner {$i}",
                'phone' => '9'.fake()->unique()->numerify('#########'), 'role' => 'customer', 'status' => 'active',
            ]);
            $this->track('users', $owner);

            $franchise = $geo['franchises'][$i % count($geo['franchises'])];
            $account = BusinessAccount::create([
                'owner_user_id' => $owner->id, 'name' => "[QA] Business {$i}",
                'business_type' => 'retail', 'franchise_id' => $franchise->id,
                'status' => 'active', 'kyc_status' => 'approved',
            ]);
            $this->track('business_accounts', $account);

            $address = Address::create([
                'user_id' => $owner->id, 'franchise_id' => $franchise->id,
                'zone_id' => collect($geo['zones'])->firstWhere('franchise_id', $franchise->id)?->id,
                'label' => 'Business', 'lat' => 12.92, 'lng' => 77.52, 'address_line' => "[QA] Business Address {$i}",
            ]);
            $this->track('addresses', $address);

            $location = BusinessLocation::create(['business_account_id' => $account->id, 'address_id' => $address->id, 'label' => 'Main Branch']);
            $this->track('business_locations', $location);
        }
    }

    // ============================= Bookings (through real Actions) =============================

    private function seedBookings(array $geo, array $customers, array $providers, array $workers, array $catalog, int $count): array
    {
        $distribution = [
            'pending' => 0.10, 'searching_provider' => 0.10, 'assigned' => 0.10,
            'in_progress' => 0.10, 'on_hold' => 0.05, 'completed' => 0.40, 'cancelled' => 0.15,
        ];

        $stats = array_fill_keys(array_keys($distribution), 0);
        $activeProviders = array_filter($providers, fn ($p) => $p->is_active && $p->kyc_status === 'approved');
        $activeProviders = array_values($activeProviders);

        // Give every QA customer at least 2 addresses up front.
        $addressesByCustomer = [];
        foreach ($customers as $customer) {
            $franchise = $geo['franchises'][array_rand($geo['franchises'])];
            $zone = collect($geo['zones'])->firstWhere('franchise_id', $franchise->id) ?? $geo['zones'][0];
            $addrs = [];
            for ($a = 1; $a <= 2; $a++) {
                $address = Address::create([
                    'user_id' => $customer->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
                    'label' => $a === 1 ? 'Home' : 'Work', 'lat' => 12.92 + ($a * 0.001), 'lng' => 77.52 + ($a * 0.001),
                    'address_line' => "[QA] Address {$a} for {$customer->name}",
                ]);
                $this->track('addresses', $address);
                $addrs[] = $address;
            }
            $addressesByCustomer[$customer->id] = ['franchise' => $franchise, 'zone' => $zone, 'addresses' => $addrs];
        }

        $targetCounts = [];
        $remaining = $count;
        $keys = array_keys($distribution);
        foreach ($keys as $i => $status) {
            $targetCounts[$status] = $i === count($keys) - 1 ? $remaining : (int) round($count * $distribution[$status]);
            $remaining -= $targetCounts[$status];
        }

        $sequence = [];
        foreach ($targetCounts as $status => $n) {
            $sequence = array_merge($sequence, array_fill(0, $n, $status));
        }
        shuffle($sequence);

        foreach ($sequence as $targetStatus) {
            $customer = $customers[array_rand($customers)];
            $ctx = $addressesByCustomer[$customer->id];
            $service = $catalog['services'][array_rand($catalog['services'])];

            $booking = app(CreateBookingAction::class)->execute([
                'franchise_id' => $ctx['franchise']->id, 'zone_id' => $ctx['zone']->id,
                'customer_id' => $customer->id, 'service_id' => $service->id,
                'address_id' => $ctx['addresses'][array_rand($ctx['addresses'])]->id,
                'price_quoted' => $service->base_price, 'payment_method' => 'online',
            ]);
            $this->track('bookings', $booking);
            if ($booking->payment) {
                $this->track('payments', $booking->payment->id);
            }

            if ($targetStatus === 'pending') {
                $stats['pending']++;

                continue;
            }

            // pending -> searching_provider via the real dispatch job.
            (new ServiceMatchingJob($booking->id, 1))->handle(app(DispatchService::class));
            $booking->refresh();

            if ($targetStatus === 'searching_provider') {
                $stats['searching_provider']++;

                continue;
            }

            // searching_provider -> assigned. Admin-direct-assign is used
            // here for determinism (real dispatch depends on geographic
            // matching succeeding, which isn't guaranteed for every
            // synthetic coordinate) — it's the same real
            // AdminReassignBookingAction an admin uses from the live queue,
            // not a synthetic shortcut.
            $provider = $activeProviders[array_rand($activeProviders)] ?? $providers[0];
            $booking = app(AdminReassignBookingAction::class)->execute($booking->id, $provider->id, 'QA seed: assigned');

            if ($targetStatus === 'assigned') {
                $stats['assigned']++;

                continue;
            }

            // A fraction of assigned bookings get delegated to a Worker,
            // exercising Phase B0.2 for real within the seed itself.
            $eligibleWorkers = array_values(array_filter($workers, function ($w) use ($provider) {
                return $w->is_active && PartnerWorker::where('provider_id', $provider->id)->where('field_worker_id', $w->id)->where('status', 'active')->exists()
                    && FieldWorkerCapability::where('field_worker_id', $w->id)->whereIn('capability_type', ['service_technician', 'handyman', 'helper'])->exists();
            }));
            if (! empty($eligibleWorkers) && random_int(1, 100) <= 40) {
                try {
                    $booking = app(AssignBookingToWorkerAction::class)->execute($booking->id, $provider, $eligibleWorkers[array_rand($eligibleWorkers)]->id);
                } catch (\RuntimeException) {
                    // Capability/team mismatch for this specific booking's
                    // category — leave it with the Partner self-performing.
                }
            }

            $booking = app(StartBookingAction::class)->execute($booking->id, $booking->start_otp);

            if ($targetStatus === 'in_progress') {
                $stats['in_progress']++;

                continue;
            }

            if ($targetStatus === 'on_hold') {
                app(PlaceBookingOnHoldAction::class)->execute($booking->id, 'awaiting_spares', 'QA seed: on hold');
                $stats['on_hold']++;

                continue;
            }

            if ($targetStatus === 'cancelled') {
                app(AdminCancelBookingAction::class)->execute($booking->id, 'QA seed: admin cancellation');
                $stats['cancelled']++;

                continue;
            }

            // completed — the real financial/loyalty/referral chain fires here.
            app(CompleteBookingAction::class)->execute($booking->id, $booking->provider, $booking->completion_otp);
            $stats['completed']++;
        }

        // Side-effect tables the real Actions above create automatically
        // (commissions, payments, wallet_transactions, wallets,
        // loyalty_points, notifications, booking_status_history,
        // dispatch_attempts) are NOT tracked row-by-row here — qa:clean
        // derives and deletes them from the booking_id/user_id foreign
        // keys of what IS tracked (bookings, users), which is exact and
        // avoids missing a row some Action created that this seeder didn't
        // explicitly know about.
        return $stats;
    }

    // ============================= Subscriptions =============================

    private function seedSubscriptions(array $customers, array $providers, array $plans, int $count): array
    {
        $stats = ['active' => 0, 'paused' => 0, 'cancelled_pending_expiry' => 0, 'upgraded' => 0];
        $service = app(SubscriptionService::class);

        for ($i = 0; $i < $count; $i++) {
            $customer = $customers[array_rand($customers)];
            $planKey = ['quantity', 'percent_discount', 'fixed_discount'][$i % 3];
            $plan = $plans[$planKey];

            try {
                $result = $service->initiateSubscribe($customer, 'customer', $plan);
            } catch (\RuntimeException) {
                continue; // e.g. eligibility rule not met for this synthetic actor — skip, don't fabricate around it
            }
            $subscription = \App\Models\Subscription::find($result['subscription_id']);
            $this->track('subscriptions', $subscription);
            foreach (\App\Models\EntitlementBalance::where('subscription_id', $subscription->id)->pluck('id') as $balId) {
                $this->track('entitlement_balances', $balId);
            }

            $variant = $i % 5;
            if ($variant === 1) {
                $service->pause($subscription);
                $stats['paused']++;
            } elseif ($variant === 2) {
                $service->cancel($subscription, 'QA seed: auto_renew off, still active until period end');
                $stats['cancelled_pending_expiry']++;
            } elseif ($variant === 3 && $planKey !== 'fixed_discount') {
                $service->scheduleUpgrade($subscription, $plans['fixed_discount']);
                $stats['upgraded']++;
            } else {
                $stats['active']++;
            }
        }

        return $stats;
    }

    // ============================= Banners / CMS =============================

    private function seedBanners(array $geo): void
    {
        $franchise = $geo['franchises'][0];

        $global = Banner::create([
            'title' => '[QA] Platform-Wide Banner', 'image' => 'banners/qa-placeholder.png',
            'placement' => 'top', 'is_active' => true, 'sort_order' => 1,
        ]);
        $this->track('banners', $global);

        $scoped = Banner::create([
            'title' => '[QA] Franchise-Scoped Banner', 'image' => 'banners/qa-placeholder.png',
            'placement' => 'mid', 'franchise_id' => $franchise->id, 'is_active' => true, 'sort_order' => 2,
        ]);
        $this->track('banners', $scoped);

        $inactive = Banner::create([
            'title' => '[QA] Inactive Banner', 'image' => 'banners/qa-placeholder.png',
            'placement' => 'top', 'is_active' => false, 'sort_order' => 3,
        ]);
        $this->track('banners', $inactive);
    }

    private function seedCms(): void
    {
        $page = ContentPage::create(['slug' => 'qa-test-page-'.Str::random(6), 'title' => '[QA] Test Page', 'content' => 'QA test content.']);
        $this->track('content_pages', $page);

        $faq = Faq::create(['category' => 'QA', 'question' => '[QA] Sample question?', 'answer' => 'Sample answer.', 'sort_order' => 1, 'is_active' => true]);
        $this->track('faqs', $faq);
    }
}
