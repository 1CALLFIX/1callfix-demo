<?php

namespace App\Console\Commands;

use App\Models\Accommodation;
use App\Models\AccommodationType;
use App\Models\AddOn;
use App\Models\AddOnGroup;
use App\Models\FieldWorker;
use App\Models\FieldWorkerCapability;
use App\Models\HotelRatePlan;
use App\Models\HotelRoomType;
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
 * the seller (test.seller, /seller/login, an approved open "Test Shop" with one product per shop option, TEST_SHOP_PRODUCTS) and the
 * restaurant (test.restaurant, /seller/login, an approved open "Test Restaurant" with TEST_RESTAURANT_DISHES) are here; add each new
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
        // Food delivery F1 (2026-11-03): a restaurant is a seller whose store is module 'food' (/seller/login too).
        'restaurant' => ['Test Restaurant', 'provider', null, null, '6000000111'],
        // Hotels H1 (2026-11-12): a hotel is a provider account that owns an approved Accommodation (partner sign-in → /hotel).
        'hotel' => ['Test Hotel', 'provider', null, null, '6000000112'],
        // Events E1 (2026-11-13): an organiser is a provider account that owns an approved EventOrganiser (partner sign-in → /organiser).
        'organiser' => ['Test Organiser', 'provider', null, null, '6000000113'],
        // Function halls R1 (2026-11-13): a hall owner is a provider account that owns an approved Hall (partner sign-in → /venue).
        'hall' => ['Test Hall Owner', 'provider', null, null, '6000000114'],
        // Houses for rent (2026-11-14): a house owner is a provider account that owns approved HouseListings (→ /venue Rental portal).
        'homeowner' => ['Test House Owner', 'provider', null, null, '6000000115'],
    ];

    /** A placeholder FSSAI number for the test restaurant only (hidden from the public like every test shop). */
    public const TEST_RESTAURANT_FSSAI = '10000000000001';

    /**
     * The test restaurant's menu, one dish per thing to try (name => [what it tests, column values, add-on group]).
     * The restaurant has a ₹99 minimum order and ₹30 delivery fee, no opening hours (open whenever switched on).
     */
    private const TEST_RESTAURANT_DISHES = [
        'Test Veg Meals' => ['Veg mark, ₹120, no choices.', ['price' => 120, 'food_type' => 'veg'], null],
        'Test Masala Dosa' => ['Must pick 1 chutney (required choice).', ['price' => 80, 'food_type' => 'veg'], 'Test Choose chutney'],
        'Test Chicken Biryani' => ['Non-veg mark, ₹220, optional extras up to 2.', ['price' => 220, 'food_type' => 'non_veg'], 'Test Extras'],
        'Test Egg Fried Rice' => ['Egg mark, 10% off: paid online only.', ['price' => 150, 'food_type' => 'egg', 'discount_percent' => 10], null],
        'Test Sold Out Dish' => ['Marked sold out for today: cannot be ordered until tomorrow.', ['price' => 90, 'food_type' => 'veg', 'sold_out' => true], null],
        'Test Hidden Dish' => ['Switched off by the restaurant: seen on its menu page, never by customers.', ['price' => 100, 'food_type' => 'veg', 'is_active' => false], null],
    ];

    /** group name => [min, max, [option => price]] */
    private const TEST_RESTAURANT_GROUPS = [
        'Test Choose chutney' => [1, 1, ['Coconut chutney' => 0, 'Tomato chutney' => 0, 'Peanut chutney' => 5]],
        'Test Extras' => [0, 2, ['Extra raita' => 20, 'Boiled egg' => 15, 'Extra gravy' => 25]],
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
        'Test T-Shirt' => ['Pick a size and a colour. XL costs more; M / Blue is sold out.', ['price' => 300, 'unit_value' => 1, 'unit' => 'piece', 'option_names' => ['Size', 'Colour']]],
        'Test Rice' => ['Sold by weight: 1 kg, or a 5 kg bag at its own price. 10% off (online only) on both.', ['price' => 70, 'discount_percent' => 10, 'option_names' => ['Weight']]],
    ];

    /** Product choices 2026-11-02: product name => [[options, price or null, stock], ...] for the Test Shop. */
    private const TEST_SHOP_CHOICES = [
        'Test T-Shirt' => [
            [['Size' => 'M', 'Colour' => 'Red'], null, 10], [['Size' => 'M', 'Colour' => 'Blue'], null, 0],
            [['Size' => 'L', 'Colour' => 'Red'], null, 10], [['Size' => 'L', 'Colour' => 'Blue'], null, 10],
            [['Size' => 'XL', 'Colour' => 'Red'], 350, 5], [['Size' => 'XL', 'Colour' => 'Blue'], 350, 5],
        ],
        'Test Rice' => [
            [['Weight' => '1 kg'], null, 40], [['Weight' => '5 kg'], 330, 10],
        ],
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
                } elseif ($key === 'restaurant') {
                    $this->makeTestRestaurant($user, $franchise, $zone);
                } elseif ($key === 'hotel') {
                    $this->makeTestHotel($user, $franchise, $zone);
                } elseif ($key === 'organiser') {
                    $this->makeTestOrganiser($user, $franchise, $zone);
                } elseif ($key === 'hall') {
                    $this->makeTestHall($user, $franchise, $zone);
                } elseif ($key === 'homeowner') {
                    $this->makeTestHomes($user, $franchise, $zone);
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

                return [$key, $email, $roleSlug ? "{$roleSlug} @ {$scopeType} #{$scopeIds[$scopeType]}" : ($key === 'rider' ? 'parcel + shop rider (approved)' : ($key === 'seller' ? 'seller, shop approved' : ($key === 'restaurant' ? 'restaurant, approved' : ($key === 'hotel' ? 'hotel, approved' : ($key === 'organiser' ? 'event organiser, approved, 1 event in review' : ($key === 'hall' ? 'one approved test hall of every kind' : ($key === 'homeowner' ? 'four approved test homes for rent' : $userRole)))))))];
            });
        }

        $this->info("Test accounts for franchise #{$franchise->id} {$franchise->name}, zone #{$zone->id} {$zone->name}:");
        $this->table(['Account', 'Login email', 'Access'], $rows);
        $this->line('Staff log in at /admin/login, the provider at /provider/login, the rider at /rider/login, the seller at /seller/login, the hotel at /provider/login (opens /hotel), the organiser at /provider/login (opens /organiser), the customer at /login.');

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
                'option_names' => null, 'unit_value' => null, 'unit' => null,
            ]);
        }
        foreach (self::TEST_SHOP_CHOICES as $name => $rows) {
            $product = Product::where('store_id', $store->id)->where('name', $name)->first();
            foreach ($rows as [$options, $price, $stock]) {
                $product?->variants()->updateOrCreate(['name' => implode(' / ', $options)], [
                    'options' => $options, 'price_override' => $price, 'stock' => $stock, 'is_active' => true,
                ]);
            }
            $product?->syncStockFromChoices();
        }
    }

    /**
     * Food F1: the test restaurant — approved, active and open, FSSAI filled, with the TEST_RESTAURANT_DISHES and their
     * choice groups. Re-running create resets these dishes and groups; anything else on the menu is left alone.
     */
    private function makeTestRestaurant(User $user, Franchise $franchise, Zone $zone): void
    {
        $provider = Provider::updateOrCreate(['user_id' => $user->id], [
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'provider_type' => 'independent',
            'kyc_status' => 'approved', 'is_active' => true, 'is_online' => false, 'skills' => null,
        ]);
        $store = Store::withTrashed()->firstOrNew(['provider_id' => $provider->id, 'module' => Modules::FOOD]);
        $store->fill([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'name' => $store->name ?: 'Test Restaurant', 'address_line' => $store->address_line ?: 'Test address, '.$zone->name,
            'lat' => $store->lat ?? $zone->center_lat ?? 0, 'lng' => $store->lng ?? $zone->center_lng ?? 0,
            'phone' => $user->phone, 'is_active' => true, 'is_open' => true,
            'approval_status' => 'approved', 'approved_at' => $store->approved_at ?? now(),
        ])->save();
        if ($store->trashed()) {
            $store->restore();
        }
        $store->forceFill([
            'minimum_order_amount' => 99, 'delivery_fee_flat' => 30, 'free_delivery_above_amount' => null,
            'fssai_number' => self::TEST_RESTAURANT_FSSAI, 'cuisines' => 'South Indian, Biryani', 'cost_for_two' => 300,
            'prep_minutes' => 20, 'busy_until' => null, 'busy_extra_minutes' => null,
        ])->save();

        $groups = [];
        foreach (self::TEST_RESTAURANT_GROUPS as $groupName => [$min, $max, $options]) {
            $group = AddOnGroup::withTrashed()->firstOrNew(['store_id' => $store->id, 'name' => $groupName]);
            $group->fill(['min_select' => $min, 'max_select' => $max, 'is_active' => true])->save();
            if ($group->trashed()) {
                $group->restore();
            }
            foreach ($options as $optionName => $price) {
                $addOn = AddOn::withTrashed()->firstOrNew(['store_id' => $store->id, 'add_on_group_id' => $group->id, 'name' => $optionName]);
                $addOn->fill(['price' => $price, 'is_active' => true])->save();
                if ($addOn->trashed()) {
                    $addOn->restore();
                }
            }
            $groups[$groupName] = $group->id;
        }

        $categoryId = MarketplaceCategory::where('module', Modules::FOOD)->where('is_active', true)->orderBy('position')->value('id');
        foreach (self::TEST_RESTAURANT_DISHES as $name => [$description, $attributes, $groupName]) {
            $soldOut = (bool) ($attributes['sold_out'] ?? false);
            unset($attributes['sold_out']);
            $dish = Product::updateOrCreate(['store_id' => $store->id, 'name' => $name], $attributes + [
                'description' => $description, 'marketplace_category_id' => $categoryId,
                'tax_percent' => 0, 'discount_percent' => null, 'stock' => \App\Livewire\Seller\Products::RESTAURANT_STOCK,
                'maximum_cart_quantity' => null, 'is_active' => true, 'is_approved' => true,
                'out_of_stock_until' => $soldOut ? $store->localNow()->endOfDay()->utc() : null,
            ]);
            $dish->addOnGroups()->sync($groupName ? [$groups[$groupName] => ['position' => 0]] : []);
        }
    }

    /** Free-to-use hotel photos (Unsplash licence; their CDN is meant to be linked directly). */
    private const UNSPLASH = 'https://images.unsplash.com/';

    private const PHOTO = '?w=1600&q=80&auto=format&fit=crop';

    /**
     * The test hotel's rooms, one per option to try (name => [what it tests, rooms of this kind, adults, children,
     * beds, normal price, weekend price, meal plan, photos]).
     */
    private const TEST_HOTEL_ROOMS = [
        'Test Standard Non-AC' => ['Room only, ₹999 (0% GST band), ₹1,249 on weekend nights. One night closed on the calendar 14 days ahead.', 3, 2, 1, '1 queen bed', 999, 1249, 'room_only', ['photo-1582719478250-c89cae4dc85b', 'photo-1618773928121-c32242e63f39']],
        'Test Deluxe AC' => ['Breakfast included, ₹1,799 (5% GST), ₹2,249 on weekends, festival price ₹2,499 seven days ahead.', 2, 3, 1, '1 king bed', 1799, 2249, 'breakfast', ['photo-1631049307264-da0ec9d70304', 'photo-1611892440504-42a792e24d32']],
        'Test Family Suite' => ['All meals, 4 adults + 2 children, ₹3,499 (5% GST), ₹3,949 on weekends. Only 1 such room: "Only 1 left".', 1, 4, 2, '1 king + 2 single beds', 3499, 3949, 'full_board', ['photo-1590490360182-c33d57733427', 'photo-1578683010236-d716f9a3f461']],
    ];

    /**
     * Hotels H1/H4: the test hotel with every option filled in (owner 2026-11-12: "a test hotel with all options and
     * beautiful pics") — approved, featured, photos, facilities, house rules, GST number, three rooms (room only /
     * breakfast / all meals) with weekend prices, a festival price and a closed night. Re-running create resets all of
     * this; bookings are left alone.
     */
    private function makeTestHotel(User $user, Franchise $franchise, Zone $zone): void
    {
        $provider = Provider::updateOrCreate(['user_id' => $user->id], [
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'provider_type' => 'independent',
            'kyc_status' => 'approved', 'is_active' => true, 'is_online' => false, 'skills' => null,
        ]);
        $hotel = Accommodation::withTrashed()->firstOrNew(['provider_id' => $provider->id]);
        $hotel->fill([
            'accommodation_type_id' => $hotel->accommodation_type_id ?: AccommodationType::where('slug', 'hotel')->value('id') ?? AccommodationType::orderBy('id')->value('id'),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'name' => 'Test Hotel', 'address_line' => $hotel->address_line ?: 'Test address, '.$zone->name,
            'description' => "Test hotel for walk-throughs, with every option filled in.\nPool, AC rooms with hot water, Wi-Fi and a 24-hour front desk. Five minutes from the railway station.",
            'policies' => "Photo ID needed at check-in for every adult.\nNo smoking in rooms. Pets on request.",
            // Its own pin a little off the zone centre, so "location pinned" is ticked like a real hotel's.
            'lat' => (float) ($zone->center_lat ?? 0) + 0.002, 'lng' => (float) ($zone->center_lng ?? 0) + 0.002,
            'contact_phone' => $user->phone, 'contact_email' => $user->email, 'gst_number' => '37AAAAA0000A1Z5',
            'check_in_time_start' => '12:00:00', 'check_out_time' => '11:00:00',
            'cover_image' => self::UNSPLASH.'photo-1564501049412-61c2a3083791'.self::PHOTO,
            'photos' => array_map(fn ($id) => self::UNSPLASH.$id.self::PHOTO, ['photo-1542314831-068cd1dbfeeb', 'photo-1571896349842-33c89424de2d', 'photo-1551882547-ff40c63fe5fa', 'photo-1584132967334-10e028bd69f7']),
            'facilities' => \App\Support\HotelSettings::lines('hotel.amenities'),
            'is_featured' => true, 'needs_acceptance' => null, 'is_active' => true,
            'approval_status' => 'approved', 'approved_at' => $hotel->approved_at ?? now(), 'rejection_reason' => null,
        ])->save();
        if ($hotel->trashed()) {
            $hotel->restore();
        }
        foreach (self::TEST_HOTEL_ROOMS as $name => [$about, $inventory, $adults, $children, $beds, $price, $weekend, $meal, $photos]) {
            $urls = array_map(fn ($id) => self::UNSPLASH.$id.self::PHOTO, $photos);
            $room = HotelRoomType::updateOrCreate(['accommodation_id' => $hotel->id, 'name' => $name], [
                'description' => $about, 'max_adults' => $adults, 'max_children' => $children, 'bed_configuration' => $beds,
                'total_inventory' => $inventory, 'cover_image' => $urls[0], 'photos' => $urls, 'is_active' => true,
            ]);
            HotelRatePlan::updateOrCreate(['hotel_room_type_id' => $room->id, 'name' => 'Standard'], [
                'nightly_rate' => $price, 'weekend_rate' => $weekend, 'meal_plan' => $meal, 'is_active' => true,
            ]);
            // Calendar options to try: a festival price on the Deluxe room, a closed night on the Standard room.
            if ($name === 'Test Deluxe AC') {
                \App\Models\HotelRoomAvailability::updateOrCreate(['hotel_room_type_id' => $room->id, 'date' => now()->addDays(7)->toDateString()], ['price_override' => 2499]);
            }
            if ($name === 'Test Standard Non-AC') {
                \App\Models\HotelRoomAvailability::updateOrCreate(['hotel_room_type_id' => $room->id, 'date' => now()->addDays(14)->toDateString()], ['is_available' => false, 'reason' => 'blocked']);
            }
        }
    }

    /**
     * Events E1: the test organiser, approved, with one test event ("Test Year End Bash") in review and three ticket
     * types (Early Bird / Regular / Couple), so the admin approval and the portal can be walked through at once.
     * Re-running create resets the organiser and that event; other events are left alone.
     */
    private function makeTestOrganiser(User $user, Franchise $franchise, Zone $zone): void
    {
        $provider = Provider::updateOrCreate(['user_id' => $user->id], [
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'provider_type' => 'independent',
            'kyc_status' => 'approved', 'is_active' => true, 'is_online' => false, 'skills' => null,
        ]);
        $organiser = \App\Models\EventOrganiser::updateOrCreate(['provider_id' => $provider->id], [
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'name' => 'Test Organiser',
            'about' => 'Test organiser for walk-throughs. Not a real event company.',
            'contact_phone' => $user->phone, 'address_line' => 'Test address, '.$zone->name,
            'approval_status' => 'approved', 'approved_at' => now(), 'rejection_reason' => null, 'is_active' => true,
        ]);

        $tz = app(\App\Services\TimezoneResolver::class)->platformTimezone();
        $start = now($tz)->addDays(30)->setTime(20, 0);
        $event = \App\Models\Event::firstOrNew(['event_organiser_id' => $organiser->id, 'title' => 'Test Year End Bash']);
        $event->fill([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'slug' => $event->slug ?: \App\Models\Event::uniqueSlug('Test Year End Bash'),
            'category' => 'New Year', 'description' => "Test event for walk-throughs. DJ night with food stalls.\nNot a real event.",
            'cover_image' => self::UNSPLASH.'photo-1492684223066-81342ee5ff30?w=1600&q=80',
            'venue_name' => 'Test Venue', 'venue_address' => 'Test address, '.$zone->name,
            'starts_at' => $start->copy()->utc(), 'ends_at' => $start->copy()->addHours(5)->utc(),
            'min_age' => 18, 'terms' => 'Carry a photo ID. Test event only.',
            'status' => 'in_review', 'submitted_at' => now(), 'approved_at' => null, 'rejection_reason' => null,
        ])->save();
        foreach ([['Early Bird', 499, 50, 1, 10], ['Regular', 799, 200, 1, 0], ['Couple', 1399, 50, 2, 0]] as $i => [$name, $price, $qty, $admits, $earlyDays]) {
            \App\Models\EventTicketType::updateOrCreate(['event_id' => $event->id, 'name' => $name], [
                'price' => $price, 'quantity' => $qty, 'admits' => $admits, 'max_per_order' => 10, 'sort_order' => $i, 'is_active' => true,
                'sale_ends_at' => $earlyDays ? now($tz)->addDays($earlyDays)->endOfDay()->utc() : null,
            ]);
        }
    }

    /**
     * Function halls: the test hall owner with one approved, switched-on test hall of every kind (owner 2026-11-13: "a test
     * rental property in each category"), each with its own photos, capacity, slots and prices, so every kind can be
     * walked through. Re-running create resets these halls; other halls are left alone.
     */
    public const TEST_HALLS = [
        // name, kind, seats, standing, AC, parking, rooms, deposit, [slot => price], cover, photos
        ['Test Function Hall', 'Function hall', 400, 700, true, 40, 2, 10000, ['Full day' => 60000, 'Morning' => 35000, 'Evening' => 40000],
            'photo-1519167758481-83f550bb49b3', ['photo-1464366400600-7168b8af9bc3', 'photo-1511795409834-ef04bbd61622', 'photo-1505236858219-8359eb29e329']],
        ['Test Banquet Hall', 'Banquet hall', 250, 400, true, 25, 1, 8000, ['Lunch' => 30000, 'Dinner' => 38000],
            'photo-1519225421980-715cb0215aed', ['photo-1478146896981-b80fe463b330', 'photo-1464366400600-7168b8af9bc3', 'photo-1505236858219-8359eb29e329']],
        ['Test Convention Centre', 'Convention centre', 1500, 2500, true, 200, 6, 50000, ['Full day' => 250000, 'Morning' => 140000, 'Evening' => 160000],
            'photo-1540541338287-41700207dee6', ['photo-1505236858219-8359eb29e329', 'photo-1511795409834-ef04bbd61622', 'photo-1519167758481-83f550bb49b3']],
        ['Test Garden Lawn', 'Open lawn / garden', 600, 1000, false, 60, 0, 15000, ['Evening' => 45000, 'Full day' => 70000],
            'photo-1469371670807-013ccf25f16a', ['photo-1519225421980-715cb0215aed', 'photo-1478146896981-b80fe463b330', 'photo-1464366400600-7168b8af9bc3']],
        ['Test Party Hall', 'Party hall', 80, 120, true, 10, 0, 3000, ['Afternoon' => 8000, 'Evening' => 12000],
            'photo-1530103862676-de8c9debad1d', ['photo-1533174072545-7a4b6ad7a6c3', 'photo-1511795409834-ef04bbd61622', 'photo-1478146896981-b80fe463b330']],
        ['Test Community Hall', 'Community hall', 300, 450, false, 20, 1, 5000, ['Full day' => 18000, 'Morning' => 10000, 'Evening' => 12000],
            'photo-1497366216548-37526070297c', ['photo-1505236858219-8359eb29e329', 'photo-1464366400600-7168b8af9bc3', 'photo-1519167758481-83f550bb49b3']],
        ['Test Farmhouse Resort', 'Resort / farmhouse', 200, 350, true, 50, 8, 25000, ['Full day' => 90000, 'Evening' => 55000],
            'photo-1587271407850-8d438ca9fdf2', ['photo-1469371670807-013ccf25f16a', 'photo-1519225421980-715cb0215aed', 'photo-1530103862676-de8c9debad1d']],
    ];

    private const SLOT_TIMES = [
        'Full day' => ['06:00:00', '23:00:00'], 'Morning' => ['06:00:00', '14:00:00'], 'Afternoon' => ['12:00:00', '17:00:00'],
        'Evening' => ['17:00:00', '23:00:00'], 'Lunch' => ['10:00:00', '16:00:00'], 'Dinner' => ['18:00:00', '23:30:00'],
    ];

    private function makeTestHall(User $user, Franchise $franchise, Zone $zone): void
    {
        $provider = Provider::updateOrCreate(['user_id' => $user->id], [
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'provider_type' => 'independent',
            'kyc_status' => 'approved', 'is_active' => true, 'is_online' => false, 'skills' => null,
        ]);
        $photo = fn (string $id) => self::UNSPLASH.$id.'?w=1600&q=80';
        foreach (self::TEST_HALLS as [$name, $kind, $seats, $standing, $ac, $parking, $rooms, $deposit, $slots, $cover, $photos]) {
            $hall = \App\Models\Hall::firstOrNew(['provider_id' => $provider->id, 'name' => $name]);
            $hall->fill([
                'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
                'slug' => $hall->slug ?: \App\Models\Hall::uniqueSlug($name),
                'hall_type' => $kind, 'about' => "Test {$kind} for walk-throughs. Seats {$seats}.\nNot a real hall.",
                'address_line' => 'Test address, '.$zone->name, 'contact_phone' => $user->phone,
                'seating_capacity' => $seats, 'floating_capacity' => $standing, 'is_ac' => $ac, 'parking_cars' => $parking, 'rooms' => $rooms,
                // Owner 2026-11-14: facilities in their sections (one paid), what may come from outside, tapped rules.
                'amenities' => ['Car parking', 'VIP cars can drive up to the entrance', "Bride's room", "Groom's room", 'Grand entry for the bride and groom',
                    'Stage', 'Dining hall', 'Kitchen', 'Generator / power backup', 'Fire extinguishers', 'First aid kit', 'Lift'],
                'facility_charges' => ['Generator / power backup' => 1500],
                'outside_policy' => ['Outside food' => 'yes', 'Outside caterers / cooks' => 'yes', 'Vessels and utensils from outside' => 'fee', 'Outside decorators' => 'no'],
                'rule_list' => ['Music and loudspeakers till 10 PM', 'No fireworks inside the hall', 'No smoking inside'],
                'cover_image' => $photo($cover), 'photos' => array_map($photo, $photos),
                'rules' => 'Test hall only.', 'deposit_amount' => $deposit,
                'status' => 'approved', 'submitted_at' => now(), 'approved_at' => now(), 'rejection_reason' => null,
                'is_active' => true, 'pending_changes' => null, 'pending_requested_at' => null, 'pending_rejection' => null,
            ])->save();
            $i = 0;
            foreach ($slots as $slot => $price) {
                [$from, $to] = self::SLOT_TIMES[$slot];
                \App\Models\HallSlot::updateOrCreate(['hall_id' => $hall->id, 'name' => $slot], ['start_time' => $from, 'end_time' => $to, 'price' => $price, 'is_active' => true, 'sort_order' => $i++]);
            }
        }
    }

    /**
     * Houses for rent: the test house owner with four approved, live test homes (flat, house, room, PG), each with its own
     * photos, rent, deposit, facilities and rules, so the whole walk-through (browse, filter, ask to visit, owner accepts)
     * can be done. Re-running create resets them; other homes are left alone.
     */
    public const TEST_HOMES = [
        // size, kind, rent, deposit months, furnishing, tenants, cover, photos
        ['2 BHK', 'Flat / apartment', 12000, 2, 'Semi-furnished', ['Family', 'Working professionals'],
            'photo-1502672260266-1c1ef2d93688', ['photo-1505691938895-1758d7feb511', 'photo-1556909114-f6e7ad7d3136', 'photo-1552321554-5fefe8c9ef14']],
        ['3 BHK', 'Independent house', 20000, 3, 'Unfurnished', ['Family'],
            'photo-1568605114967-8130f3a36994', ['photo-1484154218962-a197022b5858', 'photo-1556911220-bff31c812dba', 'photo-1584622650111-993a426fbf0a']],
        ['1 RK', 'Single room', 4500, 1, 'Fully furnished', ['Bachelor men', 'Students'],
            'photo-1522708323590-d24dbb6b0267', ['photo-1505693416388-ac5ce068fe85', 'photo-1556912173-3bb406ef7e77', 'photo-1584622781564-1d987f7333c1']],
        ['1 BHK', 'PG / hostel', 6500, 1, 'Fully furnished', ['Bachelor women', 'Working professionals'],
            'photo-1555854877-bab0e564b8d5', ['photo-1540518614846-7eded433c457', 'photo-1556909172-54557c7e4fb7', 'photo-1600566752355-35792bedcfea']],
    ];

    private function makeTestHomes(User $user, Franchise $franchise, Zone $zone): void
    {
        $provider = Provider::updateOrCreate(['user_id' => $user->id], [
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'provider_type' => 'independent',
            'kyc_status' => 'approved', 'is_active' => true, 'is_online' => false, 'skills' => null,
        ]);
        $photo = fn (string $id) => self::UNSPLASH.$id.'?w=1600&q=80';
        foreach (self::TEST_HOMES as [$size, $kind, $rent, $months, $furnishing, $tenants, $cover, $photos]) {
            $title = \App\Models\HouseListing::makeTitle($size, $kind, $zone->name);
            $home = \App\Models\HouseListing::firstOrNew(['provider_id' => $provider->id, 'home_type' => $kind, 'size' => $size]);
            $home->fill([
                'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'title' => $title,
                'slug' => $home->slug ?: \App\Models\HouseListing::uniqueSlug($title),
                'area' => ['1 RK' => 180, '1 BHK' => 550, '2 BHK' => 1050, '3 BHK' => 1600][$size] ?? null,
                'floor' => $kind === 'Independent house' ? 'Whole building' : '1st floor', 'facing' => 'East',
                'furnishing' => $furnishing, 'tenants' => $tenants,
                'rent' => $rent, 'deposit' => $rent * $months, 'maintenance' => $kind === 'Flat / apartment' ? 1500 : null,
                'available_from' => now()->toDateString(),
                'landmark' => 'Test landmark, '.$zone->name, 'address_line' => 'Test address, '.$zone->name.'. Not a real home.',
                'amenities' => ['24-hour water', 'Borewell', 'Power backup', 'Ceiling fans', 'Geyser / hot water', 'Cross ventilation',
                    'Mosquito mesh on windows', 'Two-wheeler parking', 'CCTV', 'Bus stop', 'Market'],
                'rule_list' => ['No smoking', 'Agreement for 11 months', 'One month notice to leave'],
                'about' => "Test {$size} {$kind} for walk-throughs.\nNot a real home.",
                'cover_image' => $photo($cover), 'photos' => array_map($photo, $photos),
                'contact_phone' => $user->phone,
                'status' => 'approved', 'submitted_at' => now(), 'approved_at' => now(), 'rejection_reason' => null,
                'is_active' => true, 'rented_at' => null, 'expires_at' => now()->addDays(365),
                'pending_changes' => null, 'pending_requested_at' => null, 'pending_rejection' => null,
            ])->save();
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
                Accommodation::whereIn('provider_id', Provider::where('user_id', $user->id)->select('id'))->update(['is_active' => false]);
                \App\Models\EventOrganiser::whereIn('provider_id', Provider::where('user_id', $user->id)->select('id'))->update(['is_active' => false]);
                \App\Models\Hall::whereIn('provider_id', Provider::where('user_id', $user->id)->select('id'))->update(['is_active' => false]);
                \App\Models\HouseListing::whereIn('provider_id', Provider::where('user_id', $user->id)->select('id'))->update(['is_active' => false]);
                $user->forceFill(['status' => 'suspended'])->save();
                $user->tokens()->delete();
            });
        }
        $this->info("Disabled {$users->count()} test account(s): suspended, roles removed, app tokens revoked.");

        return self::SUCCESS;
    }
}
