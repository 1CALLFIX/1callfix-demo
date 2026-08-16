# Phase 22.4 — Parcel: Implementation Record

**Status: CORE LIFECYCLE IMPLEMENTED AND TESTED. Ships fully disabled** (`modules.is_implemented` for `parcel` remains `false` — deliberately not flipped by this phase, per Phase 22.1's own hard gate and this document's own "last step, not an early one" instruction). This document originally shipped as a design-only deliverable; this revision replaces it with what was actually built, including the real deviations from the original design found while building it — the second real `Orderable` implementer this mission always intended Parcel to be.

---

## What changed from the original design, and why

The original design proposed a fully separate `parcel_dispatch_attempts`/`parcel_commissions` shadow-table family. Building the real thing found a better, evidence-based answer for two of those three tables:

- **Dispatch**: `dispatch_attempts.notifiable_type`/`notifiable_id` (Phase B0.3, worker side) turned out to be real, tested, and directly reusable for a `FieldWorker` candidate — confirmed by re-reading `RankingEngine` itself, not assumed. Rather than a separate table, this phase added the ORDER-side polymorphic counterpart (`dispatchable_type`/`dispatchable_id`) to the SAME `dispatch_attempts` table, loosening `booking_id`/`provider_id` to nullable (both purely additive — every existing row already has real values for both). `DispatchService::findCandidates()` (Service) is byte-for-byte unchanged; the new `findWorkerCandidates()` (Parcel) is an additive sibling method.
- **Commission**: `commissions` already had precedent for "plain nullable FK per concrete type, not full polymorphism" — confirmed by `payments.plan_subscription_id`'s own existing pattern. Added `commissions.parcel_order_id` (nullable, unique) the same way, rather than a separate table.
- **Status history**: the original design's instinct — a dedicated `parcel_order_status_history` table, not a change to `booking_status_history` — held up and was built exactly as designed. This one genuinely didn't need generalizing; it's a simple, cheap-to-duplicate, single-purpose table.
- **Order numbering**: built exactly as designed (`parcel_order_sequences`, its own atomic counter, `-PCL-` in the code format) — `OrderCodeService` was generalized by extracting its one genuinely shared piece (the atomic per-franchise-per-day increment) into a private helper, with the code FORMAT itself staying a decision each public method makes independently.
- **Payment**: `payments` already had an established purpose-discriminator + purpose-specific-nullable-FK pattern (`purpose='wallet_topup'` → `user_id`, `purpose='plan_subscription'` → `plan_subscription_id`). Added `'parcel_order'` as a fourth purpose value + `parcel_order_id`, following that exact precedent.
- **Worker actor model** — the design doc originally flagged this as an open business decision. Building the real thing found it isn't one: `App\Support\WorkerTypes::ALL` already includes a seeded `parcel_rider` capability type (Phase B0.1), and `dispatch_attempts.notifiable_type/id`'s own migration comment explicitly named "platform-direct dispatch (future Parcel/Taxi)" as its reason for existing. This is architectural precedent already committed to, not a new decision — Parcel workers are `FieldWorker`s holding the `parcel_rider` capability, exactly as the existing codebase's own comments anticipated.

## What stayed genuinely separate, and why

