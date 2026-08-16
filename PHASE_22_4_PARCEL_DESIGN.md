# Phase 22.4 — Parcel: Technical Design (design only, NOT implemented)

**Status: DESIGNED, not built.** This is the safest-reversible-technical-preparation step for the next real implementation phase — a concrete, evidence-based schema and reuse plan, so implementation can proceed directly next session without re-deriving it. Nothing in this document has been migrated, coded, or tested; no risk to any existing data or behavior.

Per Phase 22.2's decision, Parcel gets its own dedicated order table (`parcel_orders`), not a row in `bookings` — matching Glover's own real precedent of giving Taxi a separate order table rather than forcing it through a shared one.

## What's genuinely reusable today, verified by re-reading the actual code (not assumed)

| Engine | Reusable as-is? | Evidence |
|---|---|---|
| `WalletService` | **Yes, fully.** | Keyed to `User`, zero `Booking` coupling. |
| `FieldWorker`/`FieldWorkerCapability`/`PartnerWorker` | **Yes, fully.** | Already actor-generic (Phase B0.1), built explicitly anticipating Parcel. |
| `RankingEngine`/`RankingConfigResolver` | **Yes.** | Distance/rule-based ranking, no Service coupling. |
| `AuthorizationService` | **Yes, fully.** | Scope-generic. |
| `NotificationTemplate`/`OtpService`/adapters | **Yes, fully.** | Recipient/channel-generic. |
| `dispatch_attempts` (worker side) | **Yes** — `notifiable_type/id` polymorphic (Provider vs FieldWorker). | Phase 22.2's own correction to the original Phase 22 audit. |
| `dispatch_attempts` (order side) | **No** — `booking_id` is a hard FK to `bookings`. | Same correction. Parcel needs its own dispatch-ledger table, shaped identically, OR a future decision to polymorphize `booking_id` too (not undertaken here — a second schema decision, deliberately deferred rather than bundled into this one). |
| `CommissionService::applyForBooking(Booking $booking)` | **No, as written.** | Reads `$booking->price_final`/`$booking->provider`/`$booking->franchise->owner` by name. Needs a real second implementer (this phase) to inform whether it becomes `applyForOrder(Orderable $order)` or gets a parallel, narrower method — a decision for the actual implementation session, not guessed here. |
| `CreateBookingAction`'s module-activation guard pattern | **Directly reusable as a pattern, not as code.** | `CreateParcelOrderAction` should call `ModuleActivationService::isActive('parcel', ...)` the identical way, guaranteeing Parcel ships disabled by default (it already is — `modules.is_implemented` for `parcel` is `false`, Phase 22.1). |

## Proposed schema (design only — field list, not final migration SQL)

`parcel_orders`:
- `id`, `code` (own numbering via `OrderCodeService`, parcel-prefixed)
- `franchise_id`, `zone_id` (same convention as `bookings`)
- `customer_id`
- `pickup_address_id`, `dropoff_address_id` — **both FK to the existing `Address` model**, reused as-is (already franchise/zone/lat/lng-shaped, zero new address concept needed)
- `assigned_worker_id` (nullable FK to `field_workers`, mirrors `bookings.assigned_worker_id`)
- package details: `package_description`, `package_weight_kg` (nullable), `package_size` (enum: small/medium/large — a genuine business-policy question for real pricing tiers, left open)
- `status` enum: `pending, searching_worker, assigned, worker_en_route_pickup, picked_up, en_route_dropoff, delivered, cancelled, disputed` — deliberately mirrors `bookings.status`'s own shape/naming convention rather than inventing a different vocabulary, with pickup/dropoff as the two-sided analogue of Service's single provider-arrives-and-works model
- `price_quoted`, `price_final`, `payment_status`, `payment_method` — identical shape to `bookings`
- `pickup_otp`, `delivery_otp` — mirrors `bookings.start_otp`/`completion_otp` exactly (two OTPs instead of one, since Parcel has two customer-facing handoff events, not one)
- `cancellation_reason_id`, `cancellation_note`, `cancellation_fee`
- `completed_at`, timestamps, soft deletes

`parcel_order_status_history` — mirrors `booking_status_history` exactly, same reasoning as everywhere else in this design (proven shape, no invention).

`parcel_dispatch_attempts` — mirrors `dispatch_attempts`' shape (worker-side polymorphic from day one, learning from Phase 22.2's correction rather than repeating the original table's now-known limitation).

**`ParcelOrder implements Orderable`** — same zero-risk pattern as `Booking`, `moduleCode()` returns `'parcel'`.

## What this design deliberately leaves undecided (real business/product decisions, not engineering gaps)

- **Package size/weight pricing tiers** — no real numbers exist anywhere in this codebase's history or the Glover reference for this specific product's parcel pricing (Glover's own `PackageTypePricing` exists but its actual rates are Glover's, not 1CallFix's — not adoptable as a default per the mission's "do not invent business decisions" rule).
- **Whether a Parcel "worker" is a `FieldWorker` (platform-direct) or requires a `Provider`-style accountable business first** — `FieldWorker` already supports platform-direct workers (Phase B0.1), so this is architecturally ready either way; which one 1CallFix actually wants for Parcel specifically is unset.
- **Cancellation fee policy, distinct from Service's own configured cancellation policy** — `CancellationPolicy`/`CancellationReason` exist and are reusable as tables, but Parcel-specific fee amounts are not.

## Recommended next implementation session's order of work

1. Migrations for the three new tables above (purely additive, zero risk to `bookings` or anything referencing it).
2. `ParcelOrder implements Orderable`.
3. Real design decision (informed by this table now existing): does `CommissionService`/`WalletService`'s Booking-coupling get generalized to `Orderable`, or does Parcel get a thin, explicit `ParcelCommissionService` that calls the same underlying `WalletService::credit()`/`debit()` primitives directly? Both are legitimate; deciding requires looking at the actual shape of the resulting code, not guessing from a design doc.
4. `CreateParcelOrderAction`, gated by `ModuleActivationService::isActive('parcel', ...)` (already false everywhere — ships disabled automatically, no extra step needed).
5. Dispatch integration reusing `RankingEngine` + the new `parcel_dispatch_attempts` table.
6. Tests, at the same density this mission has held throughout (lifecycle, authorization, row-scope, edge cases).
7. Admin screen (`ParcelOrders\Manage` or similar) once the backend is proven.
8. `modules.is_implemented` for `parcel` flips to `true` only once all of the above is real and tested — per Phase 22.1's own hard gate, this is the literal switch that turns Parcel from "registered" to "operational," and it should be the last step, not an early one.
