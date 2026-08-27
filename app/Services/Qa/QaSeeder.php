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
use App\Models\Badge;
use App\Models\Banner;
use App\Models\Booking;
use App\Models\BusinessAccount;
use App\Models\BusinessLocation;
use App\Models\City;
use App\Models\ContentPage;
use App\Models\Country;
use App\Models\Faq;
use App\Models\FieldWorker;
use App\Models\FlashSale;
use App\Models\FlashSaleTarget;
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
use App\Models\ServiceOption;
use App\Models\ServiceOptionGroup;
use App\Models\ServiceSubcategory;
use App\Models\User;
use App\Models\Zone;
use App\Services\BadgeService;
use App\Services\DispatchService;
use App\Services\LoyaltyService;
use App\Services\Plans\SubscriptionService;
use App\Services\ReferralService;
use App\Services\ReviewService;
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
    /**
     * The demo Service catalog, as data rather than as code.
     *
     * Shape: category => [description?, color?, inactive?, subcategories =>
     * [subcategory => [service => [price, discount_price?, price_type?,
     * duration?, description?, new?, inactive?, options?]]]].
     *
     * ── Why these particular categories ───────────────────────────────────
     * They are the home-services set this platform actually launches with:
     * appliance repair, home repair, cleaning, pest control, painting.
     * Personal care (salon/grooming) is deliberately ABSENT — the Service
     * domain in this codebase carries no personal-care concepts (no
     * practitioner gender preference, no at-home slot duration model, no
     * treatment inventory), and seeding a vertical the backend has never
     * modelled would produce demo data that cannot be booked or dispatched.
     *
     * ── The negative cases are part of the data ───────────────────────────
     * Deliberately included, because the customer app has to be provably
     * correct about each of them:
     *   - one INACTIVE category (its services must be invisible everywhere)
     *   - one INACTIVE service inside an active category
     *   - services with NO options at all
     *   - services with no discount, and services with one
     *   - a `quote_on_inspection` service (renders "Starts from", not "From")
     *   - a small set marked `new` (recently created, so the automatic NEW
     *     badge applies) against a majority that are backdated past it
     */
    private const CATALOG = [
        'AC & Appliance Repair' => [
            'description' => 'Air conditioning and home appliance servicing, repair and installation.',
            'color' => '#0EA5E9',
            'subcategories' => [
                'AC Services' => [
                    'AC Inspection' => ['price' => 299, 'duration' => 30, 'description' => 'A technician checks cooling performance, gas pressure, drainage and electricals, then tells you what the unit actually needs.'],
                    'AC General Service' => [
                        'price' => 599, 'discount_price' => 499, 'duration' => 60,
                        'description' => 'Filter and coil cleaning, drain flush, gas pressure check and a performance test.',
                        'options' => [
                            'Number of units' => ['required' => true, 'options' => ['1 AC' => 0, '2 ACs' => 450, '3 ACs' => 850, '4 or more ACs' => 1200]],
                            'Add-ons' => ['multiple' => true, 'options' => ['Anti-bacterial coil treatment' => 249, 'Outdoor unit deep rinse' => 199]],
                        ],
                    ],
                    'AC Deep Cleaning' => [
                        'price' => 999, 'duration' => 90,
                        'description' => 'Jet-pump cleaning of the indoor and outdoor units, including the blower and drain tray.',
                        'options' => [
                            'Unit type' => ['required' => true, 'options' => ['Split AC' => 0, 'Window AC' => -150, 'Cassette AC' => 400]],
                        ],
                    ],
                    'AC Repair' => [
                        'price' => 499, 'price_type' => 'quote_on_inspection', 'duration' => 90,
                        'description' => 'Diagnosis is charged up front; the repair itself is quoted once the fault is identified. Parts are billed separately.',
                        'options' => [
                            'Reported problem' => ['required' => true, 'options' => ['Not cooling' => 0, 'Water leaking' => 0, 'Noisy operation' => 0, 'Not switching on' => 0]],
                        ],
                    ],
                    'AC Installation' => [
                        'price' => 1499, 'duration' => 120, 'new' => true,
                        'description' => 'Mounting, piping, drainage and a full test run for a new or relocated unit.',
                        'options' => [
                            'Unit type' => ['required' => true, 'options' => ['Split AC' => 0, 'Window AC' => -600]],
                            'Extras' => ['multiple' => true, 'options' => ['Additional copper piping (per metre)' => 450, 'Wall core drilling' => 350, 'Stabiliser installation' => 250]],
                        ],
                    ],
                    'AC Uninstallation' => ['price' => 699, 'duration' => 60, 'description' => 'Safe gas recovery, dismount and packing for storage or relocation.'],
                ],
                'Appliance Repair' => [
                    'Washing Machine Repair' => [
                        'price' => 399, 'price_type' => 'quote_on_inspection', 'duration' => 60,
                        'description' => 'Diagnosis of drainage, spin, drum and electronic faults on front- and top-load machines.',
                        'options' => [
                            'Machine type' => ['required' => true, 'options' => ['Top load' => 0, 'Front load' => 150, 'Semi-automatic' => -50]],
                        ],
                    ],
                    'Refrigerator Repair' => ['price' => 449, 'price_type' => 'quote_on_inspection', 'duration' => 60, 'description' => 'Cooling, compressor, thermostat and defrost faults on single- and double-door units.'],
                    'TV Check-up' => ['price' => 299, 'duration' => 45, 'description' => 'Panel, board and connectivity diagnosis, with a written verdict before any repair is agreed.'],
                    'Microwave Repair' => ['price' => 349, 'duration' => 45, 'description' => 'Heating, turntable and control-panel faults on solo, grill and convection models.'],
                    'Water Purifier / RO Service' => [
                        'price' => 549, 'discount_price' => 449, 'duration' => 60, 'new' => true,
                        'description' => 'Filter and membrane inspection, sanitisation and a TDS check before and after.',
                        'options' => [
                            'Service level' => ['required' => true, 'options' => ['Basic service' => 0, 'Service + filter replacement' => 900, 'Service + RO membrane replacement' => 1600]],
                        ],
                    ],
                    'Chimney Service' => ['price' => 799, 'duration' => 75, 'description' => 'Degreasing of filters, blower and duct, plus a suction test.'],
                    'Dishwasher Repair' => ['price' => 449, 'duration' => 60, 'inactive' => true, 'description' => 'Currently unpublished — kept in the demo data as an inactive service.'],
                ],
            ],
        ],

        'Home Repair & Maintenance' => [
            'description' => 'Electricians, plumbers and carpenters for everything that stops working.',
            'color' => '#F59E0B',
            'subcategories' => [
                'Electrical' => [
                    'Electrician Consultation' => ['price' => 199, 'duration' => 30, 'description' => 'A visit to assess the problem and quote the work. Adjusted against the repair if you go ahead.'],
                    'Fan Repair' => ['price' => 249, 'duration' => 45, 'description' => 'Ceiling, wall and exhaust fans — noise, wobble, speed and capacitor faults.'],
                    'Switchboard Repair' => ['price' => 349, 'duration' => 60, 'description' => 'Loose, sparking or dead switchboards made safe and rewired as needed.'],
                    'Socket Replacement' => [
                        'price' => 199, 'duration' => 30,
                        'description' => 'Replacement of damaged or loose sockets with new, correctly-rated fittings.',
                        'options' => [
                            'Number of sockets' => ['required' => true, 'options' => ['1 socket' => 0, '2–3 sockets' => 250, '4–6 sockets' => 550]],
                        ],
                    ],
                    'Wiring Repair' => ['price' => 599, 'price_type' => 'quote_on_inspection', 'duration' => 120, 'description' => 'Fault tracing and rewiring. Quoted after inspection because the run length is not knowable in advance.'],
                    'Light Installation' => [
                        'price' => 299, 'duration' => 45,
                        'description' => 'Mounting and wiring of ceiling lights, panels and decorative fittings.',
                        'options' => [
                            'Fitting type' => ['required' => true, 'options' => ['Ceiling light / panel' => 0, 'Chandelier' => 700, 'Track or profile lighting' => 400]],
                        ],
                    ],
                ],
                'Plumbing' => [
                    'Plumber Consultation' => ['price' => 199, 'duration' => 30, 'description' => 'An assessment visit with a written quote for the work required.'],
                    'Tap Repair' => ['price' => 249, 'duration' => 30, 'description' => 'Dripping, stiff or leaking taps repaired or replaced.'],
                    'Pipe Repair' => ['price' => 399, 'price_type' => 'quote_on_inspection', 'duration' => 60, 'description' => 'Leak tracing and pipe repair. Concealed pipework is quoted after inspection.'],
                    'Drain Blockage Removal' => [
                        'price' => 449, 'discount_price' => 399, 'duration' => 60,
                        'description' => 'Mechanical clearing of blocked sinks, floor traps and soil lines.',
                        'options' => [
                            'Blockage location' => ['required' => true, 'options' => ['Kitchen sink' => 0, 'Bathroom floor trap' => 0, 'Toilet' => 200, 'Main line' => 600]],
                        ],
                    ],
                    'Flush Tank Repair' => ['price' => 349, 'duration' => 45, 'description' => 'Running, weak or leaking flush tanks — valves, floats and seals.'],
                    'Bathroom Plumbing' => ['price' => 899, 'price_type' => 'quote_on_inspection', 'duration' => 180, 'description' => 'Fitting and re-plumbing of bathroom fixtures. Quoted after inspection.'],
                ],
                'Carpentry' => [
                    'Carpenter Consultation' => ['price' => 199, 'duration' => 30, 'description' => 'An assessment visit with a written quote for the work required.'],
                    'Furniture Repair' => ['price' => 449, 'price_type' => 'quote_on_inspection', 'duration' => 90, 'description' => 'Joints, hinges, drawers and surfaces on wooden and engineered-board furniture.'],
                    'Door Repair' => ['price' => 399, 'duration' => 60, 'description' => 'Sticking, sagging or damaged doors — hinges, locks, alignment and edges.'],
                    'Cupboard Repair' => ['price' => 449, 'duration' => 75, 'description' => 'Shutters, channels, hinges and shelves in wardrobes and kitchen units.'],
                    'Furniture Assembly' => [
                        'price' => 549, 'duration' => 90, 'new' => true,
                        'description' => 'Flat-pack assembly with wall anchoring where the design requires it.',
                        'options' => [
                            'Item size' => ['required' => true, 'options' => ['Small (chair, side table)' => 0, 'Medium (desk, dresser)' => 300, 'Large (wardrobe, bunk bed)' => 900]],
                        ],
                    ],
                ],
            ],
        ],

        'Cleaning' => [
            'description' => 'Trained cleaners, professional-grade equipment, no shortcuts.',
            'color' => '#10B981',
            'subcategories' => [
                'Home Cleaning' => [
                    'Full Home Cleaning' => [
                        'price' => 2499, 'discount_price' => 1999, 'duration' => 300,
                        'description' => 'Every room cleaned top to bottom: floors, bathrooms, kitchen, fittings and reachable glass.',
                        'options' => [
                            'Home size' => ['required' => true, 'options' => ['1 BHK' => 0, '2 BHK' => 900, '3 BHK' => 1800, '4 BHK or villa' => 3200]],
                            'Add-ons' => ['multiple' => true, 'options' => ['Balcony deep clean' => 400, 'Interior window glass' => 500, 'Refrigerator interior' => 350]],
                        ],
                    ],
                    'Bathroom Cleaning' => [
                        'price' => 699, 'duration' => 90,
                        'description' => 'Descaling and sanitisation of tiles, fittings, glass and the WC.',
                        'options' => [
                            'Number of bathrooms' => ['required' => true, 'options' => ['1 bathroom' => 0, '2 bathrooms' => 550, '3 bathrooms' => 1000]],
                        ],
                    ],
                    'Kitchen Cleaning' => ['price' => 999, 'duration' => 150, 'description' => 'Degreasing of platforms, tiles, cabinet exteriors, sink and chimney surround.'],
                    'Floor Cleaning' => ['price' => 799, 'duration' => 120, 'description' => 'Machine scrubbing and finishing for tile, marble and vitrified floors.'],
                ],
                'Upholstery Cleaning' => [
                    'Sofa Cleaning' => [
                        'price' => 599, 'duration' => 90,
                        'description' => 'Shampoo and vacuum extraction, dried before the technician leaves.',
                        'options' => [
                            'Number of seats' => ['required' => true, 'options' => ['3 seats' => 0, '5 seats' => 350, '7 seats' => 700]],
                            'Fabric' => ['options' => ['Fabric' => 0, 'Leather / rexine' => 250]],
                        ],
                    ],
                    'Mattress Cleaning' => [
                        'price' => 499, 'duration' => 60,
                        'description' => 'Dry vacuum, stain treatment and anti-dust-mite finish.',
                        'options' => [
                            'Mattress size' => ['required' => true, 'options' => ['Single' => 0, 'Double' => 200, 'King' => 350]],
                        ],
                    ],
                ],
            ],
        ],

        'Pest Control' => [
            'description' => 'Licensed treatments with a documented follow-up schedule.',
            'color' => '#EF4444',
            'subcategories' => [
                'Pest Treatments' => [
                    'Cockroach Control' => [
                        'price' => 899, 'discount_price' => 749, 'duration' => 90,
                        'description' => 'Gel baiting and residual spray in kitchens, bathrooms and service areas.',
                        'options' => [
                            'Home size' => ['required' => true, 'options' => ['1 BHK' => 0, '2 BHK' => 300, '3 BHK' => 600]],
                        ],
                    ],
                    'Ant Control' => ['price' => 699, 'duration' => 60, 'description' => 'Trail treatment and nest targeting for black and red ants.'],
                    'Termite Treatment' => [
                        'price' => 3499, 'price_type' => 'quote_on_inspection', 'duration' => 240,
                        'description' => 'Drill-and-inject treatment along the affected structure. Quoted after a site inspection because the treated area drives the price.',
                    ],
                    'Mosquito Treatment' => ['price' => 999, 'duration' => 75, 'description' => 'Indoor and perimeter fogging plus breeding-site treatment.'],
                ],
            ],
        ],

        'Painting & Home Improvement' => [
            'description' => 'Painters and finishers, with surface preparation included.',
            'color' => '#8B5CF6',
            'subcategories' => [
                'Painting' => [
                    'Room Painting' => [
                        'price' => 3999, 'price_type' => 'quote_on_inspection', 'duration' => 480,
                        'description' => 'Masking, putty, primer and two coats. Quoted per room after measurement.',
                        'options' => [
                            'Finish' => ['required' => true, 'options' => ['Distemper' => 0, 'Emulsion' => 1200, 'Premium emulsion' => 2600]],
                            'Extras' => ['multiple' => true, 'options' => ['Ceiling included' => 900, 'Full putty re-do' => 1800]],
                        ],
                    ],
                    'Full Home Painting' => [
                        'price' => 18999, 'price_type' => 'quote_on_inspection', 'duration' => 2880,
                        'description' => 'Whole-home repaint with furniture covering, surface preparation and site cleanup. Always quoted after measurement.',
                        'options' => [
                            'Home size' => ['required' => true, 'options' => ['1 BHK' => 0, '2 BHK' => 7000, '3 BHK' => 15000]],
                        ],
                    ],
                ],
                'Repairs & Finishing' => [
                    'Wall Repair' => ['price' => 1299, 'price_type' => 'quote_on_inspection', 'duration' => 180, 'description' => 'Crack filling, damp patch treatment and re-finishing to match the surrounding wall.'],
                    'Furniture Polish' => ['price' => 1899, 'price_type' => 'quote_on_inspection', 'duration' => 300, 'description' => 'Sanding, staining and melamine or PU finishing on wooden furniture.'],
                ],
            ],
        ],

        // Deliberately unpublished. Nothing inside it may appear anywhere in
        // the customer app, and its URL must 404 — the negative case the
        // catalog's visibility rule exists for.
        'Seasonal Services' => [
            'description' => 'An unpublished category, kept in the demo data as a negative case.',
            'color' => '#64748B',
            'inactive' => true,
            'subcategories' => [
                'Monsoon Prep' => [
                    'Roof Waterproofing' => ['price' => 4999, 'price_type' => 'quote_on_inspection', 'duration' => 480, 'description' => 'Should never be visible in the customer app — its category is inactive.'],
                ],
            ],
        ],
    ];

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
        [$bookingStats, $subscriptionStats, $reviewCount] = DB::transaction(function () use ($n) {
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

            // Catalog decoration, seeded last because each depends on rows
            // the stages above created: badges and flash sales hang off
            // services, and reviews can only exist on completed bookings.
            $this->seedBadges($catalog, $geo);
            $this->seedFlashSales($catalog);
            $reviewCount = $this->seedReviews();

            return [$bookingStats, $subscriptionStats, $reviewCount];
        });

        $this->manifest->save();

        return [
            'manifest_counts' => $this->manifest->counts(),
            'total_records' => $this->manifest->totalRecords(),
            'booking_status_distribution' => $bookingStats,
            'subscription_status_distribution' => $subscriptionStats,
            'reviews_created' => $reviewCount,
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

    /**
     * A self-contained placeholder image for demo rows, as an inline SVG
     * data URI.
     *
     * `ServiceCategory::image_url` (and Service/Banner's identical accessor)
     * already treat a `data:` value as a ready-to-use URL, so this needs no
     * files on disk, no `storage:link`, and no binary assets committed to the
     * repository — and it can never render as a broken image the way the
     * previous `categories/qa-placeholder.png` did on an environment where
     * that file was never created.
     *
     * Plain geometry and an initial, deliberately: this is demo scaffolding,
     * not artwork, and it must not be mistaken for a brand asset.
     */
    private function demoImage(string $label, string $color, int $width = 600, int $height = 450): string
    {
        $initial = htmlspecialchars(mb_strtoupper(mb_substr(preg_replace('/^\[QA\]\s*/', '', $label), 0, 1)), ENT_QUOTES);
        $fontSize = (int) round(min($width, $height) * 0.42);

        $svg = <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$width} {$height}" width="{$width}" height="{$height}">
          <rect width="{$width}" height="{$height}" fill="{$color}"/>
          <circle cx="{$width}" cy="0" r="{$height}" fill="#ffffff" opacity="0.12"/>
          <circle cx="0" cy="{$height}" r="{$height}" fill="#000000" opacity="0.10"/>
          <text x="50%" y="50%" dy="0.35em" text-anchor="middle"
                font-family="system-ui, sans-serif" font-size="{$fontSize}" font-weight="700"
                fill="#ffffff" opacity="0.85">{$initial}</text>
        </svg>
        SVG;

        return 'data:image/svg+xml;base64,'.base64_encode(preg_replace('/\s+/', ' ', $svg));
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

    /**
     * The Service-vertical catalog: categories -> subcategories -> services
     * -> priced option groups.
     *
     * Expanded for the customer web app's discovery phase, which needs a
     * catalog with real SHAPE to exercise honestly — a taxonomy deep enough
     * to navigate, services that differ from one another, options with real
     * price deltas, and, critically, the negative cases: an inactive
     * category, an inactive service, a service with no options, a service
     * with no discount, and services old enough that the NEW badge has
     * stopped applying to them.
     *
     * Every row is still prefixed `[QA]` and still tracked in the manifest,
     * so `qa:clean` removes exactly this and nothing else.
     *
     * ── created_at is set deliberately ────────────────────────────────────
     * The NEW badge is an `automatic` badge whose rule is `recently_created`
     * with an admin-editable `within_days` (14 by default). If every seeded
     * service were created "now", the entire catalog would carry a NEW badge
     * — which would make the badge meaningless and would prove nothing about
     * whether the rule works. So most services are backdated well past the
     * window and a handful are left recent, which is what makes the badge's
     * presence and absence both observable in the demo data.
     */
    private function seedCatalog(): array
    {
        $categories = [];
        $services = [];
        $order = 1;

        foreach (self::CATALOG as $categoryName => $definition) {
            $category = ServiceCategory::create([
                'module' => 'service',
                'name' => "[QA] {$categoryName}",
                'slug' => Str::slug('qa-'.$categoryName.'-'.Str::random(6)),
                'description' => $definition['description'] ?? null,
                'color' => $definition['color'] ?? null,
                'image' => $this->demoImage($categoryName, $definition['color'] ?? '#0F172A', 240, 240),
                'sort_order' => $order++,
                // One category is deliberately unpublished, so the catalog
                // has a real negative case: none of its services may appear
                // anywhere in the customer app, and its URL must 404.
                'is_active' => ! ($definition['inactive'] ?? false),
            ]);
            $this->track('service_categories', $category);
            $categories[] = $category;

            $subOrder = 1;
            foreach ($definition['subcategories'] as $subName => $serviceDefs) {
                $subcategory = ServiceSubcategory::create([
                    'category_id' => $category->id,
                    'name' => "[QA] {$subName}",
                    'slug' => Str::slug('qa-'.$subName.'-'.Str::random(6)),
                    'image' => $this->demoImage($subName, $definition['color'] ?? '#0F172A', 240, 240),
                    'sort_order' => $subOrder++,
                    'is_active' => true,
                ]);
                $this->track('service_subcategories', $subcategory);

                $serviceOrder = 1;
                $serviceIndex = 0;
                foreach ($serviceDefs as $serviceName => $spec) {
                    // Every fourth service is seeded with NO cover image, so
                    // the card's own no-image fallback is exercised in the
                    // demo data rather than only in a unit test. A real
                    // catalog always has rows nobody got round to
                    // photographing.
                    $hasImage = $serviceIndex++ % 4 !== 3;

                    $service = Service::create([
                        'cover_image' => $hasImage ? $this->demoImage($serviceName, $definition['color'] ?? '#0F172A') : null,
                        'category_id' => $category->id,
                        'subcategory_id' => $subcategory->id,
                        'name' => "[QA] {$serviceName}",
                        'slug' => Str::slug('qa-'.$serviceName.'-'.Str::random(6)),
                        'description' => $spec['description'] ?? null,
                        'base_price' => $spec['price'],
                        'discount_price' => $spec['discount_price'] ?? null,
                        'price_type' => $spec['price_type'] ?? 'fixed',
                        'duration_estimate_mins' => $spec['duration'] ?? 60,
                        'is_active' => ! ($spec['inactive'] ?? false),
                        'location_required' => true,
                        'age_restriction' => false,
                        'sort_order' => $serviceOrder++,
                    ]);

                    // Backdate unless this one is meant to read as new. Set
                    // after insert because `created_at` is not fillable;
                    // Eloquent only auto-assigns it on insert, so saving an
                    // explicit value here sticks.
                    $service->created_at = ($spec['new'] ?? false)
                        ? now()->subDays(random_int(1, 6))
                        : now()->subDays(random_int(90, 400));
                    $service->save();

                    $this->track('services', $service);
                    $services[] = $service;

                    $this->seedServiceOptions($service, $spec['options'] ?? []);
                }
            }
        }

        return compact('categories', 'services');
    }

    /**
     * Priced option groups for one service — the real ServiceOptionGroup /
     * ServiceOption rows the admin panel's own options modal writes.
     *
     * A deliberate mix of required single-choice groups ("how many ACs"),
     * optional multi-choice groups ("add-ons"), and services with no options
     * at all, because the customer detail screen renders each of those three
     * cases differently and all three need to be visible in demo data.
     */
    private function seedServiceOptions(Service $service, array $groups): void
    {
        $groupOrder = 1;

        foreach ($groups as $groupName => $group) {
            $optionGroup = ServiceOptionGroup::create([
                'service_id' => $service->id,
                'name' => $groupName,
                'is_required' => $group['required'] ?? false,
                'allow_multiple' => $group['multiple'] ?? false,
                'sort_order' => $groupOrder++,
            ]);
            $this->track('service_option_groups', $optionGroup);

            $optionOrder = 1;
            foreach ($group['options'] as $optionName => $delta) {
                $option = ServiceOption::create([
                    'service_option_group_id' => $optionGroup->id,
                    'name' => $optionName,
                    'price_delta' => $delta,
                    'sort_order' => $optionOrder++,
                    'is_active' => true,
                ]);
                $this->track('service_options', $option);
            }
        }
    }

    // ============================= Plans =============================

    private function seedPlans(): array
    {
        $plans = [];

        // All QA plans are price=0 (free-tier activation path) deliberately
        // — a paid plan routes through RazorpayPaymentDriver, which needs real
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

        /*
         | Bookings are spread UNEVENLY across the catalog on purpose.
         |
         | The customer app's "Most booked" rail ranks services by a real
         | count over this table. If every service received roughly the same
         | number of bookings, that ranking would be noise and the rail would
         | prove nothing about whether the ranking works. So a "head" of
         | popular services takes most of the volume and the long tail takes
         | the rest — which is what a real marketplace's distribution looks
         | like, and which makes the rail's order visibly meaningful.
         |
         | Only ACTIVE services are booked. An unpublished service having
         | booking history is a legitimate real-world state, but seeding it
         | would put rows behind a card that must never render, which makes
         | the demo data harder to reason about, not more realistic.
         */
        $activeCategoryIds = collect($catalog['categories'])->where('is_active', true)->pluck('id')->all();
        $bookableServices = array_values(array_filter(
            $catalog['services'],
            fn (Service $s) => $s->is_active && in_array($s->category_id, $activeCategoryIds, true),
        ));

        $headSize = max(1, (int) round(count($bookableServices) * 0.25));

        foreach ($sequence as $targetStatus) {
            $customer = $customers[array_rand($customers)];
            $ctx = $addressesByCustomer[$customer->id];
            // 70% of bookings land on the head, 30% on the tail.
            $service = random_int(1, 100) <= 70
                ? $bookableServices[random_int(0, $headSize - 1)]
                : $bookableServices[random_int(0, count($bookableServices) - 1)];

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

    /**
     * Both customer-facing banner slots, plus every negative case the
     * homepage's targeting has to get right.
     *
     * `top` is the hero carousel and `mid` is the mid-page promotional
     * strip — the two sellable slots Banner::PLACEMENTS already defines.
     * They are seeded independently and never share a row, because the two
     * slots on the homepage must be provably independent of each other.
     *
     * The rows that must NOT appear are as important as the ones that must:
     * an inactive banner, one whose window has closed, and one scheduled to
     * open in the future all exist here so that a change to
     * Banner::scopeCurrentlyLive() cannot silently start showing them.
     * Likewise a zone-targeted and a franchise-targeted banner, which must
     * appear only for a customer whose session zone resolves into that
     * scope, and a Marketplace-module banner, which must never appear on the
     * Service-vertical homepage at all.
     */
    private function seedBanners(array $geo): void
    {
        $franchise = $geo['franchises'][0];
        $zone = collect($geo['zones'])->firstWhere('franchise_id', $franchise->id) ?? $geo['zones'][0];

        $banners = [
            // --- Hero slot (top) ---
            ['title' => '[QA] Hero — Platform-wide', 'placement' => 'top', 'sort_order' => 1, 'link' => '/services', 'color' => '#1D4ED8'],
            ['title' => '[QA] Hero — Second slide', 'placement' => 'top', 'sort_order' => 2, 'link' => '/categories', 'color' => '#047857'],
            ['title' => '[QA] Hero — Zone targeted', 'placement' => 'top', 'sort_order' => 3, 'zone_id' => $zone->id, 'link' => '/offers', 'color' => '#B45309'],

            // --- Mid slot ---
            ['title' => '[QA] Mid — Platform-wide', 'placement' => 'mid', 'sort_order' => 1, 'link' => '/offers', 'color' => '#7C3AED'],
            ['title' => '[QA] Mid — Franchise targeted', 'placement' => 'mid', 'sort_order' => 2, 'franchise_id' => $franchise->id, 'color' => '#BE185D'],
            // No image on this one on purpose: the carousel must render it
            // as a readable panel rather than a broken <img>.
            ['title' => '[QA] Mid — No image asset', 'placement' => 'mid', 'sort_order' => 3, 'image' => ''],

            // --- Must never render ---
            ['title' => '[QA] Hidden — Switched off', 'placement' => 'top', 'sort_order' => 9, 'is_active' => false],
            ['title' => '[QA] Hidden — Window closed', 'placement' => 'top', 'sort_order' => 9, 'starts_at' => now()->subMonths(2), 'expires_at' => now()->subDay()],
            ['title' => '[QA] Hidden — Not started yet', 'placement' => 'mid', 'sort_order' => 9, 'starts_at' => now()->addWeek(), 'expires_at' => now()->addMonth()],
            ['title' => '[QA] Hidden — Marketplace module', 'placement' => 'top', 'sort_order' => 9, 'module' => 'commerce'],
        ];

        foreach ($banners as $attributes) {
            // `color` is a seeder-local hint for the placeholder artwork, not
            // a banners column — pulled out before the row is written.
            $color = $attributes['color'] ?? '#334155';
            unset($attributes['color']);

            $banner = Banner::create(array_merge([
                'image' => $this->demoImage($attributes['title'], $color, 1200, 420),
                'is_active' => true,
            ], $attributes));
            $this->track('banners', $banner);
        }
    }

    // ============================= Catalog badges =============================

    /**
     * Manual badge assignments on real services, through the real
     * BadgeService::assign() — never a raw badge_assignments insert, so the
     * duplicate guard and the default-duration handling are exercised
     * exactly as an admin assigning a badge would exercise them.
     *
     * Only `manual` badges are assigned here. NEW is an `automatic` badge
     * computed live from each service's own `created_at` (BadgeService
     * refuses to assign it at all), which is precisely why seedCatalog()
     * backdates most services and leaves a few recent — that is what makes
     * NEW appear on some cards and not others.
     *
     * One assignment is created already EXPIRED and one is created
     * zone-scoped, so the demo data proves both filters: an expired badge
     * must never render, and a zone-scoped badge must render only for a
     * customer resolved into that zone.
     */
    private function seedBadges(array $catalog, array $geo): void
    {
        $badgeService = app(BadgeService::class);
        $services = collect($catalog['services'])->filter(fn (Service $s) => $s->is_active)->values();

        if ($services->isEmpty()) {
            return;
        }

        $zone = $geo['zones'][0];

        $plan = [
            ['key' => 'popular', 'count' => 4, 'scope' => 'global', 'scope_id' => null, 'expires' => null],
            ['key' => 'featured', 'count' => 3, 'scope' => 'global', 'scope_id' => null, 'expires' => null],
            ['key' => 'best_value', 'count' => 2, 'scope' => 'global', 'scope_id' => null, 'expires' => null],
            // Zone-scoped: must be invisible to a customer in any other zone.
            ['key' => 'trending', 'count' => 2, 'scope' => 'zone', 'scope_id' => $zone->id, 'expires' => null],
            // Already over: must be invisible to everyone.
            ['key' => 'limited', 'count' => 2, 'scope' => 'global', 'scope_id' => null, 'expires' => 'past'],
        ];

        $cursor = 0;

        foreach ($plan as $step) {
            $badge = Badge::where('key', $step['key'])->first();

            if (! $badge) {
                continue; // Badge definitions are seeded by migration; skip rather than invent one.
            }

            for ($i = 0; $i < $step['count'] && $cursor < $services->count(); $i++) {
                $service = $services[$cursor++];

                try {
                    $assignment = $badgeService->assign(
                        $badge,
                        $service,
                        $step['scope'],
                        $step['scope_id'],
                        $step['expires'] === 'past' ? now()->addDay() : null,
                    );
                } catch (\RuntimeException) {
                    continue; // Already carries this badge at this scope — the real guard, not an error here.
                }

                // Backdate an "expired" assignment past its own window.
                // assign() will not accept an expires_at in the past (it
                // would be creating something already dead), so the row is
                // created live and then aged — which is exactly what happens
                // to a real assignment as time passes.
                if ($step['expires'] === 'past') {
                    $assignment->forceFill([
                        'starts_at' => now()->subMonth(),
                        'expires_at' => now()->subDay(),
                    ])->save();
                }

                $this->track('badge_assignments', $assignment);
            }
        }
    }

    // ============================= Flash sales (offers) =============================

    /**
     * The Offers screen's source data: one genuinely live sale, one that has
     * finished, and one still in draft.
     *
     * Only the live one may ever produce a discounted price or appear under
     * an "Offers" heading; the other two exist so that a regression in
     * FlashSale::isCurrentlyActive() shows up as demo data behaving visibly
     * wrong rather than as silence.
     *
     * Created directly rather than through FlashSaleService because that
     * service's own API is a state machine for an admin driving a sale
     * through its lifecycle (draft -> scheduled -> live), and what is needed
     * here is three sales already sitting in three different states.
     * `addTarget()` — which carries the real "no overlapping sale for this
     * service at this scope" guard — is still used for every target.
     */
    private function seedFlashSales(array $catalog): void
    {
        $services = collect($catalog['services'])->filter(fn (Service $s) => $s->is_active)->values();

        if ($services->count() < 6) {
            return;
        }

        $flashBadge = Badge::where('key', 'flash_sale')->first();

        $definitions = [
            [
                'name' => '[QA] Live Weekend Sale', 'customer_title' => 'Weekend sale — 20% off',
                'status' => 'live', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(3),
                'discount_type' => 'percent', 'discount_value' => 20, 'max_discount' => 500,
                'services' => [0, 1, 2],
            ],
            [
                'name' => '[QA] Finished Sale', 'customer_title' => 'Last month\'s sale',
                'status' => 'completed', 'starts_at' => now()->subMonth(), 'ends_at' => now()->subWeeks(3),
                'discount_type' => 'percent', 'discount_value' => 30, 'max_discount' => null,
                'services' => [3],
            ],
            [
                'name' => '[QA] Draft Sale', 'customer_title' => 'Not published yet',
                'status' => 'draft', 'starts_at' => null, 'ends_at' => null,
                'discount_type' => 'flat', 'discount_value' => 150, 'max_discount' => null,
                'services' => [4],
            ],
        ];

        foreach ($definitions as $definition) {
            $sale = FlashSale::create([
                'name' => $definition['name'],
                'customer_title' => $definition['customer_title'],
                'type' => 'weekend_sale',
                'status' => $definition['status'],
                'starts_at' => $definition['starts_at'],
                'ends_at' => $definition['ends_at'],
                'scope_type' => 'global',
                'discount_type' => $definition['discount_type'],
                'discount_value' => $definition['discount_value'],
                'max_discount' => $definition['max_discount'],
                'min_final_price' => 0,
                'badge_id' => $flashBadge?->id,
            ]);
            $this->track('flash_sales', $sale);

            foreach ($definition['services'] as $index) {
                $target = FlashSaleTarget::create([
                    'flash_sale_id' => $sale->id,
                    'service_id' => $services[$index]->id,
                ]);
                $this->track('flash_sale_targets', $target);
            }
        }
    }

    // ============================= Reviews =============================

    /**
     * Star ratings on completed bookings, through the real
     * ReviewService::submit() — which enforces booking ownership, the
     * completed-status rule, the 1–5 range and the one-review-per-booking
     * constraint. Nothing is inserted into `reviews` directly.
     *
     * These are what give the customer catalog its ratings: `reviews` has no
     * `service_id`, so a service's rating is derived by joining reviews to
     * their bookings (see App\Services\Customer\ServiceRatingSummary). No
     * review means no rating on the card, which is why only a proportion of
     * completed bookings are reviewed here — a catalog where every service
     * is rated would never exercise the unrated path.
     *
     * Comments are left on some and omitted on others, because the detail
     * screen lists only reviews that carry written feedback while the
     * average counts them all.
     */
    private function seedReviews(): int
    {
        $reviewService = app(ReviewService::class);

        $comments = [
            'Turned up on time and finished without any fuss.',
            'Good work overall. Took a little longer than the estimate.',
            'Technician explained what was wrong before starting. No surprises on the bill.',
            'Solid job, cleaned up afterwards.',
            'Fixed it properly the first time.',
            'Polite and quick. Would book again.',
        ];

        // Only bookings THIS run created — never whatever else is already in
        // the table, which on a shared dev database could be real data.
        $bookingIds = $this->manifest->ids('bookings');

        $count = 0;

        foreach (Booking::whereIn('id', $bookingIds)->where('status', 'completed')->whereNotNull('provider_id')->get() as $index => $booking) {
            // Roughly two in three completed bookings get reviewed, so both
            // the rated and the unrated card paths appear in the demo data.
            if ($index % 3 === 2) {
                continue;
            }

            try {
                $review = $reviewService->submit(
                    $booking,
                    $booking->customer,
                    // Weighted towards the top, matching what a functioning
                    // marketplace's real distribution looks like — but with
                    // genuine 3s and 4s present, not a wall of 5s.
                    [5, 5, 4, 5, 3, 4, 5, 4][$index % 8],
                    $index % 2 === 0 ? $comments[$index % count($comments)] : null,
                );
            } catch (\RuntimeException|\InvalidArgumentException) {
                continue; // Already reviewed, or not eligible — the real guard, not an error here.
            }

            $this->track('reviews', $review);
            $count++;
        }

        return $count;
    }

    private function seedCms(): void
    {
        $page = ContentPage::create(['slug' => 'qa-test-page-'.Str::random(6), 'title' => '[QA] Test Page', 'content' => 'QA test content.']);
        $this->track('content_pages', $page);

        $faq = Faq::create(['category' => 'QA', 'question' => '[QA] Sample question?', 'answer' => 'Sample answer.', 'sort_order' => 1, 'is_active' => true]);
        $this->track('faqs', $faq);
    }
}
