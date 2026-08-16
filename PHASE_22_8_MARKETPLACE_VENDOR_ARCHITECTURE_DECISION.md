# Phase 22.8 — Marketplace Vendor Architecture Decision

**Status: DESIGN/DECISION document only. No marketplace code implemented this phase, per the mission's own explicit instruction.** This resolves the ONE open architectural question blocking Ecommerce/Food/Grocery/Pharmacy (`PHASE_22_5_REMAINING_MODULES_TRIAGE.md`, `KNOWN_RISKS_AND_DECISIONS.md` item 20) — not the separate, genuine business decision of *whether* 1CallFix should become a multi-vendor marketplace at all, which this document does not and cannot answer (see §6).

---

## 1. The question

Ecommerce/Food/Grocery/Pharmacy all need a real "business that owns a catalog and fulfills orders" concept 1CallFix does not have today. Four candidate shapes were named:

- **A.** Vendor as a standalone business entity.
- **B.** Vendor → Business/Store → Branch (a three-tier hierarchy).
- **C.** `Provider` generalized into a `MarketplaceSeller`.
- **D.** Another evidence-supported structure.

## 2. Evidence gathered

### 2a. Glover's real `Vendor` model (direct read, `D:\D-Downloads\version-1.8.50\references\glover-1.8.5\app\Models\Vendor.php`)

A `Vendor` row is a **single physical location** — `latitude`/`longitude` (one pair, not a collection), `delivery_fee`, `delivery_range`, `charge_per_km`, `commission`, `pickup`/`delivery` capability flags, `is_open`, `vendor_type_id`. There is **no Branch/Business hierarchy anywhere in Glover** — confirmed by the model itself carrying exactly one address. A real-world multi-location chain in Glover is modeled as **multiple separate `Vendor` rows**, one per location, not one `Vendor` with several `Branch` children. This is the single most important piece of evidence this document found: **Option B has no precedent in the one reference codebase this mission is authorized to draw capability evidence from.**

### 2b. This codebase's own franchise/zone convention

Every real operational entity in the current schema — `Provider`, `FieldWorker`, `Property` (Phase 22.7), `Booking`/`ParcelOrder`/`TaxiRide`/`PropertyReservation` — carries its own `franchise_id`/`zone_id` directly, scoped to exactly one territory. A business with a genuine presence in two zones already has a real, established way to express that in this codebase: two separate rows, each independently franchise/zone-scoped (exactly the same shape Glover's own "one Vendor per location" pattern takes, arrived at independently). **This is real, existing precedent for the identical answer Glover's evidence gives — not a coincidence, but confirmation from two independent sources.**

### 2c. Why not reuse `Provider` (Option C)?

`Provider` today models one specific real-world thing: an accountable individual/business a **Service job** is offered to (KYC status, skills, online/offline dispatch availability, commission rate). Reusing it directly for property ownership worked cleanly in Phase 22.7 (`properties.provider_id`) because a property owner needs **none of Provider's Service-specific columns and adds none of its own** — it's a pure identity reuse. A marketplace Vendor is different in kind: it genuinely needs columns Provider has no use for and would never need for Service (a delivery radius/fee, opening hours, a catalog it owns, a business-type distinction between "sells food" vs. "sells electronics"). Bolting those onto `Provider` would repeat exactly the mistake Phase 22.2's Order Engine decision already rejected for `Booking` — a single table accumulating unrelated verticals' columns. **Option C fails for the same evidence-based reason Option A (a shared Order table) was rejected for the order layer.**

## 3. Decision: **Option A — a new, dedicated `Vendor` entity, franchise/zone-scoped, no Branch layer**

Specifically:

- **New `vendors` table** — a single-location business, mirroring Glover's own real column shape where it applies (`name`, `delivery_fee`, `delivery_radius_km`, `commission_model`/`commission_value` — reusing `Franchise`'s own existing `commission_model` enum vocabulary rather than inventing a second one, `is_open`, `is_active`) **plus** `franchise_id`/`zone_id` (Glover has no franchise concept at all; this is the one real, evidence-motivated addition this codebase's own convention requires that Glover's schema doesn't have to solve).
- **One row per physical location**, exactly like `Property` (Phase 22.7) and exactly like Glover's own real Vendor rows. A business operating in two zones is two `Vendor` rows, linked only informally (e.g. a shared `owner_user_id`) if/when that linkage is ever needed — not a new schema tier.
- **No `Branch` entity.** Per §2a/§2b, no evidence anywhere establishes a business genuinely needs an intermediate Business→Branch tier that a second `Vendor` row scoped to a second zone doesn't already express. Per the mission's own explicit instruction, this is documented as a **non-requirement**, not silently assumed.
- **Catalog ownership stays module-specific, not centralized on `Vendor` itself** — a `vendor_id` foreign key on each future module's own product/menu table (`products` for Ecommerce/Grocery/Pharmacy, `menu_items` for Food — real Glover evidence shows Food's menu shape genuinely differs from a flat product SKU list), mirroring how `Property` owns its own listing directly rather than a generic "catalog" table trying to serve four different product shapes at once. This is the SAME Option-A-style decision Phase 22.2 already made for orders, applied consistently to catalog ownership.

