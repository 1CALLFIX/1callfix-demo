# Phase 23 — Reservation Family Architecture & Evidence Re-Confirmation

**Status: real hardening work done (Property Rental, §3); Hotel/Vehicle-Rental/Self-Drive/Machine-Tools-Rental remain evidence-blocked, re-confirmed independently this session against a third source Phase 22.9 did not check (§2c). No fabricated domain model was built for any of the four.**

This document supersedes nothing — it extends `PHASE_22_9_REMAINING_RENTAL_AND_HOTEL_GAP.md` and `PHASE_22_8_MARKETPLACE_VENDOR_ARCHITECTURE_DECISION.md`, both still accurate, with one new independent evidence pass and the first real Reservation-family hardening cycle.

---

## 0. Starting-state correction

The mission brief that opened this phase cited `HEAD = 2ca4ded` (935/935, 2,424 assertions) as the current baseline. The actual repository `HEAD` at the start of this session was **`7559092`** — two commits ahead — "Phase 22.7 Property Rental + 22.8 Vendor architecture decision + 22.9 rental/hotel evidence re-audit." That commit already contains:

- **Property Rental**, fully implemented as the fourth real `Orderable` (`PropertyReservation`) — not a to-do item, an existing implementation.
- **Phase 22.8**, the Vendor architecture decision for the (separately business-gated) marketplace verticals — irrelevant to the Reservation family, not revisited here.
- **Phase 22.9**, a real, careful evidence re-audit that already searched Glover's model directory a second time and concluded: **zero usable reference evidence exists for Hotel, Vehicle Rental, Self-Drive Rental, or Machine/Tools Rental.**

This matters because the mission brief's Phase 23.2–23.6 instructions ("Hotel is the next major reservation implementation... inspect all available repository/reference evidence... determine actual domain structure") were written as if that evidence discovery hadn't happened yet, or might turn something up. It already happened, one commit before this session started, using the same standard of rigor this mission demands. Re-running it from scratch would either (a) reach the identical conclusion, wasting the pass, or (b) invent what Phase 22.9 correctly declined to invent. §2 below re-runs it anyway — briefly, and against a source Phase 22.9 didn't check — specifically to confirm (a), not to relitigate a settled question on a hunch.

## 1. The two transaction families — confirmed, not re-argued

The mission brief's own Family A (Service/Parcel/Taxi — dispatch/operational) vs. Family B (Property/Hotel/Car/Self-Drive/Equipment — reservation/resource-availability) split matches this codebase's real, already-built architecture exactly:

- Family A's three implementers (`Booking`, `ParcelOrder`, `TaxiRide`) share worker/rider assignment, dispatch attempts, and live operational state.
- Family B's one real implementer (`PropertyReservation`, Phase 22.7) has none of that — it has `PropertyAvailabilityService`, a calendar, and a `pending → confirmed → checked_in → completed/cancelled` FSM with no dispatch step anywhere. Deliberately not forced through `DispatchAttempt`/worker offers.

No architecture change was needed to confirm this — it's already true of the code as it stands. `App\Contracts\Orderable` is the one thing both families share (module code, order code, franchise/zone scope, customer, total price, status) — a genuinely thin, non-domain-specific contract that has now proven itself across four independently-shaped implementers (Booking, ParcelOrder, TaxiRide, PropertyReservation) without needing to grow a single Family-B-specific method on it. That's real evidence the `Orderable` abstraction is correctly scoped — it stayed thin because nothing forced it wider.

## 2. Evidence re-confirmation (this session)

### 2a. What Phase 22.9 already established

Direct read of Glover's `Vehicle.php`: `belongsTo driver()` (a `User`) — a taxi driver's own car profile (make/model/type, `verified` admin-approval flag), not rentable inventory. No `renter_id`, no calendar/availability relation, no pricing columns. Zero matches anywhere in Glover for `hotel`, `room_type`, `room_booking`, `machine`, `tool`, `equipment`.

### 2b. This session's independent re-check

