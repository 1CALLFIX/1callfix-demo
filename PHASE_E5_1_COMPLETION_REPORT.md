# Phase E5.1 — Close the Bundle-Cancellation Refund Gap & the Status-Latch Gap

**Branch:** `feature/customer-web-foundation` · **Parent:** `246efa5` (Phase E7) · **`main`:** untouched at `0381b55`

Phase E7's QA (`PHASE_E_COMPLETION_REPORT.md` §5) reported two gaps in the multi-service bundle work as **KNOWN-BROKEN, pinned by test**. This patch closes exactly those two, and nothing else.

> Historical note: no prior phase's report actually *claimed* a bundle-cancel endpoint or a written status latch existed — E3/E5/E6 consistently listed "bundle cancellation/refund" as deferred, and E7 is the report that surfaced both gaps. The source of truth used throughout E5.1 was the running repository and executed tests, not any prior narrative.

---

## Scope confirmation

| Check | Status |
|---|---|
| Only two production behaviours changed: (1) bundle-level cancellation/refund, (2) persisted bundle terminal status latch | ✅ |
| No third production behaviour modified | ✅ — `AdminCancelBookingAction` gained one **optional** param (`reconcileBundle = true`, default preserves every existing caller) + one guarded post-commit hook; `CompleteBookingAction` gained one guarded post-commit hook. No signature broke. |
| `main` untouched | ✅ still `0381b55` |
| No prior commit amended | ✅ new commit on top of `246efa5` |
| E7 scenario preserved, only the two gap-assertions changed | ✅ steps 1–8 of `E7_FullBundleLifecycleTest` byte-for-byte unchanged; steps 9–11 now assert the corrected behaviour end-to-end |
| No existing test weakened or skipped | ✅ |

---

## Baseline / after (verified by execution, not historical counts)

```
E7 baseline (fresh full run, before any E5.1 change)
  tests      1896
  passed     1896
  assertions 5335
  failures   0
  errors     0
  skipped    0
  exit       0
```

```
After E5.1 (fresh full run)
  tests      1908   (+12)
  passed     1908
  assertions 5452   (+117: +110 new E5.1 assertions, +7 tightened E7 assertions)
  failures   0
  errors     0
  skipped    0
  exit       0
```

New tests: `tests/Feature/BookingBundle/E5_1_BundleCancelRefundTest.php` — **12 tests / 110 assertions**, every financial assertion re-fetches the persisted `Payment` / `Wallet` / `WalletTransaction` row (never "mock was called" / "HTTP 200").

Focused pre-full regression run (bundle + FSM + settlement + customer booking API + wallet + commission + pricing + dispatch): **209 tests / 1032 assertions / 0 failures.**

---

## Gap 1 — bundle-level cancellation / refund

### Behaviour before (traced, confirmed by the pinning E7 tests)

A bundle child could be cancelled only through `POST /api/bookings/{id}/cancel` → `AdminCancelBookingAction` → `CancellationService::refundIfPaid($child, $fee)`, which does
`Payment::where('booking_id', $child->id)->where('status', 'captured')`.
E3 puts **one** `Payment` on the bundle (`purpose = 'booking_bundle'`, `booking_bundle_id` set, `booking_id` NULL) — never one per child — so that lookup returns nothing and the refund is a silent no-op. Cancelling a paid bundle child returned **₹0**.

### Fix

| Piece | File |
|---|---|
| `POST /api/booking-bundles/{id}/cancel` route | `routes/api.php` |
| `BookingBundleController::cancel()` — resolve + ownership (404 not 403, matching `show()`), 409 on already-terminal | `app/Http/Controllers/API/BookingBundleController.php` |
| `CancelBookingBundleAction` — cancels every still-active child through the **existing** `AdminCancelBookingAction` (`reconcileBundle: false`), then reconciles the shared Payment **once** | `app/Actions/CancelBookingBundleAction.php` (new) |
| `BundleSettlementService` — the shared latch + refund engine | `app/Services/BundleSettlementService.php` (new) |
| `AdminCancelBookingAction` — optional `reconcileBundle` param + guarded post-commit call to `BundleSettlementService::settleFromChildren()` when the cancelled booking has a `booking_bundle_id` (so cancelling **one** bundle child via the *single-booking* endpoint also reconciles the shared Payment) | `app/Actions/AdminCancelBookingAction.php` |

Fee math is **not** reimplemented: each child's `cancellation_fee` is still produced by `CancellationService::calculateFee()` inside `AdminCancelBookingAction`, exactly as a standalone cancel.

### The refund formula — derived from `refundIfPaid()`, not invented

`CancellationService::refundIfPaid()` establishes the rule for a standalone booking:

```
refund = round(max(payment.amount - retainedFee, 0), 2)          # keep the fee, return the rest, once
```

`BundleSettlementService::reconcileRefund()` applies the **same per-item rule**, summed across the children, against the one shared bundle `Payment`, guarded against a double refund by `payments.refunded_amount`:

