# Customer cancellation policy — mid-work lock, spares-delay exit, interim-work charge

Status: APPROVED 2026-10-01, built on `feature/customer-cancellation-policy` (REF 1CF-CANCEL-POLICY-001). Scope: Service `Booking` and bundle children.

## 1. The rule

1. Once the professional has started work, the customer cannot cancel mid-job.
2. Exception: the job is held for spares and the (cumulative) delay reaches **10 days** (setting, not hard-coded), or the professional's declared arrival date is already more than 10 days away. The customer may then cancel and pay only for the interim work done.
3. Always free: professional left / cannot continue; spares ready but not resumed within 48h; nobody was ever assigned.

## 2. What the code did before (verified)

| # | Finding |
|---|---|
| F1 | A customer could cancel at ANY status, mid-work included: web and API called the admin cancel action. |
| F2 | Bundle cancel cancelled every child, mid-work ones included. |
| F3 | The fee was "minutes since booking created" (15 free, default 0). It measured nothing about work done. |
| F4 | A fee on a cash/unpaid booking was recorded but never collected. |
| F5 | A retained fee was never paid to the professional. |
| F6 | `on_hold_since` is overwritten per hold, so a clock read from it could be reset by resume then re-hold. |

## 3. Policy matrix (enforced server-side in `CancellationPolicy`)

| Status | Customer can cancel? | Charge |
|---|---|---|
| pending / searching_provider | Yes | none (platform never found anyone) |
| assigned, not yet travelling | Yes | `cancellation.assigned_fee` (blank/0 = none). Kept by the platform; the professional receives nothing. The old elapsed-time fee no longer applies to customer cancels |
| provider_en_route, not arrived | Yes | `cancellation.en_route_fee`; the professional gets it minus commission |
| provider_en_route, arrival GPS-verified | Yes | the visit charge (`cancellation.visit_fee_*`); minus commission to the professional; adjusted into the final bill if the work goes ahead |
| professional late beyond `provider_late_minutes` / no-show | Yes, free | none, and the professional's reliability score drops |
| Prime plan with the waiver toggle on | as above | en-route and visit charges are 0 |
| **in_progress** | **No** | n/a |
| on_hold, provider-side | Yes | none |
| on_hold, customer-side (extra-work approval, payment decision, other) | **No**. Extra work unanswered for 72h is auto-declined and the job resumes at the original price | n/a |
| **on_hold awaiting_spares** | After the delay threshold, or when the declared arrival date is beyond it | interim-work charge |
| on_hold awaiting_spares, spares ready but not resumed for 48h | Yes, free | none, and a reliability penalty |
| disputed declaration (open) | No until an admin resolves it | n/a |

Admin keeps its unrestricted override through `AdminCancelBookingAction`.

## 4. The spares clock (`SparesDelayClock`)

- Read only from `booking_status_history`; cumulative across all spares holds, so resume then re-hold cannot reset it.
- Counts only holds where the professional or 1CallFix sources the part. A hold tagged `[src=customer]` (customer supplies their own part) never counts. Customer-caused holds never count.
- Threshold: `cancellation.spares_delay_days` (default 10). Per-category override `cancellation.spares_delay_days.category_{id}` is read when present — a settings row, no code or migration.
- Early unlock: `spares_expected_at` >= today + threshold.
- Notices to customer AND professional: warning N days before the limit (`cancellation.spares_warning_days_before`, default 3 = day 7), unlock, expected date passed (professional asked for a new date), spares-ready-not-resumed. Each is sent once (claimed under a row lock).

## 5. The interim-work charge (`InterimChargeCalculator`) — current rule (A2/A3, 2026-10-04)

Supersedes the earlier progress-% / parts / minimum-labour model. The visit charge is only for a visit where **no work was done**
(CLAUDE.md thumb rule); this section is about work that **was started and then stopped**.

```
base   = price_quoted + approved extra work
cap    = base x cancellation.interim_cap_percent / 100        (snapshot on the booking; null = not configured)
total  = min( the ONE amount the provider declared , cap , base )        — no floor of any kind
```
- The provider enters **one amount** for the work done (labour and parts together) when putting the job on hold for spares
  (`bookings.interim_amount`). Above the cap it is rejected at submission. `cancellation.interim_cap_percent` blank = not
  configured: the provider **cannot** submit an amount (fail closed). The owner's value is 40.
- The visit charge is **never** added once work has started. No declaration on a started job charges nothing.
- A booking where the provider never started work (cancelled before the job began) still pays only the visit charge, and only when
  the provider verifiably arrived.
