# 05 — Dispatch and 30-Minute Booking Resolution Policy Audit

**Ref:** `1CF-COMPREHENSIVE-AUDIT-20260922-1110` · Read-only, no code changed.

## 1. Current dispatch behavior (verified)

Source: `app/Jobs/ServiceMatchingJob.php` (full file read), `routes/console.php` (full file read),
`app/Models/DispatchAttempt.php`, `app/Actions/AdminReassignBookingAction.php`,
`app/Livewire/Bookings/{Index,Show}.php`.

```
Booking created (status: pending)
        ↓
ServiceMatchingJob round 1 fires immediately:
   - claims the booking under a row lock (idempotent vs. concurrent AcceptBookingAction)
   - status -> searching_provider
   - finds up to batchSize() (default 5) nearest eligible providers not yet offered
   - creates a DispatchAttempt per candidate, broadcasts NewJobOffered + FCM push
   - re-queues ITSELF after offerTimeoutSeconds() (default 25s)
        ↓
Round 2..maxRounds() (default 6):
   - times out prior round's un-responded attempts
   - tries a fresh batch of not-yet-offered candidates
   - re-queues again after another offerTimeoutSeconds()
        ↓
Round > maxRounds() (default 6):
   - logs a warning ("...leaving for manual admin assignment")
   - STOPS. Booking remains status = searching_provider, no further automated action, ever.
```

**Default total automated-dispatch duration: 6 × 25s = 150 seconds (~2.5 minutes)**, not the
business's specified 5 minutes — though this is a `Setting`-driven config
(`dispatch.offer_timeout_seconds`, `dispatch.max_rounds`), so the *window itself* is adjustable
without a code change; the *behavior after the window* is what is actually missing (see §2).

## 2. Gap analysis against the business's required timing model

