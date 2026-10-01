# REF 1CF-JOB-SPAN-AUDIT-001 — Job Span / Job Journey audit

Audited against commit `3ab0534` (the committed code at the time of the audit; nothing uncommitted was counted as
"existing"). Production was checked by reading the live files on the server (read-only).
Section **H** records what was changed in response to the findings.

## A. Current implementation matrix (as audited, commit 3ab0534)

| Journey step | Backend | API | Provider UI | Customer UI | Admin | Notifications | Tests | Production |
|---|---|---|---|---|---|---|---|---|
| **Pending** (booking created) | `CreateBookingAction` sets `pending`; immediate bookings go to `searching_provider` via `ServiceMatchingJob`; scheduled bookings stay `pending` until paid, then `ScheduledDispatchService::releaseIfEligible` | customer `POST /api/bookings` | n/a | timeline line missing for `pending` (falls back to a headline "Pending") | list chip + detail | customer `booking.created` | booking creation / scheduled-dispatch tests | yes |
| **Job accepted** (`assigned`) | `AcceptBookingAction` (from `searching_provider` only) | `POST /api/bookings/{id}/accept` (provider) | web Jobs list: Accept / Decline | "A professional was assigned" | reassign / assign worker | customer `assigned`, provider `assigned` | DispatchApiTest, accept tests | yes |
| **Provider in transit** (`provider_en_route`) | `MarkEnRouteAction` (from `assigned` only) | **none** | web: "I'm on my way" | "Your professional is on the way" | status shown | provider `en_route`; **customer: none** | ProviderEnRouteLifecycleTest (7) | yes (file + method present) |
| **Job started** (`in_progress`) | `StartBookingAction` (from `assigned`/`provider_en_route`, start OTP) | worker only: `POST /api/worker/jobs/{id}/start`; **provider: none** | web: Start (OTP) | "Work started" | status shown | provider `started`; **customer: none** | BookingFsmTest, BookingOtpHardeningTest, WorkerJobApiTest | yes |
| **In progress** | same status as "started" — the code has **no separate** started vs in-progress states | — | — | — | — | — | — | — |
| **For spares / On hold** (`on_hold`, `hold_reason = awaiting_spares`) | `PlaceBookingOnHoldAction` (from `assigned`/`en_route`/`in_progress`); fields `hold_category`, `hold_reason`, `hold_note`, `on_hold_since` | **none** | **none** | bare "On Hold" (reason not shown) | **no button** | provider `on_hold`; **customer: none** | ProviderJobStatusNotificationTest (hold), ProviderForegroundAlertsTest, ProviderAvailabilityServiceTest | code present; **not reachable from any screen or API** |
| **Spare available** | **does not exist** (no status, column, action or copy) | none | none | none | none | none | none | no |
| **Resume** (`on_hold` -> `in_progress`) | `ResumeBookingAction` | **none** | **none** | — | **no button** | provider `resumed`; customer none | ProviderJobStatusNotificationTest (resume) | code present; unreachable |
| **Completed** | `CompleteBookingAction` (from `assigned`/`en_route`/`in_progress`, completion OTP) | `POST /api/bookings/{id}/complete` (provider), `POST /api/worker/jobs/{id}/complete` | web: Complete (OTP) | "Job completed" | status shown | customer `completed`, provider `completed` | BookingFsmTest, settlement tests | yes |
| **Cancelled** | `AdminCancelBookingAction` (any non-terminal status, incl. `on_hold`) | customer `POST /api/bookings/{id}/cancel` | n/a | cancel button (web); timeline "Booking cancelled" with no reason / fee / refund detail | cancel with reason | customer `cancelled` / `no_provider_found` / `scheduled_unassigned_cancelled`, provider `cancelled` | BookingFsmTest, cancellation tests | yes |

## B. Existing states (exact names)

