# Phase E — Multi-Service Booking: Completion Report (E1 → E7)

**Branch:** `feature/customer-web-foundation`
**`main`:** untouched at `0381b55` (`docs: add customer web app readiness audit and implementation plan`)
**Status:** E7 (QA + one end-to-end scenario test + this report) complete. No production deployment performed. Ready for review / merge.

---

## 1. Commit chain

| Phase | Hash | Parent | Summary |
|------|------|--------|---------|
| E1 | `c7d4e19` | `d5c923c` (Phase D) | booking bundle data foundation — additive `booking_bundles` wrapper, `Booking.booking_bundle_id` / `payments.booking_bundle_id` nullable columns, `Orderable` on `BookingBundle`, `derivedStatus()` (computed, never stored) |
| E2 | `d917991` | `c7d4e19` | multi-service booking creation — `POST /api/booking-bundles`, `CreateBookingBundleAction` (one bundle + one child `Booking` per service, reusing `CreateBookingAction` + the Phase-D authoritative pricing cascade), ONE aggregate wallet debit, customer-scoped idempotency key |
| E3 | `e37b43f` | `d917991` | bundle Razorpay + wallet payments — `BookingBundlePaymentService`, `POST /api/booking-bundles/{id}/pay/create-order\|confirm`, `RazorpayWebhookHandler` `booking_bundle` branch; webhook (not `confirm()`) is authoritative; one Payment per bundle; children marked `paid` on capture |
| E4 | `ec4de9d` | `e37b43f` | provider availability + bundle dispatch consolidation — `ProviderAvailabilityService` (half-open `[start,end)` interval check), `BundleConsolidationJob` + `ConsolidationOfferTimeoutJob`, `AcceptBookingAction` provider-row `lockForUpdate` + in-transaction availability recheck |
| E5 | `df04c30` | `ec4de9d` | provider acceptance OTP, completion, settlement — `BookingOtpService` (expiry / attempt cap / single-use over the existing `start_otp` / `completion_otp` columns), `BookingOtpException`, receipt materialised on completion via the existing `DocumentService`, per-child bundle settlement + concurrency tests. Single-booking FSM untouched. |
| E6 | `31561e4` | `df04c30` | customer web transactional half — Livewire booking wizard → payment → tracking → OTP display → invoice → review → rebook, on the Phase B/C shell, reusing `CreateBookingAction` / `AdminCancelBookingAction` / `WalletService` / `ReviewService` / `DocumentService`. No backend rebuild. |
| **E7** | *(this commit)* | `31561e4` | **end-to-end QA — full regression sweep, security re-check against the 10-item risk table, one true end-to-end scenario test (`E7_FullBundleLifecycleTest`), one refund-duplication regression test (`BundleRefundDuplicationE7Test`), this report. No product behaviour changed.** |

---

## 2. Test counts

### Added per phase (bundle / multi-service specific)

| Phase | Files | Tests |
|------|-------|-------|
| E1 | `BookingBundle/BookingBundleModelTest` | 17 |
| E2 | `Api/CustomerBookingBundleApiTest` (15), `Pricing/BundlePricingAuthorityTest` (7) | 22 |
| E3 | `BookingBundle/BookingBundlePaymentTest` | 25 |
| E4 | `Dispatch/ProviderAvailabilityServiceTest` (14), `Dispatch/BundleDispatchConsolidationTest` (8), `Dispatch/AcceptBookingAvailabilityConcurrencyTest` (3), `Dispatch/BundleConsolidationMultiChildTest` (1), `Dispatch/BundleE4IntegrationTest` (1) + `Support/BundleConsolidationHelpers` trait | 27 |
| E5 | `Booking/BookingOtpHardeningTest` (15), `Booking/BookingCompletionSettlementTest` (8), `BookingBundle/BundleChildCompletionE5Test` (5), `Dispatch/AcceptBookingIdempotencyE5Test` (2) | 30 |
| E6 | `CustomerWeb/BookingWizardTest` (11), `CustomerWeb/CustomerOrderTrackingTest` (10), `CustomerWeb/CustomerWalletAndInvoiceTest` (6), `CustomerWeb/CustomerAddressesTest` (6), `CustomerWeb/CustomerWebSecurityE6Test` (5), `CustomerWeb/CustomerJourneyE6Test` (1) | 39 |
| **E1–E6 subtotal** | | **160** |
| **E7** | `BookingBundle/E7_FullBundleLifecycleTest` (1), `BookingBundle/BundleRefundDuplicationE7Test` (2) | **3** |
| **Phase E total added** | | **163** |

