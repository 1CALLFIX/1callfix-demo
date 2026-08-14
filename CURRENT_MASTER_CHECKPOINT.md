# 1CallFix — Current Master Checkpoint

**This document supersedes all previous checkpoints.** It reflects the repository as of this session. Where it conflicts with `PROJECT_CURRENT_STATE.md`, this document is more current for the specific items it covers; `PROJECT_CURRENT_STATE.md` remains authoritative for everything else until it is next synced.

Labels: **IMPLEMENTED** shipped and wired · **VERIFIED** confirmed by an automated test this session · **PARTIAL** exists but incomplete · **MISSING** does not exist · **BUSINESS DECISION** needs a human call, not an engineering one · **UNREACHABLE** code that would be dead given current guards elsewhere.

---

## 1. Git state

- **HEAD:** continuing the "FINAL EOD AUTONOMOUS COMPLETION MISSION" from baseline `6b7c36e` (306/306), all local to `main`, none pushed/deployed. Phase 10 (Operations expansion) is the latest commit.
- **Production:** unchanged at `ba0635a` throughout.
- **Full suite progression this segment:** 306/306 → 340/340 (Performance Campaigns) → 384/384 (KYC engine) → 406/406 (Compensation) → 422/422 (Chat) → 436/436 (Documents) → 458/458 (Notification Center) → 480/480 (Payment Accounts) → 491/491 (Operations expansion). 0 failures/errors/warnings at every checkpoint.

## 2. This segment's commits