- Holding for spares requires: the amount, who sources the part, the expected arrival date (evidence upload is optional). The amount is
  cumulative (a re-hold cannot declare less).
- One customer sentence everywhere (`CancellationPolicy::INTERIM_TEXT`): "Work was started but could not be completed. You pay only for the
  work done, up to [X]% of the job price. If you think the amount is wrong, you can raise a dispute and our team will review it."
- The customer can dispute the declared amount at hold time within 48h (`cancellation.dispute_window_hours`); an admin resolves it and may
  correct the amount (never above the cap).
- Removed: `cancellation.interim_min_labour` (minimum labour charge) and the separate progress-% / 50% labour-cap logic. The legacy
  `interim_progress_percent` / `interim_parts_cost` columns remain for history and are no longer written.

### 5a. Pricing disputes after payment (A2) and their refunds (A3)

- After payment (booking closed and paid) the customer raises a dispute from the order page, reason required (`booking_disputes`).
  It goes to the admin queue `/admin/booking-disputes` (open / resolved, outcome, note, audit log). Nothing is refunded automatically.
- An admin resolves it by hand: *no change*, *refund*, or *settled another way*. A refund decision records the amount, **who bears it**
  (provider / company / split — the admin must choose, no default; the shares add up exactly) and **where it goes**.
- **Destination:** an online (Razorpay) payment is refunded to the original payment method (default, a partial gateway refund capped at what
  is still refundable); a wallet payment goes to the wallet; a cash booking is credited to the customer's wallet. An admin may send an online
  payment to the wallet instead only when the customer agrees — that choice and a note are recorded.
- **Approval:** the refund then follows the manual-money approval model: permission `bookings.refund_dispute`, franchise/HQ scope,
  limits (`refund.dispute.franchise_limit` / `hq_limit`), maker-checker above `refund.dispute.dual_approval_above`, queue with age and
  escalation (`refund.dispute.escalate_after_hours`), reason required, row-locked, idempotent, audit-logged. Escalation alerts the next level
  up by push + email (0d pipeline, once per level; switch `alerts.email.dispute_refund_escalation`).
- **Provider share:** recovered through the wallet ledger when the refund runs — debited from the provider's wallet for what it can cover
  (`booking:{id}:dispute-share:{dispute}`), the rest recorded in `provider_dispute_debts` and swept from the wallet at payout-request time
  (`dispute-debt:{id}:settle:*`). All of it goes to the company; no franchise share. It never makes the wallet negative and is never a direct
  balance edit. The provider sees each share, with the dispute reference, on their Earnings page and the debt on Request Payout.
- **Company share:** never debited from the provider.
- Migrations: `2026_10_04_120000` (interim amount, disputes, permission) and `2026_10_04_200000` (destination/bearer columns, debts). Both
  `down()` methods refuse while any dispute or declared interim amount exists.


## 6. Money, in order

1. **Quote then confirm.** The customer sees the exact charge and refund; confirmation carries a signed token bound to booking, status, hold reason and amount (10-minute expiry). A changed figure re-quotes instead of charging.
2. **Re-check under lock.** Eligibility and price are recomputed inside `AdminCancelBookingAction`'s row lock (`feeResolver`), so a racing resume cannot slip through.
3. **Prepaid:** charge deducted, rest refunded (existing idempotent refund path).
4. **Cash / unpaid:** the charge must be settled first: wallet if it covers it, else a gateway order (UPI/card) whose webhook completes the cancellation. The customer is notified with a pay link to the booking page. If the job moved on while they paid, the payment is refunded. Unpaid after 7 days => flagged to admin (never stuck). Admin can waive with a logged reason (`WaiveCancellationChargeAction`).
5. **Provider paid:** charge minus platform commission (existing 3-tier resolver) and franchise share credited to wallets, idempotent (`booking:{id}:interim-payout`); the hourly sweep retries a failed payout.
6. **Idempotent:** a double submit returns the already-cancelled booking without a second refund/payout.
7. **Bundles:** the rules run per child. Locked children keep running; children that would carry a charge must be cancelled individually where the amount is shown.
8. **Audit:** `bookings.cancelled_by_role`, `cancellation_fee_basis` (json inputs/arithmetic), `booking_cancellation_requests`, activity log on waive/dispute resolution.

## 7. Policy text

Shown in plain language (numbers from settings) in the booking wizard payment step, the multi-service checkout, and the spares-hold screen.

## 8. Phase 2 (this build)