### Full suite size

| Point | Tests | Assertions | Result |
|------|-------|-----------|--------|
| After E6 (`31561e4`, E7 baseline) | 1893 | 5256 | pass, exit 0 |
| After E7 additions | 1896 | 5335 | pass, exit 0 |

(+3 tests, +79 assertions: `E7_FullBundleLifecycleTest` 1 test / 70 assertions, `BundleRefundDuplicationE7Test` 2 tests / 9 assertions.)

`php artisan test` (via `laravel/pao`) emits one JSON summary line; the by-name / by-file confirmations below were re-run through `vendor/bin/phpunit --testdox`.

---

## 3. Full regression sweep (Task 1)

Full suite: **1896 tests / 1896 passed / 5335 assertions / 0 failures / exit 0** (`~297s`). No failure was patched into passing — the three E7 additions are the only new tests, and no existing test was modified or weakened.

Named regression areas, re-run individually and confirmed green:

| Requested | Actual file(s) | Tests | Result |
|-----------|----------------|-------|--------|
| WalletConcurrencyTest / wallet-lock | `Finance/WalletConcurrencyTest` | 7 | ✅ green |
| PricingAuthorityTest / BundlePricingAuthorityTest | `Pricing/PricingAuthorityTest` (17), `Pricing/BundlePricingAuthorityTest` (7) | 24 | ✅ green — no client-supplied price ever accepted (single or bundle) |
| BookingFsmTest | `Booking/BookingFsmTest` | 21 | ✅ green — single-booking FSM untouched by E1–E7 |
| ServiceMatchingJobRaceTest | `Dispatch/ServiceMatchingJobRaceTest` | 4 | ✅ green — dispatch race safety holds pre- and post-E4 |
| OrderableContractTest | `OrderEngine/OrderableContractTest` (3, `Booking`) + `BookingBundle/BookingBundleModelTest::test_bundle_implements_orderable_and_every_method_delegates_to_a_column` | | ✅ green — `BookingBundle` still satisfies `Orderable` as a zero-behaviour column delegation |
| CommissionAbuseTest / settlement abuse | `Finance/CommissionAbuseTest` (9), `Finance/CommissionIdempotencyTest` (3), `Booking/BookingCompletionSettlementTest` (8) | 20 | ✅ green — settlement stays per-child; no bundle-level payout shortcut exists |
| All E1–E6 phase files, by file | 19 files | 160 | ✅ green (`OK (160 tests, 712 assertions)`) |

Note on `OrderableContractTest`: that file (Phase 22.2) only exercises `Booking`. `BookingBundle`'s `Orderable` conformance is proven in `BookingBundleModelTest` (E1) and re-confirmed here.

---

## 4. Security / abuse re-check — the 10-item risk table (Task 2)

Every item is closed by an existing, passing test. Item 7 was the thinnest — its refund engine was only covered transitively through the cancel endpoint's FSM guard — so a focused regression test (`BundleRefundDuplicationE7Test`) was **added**, not merely noted.