`bookings.status` enum: `pending`, `searching_provider`, `assigned`, `provider_en_route`, `in_progress`, `on_hold`,
`completed`, `cancelled`, `disputed` (migration `2026_08_03_001000_add_hold_tracking_to_bookings_table`).

`bookings.hold_category`: `customer_side`, `provider_side`.
`bookings.hold_reason`: `awaiting_spares`, `awaiting_customer_approval`, `awaiting_payment_decision`,
`provider_unresponsive`, `payment_not_reconciled`, `other_provider_issue`, `other_customer_issue`.
Timestamps on the booking: `completed_at`, `on_hold_since`, `start_otp_verified_at`, `completion_otp_verified_at`,
dispatch/scheduled timers. **There is no `accepted_at`, `en_route_at` or `started_at` column** — those times exist only
as `booking_status_history.changed_at`. History table: `booking_status_history` (`status`, `changed_by`, `note`, `changed_at`).

Other modules (their own state machines and history tables): parcel (`pending`, `searching_worker`, `assigned`,
`worker_en_route_pickup`, `picked_up`, `en_route_dropoff`, `delivered`, `cancelled`, `disputed`), taxi (`requested`,
`searching_driver`, `assigned`, `driver_en_route`, `trip_started`, `trip_completed`, `cancelled`, `disputed`),
marketplace/food (`pending`, `accepted`, `preparing`, `ready`, `completed`, `cancelled`), hotel (`pending`,
`confirmed`, `checked_in`, `checked_out`, `completed`, `cancelled`), rental (`pending`, `confirmed`, `picked_up`,
`active`, `returned`, `completed`, `cancelled`), property (`pending`, `confirmed`, `checked_in`, `completed`, `cancelled`).

## C. Existing transitions (enforced inside each Action — there is no central state-machine class)

| Transition | Action | Allowed from | Guard |
|---|---|---|---|
| created -> `pending` | CreateBookingAction | — | scheduled + cash is rejected |
| `pending` -> `searching_provider` | ServiceMatchingJob (immediate) / ScheduledDispatchService (scheduled, once `payment_status = paid`) | `pending` | payment gate for scheduled |
| `searching_provider` -> `assigned` | AcceptBookingAction | `searching_provider` | eligibility, wallet minimum, race lock |
| `assigned`/`provider_en_route` -> `searching_provider` | UnassignScheduledProviderAction | scheduled bookings | — |
| `assigned` -> `provider_en_route` | MarkEnRouteAction | `assigned` | booking is the provider's |
| `assigned`/`provider_en_route` -> `in_progress` | StartBookingAction | those two | start OTP (expiry, attempt cap, single use) |
| `assigned`/`provider_en_route`/`in_progress` -> `on_hold` | PlaceBookingOnHoldAction | those three | reason must be a known enum |
| `on_hold` -> `in_progress` | ResumeBookingAction | `on_hold` | — |
| `assigned`/`provider_en_route`/`in_progress` -> `completed` | CompleteBookingAction | those three | provider owns it; completion OTP |
| any non-terminal -> `cancelled` | AdminCancelBookingAction | not `completed`/`cancelled` | — |
| `pending`/`searching_provider` -> `assigned` | AdminReassignBookingAction | — | admin |

Observations: completion is allowed straight from `assigned` (the start step can be skipped); **resume always returns to
`in_progress`**, so a hold placed before the job started and then resumed would skip the start OTP.

## D. Spare / pending support

- **"For Spares" exists only as a hold reason** (`on_hold` + `hold_reason = awaiting_spares`). It is a customer-side hold.
- **"Pending" exists as a separate status** with its own meaning: created / waiting to be matched (immediate), or waiting
  for payment before dispatch (scheduled). **They are separate** — pending is never "for spares".
- **"Spare Available" does not exist.**
- **Can the provider resume? No.** Neither the provider web screen nor any API route can hold or resume. The only code
  that holds/resumes is `ProposeExtraWorkAction` / `RespondToExtraWorkAction` (reason `awaiting_customer_approval`),
  which have **no caller** except a console test command (`TestExtraWorkFlow`) and the QA seeder.