- **`parcel_orders`** — its own table, exactly as designed (Phase 22.2's Option A decision). `bookings.service_id` was never touched.
- **`ParcelDispatchJob`** — a separate class from `ServiceMatchingJob`, not a generalized "OrderMatchingJob." The two jobs share a SHAPE (round-limited, row-locked, self-requeuing) but operate on structurally different models/events/Setting namespaces throughout — forcing one shared class would mean either a large parameter surface or runtime type-checking scattered through the body, the exact "generalize because names are similar" trap this mission's own instructions warn against.
- **`CommissionService::applyForParcelOrder()`** — a sibling method to `applyForBooking()`, not a generalized `applyForOrder(Orderable)`. The two share their real split-calculate-credit logic in shape, but Parcel has no Plan-Entitlement rate-override concept (FieldWorkers don't hold Provider entitlements) — threading a permanently-null parameter through a "generalized" signature would be worse than two honest, slightly-overlapping methods.
- **`CancellationService`** — DID get a genuinely shared private helper (`calculateFeeGeneric()`) for the one calculation that's identical for both verticals (free-window + flat/percent fee), while `refundIfPaid()`/`refundIfPaidForParcelOrder()` stayed separate (different notification classes, different Payment-purpose lookups).
- **`ParcelOrderStatusNotification`** — a new class, not a generalized `BookingStatusNotification`. Combines what `BookingStatusNotification` and `PaymentStatusNotification` separately cover for Service into one class, since Parcel's notification surface is smaller.

## What was deliberately NOT built this phase (real, honest scope boundaries)

- **Live Razorpay ('online') payment capture for Parcel.** `PaymentController::createOrder()`/`confirm()` are hard-typed to `Booking` today. `CreateParcelOrderAction` accepts `payment_method: 'online'` and stores it, but nothing captures a real gateway payment for it yet — **this is not a Parcel-specific shortfall**: Service's own `CreateBookingAction` doesn't handle 'online' payment capture inline either; that's always been a separate two-step flow (`PaymentController::createOrder()` then `confirm()`, called by a customer app that doesn't exist in this repository — `PROJECT_CURRENT_STATE.md §21`). `payment_method: 'wallet'` is fully implemented and tested end-to-end (debit, `Payment` row, order marked paid). `payment_method: 'cash'` needs no gateway and works as a stored value, same as Service.
- **Customer-facing REST API for order creation.** Confirmed by reading `routes/api.php` directly: **no such endpoint exists for Service bookings either** — `Bookings\Index::createBooking()` (an admin/operator Livewire action) is the only real order-creation entry point in this entire codebase, consistent with 1CallFix's real call-center-driven business model (the product's own name). Parcel's admin creation panel (`ParcelOrders\Manage`) mirrors this exact precedent rather than inventing a customer API surface Service itself doesn't have.
- **Chat for Parcel.** `chat_messages.booking_id` is a hard, non-nullable FK — confirmed by reading its migration directly. Per this document's own original instruction ("do not force Parcel into Booking simply to reuse chat... document the limitation"), Parcel chat was not built. No schema change was made to `chat_messages`.
- **Finer-grained tracking states.** `worker_en_route_pickup`/`en_route_dropoff` are real enum values on `parcel_orders.status` but have no dedicated transition action in this slice — `picked_up` is directly reachable from `assigned`, `delivered` from `picked_up`. A future rider-app increment can add lightweight status-update actions for these without any schema change.
- **Loyalty/referral integration for Parcel deliveries.** No evidence-backed decision exists that a delivered parcel should earn loyalty points or qualify a referral the way a completed Service booking does (`KNOWN_RISKS_AND_DECISIONS.md` items 1/2 are Service/Customer-referral-specific) — extending them would be inventing a new business rule, not implementing an existing one.
- **Package pricing tiers.** `parcel.base_fare`/`parcel.per_kg_rate` Settings exist, default to `0`/`0` (the same "no invented values" discipline as every compensation/tip rate in this codebase) — a real commercial pricing model remains an open business decision.

## Business decisions still required (not invented, not guessed)

**BUSINESS DECISION REQUIRED:** Real parcel pricing model (base fare, per-kg rate, size-tier multipliers).
**EXACT VALUE/CHOICE NEEDED:** `parcel.base_fare`, `parcel.per_kg_rate` real currency values (and whether `package_size` should carry its own multiplier).
**WHY CODE CANNOT SAFELY DECIDE:** These are commercial values with real revenue impact, no different in kind from the tip/compensation rates this codebase has always left at a safe `0` default until a human sets them.
**SAFE TECHNICAL WORK COMPLETED:** The configurable infrastructure is real, tested, and Setting-scope-cascaded (`CreateParcelOrderAction::quote()`) — a real value can be set today with zero code change.
**BLOCKED COMPONENT:** Nothing is blocked — every rate defaults to 0, exactly matching this mission's own "deploy disabled first" philosophy at the pricing layer too, independent of the module-activation gate.

## Data migration safety (verified, not just claimed)

All 7 migrations in this phase are additive:
- 3 new tables (`parcel_orders`, `parcel_order_status_history`, `parcel_order_sequences`) — no existing table touched.
- `commissions`/`payments`/`dispatch_attempts` each gained new nullable columns, and had one or two existing columns loosened from `NOT NULL` to nullable (`booking_id` on all three, `provider_id` on `dispatch_attempts`, `purpose` enum widened on `payments`). Every existing row in every one of these tables already has real, non-null values for the loosened columns — confirmed by direct schema/migration inspection, not assumed — so this is additive in practice, not just in intent.
- Verified via the actual test suite running these migrations fresh every run (SQLite `:memory:`), not just by reading the migration files: **[see FINAL REPORT for the exact post-Parcel test count]**.
- No production migration was run. Production remains at `ba0635a`, untouched.

## Module activation status (verified, not just claimed)

`ModuleActivationEnforcementTest`-equivalent coverage exists for Parcel specifically: `test_creating_a_parcel_order_is_blocked_while_the_module_is_not_implemented` proves the REAL shipped default (`is_implemented=false`) blocks order creation — not just that the mechanism is correct in isolation. A second test proves an explicit franchise-level deactivation also blocks creation even once the module is implemented, and a third proves the default create-path succeeds once both `is_implemented` and an explicit activation row are set — mirroring the exact two-step reality a real admin would go through via the `/admin/modules` screen (Phase 22.1).
