# 04 — Cash Payment and Direct Provider Remittance Audit

**Ref:** `1CF-COMPREHENSIVE-AUDIT-20260922-1110` · Read-only, no code changed.

## 1. What exists today (verified)

Source: `app/Services/CommissionService.php::applyForBooking()`, `app/Actions/CreateBookingAction.php`,
`app/Actions/CompleteBookingAction.php`, `app/Services/PayoutService.php`,
`app/Models/ProviderCommissionReceivable.php`, migration `2026_09_06_002000_create_provider_commission_receivables_table.php`.

```
Customer selects cash               → CreateBookingAction: NO Payment row created,
                                       booking.payment_status stays 'pending' (DB default)
        ↓
Provider completes (OTP-verified)   → CompleteBookingAction: booking.status = 'completed'
                                       booking.payment_status is NEVER touched — stays 'pending' forever
        ↓
CommissionService::applyForBooking() runs (outside the completion lock, own transaction):
   - computes platform/franchise/provider split exactly as any other booking
   - Commission row written (reporting)
   - provider wallet is NOT credited (they hold the cash)
   - franchise owner wallet is NOT credited
   - ProviderCommissionReceivable row created: amount_owed = platform + franchise portions,
     amount_settled = 0, status = 'outstanding'
        ↓
Settlement: PayoutService::settleCashCommissionReceivables($providerId)
   - runs ONLY when the provider calls PayoutService::request() (self-service payout request)
   - sweeps the provider's WALLET balance (from their own digital-paid job earnings) against
     outstanding receivables, oldest first, row-locked
   - franchise owner is credited its proportional share only as the sweep proceeds
        ↓
No independent "remittance" step, no proof-of-payment field, no admin verification exists anywhere.
```

## 2. Findings, by the business's required output list

### 2.1 "Whether cash payments are currently visible in admin"
**Status: Missing.**
- No `Payment` row is ever created for a cash booking (`Payment::create()` only appears in the
  wallet-payment branch of `CreateBookingAction.php`, never in a cash branch — confirmed by direct
  grep, no cash-specific `Payment::create` anywhere in the codebase).
- `booking.payment_status` never advances past its DB default of `'pending'`
  (`2026_08_01_017000_create_bookings_table.php:28`) for a cash booking — not at creation, not at
  completion (`CompleteBookingAction.php` never assigns `payment_status`, confirmed by grep).
- Consequence: **a fully completed, fully cash-paid booking permanently displays as payment
  "pending"** everywhere `payment_status` is read — customer order view, any admin bookings/payments
  screen, exports. This is misleading, not just cosmetic: it means no query keyed off "payments" can
  ever surface a cash transaction, because no `payments` row exists to query.
- The only place cash dues become visible to *anyone* is `outstandingCashCommission()`
  (`PayoutService.php:220`), rendered exclusively on the **provider's own** self-service
  `RequestPayout` screen (`app/Livewire/Provider/RequestPayout.php`,
  `resources/views/livewire/provider/request-payout.blade.php` — confirmed the only two references
  to `ProviderCommissionReceivable`/`outstandingCashCommission` in any Livewire/view file).
- **There is no admin Livewire component, report, or export for cash dues at all** — searched
  `app/Livewire/Commissions`, `app/Livewire/Payouts`, and every admin-area component; none reference
  `ProviderCommissionReceivable`.

### 2.2 "Whether cash payments can be audited end to end"
**Status: Missing / Unsafe.** No `Payment` row → no capture timestamp, no gateway reference, no
receipt artifact for the transaction itself. The only audit trail is the `Commission` row (a split
calculation, not a payment record) and the `ProviderCommissionReceivable` row (a debt ledger, not a
remittance record). Neither carries who collected the cash, when, or any proof.

### 2.3 "Whether commission is calculated correctly"
**Status: Implemented and verified.** The exact same 3-tier rate resolution
(`ProviderCommercialRateResolver`) and split math run for cash as for any other payment method —
confirmed reading `CommissionService::applyForBooking()` in full. This part is sound.

### 2.4 "Whether the provider's platform dues are calculated at the correct booking state"
**Status: Implemented and verified.** Dues are created at `completion` (inside
`CompleteBookingAction`'s post-lock sequence), not at booking creation — correct, since the final
price (`price_final`, including approved extras) is only known at completion.

### 2.5 "Whether direct remittance is supported"
**Status: Missing.** There is no "provider submits a remittance (UPI/bank transfer/cash-deposit
reference + proof), admin verifies it" flow anywhere in the codebase. The only recovery mechanism is
the passive wallet sweep in §1, triggered exclusively by the provider's own voluntary payout
request. This directly contradicts the business's explicit requirement for an auditable remittance
record with method, reference number, proof, submitted-by, verified-by, and verification status —
none of those fields exist on `ProviderCommissionReceivable` (its `$fillable` is: `provider_id,
booking_id, commission_id, platform_portion, franchise_portion, amount_owed, amount_settled,
franchise_settled, status, settled_at` — confirmed by reading the model).

### 2.6 "Whether pending balances can be incorrectly hidden or bypassed"
**Status: Unsafe / financially incomplete.** Two concrete, evidenced failure modes:

