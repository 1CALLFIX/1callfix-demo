# Phase 22.2 — Generalized Order Engine Architecture Decision

**Status: DECIDED + minimal safe technical preparation implemented. No existing table modified. No existing behavior changed.**

This is the architectural decision `PHASE_22_PLATFORM_CAPABILITY_RECOVERY_AUDIT.md §7/§17` identified as the one real blocker to multi-vertical reuse: `bookings.service_id` is a hard, non-nullable, non-polymorphic FK — `Booking` *is* the Service vertical's order table, not a generic Order abstraction. Every future vertical (Parcel onward) needs this resolved first.

## The three options, evaluated against this specific codebase

### Option A — Keep `Booking` Service-specific; separate domain order tables per vertical (`FoodOrder`, `TaxiRide`, `HotelBooking`, `RentalBooking`, `ParcelOrder`, ...), reusing central engines via a shared contract, not a shared table.

- **Migration complexity:** LOWEST. Zero changes to `bookings` or anything that references it (`commissions`, `payments`, `wallet_transactions`, `dispatch_attempts`, `booking_status_history`, `chat_messages`, `reviews`, `cancellation_reasons` usage, KYC-linked references — a genuinely long list, confirmed by grep, all currently FK to `bookings.id`). Nothing here is touched.
- **Existing Booking compatibility:** PERFECT — literally unchanged.
- **Order numbering:** `OrderCodeService`/`BookingSequence` already generate a code from inputs (franchise, year) rather than being hard-wired to the `bookings` table itself — reusable per-vertical with a prefix parameter, whenever a second table needs one.
- **Lifecycle/state machine:** Each vertical gets its own FSM, matching Glover's own real precedent (Taxi got a dedicated `TaxiOrder`, not a shared `Order` row — `PHASE_22_PLATFORM_CAPABILITY_RECOVERY_AUDIT.md §4b`) and this codebase's own typed-column convention (`Service.base_price`/`discount_price`/`duration_estimate_mins` as real columns, never a JSON blob) — a taxi fare-meter field, a hotel room/nights/guest count, and a rental deposit amount are different enough concepts that forcing them into one shared table means either a wide mostly-null table or a JSON escape hatch, neither of which matches how this codebase already models data.
- **Payments/wallet/commissions/settlement/dispatch/notifications:** These services (`CommissionService::applyForBooking(Booking $booking)`, `WalletService`, `DispatchService::findCandidates(Booking $booking)`, `NotificationCenter`) are **concretely coupled to `Booking`'s specific columns today** — verified directly (`CommissionService::applyForBooking()` reads `$booking->price_final`, `$booking->provider`, `$booking->franchise->owner` by name, not through any abstraction). Reusing them for a second vertical requires each of these to accept an interface instead of the concrete class — real work, but **deferred work, not blocked work**: nothing about Option A requires doing this refactor now, with no second real vertical's requirements yet to design the interface against.
- **Reporting/exports/admin screens/API:** Matches this codebase's existing pattern of one dedicated screen per concern (33 distinct Livewire screens today, not one generic screen doing everything) — each new vertical gets its own admin screen(s) when built, same as every vertical built so far.
- **Future scalability:** No shared-table lock contention or column-bloat risk as verticals are added.
- **Data preservation:** Nothing about existing data is ever touched by adding a new, unrelated table.

### Option B — Introduce a generalized `Order` aggregate while preserving Booking compatibility (one shared parent/envelope row every vertical's detail table hangs off, `bookings.order_id` becomes an additive nullable FK to it).