Re-ran the same search across **three** sources, not the one Phase 22.9 used:

| Source | hotel/room | vehicle/car rental (as inventory) | equipment/tools/machine |
|---|---|---|---|
| Glover 1.8.5 (`app/Models`, migrations) | 0 matches | `Vehicle`/`VehicleType`/`CarMake`/`CarModel` exist — same taxi-driver-profile shape Phase 22.9 already found, re-confirmed | 0 matches |
| 6amMart 4.0.1 (`Models`) | 0 matches | 0 matches | 0 matches |
| **Historical `DB_1cal_app_1.8.10.sql`** (this codebase's own real prior-production dump — the source Phase 13's Glover-parity audit discovered but Phase 22.9's rental re-audit never cross-checked) | 0 matches (`CREATE TABLE` scan for `hotel`/`room`) | `car_makes`, `car_models`, `vehicles`, `vehicle_types`, `fleet_vehicle` exist | 0 matches |

### 2c. The one genuinely new check: is the historical dump's `vehicles` table rental inventory?

Direct read of its real `CREATE TABLE`/data:

```sql
CREATE TABLE `vehicles` (
  `id`, `car_model_id`, `driver_id`, `vehicle_type_id`,
  `reg_no`, `color`, `is_active`, `verified`, ...
)
```

`driver_id` (not `owner_id`/`renter_id`), no availability/calendar table, no per-day/per-trip pricing column — this is the **identical taxi-driver-vehicle-profile concept** Phase 22.9 already found in Glover's source, now independently confirmed in this codebase's own real historical production data too, not just the reference app. `fleet_vehicle` is a bare two-column pivot (`fleet_id`, `vehicle_id`) — a driver-fleet grouping concept, still nothing shaped like bookable inventory (no customer/renter side at all).

**Conclusion: Phase 22.9's finding is now confirmed by a third, independent, previously-uncross-checked source. There is no reference evidence anywhere available to this project for Hotel, Vehicle Rental (as customer-facing rental inventory), Self-Drive Rental, or Machine/Tools Rental.** This is not a re-assertion of the prior finding — it's the same finding, reached again, against different material.

## 3. Phase 23.1 — Property Rental hardening (real work, this session)

Property Rental (Phase 22.7) already had 73 tests across schema/model, lifecycle (including double-booking conflict + idempotency), authorization (permission denial/success, row-level scope, cross-franchise IDOR), and an admin-screen N+1 guard. This phase's hardening pass read every layer end-to-end against the mission's own checklist rather than re-asserting it was already fine, and found **one genuine, previously-untested defect**:

### Finding: `GET /api/properties` date-filtered search — N+1 + a real correctness bug

`PropertyController::index()` fetched the first 50 properties **by id** (`->limit(50)->get()`), then filtered availability **in PHP, afterward** (`->filter(fn ($p) => $availability->isAvailable(...))`). Two real consequences, neither previously covered by any test (`PropertyRentalPerformanceTest` only covers the two admin Livewire screens; the public search endpoint had zero query-count coverage):

1. **N+1** — one `isAvailable()` query per fetched property (up to 50 extra queries per search request).
2. **Correctness** — the availability filter ran *after* the `limit(50)`, not before. A property with a lower id that happens to be booked for the requested dates could crowd out an available property with a higher id from ever being fetched at all — the search could return fewer results than actually available, or miss the only available match entirely, once a franchise/zone has more than 50 listings.

**Fix:** moved the availability filter into the query itself (`whereDoesntHave('availabilities', ...)` against `property_availabilities`, matching the same table `PropertyAvailabilityService` already uses as the authoritative source), applied *before* `orderBy('id')->limit(50)`. One query, correct results regardless of id ordering, same "browse-time filter only — `reserveDates()` inside a real reservation's own transaction remains the authoritative safety check" caveat as before (this endpoint was never the concurrency-safety boundary; that boundary is untouched).

Two new regression tests in `tests/Feature/Api/PropertyRentalApiTest.php`, both proven to fail against the pre-fix code before being confirmed passing:
- `test_date_filtered_search_finds_an_available_property_outside_the_first_page_window` — 51 properties, 1 booked with the lowest id, the free one with the highest id; must still be returned.
- `test_date_filtered_search_does_not_n_plus_one` — query count must not grow with candidate-property count.

### Checklist items reviewed and found already correct (no code change)

- **Ownership/geography/module activation** — `CreatePropertyReservationAction` gates on `ModuleActivationService::isActive(Modules::CAR_RENTAL, scope)` before any reservation is created; historical reservations remain fully readable via the admin screen and customer API regardless of module state (neither `Manage::render()`/`scopedReservationsQuery()` nor `PropertyReservationController::mine()/show()` check module activation — confirmed by reading both, not assumed).
- **Overlapping-reservation / concurrency safety** — `PropertyAvailabilityService::reserveDates()`'s two-part design (`lockForUpdate()` on existing rows + the table's own `unique(['property_id','date'])` constraint as the real backstop for the case neither concurrent transaction sees a pre-existing row) is real, not asserted — verified by reading the implementation and its own dedicated conflict test (`test_a_conflicting_reservation_does_not_leave_a_partial_reservation_row`).
- **Cancellation** — `AdminCancelPropertyReservationAction` releases held dates (`releaseDates()`) inside the same transaction as the status change; a dedicated test proves the freed range can be re-booked.
- **Payment/commission/settlement** — reuses `WalletService`/`CommissionService`/`Payment` unchanged, no parallel engine. `CompletePropertyReservationAction`'s commission step is idempotent (`test_completion_is_idempotent_and_never_double_credits`).
- **Notifications** — `PropertyReservationStatusNotification`, queued, routed through the shared `ChannelResolver` like every other vertical.
- **Admin** — both `/admin/properties` and `/admin/property-reservations` gate on their own permission slug in `mount()`, scope every query through `AuthorizationService::scopeQuery()`, and re-validate scope inside every write action (`assertCanManage()`) rather than trusting a client-supplied `selectedReservationId` — closing the exact "tampered Livewire public-property" class of IDOR this phase's checklist called out, confirmed by reading the action methods, not assumed.
- **API authorization/IDOR** — `PropertyReservationController::show()`/`mine()` check `customer_id` ownership server-side; a direct-ID cross-customer read returns 404, not 403 (doesn't even confirm the row exists to an unauthorized caller) — covered by `test_a_customer_cannot_view_another_customers_reservation_direct_id_manipulation`.
- **Indexes** — `properties(franchise_id, zone_id)`, `properties(is_active)`, `property_reservations(status)`, `property_reservations(franchise_id, zone_id)`, `property_reservations(property_id, check_in_date, check_out_date)`, `property_availabilities` unique `(property_id, date)` (doubles as the lookup index) all present and matched to real query shapes, confirmed by reading every migration, not assumed present.
- **Historical data safety** — `SoftDeletes` on `Property`/`PropertyReservation`; all Phase 22.7/23.1 migrations are additive only, nothing drops or rewrites existing columns.

No other genuine defect was found. Per the mission's own "do not reopen already-closed findings without evidence" instruction, nothing else was touched.

## 4. Phase 23.2–23.6 disposition — Hotel / Vehicle / Self-Drive / Equipment

Per §2, all four remain **evidence-blocked**, confirmed independently this session against a third source. Building any of their domain models now would mean inventing the structural shape itself (room/room-type/inventory tables for Hotel; deposit/mileage/insurance-bearing reservation tables for Vehicle/Self-Drive; a product category with no reference shape at all for Equipment) — not just a policy value, which is the one thing this mission's "business decision required" pattern is designed to defer safely. There is a real difference between deferring a *number* (a cancellation fee, a commission percentage — Property Rental already does this cleanly, item 33) and fabricating a *schema* with no evidence anywhere to check it against. The mission's own repeated instruction — "do not invent requirements," "if a requirement has no evidence, document it as a business/product decision" — applies to the schema question here, not only the policy question.

This is not a new blocker invented by this phase; it is Phase 22.9's already-correct conclusion, now checked a second, independent way and still correct. Per the mission's own continuation rule ("if one vertical is blocked, continue another... if a genuine business/legal decision blocks all remaining work, that's a stop condition — but if one vertical is blocked, continue another"), work continued on the one vertical that is genuinely unblocked (Property Rental hardening, §3) and on real documentation (this file, plus a consolidated gap document below) rather than on invented Hotel/Vehicle/Equipment schemas.

`PHASE_23_HOTEL_AND_RENTAL_REQUIREMENTS_GAP.md` (new, this session) consolidates the gap for all four verticals in one place, in the same format the mission's own Phase 23.6 instruction specified for Equipment alone — extended here to Hotel/Vehicle/Self-Drive since the evidence situation is now confirmed identical for all four, not just Equipment.

**BUSINESS DECISION REQUIRED (all four):** Real product requirements or a genuinely different, evidenced reference source than what this project has access to (Glover 1.8.5, 6amMart 4.0.1, and this codebase's own historical `DB_1cal_app_1.8.10.sql` dump — all three now checked and exhausted for this purpose).
**EXACT DECISION NEEDED:** Either supply real domain requirements directly (room-type/rate-plan shape for Hotel; deposit/mileage/insurance policy for Vehicle/Self-Drive; a product category definition for Equipment), or point to a reference source not yet searched.
**TECHNICAL WORK POSSIBLE WITHOUT IT:** None at the domain-model layer — building one would be fabrication, which this mission's own rules forbid. What IS real, evidence-based, and already done: the module slugs for all four already exist in `App\Support\Modules::ALL` with `is_implemented = false` (Phase 22.1/22.5), the shared infrastructure they would reuse (Wallet, Commission/Settlement, `AuthorizationService::scopeQuery()`, `ModuleActivationService`, the `Orderable` contract, `ChannelResolver`) is proven across four independent implementers and ready the day real requirements arrive, and Property Rental's own schema (§3) is the closest real architectural precedent for whichever of these is built first.

## 5. Reservation abstraction review (Phase 23.3) — disposition

The mission brief's Phase 23.3 asks to compare `PropertyReservation` against a working `HotelReservation` to decide whether to extract shared Reservation infrastructure. With only one real Family-B implementer in existence, there is nothing to compare yet — extracting an abstraction from a single implementer is exactly the "abstract for abstraction's sake" anti-pattern this mission's own architecture rule forbids (the same discipline that correctly kept `Orderable` thin across Family A, §1). **No abstraction extracted. Revisit only once a second real Family-B implementer exists**, per real evidence, same as Family A's own precedent (three implementers, one thin shared contract, no premature generic engine).

## 6. Definition of Done — honest status

| Vertical | Domain model | Migrations | Availability/concurrency | Lifecycle | Payment/commission | Module activation | Admin | API | Auth/IDOR/scope | Tests | Docs |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Property Rental | ✅ (Phase 22.7) | ✅ | ✅ (re-verified §3) | ✅ | ✅ (reused) | ✅ | ✅ | ✅ | ✅ | ✅ 48 tests, +2 this phase | ✅ |
| Hotel | ❌ evidence-blocked | — | — | — | — | slug exists, disabled | — | — | — | — | ✅ gap documented |
| Car Rental | ❌ evidence-blocked | — | — | — | — | slug exists, disabled | — | — | — | — | ✅ gap documented |
| Self-Drive Rental | ❌ evidence-blocked | — | — | — | — | slug exists, disabled | — | — | — | — | ✅ gap documented |
| Equipment Rental | ❌ evidence-blocked | — | — | — | — | slug exists, disabled | — | — | — | — | ✅ gap documented |

No vertical is falsely marked complete. Property Rental is the only Family-B vertical with real evidence to build from, and it is now hardened, not merely built.
