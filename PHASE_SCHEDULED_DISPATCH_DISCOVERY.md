# 1CF-SCHEDULED-DISPATCH-DISCOVERY-001

**Mode:** READ-ONLY discovery. No code changes made.

**Trigger:** NLR-2509-00000002 — a booking scheduled for a future date/time
dispatched immediately at creation, found no provider, and sat in
`searching_provider` for ~36 hours with no admin alert and no
auto-cancellation, until the customer manually cancelled it.

---

## 1. When does `ServiceMatchingJob` actually fire for a scheduled booking?

**Immediately at booking creation — `scheduled_at` is never read.**

`CreateBookingAction::execute()` (`app/Actions/CreateBookingAction.php:66`)
dispatches `ServiceMatchingJob::dispatch($booking->id)` unconditionally,
right after the DB transaction that created the row commits — with no
`->delay()` and no branch on whether `$data['scheduled_at']` was set.

`ServiceMatchingJob::handle()` (`app/Jobs/ServiceMatchingJob.php`) itself
never inspects `$booking->scheduled_at` anywhere in its body. It:
- transitions `pending` → `searching_provider` on its first run and stamps
  `dispatch_deadline_at = now()` (line 114) — **the anchor timestamp is
  "now", not "scheduled_at minus something"**,
- immediately calls `DispatchService::findCandidates()`, which finds
  providers who are free **right now** (see finding 2),
- re-broadcasts every `offer_timeout_seconds` (default 25s) for up to
  `max_rounds` (default 24) — i.e. a ~10 minute automatic search window
  that starts at creation time, not at `scheduled_at`.

So a booking made today for a slot three days out gets exactly the same
treatment, starting at the same instant, as an ASAP booking.

## 2. Does immediate dispatch make sense for a scheduled booking?

**No — confirmed problem, not working-as-intended.**

`findCandidates()` (`app/Services/DispatchService.php:45`) excludes only
providers with a currently-active booking (`$busyProviderIds`, statuses
`assigned/provider_en_route/in_progress/on_hold`, no `scheduled_at`
comparison at all — `app/Services/DispatchService.php:68-70`). It never
calls `ProviderAvailabilityService::isAvailableAt()`.

`ProviderAvailabilityService::isAvailableAt()` (Phase E4) is the exact
"is this provider free for `[scheduled_at, scheduled_at+duration)`"
primitive the brief is asking for — but grepping every call site shows it
is used in exactly two places:
- `BundleConsolidationJob` (candidate filtering for bundle siblings), and
- `AcceptBookingAction::execute()` (`app/Actions/AcceptBookingAction.php:151-156`),
  as a **race-safe recheck at the moment of acceptance only**, throwing
  "You already have another job scheduled that overlaps this one's time
  slot" if the provider tries to accept anyway.

Net effect for a standalone scheduled Service Booking:
- offers go out to whoever is free **at dispatch time**, which tells you
  nothing about who will be free **at `scheduled_at`**;
- a provider who is busy right now but free at `scheduled_at` is wrongly
  excluded from the offer pool;
- a provider who is free right now but already booked for `scheduled_at`
  can be offered the job and will only find out (or get rejected) if they
  try to accept — wasting a round instead of never being offered it.

Confirms the brief's instinct: dispatch should defer to close to
`scheduled_at`, and `findCandidates()` should use `isAvailableAt()` for
scheduled bookings, not just `$busyProviderIds`.

## 3. Does ANY sweep/alert/escalation run against scheduled bookings stuck in `searching_provider`?

**Confirmed genuine gap — F-01's exclusion left scheduled bookings with no equivalent active safety net.**

`DispatchDeadlineSweepService` (`app/Services/DispatchDeadlineSweepService.php`)
is the only *mutating*, *proactive* sweep for this status, and both of its
passes explicitly filter scheduled bookings out:
- `escalateOverdue()` — `->whereNull('scheduled_at')` (line 104)
- `cancelOverdue()` — `->whereNull('scheduled_at')` (line 170)

So a scheduled booking gets **zero** T+5 admin alert and **zero** T+30
auto-cancel, by explicit design (the REF 1CF-IMPLEMENT-20260923-F01
comment on line 162-169 documents this was deliberate, to stop scheduled
bookings from being wrongly flagged as overdue seconds after creation —
but no replacement mechanism was ever added).

The only thing that isn't blind to it is `StuckBookingService`
(`app/Services/Operations/StuckBookingService.php`) — it is READ-ONLY, has
no `scheduled_at` filter, and does list a `searching_provider` booking
once it's been in that status >30 minutes (`DEFAULT_THRESHOLD_MINUTES`).
But this only surfaces as:
- a manual "stuck bookings" screen (`Livewire\Operations\Health`,
  `Livewire\Dashboard`) an admin has to actively open, or