| # | Risk | Closed by |
|---|------|-----------|
| 1 | Duplicate bundle submission | `Api/CustomerBookingBundleApiTest::test_an_exact_retry_with_the_same_idempotency_key_returns_the_original_bundle`, `::test_reusing_a_key_with_a_materially_different_body_is_rejected`, `::test_the_same_idempotency_key_from_another_customer_cannot_reuse_the_first_customers_bundle` (+ `CreateBookingBundleAction` `QueryException` race-loser recovery) |
| 2 | Wallet double-spend | `Finance/WalletConcurrencyTest::test_a_stale_balance_snapshot_does_not_authorise_a_second_debit`, `::test_two_wallet_bookings_of_300_against_a_balance_of_500_cannot_both_succeed`; `BookingBundle/BookingBundlePaymentTest::test_one_wallet_cannot_pay_two_bundles_that_together_exceed_the_balance`; `Api/CustomerBookingBundleApiTest::test_insufficient_wallet_rolls_the_entire_bundle_back` |
| 3 | Two providers accepting the same child | `Dispatch/AcceptBookingIdempotencyE5Test::test_a_second_provider_with_a_live_offer_loses_the_race_cleanly`, `::test_the_same_provider_re_accepting_produces_no_second_assignment_or_side_effect` (`AcceptBookingAction` locks the booking row + null-`provider_id` gate) |
| 4 | Provider availability race (same provider, overlapping windows) | `Dispatch/AcceptBookingAvailabilityConcurrencyTest::test_second_overlapping_acceptance_for_the_same_provider_is_rejected`; `Dispatch/ProviderAvailabilityServiceTest::test_overlapping_request_is_not_available` (+ 13 sibling interval cases) |
| 5 | Duplicate payment webhook | `BookingBundle/BookingBundlePaymentTest::test_a_duplicate_capture_webhook_does_not_re_run_bundle_side_effects`, `::test_end_to_end_bundle_payment_create_order_confirm_then_webhook_capture` (late duplicate webhook changes nothing) |
| 6 | Duplicate completion / payout | `BookingBundle/BundleChildCompletionE5Test::test_completing_one_child_twice_does_not_double_settle_or_re_derive_the_bundle`; `Booking/BookingCompletionSettlementTest::test_a_duplicate_completion_cannot_double_settle`; `Finance/CommissionAbuseTest::test_replaying_the_completion_request_does_not_pay_a_second_time`; `Finance/CommissionIdempotencyTest::test_calling_apply_for_booking_twice_does_not_double_credit_the_wallet` |
| 7 | Partial-refund duplication | **Single booking:** `BookingBundle/BundleRefundDuplicationE7Test::test_refund_if_paid_is_idempotent_for_a_single_booking` (**new** — proves `CancellationService::refundIfPaid()` credits exactly once because the captured Payment flips to `refunded`), backed by the endpoint-level FSM guard in `Booking/BookingFsmTest::test_an_already_cancelled_booking_cannot_be_cancelled_again` and the single refund in `Api/CustomerBookingApiTest::test_cancellation_fee_and_refund_behavior_is_honored_via_the_real_cancellation_service`. **Bundle child:** `BookingBundle/BundleRefundDuplicationE7Test::test_refund_if_paid_on_a_bundle_child_moves_no_money_and_leaves_the_bundle_payment_intact` (**new**) — there is no bundle-level partial-refund path to duplicate (see §6 known limitation). |
| 8 | Price change post-payment | `BookingBundle/BookingBundlePaymentTest::test_create_order_is_rejected_for_an_already_paid_bundle`, `::test_a_client_supplied_amount_has_zero_influence_on_the_gateway_order`; `Pricing/BundlePricingAuthorityTest::test_a_manipulated_client_price_cannot_replace_the_authoritative_child_prices` (bundle total frozen at creation; webhook + create-order read the stored `total_price_final ?? total_price_quoted`, never the request) |
| 9 | Unauthorized sibling access | `BookingBundle/BookingBundlePaymentTest::test_a_customer_cannot_confirm_their_bundle_with_another_bundles_order_id`, `::test_a_capture_webhook_only_pays_the_bundle_that_owns_the_order`, `::test_another_customer_cannot_create_an_order_or_confirm_for_a_bundle` |
| 10 | IDOR on bundle IDs | `Api/CustomerBookingBundleApiTest::test_a_customer_cannot_view_another_customers_bundle` (404, not 403); `CustomerWeb/CustomerWebSecurityE6Test::test_mounting_another_customers_order_is_a_404` |

---

## 5. End-to-end scenario test (Task 3)