- **Migration complexity:** MEDIUM if done additively (new `orders` table, new nullable `bookings.order_id`, backfill one `orders` row per existing booking) — this is the shape `PHASE_22_PLATFORM_CAPABILITY_RECOVERY_AUDIT.md §8` rated MEDIUM, contingent on staying additive. HIGH if ever done by *replacing* `bookings.id` as the FK target other tables use — that would mean touching `commissions.booking_id`, `payments.booking_id`, `dispatch_attempts`, `booking_status_history`, `chat_messages`, `reviews`, and every test/fixture that references any of them. This audit does not recommend ever taking that HIGH path.
- **Existing Booking compatibility:** Good if additive-only (new nullable column, nothing removed) — but every one of `commissions`/`payments`/`wallet_transactions`/`dispatch_attempts` etc. would need its own new nullable `order_id` too for a future vertical to actually benefit from the shared envelope, which is the same "touch a long list of tables" cost as Option A's eventual interface-refactor, just spent on schema instead of PHP interfaces.
- **Central engine reuse:** The strongest argument for B — Wallet/Commission/Dispatch/Notification could, in principle, all key off one `orders.id` uniformly. But this benefit is **not free**: those services are coupled to `Booking`-specific columns today (see Option A's finding above) regardless of which table their foreign key points at — B does not avoid the interface/contract refactor A also needs, it just adds a schema layer on top of it.
- **Data preservation:** Additive-only version is real and safe; the temptation to eventually "finish the job" by repointing every FK is where the real risk lives, and that temptation is structural to this option, not incidental.

### Option C — Evidence-supported hybrid: reuse Option A's separate-order-tables shape, but extract the genuinely-shared fields (a lightweight `Orderable` contract, not a shared table) that central engines can code against once, informed by real requirements the moment a second vertical actually needs them.

## Decision: **Option A, prepared via a lightweight `Orderable` contract (no new order table, no schema change to any existing table).**

Reasoning, directly from the evaluation above:
1. **Zero risk to existing production data** — the non-negotiable principle #1 ("current production data is sacred") is best served by an option that touches nothing about `bookings` or anything referencing it, and Option A is the only one of the three with that property unconditionally, not just in its additive-best-case.
2. **Matches real precedent** — Glover itself (§4b of the Phase 22 audit) separated Taxi into its own order table rather than forcing it through the shared `Order` used by Food/Grocery/Pharmacy/Ecommerce; this is direct evidence from the reference codebase this mission is explicitly allowed to take capability precedent from.
3. **Matches this codebase's own existing convention** — every vertical-shaped concept so far (`Service`, `Plan`, `FieldWorker`, `Provider`) is a dedicated, strongly-typed table, never a shared polymorphic mega-table with per-type nullable columns or JSON payloads.
4. **The central-engine coupling problem is real either way** (Option A and B both eventually need it solved) — Option A just doesn't force solving it speculatively, before a second vertical's real field requirements exist to design the interface against. Building `CommissionService::applyForOrder(Orderable $order)` today, informed by zero real second implementers, would be exactly the kind of premature abstraction the mission brief's own "create reusable abstractions only where real repetition exists" principle (Phase 22.3) warns against.

**This is a technical architecture decision, not a business decision** — no human product call is required to proceed (unlike, say, whether 1CallFix becomes a multi-vendor marketplace at all, which remains open per `KNOWN_RISKS_AND_DECISIONS.md`/Phase 22 audit §14).

## What was actually built this phase (minimal, safe, reversible)

- **`App\Contracts\Orderable`** — a genuinely minimal interface (order code, module code, franchise/zone/customer ids, total price, current status: the handful of facts `AuthorizationService::scopeQuery()`, `TimezoneResolver`, and the admin screens' list views actually read across every existing order-shaped model, verified by inspection, not guessed). **`Booking` now implements it** — every method is a one-line delegation to an existing column/accessor, zero behavior change, zero new query, safe to ship today.
- **Deliberately NOT done:** no refactor of `CommissionService`/`WalletService`/`DispatchService`/`NotificationCenter` to accept `Orderable` instead of `Booking` — there is no second implementer yet to prove that interface's shape against, and guessing it now risks designing the wrong contract that Parcel (Phase 22.4) would then have to work around rather than validate. This is the "safest reversible technical preparation" the mission brief calls for when a step shouldn't be forced ahead of its real dependency.
- **No new table. No migration. No schema change.** This phase is a pure PHP-interface addition.

## Next step

Phase 22.4 (Parcel) is where `Orderable` gets its second real implementer, and where `CommissionService`/`WalletService`/`DispatchService`'s actual interface needs become knowable from real requirements instead of guessed.