```
retained per child:
  cancelled child ....... child.cancellation_fee          # identical to refundIfPaid's "retainedFee"
  completed child ....... child.price_quoted              # service delivered -> its share of the frozen
                                                          # bundle total is consumed, nothing to refund.
                                                          # price_quoted (not price_final): approved
                                                          # extras were never part of the bundle Payment.
  still-active child .... child.price_quoted              # not cancelled yet -> nothing refundable yet;
                                                          # a later cancel reconciles again.

refundDue = round(max(payment.amount - Σ retained, 0), 2)
refundNow = round(max(refundDue - (payment.refunded_amount ?? 0), 0), 2)
```

- `payment.amount` (what was actually charged) is the anchor — the same field `refundIfPaid()` uses — so this stays correct even if `total_price_final` is set by a future settlement step.
- Disbursement is the **identical branch** `refundIfPaid()` uses: `payment.gateway === 'wallet'` → `WalletService::credit(customer, refundNow, "...", "booking_bundle:{id}:wallet-refund")`; otherwise `PaymentGateway::refund(payment.gateway_payment_id, refundNow, "...")`.
- `payment.refunded_amount` accumulates; `payment.status` / `bundle.payment_status` become `refunded` when the cumulative refund reaches `payment.amount`, else `partially_refunded`.
- Cash bundles have no `booking_bundle` `Payment` row at all → no-op (nothing to refund), exactly like a cash single-booking.

### Proven closed by (all DB-backed)

| Assertion | Test |
|---|---|
| Fully-pending wallet bundle → full total refunded once; `Payment.refunded_amount` = total, `status` = `refunded`; wallet restored; **no** per-child `Payment` row | `E5_1_BundleCancelRefundTest::test_cancelling_a_fully_pending_wallet_bundle_refunds_the_full_total` |
| Non-zero fees summed & retained: 3×₹50 kept, `1500 − 150 = 1350` refunded; `partially_refunded` | `::test_cancellation_fees_are_summed_and_retained_across_children` |
| Online (gateway) bundle → exactly **one** `PaymentGateway::refund('pay_…', 950.0, …)`; persisted `refunded_amount = 950`; **no** wallet credit | `::test_online_paid_bundle_cancellation_issues_one_gateway_refund_for_the_remainder` |
| Completed child untouched (status, fee, commission, provider wallet) and its `price_quoted` retained from the refund | `::test_a_completed_child_is_untouched_and_its_price_is_retained_from_the_refund` |
| Cancelling **one** child via the *single-booking* endpoint reconciles the shared Payment for that child only | `::test_cancelling_one_child_via_the_single_booking_endpoint_reconciles_the_shared_payment_without_latching` |
| No over-refund / no duplicate: 2nd bundle-cancel → 409, wallet + `refunded_amount` unchanged, one refund txn | `::test_cancelling_an_already_cancelled_bundle_is_a_409_with_no_second_refund` |
| Engine idempotency: `settleFromChildren()` ×3 → refunds once, then `null`, `null` | `::test_settle_from_children_is_idempotent_at_the_engine` |
| Child bookings never get independent `Payment` rows | asserted in the two tests above + `E7_FullBundleLifecycleTest` step 9 |
| Ownership: another customer → 404, no state change | `::test_a_customer_cannot_cancel_another_customers_bundle` |
| `reason` required → 422 | `::test_bundle_cancel_requires_a_reason` |
| Full lifecycle: create → wallet pay → dispatch → consolidation → complete 2 → **bundle-cancel the 3rd** → `500` refunded once, `Payment.partially_refunded`, `refunded_amount = 500`, net customer spend = the two delivered children only | `E7_FullBundleLifecycleTest` (step 9–11, updated) |

---

## Gap 2 — persisted bundle terminal status latch

### Behaviour before

`BookingBundle.status` (`enum('active','completed','cancelled')`, default `active`) was never advanced by any code. `derivedStatus()` (E1, computed, never stored) was correct; the stored column was permanently `active`.

### Fix

`BundleSettlementService::latchTerminalStatus(BookingBundle $bundle)` — a **one-way** `active → terminal` write, decided **solely** by `BookingBundle::derivedStatus()` (no new status rule invented):

```
if bundle.status !== 'active'                 -> no-op (never re-flips)
if derivedStatus() ∉ {completed, cancelled}   -> no-op (children not all terminal)
else bundle.status = derivedStatus()          -> 'completed' (>=1 completed) or 'cancelled' (all cancelled)
```

Called (inside a `lockForUpdate` transaction, `children` freshly loaded) from `BundleSettlementService::settleFromChildren()`, which is invoked guarded-by-`booking_bundle_id` from:

| Trigger | File |
|---|---|
| a bundle child is **completed** | `app/Actions/CompleteBookingAction.php` (post-commit, `try/catch`-logged, same placement as commission/loyalty/receipt) |
| a bundle child is **cancelled** (single-booking endpoint) | `app/Actions/AdminCancelBookingAction.php` (post-commit, `reconcileBundle` path) |
| the **whole bundle** is cancelled | `app/Actions/CancelBookingBundleAction.php` (once, after the per-child loop) |