`tests/Feature/BookingBundle/E7_FullBundleLifecycleTest.php` — one test method, 70 assertions, no internal Action mocked (bundle creation via the real `POST /api/booking-bundles`; completion via the real `POST /api/bookings/{id}/complete`; cancellation via the real `POST /api/bookings/{id}/cancel`; acceptance / start via the real Actions; consolidation via the real `BundleConsolidationJob`). Only the queue is faked, so after-commit jobs are asserted on and then run in order — the same boundary `BundleE4IntegrationTest` / `BundleChildCompletionE5Test` already draw.

Walkthrough (customer wallet opens at ₹100,000; services priced 400 / 600 / 500; franchise takes a 10% platform fee, 0% revenue share):

1. 3-service bundle created at three scheduled times, wallet-funded — `total_price_quoted` = 1500 (server-computed).
2. **One** aggregate wallet debit of 1500 → balance 98,500; one `Payment` (`purpose=booking_bundle`, `captured`); bundle + all three children `payment_status=paid`.
3. Three `ServiceMatchingJob`s dispatched — one per child.
4. Provider A accepts child 1 → one `BundleConsolidationJob` dispatched, keyed to child 1's id; running it offers child 2 to provider A (skill + radius + `[09:00,10:00)` vs `[13:00,14:00)` availability all pass) and **falls child 3 back to standard dispatch** (its far address is outside provider A's radius — explicit fallback, asserted via the `ServiceMatchingJob` push for child 3).
5. Provider A accepts child 2 through the consolidated offer — `AcceptBookingAction`'s in-transaction availability guard lets the non-overlapping slot through; provider A now holds two bundle children.
6. Provider B (at child 3's location) accepts child 3 through the fallback path.
7. Child 1 completed → `derivedStatus()` = `partially_completed`; commission row for child 1 only.
8. Child 2 completed → `derivedStatus()` still `partially_completed` (child 3 still `assigned`).
9. Child 3 cancelled via `POST /api/bookings/{id}/cancel` → child 3 `cancelled`, cancellation fee computed (0 — inside the free window), children 1 & 2 untouched.
10. Final: `derivedStatus()` = `completed` (≥1 completed, the rest terminal — a cancelled child counts as terminal); stored `status` = `active`.
11. Money reconciles: customer wallet = 100,000 − 1500 = 98,500 (no refund); provider A wallet = `provider_commission(child1)` + `provider_commission(child2)` = 360 + 540 = 900; `platform_commission` = 40 + 60 = 100; total commission recorded = 1000 = `price_final(child1) + price_final(child2)`; provider B settled nothing; zero refund ledger rows.

### Two deviations from the brief — found by E7, CLOSED by Phase E5.1

E7 originally pinned these two as KNOWN-BROKEN. **Phase E5.1 (commit on top of E7) closed both** — see `PHASE_E5_1_COMPLETION_REPORT.md`. `E7_FullBundleLifecycleTest` steps 9–11 now assert the corrected behaviour (steps 1–8 unchanged).

| Brief step | Gap E7 found | Resolution in E5.1 |
|-----------|--------------|--------------------|
| **9 — bundle-cancel endpoint + refund** | No bundle-cancel endpoint existed; cancelling a bundle child via `POST /api/bookings/{id}/cancel` issued **no refund** — `CancellationService::refundIfPaid()` looks up `Payment::where('booking_id', $child->id)` and a bundle child has no `Payment` of its own (E3 keeps one per bundle). | New `POST /api/booking-bundles/{id}/cancel` + `CancelBookingBundleAction` + `BundleSettlementService`. Cancels every still-active child through the existing `AdminCancelBookingAction` (unchanged fee math), then reconciles the ONE shared bundle `Payment` **once**: `refundDue = payment.amount − Σ retained` where retained = `cancellation_fee` for a cancelled child, `price_quoted` for a delivered/still-active one — the same "keep the fee, return the rest" rule `refundIfPaid()` uses, summed across children and guarded against a double refund by `payments.refunded_amount`. The single-booking endpoint now also reconciles when the cancelled booking is a bundle child. |
| **10 — stored `status` latch** | `BookingBundle.status` was never advanced from `active`; only `derivedStatus()` was accurate. | `BundleSettlementService::latchTerminalStatus()` — one-way `active → completed/cancelled`, decided solely by `derivedStatus()` — called (guarded by `booking_bundle_id`, in a locked transaction) from `CompleteBookingAction`, `AdminCancelBookingAction` and `CancelBookingBundleAction`. |

---

## 6. Deferred decision points — how each was actually resolved

| Discovery-time decision point | Resolution during E1–E7 |
|-------------------------------|--------------------------|
| **Free-cancellation window — per-child or per-bundle?** | **Per-child.** Cancelling a bundle child runs `CancellationService::calculateFee(Booking $child)` — the same `cancellation.free_minutes` / `cancellation.fee_type` / `cancellation.fee_value` cascade (measured from `child.created_at`) that a standalone booking uses, scoped to that child's own franchise/zone. There is no bundle-level cancellation window. In practice every child of a bundle is created in the same instant, so the windows coincide, but the mechanism is genuinely per-child (a future per-child reschedule would move only that child's clock). |
| **Mixed completed/cancelled receipt wording** | **Not specially handled — the receipt is the aggregate.** E5 materialises exactly one receipt per bundle, via `DocumentService::forPayment($bundlePayment, 'receipt')` keyed to the bundle's single aggregate `Payment`. `DocumentService::linesFor()` has no `booking_bundle` branch, so the receipt shows one line — generic label, the full `Payment.amount` (the whole bundle total). It is generated once, on the first child completion, and is idempotent by `generated_documents (documentable, type)`. A later child cancellation does **not** restate or amend it (and, per §5, issues no refund that would need restating). |
| **Slot-locking mechanism** | **Pessimistic lock on the parent `Provider` row + in-transaction availability recheck.** `AcceptBookingAction` does `Provider::whereKey($id)->lockForUpdate()->firstOrFail()` inside the assignment transaction, then re-runs `ProviderAvailabilityService::isAvailableAt()` (half-open `[start, end)` overlap over the provider's `assigned` / `provider_en_route` / `in_progress` / `on_hold` bookings) before writing `provider_id`. No dedicated slot/lock table was introduced. On MySQL/Postgres this is a real row lock; on SQLite the whole-database write lock serializes equivalently (documented in `ProviderAvailabilityService` and the concurrency tests). |

---

## 7. Known limitations / out of scope (intentionally deferred)

- ~~**Bundle cancellation & partial refund.**~~ **CLOSED in Phase E5.1** — `POST /api/booking-bundles/{id}/cancel` + `BundleSettlementService` reconcile the shared bundle `Payment`. See `PHASE_E5_1_COMPLETION_REPORT.md`.
- ~~**Bundle status latch.**~~ **CLOSED in Phase E5.1** — `BundleSettlementService::latchTerminalStatus()` performs the one-way `active → completed/cancelled` write from `derivedStatus()`.
- **Customer web UI polish beyond E6.** In-booking live chat UI, membership/plan customer UI, loyalty/referral UI, coupon UI (no booking-path backend consumer), and any bundle-specific customer web screen (the E6 web builds single-service bookings; the bundle endpoint is API-only).
- **Provider / rider mobile apps** — untouched.
- **Timezone handling.** `scheduled_at` is stored/compared naïvely; `ProviderAvailabilityService` and the scheduling-window validation assume a single implicit zone.
- **Working-hours / shift / break / holiday calendars.** `ProviderAvailabilityService` explicitly models only booking-vs-booking interval overlap (documented in its docblock) — no shift boundaries, travel-time buffers, or holiday calendars.
- **`ConsolidationOfferTimeoutJob` real-time behaviour** is covered by unit-level tests only (queue faked); no load/soak testing of the offer→timeout→fallback loop under concurrency.

---

## 8. Confirmation

- **`main` untouched** — still at `0381b55`; every Phase E commit is on `feature/customer-web-foundation` only.
- **No prior E1–E6 commit amended.**
- **No production deployment.** Staging `https://api.1callfix.com/` continues to serve the default Laravel page and does not serve the customer web; no deploy was performed in E7.
- **E7 changed no product behaviour** — three new test files (`E7_FullBundleLifecycleTest`, `BundleRefundDuplicationE7Test`) and this report; no `app/`, `routes/`, `config/`, `database/` or existing-test changes.
- **Ready for review / merge.**
