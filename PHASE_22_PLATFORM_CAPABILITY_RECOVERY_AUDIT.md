# Phase 22 — Platform Capability & Glover Recovery Audit

**Mission type: READ-ONLY architecture/capability audit. Nothing was implemented, migrated, deployed, or pushed.** Every claim below was checked directly against source in this repository or the reference codebases named in §4 — not inferred from filenames, not carried over from memory. Where a prior document (`GLOVER_6AMMART_PARITY_AUDIT.md`, `PROJECT_CURRENT_STATE.md`, `FINAL_ADMIN_CAPABILITY_MATRIX.md`, `KNOWN_RISKS_AND_DECISIONS.md`) already established a fact, this document cites it rather than re-deriving it; where this audit found something none of those documents covers (the module-control-plane fragmentation in §3, the non-polymorphic `Booking.service_id` finding in §7, the incomplete car-rental addon in §4), that is flagged as new.

**Baseline verified this session:** HEAD `8cd0618`, working tree clean, branch `main`. Full suite: **778/778 passing, 1,799 assertions, 0 failures** (`php artisan test`, re-run at the start of this audit). Production unchanged at `ba0635a`. Nothing in this document required a code change to produce, so this baseline is also the end-of-session baseline.

**Addendum (Phase 22.1, same continued mission):** the §3/§6/§11/§16 findings below (no Country→City→Zone→Franchise module-activation cascade; `franchise_modules` unread by any code; no Modules admin screen) are now **RESOLVED** — see `PHASE_22_1_MODULE_ACTIVATION_FOUNDATION.md`. This audit document's own findings are left exactly as originally written below (an audit is a point-in-time record, not something to rewrite after the fact); read them together with that follow-up document for current state.

---

## 1. Executive Summary

1CallFix today is a **single-vertical (Service), franchise-scoped home-services platform** with a genuinely mature core: booking FSM, dispatch, wallet/commission/payout, RBAC, KYC, compensation, campaigns, chat, notifications, and a just-completed (Phase 21) admin design system across 35 screens. This is real, tested, and already ahead of both reference products in several dimensions (franchise ownership model, granular compensation engine, KYC video/deadline lifecycle) — see `GLOVER_6AMMART_PARITY_AUDIT.md §1` for the full cross-comparison.

What it is **not** — and this is the headline finding of this audit — is a multi-vertical marketplace with a generalized module-activation control plane. Three separate, non-overlapping "module" mechanisms exist in the codebase today (a `service_categories.module` **tag**, a `franchise_modules` boolean **toggle with zero consumers**, and an RBAC/Settings **scope key** literally named `module`) and none of them implement, or were ever wired to implement, the `Global → Country → City → Zone → Franchise → Branch → Category → Subcategory → Service/Product` cascade this mission's brief describes. See §3.

The Glover/6amMart reference material (full source for Glover 1.8.5, full admin-panel source for 6amMart 4.0.1, a diff-only car-rental addon, and — most valuable — a real prior 1CallFix production database on Glover/6amMart lineage) is **architecturally a flat multi-vendor marketplace**, not a franchise-hierarchy platform: it has no franchise/branch concept at all. Its module control is a single boolean (`vendor_types.is_active`) crossed with a per-zone pivot (`delivery_zone_vendor_type`) — one level of geography, not seven. Recovering Glover's *module inventory* (which verticals exist, what each one's order/vendor/pricing shape looks like) is valuable; recovering its *control-plane architecture* is not, because 1CallFix's own franchise/zone/RBAC scoping is already a materially richer hierarchy than anything Glover ever built (§9, §10).

Of the 9 requested modules, only **Services** is classified A (fully implemented). **Parcel** is B (real polymorphic dispatch foundation exists, no operational module). Every other module — E-Commerce, Food, Grocery, Pharmacy, Taxi, Hotel, and all four Rental sub-types — is **D** (Glover/6amMart reference only, zero 1CallFix implementation). None is classified E (superseded) — nothing in the current architecture makes any of these obsolete; they are simply not built yet.

The central engines (Wallet, Commission, RBAC, Notification, Loyalty, Referral, TimezoneResolver) are booking-generic and would reuse cleanly across future verticals. The one real structural obstacle is `bookings.service_id`: a hard, non-nullable, non-polymorphic foreign key to the Service catalog. `Booking` **is** the Service vertical's order table today, not a generic Order abstraction — this is the one piece of "central engine compatibility" that genuinely needs a decision before a second vertical can share the same order pipeline (§7).

---

## 2. Current 1CallFix Architecture

This section is a condensed, file-verified index. Full narrative detail (with test counts and commit hashes) already exists in `PROJECT_CURRENT_STATE.md` and `FINAL_ADMIN_CAPABILITY_MATRIX.md` — this section exists so the rest of this audit can cite exact files without re-deriving them.

### CORE
| Concern | Implementation | Evidence |
|---|---|---|
| Module/capability tagging | `App\Support\Modules::ALL` (9-slug PHP constant: service, parcel, car_rental, food, grocery, pharmacy, commerce, taxi, bookings) + `App\Models\Module` (a seeded-from-that-constant DB mirror, used for RBAC/Settings scope attachment, not activation) | `app/Support/Modules.php`, `app/Models/Module.php` |
| Countries / Cities | `Country`/`City` — flat `is_active` boolean each, `Country hasMany City`, `City hasMany Franchise` | `app/Models/Country.php`, `app/Models/City.php` |
| Zones | `Zone` — `belongsTo(Franchise)`, `is_active`, `boundary_polygon`, `default_dispatch_radius_km` | `app/Models/Zone.php` |
| Franchises | `Franchise` — single-tier operating unit (`belongsTo(Country)`, `belongsTo(City)`, `hasMany(Zone)`); **no separate "Branch" model exists** — Franchise is the smallest administrable business unit, Zone the smallest geographic one | `app/Models/Franchise.php` |
| Operators/Roles/Permissions | `User`, `Role`, `Permission`, `RoleAssignment` (scope_type ∈ global/country/city/zone/module/franchise, additive across multiple assignments) | `app/Services/AuthorizationService.php`, `app/Models/RoleAssignment.php` |
| Settings / feature flags | `Setting` — scope-cascaded (`zone > franchise > module > city > country`), most-specific-wins | `app/Models/Setting.php` |
| Activation/status mechanisms | Per-entity `is_active` booleans (Country/City/Zone/ServiceCategory/ServiceSubcategory/Service/Franchise `status` enum) — each entity owns its own flag; no cascading inheritance between levels (verified — see §3) | multiple models |

### CATALOG
`ServiceCategory` (tagged with a `module` slug from `Modules::ALL`) → `ServiceSubcategory` → `Service` (`base_price`, `discount_price`, `price_type`, soft-deletable). `FranchiseServicePricing` allows per-franchise price overrides. No `Product`/`Brand`/`Variant`/vendor-catalog concept exists — there is exactly one catalog shape, built for a technician-delivered service, not a physical product. `App\Services\Catalog\CatalogImporter` (+ 3 subclasses) is a real, tested, `external_id`-idempotent import engine for Categories/Subcategories/Services only (Phase 14; see `KNOWN_RISKS_AND_DECISIONS.md` item 20 for why a Vendor/Menu/Product importer was deliberately not built — no target schema exists).