## 4. What this reuses vs. what's genuinely new

| Concern | Reuse or new? |
|---|---|
| Wallet | Reuse `WalletService` unchanged — already fully module-neutral, proven three times over (Parcel/Taxi/Property). |
| Commission/Settlement | Reuse the `applyForFieldWorkerOrder()`-shaped pattern (Phase 22.6/22.7) — a Vendor is an "earner" exactly like a FieldWorker/Provider is; the shared private helper's type hint would widen again the same evidence-based way it already has twice. |
| RBAC/franchise scope | Reuse `AuthorizationService::scopeQuery()` unchanged — `vendors.franchise_id`/`zone_id` slot into the exact same column-map convention every other screen already uses. |
| Module activation | Reuse `ModuleActivationService` unchanged — Ecommerce/Food/Grocery/Pharmacy are already real rows in `App\Support\Modules::ALL`/the `modules` table (Phase 22.1), just `is_implemented = false`. |
| Order engine | **New per module** — each of Ecommerce/Food/Grocery/Pharmacy gets its own order table (Option A, Phase 22.2), same as Parcel/Taxi/PropertyReservation. A Vendor decision does not by itself decide whether these four share ONE order table or four — that remains Phase 22.2's own precedent (they don't) unless new evidence says otherwise. |
| Staff/multi-user Vendor access | **Genuinely undecided** — no evidence found (Glover's Vendor uses `HasPermissions`/Spatie directly on the Vendor-owning user, not a staff-roster concept 1CallFix's own `RoleAssignment` model would need to be extended to support). Logged as an open question, not assumed either way (§6). |

## 5. Future multi-country operation

Since `Vendor` inherits the exact `franchise_id`/`zone_id` scoping every other 1CallFix entity already uses, multi-country operation requires no new mechanism — the existing `Country → City → Franchise → Zone` hierarchy (and Phase 22.1's own module-activation cascade sitting on top of it) already covers a Vendor the same way it covers a `Property` or a `Provider`.

## 6. What remains a genuine business decision (NOT resolved by this document)

This document answers **"what shape should a Vendor be"** — it does not and cannot answer **"should 1CallFix become a multi-vendor marketplace at all"** (`KNOWN_RISKS_AND_DECISIONS.md` item 20, unchanged). That is a real commercial/strategic decision this technical architecture document is not positioned to make, consistent with every other business-decision boundary this mission has respected throughout (pricing tiers, fare models, cancellation policies).

**BUSINESS DECISION REQUIRED:** Whether 1CallFix pursues Ecommerce/Food/Grocery/Pharmacy as real product verticals at all, and if so, in what order/timeline.
**EXACT DECISION NEEDED:** A go/no-go and sequencing call from the business.
**WHY CODE CANNOT SAFELY DECIDE:** This is a market-strategy decision with real commercial stakes, not a technical pattern question — exactly what the architecture question in §1–5 was scoped to avoid conflating with.
**SAFE TECHNICAL WORK COMPLETED:** The Vendor entity shape is now decided and evidence-based, ready to implement the day a real module is prioritized — no further architecture research is a prerequisite once that business call is made.

Also genuinely undecided, smaller in scope, logged for whoever eventually implements the first marketplace module:
- Multi-user staff access to a single Vendor account (no evidence found either way).
- Whether Food's menu-item catalog and Ecommerce/Grocery/Pharmacy's product-SKU catalog should share a base table with type-specific extension columns, or be fully separate tables — real Glover evidence shows Food's menu shape differs materially from a flat product list, but this is a catalog-table-design decision *within* whichever module is built first, not a prerequisite to deciding Vendor's own shape.

## 7. Recommended next step

Per `PHASE_22_5_REMAINING_MODULES_TRIAGE.md`'s own priority reasoning, this document removes the ARCHITECTURAL blocker for Ecommerce/Food/Grocery/Pharmacy — but the BUSINESS decision in §6 remains genuinely open, and per this mission's own stated priority order (Property Rental → Vendor Architecture Decision → Ecommerce → ...), the next concrete step is a human decision on §6, not further engineering. In the meantime, per "if Vendor architecture requires a genuine human decision, document it, then continue with independent work," the next technically-unblocked engineering work is a fresh evidence discovery pass on the remaining Rental sub-types (Vehicle/Self-Drive/Machine-Tools) and Hotel — both still genuinely evidence-blocked per the original Phase 22 audit, not architecture-blocked, and unaffected by this document's own findings.
