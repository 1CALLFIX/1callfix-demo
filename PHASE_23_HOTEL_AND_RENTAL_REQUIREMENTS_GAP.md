# Phase 23 — Hotel / Car Rental / Self-Drive Rental / Machine-Tools Rental: Requirements Gap

**Status: EVIDENCE DISCOVERY ONLY. No speculative schema/behavior built for any of the four modules below.** See `PHASE_23_RESERVATION_ARCHITECTURE.md §2` for the full evidence trail this document summarizes per-vertical.

This consolidates what Phase 22.9 found for Vehicle/Self-Drive/Machine-Tools and Hotel, plus one new independently-checked source (this codebase's own historical `DB_1cal_app_1.8.10.sql` production dump), into the per-vertical gap format the mission's own Phase 23.6 instruction specified for Equipment. Extended here to all four since the evidence situation is now confirmed identical.

---

## Hotel Booking

**Known evidence:** None. Zero matches for `hotel`/`room_type`/`room_booking` in Glover 1.8.5, 6amMart 4.0.1, or the historical 1CallFix production dump.

**Closest real precedent:** Property Rental's own schema (Phase 22.7) — calendar/availability (`property_availabilities`, sparse-default, unique-constraint-backed), check-in/out, guest counts, per-night pricing with per-date overrides. Structurally the nearest thing in this codebase, but NOT simply reusable as-is: a hotel's real domain differs in ways no evidence here can responsibly guess — multi-room-type inventory per property (one hotel, many bookable room *types*, each with its own count of physical units), rate plans (refundable vs. non-refundable pricing tiers for the identical room), and cancellation-by-rate-plan rather than one policy per listing.

**Missing domain rules (all genuinely undecided, not invented):**
- Room-type vs. room-unit inventory model (is a "room" a bookable unit, or is a "room type" the bookable unit with N interchangeable physical rooms behind it?).
- Rate-plan structure (single price per room-type, or multiple rate plans with different cancellation/payment terms for the same physical room?).
- Whether check-in/out times, amenities, and cancellation policy are per-property or per-room-type.
- Guest-count-to-room-type capacity matching rules.

**Recovery decision:** Not built. Real product requirements (either a direct specification or a genuinely new reference source — Glover/6amMart/the historical dump are now exhausted for this term set) are required before any schema decision.

**Reusable infrastructure already available the day this is prioritized:** `Orderable` contract, `WalletService`, `CommissionService`'s `applyForFieldWorkerOrder()`-shaped pattern (now proven across FieldWorker/Provider/four implementers), `AuthorizationService::scopeQuery()`, `ModuleActivationService`, `ChannelResolver` notifications, Property Rental's own `PropertyAvailabilityService` concurrency pattern (lock existing rows + a DB unique constraint as the real race backstop) as a directly-applicable template once the room-type-vs-room-unit question above is answered.

---

## Car Rental (rental inventory, not the taxi-driver's own vehicle)

**Known evidence:** None for rental inventory. Glover's `Vehicle`/`VehicleType`/`CarMake`/`CarModel` and the historical dump's `vehicles`/`vehicle_types`/`car_makes`/`car_models`/`fleet_vehicle` are all the **same real-world concept**: a taxi driver's own car profile (`vehicles.driver_id`, no `renter_id`, no availability/calendar table, no per-day/per-trip pricing column, a `verified` admin-approval flag) — already-implemented Taxi territory (Phase 22.6), confirmed by direct schema read in both sources, not assumed from the model name alone.

**This is an important disambiguation, not evidence FOR rental:** it would be a real mistake to reuse these tables for rental inventory just because the names overlap — doing so would silently corrupt the taxi-driver-profile data model (a "rental vehicle" is not owned/driven by the person renting it the way `vehicles.driver_id` implies).

**Missing domain rules (all genuinely undecided):**
- Deposit handling (amount, hold-vs-charge mechanism).
- Driver's-license verification requirement and process.
- Mileage/fuel policy.
- Insurance requirements.
- Pickup/return location model (single depot vs. multi-location fleet).

**Recovery decision:** Not built. Genuinely new evidence or a direct specification is required — none of the mission brief's own listed concepts (rental vehicle, owner/provider, availability, pricing, reservation, pickup, return) have a real schema anywhere in this project's reference material.

**Reusable infrastructure already available:** Same list as Hotel above, plus the fact that `Vehicle`/`CarMake`/`CarModel` (Taxi's own, Phase 22.6) should NOT be extended or reused for this — a fresh, dedicated rental-inventory table is the correct shape once real requirements exist, per the same "own dedicated table, no shared wide table" precedent Phase 22.2's Order Engine decision already established for every other vertical.

---

## Self-Drive Car Rental

**Known evidence:** None, same search as Car Rental above — self-drive is a policy variant of the same underlying "reservable vehicle inventory" concept Car Rental needs, and that concept itself has zero reference evidence.

**Missing domain rules:** Everything Car Rental is missing, plus self-drive-specific eligibility (minimum age, license class, driving-history checks) — none of which appear anywhere in Glover, 6amMart, or the historical dump.

**Recovery decision:** Not built; explicitly deferred behind the same module-disabled gate as Car Rental. Per the mission's own instruction, this module should stay disabled until commercial/legal eligibility requirements are settled by the business, independent of whether the underlying vehicle-reservation infrastructure ever gets built for Car Rental first.

---

## Machine / Tools / Equipment Rental

**Known evidence:** None. Zero matches for `machine`/`tool`/`equipment` in Glover's model directory (confirmed twice — original Phase 22 audit and Phase 22.9's re-audit) or 6amMart's, and zero matches in the historical dump's table names either (checked this session).

**Missing domain rules:** The entire product category is undefined — not even a rough real-world shape (rental unit types, condition/damage tracking, deposit, rental-duration pricing tiers) exists in any reference material this project has access to.

**Recovery decision:** Not built, and no partial schema was started — there is nothing evidence-based to start from. This is the weakest-evidence vertical of the four, consistent with the mission brief's own framing.

**Reusable infrastructure already available the day real requirements arrive:** identical list to the other three — nothing Equipment-specific needs to be pre-built, since the domain model itself is the entire open question.

---

## Common recovery path for all four

1. A human supplies real product requirements (even informally — a description of the intended booking flow, inventory shape, and policy terms) for whichever vertical is prioritized first, OR points to a reference source not yet checked (Glover 1.8.5, 6amMart 4.0.1, and this codebase's own historical `DB_1cal_app_1.8.10.sql` are now all exhausted for these search terms).
2. The module slug already exists (`App\Support\Modules::ALL`, `is_implemented = false`) — no schema work is needed to make the module "exist" in the activation system; only real domain tables are missing.
3. Property Rental (Phase 22.7, hardened Phase 23.1) is the direct architectural template for whichever vertical goes first — same discipline: read real evidence, build the real thing, don't force it through the dispatch engine, don't invent policy values.
4. Per `PHASE_23_RESERVATION_ARCHITECTURE.md §5`, no generic `ReservationEngine` abstraction should be extracted until a SECOND real Family-B implementer exists to compare against Property Rental — building one now, with a single implementer, would be premature regardless of which of these four goes first.

*Last updated: 2026-08-17, Phase 23 (this session) — consolidates Phase 22.9's findings with one new independently-checked source (the historical `DB_1cal_app_1.8.10.sql` dump) confirming the same zero-evidence conclusion for all four verticals a second, independent way.*
