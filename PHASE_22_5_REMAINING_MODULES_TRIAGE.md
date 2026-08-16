# Phase 22.5 — Remaining Modules Triage (post-Parcel)

**Status: AUDIT ONLY, per the mission's own "AUDIT EXISTING CAPABILITIES → DESIGN ONLY WHAT IS MISSING" instruction, executed before implementing anything further.** No code changed. This determines, module by module, which of the remaining 8 modules have a genuine technical path forward right now (like Parcel did) versus which are blocked on a real, previously-identified business/architecture decision this mission's own discipline forbids guessing.

## Method

For each module: does building its core order/catalog schema require inventing a business entity or commercial rule with no current evidence — or does it, like Parcel, reuse existing architecture (FieldWorker/dispatch/Address/Wallet) with only *rates* left safely configurable-and-zero?

## Findings

| Module | Blocked? | Why |
|---|---|---|
| **E-Commerce** | **YES — foundational** | Requires a "Vendor" business entity (multi-item catalog, inventory, a business owning products) that does not exist anywhere in this schema. `KNOWN_RISKS_AND_DECISIONS.md` item 20 already named this precisely: "Building a Vendor/Menu/Product importer would mean inventing a new business entity and vertical first — a product/architecture decision." Nothing technical can proceed without deciding this first. |
| **Food Delivery** | **YES — same blocker** | Glover's own Food module reuses the identical generic `Vendor`/`Order` entity Ecommerce does (`PHASE_22_PLATFORM_CAPABILITY_RECOVERY_AUDIT.md §4b`) — same missing foundational entity, not a separate question. |
| **Grocery** | **YES — same blocker** | Same as Food — reuses Glover's shared Vendor/Order shape. |
| **Pharmacy** | **YES — same blocker, plus regulatory** | Same Vendor-entity blocker, compounded by prescription/restricted-product verification rules this mission's own brief explicitly forbids inventing ("do not invent regulatory rules"). |
| **Taxi / Ride** | **NO — technically approachable, same shape as Parcel** | `WorkerTypes::ALL` already seeds `taxi_driver` (Phase B0.1, unused until now, same as `parcel_rider` was before this phase). A `TaxiRide` table (own order, `FieldWorker` with `taxi_driver` capability, real-time dispatch reusing `RankingEngine`) needs no new business entity — only a fare model, which is a *rate*, not a foundational entity, and can be Setting-driven-and-zero exactly like Parcel's `base_fare`/`per_kg_rate` were. Real-time trip tracking (continuous GPS during an active ride) is a genuinely new capability neither Service nor Parcel needed — a real scope difference from Parcel, not a business-decision blocker. |
| **Hotel Booking** | **YES — but for a different reason: zero evidence, not a business decision** | `PHASE_22_PLATFORM_CAPABILITY_RECOVERY_AUDIT.md §5` already found this the least-evidenced module in the entire matrix — no usable reference in Glover, 6amMart, or this codebase for what a room/rate-plan/multi-night-stay model should even look like. Building schema here without any evidence to ground it would be inventing structure, not implementing a known one — the same discipline that stopped this audit from guessing Hotel Booking's capabilities in Phase 22.3 (`ModuleCapabilities::for('bookings')` is `null` throughout, deliberately). |
| **Rental — Property (9A)** | **NO — technically approachable, real reference exists** | Glover's own `Property`/`PropertyType`/`PropertyFee`/`PropertyAvailability` (`PHASE_22_PLATFORM_CAPABILITY_RECOVERY_AUDIT.md §4b`) is real, evidenced schema this audit can follow the same way Service/Parcel's own column shapes were followed — a genuine second candidate for the next technical phase. |
| **Rental — Vehicle/Self-drive/Machine-tools (9B–9D)** | **YES — zero reference evidence anywhere** | Confirmed in the original Phase 22 audit: no usable source in Glover, 6amMart's admin panel, or the (diff-only, unreadable) car-rental addon. Same "would be inventing, not implementing" reasoning as Hotel Booking. |

## Conclusion

Of the 8 remaining modules, **6 are genuinely blocked** — 4 on the same real, previously-identified business/architecture decision (multi-vendor marketplace + Vendor entity), 2 on a genuine absence of any evidence to build from (not a business decision, but the same "don't invent" discipline applies). **2 have a real, evidence-based technical path forward right now**: Taxi (reusing the FieldWorker/dispatch pattern this phase just proved out with Parcel) and Rental — Property specifically (real Glover schema precedent).

Per the mission's own instruction ("If only ONE module is blocked: continue with other modules"), Taxi is the correct next module to build with the same rigor as Parcel — it is the most direct continuation of the exact pattern just validated (FieldWorker capability + dispatch + Setting-driven zero-default rates), whereas Rental — Property is a materially different domain (calendar/availability-based booking, not dispatch-based) that would be a genuinely fresh design exercise, not a second data point for the same pattern.

**BUSINESS DECISION REQUIRED (blocks Ecommerce/Food/Grocery/Pharmacy entirely):** Whether 1CallFix becomes a multi-vendor marketplace at all, and if so, what a "Vendor" business entity means relative to the existing `Provider`/`Franchise` concepts.
**EXACT DECISION NEEDED:** A yes/no on the marketplace model, and — if yes — the real business shape of Vendor (owns a catalog? owns inventory? franchise-scoped or platform-wide?).
**WHY CODE CANNOT SAFELY DECIDE:** This is a new business-entity/vertical decision, not a rate or a technical pattern — inventing it would mean building real schema/screens around a guessed answer, the exact class of mistake this mission's own discipline exists to prevent.
**SAFE TECHNICAL WORK COMPLETED:** None needed yet — nothing about these 4 modules can start safely before this decision, unlike Parcel/Taxi where only rates were open.
**BLOCKED COMPONENT:** All of Ecommerce, Food, Grocery, Pharmacy.

**BUSINESS DECISION REQUIRED (Hotel, Rental 9B–9D):** None — these are blocked on absent reference evidence, not a commercial decision. Building them would mean designing schema from zero precedent, which is a legitimate future task but a materially different (and materially riskier) kind of work than anything this mission has done so far, best undertaken as its own deliberately-scoped design effort rather than folded into this continuation.

## Next technical phase

**Phase 22.6 — Taxi**, following the same audit → design → implement → test → document → commit discipline this phase (Parcel) just established.

**Status update: Phase 22.6 (Taxi) is now implemented** — see `CURRENT_MASTER_CHECKPOINT.md` commit-log entry for the full record. It validated this triage's own prediction: the FieldWorker/dispatch/Setting-driven-zero-rate pattern reused cleanly (`taxi_driver` capability, already seeded; `dispatch_attempts`' polymorphic columns Parcel added were directly reusable with zero further schema change; `CommissionService`/`CancellationService` gained real, evidence-based shared private helpers now that a genuine third data point — Booking/Parcel/Taxi — existed for the field-worker-commission-split logic specifically). Remaining triage conclusions (Ecommerce/Food/Grocery/Pharmacy blocked on the Vendor-entity decision; Hotel/Rental 9B–9D blocked on absent reference evidence; Rental — Property technically approachable but a materially different domain) are unchanged.
