# Wallet & job lifecycle leak audit — 2026-10-15

Read-only audit, code at `main` `bed4eaf` (deployed). Nothing was changed. Scope: from homepage booking to dispatch,
accept, start, complete, payment, rating, wallet settlement and payout, with a focus on "can a provider get or keep
work, or money, that they should not".

Production setting values could not be read from this session (production DB reads are blocked). The command to
read them is at the end; several findings depend on them.

## Summary table

| # | Leak | Severity | Today's behaviour |
|---|------|----------|-------------------|
| L1 | Low/zero wallet balance still receives job offers | **High** | Offer rings + push go out; only the Accept button refuses |
| L2 | Minimum balance is off unless set | **High** (if unset on prod) | Default `0` = no gate at all |
| L3 | Unpaid cash commission is ignored when taking jobs | **Critical** | A provider can do unlimited cash jobs and never pay commission |
| L4 | Online-pay instant booking is worked and completed without payment | **Critical** | Provider's wallet is credited from money never collected |
| L5 | Admin assign/reassign skips every eligibility check | **High** | Pending-KYC, wrong-skill, low-balance providers can be assigned |
| L6 | Suspended provider keeps getting offers for up to ~30 min | Medium | Cannot accept (blocked), but offer slots are wasted |
| L7 | Payout "Mark paid / Mark failed" is outside the money approval model | **High** (rule breach) | One permission, no limits, no maker-checker, no reason |
| L8 | Low-balance error gives no way to top up | Medium | Text error only; provider web has no top-up page |

## Detail

### L1 — Low balance still receives offers (the "he receives a job" case)
- `AcceptBookingAction::assertMeetsMinimumWalletBalance()` (`app/Actions/AcceptBookingAction.php:332`) is the **only**
  place the balance is checked.
- Dispatch (`DispatchService::eligibleQuery()`, `ServiceMatchingJob`, `ScheduledDispatchService`,
  `BundleConsolidationJob`) never looks at the wallet. So with ₹0 the provider is still ranked, rung, pushed.
- When they tap Accept they see: *"Your wallet balance (0) is below the minimum (500) required to accept new
  jobs."* That is the "receives a job but unable to accept" case — and it is a leak in two ways:
  1. One of the (up to 5) offer slots in that round is spent on someone who cannot take it → customer waits longer,
     rounds run out sooner (T+30 auto-cancel).
  2. The provider's "Ready for jobs" panel (`BuildsProviderEligibility`) has no wallet line, so they show as ready
     and do not know why they never win a job.
- **Fix:** filter low-balance providers out in `eligibleQuery()` and `providerEligibleForBooking()` (one shared
  rule), keep the accept-time check as the race backstop, add a "Wallet ≥ ₹X" line to the readiness panel.

### L2 — The minimum balance is off by default
- Key `wallet.provider_min_balance_to_accept_jobs`, default `'0'`, `0` = no gate (Admin → Settings). Scoped
  (zone / franchise / city / country) — a value set only on one franchise does not cover others.
- If production has never saved it, **every provider can accept with ₹0 or a negative-equivalent debt.**

### L3 — Unpaid cash commission never stops a provider (most serious)
- Cash job: provider keeps the whole cash; the platform share becomes a `provider_commission_receivables` row
  (`CommissionService::applyForBooking`).
- That debt is recovered **only** when the provider requests a payout (`PayoutService::request()` →
  `settleCashCommissionReceivables()` / `assertNoBlockingCashDebt()`).
- The accept gate compares `wallets.balance` only. A provider with ₹600 in the wallet and ₹8,000 of unpaid cash
  commission passes. A provider who only does cash jobs and never requests a payout **never pays commission and is
  never stopped.**
- **Fix:** gate on `wallet balance − outstanding cash commission ≥ minimum`, and add a hard "max unpaid cash
  commission" setting (null = off) after which the provider stops getting offers (cash ones at least) until they
  settle. Needs a top-up/settle path (see L8).

### L4 — Online booking worked without payment
- `CreateBookingAction::execute()` dispatches an **instant** booking at creation, before payment, unless it has a
  coupon (coupon bookings and scheduled bookings wait for capture — those are correct).
- `StartBookingAction` and `CompleteBookingAction` never check `payment_status`.
- `CommissionService` treats every non-`cash` booking as prepaid and **credits the provider's wallet** with their
  share on completion.
- The customer's "Pay now" (`Customer\Orders\Show::startPayment`) stays available after completion.
- Result: customer picks online, never pays → job done → provider is credited and can withdraw → platform loses
  the provider share and never receives the revenue. The provider may also take cash on the doorstep for an
  "online" booking, which nothing records.
