# PHASE B0.2 — Service Partner → Worker Delegation

**Status:** Implemented locally, verified against the real production database via a test-and-rollback cycle (migrated up, tested including real HTTP calls to the new endpoints, cleaned up, migrated back down, all server files restored), **not committed, not deployed**.

**Builds on:** Phase A (frozen, `4f92fdf`) — untouched. Phase B0.1 (local, uncommitted) — used exactly as built, not recreated or modified.

---

## 1. Current baseline

Confirmed at the start of this phase: local working tree at commit `4f92fdf` with the full B0.1 foundation present but uncommitted (`field_workers`, `field_worker_capabilities`, `partner_workers`, `field_worker_documents`, `WorkerTypes`, 6 Worker/Partner-Workforce permissions, `User::fieldWorkerProfile()`, `Provider` worker relations). Production matched exactly, at migration `039000`.

Also confirmed by fresh inspection before writing any code (not assumed from the earlier audit):
- `bookings.status` enum is actually 9 values: `pending, searching_provider, assigned, provider_en_route, in_progress, on_hold, completed, cancelled, disputed`.
- **No "start" flow exists anywhere today, for anyone.** `start_otp` is generated at acceptance (`AcceptBookingAction`) and returned in the API response, but no code path has ever verified it or transitioned a booking to `in_progress`/`provider_en_route`. `CompleteBookingAction` already accepts `assigned`/`provider_en_route`/`in_progress` as valid starting states, so Providers have always skipped straight from acceptance to completion.

## 2. B0.1 dependency

Used as-is. Not one B0.1 file was modified. `AssignBookingToWorkerAction` reads `field_workers`/`field_worker_capabilities`/`partner_workers` exactly as B0.1 defined them.

## 3. What B0.2 implemented