- **Financially:** a hold has no effect on payment, commission, wallet, invoice or fee. Money moves only on capture
  (webhook), completion (`CompleteBookingAction`) and cancellation (`AdminCancelBookingAction` -> `refundIfPaid`).

## E. Demonstrable gaps (at the audited commit)

1. No "Spare Available" step anywhere.
2. No provider UI or API to hold, mark spares or resume; no admin button either (hold/resume unreachable in production).
3. No provider API for "on the way" or for a non-worker provider to **start** a job (accept and complete only).
4. Customers get **no notification** for provider on the way, work started, hold, spares, resume.
5. The customer timeline is a plain list ("Work started" / "Job completed"); it does not show pending semantics, a hold
   and its reason, who cancelled, the cancellation fee, or where the refund went.
6. Admin and provider screens show a raw status list only.
7. Other modules (parcel, taxi, food/marketplace, hotel, rental, property) have no journey presentation at all.
8. Resume-skips-start-OTP hazard if a job is held before it starts (C, observation).

## F. Git / deployment evidence

| Item | Commit | Date | Contained in |
|---|---|---|---|
| Hold / resume layer (`PlaceBookingOnHoldAction`, `ResumeBookingAction`, hold columns) | `2e5d114` | 2026-07-29 | `main`, `release/earn3-integration` |
| Extra-work flow (`ProposeExtraWorkAction`, `RespondToExtraWorkAction`) | `6ee9124` | 2026-08-06 | `main`, `release/earn3-integration` |
| "On the way" transition (`MarkEnRouteAction`) + provider notifications | `0c61576` (Phase PN1) | 2026-09-07 | `main`, `release/earn3-integration` |

Production (read from the live server): `MarkEnRouteAction`, `PlaceBookingOnHoldAction`, `ResumeBookingAction`,
`ProposeExtraWorkAction` are present, and the provider job screen there has exactly `enRoute`, `start`, `complete`.
Git carries no deployment tags, so "deployed" here means "the file is on the server", not a recorded release.

## G. Verdict

**2. PARTIALLY IMPLEMENTED** — the normal path (accept -> on the way -> start -> complete) works end to end;
the spares path has a hold/resume backend but no "Spare Available" step and no way to reach it from any provider,
admin or API surface, and customers are not told about it.

NEXT SAFE ACTION (as of the audit): add the missing step and controls by reusing the existing hold/resume Actions —
a `spares available` step, provider/admin/API controls, customer stage notifications, and one shared journey timeline —
with no migration and no change to the existing state machine. That is what Section H records.

## H. Response to the audit (change set, no migration)

| Gap | Resolution |
|---|---|
| 1 | `MarkSparesAvailableAction`: stays `on_hold`, writes a history entry the journey reads; idempotent |
| 2 | Provider web: "Waiting for spares" (only once in progress), "Spares available", "Resume work" (spares holds only). Admin booking page: Put on hold (in-progress jobs only) / Spares available / Resume, gated by `bookings.reassign` |
| 3 | API: `POST /api/bookings/{id}/en-route`, `/start`, `/hold-for-spares`, `/spares-available`, `/resume`; ownership = the provider or the assigned worker |
| 4 | Customer notifications for en route, started, on hold, spares available, resumed (push + in-app for the minor stages; every configured channel for a hold) |
| 5, 6 | One timeline component on the customer, provider and admin screens; pending / schedule / payment / hold lane / cancellation detail (reason, fee, refund destination) |
| 7 | Journey definitions for parcel, taxi, food, retail, hotel, rental, property, shown on each module's admin detail |
| 8 | Hold is offered only once a job is in progress |

Not changed (and why): extra-work approval has no customer UI/API (it also involves charging an extra amount) — left
as a separate decision; the state machines and every financial trigger are untouched, so **completion remains the
authoritative settlement trigger**.