| Required behavior | Current implementation | Status |
|---|---|---|
| T+0 to T+5: automated offer rounds | Config-adjustable window exists, defaults to ~2.5 min, can be set to 5 min via `Setting` | **Partially implemented** |
| T+5: if unaccepted, set an escalation status + notify admin/operator + show in an urgent queue | On round exhaustion, `ServiceMatchingJob` only `Log::warning(...)`. No status change (booking stays `searching_provider`, the same status it's had since round 1 — no distinct "escalated" state), no admin notification of any kind, no urgent/priority queue. `AdminOpsAlertService` (the service that does fire admin pushes elsewhere — booking-created, payment-captured) is never called from this job. | **Missing** |
| T+5 to T+30: admin can manually assign; system prevents duplicate assignment/race | `AdminReassignBookingAction` exists and is reachable from `Bookings/Show.php` (`reassign()`, `assignWorker()`), gated by a `bookings.reassign` permission — this part of the manual path is real. Race safety vs. a concurrent auto-accept was not independently re-verified this pass (the class docblock references row-locking patterns used elsewhere in the codebase, e.g. `ServiceMatchingJob`'s own lock-then-check-status pattern) — flagged **unverified**, not confirmed. | **Implemented (manual assignment) / Unverified (race safety)** |
| T+30: auto-cancel, notify customer, close offers, stop dispatch, record reason + audit | No code path exists. `routes/console.php` (read in full) has exactly five scheduled tasks — `campaigns:dispatch-due`, `plans:renew-due`, `referrals:expire-due`, `kyc:send-reminders`, `digest:send-daily` — none reference bookings, dispatch, or `searching_provider`. There is no scheduled command, no job, and no listener anywhere that auto-cancels a stale searching booking. | **Missing** |

## 3. Why bookings can be "lost" today (root cause, confirmed)

A booking that exhausts `maxRounds()` with zero acceptance sits in `searching_provider` **forever**,
with:
- no visual distinction in the admin bookings list from a booking still in round 1 (both show the
  same `searching_provider` status — `Bookings/Index.php`'s `statusFilter` was confirmed to be a
  plain status match, not an urgency-aware one),
- no elapsed/remaining-time field rendered anywhere (no `dispatch_deadline_at` or equivalent column
  exists on `bookings` — not found in the `create_bookings_table` migration or any later
  ALTER TABLE migration touching `bookings`),
- no admin notification that would prompt someone to look,

until a human happens to notice it in the bookings list and manually reassigns or cancels it. This
matches the business's own description of the symptom ("bookings may remain searching or become
lost") exactly, and the root cause is now evidenced rather than assumed.

## 4. Safety controls — what's actually in place

| Control | Status | Evidence |
|---|---|---|
| Idempotent dispatch round | Implemented | Row-lock + status re-check before every state mutation (`ServiceMatchingJob.php:88-97`) |
| Round-count bug (infinite loop when zero candidates) | **Already fixed** — the code comment at line 144-152 documents a previously-reproduced production bug (booking #10 looping indefinitely) and its fix (round now increments even on an empty-candidate round) | Verified — this is a genuinely closed issue, not a live one |
| Duplicate provider offers within a round | Implemented — `dispatchService->findCandidates()` is asked for providers "who haven't already been offered this booking" (per the class docblock); not independently re-derived this pass | Implemented, per docblock (query itself not re-read) |
| Provider acceptance after cancellation | Likely covered by `AcceptBookingAction`'s own status gate (not re-read this pass — flagged unverified) | Unverified |
| Admin assignment after cancellation | Depends on `AdminReassignBookingAction`'s status gate (not re-read this pass) | Unverified |
| Stale Redis keys | No Redis-backed dispatch state was found — `ServiceMatchingJob` reads all state from MySQL (`bookings`, `dispatch_attempts`) via Eloquent, not Redis. If Redis is used elsewhere (queue driver, cache), it isn't part of dispatch *decision* state. | Not applicable to this job as implemented |
| Failed notification delivery | FCM push is fire-and-forget inside the job body (`ProviderJobOfferNotification`, `ShouldQueue`); a failed push does not block or retry the dispatch round itself — acceptable by design, but no failure visibility was found | Implemented by design; failure visibility unverified |

## 5. Required admin/operator experience — current vs. required

The business wants, per booking: ID, customer, service, location, creation time, elapsed, remaining,
current round, eligible/notified/rejected providers, assignment status, urgency indicator, manual
assign action, cancellation deadline, audit history.

Confirmed present: booking ID/customer/service/location (standard `Bookings/Index` + `Show` fields),
manual assignment action, audit history (`booking.statusHistory()`, used throughout —
`CompleteBookingAction`, `ServiceMatchingJob`, etc. all write to it).

Confirmed absent: elapsed/remaining time, current dispatch round, notified/rejected provider list on
the admin screen (the data exists in `dispatch_attempts` but was not found surfaced in
`Bookings/Show.php` in this pass — flagged for direct confirmation in implementation phase), urgency
indicator, cancellation deadline.

## 6. Required changes (roadmap input — not implemented in this pass)

| # | Change | Layer | Risk |
|---|---|---|---|
| 1 | Add an explicit escalation state to the booking lifecycle (e.g. `dispatch_escalated`) distinct from `searching_provider`, set when `ServiceMatchingJob` exhausts its automated window | `Booking` status enum + `ServiceMatchingJob` | Medium — touches the FSM; must be additive (new terminal-adjacent state, not a rename) |
| 2 | Fire an `AdminOpsAlertService`-style push/notification on escalation, matching the existing pattern already used for booking-created/payment-captured | `ServiceMatchingJob` + `AdminOpsAlertService` | Low — reuses an existing, tested service |
| 3 | New scheduled job (`dispatch:expire-stale`, minute-granularity, added to `routes/console.php` alongside the existing five) that finds bookings past their deadline (`created_at` or a new `dispatch_deadline_at` column) still in `searching_provider`/escalated and auto-cancels via the existing `AdminCancelBookingAction`/`CancellationService` — reuse, don't reimplement, cancellation + refund logic | New console command + migration (`dispatch_deadline_at` column, or derive from `created_at` + a `Setting`) | Medium — a scheduled auto-cancel is inherently higher-stakes; must be idempotent and race-safe against a concurrent manual assignment (lock pattern already established in `ServiceMatchingJob`, reuse it) |
| 4 | Admin bookings list: urgency indicator + elapsed/remaining columns, sourced from `created_at` + the configured deadline | `Bookings/Index.php` + Blade | Low |
| 5 | Admin bookings detail: dispatch-attempts timeline (candidates notified/rejected/timed out per round) | `Bookings/Show.php` + Blade, reads existing `dispatch_attempts` | Low — data already exists |
| 6 | Confirm/tighten the "T+5 admin escalation, T+5-to-T+30 manual window, T+30 hard cancel" numbers against `dispatch.offer_timeout_seconds` / `dispatch.max_rounds` defaults, or make the escalation point independently configurable rather than derived from `offer_timeout_seconds × max_rounds` (today those two numbers implicitly define the "5 minutes," which is fragile) | `Setting` config + `ServiceMatchingJob` | Low |

## 7. Required tests (not yet run this pass)

- Zero eligible providers from T+0 → escalation fires at the configured mark, not before/after
- One provider available who never responds → correctly times out, tries next candidate, eventually escalates
- Admin manually assigns during the T+5–T+30 window → dispatch stops cleanly, no further offers sent
- Provider accepts at the exact moment admin manually assigns (race) → exactly one wins, the other is rejected with a clear reason, no double-assignment
- Auto-cancel at T+30 → booking cancelled, customer notified, any live offers closed, reason + audit event recorded, refund path (if pre-paid) triggers correctly through the existing `CancellationService`
- Worker/queue downtime during the window → booking doesn't silently vanish; recovers correctly once workers resume (needs the queue's own retry/backoff behavior confirmed, not yet done this pass)

## 8. Open business decisions

1. **Exact escalation/cancellation timing** — the brief's diagram is unambiguous (5 min / 30 min
   total), but whether these should be globally fixed or configurable per zone/franchise/service
   type is not stated.
2. **What happens to an in-flight `BookingBundle` child that gets auto-cancelled at T+30** — does the
   rest of the bundle proceed, or does the whole bundle need a partial-cancellation UX? Not addressed
   in the brief; relevant given quantity/bundle work in doc 03.