- `bookings.assigned_worker_id` — nullable FK to `field_workers`, additive only. `provider_id`'s meaning is completely unchanged.
- `AssignBookingToWorkerAction` — a Partner delegates an already-accepted booking to one of their Workers, with 4 real checked business rules (ownership, assignable status, active partner link, matching Service capability).
- `StartBookingAction` — new, minimal: verifies `start_otp`, transitions `assigned`/`provider_en_route` → `in_progress`. Used by the Worker flow; not required for (and doesn't change) Provider self-completion.
- `WorkerJobController` (`GET /worker/jobs`, `POST /worker/jobs/{id}/start`, `POST /worker/jobs/{id}/complete`) and `PartnerWorkerController` (`POST /partner/workers/assign-booking`).
- `WorkAssignmentNotification` — notifies the assigned Worker, same shape as every other transactional notification.
- One two-line safety addition to `AdminReassignBookingAction`: reassigning a booking to a *different* provider now clears a stale `assigned_worker_id` (a worker on the old provider's team has no business being attached once the booking belongs to someone else). Reassigning to the *same* provider is a no-op for this field — verified both ways.

## 4. Booking assignment architecture

`assigned_worker_id` is a side annotation on the existing FSM, not a new status and not a new table. `provider_id` stays the sole accountability/commission anchor. `AssignBookingToWorkerAction` never touches `status`; it writes a `booking_status_history` row and fires `BookingStatusUpdated` (same pattern `AdminReassignBookingAction` already uses for a non-status-changing significant event).

## 5. Partner authorization

`$request->user()->providerProfile` — same profile-existence pattern as the existing `DispatchController`. Ownership enforced inside the action (`booking.provider_id === $partner->id`), not by RBAC. **Deliberate choice, not an oversight:** the `partner.workers.*` permissions B0.1 seeded stay reserved for future admin-panel screens — RBAC (`role_assignments`) is used exclusively for admin capability in this app; every provider/customer-facing mobile endpoint (booking accept/complete, wallet, loyalty, plan subscribe/cancel) authorizes via profile ownership, never `hasPermission()`. Gating the Partner's own API on a permission only Super Admin holds would make the feature unusable by real partners.

## 6. Worker authorization

`$request->user()->fieldWorkerProfile` + `booking.assigned_worker_id === $worker->id` on every single action, including the job list query itself (`WHERE assigned_worker_id = ?`, never a client-supplied filter). A booking id alone is never sufficient — verified directly (Booking 5/6/7/8 in the test matrix).

## 7. Start/completion behavior

- **Provider self-perform (unchanged):** accept → complete directly. No start step required, none added to this path.
- **Worker-delegated:** accept (Partner) → assign (Partner) → start (Worker, `start_otp`) → complete (Worker, `completion_otp`).
- Worker completion is authorized by the new controller layer, then delegates straight into the **unmodified** `CompleteBookingAction`, called with `$booking->provider` (the real accountable Provider) — commission/wallet crediting flows through exactly the same, untouched code as every existing booking.

## 8. OTP behavior

No new OTP mechanism. `start_otp`/`completion_otp` are the same two columns that have existed since Phase 1. `StartBookingAction` gives `start_otp` a real purpose for the first time; `CompleteBookingAction`'s OTP check is completely untouched.

## 9. Notifications

One new class (`WorkAssignmentNotification`, event key `worker.job_assigned`), reusing the existing pipeline (`ChannelResolver`, `notification_logs`, the global `NotificationSent` listener) exactly as every other notification does. No new notification infrastructure.

## 10. API endpoints

```
GET  /api/worker/jobs
POST /api/worker/jobs/{booking}/start
POST /api/worker/jobs/{booking}/complete
POST /api/partner/workers/assign-booking
```
All inside the existing single `auth:sanctum` route group — no new guard, no new route file.

## 11. Database changes

One migration: `bookings.assigned_worker_id` (nullable FK → `field_workers`, `nullOnDelete`). Nothing else. Constraint name (`bookings_assigned_worker_id_foreign`) checked against MySQL's 64-character limit — well under it, no explicit naming needed this time.

## 12. Tests

**36 real checks**, all passed after fixing 2 test-script bugs (details below):

- **Happy paths (real HTTP):** Provider self-completes via the existing endpoint (regression), full delegation chain (assign → worker sees job → start → complete) via the new endpoints.
- **8 negative/authorization tests, all via real HTTP, exactly per spec:** unrelated-worker assignment rejected, cross-partner access rejected, cross-worker visibility/access rejected (confirmed the job list itself never leaks another worker's booking), completing an unassigned booking rejected, completing another partner's booking rejected, customer using the worker-completion endpoint rejected, assigning a completed booking rejected, assigning a cancelled booking rejected.
- **Commission/wallet regression:** both the self-performed and the delegated booking credited the *Partner's* wallet identically; the Worker's wallet received zero commission (no compensation model invented, as required).
- **Admin reassignment regression:** reassigning to a different provider now clears `assigned_worker_id`; reassigning to the same provider does not.
- **B0.1 regression:** 1:1 User↔FieldWorker protection, multi-capability, multi-partner linking, and platform-direct workers all reconfirmed live within this same run.
- **Phase A smoke test:** a real zero-price Plan subscription + a real booking through `CreateBookingAction`, confirming the Plan Engine's discount still applies correctly after the B0.1+B0.2 migrations.
- **Migration integrity:** all 6 migrations (5 from B0.1 + 1 from B0.2) ran clean, then rolled back clean, confirmed via `migrate:status`.

### Bugs found

**Zero product bugs.** Two test-script bugs, both found, fixed, and reverified before proceeding:
1. The first verification pass asserted on bookings 11/13 having a worker already assigned — but the setup script never actually called the assign action for them. Fixed by assigning them for real before testing the reassignment behavior.
2. A "must be null" assertion checked `$booking->getAttributes()` on a freshly-created in-memory model instead of `->fresh()` — the same Eloquent in-memory-default gotcha already documented from an earlier phase this session. Fixed by re-querying via `->fresh()`.

Both were caught because the assertions failed loudly rather than being trusted blindly — re-run and confirmed passing afterward.

## 13. Cleanup

All disposable fixtures (6 users, 2 providers, 2 field_workers, capabilities, partner_workers links, 9 bookings and their payments/commissions/status history, 1 plan/subscription) deleted and verified via 13 direct-DB integrity queries — zero orphans, permission/role counts unchanged at their B0.1 baseline, `jobs=0`, `failed_jobs` unchanged at 1. The real "Nellore" franchise confirmed untouched (`owner_user_id` still `NULL`).

## 14. Regression results

Provider self-completion: unaffected. Existing `bookings.provider_id` semantics: unaffected. `CommissionService`: not modified, not called differently. `WalletService`: not modified. `DispatchService`/`ServiceMatchingJob`: not touched. Admin reassignment: existing behavior (provider_id update, timeout of pending offers, history note) fully intact, with one additive safety behavior layered on top.

## 15. Phase A verification

Untouched throughout — zero Phase A files in the change list. Live smoke test (real Plan + real Subscription + real discounted Booking) confirmed the engine still functions correctly after both B0.1's and B0.2's migrations.

## 16. Open decisions (unchanged, none silently resolved by this phase)

All open items from B0.1 remain open. B0.2 adds one more: **reassignment/delegation of a booking that has already moved past `provider_en_route` (i.e. mid-`in_progress`, or `on_hold`) is explicitly not supported** — assignment is only possible in the `assigned`/`provider_en_route` window, matching the real-world example given (delegated right after acceptance). Whether a Partner should ever be able to swap the executing Worker mid-job is a genuine open product question, not addressed here.

## 17. Deliberately not implemented

Everything the prompt marked out of scope: Parcel/Taxi/Food/Grocery, any Flutter app, polymorphic `dispatch_attempts`, any `DispatchService`/`ServiceMatchingJob`/`CommissionService` redesign, Worker commission/payroll/settlement/scheduling/fleet management, Worker Plan Engine consumption, and a universal cross-vertical assignment table.