- **Policy snapshot.** `bookings.cancellation_policy_snapshot` freezes every fee/timer/radius at booking creation (`Booking::booted` -> `PolicySettings::snapshot`). All fee maths reads the snapshot (`PolicySettings::get`); a booking made before the snapshot existed falls back to the live settings. Changing a setting never changes an existing booking. `null` = not configured -> registry default; `0` = explicit zero.
- **One registry.** `PolicySettings::REGISTRY` lists every key (label, type, bounds, default, help). The admin screen renders and validates from it; nothing is hard-coded elsewhere.
- **Arrival check-in.** `CheckInArrivalAction`: accepted only within `cancellation.arrival_radius_meters` of the booking address; stores lat/lng/time/distance on the booking; the booking status is unchanged (the FSM is untouched). No verified arrival, no visit charge.
- **In-app quote + call log.** `SendBookingQuoteAction`, `RespondToBookingQuoteAction`, `LogCallAttemptAction`. A quote-rejected cancel needs an in-app quote that the customer rejected (or that stayed unanswered for `quote_response_minutes`). A no-show cancel needs arrival + `no_show_wait_minutes` + `no_show_call_attempts`.
- **Professional cancels.** `ProviderCancelBookingAction`: `own_reason` (before arrival; free; reliability drops), `customer_unreachable`, `quote_rejected` (visit fee). Prepaid: the fee is kept from the refund and the professional is paid at once. Unpaid: a `booking_cancellation_requests` row the customer settles (`CustomerCancelBookingAction::payOutstandingCharge`: wallet, else a Razorpay order the webhook completes); flagged to admins after `unpaid_flag_days`; admin can waive with a reason. The professional's share goes through `CommissionService::applyForCancelledBooking` - idempotent, retried by the hourly sweep.
- **Prime waiver.** `plans.waives_cancellation_visit_charges` (toggle on the Plans screen). Evaluated live against the customer's active plan at cancel time (it is a plan attribute, not a setting).
- **Admin.** Super Admin -> Cancellation Policy: all keys, category overrides, live preview, operations queues (held for spares with days counted, pending charges + waive, disputes), audit log (`activity_log`, via `SettingsAuditor`).
- **Documents.** Cancellation invoice = the existing invoice template + numbering with the Booking as documentable; credit note = new `credit_note` type (`CRN/...`) for the refunded part of a prepaid payment. **Tax:** the shared template carries a single amount line and no GST/tax breakdown for any purpose; these documents do exactly the same. Nothing was invented.
- **Bundle cancel.** `CancelBookingBundleAction::preview()` lists visits that will be cancelled and visits that stay (with the reason). A partial cancel without the preview's `token` throws `BundleCancelNeedsConfirmation` (API: 409 + `preview`; web: review panel). A bundle where every open visit can go needs no token.
- **API.** `POST /bookings/{id}/arrive|quote|call-attempt|provider-cancel`, `POST /booking-quotes/{id}/respond`, `POST /cancellation-requests/{id}/pay`, `GET /cancellation/policy`, `GET /booking-bundles/{id}/cancel-preview`. Web screens call the same Actions.

## 9. Deferred (documented, deliberately not built)

- Dispatch ranking reading `providers.reliability_score` (the score is recorded and shown; ranking does not read it).
- A standalone Razorpay Payment Link (the pay flow is the booking page's Pay button / wallet).
- Other modules: parcel, taxi, rentals, marketplace.
- Accepting a quote does not change `price_quoted` (pricing is outside this policy).

## 10. Open business decisions

- With the provider-side rules unset (`arrival_radius_meters`, `no_show_*`, `quote_response_minutes`, `provider_late_minutes`), those rules are inactive: arrival cannot be verified, so no visit charge can be levied. Enter the values to switch them on.
- Is an admin-waived professional-raised charge ever paid to the professional? Currently no.
- Should `en_route_fee` / visit fee also apply when the customer cancels a scheduled booking far ahead of its time?

## 11. Known test issues (not fixed in this branch)

- **`NotificationCenterAuditTest::test_provider_status_shows_log_fallback_by_default`** fails whenever `.env` sets a real `PUSH_DRIVER`. It fails identically on clean `main`. Later fix: the test should force `config('push.driver')` to `log` itself instead of depending on `.env`.
- **A DailyDigest test looks order-dependent.** It failed once in a full parallel run and passed in isolation and in later full runs. Later investigation needed.
- **The admin layout loads scripts from `unpkg.com` (and Firebase from `gstatic.com`) at runtime.** When those hosts are slow or unreachable the page stalls and Livewire never starts. Later fix (not this branch): bundle the Alpine/Livewire/Trix assets through Vite so admin does not depend on a public CDN.