- **Fix (owner decision):** either (a) hold instant online bookings until capture like coupon bookings, or (b) allow
  pay-later but block completion — or at least the wallet credit — until `payment_status = paid`, with the provider
  app showing "Customer has not paid — collect payment". Option (a) is the smallest and matches the coupon gate.

### L5 — Admin assign bypasses eligibility
- `Bookings\Show::getAvailableProvidersProperty()` lists every `is_active` provider in the zone — no KYC, skill,
  suspension, online or balance filter.
- `AdminReassignBookingAction` accepts **any** provider id (the dropdown value is client-editable) and checks nothing.
  `ReassignInProgressJobAction` checks only KYC + active.
- **Fix:** one server-side check in the action (KYC approved, active, user not suspended, skill matches, wallet rule
  from L1/L3), with an explicit "override" reason only if the owner wants one.

### L6 — Suspended provider still gets offers
- Suspend sets `users.status = suspended` and revokes API tokens; `EnsureAccountNotSuspended` (also persistent for
  Livewire) blocks accept on web. Good.
- But it does not set `providers.is_online = false`, and dispatch does not read `users.status`, so they stay in
  offer rounds until their location goes stale (≈30 min).
- **Fix:** on suspend set `is_online = false`; add `whereHas('user', status = active)` to `eligibleQuery()`.

### L7 — Payouts outside the manual money approval model
- `Payouts\Manage::markPaid / markFailed` need only `payouts.manage`. No per-level amount limits, no maker-checker,
  no required reason, no escalation queue. `markFailed` credits the wallet back.
- CLAUDE.md lists "manual payouts" explicitly under MANUAL MONEY ACTIONS. Should be moved onto the same model as
  mismatch refunds (`MismatchRefundService`).

### L8 — No way to top up when blocked
- Provider web has no wallet top-up page; the low-balance message is plain text. Once L1/L3 are fixed, a blocked
  provider needs "Add money / Settle dues" (Razorpay → wallet, then auto-settle receivables).

## Checked and sound

- Accept race: row lock, offer freshness, booking still `searching_provider`, availability slot, eligibility re-check.
- KYC / online / location freshness / skills / zone / radius are enforced in dispatch and re-checked at accept.
- Start and completion OTP: expiry, attempt cap, single use (`BookingOtpService`).
- Reviews: only the booking's customer, only after completion, one per booking (DB unique), rating 1–5;
  `providers.rating_avg` recomputed from real reviews (`ReviewObserver`).
- Commission: idempotent (one row per booking), 3-tier rate (provider → franchise → global).
- Wallet: debits locked and refuse to go negative; frozen wallets cannot pay out; promotional credit is not
  withdrawable (EARN3).
- Payout request: verified account only, cash debt swept first, KYC withdrawal rule, limits.
- Coupons/benefits: online-only enforced server-side; coupon bookings held until capture; unpaid sweep cancels.
- Razorpay amount mismatches go to the approval-model refund queue.
- Suspended accounts cannot act on web, Livewire or API.

## Not covered here

Self-dealing (a provider booking themselves with a second number for 5-star reviews or referral rewards),
franchise-level revenue share correctness, and non-service modules (parcel, taxi, rental, marketplace) were not
traced end to end. The 2026-09-22 comprehensive audit (`docs/audit/2026-09-22/`) covers some of these.

## Production values to read (run on the server)

```bash
php artisan tinker --execute='foreach (["wallet.provider_min_balance_to_accept_jobs","payment.cash_enabled","payment.online_enabled","dispatch.offer_timeout_seconds"] as $k) echo $k, " = ", var_export(\App\Models\Setting::get($k), true), PHP_EOL; echo "scoped min-balance rows: ", \App\Models\Setting::where("key","wallet.provider_min_balance_to_accept_jobs")->get(["scope_type","scope_id","value"])->toJson(), PHP_EOL; echo "outstanding cash commission: ", \App\Models\ProviderCommissionReceivable::outstanding()->sum(\DB::raw("amount_owed - amount_settled")), PHP_EOL; echo "completed online bookings still unpaid: ", \App\Models\Booking::where("status","completed")->where("payment_method","online")->where("payment_status","!=","paid")->count(), PHP_EOL;'
```

The last two numbers show whether L3 and L4 have already cost money.

## Suggested fix order

1. L4 (hold instant online bookings until paid) and L3 (count cash debt + cap) — real money.
2. L1 + L2 + L8 together (filter at dispatch, readiness line, top-up/settle page, set the minimum).
3. L5 and L6 (one shared eligibility rule for dispatch, accept and admin assign).
4. L7 (payouts onto the approval model).