A completion never produces a refund — `reconcileRefund()` only pays out for children whose status is `cancelled`.

### Proven closed by (fresh `BookingBundle::findOrFail($id)->status`, not `derivedStatus()`, not the in-memory instance)

| Assertion | Test |
|---|---|
| Last child completes → stored `status` becomes `completed`; one-of-two done → still `active` | `E5_1_BundleCancelRefundTest::test_last_child_completion_latches_the_bundle_to_completed_in_the_database` |
| All children cancelled, none completed → stored `status` becomes `cancelled` | `::test_all_children_cancelled_none_completed_latches_to_cancelled` |
| Latch is idempotent, never re-flips (re-run ×2 after latching) | `::test_the_status_latch_is_idempotent_and_never_re_flips`, `::test_settle_from_children_is_idempotent_at_the_engine` |
| Cancelling one child while a sibling is still active does **not** latch (stays `active`, `derivedStatus()` = `partially_completed`) | `::test_cancelling_one_child_via_the_single_booking_endpoint_reconciles_the_shared_payment_without_latching` |
| Full lifecycle: 2 completed + 1 bundle-cancelled → stored `status` = `completed` | `E7_FullBundleLifecycleTest` step 10 (updated) |

---

## E7 regression — scenario preserved, two assertions corrected

`E7_FullBundleLifecycleTest` steps 1–8 are unchanged. The changes, all in steps 9–11:

| Was (pinned the gap) | Now (asserts the fix) |
|---|---|
| step 9 cancelled child 3 via `POST /api/bookings/{c3}/cancel` | via `POST /api/booking-bundles/{bundle}/cancel` |
| `customer wallet unchanged after cancel` + comment "KNOWN GAP: … issues no refund" | `wallet += 500`; exactly one `booking_bundle:{id}:wallet-refund` credit of `500`; `Payment.status = partially_refunded`, `refunded_amount = 500`; still exactly one bundle `Payment`; child 3 still has **no** `Payment` row |
| `bundlePayment->status === 'captured'` | `=== 'partially_refunded'` |
| step 10 `$bundle->fresh()->status === 'active'` + comment "stored latch is never written" | `BookingBundle::findOrFail($id)->status === 'completed'` (fresh fetch) + `payment_status === 'partially_refunded'` |
| step 11 "one debit … zero refunds"; `is_credit` customer txns == 0 | net customer spend == the two delivered children only; the single customer credit is the `booking_bundle:{id}:wallet-refund` |

Result: `E7_FullBundleLifecycleTest` — 1 test, **77 assertions** (was 70), green, no internal Action mocked.

`BundleRefundDuplicationE7Test` — unchanged tests (2 / 9 assertions); its docblock updated to state that the per-child `refundIfPaid()` being **inert** on a bundle child is now load-bearing (it is what keeps `BundleSettlementService` the sole refunder and rules out a double refund when `AdminCancelBookingAction` runs both on one cancel).

---

## Files

```
 app/Actions/AdminCancelBookingAction.php           |  +optional param, +guarded hook
 app/Actions/CancelBookingBundleAction.php          |  new
 app/Actions/CompleteBookingAction.php              |  +guarded hook
 app/Http/Controllers/API/BookingBundleController.php |  +cancel()
 app/Services/BundleSettlementService.php           |  new
 routes/api.php                                     |  +1 route
 tests/Feature/BookingBundle/E5_1_BundleCancelRefundTest.php   |  new (12 tests)
 tests/Feature/BookingBundle/E7_FullBundleLifecycleTest.php    |  steps 9–11 corrected
 tests/Feature/BookingBundle/BundleRefundDuplicationE7Test.php |  docblock only
 PHASE_E_COMPLETION_REPORT.md                       |  §5/§7 updated: the two gaps now marked CLOSED by E5.1
 PHASE_E5_1_COMPLETION_REPORT.md                    |  this file
```

No migration: `booking_bundles.status` / `.cancellation_note` / `.cancellation_fee` and `payments.refunded_amount` already exist (E1 / earlier schema).

---

## Both gaps: CLOSED

- **Gap 1** — a paid bundle child cancellation reconciles against the ONE shared bundle `Payment` with the correct amount and no duplicate/over-refund. Proven by `E5_1_BundleCancelRefundTest` (12 DB-backed tests) and `E7_FullBundleLifecycleTest` step 9–11.
- **Gap 2** — `BookingBundle.status` changes to the correct terminal value and stays there after a fresh DB fetch. Proven by `E5_1_BundleCancelRefundTest::test_last_child_completion_latches_the_bundle_to_completed_in_the_database` and `::test_all_children_cancelled_none_completed_latches_to_cancelled`.

`main` untouched. No commit amended. Ready for review / merge.