### ORDER ENGINE
`Booking` is the **only** order/booking table in the schema. FSM: `pending → searching_provider → assigned → provider_en_route → in_progress → on_hold → completed/cancelled/disputed`, enforced by dedicated Action classes (`AcceptBookingAction`, `StartBookingAction`, `CompleteBookingAction`, `AdminCancelBookingAction`, `AdminReassignBookingAction`, `AssignBookingToWorkerAction`, `CreateBookingAction`), each `DB::transaction()` + row-locked, each writing `booking_status_history`. **`bookings.service_id` is a hard, non-nullable, non-polymorphic FK to `services`** (`app/Models/Booking.php` — verified directly, not assumed) — see §7 for why this is the single most consequential finding for multi-vertical readiness. Numbering via `BookingSequence`/`CodeGeneratorService`/`OrderCodeService`.

### DISPATCH
`ServiceMatchingJob` (self-requeuing queued job) + `DispatchService` (`findCandidates()` — same-zone, online, KYC-approved, category-skill-matched, distance-ranked via `RankingEngine`/`RankingConfigResolver`). `dispatch_attempts.notifiable_type`/`notifiable_id` is **polymorphic on the worker side** (Phase B0.3 — Provider vs. FieldWorker); `dispatch_attempts.booking_id` is NOT (see §6 Central Engine Compatibility's Dispatch Engine row for the Phase 22.2 correction to this document's own original overstatement here). Queue driver is `database` (`QUEUE_CONNECTION=database` in `.env.example`); **Redis is configured as available but has zero call sites anywhere in `app/`** (grepped, zero matches) — confirms `PROJECT_CURRENT_STATE.md §3`'s "Redis installed but unused" directly rather than repeating it uninspected. `DispatchService::findCandidates()` itself takes a `Booking` and directly reads `$booking->service->category_id` — coupled to the Service catalog shape at the call-site level, separate from the schema-level coupling in §Order Engine above.

### FINANCE
`WalletService` (sole writer of `wallets.balance`, row-locked, never negative), `CommissionService::applyForBooking()` (sole split authority, DB-unique-constrained idempotent on `commissions.booking_id`), `PayoutService` (`payment_accounts`-verified, row-level franchise-scoped since Phase 21 item TECH-1), `RazorpayService`/`PaymentGateway` contract (Razorpay-only bound; second provider is item 9 in the risk register), `TipService`/`CompensationService` (tips/overtime/night/peak auto, rain/waiting admin-triggered — all rate `Setting`s default to `0`), `PaymentWebhookLog` (receipt + reprocess), `ReconciliationService` (5 detect-only checks). All of these key off `Booking`, not off any vertical-specific concept — see §7.