- one line in `DailyDigestService`'s **once-a-day** digest email.

Neither is a real-time push alert. That's consistent with what actually
happened to NLR-2509-00000002: it was theoretically listable on a
dashboard nobody opened, for 36 hours, with no active notification at
all — the same class of gap `AdminOpsAlertService::dispatchEscalation()`
was built to close for ASAP bookings, but never extended to scheduled
ones.

## 4. What does ASAP-booking dispatch do when all rounds are exhausted?

`ServiceMatchingJob::handle()` (line 148-151): once `$this->round` exceeds
`maxRounds()` (default 24, ≈10 minutes), it logs a warning and **returns
without requeuing itself** — no radius widening, no fallback engine. The
booking is simply left in `searching_provider` "for manual admin
assignment" (its own comment, line 30-32 and 149).

The actual safety net for that state is entirely
`DispatchDeadlineSweepService` — T+5 alert, T+30 auto-cancel-with-refund.

Confirmed: **none of that fallback applies to scheduled bookings either**,
for the same reason as finding 3 — both passes are `whereNull('scheduled_at')`.
So today a scheduled booking's only possible resolutions after its ~10
minute dispatch-round window are (a) a provider eventually accepts on a
later manually-triggered redispatch/admin action, or (b) it sits forever,
visible only on an unvisited dashboard/digest.

## 5. Other bookings currently stuck in the same state

**Could not be checked from this session.** Two blockers:
- This local environment's `.env` points at a local SQLite file
  (`database/database.sqlite`), not the production database — querying it
  would tell us nothing about real customer data.
- Per this session's own standing constraint, SSH access to
  `api.1callfix.com` (production) is blocked from this environment; `.env`
  for prod is local-only to that box.

**Hand this query to whoever can run `php artisan tinker` (or a read-only
SQL client) against production**, exactly as the brief specifies — pure
`SELECT`, no writes:

```php
// Genuinely stuck scheduled bookings: still searching, dispatch already
// started, and either past their scheduled time or long past their
// (~10 min) automatic dispatch window with no resolution.
\App\Models\Booking::query()
    ->where('status', 'searching_provider')
    ->whereNotNull('scheduled_at')
    ->whereNotNull('dispatch_deadline_at')
    ->where(function ($q) {
        $q->where('scheduled_at', '<', now())
          ->orWhere('dispatch_deadline_at', '<', now()->subMinutes(15));
    })
    ->select('id', 'code', 'status', 'scheduled_at', 'dispatch_deadline_at', 'created_at')
    ->get();
```

Report back the count and IDs before anything is done to them (Part 23 of
the follow-up brief: no production data changes, no auto-cleanup).

---

## Recommended fix approach for the follow-up implementation

1. **Defer dispatch trigger for scheduled bookings.** In
   `CreateBookingAction::execute()`, branch on `scheduled_at`: an ASAP
   booking keeps `ServiceMatchingJob::dispatch($booking->id)` immediately;
   a scheduled booking instead delays that same dispatch to
   `scheduled_at - buffer` (the unified Admin buffer from the parent
   brief), via `->delay()` on the same job — no second dispatch engine.
   Booking stays `pending` (or a new explicit "scheduled/awaiting release"
   state, if the FSM needs one) until then, not `searching_provider`.
2. **Scheduler backstop.** A delayed job can be lost; add an idempotent
   `dispatch:release-scheduled` sweep (parallel to
   `DispatchDeadlineSweepService`) that finds `scheduled_at IS NOT NULL`
   bookings still `pending` past their release time and dispatches them,
   guarded so it can't double-fire against a delayed job that already
   started.
3. **Provider availability at offer time.** Have `findCandidates()` (or a
   scheduled-specific variant) exclude candidates using
   `ProviderAvailabilityService::isAvailableAt($provider, $booking->scheduled_at, $duration)`
   in addition to (not instead of) the existing `$busyProviderIds` check,
   so scheduled dispatch only offers to providers who will actually be
   free at the scheduled time — `AcceptBookingAction`'s existing recheck
   stays as the race-safe backstop, unchanged.
4. **Scheduled-specific escalation, not the ASAP T+5/T+30 sweep.** Add a
   dedicated escalation pass (reusing `AdminOpsAlertService`, not a new
   channel) that fires once a scheduled booking reaches `scheduled_at`
   still unassigned. Per the parent brief (Part 15), this is **alert
   only** — auto-cancellation/refund for this case is an explicit open
   business decision, not to be implemented yet.
5. **`StuckBookingService` stays as-is** — it already doesn't exclude
   scheduled bookings and needs no change; it's simply not a substitute
   for a real-time alert, which is what step 4 adds.

No code was changed in this pass.
