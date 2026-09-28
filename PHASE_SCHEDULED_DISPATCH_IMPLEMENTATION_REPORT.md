# 1CF-SCHEDULING-DISPATCH-001 — Implementation Report

**Mode:** BUILD ON BRANCH. **Branch:** `feature/scheduled-dispatch-open-offer`.
**Base commit (main):** `f6632b50a515117ab13640fd029337a06c330393` (merge of
PR #7, `fix/dispatch-window-round-offset`).
**No production changes. No deploy. Not merged.**

---

## What this branch does

### Part 1 — Customer scheduling buffer + slot computation

- **ONE** unified buffer setting: `booking.scheduling_buffer_minutes`
  (30 or 60, default 30) — `App\Support\BookingSchedule::bufferMinutes()`.
  Used for all three things the brief named: TODAY lead time, the last
  selectable slot on any day, and the urgent escalation point before
  `scheduled_at`.
- **`App\Support\BookingScheduleSlots`** — new slot-generation service.
  TODAY applies `slot_time >= now + buffer` and disappears once no slot
  qualifies; future days show the full window and are **never** filtered
  by "now", with the final slot always `window_end - buffer`. Slot
  granularity is deliberately the buffer itself (no second interval
  setting). Days-ahead count still comes from the existing
  `booking.max_schedule_days_ahead`.
- **Two new settings this codebase had no equivalent of before:**
  `booking.service_window_start_hour` / `booking.service_window_end_hour`
  (defaults 8/20). **Important scope note:** a search of this codebase
  found **no pre-existing service-window-hours concept and no
  pre-existing slot-picker UI anywhere** — only a free-form
  `datetime-local` input validated by `BookingSchedule::validate()`. The
  brief's "future days lose morning slots" bug could not be literally
  reproduced because there was nothing to reproduce it in; `BookingScheduleSlots`
  is written from scratch with the TODAY/future distinction structurally
  separate, which is what prevents that bug class.
- `BookingSchedule::validate()` now also enforces the TODAY buffer
  lead-time (previously only "not in the past" + max-days-ahead). It does
  **not** enforce the service-window hours on the existing five free-form
  entry points (Wizard, Checkout, Cart, ServiceShow "add to cart",
  admin call-centre form) — retrofitting window-hour rejection onto five
  live, separately-tested UI flows was judged too wide a blast radius for
  this pass without also replacing their raw datetime input with a real
  slot picker (a larger, separate UI task). `BookingScheduleSlots` is
  ready for that picker to consume.
- Admin UI: new "Scheduled dispatch" fields added to the existing Booking
  settings tab (buffer dropdown, window start/end hour, early-warning
  hours, re-offer interval, both reminder offsets, auto-cancel toggle).

### Part 2 — Scheduled dispatch = open offer / future commitment

- `CreateBookingAction` branches on `scheduled_at`: ASAP unchanged
  (`ServiceMatchingJob::dispatch()` immediately); a scheduled booking
  calls the new `ScheduledDispatchService::releaseIfEligible()` instead.
- **Payment gate:** a scheduled booking with `payment_method = 'cash'` is
  now rejected outright at creation (`RuntimeException`) — cash never
  reaches `payment_status = 'paid'` on its own, so without this a cash
  scheduled booking's dispatch would be silently stranded forever.
  Wallet (synchronous capture) and online (via the existing Razorpay
  webhook) both work; `releaseIfEligible()` is the one gate both paths
  call through, and it's a no-op until `payment_status = 'paid'`.
- **`DispatchService::findScheduledCandidates()`** — the open-offer
  candidate query: same `excludedProviderIdsForBooking()` (already-
  offered/declined) as ASAP, but eligibility does **not** require
  `is_online` or fresh location, and busy-ness is decided by
  `ProviderAvailabilityService::isAvailableAt($provider, scheduled_at, duration)`
  instead of "any active booking right now".
- **Open offer, no timeout:** offers are sent once to every eligible
  candidate; `dispatch_attempts` stays `notified` — nothing ever flips it
  to `timeout` the way `ServiceMatchingJob` does for ASAP.
  `AcceptBookingAction`'s 25-second freshness check and its
  online/fresh-location re-check are both bypassed specifically for a
  scheduled booking's acceptance (new `$requireOnlineAndFreshLocation`
  param on `DispatchService::providerEligibleForBooking()`, defaulting
  `true` — every ASAP call site is unchanged). First valid acceptance
  still wins via the **existing, untouched** `AcceptBookingAction`
  row-lock/race-guard.
- **Repeated offers:** `ScheduledDispatchService::sendOpenOffers()` is
  idempotent and doubles as newly-eligible-provider catch-up;
  `sendReminderIfDue()` re-pushes the notification (not a new
  `dispatch_attempts` row) once `dispatch.scheduled_reoffer_interval_hours`
  (default 2h) has elapsed. Declined (`rejected`) providers are never
  re-pushed — reuses the existing decline endpoint
  (`Livewire\Provider\Jobs\Index::decline()`), unchanged.
- **New scheduler command `dispatch:sweep-scheduled`** (every minute,
  `withoutOverlapping`, same idiom as `dispatch:sweep-deadlines`): release
  catch-up, open-offer/re-offer catch-up, escalation, and provider
  reminders — so a lost delayed job can never permanently strand a
  scheduled booking (the exact NLR-2509-00000002 failure mode).