### CUSTOMER
`User` (customer role) + `Address` + `Booking` (customer's orders) + `Subscription`/`Plan`/`PlanEntitlement`/`EntitlementBalance`/`UsageLedger` (Plan Engine, explicitly frozen/untouched this mission) + `Coupon`/`CouponUsage` (schema real, **dormant** — zero customer-facing redemption path, risk item 7) + `Referral`/`LoyaltyPoint` (both real, Customer↔Customer only — item 2) + `FlashSale`/`FlashSaleTarget`/`FlashSaleRedemption` (real engine, **never called from `CreateBookingAction`** — risk item 29, newly discovered Phase 21) + `Badge`/`BadgeAssignment` (real, one automatic rule).

### VENDOR
`Provider` (the accountable business/technician entity) — KYC (`ProviderDocument`, `KycVerificationVideo`, 30-day deadline + reminders), `ProviderSubscription`, wallet via the same `WalletService`, commission via the same `CommissionService`. No "vendor catalog"/"vendor packages"/"vendor leads" concept exists — a Provider does not own a product catalog; it owns skill/capability tags matched against the single Service catalog.

### RIDER/WORKER
`FieldWorker`/`FieldWorkerCapability`/`PartnerWorker`/`FieldWorkerDocument` (Phase B0.1) — a Worker is a first-class identity, independently linkable to zero-or-more Partners, holding zero-or-more capabilities. `AssignBookingToWorkerAction` (Phase B0.2) enforces every delegation boundary. **No independent Worker earnings model exists** (risk item 6 — Workers earn through a delegating Provider's own commission split; a direct Worker payout path was never built).

### NOTIFICATIONS
`OtpService`/`Otp` (hashed, lockout, resend-cooldown, full audit trail — the real, complete OTP engine), `NotificationTemplate`/`NotificationLog`/`NotificationCampaign` (Phase 8, admin CRUD + delivery logs + provider-status panel + retry), `QrChallenge` (device-pairing login). SMS/push adapters: `LogSmsAdapter`/`LogPushAdapter` bound by default; `Msg91SmsAdapter`/`GatewayApiSmsAdapter`/`FirebaseFcmPushAdapter` exist and are tested against `Http::fake()` but **none is bound**, no real credentials exist anywhere (BD-8, `KNOWN_RISKS_AND_DECISIONS.md` item 8 — this is the item Phase 21 explicitly left OPEN and this audit's brief names as still-open). Email: no channel exists at all — grepped, zero `Mail::` usage in `app/`.

### ADMIN
Livewire 4, 33 registered screens/views (28 top-level + 5 drill-downs), every one permission-gated at `mount()`, sidebar-synced, and test-covered for both denial and success paths — verified end-to-end at Phase 19 (`FINAL_ADMIN_CAPABILITY_MATRIX.md §A`), re-confirmed unchanged by this session's test run. TECH-6 (Phase 21) completed a shared UI design system across all 35 screens — see §12.

---

## 3. Current Module Control Plane

**This is the section the mission brief most wants a precise answer to. Verified by direct code trace, not assumed.**

Three separate mechanisms in the codebase use the word "module." They do not compose into a hierarchy, and two of the three have essentially no runtime effect today.

### 3a. `service_categories.module` — a catalog **tag**, not an activation gate
Every `ServiceCategory` row carries a `module` column (validated against `Modules::slugs()` — one of `service|parcel|car_rental|food|grocery|pharmacy|commerce|taxi|bookings`). This is used for **filtering**: the Categories/Subcategories/Services/Banners/NotificationCenter/Plans admin screens all let an operator filter by module (`filterModule` Livewire property, `->where('module', ...)` clauses — verified in `app/Livewire/{Categories,Subcategories,Services,Banners,NotificationCenter,Plans}/Manage.php`). It answers "which vertical does this category belong to," never "is this vertical turned on right now." No booking-creation code path (`CreateBookingAction`, `DispatchService`) ever reads `service_categories.module` to decide whether to allow or block anything — it is descriptive metadata only.

### 3b. `franchise_modules` — a per-franchise toggle with **zero consumers**
`FranchiseModule` (table `franchise_modules`) stores 8 booleans per franchise (`service, food, parcel, taxi, grocery, pharmacy, commerce, bookings` — note: 8, not 9; it predates the `car_rental` addition to `Modules::ALL` and was never reconciled, a gap the code's own docblock in `app/Support/Modules.php` names explicitly). It is **written** by `Franchises\Manage` (admin CRUD, `updateOrCreate` on two call sites) and by `FranchiseObserver` (auto-creates a row with `service=true` when a Franchise is created) and by `QaSeeder`/`QaCleaner` (test fixtures). A repo-wide search for any *reader* of these columns outside that admin CRUD path found **none** — no controller, Action, Service, or dispatch/booking code anywhere queries `franchise_modules.service`/`.food`/etc. to gate anything. This is a real, stored, admin-editable flag that currently has **no observable effect on the running application** — the "Service is the only live vertical" state described in `PROJECT_CURRENT_STATE.md §1` is true by construction (no code for other verticals exists to gate), not because this toggle is turning anything off.

### 3c. `module` as an RBAC/Settings **scope key** — an authorization/config axis, not a customer-facing switch
`RoleAssignment.scope_type` accepts `module` as one of six values (`global/country/city/zone/module/franchise`) — a permission grant can be scoped to a specific `Module` row (`app/Models/RoleAssignment.php`, `app/Livewire/Roles/Manage.php:150`). `Setting::SCOPE_ORDER` likewise includes `module` in its most-specific-wins cascade (`zone > franchise > module > city > country`). Both are real, live mechanisms — but they answer "who can act on this module" or "what config value applies for this module," never "is this module visible/bookable right now."

### Verdict on the requested cascade
```
GLOBAL MODULE → COUNTRY → CITY → ZONE → FRANCHISE/HQ → BRANCH → CATEGORY → SUBCATEGORY → SERVICE/PRODUCT
```
**This cascade does not exist in the current codebase, at any level, in any form.** Specifically, verified directly against each model:
- `Country`/`City`/`Zone` each have only a generic `is_active` boolean with **no module dimension at all** — a country cannot be "on for Service, off for Parcel." There is no country-level or city-level or zone-level module toggle anywhere in the schema.
- `franchise_modules` is the *only* level with a real per-module column set, and it has no consumer (§3b).
- There is no `Branch` layer distinct from `Franchise` — the hierarchy today is `Country → City → Franchise → Zone`, not `Franchise/HQ → Branch`.
- `service_categories.module` sits below all of the above as a tag with no relationship back up to any activation state.

**No inheritance or override behavior exists to trace, because no level actually enforces activation** — there is nothing for a lower level to inherit from or override. This is a genuine, currently-unbuilt capability, not a bug in an existing one.

---

## 4. Glover Reference Architecture

Sources (same as `GLOVER_6AMMART_PARITY_AUDIT.md`, re-verified present on disk this session): Glover 1.8.5 full Laravel source (`D:\D-Downloads\version-1.8.50\references\glover-1.8.5`, 152 migrations, 118 models), 6amMart 4.0.1 full admin-panel source (`D:\D-Downloads\1CallFix Super App\6amMart Main app V 4.0.1\Admin panel new install V4.0.1`), and — **checked directly for the first time this session** — `6ammart-car-rental-module-addon`, which turned out to contain **only a `Changed files from V1.9 to V2.0` diff folder, not a full addon source tree**. This is a real limitation on how much this audit can say about 6amMart's car-rental module specifically: it can be *inferred* to exist as a licensed addon, but its actual implementation could not be inspected — flagged here rather than glossed over.

### 4a. Glover's actual module-control shape (not the hierarchy the brief hypothesized)
Glover is a **flat, geography-light multi-vendor marketplace**:
- `vendor_types` — one row per vertical (food/grocery/taxi/parcel/service/pharmacy/commerce), each with a single `is_active` boolean (`database/migrations/2014_01_07_180116_create_vendor_types_table.php`). `App\Services\WebsiteModuleService::getModules()` reads exactly this — one global on/off per vertical, no geography dimension at that layer.
- `delivery_zones` (+ `delivery_zone_points` for polygon geometry) — Glover's only geographic subdivision below "whole platform." **No country/city/franchise/branch hierarchy exists in Glover at all** — confirmed by the absence of any such tables in its 152 migrations.
- `delivery_zone_vendor_type` — a pivot table (`2023_05_08_201522_create_delivery_zone_vendor_type_pivot_table.php`) is the **entire** cross-cutting activation mechanism: which vertical is enabled in which zone. That is the full depth of Glover's "module control plane" — one geography level × one boolean, no country/city/franchise/branch layers to cascade through.
- `Vendor` (the store/business entity) sits *under* a `vendor_type`, and separately under a `delivery_zone` — Glover's vendor is a materially different, richer entity than 1CallFix's `Provider` (delivery fee, tax, commission, prepare/delivery time — confirmed by `GLOVER_6AMMART_PARITY_AUDIT.md` and independently by this session's model inspection).

**This means 1CallFix's own franchise/zone/RBAC scoping is already structurally deeper than Glover's module control ever was** — Glover never had a franchise or branch concept to cascade a toggle through in the first place. The 7-level cascade the mission brief describes is not "what Glover has and 1CallFix lacks" — it's a **new design neither reference product ever built**, closer in shape to 6amMart's `nWidart/laravel-modules`-based addon system (physically separate module packages) than to Glover's single-pivot-table approach.

### 4b. Module-by-module source inventory (Glover unless noted)
| Module | Real models/controllers found | Order/booking entity | Notes |
|---|---|---|---|
| Food/Grocery/Pharmacy/E-commerce | `Vendor*`, `VendorType`, product-side models (not separately enumerated — same `Vendor` entity serves all four, differentiated by `vendor_type_id`) | `Order` (generic to all vendor-type verticals) | One shared order table across 4 verticals — the opposite of 1CallFix's per-vertical-`Booking` shape |
| Parcel | `PackageType`, `PackageTypePricing`, `PackageOrderController`, `PackageOrderTrait` | `Order` (package-flavored) | Reuses the same generic `Order` table as food/grocery |
| Taxi | `TaxiOrder`, `TaxiZone`/`TaxiZonePoint` (separate zone geometry from `delivery_zones`), `TaxiOrderMatchingJob`/`VPSTaxiOrderMatchingJob`/`WebsocketTaxiOrderMatchingJob`, `TaxiOrderService` | `TaxiOrder` (its own dedicated table, NOT the generic `Order`) | Taxi is the one vertical Glover gave a fully separate order pipeline and matching-job family, not the shared one |
| Hotel Booking | Not found as a distinct concept in Glover's 118 models (no `Hotel`/`Room` model located) | — | No Glover evidence found; **classify Hotel as D on 1CallFix evidence and unconfirmed on Glover evidence** — see §5 |
| 9A. Property/House Rental | `Property`, `PropertyType`, `PropertyFee`, `PropertyAvailability`, `PropertyReview`, `PropertyObserver`, `PropertyController`/`VendorPropertyController` | `Property` booking flow (separate from `Order`) | Real, materially complete reference implementation |
| 9B. Vehicle/Taxi Rental | Not distinct from 4b's Taxi (ride-hailing) in Glover core; **6amMart's car-rental addon exists only as an unreadable diff** (§4, above) | — | No usable reference source for this specifically; do not conflate with Taxi (4b) |
| 9C. Self-Drive Car Rental | Not found anywhere in Glover core; not found in the car-rental addon (diff-only, unreadable) | — | No reference evidence at all |
| 9D. Machine & Tools Rental | Not found anywhere in Glover, the 6amMart admin panel, or the car-rental addon — a repo-wide `machine\|tool.?rental\|equipment.?rental` search across both reference trees returned zero matches | — | No reference evidence at all, in any source available to this audit |

---

## 5. Module Capability Matrix

Classification key: **A** fully implemented · **B** core architecture exists, operational module incomplete · **C** partially implemented · **D** Glover reference only / not currently implemented · **E** superseded by a newer 1CallFix mechanism.

| # | Module | Class | Evidence |
|---|---|---|---|
| 1 | **Services** | **A** | The entire built platform (§2). Full FSM, dispatch, wallet/commission, RBAC, KYC, 778 passing tests exercise this vertical almost exclusively. |
| 2 | **Parcel** | **B** | `dispatch_attempts.notifiable_type/id` is polymorphic on the worker side (Phase B0.3), so a future Parcel vertical's dispatch offers can notify a `FieldWorker` the same way Service notifies a `Provider` — but `dispatch_attempts.booking_id` itself is a hard FK to `bookings`, NOT polymorphic (corrected in Phase 22.2, see §6 — the original audit pass here overstated this). `FieldWorker`/capability/wallet/RBAC are all reusable. **No `Parcel`/package model, no parcel-specific order/pricing/dispatch behavior exists.** Named "next" in the roadmap since before this mission began; explicitly not started. |
| 3 | **E-Commerce** | **D** | Zero schema, zero model, zero controller. `KNOWN_RISKS_AND_DECISIONS.md` item 20 confirms no `products`/`vendors`/`menus` tables exist to import into. Glover/6amMart both have a full multi-vendor commerce stack (§4b). |
| 4 | **Food Delivery** | **D** | Same as E-Commerce — shares Glover's generic `Vendor`/`Order` entity, which has no 1CallFix counterpart. |
| 5 | **Grocery** | **D** | Same as above. |
| 6 | **Pharmacy** | **D** | Same as above; also no prescription/RX verification concept exists in either reference or 1CallFix. |
| 7 | **Taxi / Ride** | **D** | 1CallFix has zero taxi/ride concept (no vehicle, no ride-matching-by-route, no fare-meter logic). Glover has a fully separate, dedicated `TaxiOrder` pipeline (§4b) — genuinely the most mature single-vertical reference of the nine. |
| 8 | **Hotel Booking** | **D** | Zero 1CallFix implementation. No Glover evidence located either (§4b) — this module's reference material is thinner than the other eight; treat any future "Hotel" scoping as needing outside research beyond what these two reference codebases can supply. |
| 9A | Rental — Property/House | **D** | Zero 1CallFix implementation. Glover's `Property`/`PropertyType`/`PropertyFee`/`PropertyAvailability` is a real, materially complete reference (§4b) — the single best-documented "D" module in this matrix. |
| 9B | Rental — Taxi/Vehicle | **D** | Zero 1CallFix implementation. No usable Glover-core reference (Taxi in Glover is ride-hailing, not rental); 6amMart's car-rental addon is present on disk but unreadable (diff-only, §4). |
| 9C | Rental — Self-Drive Car | **D** | Zero 1CallFix implementation, zero reference-source evidence found anywhere. |
| 9D | Rental — Machine & Tools | **D** | Zero 1CallFix implementation, zero reference-source evidence found in Glover, 6amMart's admin panel, or the car-rental addon. This is the single least-evidenced module in the entire matrix — no reference architecture to recover from at all; any future build here would be greenfield design, not "recovery." |

No module was classified **E**. Nothing in the current architecture makes any Glover-side capability obsolete — the gap is entirely "not built yet," not "built differently and better."

---

## 6. Module Activation Matrix

| Module | Global Toggle | Country | City | Zone | Franchise | Branch | Category | Subcategory | Service/Product |
|---|---|---|---|---|---|---|---|---|---|
| Services | NOT PRESENT (only `Modules::ALL` as a static code list — nothing turns it "off" platform-wide) | NOT PRESENT | NOT PRESENT | NOT PRESENT | EXISTS (`franchise_modules.service`) but **unenforced** (§3b) | NOT APPLICABLE (no Branch layer exists) | EXISTS (`is_active` per `ServiceCategory`, enforced — catalog screens filter on it) | EXISTS (`is_active` per `ServiceSubcategory`, enforced) | EXISTS (`is_active` per `Service`, enforced, soft-delete-aware) |
| Parcel / Ecommerce / Food / Grocery / Pharmacy / Taxi / Hotel / Rental (all 4) | NOT PRESENT | NOT PRESENT | NOT PRESENT | NOT PRESENT | PARTIAL — `franchise_modules` has a boolean column for `food/parcel/taxi/grocery/pharmacy/commerce/bookings` (not `car_rental`), but since none of these verticals has any operational code, the column is inert by definition, not "existing and off" | NOT APPLICABLE | NOT APPLICABLE (no category rows exist under these `Modules::ALL` tags today — nothing to activate) | NOT APPLICABLE | NOT APPLICABLE |

**Inheritance/override behavior:** none exists to describe. Category/Subcategory/Service activation (the one genuinely enforced layer) is **independent per row** — a subcategory's `is_active=false` does not require its parent category to also be inactive, and no code path checks a parent's activation state before rendering/booking a child (verified: `ServiceCategory`/`ServiceSubcategory`/`Service` each carry their own `is_active` with no cross-model guard clause in the catalog Livewire screens or `CreateBookingAction`). This is a flat set of independent switches, not a cascade with fallback semantics — **do not assume** (per the brief's own instruction) that turning a category off silently deactivates its subcategories; it does not, today.

---

## 7. Central Engine Compatibility

| Engine | Module-neutral today? | Evidence | Reuse verdict per new module |
|---|---|---|---|
| **Order Engine (`Booking`)** | **NO — the single biggest structural finding of this audit.** `bookings.service_id` is a hard, non-nullable, non-polymorphic FK to `services` (`app/Models/Booking.php`, verified directly). `Booking` is not a generic "Order" — it IS the Service vertical's order table. | Direct model read this session | **CANNOT REUSE as-is.** A second vertical (Parcel, Food, etc.) needs one of: (a) a new nullable/polymorphic `orderable_type`/`orderable_id` pair added to `Booking` alongside the existing `service_id` (additive, low-risk per §8), (b) a shared parent `Order` table with `Booking` becoming one subtype among several, or (c) a fully separate per-vertical order table (Glover's own Taxi precedent — §4b) that reuses the *surrounding* engines (Dispatch/Wallet/Commission) but not `Booking` itself. This is a real architecture decision, not a schema afterthought. |
| **Dispatch Engine** | PARTIALLY — `dispatch_attempts.notifiable_type`/`notifiable_id` (Phase B0.3) is genuinely polymorphic on the WORKER side (who's being notified — a `Provider` or a `FieldWorker`). **Correction, made during Phase 22.2 (2026-08-16): `dispatch_attempts.booking_id` itself is NOT polymorphic** — still a hard, non-nullable FK straight to `bookings`, verified by re-reading both migrations directly. The original audit pass here conflated "the table has a polymorphic column" with "the table is fully reusable for any order type" — it is not; a future vertical would need its own dispatch-attempts table (or a further, separately-decided migration polymorphizing `booking_id` too) to reuse this ledger shape. `DispatchService::findCandidates()` also reads `$booking->service->category_id` directly, coupling the *matching logic* (not just the schema) to the Service catalog shape. | `app/Services/DispatchService.php` + both `dispatch_attempts` migrations, re-read directly | **PARTIAL REUSE, narrower than originally stated.** `RankingEngine` is reusable as-is. The `dispatch_attempts` ledger's *worker-side* polymorphism is reusable; its *order-side* FK is not, without further schema work Phase 22.2 did not undertake. |
| **Wallet Engine** | YES | `WalletService` operates on `Wallet`/`WalletTransaction` keyed to a `User`, with no Booking/Service coupling at all | **FULL REUSE.** Any new vertical's payouts/refunds/top-ups go through the identical `WalletService` API. |
| **Settlement/Commission Engine** | MOSTLY | `CommissionService::applyForBooking()` takes a `Booking` and computes off `Franchise`-configured rates — coupled to `Booking` (see Order Engine row), not to Service specifically | **REUSABLE once the Order Engine question is resolved** — the split-calculation logic itself has no Service-specific assumption. |
| **Notification Engine** | YES | `NotificationTemplate`/`NotificationLog`/adapters key off recipient + channel, not off any vertical concept; `module` even exists as a targeting field on `NotificationCampaign`/`Banner` already (§3a) | **FULL REUSE.** |
| **Coupon Engine** | YES (architecturally) but **dormant** | `Coupon`/`CouponUsage` schema has FK integrity but zero live redemption path for any vertical today (risk item 7) | **REUSABLE once launched** — nothing about it is Service-specific, but it isn't live for Services either, so there is no proven multi-vertical precedent yet. |
| **Referral Engine** | YES for the mechanism, **NO for scope** | `ReferralService` qualifies off "first completed booking" — again the `Booking`-coupling from the Order Engine row surfaces here indirectly | **REUSABLE once Order Engine question resolved**; also gated on risk item 2 (cross-actor referral scope) regardless of vertical. |
| **Loyalty Engine** | YES | `LoyaltyPoint` ledger keyed to `User`, earn/redeem independent of any vertical concept | **FULL REUSE.** |
| **Authorization/Scoping (`AuthorizationService`)** | YES | Global/country/city/zone/module/franchise scope model, verified to already include `module` as a first-class scope type (§3c) | **FULL REUSE** — arguably the one engine already *over*-built relative to what's activated today; the module-scoped RBAC capability exists and has zero current consumers to exercise it, the same "orphaned mechanism" shape this mission's own risk register has repeatedly found elsewhere (Tips, Reviews, in-app notifications before they were wired). |

**Bottom line:** 6 of 9 engines are genuinely module-neutral today. The exceptions all trace back to one root cause — `Booking.service_id` — not nine separate problems. Resolving that one schema/architecture question (additive polymorphic column, shared parent table, or per-vertical tables reusing the surrounding engines) is the actual prerequisite for "can the current architecture support 9 modules without 9 separate systems," not a rebuild of Wallet/Commission/Notification/Loyalty, which already qualify.

---

## 8. Data Preservation Analysis

No future-module work was performed this session; this section evaluates the *shape* of the risk based on the schema as it exists today.

| Future work | Risk | Why |
|---|---|---|
| Add `car_rental` boolean column to `franchise_modules` (closing the 8-vs-9 drift `app/Support/Modules.php` already flags) | **LOW** | Purely additive column, nullable/defaulted, no existing row touched, no FK. |
| Add country/city/zone-level module-activation tables (closing §3/§6's gap) | **LOW** | New tables (`country_modules`, `city_modules`, `zone_modules` or similar), no modification to `countries`/`cities`/`zones`/`franchises` themselves required — an activation lookup can sit entirely beside the existing geography tables. Can ship with every row defaulted OFF, zero behavior change until an admin opts in. |
| Add a new vertical's catalog (e.g., `parcel_types`, `products`) | **LOW** | New tables under a new module tag already accepted by `Modules::slugs()` validation (Categories screen already validates against the full 9-slug list, including verticals with zero real categories today) — no existing catalog table needs a column added. |
| Resolve `Booking.service_id` non-polymorphism (§7) | **MEDIUM–HIGH depending on approach** | Adding a *new* nullable `orderable_type`/`orderable_id` pair alongside the existing `service_id`/`franchise_id`/`zone_id` columns is itself additive and safe (existing rows keep working unchanged, `service_id` stays populated for every historical Service booking). The risk rises only if a later step ever *migrates* `service_id` data into the new polymorphic shape or drops it — that would touch every existing `bookings`/`commissions`/`payments`/`dispatch_attempts` row's FK chain. **Recommendation embedded in this classification: add alongside, never replace-in-place**, which keeps this LOW-to-MEDIUM rather than HIGH. |
| Order numbering (`BookingSequence`/`OrderCodeService`) for a second vertical | **LOW** | Already keyed per-franchise-per-year, not globally sequential — a second vertical sharing the same sequence generator needs, at most, a vertical-prefix parameter, not a redesign. Existing booking codes are untouched either way. |
| Wallet/commission ledger compatibility | **LOW** | Both are already keyed to `User`/`Franchise`, not to `Service` — a new vertical's transactions slot into the existing ledger schema with no structural change (confirmed §7). |
| Historical order readability | **LOW**, contingent on the Order Engine decision above being additive | As long as `service_id` is never dropped or repurposed, every historical booking remains fully readable exactly as today. |
| Module activation shipped OFF by default | **Fully achievable** | Every activation table proposed above can seed 100% OFF and require an explicit admin action to flip — no migration in this analysis requires flipping anything live to deploy safely. |
| Rollback implications | **LOW across the board** | Every table proposed here is new and additive; a `migrate:rollback` on any of them drops a table nothing else depends on yet. The one already-known SQLite-only rollback quirk (`PROJECT_CURRENT_STATE.md §16`, `2026_08_01_003500_add_owner_fk_to_franchises_table`) is unrelated to any future-module work and was not touched by this audit. |

No claim of "zero risk" is made anywhere above, per the brief's own instruction — every LOW rating is LOW because the proposed shape is additive-only, not because no risk exists at all (a badly-written activation-lookup query could still be slow at scale, an admin could still misconfigure an override, etc. — ordinary engineering risk, not data-loss risk).

---

## 9. Glover Capabilities Worth Recovering

Capability-level, not code-level (per the mission's own "recover capabilities, not copy code" principle — see §Final Principle):

- **The concept of a per-zone module-activation pivot** (Glover's `delivery_zone_vendor_type`) — not the table itself, but the *idea* that "which verticals are sellable in this specific geography" deserves its own explicit lookup rather than being inferred. 1CallFix's own richer geography (Country→City→Franchise→Zone, vs. Glover's flat zone-only) means the *right* recovery is a multi-level version of this idea (§6's proposed `country_modules`/`city_modules`/`zone_modules`), not a literal copy of Glover's single pivot.
- **Property/House Rental's real data shape** (`Property`/`PropertyType`/`PropertyFee`/`PropertyAvailability`) — the most complete, directly reusable reference in the entire audit (§4b). A future 9A build has real prior art to consult for what fields/relationships a rental listing needs.
- **Taxi's dedicated order pipeline as a precedent for "don't force everything through one order table"** — Glover itself didn't put Taxi through its generic `Order` table; it gave Taxi (`TaxiOrder`) its own. This is direct, real-world precedent supporting the §7 recommendation that a future 1CallFix vertical may reasonably get its own order table rather than forcing a `Booking` schema retrofit.
- **The real historical 1CallFix business values already extracted in `GLOVER_6AMMART_PARITY_AUDIT.md §2`** (₹10 referral, 20%/12% commission splits, Firebase+MSG91 vendor precedent, real 140-service catalog, real Nellore zone geometry) — already surfaced, not re-derived here, but worth restating as still-live evidence for whoever eventually makes the business decisions in `KNOWN_RISKS_AND_DECISIONS.md`.
- **6amMart's per-vertical addon packaging pattern** (`nWidart/laravel-modules`) as a *structural* precedent, not code to import — physically separating each vertical's routes/views/migrations into its own package is a reasonable answer to "avoid nine tangled systems," worth weighing against a single-schema-with-`orderable_type` approach when the Order Engine decision (§7) is actually made.

---

## 10. Glover Capabilities NOT Worth Copying

- **Glover's flat, franchise-less geography model.** 1CallFix's Country→City→Franchise→Zone + additive RBAC scoping is already a materially richer ownership/territory model than either reference product has. Adopting Glover's single-`delivery_zone` geography would be a regression, not a recovery.
- **Glover's single unified `Order` table shared across Food/Grocery/Pharmacy/Ecommerce.** This is exactly the kind of "old code was tightly coupled" pattern the brief warns about — a table that means four different things depending on `vendor_type_id` is harder to reason about than 1CallFix's current one-table-one-meaning `Booking`, and copying it would trade today's clarity for Glover's own accumulated complexity, without gaining anything 1CallFix doesn't already have a cleaner path to (§7's additive-polymorphic-column option).
- **Glover/6amMart's authorization model.** Both use conventional Laravel role/permission packages (`spatie/laravel-permission` per the real 1.8.10 dump) with no scope-cascade concept — 1CallFix's own `AuthorizationService` (global/country/city/zone/module/franchise, additive) is already strictly more capable and should not be diluted by importing a simpler model.
- **Glover/6amMart's own admin UI.** TECH-6 (Phase 21) just finished a shared design system across 35 screens; neither reference's admin UI (traditional Blade/jQuery-era for Glover, Blade+Bootstrap for 6amMart) is a better foundation than what already exists — see §12, do not redesign TECH-6.
- **The unreadable car-rental addon as a basis for 9B.** Since only a diff folder exists (§4), there is no actual code to evaluate for "worth copying" — this isn't a decision to make yet, it's a gap in available reference material to note honestly rather than paper over by assuming the addon resembles the rest of 6amMart's structure.
- **Glover's `vendor_types.is_active` as a single global switch.** A future 1CallFix module-activation table should be scoped (country/city/zone/franchise-aware, per §6's proposal), not a single platform-wide boolean — Glover's own simplicity here is a product of its flat geography, which 1CallFix has already outgrown.

---

## 11. Admin Control Plane Gap Analysis

What exists today toward the requested Glover-style module-activation admin experience:

| Piece | Status | Evidence |
|---|---|---|
| A "Modules" list screen | **NOT PRESENT** | No `admin.modules.*` route exists in `routes/admin.php`; `Module` model has no Livewire screen anywhere. |
| Per-module ON/OFF at any geography level | **NOT PRESENT** (§3, §6) | No country/city/zone activation table exists to build a screen over. |
| Franchise-level module toggle UI | **PARTIAL, already exists** | `Franchises\Manage` already renders/edits the 8 `franchise_modules` booleans (`app/Livewire/Franchises/Manage.php`) — this is real, working admin UI, just currently unenforced downstream (§3b). |
| Categories/Subcategories/Services activation UI | **EXISTS, fully working** | `Categories\Manage`/`Subcategories\Manage`/`Services\Manage` — real CRUD, real `is_active` toggles, real filtering by module tag, real tests. |
| Pricing/Availability per branch | **NOT APPLICABLE** — no Branch layer exists (§3); franchise-level pricing exists (`FranchiseServicePricing`) |
| Vendors/Coupons/Promotions admin | **PARTIAL** — Vendors: N/A (no multi-vendor concept exists to administer beyond `Providers\Manage`, which already exists and is Service-specific). Coupons: schema exists, **no admin screen** (dormant, item 7). Promotions: `FlashSales\Manage`/`Badges\Manage`/`PerformanceCampaigns\Manage` all exist and are real, though Flash Sale is disconnected from real bookings (item 29). |

**What would need to be built later** (not built now, per the brief): a `Modules\Manage` screen; new activation tables + a scope-aware activation service (mirroring `AuthorizationService`'s own scope-resolution pattern, which is directly reusable as a design template even though it currently answers a different question); reconciling the `franchise_modules` 8-vs-9 column drift; wiring `service_categories.module`/`franchise_modules` into an actual enforcement point in `CreateBookingAction`/catalog-visibility queries once any future vertical has real categories to gate.

---

## 12. AI Admin Compatibility

TECH-6 (Phase 21, `88cd6da`/`6d76d01`) is complete and explicitly **not** being redesigned by this audit. The shared component set it established (per `PROJECT_CURRENT_STATE.md`/commit history — buttons, cards, tables, badges, modals, status-pills, applied to all 35 screens) is the correct foundation for a future module control plane to sit inside: a `Modules\Manage` screen would consume the exact same primitives every other Phase-21-migrated screen already does, not invent new ones.

Concrete future opportunities (not implemented, per the brief):
- **Command palette / global search** — natural fit once a `Modules\Manage` screen exists; today's sidebar (`resources/views/layouts/admin.blade.php`) is a flat, permission-filtered list with no search layer at all.
- **AI-assisted navigation / operational summaries** — `Operations\Health` already aggregates read-only detection signals (reconciliation, dispatch-health, stuck-booking) in one place; this is the natural data source for a future AI summary layer, since the detection logic (not the presentation) is already centralized.
- **Anomaly detection** — `ReconciliationService`'s 5 existing checks are exactly the shape a future AI layer would want to reason over (real, structured, already-computed signals) rather than raw table scans.
- **Bulk operations / saved views / quick actions** — no current admin screen has any of these; every list screen today is single-row-action-per-row (verified across the 33-screen inventory in `FINAL_ADMIN_CAPABILITY_MATRIX.md §A`) — a real, bounded gap for any future productivity pass, module-control-plane or otherwise.

No AI feature was built or scaffolded this session.

---

## 13. Future Implementation Roadmap

Ordered by dependency, not by arbitrary priority number. Every phase below is proposed, not scheduled — no dates are given, per the brief's own instruction, since nothing in the repository establishes a basis for one.

| Phase (proposed) | Objective | Prerequisite | Affected engines | Data risk | Business decision required first? |
|---|---|---|---|---|---|
| Module Control Plane — geography activation | Build country/city/zone (and reconcile franchise-level) module-activation tables + admin screen | None structural — additive only (§8) | AuthorizationService (pattern reuse), Settings | LOW | No — pure engineering; can ship fully OFF |
| Order Engine decision | Resolve `Booking.service_id`'s non-polymorphism (§7) — pick additive-polymorphic-column vs. shared-parent-table vs. per-vertical-tables | Module Control Plane (so a new vertical has somewhere to be turned on) | Order, Dispatch, Commission, Referral | MEDIUM (if additive-only, per §8) | **Yes** — this is an architecture decision with real long-term cost, not a default-safe engineering call |
| Parcel completion | Build the actual Parcel order/pricing/dispatch module — reusing `dispatch_attempts`' worker-side polymorphism, but needs its own dispatch-ledger table or a further migration for the order side (see §6 correction) | Order Engine decision | Dispatch (partial reuse), Wallet/Commission (reuse once Order Engine resolved) | LOW–MEDIUM | Business: real parcel pricing model |
| Ecommerce / Food / Grocery / Pharmacy | New catalog shape (products, not services), new vendor entity or `Provider` extension, new order flow | Order Engine decision, a real Vendor/Product target schema (item 20) | All of §7's table, once Order Engine resolved | MEDIUM–HIGH (new tables, new relationships, real vendor-onboarding data) | **Yes** — whether 1CallFix ever becomes a multi-vendor marketplace at all (item 20 already flags this as unresolved) |
| Taxi | New ride/vehicle/fare-meter domain, likely its own order table (Glover precedent, §9) | Order Engine decision | Dispatch (partial reuse — real-time matching differs materially from Service's book-ahead model) | MEDIUM | Business: fare model, driver vs. Provider/Worker fit |
| Hotel Booking | Greenfield — no strong reference exists (§4b, §5) | Order Engine decision | Order, Wallet/Commission (reuse) | MEDIUM | Business: this module has the least existing groundwork of the nine |
| Rental — Property | Reuse Glover's `Property`/`PropertyType`/`PropertyFee` shape as design reference | Order Engine decision | Order, Wallet/Commission (reuse) | MEDIUM | Business: listing/availability model |
| Rental — Vehicle / Self-Drive / Machine-Tools | Greenfield for all three — no usable reference exists (§4b, §5) | Order Engine decision, Rental-Property (shared rental primitives likely) | Order, Wallet/Commission (reuse) | MEDIUM | Business: these three are the least-evidenced of the entire matrix |
| Admin AI layer | Command palette, anomaly surfacing, bulk ops | TECH-6 (done) | None new — presentation layer over existing engines | LOW | No — additive UI |
| Web application / Customer / Vendor / Rider Flutter apps | Out of scope for this audit's module questions — noted for completeness since the brief lists them | — | — | — | Unchanged from `PROJECT_CURRENT_STATE.md §21`'s existing "no mobile app exists" finding |
| E2E / Load testing / staging | Unchanged, long-standing gap (`PROJECT_CURRENT_STATE.md §19/§20`) | — | — | — | No — pure engineering backlog |

---

## 14. Business Decisions

Carried forward from `KNOWN_RISKS_AND_DECISIONS.md` where directly relevant to this audit, plus new ones this audit itself surfaces:

- **Whether 1CallFix becomes a multi-vendor marketplace at all** (item 20) — the single largest unresolved question underlying this entire audit; every module beyond Parcel depends on it.
- **Which Order Engine shape to adopt** (§7, §13) — additive-polymorphic column vs. shared parent table vs. per-vertical tables. A genuine architecture decision, not a default.
- **Whether module activation should be seeded OFF-everywhere or ON-for-existing-Service-only** the moment the geography-activation tables (§13) are built — a rollout-sequencing choice, not an engineering default.
- **Taxi's actor model** — does a Taxi driver map onto the existing `Provider`/`FieldWorker` duality, or does it need a new actor type? Not answerable from evidence in either reference codebase.
- All previously-open items in `KNOWN_RISKS_AND_DECISIONS.md` (referral/commission/compensation rate values, coupon launch, second payment provider, `payment_methods` consolidation, Flash Sale stacking, KYC deadline scope for Workers, T&Cs/Privacy content, multi-language) remain open and unaffected by this audit — none were resolved or newly invented here.

---

## 15. Technical Decisions

- **Do not retrofit `Booking.service_id` in place.** Any polymorphic conversion must be additive (new nullable columns alongside the existing ones) — never a replace-in-place migration, per §8's risk classification.
- **Reuse `AuthorizationService`'s scope-resolution pattern** (not its code, its shape — global→country→city→zone→franchise cascade, additive grants) as the design template for the future module-activation service, rather than inventing a new resolution algorithm.
- **Close the `franchise_modules` 8-vs-9 column drift** (missing `car_rental`) the moment any future-module work touches that table — a one-line addition already flagged in the code's own docblock, not a new finding, but worth doing in the same pass rather than compounding the drift further.
- **Do not copy Glover's single global `vendor_types.is_active`** — any future activation mechanism should be scoped per §6's proposed multi-level tables, matching 1CallFix's actual geography depth.
- **Treat the 6amMart car-rental addon as unusable reference material** until/unless a full (non-diff) copy becomes available — do not infer its structure from the rest of 6amMart's codebase.

---

## 16. Risks

- **Silent-toggle risk:** `franchise_modules` already presents a working admin UI (§11) that implies functioning module control to anyone using it — an operator could reasonably believe toggling "Food" off/on for a franchise does something today. It does not (§3b). This is a real, present UX-honesty gap independent of any future build — worth a small, low-risk fix (either wiring a real enforcement point once any module exists to enforce, or, at minimum, labeling the toggle as "not yet enforced" in the UI) even before a second vertical is built.
- **Order Engine decision deferred risk:** every future vertical is blocked on the same unresolved architecture question (§7) — deferring it repeatedly compounds cost the more Service-specific logic accumulates around `Booking`.
- **Rental sub-type evidence gap:** three of four Rental sub-types (Vehicle, Self-Drive, Machine/Tools) have zero usable reference material anywhere available to this audit — any future work there carries the ordinary greenfield-design risk of a first-of-its-kind build, not a "port from a working reference" risk profile like Property Rental enjoys.
- **RBAC module-scope over-build risk:** the `module` RBAC/Settings scope type already exists and works (§3c) with no current consumer to exercise it in anger — the same "orphaned mechanism" shape the risk register has repeatedly found (and fixed) elsewhere. Worth deliberately testing end-to-end the first time a real module-scoped grant is actually used, not assuming it works because it's schema-complete.

---

## 17. Recommended Next Phase

**Module Control Plane — Geography Activation (§13, phase 1).** This is the lowest-risk, highest-leverage next step: it is purely additive (§8: LOW risk), requires no business decision to *start* (only to decide default-seeded state, §14), reuses an existing design pattern (`AuthorizationService`, §15) rather than inventing one, and directly closes the gap this entire audit exists to name (§3's "no such cascade exists" finding). It does **not** require resolving the Order Engine question (§7) first, since geography-level activation is orthogonal to how a future vertical's orders are stored — it only needs to exist before any second vertical's admin screens go live, so building it now is not premature.

The Order Engine decision (§7/§13, phase 2) should follow as a deliberate, human-reviewed architecture decision — not bundled into the same phase as the additive geography work, per this mission's own repeated pattern of not conflating an engineering-safe default with a real design choice.

---

## FINAL REPORT

**PHASE 22 STATUS:** Complete — read-only audit, no implementation.

**CURRENT TESTS:** 778/778 passing, 1,799 assertions, 0 failures (re-run this session).

**CURRENT HEAD:** `8cd0618` on `main`.

**WORKING TREE:** Clean at audit start; this document is the only file added.

**PRODUCTION:** Unchanged at `ba0635a`. Not touched, not deployed, not migrated.

**MODULE SUMMARY:**
- Services: **A** — fully implemented.
- Parcel: **B** — polymorphic dispatch foundation exists; operational module not built.
- E-Commerce: **D**
- Food: **D**
- Grocery: **D**
- Pharmacy: **D**
- Taxi: **D**
- Hotel: **D**

**RENTAL:**
- Property: **D** — best-evidenced of the nine "D" modules (real Glover reference: `Property`/`PropertyType`/`PropertyFee`/`PropertyAvailability`).
- Vehicle: **D** — no usable reference (6amMart car-rental addon is diff-only).
- Self-drive: **D** — zero reference evidence anywhere.
- Machine/Tools: **D** — zero reference evidence anywhere; least-evidenced module in the matrix.

**CURRENT MODULE CONTROL PLANE:** Three disconnected mechanisms (`service_categories.module` tag, `franchise_modules` unconsumed toggle, RBAC/Settings `module` scope key) — no Country→City→Zone→Franchise→Branch→Category cascade exists at any level (§3).

**GLOVER MODULE CONTROL PLANE:** A single geography level (`delivery_zones`) × a single boolean (`vendor_types.is_active`) via one pivot table — structurally shallower than 1CallFix's own existing franchise/zone/RBAC scoping, despite covering more verticals (§4).

**WHAT SURVIVED:** The Service vertical, in full, plus every cross-cutting engine (Wallet, Commission, RBAC, Notification, Loyalty, KYC, Compensation) built to a standard that already exceeds both references in several respects (§1, §9).

**WHAT IS MISSING:** 8 of 9 requested modules (all but Services); the geography-level module-activation cascade; a non-polymorphic Order Engine blocking clean multi-vertical reuse (§2, §3, §7).

**WHAT IS SUPERSEDED:** Nothing — no module was classified E. 1CallFix's franchise/RBAC architecture already exceeds Glover's own geography/authorization model, but no Glover *module capability* has been replaced by an equivalent 1CallFix mechanism (§5).

**CENTRAL ENGINE REUSE:** Wallet, Commission, Notification, Loyalty, Coupon, Referral, and Authorization are module-neutral or nearly so. Order and Dispatch are coupled to the Service catalog via `bookings.service_id` — the one real structural blocker (§7).

**DATA PRESERVATION:** All proposed near-term work (geography activation tables, franchise_modules column fix, new vertical catalogs) is additive and LOW risk. The Order Engine resolution is MEDIUM risk if done additively, HIGH risk only if a future step migrates or drops `service_id` (§8).

**MODULE-BY-MODULE RISK:** See §8 table — every row is LOW as proposed, contingent on additive-only migrations.

**ADMIN CONTROL PLANE STATUS:** Franchise-level module toggle UI exists (unenforced downstream). Catalog activation UI exists and works. No Modules list screen, no geography-level activation UI exists (§11).

**FUTURE AI ADMIN STATUS:** TECH-6 foundation is ready to host it; no AI feature exists or was built this session (§12).

**BUSINESS DECISIONS:** Whether to become multi-vendor at all; which Order Engine shape to adopt; module-activation default-seeding policy; Taxi's actor model — none resolved or invented here (§14).

**TECHNICAL WORK REQUIRED:** See roadmap (§13) — Module Control Plane (geography activation) first, Order Engine decision second, then per-vertical builds.

**ENVIRONMENT CONSTRAINTS:** 6amMart car-rental addon is diff-only, not a full source — cannot be used as a reference for Vehicle Rental (9B) until/unless a complete copy is obtained (§4).

**RECOMMENDED PHASE ORDER:** Module Control Plane (geography activation) → Order Engine decision → Parcel completion → remaining modules per business priority (§13, §17).

**TOP 5 NEXT ACTIONS:**
1. Human decision: is multi-vendor/multi-vertical expansion actually wanted, and if so, which module first after Parcel?
2. Human decision: Order Engine shape (additive polymorphic column vs. shared parent vs. per-vertical tables).
3. Engineering (safe to start immediately, no decision blocking it): build geography-level module-activation tables + admin screen, seeded fully OFF.
4. Engineering: reconcile `franchise_modules`' missing `car_rental` column.
5. Engineering/UX: label or wire the currently-inert `franchise_modules` toggle so it stops implying functionality it doesn't have (§16).

**DOCUMENT CREATED:** `PHASE_22_PLATFORM_CAPABILITY_RECOVERY_AUDIT.md` (this file).

**COMMITS:** None yet — pending explicit confirmation this document should be committed locally (not pushed), consistent with this repository's own "work locally, do NOT push" convention for every prior phase's documentation.

---

**No code changed. No production touched. No migration created. No deployment. No push.**