1. **`d1e7995` — Performance/Growth Campaign Engine.** Configurable audience(Franchise/Provider/FieldWorker/Customer)/scope/metric/qualification(threshold or top-N)/reward-type architecture. Metrics computed from real `Booking.status='completed'` data only. Reward disbursement reuses Wallet/Loyalty/Badge — no parallel payout mechanism. Approve→disburse separated into its own permission (separation of duties). 34 tests.
2. **`ac816a8` + `3430197`(docs) — KYC completeness engine.** Document security overhaul (closed a real raw-`<a href>` public-link exposure), configurable required-document list per actor/country, Partner verification video, 30-day deadline with idempotent reminders, live-derived (never-boolean) withdrawal restriction enforced at `PayoutService::request()`, franchise-raises/central-admin-decides support-request workflow creating auditable time-bound exceptions. 44 tests.
3. **`cd174cc` — Tips/waiting/rain/overtime/peak/night compensation.** Overtime/night/peak auto-computed from real booking timestamps; rain/waiting admin-triggered only (no real data source exists to auto-derive them); tips move money customer-wallet→provider-wallet atomically. Every rate defaults to 0 (no invented values). Hooked into `CompleteBookingAction`. 22 tests.
4. **`a0b7b15` — Universal Chat.** `chat_messages` existed since P2 but was fully dormant. Built `ChatService` (participant-derivation + IDOR guard), `ChatController` (Sanctum API), private authorization-gated attachments. Supports Customer↔Partner, Customer↔Worker, Partner↔Worker — all three combinations naturally derived from real booking data. 16 tests.
5. **`447ea55` — Printing/Document Engine (user-directed continuation).** No PDF library existed anywhere in the repo; added `barryvdh/laravel-dompdf` per explicit user choice. One normalized view-model + one Blade template renders invoices/receipts for all three real Payment purposes (booking/wallet_topup/plan_subscription). Idempotent per-country-per-year numbering (row-locked sequence). Admin download reuses `payments.view`'s existing row-level scope; customer self-service API endpoint checks real payer ownership. 14 tests.
6. **`642d582` — Notification/Communication Center completeness (user-directed continuation).** Templates CRUD, Delivery Logs browser, Provider Status panel (adapter binding visibility, never exposes credentials), a real working `resendToFailedRecipients()` retry, and the in-app notification read API (Laravel's `database` channel had zero read-side despite being fully write-wired). All were real gaps where backend/permissions already existed but no UI/API consumed them. 22 tests.
7. **`8d44153` — Payment Admin completion (user-directed continuation).** `payment_accounts` was already read by PayoutService/Payouts\Manage but had zero write path anywhere — added `PaymentAccountService` (create/update/setDefault/delete/verify, verification always admin-only), self-service API, admin verification UI on the existing Payouts screen, and closed an IDOR-adjacent gap where a payout's payment_account_id was never checked to actually belong to the payee. `payment_methods` (the other table risk-register item 11 names) stays deliberately blocked on its own consolidation decision. 22 tests.
8. **Operations/Troubleshoot expansion (mission Phase 10).** `/admin/operations` previously only showed failed jobs, notification failures, and static health checks. Added: `payment_webhook_logs` (every Razorpay webhook receipt persisted regardless of outcome — an invalid signature or unmatched order used to vanish into a `Log::warning()` line with a 200 telling Razorpay to stop retrying) plus an admin "reprocess" action reusing the same idempotent `RazorpayWebhookHandler` extracted from `PaymentController`'s former private methods; `scheduled_task_runs` real run-history via `ScheduleRunTracker` wired onto all 4 existing `Schedule::command()` entries (no row means "hasn't run," never a fabricated "healthy"); three read-only detection services — `ReconciliationService` (paid-without-captured-payment, completed-without-commission, wallet ledger drift), `DispatchHealthService` (stale offers, exhausted bookings, reusing the existing `dispatch_attempts` table), `StuckBookingService` (Setting-driven per-status thresholds against `booking_status_history`) — all surfaced on the same screen, row-scoped via the same `AuthorizationService::scopeQuery` convention every other admin screen uses; and `ActivityLog` (existed since Phase P3, zero writers) now wired into every Operations mutation (job retry/discard, webhook reprocess). 11 new tests plus additive `PaymentWebhookLog` assertions on existing webhook tests. Full suite 491/491.

## 3. Full backend/admin capability snapshot (this segment's additions — see `PROJECT_CURRENT_STATE.md` for everything from prior sessions)

| Capability | Status |
|---|---|
| Performance/Growth Campaign Engine | [IMPLEMENTED] [VERIFIED] |
| KYC document security + configurable requirements | [IMPLEMENTED] [VERIFIED] |
| Partner verification video | [IMPLEMENTED] [VERIFIED] |
| KYC 30-day deadline + reminders | [IMPLEMENTED] [VERIFIED] |
| Withdrawal restriction (derived, never boolean) | [IMPLEMENTED] [VERIFIED] |
| Franchise KYC support-request → Central Admin decision | [IMPLEMENTED] [VERIFIED] |
| Overtime/night/peak compensation | [IMPLEMENTED] [VERIFIED] — auto-computed |
| Rain/waiting compensation | [IMPLEMENTED] [VERIFIED] — admin-triggered (no auto data source) |
| Tips | [IMPLEMENTED] [VERIFIED] |
| Universal Chat (Customer/Partner/Worker) | [IMPLEMENTED] [VERIFIED] |
| Printing/Document Engine (invoices/receipts, real PDF) | [IMPLEMENTED] [VERIFIED] |
| Notification Center: templates CRUD, delivery logs, provider status, retry, in-app read API | [IMPLEMENTED] [VERIFIED] |
| Payment Admin: settlement account (`payment_accounts`) self-service + admin verification | [IMPLEMENTED] [VERIFIED] |
| `payment_methods` admin UI | [BUSINESS DECISION] — blocked on risk register item 11, deliberately untouched |
| Payment webhook receipt logging + admin reprocess action | [IMPLEMENTED] [VERIFIED] |
| Scheduled-task (CRON) run-history tracking | [IMPLEMENTED] [VERIFIED] |
| Reconciliation warnings (payment/commission/wallet drift detection) | [IMPLEMENTED] [VERIFIED] — detect-and-report only, no auto-repair |
| Dispatch health (stale offers, exhausted bookings) | [IMPLEMENTED] [VERIFIED] |
| Stuck-booking detection (Setting-driven thresholds) | [IMPLEMENTED] [VERIFIED] — recovery via existing Bookings\Show actions |
| Activity/audit log wired into Operations mutations | [IMPLEMENTED] [VERIFIED] — Operations domain only, not retrofitted repo-wide |
| CMS / Glover-6amMart parity / QA data / financial reconciliation audit / API-E2E security sweep / international readiness / performance-scale / final capability matrix | [NOT STARTED this segment] |

## 4. Do not confuse these

- All eight commits above (ten including doc syncs) are **COMMITTED** to local `main` and **TEST-VERIFIED** (full suite run before each).
- Nothing this segment is **PUSHED**, **DEPLOYED**, or **PRODUCTION VERIFIED** — production remains `ba0635a`, untouched.
- Mission phases 11–20 (Admin menu/settings audit, CMS, Glover/6amMart parity, QA data, financial reconciliation audit, API/E2E security, international readiness, performance/scale, final capability matrix, final release-readiness audit) are **NOT STARTED, NOT CLAIMED COMPLETE**.