1. **Debt never surfaces if the provider never requests a payout.** A provider who only ever takes
   cash jobs (or takes far more cash than digital jobs) accumulates `ProviderCommissionReceivable`
   debt that is never collected, because settlement only runs inside `PayoutService::request()`.
   There is no scheduled sweep (`routes/console.php` was read in full for this audit — only four
   scheduled commands exist: `campaigns:dispatch-due`, `plans:renew-due`, `referrals:expire-due` +
   `kyc:send-reminders`, `digest:send-daily`; none touch commission receivables), and no admin
   report exists to even notice the growing balance (§2.1).
2. **No restriction on new job assignment while dues are outstanding.** Confirmed by grep: neither
   `DispatchService`, `ProviderAvailabilityService`, nor `AcceptBookingAction` reference
   `ProviderCommissionReceivable` or any cash-debt check. `PayoutService::assertNoBlockingCashDebt()`
   (line 204) only blocks the provider's own *payout request* — it has zero effect on their ability
   to keep accepting new cash jobs and accumulating more debt. This is the opposite of the business's
   "no unnecessary... long-running outstanding balance" instruction: today, outstanding balance can
   grow indefinitely and invisibly.

### 2.7 Reversal / correction process
Not audited in this pass (no `ProviderCommissionReceivable` reversal/void path was found in the
grep sweep above; a dedicated search for admin correction actions is required before the roadmap
finalizes this section — flagged as **unverified**, not confirmed-missing).

## 3. Summary classification

| Capability | Status |
|---|---|
| Commission split math (cash) | Implemented and verified |
| Dues calculated at correct booking state | Implemented and verified |
| Cash payment recorded as a `Payment`/receipt | **Missing** |
| `booking.payment_status` reflects cash reality | **Unsafe / financially incomplete** |
| Admin visibility of cash dues | **Missing** |
| Direct remittance workflow (method, reference, proof, verify) | **Missing** |
| Automatic/scheduled collection of stale dues | **Missing** |
| Dispatch restriction tied to unresolved dues | **Missing** |
| Reversal/correction/void path | **Unverified** |

## 4. Required changes (roadmap input — not implemented in this pass)

| # | Change | Layer | Risk | Depends on |
|---|---|---|---|---|
| 1 | Give a completed cash booking a real payment record: either create a `Payment` row (`gateway = 'cash'`, `status = 'captured'`) at completion, or explicitly define `payment_status` semantics for cash (e.g. `paid` at completion since the money is physically collected) | `CompleteBookingAction` + `Payment` model | Medium — touches a completion path every booking goes through; must not disturb the non-cash paths | Business decision: does "paid" mean "customer paid the provider" or "platform has been remitted"? These are different signals and today neither is tracked. |
| 2 | New `provider_remittances` (or similarly named) table: `provider_id, amount, method, reference_number, proof_path, submitted_at, submitted_by, verified_by, verified_at, status (pending/verified/rejected), receivable_ids[] or a pivot` | New migration + model | Medium — additive, no existing table touched | Business decision on accepted remittance methods (§4 of the brief) |
| 3 | Admin screen: outstanding cash dues per provider (list + drill into bookings), with the existing `[ All ] [ Outstanding ] [ Settled ]` filter pattern requested in §5 of the brief | New admin Livewire component | Low — read-mostly, reuses `ProviderCommissionReceivable` | #2 for the "record remittance" action on this screen |
| 4 | Admin action: record + verify a remittance against one or more outstanding receivables (manual admin action, not automatic) | Admin Livewire + a `RecordProviderRemittanceAction` | Medium — money-adjacent; needs the same authorization-matrix treatment as every other financial action in §6 of the brief | #2, #3 |
| 5 | Scheduled sweep/report (not auto-cancel dues — just surface them): flag providers whose outstanding cash debt exceeds a configurable threshold or age, for admin follow-up | New console command + schedule entry | Low | #3 |
| 6 | Decide, then implement if approved: restrict new cash-job dispatch eligibility once a provider's outstanding debt crosses a threshold (business explicitly asked this be investigated, not pre-decided) | `DispatchService`/`ProviderAvailabilityService` | High — directly affects provider earnings and dispatch fairness; needs explicit business sign-off before implementation | Open business decision (§5 below) |

## 5. Open business decisions (cannot be resolved from the repo)

1. **What counts as "the customer paid"?** Does `payment_status = paid` on a cash booking mean (a)
   the customer physically paid the provider, or (b) the platform's share has actually been remitted
   and verified? These need different triggers and today track neither.
2. **Accepted remittance methods** — cash deposit to a company account, UPI, bank transfer, or a mix
   with per-country rules. The brief explicitly declines to pre-choose this; it must come from the
   business.
3. **Debt threshold for dispatch restriction** — whether/when unresolved cash debt should block a
   provider from receiving new job offers, and the amount/age threshold.
4. **Remittance cadence** — is remittance expected per-booking, daily, weekly, or on-demand at
   provider discretion (subject to a cap)? This determines whether #5 above is a hard deadline system
   or an advisory report.