- **CRITICAL AVAILABILITY RULE, fixed:** `DispatchService::findCandidates()`
  (the ASAP path) used to exclude a provider from TODAY's instant dispatch
  the moment they held **any** active booking, regardless of
  `scheduled_at` — so a provider holding a future Wednesday 10-11
  scheduled job was wrongly treated as busy for a Monday/today instant
  job. Replaced with a time-aware check
  (`DispatchService::busyProviderIdsAt()`): an ASAP-active job
  (`scheduled_at IS NULL`) still always counts as busy (there's no future
  window to compare against); a scheduled job only blocks if its actual
  window overlaps the new request's window. Covered by two new tests
  (23/24).
- Provider offer listing endpoints (`ProviderOfferController::index`,
  `Livewire\Provider\Jobs\Index` mount/render) updated so an open
  (scheduled) offer stays visible past the ASAP 25s window instead of
  silently disappearing from the provider's own Jobs screen.

### Escalation

- **`ScheduledBookingEscalationService`** — the scheduled-booking
  counterpart to `DispatchDeadlineSweepService` (which stays untouched,
  ASAP-only): early-warning (`scheduled_at - dispatch.scheduled_early_warning_hours`,
  default 3h — admin alert + customer "still finding your professional"),
  urgent alert (`scheduled_at - buffer`, admin-only, reusing the **same**
  Part-1 buffer — no second setting), and auto-cancel exactly at
  `scheduled_at` if still unassigned — via the **existing**
  `AdminCancelBookingAction` (zero fee already guaranteed by
  `CancellationService::calculateFee()`'s own `provider_id === null` rule;
  refund via the same Main-Wallet routing the ASAP T+30 sweep uses).
  `dispatch.scheduled_auto_cancel_enabled` (default ON) — OFF means
  escalate only, never auto-cancel.
- Late-created bookings (a milestone already overdue at creation) fire
  that milestone exactly once, at the next sweep — no special-casing
  needed, same "threshold reached AND not yet marked" idiom as the
  existing ASAP sweep.

### Provider reminders + notifications

- **`ScheduledBookingReminderService`** — T-60/T-30 provider reminders
  (both offsets admin-configurable), idempotent per milestone.
  **Late-assignment rule** (deliberately the opposite of the escalation
  rule above): if a provider is assigned after a reminder's time has
  already passed, that one reminder is **skipped**, not fired late —
  `markPassedMilestonesAsSkipped()` is called from both
  `AcceptBookingAction` and `AdminReassignBookingAction`.
- Acceptance confirmations: new `scheduled_assigned` event key on both
  `BookingStatusNotification` (customer — names the provider and
  day/time) and `ProviderJobStatusNotification` (provider — names
  service/day-time/area). ASAP's existing `assigned` copy is completely
  unchanged.
- **`UnassignScheduledProviderAction`** (new) — the one gap this codebase
  had no existing mechanism for at all: putting an already-assigned
  scheduled booking back into the open-offer cycle (provider
  cancels/becomes invalid/admin unassigns/replaces). Notifies the
  customer immediately, reopens via the same idempotent
  `sendOpenOffers()`, and relies on the existing
  `excludedProviderIdsForBooking()` permanent-exclusion rule so the
  previous provider is never re-offered and no duplicate assignment is
  possible.
- Reused `NotificationService`/`AdminOpsAlertService`/`ChannelResolver`
  throughout — no second notification pipeline.

---

## Explicitly out of scope for this branch (found, not fixed)

- **Bundle children** (`CreateBookingBundleAction`) dispatch each child's
  `ServiceMatchingJob` unconditionally too, with the identical
  "scheduled bundle child dispatches immediately, no payment gate, no
  open-offer behaviour" gap this whole phase fixes for a standalone
  Service Booking. Confirmed present, not touched — bundle dispatch has
  its own consolidation/race logic (Phase E4) that deserves its own
  pass rather than being folded in here.
- 24/7 emergency booking and the emergency surcharge — explicitly out of
  scope per the brief.
- The five existing free-form scheduling entry points are not retrofitted
  with service-window-hour enforcement (see Part 1 note above) — only the
  TODAY buffer lead-time is now enforced on them.
- No new customer-facing slot-picker UI was built; `BookingScheduleSlots`
  is the computational service a future picker would consume.

## Part 4 — production data safety

**Could not be verified from this session** — this environment's `.env`
points at a local SQLite database, not production, and SSH to
`api.1callfix.com` is blocked from this session (standing constraint).
No production rows were read or modified. Per the brief: not inventing a
result here — hand the read-only query in
`PHASE_SCHEDULED_DISPATCH_DISCOVERY.md` (already written, this repo root)
to whoever can run it against production before this branch is merged.

---

## Tests

39 new tests across four files (all green, plus the full existing suite
re-run clean against this branch):

- `tests/Feature/Scheduling/BookingScheduleSlotsTest.php` — Part 1 (buffer + slots)
- `tests/Feature/Dispatch/ScheduledDispatchTest.php` — Part 2 (payment gate,
  candidate selection, open offer, race safety, critical availability rule)
- `tests/Feature/Dispatch/ScheduledBookingEscalationTest.php` — escalation
- `tests/Feature/Dispatch/ScheduledBookingReminderTest.php` — reminders +
  replacement + acceptance confirmation

One pre-existing test (`BookingWizardTest::test_a_scheduled_booking_stores_the_chosen_time`)
was updated from `payment_method = 'cash'` to `'online'` — it was
exercising exactly the combination the new payment gate now correctly
rejects.
